<?php
// =====================================================
// API PROJETOS - PORTAL DO MORADOR (consumidor de Ordens de Serviço)
// =====================================================
// "Projeto" não é um cadastro próprio: é apenas a O.S (os_chamados) com
// projeto_publico=1. Esta API só LÊ essa mesma tabela (mais os_interacoes,
// os_interacao_fotos, os_etapas, departamentos e documentos do GED),
// nunca grava nada — o Portal é somente consulta. Diferente de
// api_portal_os.php, aqui NÃO há filtro por morador_id: projetos são de
// interesse de todos os moradores, não de quem abriu o chamado original.
//
// Endpoints (GET, acao=):
//   dashboard            — contadores por status público
//   listar&status=&busca=&pagina=  — cards de projetos
//   detalhe&id=          — projeto completo: timeline pública, fotos, documentos
//
// Autenticação: token Bearer via sessoes_portal (mesmo padrão já usado em
// api_portal_documentos.php / api_portal_marketplace.php).

session_start();
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';

ob_end_clean();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// ========== HELPERS ==========
function pp_json($ok, $msg, $dados = null, $code = 200) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    $r = ['sucesso' => $ok, 'mensagem' => $msg];
    if ($dados !== null) $r['dados'] = $dados;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

function pp_token() {
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

function pp_auth($conexao) {
    $token = pp_token();
    if (!$token) pp_json(false, 'Token não informado.', null, 401);
    $stmt = $conexao->prepare("SELECT morador_id FROM sessoes_portal WHERE token = ? AND ativo = 1 AND data_expiracao > NOW() LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$r) pp_json(false, 'Sessão expirada. Faça login novamente.', null, 401);
    return (int)$r['morador_id'];
}

// Campos públicos whitelisted — nunca retorna ids internos de usuário,
// atendente, morador solicitante original, ou qualquer coluna operacional.
//
// "Projeto" não duplica cadastro: título, departamento, responsável e datas
// vêm sempre da própria O.S (single source of truth) — nunca de colunas
// projeto_* paralelas. Só Etapa/Conclusão/Capa continuam exclusivas do
// Projeto, pois não existem em nenhum outro lugar da O.S.
function pp_campos_publicos() {
    return "o.id, o.titulo AS projeto_nome, o.descricao AS projeto_descricao,
            o.departamento AS departamento_nome, o.atendente_nome AS projeto_responsavel,
            DATE_FORMAT(o.data_abertura,'%d/%m/%Y') AS projeto_data_inicio_prevista,
            DATE_FORMAT(o.data_previsao,'%d/%m/%Y') AS projeto_data_fim_prevista,
            " . pp_status_derivado_sql() . " AS projeto_status,
            o.projeto_imagem_capa, o.projeto_percentual,
            o.projeto_etapa_id, e.nome AS projeto_etapa_nome,
            (SELECT DATE_FORMAT(MAX(i.criado_em),'%d/%m/%Y') FROM os_interacoes i
             WHERE i.os_id = o.id AND i.tipo='andamento' AND i.publica=1) AS ultima_atualizacao";
}

function pp_from_join() {
    return "FROM os_chamados o
            LEFT JOIN os_etapas e ON e.id = o.projeto_etapa_id";
}

// Status público é sempre derivado do status real da O.S — nunca cadastrado
// manualmente. Assim, finalizar/cancelar/reabrir uma O.S. (fluxo já
// existente, intocado) reflete automaticamente no Portal e na Página
// Pública, sem qualquer sincronização.
function pp_status_derivado_sql() {
    return "CASE o.status
              WHEN 'aberto'     THEN 'planejamento'
              WHEN 'andamento'  THEN 'execucao'
              WHEN 'finalizado' THEN 'finalizado'
              WHEN 'cancelado'  THEN 'cancelado'
              ELSE 'planejamento'
            END";
}

// Traduz o status público (filtro recebido do cliente) para o status real
// da O.S. correspondente — usado para filtrar sem depender de coluna derivada.
function pp_status_os_correspondente($statusPublico) {
    $mapa = ['planejamento' => 'aberto', 'execucao' => 'andamento', 'finalizado' => 'finalizado', 'cancelado' => 'cancelado'];
    return $mapa[$statusPublico] ?? null;
}

// A descrição da O.S é um campo rich-text (editor com formatação/imagens
// inline) — para exibição pública como resumo em texto simples, as tags
// são removidas aqui em vez de renderizadas como HTML.
function pp_limpar_descricao($html) {
    if (!$html) return '';
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
}

// ========== INICIALIZAÇÃO ==========
$conexao = conectar_banco();
if (!$conexao) pp_json(false, 'Erro ao conectar ao banco de dados.', null, 500);
$conexao->set_charset('utf8mb4');

$acao = $_GET['acao'] ?? '';

try {

    $moradorId = pp_auth($conexao); // garante sessão válida; projetos não são filtrados por morador

    // ============================================================
    // DASHBOARD — contadores por status público
    // ============================================================
    if ($acao === 'dashboard') {
        $res = $conexao->query("SELECT status, COUNT(*) c FROM os_chamados WHERE projeto_publico = 1 GROUP BY status");
        $porStatusOs = [];
        if ($res) while ($r = $res->fetch_assoc()) $porStatusOs[$r['status']] = (int)$r['c'];

        $planejamento = $porStatusOs['aberto']     ?? 0;
        $execucao     = $porStatusOs['andamento']  ?? 0;
        $finalizado   = $porStatusOs['finalizado'] ?? 0;
        $cancelado    = $porStatusOs['cancelado']  ?? 0;

        pp_json(true, 'OK', [
            'planejamento' => $planejamento,
            'execucao'     => $execucao,
            'cancelado'    => $cancelado,
            'finalizado'   => $finalizado,
            'total_ativos' => $planejamento + $execucao,
        ]);
    }

    // ============================================================
    // LISTAR — cards de projetos públicos
    // GET ?status=&busca=&pagina=
    // ============================================================
    if ($acao === 'listar') {
        $status = trim($_GET['status'] ?? '');
        $busca  = trim($_GET['busca']  ?? '');
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));
        $limit  = 20;
        $offset = ($pagina - 1) * $limit;

        $where  = ['o.projeto_publico = 1'];
        $params = [];
        $types  = '';

        if ($status !== '' && in_array($status, ['planejamento','execucao','finalizado','cancelado'], true)) {
            $statusOs = pp_status_os_correspondente($status);
            if ($statusOs) {
                $where[] = 'o.status = ?';
                $params[] = $statusOs;
                $types .= 's';
            }
        }
        if ($busca !== '') {
            $where[] = '(o.titulo LIKE ? OR o.departamento LIKE ?)';
            $b = '%' . $busca . '%';
            $params[] = $b; $params[] = $b;
            $types .= 'ss';
        }
        $whereSql = implode(' AND ', $where);

        $sql = "SELECT " . pp_campos_publicos() . " " . pp_from_join() . "
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
            $row['projeto_descricao'] = pp_limpar_descricao($row['projeto_descricao']);
            $lista[] = $row;
        }

        pp_json(true, 'OK', ['projetos' => $lista, 'pagina' => $pagina]);
    }

    // ============================================================
    // DETALHE — projeto completo (timeline pública, fotos, documentos)
    // GET ?id=
    // ============================================================
    if ($acao === 'detalhe') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) pp_json(false, 'ID inválido.', null, 400);

        $stmt = $conexao->prepare("SELECT " . pp_campos_publicos() . " " . pp_from_join() . " WHERE o.id = ? AND o.projeto_publico = 1 LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $projeto = $stmt->get_result()->fetch_assoc();
        if (!$projeto) pp_json(false, 'Projeto não encontrado ou não é público.', null, 404);
        $projeto['projeto_descricao'] = pp_limpar_descricao($projeto['projeto_descricao']);

        // Timeline pública: somente interações tipo=andamento marcadas como públicas
        $stmtInt = $conexao->prepare(
            "SELECT i.id, i.mensagem, i.percentual, i.publica,
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

        // Fotos das interações públicas (batch) — só o id é exposto; a URL
        // real da imagem é resolvida por api_imagem_projeto.php (nunca o
        // caminho de arquivo em si).
        if ($idsInteracoes) {
            $idsSql = implode(',', $idsInteracoes);
            $resFotos = $conexao->query("SELECT id, interacao_id FROM os_interacao_fotos WHERE interacao_id IN ($idsSql) ORDER BY criado_em ASC");
            $fotosPorInteracao = [];
            if ($resFotos) while ($f = $resFotos->fetch_assoc()) $fotosPorInteracao[(int)$f['interacao_id']][] = (int)$f['id'];
            foreach ($timeline as &$t) $t['fotos'] = $fotosPorInteracao[(int)$t['id']] ?? [];
            unset($t);
        }

        // Documentos do GED vinculados — só os visíveis a moradores (mesmas
        // regras de visibilidade já usadas por api_portal_documentos.php)
        $documentos = [];
        $tabDoc = $conexao->query("SHOW TABLES LIKE 'documentos'");
        if ($tabDoc && $tabDoc->num_rows > 0) {
            $stmtDoc = $conexao->prepare(
                "SELECT id, nome, descricao FROM documentos
                 WHERE os_id = ? AND status = 'ativo'
                   AND (data_expiracao IS NULL OR data_expiracao >= CURDATE())
                   AND visibilidade IN ('todos','moradores')
                 ORDER BY nome ASC"
            );
            $stmtDoc->bind_param('i', $id);
            $stmtDoc->execute();
            $resDoc = $stmtDoc->get_result();
            while ($row = $resDoc->fetch_assoc()) $documentos[] = $row;
        }

        pp_json(true, 'OK', [
            'projeto'    => $projeto,
            'timeline'   => $timeline,
            'documentos' => $documentos,
        ]);
    }

    pp_json(false, 'Ação inválida.', null, 400);

} catch (Exception $e) {
    error_log('Exception em api_portal_projetos: ' . $e->getMessage());
    pp_json(false, 'Erro interno no servidor: ' . $e->getMessage(), null, 500);
}
