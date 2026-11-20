<?php
ob_start();
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('erp_responder_configuracao_indisponivel')) {
    function erp_responder_configuracao_indisponivel(): void {
        if (ob_get_level() > 0) ob_end_clean();
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'sucesso' => false,
            'mensagem' => 'Configuração de serviço indisponível. Tente novamente mais tarde.',
            'dados' => (object)[],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

require_once 'config.php';
require_once __DIR__ . '/db/DatabaseDialect.php';

header('Content-Type: application/json; charset=utf-8');

function logout_morador_responder(bool $sucesso, string $mensagem, int $status): void {
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    echo json_encode(['sucesso' => $sucesso, 'mensagem' => $mensagem, 'dados' => (object)[]], JSON_UNESCAPED_UNICODE);
    exit;
}

function logout_morador_limpar_sessao(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION = [];
    session_destroy();
    session_write_close();
}

$metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
if (!in_array($metodo, ['GET', 'POST'], true)) {
    logout_morador_responder(false, 'Método não permitido.', 405);
}

$moradorId = (int)($_SESSION['morador_id'] ?? 0);
$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$tokenPortal = (string)($_SESSION['morador_portal_token'] ?? '');
$sessaoPortalAtiva = $moradorId > 0 || !empty($_SESSION['morador_logado']);
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authorization = (string)($headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '');
$tokenHeader = '';
if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $match)) {
    $tokenHeader = trim($match[1]);
}
if ($tokenHeader !== '' && $tokenPortal !== '' && !hash_equals($tokenPortal, $tokenHeader)) {
    logout_morador_responder(false, 'Sessão não localizada para encerramento.', 401);
}
$tokenParaRevogar = $tokenPortal !== '' ? $tokenPortal : $tokenHeader;
if ($sessaoPortalAtiva && $tokenParaRevogar === '') {
    logout_morador_responder(false, 'Sessão não localizada para encerramento.', 401);
}

if ($tokenParaRevogar !== '') {
    $conexao = null;
    try {
        $conexao = conectar_banco();
        $isPostgres = \ERP\Database\DatabaseDialect::isPostgres($conexao);
        $temTenantSessao = \ERP\Database\DatabaseDialect::columnExists($conexao, 'sessoes_portal', 'tenant_id');
        $temTenantMorador = \ERP\Database\DatabaseDialect::columnExists($conexao, 'moradores', 'tenant_id');
        if ($isPostgres && (!$temTenantSessao || !$temTenantMorador
            || !\ERP\Database\DatabaseDialect::tableExists($conexao, 'tenants'))) {
            throw new RuntimeException('Schema de sessão Portal sem tenant válido.');
        }
        $tenantSelect = $temTenantSessao
            ? ', sp.tenant_id AS tenant_id'
            : ($temTenantMorador ? ', m.tenant_id AS tenant_id' : ', 0 AS tenant_id');
        $tenantJoin = $temTenantMorador && \ERP\Database\DatabaseDialect::tableExists($conexao, 'tenants')
            ? " INNER JOIN tenants t ON t.id=m.tenant_id AND t.status='ativo'"
            : '';
        $tenantMatch = $temTenantSessao && $temTenantMorador ? ' AND sp.tenant_id=m.tenant_id' : '';
        $stmtIdentidade = $conexao->prepare(
            "SELECT sp.morador_id{$tenantSelect}
             FROM sessoes_portal sp
             INNER JOIN moradores m ON m.id=sp.morador_id{$tenantJoin}
             WHERE sp.token=? AND sp.ativo=1 AND sp.data_expiracao>NOW() AND m.ativo=1{$tenantMatch}
             LIMIT 2"
        );
        if (!$stmtIdentidade) throw new RuntimeException('Falha ao localizar sessão Portal.');
        $stmtIdentidade->bind_param('s', $tokenParaRevogar);
        if (!$stmtIdentidade->execute()) {
            $stmtIdentidade->close();
            throw new RuntimeException('Falha ao localizar sessão Portal.');
        }
        $resultadoIdentidade = $stmtIdentidade->get_result();
        if (!$resultadoIdentidade || $resultadoIdentidade->num_rows !== 1) {
            if ($resultadoIdentidade) $resultadoIdentidade->free();
            $stmtIdentidade->close();
            try { fechar_conexao($conexao); } catch (Throwable) {}
            $conexao = null;
            logout_morador_responder(false, 'Sessão não localizada para encerramento.', 401);
        }
        $identidade = $resultadoIdentidade->fetch_assoc();
        $resultadoIdentidade->free();
        $stmtIdentidade->close();
        $moradorIdToken = (int)$identidade['morador_id'];
        $tenantIdToken = (int)$identidade['tenant_id'];
        if (($moradorId > 0 && $moradorId !== $moradorIdToken)
            || ($temTenantSessao && $tenantId > 0 && $tenantId !== $tenantIdToken)) {
            try { fechar_conexao($conexao); } catch (Throwable) {}
            $conexao = null;
            logout_morador_responder(false, 'Sessão não localizada para encerramento.', 401);
        }
        $moradorId = $moradorIdToken;
        $tenantId = $tenantIdToken;
        if ($moradorId <= 0 || ($temTenantSessao && $tenantId <= 0)) {
            throw new RuntimeException('Identidade Portal inválida para revogação.');
        }
        $stmtRevogar = $temTenantSessao
            ? $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE token=? AND morador_id=? AND tenant_id=? AND ativo=1')
            : $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE token=? AND morador_id=? AND ativo=1');
        if (!$stmtRevogar) throw new RuntimeException('Falha ao preparar revogação Portal.');
        if ($temTenantSessao) $stmtRevogar->bind_param('sii', $tokenParaRevogar, $moradorId, $tenantId);
        else $stmtRevogar->bind_param('si', $tokenParaRevogar, $moradorId);
        if (!$stmtRevogar->execute()) {
            $stmtRevogar->close();
            throw new RuntimeException('Falha ao revogar sessão Portal.');
        }
        $revogado = $stmtRevogar->affected_rows > 0;
        $stmtRevogar->close();
        if (!$revogado) {
            if ($conexao !== null) {
                try { fechar_conexao($conexao); } catch (Throwable) {}
                $conexao = null;
            }
            logout_morador_responder(false, 'Sessão não localizada para encerramento.', 401);
        }
    } catch (Throwable) {
        error_log('[logout_morador] Falha operacional ao revogar sessão Portal.');
        if ($conexao !== null) {
            try { fechar_conexao($conexao); } catch (Throwable) {}
        }
        logout_morador_responder(false, 'Não foi possível encerrar a sessão. Tente novamente.', 503);
    }
    if ($conexao !== null) {
        try { fechar_conexao($conexao); } catch (Throwable) { error_log('[logout_morador] Falha ao fechar conexão.'); }
    }
}

if ($sessaoPortalAtiva && isset($_SESSION['morador_nome'])) {
    try { registrar_log('LOGOUT_MORADOR', 'LOGOUT_PORTAL', null, false); }
    catch (Throwable) { error_log('[logout_morador] Falha na auditoria de logout.'); }
}
if ($sessaoPortalAtiva) logout_morador_limpar_sessao();

if ($metodo === 'GET') {
    header('Location: /frontend/login_morador.html');
    exit;
}
logout_morador_responder(true, 'Logout realizado com sucesso.', 200);
