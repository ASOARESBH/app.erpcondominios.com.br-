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
            'errno' => $errno,
        'errfile' => basename($errfile), 'errline' => $errline
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
    if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso'=>false,'mensagem'=>'Erro interno. Tente novamente ou contate o administrador.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return false;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $log_file = __DIR__ . '/../logs/debug_portal_os.log';
        $entry = date('Y-m-d H:i:s') . ' | FATAL | ' . json_encode([
            'type' => $error['type'], 'file' => basename($error['file']), 'line' => $error['line'],
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['sucesso'=>false,'mensagem'=>'Erro interno. Tente novamente ou contate o administrador.'], JSON_UNESCAPED_UNICODE);
        }
    }
});

// ─── Sessão e dependências ───────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
ob_start();
require_once 'config.php';
require_once __DIR__ . '/helpers/os_numbering.php';
ob_end_clean();

// ─── Headers ─────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$allowed_origins = [
    'https://app.erpcondominios.com.br',
    'https://erpcondominios.com.br',
    'https://www.erpcondominios.com.br',
    'http://localhost',
    'http://127.0.0.1',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, $allowed_origins, true)) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Origem não autorizada.', 'dados' => null], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
if ($origin !== '') header('Access-Control-Allow-Credentials: true');
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

function portal_os_json($sucesso, $mensagem, $dados = null, int $statusHttp = 200) {
    if ($statusHttp < 100 || $statusHttp > 599) $statusHttp = 500;
    http_response_code($statusHttp);
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

    if (!$token && !empty($_SERVER['HTTP_AUTHORIZATION'])
        && preg_match('/^Bearer\s+(.+)$/i', (string)$_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $token = trim($m[1]);
    }
    if (!$token) return null;

    $tenantSessionFilter = \ERP\Database\DatabaseDialect::columnExists($conn, 'sessoes_portal', 'tenant_id')
        ? ' AND sp.tenant_id = m.tenant_id'
        : '';
    $stmt = $conn->prepare("
        SELECT sp.morador_id, m.nome, m.unidade, m.tenant_id
        FROM sessoes_portal sp
        JOIN moradores m ON m.id = sp.morador_id
        WHERE sp.token = ? AND sp.ativo = 1 AND sp.data_expiracao > NOW() AND m.ativo = 1
          $tenantSessionFilter
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('s', $token);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $morador = $res->fetch_assoc();
        $stmt->close();
        return $morador;
    }
    $stmt->close();
    return null;
}

// ─── Conexão ─────────────────────────────────────────────────
$conn = conectar_banco();
if (!$conn) {
    portal_os_json(false, 'Erro de conexão com o banco de dados', null, 503);
}
if (\ERP\Database\DatabaseDialect::isPostgres($conn)) {
    portal_os_json(false, 'Portal de Ordens de Serviço ainda não está disponível neste banco.', null, 503);
}
foreach ([
    'sessoes_portal' => ['token', 'morador_id', 'ativo', 'data_expiracao'],
    'moradores' => ['id', 'tenant_id', 'ativo', 'nome', 'unidade'],
    'os_chamados' => [
        'id', 'tenant_id', 'numero', 'titulo', 'assunto_id', 'departamento', 'prioridade', 'status',
        'morador_id', 'morador_nome', 'morador_unidade', 'descricao', 'origem_portal',
        'criado_por_id', 'criado_por_nome', 'assumido_por_nome', 'data_abertura', 'data_inicio',
        'data_previsao', 'data_finalizacao',
    ],
    'os_assuntos' => ['id', 'tenant_id', 'nome', 'departamento', 'ativo'],
    'os_interacoes' => ['id', 'tenant_id', 'os_id', 'tipo', 'mensagem', 'usuario_id', 'usuario_nome', 'criado_em'],
] as $tabela => $colunas) {
    if (!\ERP\Database\DatabaseDialect::tableExists($conn, $tabela)) {
        http_response_code(503);
        portal_os_json(false, 'Estrutura do Portal O.S. ainda não está pronta.', null, 503);
    }
    foreach ($colunas as $coluna) {
        if (!\ERP\Database\DatabaseDialect::columnExists($conn, $tabela, $coluna)) {
            http_response_code(503);
            portal_os_json(false, 'Estrutura do Portal O.S. ainda não está pronta.', null, 503);
        }
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    portal_os_json(false, 'Método não permitido.', null, 405);
}
$body   = [];
if ($requestMethod === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
}

portal_os_log('info', 'Requisição recebida', ['action' => $action, 'method' => $_SERVER['REQUEST_METHOD']]);

// ─── Autenticação obrigatória para demais rotas ───────────────
try {
    $morador = portal_os_autenticar($conn);
} catch (Throwable $e) {
    portal_os_json(false, 'Não foi possível validar a sessão. Tente novamente.', null, 503);
}
if (!$morador) {
    portal_os_json(false, 'Não autorizado. Faça login novamente.', null, 401);
}

$morador_id     = (int)$morador['morador_id'];
$tenant_id      = (int)($morador['tenant_id'] ?? 0);
$morador_nome   = $morador['nome']    ?? 'Morador';
$morador_unidade = $morador['unidade'] ?? '';
if ($tenant_id <= 0) {
    portal_os_json(false, 'Vínculo do condomínio não está disponível.', null, 503);
}
if ($action === 'abrir') {
    if ($requestMethod !== 'POST') portal_os_json(false, 'Esta ação exige POST.', null, 405);
}

if ($action === 'listar_assuntos') {
    $stmtAssuntos = $conn->prepare('SELECT id, nome, departamento FROM os_assuntos WHERE tenant_id = ? AND ativo = 1 ORDER BY nome ASC');
    if (!$stmtAssuntos) { portal_os_json(false, 'Não foi possível carregar os assuntos.', null, 503); }
    $stmtAssuntos->bind_param('i', $tenant_id);
    if (!$stmtAssuntos->execute()) { portal_os_json(false, 'Não foi possível carregar os assuntos.', null, 503); }
    $lista = $stmtAssuntos->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtAssuntos->close();
    portal_os_json(true, 'Assuntos carregados', $lista);
}

portal_os_log('info', 'Morador autenticado', ['action' => $action]);

// ─── Rotas autenticadas ───────────────────────────────────────
switch ($action) {

    // ─── LISTAR OS DO MORADOR ────────────────────────────────
    case 'listar':
        $status  = $_GET['status'] ?? '';
        $pagina  = max(1, (int)($_GET['pagina'] ?? 1));
        $por_pag = 20;
        $offset  = ($pagina - 1) * $por_pag;

        $where  = ['o.tenant_id = ?', 'o.morador_id = ?'];
        $params = [$tenant_id, $morador_id];
        $types  = 'ii';

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
                (SELECT COUNT(*) FROM os_interacoes i WHERE i.tenant_id = o.tenant_id AND i.os_id = o.id) as total_interacoes
            FROM os_chamados o
            LEFT JOIN os_assuntos a ON a.tenant_id = o.tenant_id AND a.id = o.assunto_id
            WHERE $where_sql
            ORDER BY
                CASE o.status WHEN 'aberto' THEN 1 WHEN 'andamento' THEN 2 WHEN 'finalizado' THEN 3 WHEN 'cancelado' THEN 4 ELSE 0 END,
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
        if (!$id) portal_os_json(false, 'ID inválido.', null, 400);

        $stmt = $conn->prepare("
            SELECT
                o.*,
                a.nome as assunto_nome
            FROM os_chamados o
            LEFT JOIN os_assuntos a ON a.tenant_id = o.tenant_id AND a.id = o.assunto_id
            WHERE o.tenant_id = ? AND o.id = ? AND o.morador_id = ?
        ");
        $stmt->bind_param('iii', $tenant_id, $id, $morador_id);
        $stmt->execute();
        $os = $stmt->get_result()->fetch_assoc();

        if (!$os) portal_os_json(false, 'OS não encontrada ou sem permissão de acesso.', null, 404);

        portal_os_json(true, 'OS encontrada', $os);
        break;

    // ─── LISTAR INTERAÇÕES/MOVIMENTAÇÕES ────────────────────
    case 'listar_interacoes':
        $os_id = (int)($_GET['os_id'] ?? $body['os_id'] ?? 0);
        if (!$os_id) portal_os_json(false, 'os_id inválido.', null, 400);

        // Verificar se a OS pertence ao morador
        $stmt = $conn->prepare("SELECT id FROM os_chamados WHERE tenant_id = ? AND id = ? AND morador_id = ?");
        $stmt->bind_param('iii', $tenant_id, $os_id, $morador_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            portal_os_json(false, 'OS não encontrada ou sem permissão.', null, 404);
        }

        // Buscar interações (excluir notas internas — visíveis só para atendentes)
        $stmt = $conn->prepare("
            SELECT id, tipo, mensagem, usuario_nome, criado_em
            FROM os_interacoes
            WHERE tenant_id = ? AND os_id = ? AND tipo != 'nota_interna'
            ORDER BY criado_em ASC
        ");
        $stmt->bind_param('ii', $tenant_id, $os_id);
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
        if (empty($titulo))     portal_os_json(false, 'Título é obrigatório.', null, 422);
        if (empty($descricao))  portal_os_json(false, 'Descrição/mensagem é obrigatória.', null, 422);
        if (strlen($titulo) > 200) portal_os_json(false, 'Título muito longo (máx. 200 caracteres).', null, 422);

        // Se assunto_id informado, buscar departamento automaticamente
        if ($assunto_id) {
            $stmtAssunto = $conn->prepare('SELECT departamento FROM os_assuntos WHERE tenant_id = ? AND id = ? AND ativo = 1');
            $stmtAssunto->bind_param('ii', $tenant_id, $assunto_id);
            $stmtAssunto->execute();
            $res_a = $stmtAssunto->get_result();
            $ass   = $res_a ? $res_a->fetch_assoc() : null;
            $stmtAssunto->close();
            if (!$ass) portal_os_json(false, 'Assunto não encontrado neste condomínio.', null, 404);
            if (empty($departamento) && !empty($ass['departamento'])) {
                $departamento = $ass['departamento'];
            }
        }

        // O índice de numero é global; serialize a leitura do máximo com a inserção.
        $ano = date('Y');
        if (!os_adquirir_lock_numeracao($conn, (int)$ano)) {
            http_response_code(503);
            portal_os_json(false, 'Não foi possível reservar a numeração da O.S. Tente novamente.', null, 503);
        }
        $prefixoNumero = '%-' . $ano . '-%';
        $stmtNumeros = null;
        try {
            $stmtNumeros = $conn->prepare('SELECT numero FROM os_chamados WHERE numero LIKE ?');
            if (!$stmtNumeros || !$stmtNumeros->bind_param('s', $prefixoNumero) || !$stmtNumeros->execute()) {
                throw new RuntimeException('portal_os_number_scan_failed');
            }
            $resultadoNumeros = $stmtNumeros->get_result();
            if (!$resultadoNumeros) throw new RuntimeException('portal_os_number_result_failed');
            $numeros = $resultadoNumeros->fetch_all(MYSQLI_ASSOC);
            $stmtNumeros->close();
            $stmtNumeros = null;
        } catch (Throwable $e) {
            if ($stmtNumeros) $stmtNumeros->close();
            os_liberar_lock_numeracao($conn, (int)$ano);
            portal_os_log('erro', 'Falha ao consultar a numeração de O.S.', ['tipo_erro' => get_class($e)]);
            http_response_code(503);
            portal_os_json(false, 'Não foi possível verificar a numeração da O.S.', null, 503);
        }
        $seq = os_sequencia_numero_anual($numeros, (int)$ano);
        $numero = os_formatar_numero_anual((int)$ano, $seq, 'OS-');

        // Prioridade padrão para OS do portal: média
        // (o atendente deve reclassificar ao assumir)
        $prioridade = 'media';

        // INSERT
        $stmt = $conn->prepare("
            INSERT INTO os_chamados
                (tenant_id, numero, titulo, assunto_id, departamento, prioridade, status,
                 morador_id, morador_nome, morador_unidade,
                 descricao, origem_portal,
                 criado_por_id, criado_por_nome)
            VALUES
                (?, ?, ?, ?, ?, ?, 'aberto',
                 ?, ?, ?,
                 ?, 'portal_morador',
                 ?, ?)
        ");

        if (!$stmt || !$conn->begin_transaction()) {
            os_liberar_lock_numeracao($conn, (int)$ano);
            http_response_code(503);
            portal_os_json(false, 'Não foi possível iniciar a abertura da O.S.', null, 503);
        }

        try {
            if (!$stmt->bind_param(
                'issississsis',
                $tenant_id, $numero, $titulo, $assunto_id, $departamento, $prioridade,
                $morador_id, $morador_nome, $morador_unidade,
                $descricao,
                $morador_id, $morador_nome
            )) throw new RuntimeException('portal_os_bind_failed');
            if (!$stmt->execute()) throw new RuntimeException('portal_os_insert_failed');
            $os_id = (int)$conn->insert_id;
            $stmt->close();
            if ($os_id <= 0) throw new RuntimeException('portal_os_insert_id_invalid');

            $msg_inicial = 'Chamado aberto pelo Portal do Morador. Aguardando atendimento.';
            $stmt_int = $conn->prepare(
                "INSERT INTO os_interacoes (tenant_id, os_id, tipo, mensagem, usuario_id, usuario_nome)
                 VALUES (?, ?, 'comentario', ?, ?, ?)"
            );
            if (!$stmt_int) throw new RuntimeException('portal_os_interaction_prepare_failed');
            if (!$stmt_int->bind_param('iisis', $tenant_id, $os_id, $msg_inicial, $morador_id, $morador_nome)) {
                $stmt_int->close();
                throw new RuntimeException('portal_os_interaction_bind_failed');
            }
            if (!$stmt_int->execute()) { $stmt_int->close(); throw new RuntimeException('portal_os_interaction_insert_failed'); }
            $stmt_int->close();
            if (!$conn->commit()) throw new RuntimeException('portal_os_commit_failed');
        } catch (Throwable $e) {
            $conn->rollback();
            os_liberar_lock_numeracao($conn, (int)$ano);
            portal_os_log('erro', 'Falha transacional ao abrir O.S. Portal', ['tipo_erro' => get_class($e)]);
            http_response_code(503);
            portal_os_json(false, 'Não foi possível abrir a O.S. Tente novamente.', null, 503);
        }
        if (!os_liberar_lock_numeracao($conn, (int)$ano)) {
            error_log('Não foi possível liberar o lock de numeração da O.S. Portal.');
        }

        portal_os_log('info', 'OS aberta pelo portal', [
            'os_id'      => $os_id,
            'numero'     => $numero
        ]);

        portal_os_json(true, "Chamado {$numero} aberto com sucesso! Em breve nossa equipe entrará em contato.", [
            'id'     => $os_id,
            'numero' => $numero
        ]);
        break;

    // ─── AÇÃO INVÁLIDA ───────────────────────────────────────
    default:
        portal_os_log('aviso', 'Ação inválida');
        portal_os_json(false, 'Ação inválida.', null, 400);
        break;
}
