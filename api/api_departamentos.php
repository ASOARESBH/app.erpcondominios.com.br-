<?php
// =====================================================
// API - DEPARTAMENTOS (Central)
// Versão: 1.0 | Data: 2026-06-29
// =====================================================
// Endpoints (acao via GET ou JSON body):
//   listar        — lista todos (opcional ?ativo=1 ou 0)
//   listar_nomes  — retorna apenas array de nomes (para selects)
//   criar         — cria departamento
//   editar        — edita departamento
//   excluir       — desativa departamento (soft delete)
// =====================================================

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');

ob_start();
require_once 'config.php';
require_once 'auth_helper.php';
require_once 'tenant_helper.php';;
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
$allowed_origins = [
    'https://app.erpcondominios.com.br',
    'https://erpcondominios.com.br',
    'http://localhost',
    'http://127.0.0.1'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, $allowed_origins, true)) {
    http_response_code(403);
    exit;
}
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origin === '') { http_response_code(403); exit; }
    http_response_code(204);
    exit;
}

if (!function_exists('dept_json')) {
    function dept_json($ok, $msg, $data = null, int $statusHttp = 200) {
        http_response_code($statusHttp);
        $r = ['sucesso' => $ok, 'mensagem' => $msg];
        if ($data !== null) $r['dados'] = $data;
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ─── Auth ─────────────────────────────────────────────
try {
    verificarAutenticacao(true, 'operador');
$tenant_id = exigirTenantId();
} catch (Exception $e) {
    dept_json(false, 'Não autenticado', null, 401);
}

// ─── Conexão ──────────────────────────────────────────
$conn = conectar_banco();
if (!$conn) dept_json(false, 'Erro ao conectar ao banco de dados');
$conn->set_charset('utf8mb4');

// ─── Migration automática ─────────────────────────────
$isPostgres = \ERP\Database\DatabaseDialect::isPostgres($conn);
function dept_garantir_schema($conn): void {
    if (\ERP\Database\DatabaseDialect::isPostgres($conn)) return;
    $conn->query("CREATE TABLE IF NOT EXISTS departamentos (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id     INT NOT NULL DEFAULT 1,
        nome          VARCHAR(100) NOT NULL,
        descricao     VARCHAR(255) DEFAULT NULL,
        ativo         TINYINT(1) NOT NULL DEFAULT 1,
        criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_tenant_nome (tenant_id, nome)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
dept_garantir_schema($conn);

if (!\ERP\Database\DatabaseDialect::columnExists($conn, 'departamentos', 'tenant_id')) {
    dept_json(false, 'Schema de departamentos sem isolamento por condomínio.', null, 503);
}

// Seed inicial se tabela vazia
$cnt_stmt = $conn->prepare('SELECT COUNT(*) AS c FROM departamentos WHERE tenant_id = ?');
$cnt_stmt->bind_param('i', $tenant_id);
$cnt_stmt->execute();
$cnt_res = $cnt_stmt->get_result();
$cnt_stmt->close();
if ($cnt_res && (int)$cnt_res->fetch_assoc()['c'] === 0) {
    $seeds = [
        'ADMINISTRATIVO','FINANCEIRO','JARDINAGEM','LIMPEZA',
        'MANUTENÇÃO','PORTARIA','RONDA','SEGURANÇA','ZELADORIA'
    ];
    $sql_seed = $isPostgres
        ? 'INSERT INTO departamentos (tenant_id, nome) VALUES (?, ?) ON CONFLICT DO NOTHING'
        : 'INSERT IGNORE INTO departamentos (tenant_id, nome) VALUES (?, ?)';
    $stmt_seed = $conn->prepare($sql_seed);
    foreach ($seeds as $s) { $stmt_seed->bind_param('is', $tenant_id, $s); $stmt_seed->execute(); }
}

// ─── Ação ─────────────────────────────────────────────
$body = [];
$raw = file_get_contents('php://input');
if ($raw) { $d = json_decode($raw, true); if (!json_last_error()) $body = $d; }
$acao = $_GET['acao'] ?? $body['acao'] ?? '';
$acoesEscrita = ['criar', 'editar', 'excluir'];
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    dept_json(false, 'Método não permitido.', null, 405);
}
if (in_array($acao, $acoesEscrita, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST, OPTIONS');
        dept_json(false, 'Esta ação exige POST.', null, 405);
    }
    if ($origin === '' || !in_array($origin, $allowed_origins, true)) {
        dept_json(false, 'Origem não autorizada.', null, 403);
    }
}

switch ($acao) {

    // ─── listar ─────────────────────────────────────────
    case 'listar':
        $ativo = isset($_GET['ativo']) ? (int)$_GET['ativo'] : -1;
        $where = $ativo >= 0 ? "tenant_id = $tenant_id AND ativo = $ativo" : "tenant_id = $tenant_id";
        $res   = $conn->query("SELECT * FROM departamentos WHERE $where ORDER BY nome ASC");
        $lista = [];
        if ($res) while ($r = $res->fetch_assoc()) $lista[] = $r;
        dept_json(true, 'Departamentos carregados', $lista);
        break;

    // ─── listar_nomes ───────────────────────────────────
    case 'listar_nomes':
        $res   = $conn->query("SELECT nome FROM departamentos WHERE tenant_id = $tenant_id AND ativo=1 ORDER BY nome ASC");
        $nomes = [];
        if ($res) while ($r = $res->fetch_assoc()) $nomes[] = $r['nome'];
        dept_json(true, 'OK', $nomes);
        break;

    // ─── criar ──────────────────────────────────────────
    case 'criar':
        $nome     = strtoupper(trim($body['nome']     ?? ''));
        $descricao = trim($body['descricao'] ?? '');
        if (!$nome) dept_json(false, 'Nome é obrigatório');
        if (mb_strlen($nome) > 100) dept_json(false, 'Nome muito longo (máx 100 caracteres)');

        $stmt = $conn->prepare('SELECT id, ativo FROM departamentos WHERE tenant_id = ? AND nome = ? LIMIT 1');
        if (!$stmt || !$stmt->bind_param('is', $tenant_id, $nome) || !$stmt->execute()) {
            dept_json(false, 'Não foi possível validar o departamento.', null, 503);
        }
        $res = $stmt->get_result();
        $existe = $res ? $res->fetch_assoc() : null;
        if ($res) $res->free();
        $stmt->close();
        if ($existe) {
            if (!(int)$existe['ativo']) {
                // Reativar departamento inativo com mesmo nome
                $idExistente = (int)$existe['id'];
                $stmt = $conn->prepare('UPDATE departamentos SET ativo=1, descricao=?, atualizado_em=CURRENT_TIMESTAMP WHERE tenant_id=? AND id=?');
                if (!$stmt || !$stmt->bind_param('sii', $descricao, $tenant_id, $idExistente) || !$stmt->execute()) {
                    dept_json(false, 'Não foi possível reativar o departamento.', null, 503);
                }
                $stmt->close();
                dept_json(true, "Departamento \"$nome\" reativado", ['id' => (int)$existe['id']]);
            }
            dept_json(false, "Departamento \"$nome\" já existe");
        }

        $stmt = $conn->prepare("INSERT INTO departamentos (tenant_id, nome, descricao) VALUES (?, ?, ?)");
        if (!$stmt || !$stmt->bind_param('iss', $tenant_id, $nome, $descricao) || !$stmt->execute()) {
            dept_json(false, 'Não foi possível criar o departamento.', null, 503);
        }
        dept_json(true, "Departamento \"$nome\" criado com sucesso", ['id' => $conn->insert_id]);
        break;

    // ─── editar ─────────────────────────────────────────
    case 'editar':
        $id       = (int)($body['id']    ?? 0);
        $nome     = strtoupper(trim($body['nome'] ?? ''));
        $descricao = trim($body['descricao'] ?? '');
        $ativo    = isset($body['ativo']) ? (int)$body['ativo'] : 1;
        if (!$id)   dept_json(false, 'ID inválido');
        if (!$nome) dept_json(false, 'Nome é obrigatório');

        if (!in_array($ativo, [0, 1], true)) dept_json(false, 'Estado inválido');
        $stmt = $conn->prepare('SELECT id FROM departamentos WHERE tenant_id=? AND nome=? AND id<>? LIMIT 1');
        if (!$stmt || !$stmt->bind_param('isi', $tenant_id, $nome, $id) || !$stmt->execute()) {
            dept_json(false, 'Não foi possível validar o departamento.', null, 503);
        }
        $res = $stmt->get_result();
        $dup = $res ? $res->fetch_assoc() : null;
        if ($res) $res->free();
        $stmt->close();
        if ($dup) dept_json(false, "O nome \"$nome\" já existe em outro departamento");

        $stmt = $conn->prepare('SELECT id FROM departamentos WHERE tenant_id=? AND id=? LIMIT 1');
        if (!$stmt || !$stmt->bind_param('ii', $tenant_id, $id) || !$stmt->execute()) {
            dept_json(false, 'Não foi possível validar o departamento.', null, 503);
        }
        $res = $stmt->get_result();
        $exists = $res ? $res->fetch_assoc() : null;
        if ($res) $res->free();
        $stmt->close();
        if (!$exists) dept_json(false, 'Departamento não encontrado.', null, 404);

        $stmt = $conn->prepare('UPDATE departamentos SET nome=?, descricao=?, ativo=?, atualizado_em=CURRENT_TIMESTAMP WHERE tenant_id=? AND id=?');
        if (!$stmt || !$stmt->bind_param('ssiii', $nome, $descricao, $ativo, $tenant_id, $id) || !$stmt->execute()) {
            dept_json(false, 'Não foi possível atualizar o departamento.', null, 503);
        }
        $stmt->close();
        dept_json(true, 'Departamento atualizado com sucesso');
        break;

    // ─── excluir (soft delete) ───────────────────────────
    case 'excluir':
        $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
        if (!$id) dept_json(false, 'ID inválido');
        $stmt = $conn->prepare('UPDATE departamentos SET ativo=0, atualizado_em=CURRENT_TIMESTAMP WHERE tenant_id=? AND id=?');
        if (!$stmt || !$stmt->bind_param('ii', $tenant_id, $id) || !$stmt->execute()) {
            dept_json(false, 'Não foi possível desativar o departamento.', null, 503);
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected < 1) dept_json(false, 'Departamento não encontrado ou já desativado.', null, 404);
        dept_json(true, 'Departamento desativado');
        break;

    default:
        dept_json(false, "Ação inválida: '$acao'");
}
