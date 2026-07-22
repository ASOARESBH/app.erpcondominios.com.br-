<?php
// =====================================================
// API DOCUMENTOS - PORTAL DO MORADOR (consumidor do GED)
// =====================================================
// O Portal não possui cadastro próprio de documentos: esta API apenas LÊ
// as mesmas tabelas do módulo GED (documentos, documentos_pastas,
// documentos_grupos, documentos_grupos_moradores, documentos_acessos,
// documentos_logs) já usadas por api/api_documentos.php, aplicando o
// filtro de visibilidade para morador e gravando rastreabilidade nas
// MESMAS tabelas de auditoria — nada é duplicado nem paralelo.
//
// Endpoints (GET, acao=):
//   pastas_listar                — árvore de pastas com documentos visíveis
//   documentos_listar&pasta_id=  — documentos de UMA pasta (lazy load; 0 = sem pasta)
//   buscar&q=&tipo=&pagina=      — busca por nome/tags/descrição (todas as pastas)
//   visualizar&id=               — stream inline / link externo (audit: visualizacao)
//   download&id=                 — stream attachment / link externo (audit: download)
//
// Autenticação: token Bearer via sessoes_portal (mesmo padrão de
// api_portal_marketplace.php / api_morador_hidrometro.php).

session_start();
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';

ob_end_clean();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ========== HELPERS ==========
function pd_json($ok, $msg, $dados = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    $r = ['sucesso' => $ok, 'mensagem' => $msg];
    if ($dados !== null) $r['dados'] = $dados;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

function pd_token() {
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        foreach ($h as $k => $v) {
            if (strtolower($k) === 'authorization') {
                if (preg_match('/Bearer\s+(.+)/i', $v, $m)) return trim($m[1]);
            }
        }
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $m)) return trim($m[1]);
    return $_GET['token'] ?? $_POST['token'] ?? null;
}

function pd_auth($conexao) {
    $token = pd_token();
    if (!$token) pd_json(false, 'Token não informado.', null, 401);
    $stmt = $conexao->prepare("SELECT morador_id FROM sessoes_portal WHERE token = ? AND ativo = 1 AND data_expiracao > NOW() LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) pd_json(false, 'Sessão expirada. Faça login novamente.', null, 401);
    return (int)$r['morador_id'];
}

function pd_ip() {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Garante que o valor 'acesso_negado' existe no ENUM de documentos_logs.acao
// (evolução puramente aditiva — não remove nem altera nenhum valor existente
// do GED; necessário porque o conceito de "tentativa negada" só existe a
// partir do consumo pelo Portal).
function pd_garantir_schema($db) {
    $r = $db->query("SHOW COLUMNS FROM documentos_logs LIKE 'acao'");
    if (!$r) return;
    $col = $r->fetch_assoc();
    if ($col && stripos($col['Type'], 'acesso_negado') === false) {
        $db->query("ALTER TABLE documentos_logs MODIFY COLUMN acao
            ENUM('criacao','edicao','exclusao','download','visualizacao','compartilhamento',
                 'expiracao','restauracao','upload','acesso_negado') NOT NULL");
    }
}

// Fragmento SQL reutilizado em toda listagem/consulta: só retorna documentos
// que o morador realmente pode ver, replicando as regras já cadastradas no
// GED (grupo de acesso + campo "quem pode visualizar" + unidade específica).
// $moradorId e $unidadeId são inteiros resolvidos no backend (nunca vêm do cliente).
function pd_where_visivel($moradorId, $unidadeId) {
    $unidadeSql = $unidadeId ? (int)$unidadeId : 0;
    return "d.status = 'ativo'
        AND (d.data_expiracao IS NULL OR d.data_expiracao >= CURDATE())
        AND (
              d.grupo_id IS NULL
           OR EXISTS (
                SELECT 1 FROM documentos_grupos g
                WHERE g.id = d.grupo_id AND g.ativo = 1
                  AND (
                        g.acesso_tipo IN ('todos','moradores')
                     OR (g.acesso_tipo = 'personalizado' AND EXISTS (
                            SELECT 1 FROM documentos_grupos_moradores gm
                            WHERE gm.grupo_id = g.id AND gm.morador_id = $moradorId
                        ))
                  )
              )
        )
        AND (
              d.visibilidade IN ('todos','moradores')
           OR (d.visibilidade = 'unidades_especificas' AND $unidadeSql > 0
               AND JSON_CONTAINS(CAST(d.unidades_acesso AS JSON), CAST($unidadeSql AS JSON)))
        )";
}

function pd_esc($db, $v) { return $db->real_escape_string(trim((string)$v)); }

// Ícone/categoria por extensão — usado pelo filtro "tipo" (pdf/word/excel/imagem/zip)
function pd_categoria_arquivo($nomeArquivo, $mime) {
    $ext = strtolower(pathinfo((string)$nomeArquivo, PATHINFO_EXTENSION));
    if ($ext === 'pdf') return 'pdf';
    if (in_array($ext, ['doc','docx'])) return 'word';
    if (in_array($ext, ['xls','xlsx'])) return 'excel';
    if (in_array($ext, ['png','jpg','jpeg','gif','webp'])) return 'imagem';
    if (in_array($ext, ['zip'])) return 'zip';
    return 'outro';
}

function pd_registrar_acesso($db, $tipo, $docId, $moradorId, $nomeCompleto) {
    $ip   = pd_esc($db, pd_ip());
    $nome = pd_esc($db, $nomeCompleto);
    $ua   = pd_esc($db, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500));
    $ref  = pd_esc($db, substr($_SERVER['HTTP_REFERER']    ?? '', 0, 499));

    $db->query("INSERT INTO documentos_acessos
                (documento_id, tipo, origem, usuario_id, usuario_nome, usuario_perfil, morador_id, ip, user_agent, referer)
                VALUES ($docId, '$tipo', 'interno', NULL, '$nome', 'morador', $moradorId, '$ip', '$ua', '$ref')");

    if ($tipo === 'download') {
        $db->query("UPDATE documentos SET total_downloads = total_downloads + 1 WHERE id = $docId");
    } elseif ($tipo === 'visualizacao') {
        $db->query("UPDATE documentos SET total_visualizacoes = total_visualizacoes + 1 WHERE id = $docId");
    }

    $descEsc = pd_esc($db, "Portal do Morador — $nomeCompleto");
    $db->query("INSERT INTO documentos_logs (documento_id, usuario_id, acao, descricao, ip)
                VALUES ($docId, NULL, '$tipo', '$descEsc', '$ip')");
}

function pd_registrar_negado($db, $docId, $moradorId, $nomeCompleto, $motivo) {
    $ip      = pd_esc($db, pd_ip());
    $descEsc = pd_esc($db, "Acesso negado no Portal do Morador — $nomeCompleto — motivo: $motivo");
    $didSql  = $docId ? (int)$docId : 'NULL';
    $db->query("INSERT INTO documentos_logs (documento_id, usuario_id, acao, descricao, ip)
                VALUES ($didSql, NULL, 'acesso_negado', '$descEsc', '$ip')");
}

// ========== INICIALIZAÇÃO ==========
$conexao = conectar_banco();
if (!$conexao) pd_json(false, 'Erro ao conectar ao banco de dados.', null, 500);
$conexao->set_charset('utf8mb4');

$acao = $_GET['acao'] ?? '';

try {

    pd_garantir_schema($conexao);

    $moradorId = pd_auth($conexao);

    // Dados do morador (nome + unidade textual + id da unidade central, se existir)
    $stmt = $conexao->prepare("SELECT m.nome, m.unidade, u.id AS unidade_id
                                FROM moradores m
                                LEFT JOIN unidades u ON u.nome = m.unidade
                                WHERE m.id = ? LIMIT 1");
    $stmt->bind_param('i', $moradorId);
    $stmt->execute();
    $morador = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$morador) pd_json(false, 'Morador não encontrado.', null, 404);

    $moradorNome     = $morador['nome'];
    $moradorUnidade  = $morador['unidade'];
    $unidadeId       = (int)($morador['unidade_id'] ?? 0);
    $nomeCompleto    = $moradorNome . ' — Unidade ' . $moradorUnidade;
    $whereVisivel    = pd_where_visivel($moradorId, $unidadeId);

    // ============================================================
    // PASTAS_LISTAR — árvore de pastas com contagem de documentos visíveis
    // ============================================================
    if ($acao === 'pastas_listar') {
        $resPastas = $conexao->query("SELECT id, nome, pasta_pai_id FROM documentos_pastas WHERE ativo = 1 ORDER BY nome ASC");
        $pastas = [];
        if ($resPastas) while ($p = $resPastas->fetch_assoc()) $pastas[(int)$p['id']] = ['id' => (int)$p['id'], 'nome' => $p['nome'], 'pasta_pai_id' => $p['pasta_pai_id'] !== null ? (int)$p['pasta_pai_id'] : null, 'total_direto' => 0, 'total_visivel' => 0];

        // Contagem direta de documentos visíveis por pasta
        $resCont = $conexao->query("SELECT d.pasta_id, COUNT(*) c FROM documentos d WHERE $whereVisivel AND d.pasta_id IS NOT NULL GROUP BY d.pasta_id");
        if ($resCont) while ($c = $resCont->fetch_assoc()) {
            $pid = (int)$c['pasta_id'];
            if (isset($pastas[$pid])) { $pastas[$pid]['total_direto'] = (int)$c['c']; $pastas[$pid]['total_visivel'] = (int)$c['c']; }
        }

        // Propagar contagem para pastas-pai (uma pasta "conta" se ela própria
        // ou algum descendente tiver documento visível)
        $mudou = true;
        while ($mudou) {
            $mudou = false;
            foreach ($pastas as $p) {
                if ($p['total_visivel'] > 0 && $p['pasta_pai_id'] !== null && isset($pastas[$p['pasta_pai_id']])) {
                    if ($pastas[$p['pasta_pai_id']]['total_visivel'] < $p['total_visivel']) {
                        $pastas[$p['pasta_pai_id']]['total_visivel'] += $p['total_visivel'];
                        $mudou = true;
                    }
                }
            }
        }

        $lista = array_values(array_filter($pastas, fn($p) => $p['total_visivel'] > 0));
        usort($lista, fn($a, $b) => strcasecmp($a['nome'], $b['nome']));

        // "Sem pasta" — documentos visíveis sem pasta_id
        $resSemPasta = $conexao->query("SELECT COUNT(*) c FROM documentos d WHERE $whereVisivel AND d.pasta_id IS NULL");
        $totalSemPasta = $resSemPasta ? (int)$resSemPasta->fetch_assoc()['c'] : 0;
        if ($totalSemPasta > 0) {
            $lista[] = ['id' => 0, 'nome' => 'Outros Documentos', 'pasta_pai_id' => null, 'total_direto' => $totalSemPasta, 'total_visivel' => $totalSemPasta];
        }

        pd_json(true, 'OK', ['pastas' => $lista]);
    }

    // ============================================================
    // DOCUMENTOS_LISTAR — documentos de UMA pasta (lazy load)
    // GET ?pasta_id= (0 = sem pasta) [&tipo=pdf|word|excel|imagem|zip]
    // ============================================================
    if ($acao === 'documentos_listar') {
        $pastaId = (int)($_GET['pasta_id'] ?? -1);
        if ($pastaId < 0) pd_json(false, 'pasta_id inválido.', null, 400);
        $tipoFiltro = pd_esc($conexao, $_GET['tipo'] ?? '');

        $wherePasta = $pastaId === 0 ? "d.pasta_id IS NULL" : "d.pasta_id = $pastaId";
        $res = $conexao->query("SELECT d.id, d.nome, d.descricao, d.tags, d.arquivo, d.arquivo_tipo,
                                        d.arquivo_tamanho, d.arquivo_nome_original, d.link_externo,
                                        DATE_FORMAT(d.data_publicacao,'%d/%m/%Y') AS data_publicacao,
                                        DATE_FORMAT(d.updated_at,'%d/%m/%Y') AS atualizado_em
                                 FROM documentos d
                                 WHERE $wherePasta AND $whereVisivel
                                 ORDER BY d.nome ASC");
        $docs = [];
        if ($res) while ($d = $res->fetch_assoc()) {
            $d['categoria'] = pd_categoria_arquivo($d['arquivo_nome_original'] ?: $d['arquivo'], $d['arquivo_tipo']);
            if ($tipoFiltro !== '' && $d['categoria'] !== $tipoFiltro) continue;
            $docs[] = $d;
        }

        pd_json(true, 'OK', ['documentos' => $docs]);
    }

    // ============================================================
    // BUSCAR — nome/tags/descrição em todas as pastas (paginado)
    // GET ?q=&tipo=&pagina=
    // ============================================================
    if ($acao === 'buscar') {
        $q      = pd_esc($conexao, $_GET['q'] ?? '');
        $tipoFiltro = pd_esc($conexao, $_GET['tipo'] ?? '');
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $limit  = 30;
        $offset = ($pagina - 1) * $limit;

        $whereBusca = $whereVisivel;
        if ($q !== '') {
            $whereBusca .= " AND (d.nome LIKE '%$q%' OR d.descricao LIKE '%$q%' OR d.tags LIKE '%$q%')";
        }

        $res = $conexao->query("SELECT d.id, d.nome, d.descricao, d.tags, d.arquivo, d.arquivo_tipo,
                                        d.arquivo_tamanho, d.arquivo_nome_original, d.link_externo,
                                        DATE_FORMAT(d.data_publicacao,'%d/%m/%Y') AS data_publicacao,
                                        DATE_FORMAT(d.updated_at,'%d/%m/%Y') AS atualizado_em,
                                        p.nome AS pasta_nome
                                 FROM documentos d
                                 LEFT JOIN documentos_pastas p ON p.id = d.pasta_id
                                 WHERE $whereBusca
                                 ORDER BY d.nome ASC
                                 LIMIT $limit OFFSET $offset");
        $docs = [];
        if ($res) while ($d = $res->fetch_assoc()) {
            $d['categoria'] = pd_categoria_arquivo($d['arquivo_nome_original'] ?: $d['arquivo'], $d['arquivo_tipo']);
            if ($tipoFiltro !== '' && $d['categoria'] !== $tipoFiltro) continue;
            $docs[] = $d;
        }

        pd_json(true, 'OK', ['documentos' => $docs, 'pagina' => $pagina]);
    }

    // ============================================================
    // VISUALIZAR / DOWNLOAD — stream do arquivo (com re-validação total)
    // GET ?id=
    // ============================================================
    if ($acao === 'visualizar' || $acao === 'download') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) pd_json(false, 'ID inválido.', null, 400);

        $res = $conexao->query("SELECT d.* FROM documentos d WHERE d.id = $id AND $whereVisivel LIMIT 1");
        $doc = $res ? $res->fetch_assoc() : null;

        if (!$doc) {
            pd_registrar_negado($conexao, $id, $moradorId, $nomeCompleto, $acao);
            pd_json(false, 'Documento não disponível ou sem permissão de acesso.', null, 403);
        }

        // Link externo (Google Drive / OneDrive / Dropbox): registra e devolve a URL
        if (empty($doc['arquivo'])) {
            if (empty($doc['link_externo'])) pd_json(false, 'Documento sem arquivo ou link disponível.', null, 404);
            pd_registrar_acesso($conexao, $acao === 'download' ? 'download' : 'visualizacao', $id, $moradorId, $nomeCompleto);
            pd_json(true, 'OK', ['link_externo' => $doc['link_externo']]);
        }

        $path = dirname(__DIR__) . '/uploads/documentos/' . basename($doc['arquivo']);
        if (!file_exists($path)) pd_json(false, 'Arquivo não encontrado no servidor.', null, 404);

        pd_registrar_acesso($conexao, $acao === 'download' ? 'download' : 'visualizacao', $id, $moradorId, $nomeCompleto);

        if (ob_get_length()) ob_clean();
        $disposicao = $acao === 'download' ? 'attachment' : 'inline';
        header('Content-Type: ' . ($doc['arquivo_tipo'] ?: 'application/octet-stream'));
        header('Content-Disposition: ' . $disposicao . '; filename="' . rawurlencode($doc['arquivo_nome_original'] ?: basename($doc['arquivo'])) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-cache');
        readfile($path);
        exit;
    }

    pd_json(false, 'Ação inválida.', null, 400);

} catch (Exception $e) {
    error_log('Exception em api_portal_documentos: ' . $e->getMessage());
    pd_json(false, 'Erro interno no servidor: ' . $e->getMessage(), null, 500);
}
