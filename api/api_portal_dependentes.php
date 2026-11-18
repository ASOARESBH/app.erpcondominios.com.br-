<?php
/**
 * =====================================================
 * API: DEPENDENTES DO PORTAL DO MORADOR
 * =====================================================
 * 
 * Gerencia dependentes do morador logado no portal.
 * Autenticação via token Bearer (sessoes_portal) ou sessão PHP.
 * 
 * Endpoints:
 *   GET    ?action=listar       → Lista dependentes do morador
 *   POST   ?action=criar        → Cadastra novo dependente
 *   PUT    ?action=atualizar&id=X → Atualiza dependente
 *   DELETE ?action=excluir&id=X → Exclui dependente
 * 
 * @author Sistema ERP Condomínio
 * @version 1.0
 * @date 2026-03-22
 */

session_start();
ob_start();

require_once 'config.php';
require_once 'auth_helper.php';
require_once __DIR__ . '/helpers/pwa_security.php';

// Função JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null, $statusHttp = null) {
        if ($statusHttp !== null) http_response_code((int)$statusHttp);
        header('Content-Type: application/json; charset=utf-8');
        $resposta = ['sucesso' => $sucesso, 'mensagem' => $mensagem];
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
$_portalOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$_portalOriginPermitida = $_portalOrigin !== '' && pwa_origem_permitida($_portalOrigin);
if ($_portalOrigin !== '' && !$_portalOriginPermitida) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Origem não autorizada.']);
    exit;
}
if ($_portalOrigin !== '' && $_portalOriginPermitida) {
    header('Access-Control-Allow-Origin: ' . $_portalOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
$portalHeaders = function_exists('getallheaders') ? getallheaders() : [];
$portalAuthorization = $portalHeaders['Authorization'] ?? $portalHeaders['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$portalBearerApresentado = is_string($portalAuthorization)
    && preg_match('/^Bearer\s+\S+$/i', trim($portalAuthorization)) === 1;
$conexao = conectar_banco();
$metodo  = $_SERVER['REQUEST_METHOD'];
$action  = $_GET['action'] ?? '';
if (!$conexao) {
    retornar_json(false, 'O serviço de dependentes está temporariamente indisponível.', null, 503);
}
if (\ERP\Database\DatabaseDialect::isPostgres($conexao)) {
    retornar_json(false, 'O serviço de dependentes está temporariamente indisponível.', null, 503);
}
if (!in_array($metodo, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
    header('Allow: GET, POST, PUT, DELETE, OPTIONS');
    retornar_json(false, 'Método não permitido.', null, 405);
}
if (in_array($metodo, ['POST', 'PUT', 'DELETE'], true) && !$_portalOriginPermitida && !$portalBearerApresentado) {
    retornar_json(false, 'Origem não autorizada.', null, 403);
}

// ─── Autenticação: Token Bearer OU Sessão PHP ─────────────────────────────
function autenticar_morador_portal($conexao) {
    // 1. Tentar via token Bearer
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $headers[str_replace('_', '-', substr($k, 5))] = $v;
            }
        }
    }

    $auth_header = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($auth_header !== '') {
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $matches)) {
            retornar_json(false, 'Sessão inválida. Faça login novamente.', null, 401);
        }
        $token = $matches[1];
        $tenantFilter = \ERP\Database\DatabaseDialect::columnExists($conexao, 'sessoes_portal', 'tenant_id')
            ? ' AND sp.tenant_id = m.tenant_id'
            : '';
        $stmt  = $conexao->prepare(
            "SELECT sp.morador_id FROM sessoes_portal sp
             JOIN moradores m ON m.id = sp.morador_id
             WHERE sp.token = ? AND sp.ativo = 1 AND sp.data_expiracao > NOW() AND m.ativo = 1
               $tenantFilter
             LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('portal_session_prepare_failed');
        $stmt->bind_param('s', $token);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('portal_session_execute_failed');
        }
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $stmt->close();
            return (int)$row['morador_id'];
        }
        $stmt->close();
        retornar_json(false, 'Sessão inválida. Faça login novamente.', null, 401);
    }

    // 2. Tentar via sessão PHP
    if (isset($_SESSION['morador_logado']) && $_SESSION['morador_logado'] === true) {
        $moradorIdSessao = (int)($_SESSION['morador_id'] ?? 0);
        if ($moradorIdSessao <= 0) return null;
        $stmt = $conexao->prepare('SELECT id FROM moradores WHERE id = ? AND ativo = 1 LIMIT 1');
        if (!$stmt) throw new RuntimeException('portal_session_morador_prepare_failed');
        $stmt->bind_param('i', $moradorIdSessao);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('portal_session_morador_execute_failed');
        }
        $moradorAtivo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $moradorAtivo ? $moradorIdSessao : null;
    }

    retornar_json(false, 'Sessão inválida. Faça login novamente.', null, 401);
}

try {
    $morador_id = autenticar_morador_portal($conexao);
    if (!$morador_id) {
        retornar_json(false, 'Sessão inválida. Faça login novamente.', null, 401);
    }
} catch (Throwable $e) {
    retornar_json(false, 'Não foi possível validar a sessão. Tente novamente.', null, 503);
}
$portalDependentesPg = false;

// ─── Verificar/criar tabela dependentes ───────────────────────────────────
if (!$portalDependentesPg) {
    $chk = $conexao->query("SHOW TABLES LIKE 'dependentes'");
    if (!$chk || $chk->num_rows === 0) {
        $sql_create = "CREATE TABLE IF NOT EXISTS `dependentes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `morador_id` int(11) NOT NULL,
            `nome_completo` varchar(200) NOT NULL,
            `cpf` varchar(14) DEFAULT NULL,
            `parentesco` varchar(50) DEFAULT NULL,
            `data_nascimento` date DEFAULT NULL,
            `email` varchar(200) DEFAULT NULL,
            `telefone` varchar(20) DEFAULT NULL,
            `celular` varchar(20) DEFAULT NULL,
            `observacao` text DEFAULT NULL,
            `ativo` tinyint(1) DEFAULT 1,
            `data_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `tenant_id` (`tenant_id`),
            KEY `morador_id` (`morador_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $conexao->query($sql_create);
    }
}
if (!\ERP\Database\DatabaseDialect::columnExists($conexao, 'dependentes', 'tenant_id')) {
    retornar_json(false, 'O serviço de dependentes está temporariamente indisponível.', null, 503);
}

$morador_tenant_id = null;
try {
    if (\ERP\Database\DatabaseDialect::columnExists($conexao, 'moradores', 'tenant_id')) {
        $stmtTenantMorador = $conexao->prepare('SELECT tenant_id FROM moradores WHERE id = ? LIMIT 1');
        if (!$stmtTenantMorador) throw new RuntimeException('portal_tenant_prepare_failed');
        $stmtTenantMorador->bind_param('i', $morador_id);
        if (!$stmtTenantMorador->execute()) throw new RuntimeException('portal_tenant_execute_failed');
        $tenantMoradorRow = $stmtTenantMorador->get_result()->fetch_assoc();
        $stmtTenantMorador->close();
        $morador_tenant_id = (int)($tenantMoradorRow['tenant_id'] ?? 0);
        if ($morador_tenant_id <= 0) {
            retornar_json(false, 'Contexto de condomínio inválido.', null, 403);
        }
    } else {
        retornar_json(false, 'O serviço de dependentes está temporariamente indisponível.', null, 503);
    }
} catch (Throwable $e) {
    retornar_json(
        false,
        $portalDependentesPg ? 'O serviço de dependentes está temporariamente indisponível.' : 'Contexto de condomínio inválido.',
        null,
        503
    );
}

// ─── GET: Listar dependentes ───────────────────────────────────────────────
if ($metodo === 'GET' && $action === 'listar') {
    $stmt = $conexao->prepare(
        "SELECT id, nome_completo, cpf, parentesco,
                DATE_FORMAT(data_nascimento, '%d/%m/%Y') as data_nascimento,
                email, telefone, celular, observacao, ativo,
                DATE_FORMAT(data_cadastro, '%d/%m/%Y %H:%i') as data_cadastro
         FROM dependentes
         WHERE tenant_id = ? AND morador_id = ?
         ORDER BY nome_completo ASC"
    );
    $stmt->bind_param('ii', $morador_tenant_id, $morador_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $dependentes = [];
    while ($row = $res->fetch_assoc()) {
        $dependentes[] = $row;
    }
    $stmt->close();
    fechar_conexao($conexao);

    retornar_json(true, 'Dependentes carregados', $dependentes);
}

// ─── POST: Criar dependente ────────────────────────────────────────────────
if ($metodo === 'POST' && $action === 'criar') {
    $dados = json_decode(file_get_contents('php://input'), true);
    if (!$dados) $dados = $_POST;

    $nome_completo   = trim($dados['nome_completo'] ?? '');
    $cpf             = preg_replace('/[^0-9]/', '', $dados['cpf'] ?? '');
    $parentesco      = trim($dados['parentesco'] ?? '');
    $data_nascimento = trim($dados['data_nascimento'] ?? '');
    $email           = trim($dados['email'] ?? '');
    $telefone        = trim($dados['telefone'] ?? '');
    $celular         = trim($dados['celular'] ?? '');
    $observacao      = trim($dados['observacao'] ?? '');

    if (empty($nome_completo)) {
        retornar_json(false, 'O nome completo é obrigatório.', null, 422);
    }

    // Verificar CPF duplicado (se informado)
    if (!empty($cpf)) {
        if ($morador_tenant_id !== null) {
            $stmt = $conexao->prepare("SELECT d.id FROM dependentes d INNER JOIN moradores m ON m.id = d.morador_id WHERE d.tenant_id = ? AND m.tenant_id = ? AND REPLACE(REPLACE(REPLACE(d.cpf, '.', ''), '-', ''), ' ', '') = ? LIMIT 1");
            if ($stmt) $stmt->bind_param('iis', $morador_tenant_id, $morador_tenant_id, $cpf);
        } else {
            $stmt = $conexao->prepare("SELECT id FROM dependentes WHERE morador_id = ? AND REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ? LIMIT 1");
            if ($stmt) $stmt->bind_param('is', $morador_id, $cpf);
        }
        if (!$stmt || !$stmt->execute()) {
            if ($stmt) $stmt->close();
            retornar_json(false, 'Não foi possível validar o cadastro. Tente novamente.', null, 503);
        }
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $stmt->close();
            fechar_conexao($conexao);
            retornar_json(false, 'CPF já cadastrado no sistema.', null, 409);
        }
        $stmt->close();
    }

    // Formatar CPF para salvar
    $cpf_fmt = '';
    if (strlen($cpf) === 11) {
        $cpf_fmt = substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
    } else {
        $cpf_fmt = $dados['cpf'] ?? '';
    }

    // Data nascimento
    $dn = null;
    if (!empty($data_nascimento)) {
        // Aceita dd/mm/yyyy ou yyyy-mm-dd
        if (strpos($data_nascimento, '/') !== false) {
            $partes = explode('/', $data_nascimento);
            if (count($partes) === 3) {
                $dn = $partes[2] . '-' . $partes[1] . '-' . $partes[0];
            }
        } else {
            $dn = $data_nascimento;
        }
    }

    $stmt = $conexao->prepare(
        "INSERT INTO dependentes (tenant_id, morador_id, nome_completo, cpf, parentesco, data_nascimento, email, telefone, celular, observacao, ativo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
    );
    $stmt->bind_param('iissssssss', $morador_tenant_id, $morador_id, $nome_completo, $cpf_fmt, $parentesco, $dn, $email, $telefone, $celular, $observacao);

    if ($stmt->execute()) {
        $id = $conexao->insert_id;
        $stmt->close();
        fechar_conexao($conexao);
        registrar_log('PORTAL_DEPENDENTE_CRIADO', 'Dependente criado.', null, false);
        retornar_json(true, 'Dependente cadastrado com sucesso', ['id' => $id]);
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        error_log('[api_portal_dependentes] Falha ao inserir dependente.');
        retornar_json(false, 'Erro ao cadastrar dependente');
    }
}

// ─── PUT: Atualizar dependente ─────────────────────────────────────────────
if ($metodo === 'PUT' && $action === 'atualizar') {
    $id    = (int)($_GET['id'] ?? 0);
    $dados = json_decode(file_get_contents('php://input'), true);

    if ($id <= 0) {
        retornar_json(false, 'ID inválido.', null, 400);
    }

    // Verificar se o dependente pertence ao morador
    $stmt = $conexao->prepare("SELECT id FROM dependentes WHERE id = ? AND morador_id = ? AND tenant_id = ?");
    $stmt->bind_param('iii', $id, $morador_id, $morador_tenant_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Dependente não encontrado.', null, 404);
    }
    $stmt->close();

    $nome_completo   = trim($dados['nome_completo'] ?? '');
    $parentesco      = trim($dados['parentesco'] ?? '');
    $data_nascimento = trim($dados['data_nascimento'] ?? '');
    $email           = trim($dados['email'] ?? '');
    $telefone        = trim($dados['telefone'] ?? '');
    $celular         = trim($dados['celular'] ?? '');
    $observacao      = trim($dados['observacao'] ?? '');

    if (empty($nome_completo)) {
        retornar_json(false, 'O nome completo é obrigatório.', null, 422);
    }

    // Data nascimento
    $dn = null;
    if (!empty($data_nascimento)) {
        if (strpos($data_nascimento, '/') !== false) {
            $partes = explode('/', $data_nascimento);
            if (count($partes) === 3) {
                $dn = $partes[2] . '-' . $partes[1] . '-' . $partes[0];
            }
        } else {
            $dn = $data_nascimento;
        }
    }

    $stmt = $conexao->prepare(
        "UPDATE dependentes SET nome_completo = ?, parentesco = ?, data_nascimento = ?,
         email = ?, telefone = ?, celular = ?, observacao = ?
         WHERE id = ? AND morador_id = ? AND tenant_id = ?"
    );
    $stmt->bind_param('sssssssiii', $nome_completo, $parentesco, $dn, $email, $telefone, $celular, $observacao, $id, $morador_id, $morador_tenant_id);

    if ($stmt->execute()) {
        $stmt->close();
        fechar_conexao($conexao);
        registrar_log('PORTAL_DEPENDENTE_ATUALIZADO', 'Dependente atualizado.', null, false);
        retornar_json(true, 'Dependente atualizado com sucesso');
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Não foi possível atualizar o dependente.', null, 503);
    }
}

// ─── DELETE: Excluir dependente ────────────────────────────────────────────
if ($metodo === 'DELETE' && $action === 'excluir') {
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        retornar_json(false, 'ID inválido.', null, 400);
    }

    // Verificar se pertence ao morador
    $stmt = $conexao->prepare("SELECT nome_completo FROM dependentes WHERE id = ? AND morador_id = ? AND tenant_id = ?");
    $stmt->bind_param('iii', $id, $morador_id, $morador_tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Dependente não encontrado.', null, 404);
    }
    $dep = $res->fetch_assoc();
    $stmt->close();

    $stmt = $conexao->prepare("DELETE FROM dependentes WHERE id = ? AND morador_id = ? AND tenant_id = ?");
    $stmt->bind_param('iii', $id, $morador_id, $morador_tenant_id);

    if ($stmt->execute()) {
        $stmt->close();
        fechar_conexao($conexao);
        registrar_log('PORTAL_DEPENDENTE_EXCLUIDO', 'Dependente excluído.', null, false);
        retornar_json(true, 'Dependente excluído com sucesso');
    } else {
        $stmt->close();
        fechar_conexao($conexao);
        retornar_json(false, 'Não foi possível excluir o dependente.', null, 503);
    }
}

fechar_conexao($conexao);
retornar_json(false, 'Ação não encontrada ou método incorreto.', null, 400);
?>
