<?php
// =====================================================
// LOGOUT DO MORADOR
// =====================================================

session_start();

// Registrar log antes de destruir a sessão
if (isset($_SESSION['morador_nome'])) {
    require_once 'config.php';
    registrar_log('LOGOUT_MORADOR', "Morador deslogado: {$_SESSION['morador_nome']}", $_SESSION['morador_nome']);
}

// Destruir todas as variáveis de sessão
$_SESSION = array();

// Destruir a sessão
session_destroy();

// Redirecionar para a página de login do morador
header('Location: login_morador.html');
exit;

