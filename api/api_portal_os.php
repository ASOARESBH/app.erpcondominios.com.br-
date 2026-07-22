<?php
/**
 * API PORTAL DO MORADOR — ORDENS DE SERVIÇO
 * ============================================================
 * Endpoints:
 *   GET  ?action=listar_assuntos          — lista assuntos ativos (público para o portal)
 *   GET  ?action=listar                   — lista OS do morador autenticado
 *   GET  ?action=buscar&id=X              — detalhes de uma OS do morador
 *   GET  ?action=listar_interacoes&os_id=X — movimentações de uma OS
 *   POST ?action=abrir                    — abre nova OS pelo portal
 * ============================================================
 * Autenticação: Bearer token via sessoes_portal
 * ============================================================
 */

// ─── Tratamento de erros ─────────────────────────────────────
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $log_file = __DIR__ . '/../logs/debug_portal_os.log';
    $dir = dirname($log_file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $entry = date('Y-m-d H:i:s') . ' | ERROR | ' . json_encode([
        'errno' => $errno, 'errstr' => $errstr,
        'errfile' => basename($errfile), 'errline' => $errline
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso'=>false,'mensagem'=>"Erro interno: $errstr"], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return false;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $log_file = __DIR__ . '/../logs/debug_portal_os.log';
        $entry = date('Y-m-d H:i:s') . ' | FATAL | ' . json_encode($error, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['sucesso'=>false,'mensagem'=>'Erro fatal: '.$error['message']], JSON_UNESCAPED_UNICODE);
        }
    }
});

// ─── Sessão e dependências ───────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ob_start();
require_once 'config.php';
ob_end_clean();

// ─── Headers ─────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$allowed_origins = [
    'https://app.erpcondominios.com.br',
    'http://app.erpcondominios.com.br',
    'https://erpcondominios.com.br',
    'http://localhost',
    'http://127.0.0.1'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed_origins) ? $origin : 'https://app.erpcondominios.com.br'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ─── Funções utilitárias ─────────────────────────────────────
function portal_os_log($nivel, $mensagem, $dados = []) {
    $log_file = __DIR__ . '/../logs/debug_portal_os.log';
    $dir = dirname($log_file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $entry = date('Y-m-d H:i:s') . ' | ' . strtoupper($nivel) . ' | ' . json_encode([
        'mensagem' => $mensagem,
        'dados'    => $dados
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
}

function portal_os_json($sucesso, $mensagem, $dados = null) {
    $r = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
    if ($dados !== null) $r['dados'] = $dados;
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Autenticação via token do portal ────────────────────────
function portal_os_autenticar($conn) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = null;

    // Tentar pegar token do header Authorization
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'authorization') {
            if (preg_match('/Bearer\s+(.+)/i', $v, $m)) {
                $token = trim($m[1]);
            }
        }
    }

    // Fallback: token no GET/POST
    if (!$token) $token = $_GET['token'] ?? $_POST['token'] ?? null;

    if (!$token) return null;

    $stmt = $conn->prepare("
        SELECT sp.morador_id, m.nome, m.unidade, m.cpf, m.email
        FROM sessoes_portal sp
        JOIN moradores m ON m.id = sp.morador_id
        WHERE sp.token = ? AND sp.data_expiracao > NOW()
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    return null;
}

// ─── Conexão ─────────────────────────────────────────────────
$conn = conectar_banco();
if (!$conn) {
    portal_os_json(false, 'Erro de conexão com o banco de dados');
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$body   = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
}

portal_os_log('info', 'Requisição recebida', ['action' => $action, 'method' => $_SERVER['REQUEST_METHOD']]);

// ─── Rotas públicas (sem autenticação) ───────────────────────
if ($action === 'listar_assuntos') {
    $res = $conn->query("SELECT id, nome, departamento FROM os_assuntos WHERE ativo = 1 ORDER BY nome ASC");
    $lista = [];
    if ($res) while ($row = $res->fetch_assoc()) $lista[] = $row;
    portal_os_json(true, 'Assuntos carregados', $lista);
}

// ─── Autenticação obrigatória para demais rotas ───────────────
$morador = portal_os_autenticar($conn);
if (!$morador) {
    http_response_code(401);
    portal_os_json(false, 'Não autorizado. Faça login novamente.');
}

$morador_id     = (int)$morador['morador_id'];
$morador_nome   = $morador['nome']    ?? 'Morador';
$morador_unidade = $morador['unidade'] ?? '';

portal_os_log('info', 'Morador autenticado', ['morador_id' => $morador_id, 'action' => $action]);

// ─── Rotas autenticadas ───────────────────────────────────────
switch ($action) {

    // ─── LISTAR OS DO MORADOR ────────────────────────────────
    case 'listar':
        $status  = $_GET['status'] ?? '';
        $pagina  = max(1, (int)($_GET['pagina'] ?? 1));
        $por_pag = 20;
        $offset  = ($pagina - 1) * $por_pag;

        $where  = ['o.morador_id = ?'];
        $params = [$morador_id];
        $types  = 'i';

        if ($status !== '') {
            $where[]  = 'o.status = ?';
            $params[] = $status;
            $types   .= 's';
        }

        $where_sql = implode(' AND ', $where);

        // Total
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM os_chamados o WHERE $where_sql");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];

        // Lista
        $params[] = $por_pag;
        $params[] = $offset;
        $types   .= 'ii';

        $stmt = $conn->prepare("
            SELECT
                o.id, o.numero, o.titulo, o.status, o.prioridade,
                o.departamento, o.origem_portal,
                o.assumido_por_nome,
                o.data_abertura,
                o.data_inicio,
                o.data_previsao,
                o.data_finalizacao,
                a.nome as assunto_nome,
                (SELECT COUNT(*) FROM os_interacoes i WHERE i.os_id = o.id) as total_interacoes
            FROM os_chamados o
            LEFT JOIN os_assuntos a ON o.assunto_id = a.id
            WHERE $where_sql
            ORDER BY
                FIELD(o.status,'aberto','andamento','finalizado','cancelado'),
                o.data_abertura DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res  = $stmt->get_result();
        $lista = [];
        while ($row = $res->fetch_assoc()) $lista[] = $row;

        portal_os_json(true, 'OS carregadas', [
            'lista'    => $lista,
            'total'    => $total,
            'pagina'   => $pagina,
            'paginas'  => ceil($total / $por_pag)
        ]);
        break;

    // ─── BUSCAR UMA OS ───────────────────────────────────────
    case 'buscar':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        if (!$id) portal_os_json(false, 'ID inválido');

        $stmt = $conn->prepare("
            SELECT
                o.*,
                a.nome as assunto_nome
            FROM os_chamados o
            LEFT JOIN os_assuntos a ON o.assunto_id = a.id
            WHERE o.id = ? AND o.morador_id = ?
        ");
        $stmt->bind_param('ii', $id, $morador_id);
        $stmt->execute();
        $os = $stmt->get_result()->fetch_assoc();

        if (!$os) portal_os_json(false, 'OS não encontrada ou sem permissão de acesso');

        portal_os_json(true, 'OS encontrada', $os);
        break;

    // ─── LISTAR INTERAÇÕES/MOVIMENTAÇÕES ────────────────────
    case 'listar_interacoes':
        $os_id = (int)($_GET['os_id'] ?? $body['os_id'] ?? 0);
        if (!$os_id) portal_os_json(false, 'os_id inválido');

        // Verificar se a OS pertence ao morador
        $stmt = $conn->prepare("SELECT id FROM os_chamados WHERE id = ? AND morador_id = ?");
        $stmt->bind_param('ii', $os_id, $morador_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            portal_os_json(false, 'OS não encontrada ou sem permissão');
        }

        // Buscar interações (excluir notas internas — visíveis só para atendentes)
        $stmt = $conn->prepare("
            SELECT id, tipo, mensagem, usuario_nome, criado_em
            FROM os_interacoes
            WHERE os_id = ? AND tipo != 'nota_interna'
            ORDER BY criado_em ASC
        ");
        $stmt->bind_param('i', $os_id);
        $stmt->execute();
        $res  = $stmt->get_result();
        $lista = [];
        while ($row = $res->fetch_assoc()) $lista[] = $row;

        portal_os_json(true, 'Interações carregadas', $lista);
        break;

    // ─── ABRIR NOVA OS ───────────────────────────────────────
    case 'abrir':
        $dados      = array_merge($body, $_POST);
        $titulo     = trim($dados['titulo']      ?? '');
        $assunto_id = !empty($dados['assunto_id']) ? (int)$dados['assunto_id'] : null;
        $departamento = trim($dados['departamento'] ?? '');
        $descricao  = trim($dados['descricao']    ?? '');

        // Validações
        if (empty($titulo))     portal_os_json(false, 'Título é obrigatório');
        if (empty($descricao))  portal_os_json(false, 'Descrição/mensagem é obrigatória');
        if (strlen($titulo) > 200) portal_os_json(false, 'Título muito longo (máx. 200 caracteres)');

        // Se assunto_id informado, buscar departamento automaticamente
        if ($assunto_id && empty($departamento)) {
            $res_a = $conn->query("SELECT departamento FROM os_assuntos WHERE id = $assunto_id AND ativo = 1");
            $ass   = $res_a ? $res_a->fetch_assoc() : null;
            if ($ass && !empty($ass['departamento'])) {
                $departamento = $ass['departamento'];
            }
        }

        // Gerar número sequencial (mesmo padrão do sistema interno)
        $ano = date('Y');
        $res_seq = $conn->query("SELECT COUNT(*) as total FROM os_chamados WHERE YEAR(data_abertura) = $ano");
        $seq     = $res_seq ? ((int)$res_seq->fetch_assoc()['total'] + 1) : 1;
        $numero  = 'OS-' . $ano . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

        // Prioridade padrão para OS do portal: média
        // (o atendente deve reclassificar ao assumir)
        $prioridade = 'media';

        // INSERT
        $stmt = $conn->prepare("
            INSERT INTO os_chamados
                (numero, titulo, assunto_id, departamento, prioridade, status,
                 morador_id, morador_nome, morador_unidade,
                 descricao, origem_portal,
                 criado_por_id, criado_por_nome)
            VALUES
                (?, ?, ?, ?, ?, 'aberto',
                 ?, ?, ?,
                 ?, 'portal_morador',
                 ?, ?)
        ");

        $stmt->bind_param(
            'ssissiissii',
            $numero, $titulo, $assunto_id, $departamento, $prioridade,
            $morador_id, $morador_nome, $morador_unidade,
            $descricao,
            $morador_id, $morador_nome
        );

        if (!$stmt->execute()) {
            portal_os_log('erro', 'Erro ao abrir OS', ['error' => $conn->error]);
            portal_os_json(false, 'Erro ao abrir O.S: ' . $conn->error);
        }

        $os_id = $conn->insert_id;

        // Interação inicial automática
        $msg_inicial = "Chamado aberto pelo Portal do Morador. Aguardando atendimento.";
        $stmt_int = $conn->prepare("
            INSERT INTO os_interacoes (os_id, tipo, mensagem, usuario_id, usuario_nome)
            VALUES (?, 'comentario', ?, ?, ?)
        ");
        $stmt_int->bind_param('isis', $os_id, $msg_inicial, $morador_id, $morador_nome);
        $stmt_int->execute();

        portal_os_log('info', 'OS aberta pelo portal', [
            'os_id'      => $os_id,
            'numero'     => $numero,
            'morador_id' => $morador_id,
            'titulo'     => $titulo
        ]);

        portal_os_json(true, "Chamado {$numero} aberto com sucesso! Em breve nossa equipe entrará em contato.", [
            'id'     => $os_id,
            'numero' => $numero
        ]);
        break;

    // ─── AÇÃO INVÁLIDA ───────────────────────────────────────
    default:
        portal_os_log('aviso', 'Ação inválida', ['action' => $action]);
        portal_os_json(false, "Ação inválida: '$action'");
        break;
}
