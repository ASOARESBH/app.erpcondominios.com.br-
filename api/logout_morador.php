<?php
// =====================================================
// LOGOUT DO MORADOR
// =====================================================

session_start();
require_once 'config.php';
require_once __DIR__ . '/db/DatabaseDialect.php';

// Revogar o Bearer desta sessão antes de destruir a sessão PHP. O token nunca
// é aceito do query string e a revogação é restringida ao morador/tenant da sessão.
$moradorId = (int)($_SESSION['morador_id'] ?? 0);
$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$tokenPortal = (string)($_SESSION['morador_portal_token'] ?? '');
if ($moradorId > 0 && $tokenPortal !== '') {
    $conexao = null;
    try {
        $conexao = conectar_banco();
        $isPostgres = \ERP\Database\DatabaseDialect::isPostgres($conexao);
        $temTenantSessao = \ERP\Database\DatabaseDialect::columnExists($conexao, 'sessoes_portal', 'tenant_id');
        if ($isPostgres && (!$temTenantSessao || $tenantId <= 0)) {
            throw new RuntimeException('Schema de sessão Portal sem tenant válido.');
        }
        if ($temTenantSessao) {
            $stmtRevogar = $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE token=? AND morador_id=? AND tenant_id=? AND ativo=1');
            if (!$stmtRevogar) throw new RuntimeException('Falha ao preparar revogação Portal.');
            $stmtRevogar->bind_param('sii', $tokenPortal, $moradorId, $tenantId);
        } else {
            // Compatibilidade MySQL apenas para schemas legados sem coluna tenant_id.
            $stmtRevogar = $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE token=? AND morador_id=? AND ativo=1');
            if (!$stmtRevogar) throw new RuntimeException('Falha ao preparar revogação Portal.');
            $stmtRevogar->bind_param('si', $tokenPortal, $moradorId);
        }
        if (!$stmtRevogar->execute()) throw new RuntimeException('Falha ao revogar sessão Portal.');
        $stmtRevogar->close();
    } catch (Throwable $e) {
        error_log('[logout_morador] Falha ao revogar sessão Portal.');
    } finally {
        if ($conexao !== null) {
            try { fechar_conexao($conexao); } catch (Throwable) { error_log('[logout_morador] Falha ao fechar conexão.'); }
        }
    }
}

// Registrar log antes de destruir a sessão
if (isset($_SESSION['morador_nome'])) {
    registrar_log('LOGOUT_MORADOR', 'LOGOUT_PORTAL', null, false);
}

// Destruir todas as variáveis de sessão
$_SESSION = array();

// Destruir a sessão
session_destroy();

// Redirecionar para a página de login do morador
header('Location: login_morador.html');
exit;

