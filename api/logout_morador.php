<?php
// =====================================================
// LOGOUT DO MORADOR
// =====================================================

session_start();
require_once 'config.php';

// Revogar o Bearer desta sessão antes de destruir a sessão PHP. O token nunca
// é aceito do query string e a revogação é restringida ao morador/tenant da sessão.
$moradorId = (int)($_SESSION['morador_id'] ?? 0);
$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$tokenPortal = (string)($_SESSION['morador_portal_token'] ?? '');
if ($moradorId > 0 && $tokenPortal !== '') {
    $conexao = conectar_banco();
    $stmtTenantSessao = $conexao->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sessoes_portal' AND COLUMN_NAME='tenant_id' LIMIT 1");
    $temTenantSessao = false;
    if ($stmtTenantSessao) {
        $stmtTenantSessao->execute();
        $temTenantSessao = (bool)$stmtTenantSessao->get_result()->fetch_row();
        $stmtTenantSessao->close();
    }
    if ($temTenantSessao && $tenantId > 0) {
        $stmtRevogar = $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE token=? AND morador_id=? AND tenant_id=? AND ativo=1');
        if ($stmtRevogar) {
            $stmtRevogar->bind_param('sii', $tokenPortal, $moradorId, $tenantId);
            $stmtRevogar->execute();
            $stmtRevogar->close();
        }
    } else {
        $stmtRevogar = $conexao->prepare('UPDATE sessoes_portal SET ativo=0 WHERE token=? AND morador_id=? AND ativo=1');
        if ($stmtRevogar) {
            $stmtRevogar->bind_param('si', $tokenPortal, $moradorId);
            $stmtRevogar->execute();
            $stmtRevogar->close();
        }
    }
    fechar_conexao($conexao);
}

// Registrar log antes de destruir a sessão
if (isset($_SESSION['morador_nome'])) {
    registrar_log('LOGOUT_MORADOR', 'Morador deslogado do Portal.', $_SESSION['morador_nome']);
}

// Destruir todas as variáveis de sessão
$_SESSION = array();

// Destruir a sessão
session_destroy();

// Redirecionar para a página de login do morador
header('Location: login_morador.html');
exit;

