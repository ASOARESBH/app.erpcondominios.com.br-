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
    'https://asl.erpcondominios.com.br',
    'http://asl.erpcondominios.com.br',
    'https://erpcondominios.com.br',
    'http://localhost',
    'http://127.0.0.1'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . (in_array($origin, $allowed_origins) ? $origin : 'https://asl.erpcondominios.com.br'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (!function_exists('dept_json')) {
    function dept_json($ok, $msg, $data = null) {
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
    dept_json(false, 'Não autenticado: ' . $e->getMessage());
}

// ─── Conexão ──────────────────────────────────────────
$conn = conectar_banco();
if (!$conn) dept_json(false, 'Erro ao conectar ao banco de dados');
$conn->set_charset('utf8mb4');

// ─── Migration automática ─────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS departamentos (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    nome          VARCHAR(100) NOT NULL,
    descricao     VARCHAR(255) DEFAULT NULL,
    ativo         TINYINT(1) NOT NULL DEFAULT 1,
    criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Seed inicial se tabela vazia
$cnt_res = $conn->query("SELECT COUNT(*) as c FROM departamentos");
if ($cnt_res && (int)$cnt_res->fetch_assoc()['c'] === 0) {
    $seeds = [
        'ADMINISTRATIVO','FINANCEIRO','JARDINAGEM','LIMPEZA',
        'MANUTENÇÃO','PORTARIA','RONDA','SEGURANÇA','ZELADORIA'
    ];
    $stmt_seed = $conn->prepare("INSERT IGNORE INTO departamentos (nome) VALUES (?)");
    foreach ($seeds as $s) { $stmt_seed->bind_param('s', $s); $stmt_seed->execute(); }
}

// ─── Ação ─────────────────────────────────────────────
$body = [];
$raw = file_get_contents('php://input');
if ($raw) { $d = json_decode($raw, true); if (!json_last_error()) $body = $d; }
$acao = $_GET['acao'] ?? $body['acao'] ?? '';

switch ($acao) {

    // ─── listar ─────────────────────────────────────────
    case 'listar':
        $ativo = isset($_GET['ativo']) ? (int)$_GET['ativo'] : -1;
        $where = $ativo >= 0 ? "WHERE ativo = $ativo" : '';
        $res   = $conn->query("SELECT * FROM departamentos $where ORDER BY nome ASC");
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

        $nome_esc = $conn->real_escape_string($nome);
        $existe = $conn->query("SELECT id, ativo FROM departamentos WHERE tenant_id = $tenant_id AND nome = '$nome_esc'")->fetch_assoc();
        if ($existe) {
            if (!(int)$existe['ativo']) {
                // Reativar departamento inativo com mesmo nome
                $desc_esc = $conn->real_escape_string($descricao);
                $conn->query("UPDATE departamentos SET ativo=1, descricao='$desc_esc', atualizado_em=NOW() WHERE tenant_id = $tenant_id AND id={$existe['id']}");
                dept_json(true, "Departamento \"$nome\" reativado", ['id' => (int)$existe['id']]);
            }
            dept_json(false, "Departamento \"$nome\" já existe");
        }

        $stmt = $conn->prepare("INSERT INTO departamentos (nome, descricao) VALUES (?, ?)");
        $stmt->bind_param('ss', $nome, $descricao);
        if (!$stmt->execute()) dept_json(false, 'Erro ao criar: ' . $conn->error);
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

        $nome_esc = $conn->real_escape_string($nome);
        $dup = $conn->query("SELECT id FROM departamentos WHERE tenant_id = $tenant_id AND nome='$nome_esc' AND id != $id")->fetch_assoc();
        if ($dup) dept_json(false, "O nome \"$nome\" já existe em outro departamento");

        $stmt = $conn->prepare("UPDATE departamentos SET nome=?, descricao=?, ativo=?, atualizado_em=NOW() WHERE tenant_id = $tenant_id AND id=?");
        $stmt->bind_param('ssii', $nome, $descricao, $ativo, $id);
        $stmt->execute();
        dept_json(true, 'Departamento atualizado com sucesso');
        break;

    // ─── excluir (soft delete) ───────────────────────────
    case 'excluir':
        $id = (int)($body['id'] ?? $_GET['id'] ?? 0);
        if (!$id) dept_json(false, 'ID inválido');
        $conn->query("UPDATE departamentos SET ativo=0, atualizado_em=NOW() WHERE tenant_id = $tenant_id AND id=$id");
        dept_json(true, 'Departamento desativado');
        break;

    default:
        dept_json(false, "Ação inválida: '$acao'");
}
