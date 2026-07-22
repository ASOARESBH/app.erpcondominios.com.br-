<?php
// =====================================================
// API PÚBLICA DE PROJETOS — Página de Transparência (SEM LOGIN)
// =====================================================
// Superfície pública do módulo Projetos. Mesma fonte de dados de
// api_portal_projetos.php (os_chamados com projeto_publico=1), porém:
//   - Sem autenticação (qualquer visitante pode consultar);
//   - Nunca expõe usuários, permissões, SQL ou estrutura interna;
//   - Documentos só aparecem se visibilidade='todos' (nunca 'moradores');
//   - Rate limit por IP, cache de leitura curto, headers de segurança;
//   - Somente leitura — nenhuma ação grava dados.
//
// Endpoints (GET, acao=):
//   dashboard                       — contadores por status público
//   listar&status=&busca=&pagina=   — cards de projetos
//   detalhe&id=                     — projeto completo (timeline+documentos públicos)
//   documento&id=                   — download de documento público vinculado ao projeto

ob_start();
require_once 'config.php';
ob_end_clean();

// ── Headers de segurança ──────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; frame-src https://www.google.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; script-src 'self'");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function pub_json($ok, $msg, $dados = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    $r = ['sucesso' => $ok, 'mensagem' => $msg];
    if ($dados !== null) $r['dados'] = $dados;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

function pub_ip() {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ── Rate limit simples por IP (sem infraestrutura externa) ────────────
// Limite generoso o suficiente para navegação normal (60 requisições por
// minuto por IP), mas suficiente para conter varredura/scraping agressivo.
function pub_rate_limit($conn, $rota) {
    $conn->query("CREATE TABLE IF NOT EXISTS publico_rate_limit (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        ip        VARCHAR(45) NOT NULL,
        rota      VARCHAR(60) NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_rota_tempo (ip, rota, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ip   = $conn->real_escape_string(pub_ip());
    $rota = $conn->real_escape_string($rota);
    $limite = 60; // por minuto

    $res = $conn->query("SELECT COUNT(*) c FROM publico_rate_limit WHERE ip='$ip' AND rota='$rota' AND criado_em >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
    $total = $res ? (int)$res->fetch_assoc()['c'] : 0;
    if ($total >= $limite) {
        pub_json(false, 'Muitas requisições. Aguarde um momento e tente novamente.', null, 429);
    }
    $conn->query("INSERT INTO publico_rate_limit (ip, rota) VALUES ('$ip','$rota')");

    // Limpeza esporádica (1% das requisições) para não acumular linhas indefinidamente
    if (mt_rand(1, 100) === 1) {
        $conn->query("DELETE FROM publico_rate_limit WHERE criado_em < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    }
}

// ── Cache de leitura em arquivo (TTL curto) ────────────────────────────
// Reduz carga no banco em picos de acesso (ex.: link compartilhado no
// WhatsApp) sem depender de Redis/Memcached, que não estão disponíveis
// neste ambiente.
function pub_cache_get($chave) {
    $arq = sys_get_temp_dir() . '/pub_projetos_cache_' . md5($chave) . '.json';
    if (is_file($arq) && (time() - filemtime($arq)) < 30) {
        $conteudo = @file_get_contents($arq);
        if ($conteudo !== false) {
            $dados = json_decode($conteudo, true);
            if (json_last_error() === JSON_ERROR_NONE) return $dados;
        }
    }
    return null;
}
function pub_cache_set($chave, $dados) {
    $arq = sys_get_temp_dir() . '/pub_projetos_cache_' . md5($chave) . '.json';
    @file_put_contents($arq, json_encode($dados, JSON_UNESCAPED_UNICODE));
}

// Campos públicos whitelisted — nunca id de usuário/atendente/morador, SQL
// ou estrutura interna.
//
// "Projeto" não duplica cadastro: título, departamento, responsável e datas
// vêm sempre da própria O.S (single source of truth), nunca de colunas
// projeto_* paralelas. Só Etapa/Conclusão/Capa continuam exclusivas do
// Projeto, pois não existem em nenhum outro lugar da O.S.
function pub_campos_publicos() {
    return "o.id, o.titulo AS projeto_nome, o.descricao AS projeto_descricao,
            o.departamento AS departamento_nome, o.atendente_nome AS projeto_responsavel,
            DATE_FORMAT(o.data_abertura,'%d/%m/%Y') AS projeto_data_inicio_prevista,
            DATE_FORMAT(o.data_previsao,'%d/%m/%Y') AS projeto_data_fim_prevista,
            " . pub_status_derivado_sql() . " AS projeto_status,
            o.projeto_imagem_capa, o.projeto_percentual,
            e.nome AS projeto_etapa_nome,
            (SELECT DATE_FORMAT(MAX(i.criado_em),'%d/%m/%Y') FROM os_interacoes i
             WHERE i.os_id = o.id AND i.tipo='andamento' AND i.publica=1) AS ultima_atualizacao";
}
function pub_from_join() {
    return "FROM os_chamados o
            LEFT JOIN os_etapas e ON e.id = o.projeto_etapa_id";
}

// Status público sempre derivado do status real da O.S — nunca cadastrado
// manualmente. Finalizar/cancelar/reabrir a O.S. (fluxo já existente,
// intocado) reflete automaticamente aqui, sem sincronização.
function pub_status_derivado_sql() {
    return "CASE o.status
              WHEN 'aberto'     THEN 'planejamento'
              WHEN 'andamento'  THEN 'execucao'
              WHEN 'finalizado' THEN 'finalizado'
              WHEN 'cancelado'  THEN 'cancelado'
              ELSE 'planejamento'
            END";
}
function pub_status_os_correspondente($statusPublico) {
    $mapa = ['planejamento' => 'aberto', 'execucao' => 'andamento', 'finalizado' => 'finalizado', 'cancelado' => 'cancelado'];
    return $mapa[$statusPublico] ?? null;
}

// A descrição da O.S é rich-text (editor com formatação/imagens inline) —
// para resumo público em texto simples, as tags são removidas aqui.
function pub_limpar_descricao($html) {
    if (!$html) return '';
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
}

// ========== INICIALIZAÇÃO ==========
$conexao = conectar_banco();
if (!$conexao) pub_json(false, 'Erro ao conectar ao banco de dados.', null, 500);
$conexao->set_charset('utf8mb4');

$acao = $_GET['acao'] ?? '';

try {

    pub_rate_limit($conexao, $acao ?: 'desconhecida');

    // ============================================================
    // DASHBOARD
    // ============================================================
    if ($acao === 'dashboard') {
        $cache = pub_cache_get('dashboard');
        if ($cache !== null) pub_json(true, 'OK', $cache);

        $res = $conexao->query("SELECT status, COUNT(*) c FROM os_chamados WHERE projeto_publico = 1 GROUP BY status");
        $porStatusOs = [];
        if ($res) while ($r = $res->fetch_assoc()) $porStatusOs[$r['status']] = (int)$r['c'];

        $planejamento = $porStatusOs['aberto']     ?? 0;
        $execucao     = $porStatusOs['andamento']  ?? 0;
        $finalizado   = $porStatusOs['finalizado'] ?? 0;
        $cancelado    = $porStatusOs['cancelado']  ?? 0;

        $resMedia = $conexao->query("SELECT ROUND(AVG(projeto_percentual)) m FROM os_chamados WHERE projeto_publico = 1 AND status IN ('aberto','andamento')");
        $percentualMedio = $resMedia ? (int)($resMedia->fetch_assoc()['m'] ?? 0) : 0;

        $dados = [
            'planejamento'      => $planejamento,
            'execucao'          => $execucao,
            'cancelado'         => $cancelado,
            'finalizado'        => $finalizado,
            'total_ativos'      => $planejamento + $execucao,
            'percentual_medio'  => $percentualMedio,
        ];
        pub_cache_set('dashboard', $dados);
        pub_json(true, 'OK', $dados);
    }

    // ============================================================
    // LISTAR
    // ============================================================
    if ($acao === 'listar') {
        $status = trim($_GET['status'] ?? '');
        $busca  = trim($_GET['busca']  ?? '');
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        // Validação rigorosa: paginação limitada, status restrito a valores conhecidos
        if ($pagina > 500) $pagina = 500;
        $limit  = 20;
        $offset = ($pagina - 1) * $limit;

        $chaveCache = 'listar_' . md5($status . '|' . $busca . '|' . $pagina);
        $cache = pub_cache_get($chaveCache);
        if ($cache !== null) pub_json(true, 'OK', $cache);

        $where  = ['o.projeto_publico = 1'];
        $params = [];
        $types  = '';

        if ($status !== '' && in_array($status, ['planejamento','execucao','finalizado','cancelado'], true)) {
            $statusOs = pub_status_os_correspondente($status);
            if ($statusOs) {
                $where[] = 'o.status = ?';
                $params[] = $statusOs;
                $types .= 's';
            }
        }
        if ($busca !== '') {
            // Validação de tamanho — evita LIKE custoso com strings absurdas
            $busca = mb_substr($busca, 0, 100);
            $where[] = '(o.titulo LIKE ? OR o.departamento LIKE ?)';
            $b = '%' . $busca . '%';
            $params[] = $b; $params[] = $b;
            $types .= 'ss';
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT " . pub_campos_publicos() . " " . pub_from_join() . "
                WHERE $whereSql
                ORDER BY FIELD(o.status,'andamento','aberto','finalizado','cancelado'), o.data_previsao IS NULL, o.data_previsao ASC
                LIMIT ? OFFSET ?";
        $params[] = $limit; $params[] = $offset;
        $types .= 'ii';

        $stmt = $conexao->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $lista = [];
        while ($row = $res->fetch_assoc()) {
            $row['projeto_descricao'] = pub_limpar_descricao($row['projeto_descricao']);
            $lista[] = $row;
        }

        $dados = ['projetos' => $lista, 'pagina' => $pagina];
        pub_cache_set($chaveCache, $dados);
        pub_json(true, 'OK', $dados);
    }

    // ============================================================
    // DETALHE
    // ============================================================
    if ($acao === 'detalhe') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) pub_json(false, 'ID inválido.', null, 400);

        $chaveCache = 'detalhe_' . $id;
        $cache = pub_cache_get($chaveCache);
        if ($cache !== null) pub_json(true, 'OK', $cache);

        $stmt = $conexao->prepare("SELECT " . pub_campos_publicos() . " " . pub_from_join() . " WHERE o.id = ? AND o.projeto_publico = 1 LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $projeto = $stmt->get_result()->fetch_assoc();
        // IDOR: nunca revela se o id existe mas não é público — mesma mensagem genérica
        if (!$projeto) pub_json(false, 'Projeto não encontrado.', null, 404);
        $projeto['projeto_descricao'] = pub_limpar_descricao($projeto['projeto_descricao']);

        $stmtInt = $conexao->prepare(
            "SELECT i.id, i.mensagem, i.percentual,
                    DATE_FORMAT(i.criado_em,'%d/%m/%Y') AS data,
                    e.nome AS etapa_nome
             FROM os_interacoes i
             LEFT JOIN os_etapas e ON e.id = i.etapa_id
             WHERE i.os_id = ? AND i.tipo = 'andamento' AND i.publica = 1
             ORDER BY i.criado_em ASC"
        );
        $stmtInt->bind_param('i', $id);
        $stmtInt->execute();
        $resInt = $stmtInt->get_result();
        $timeline = [];
        $idsInteracoes = [];
        while ($row = $resInt->fetch_assoc()) { $timeline[] = $row; $idsInteracoes[] = (int)$row['id']; }

        if ($idsInteracoes) {
            $idsSql = implode(',', $idsInteracoes);
            $resFotos = $conexao->query("SELECT id, interacao_id FROM os_interacao_fotos WHERE interacao_id IN ($idsSql) ORDER BY criado_em ASC");
            $fotosPorInteracao = [];
            if ($resFotos) while ($f = $resFotos->fetch_assoc()) $fotosPorInteracao[(int)$f['interacao_id']][] = (int)$f['id'];
            foreach ($timeline as &$t) { $t['fotos'] = $fotosPorInteracao[(int)$t['id']] ?? []; unset($t['id']); }
            unset($t);
        }

        // Documentos públicos: SOMENTE visibilidade='todos' (nunca 'moradores' para visitante anônimo)
        $documentos = [];
        $tabDoc = $conexao->query("SHOW TABLES LIKE 'documentos'");
        if ($tabDoc && $tabDoc->num_rows > 0) {
            $stmtDoc = $conexao->prepare(
                "SELECT id, nome FROM documentos
                 WHERE os_id = ? AND status = 'ativo'
                   AND (data_expiracao IS NULL OR data_expiracao >= CURDATE())
                   AND visibilidade = 'todos'
                 ORDER BY nome ASC"
            );
            $stmtDoc->bind_param('i', $id);
            $stmtDoc->execute();
            $resDoc = $stmtDoc->get_result();
            while ($row = $resDoc->fetch_assoc()) $documentos[] = $row;
        }

        $dados = ['projeto' => $projeto, 'timeline' => $timeline, 'documentos' => $documentos];
        pub_cache_set($chaveCache, $dados);
        pub_json(true, 'OK', $dados);
    }

    // ============================================================
    // DOCUMENTO — download público (revalida vínculo + visibilidade a cada acesso)
    // ============================================================
    if ($acao === 'documento') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) pub_json(false, 'ID inválido.', null, 400);

        $tabDoc = $conexao->query("SHOW TABLES LIKE 'documentos'");
        if (!$tabDoc || $tabDoc->num_rows === 0) pub_json(false, 'Documento não disponível.', null, 404);

        $stmt = $conexao->prepare(
            "SELECT arquivo, arquivo_tipo, arquivo_nome_original, link_externo, nome
             FROM documentos
             WHERE id = ? AND os_id IS NOT NULL
               AND (SELECT projeto_publico FROM os_chamados WHERE id = documentos.os_id) = 1
               AND status = 'ativo'
               AND (data_expiracao IS NULL OR data_expiracao >= CURDATE())
               AND visibilidade = 'todos'
             LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        if (!$doc) pub_json(false, 'Documento não encontrado ou não é público.', null, 404);

        if (empty($doc['arquivo'])) {
            if (empty($doc['link_externo'])) pub_json(false, 'Documento sem arquivo disponível.', null, 404);
            pub_json(true, 'OK', ['link_externo' => $doc['link_externo']]);
        }

        $path = dirname(__DIR__) . '/uploads/documentos/' . basename($doc['arquivo']);
        if (!file_exists($path)) pub_json(false, 'Arquivo não encontrado no servidor.', null, 404);

        if (ob_get_length()) ob_clean();
        header('Content-Type: ' . ($doc['arquivo_tipo'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($doc['arquivo_nome_original'] ?: basename($doc['arquivo'])) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-cache');
        readfile($path);
        exit;
    }

    pub_json(false, 'Ação inválida.', null, 400);

} catch (Exception $e) {
    error_log('Exception em api_publico_projetos: ' . $e->getMessage());
    // Nunca expor detalhes internos (SQL, stack trace) numa API pública
    pub_json(false, 'Erro interno no servidor. Tente novamente mais tarde.', null, 500);
}
