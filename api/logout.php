<?php
// =====================================================
// SISTEMA DE CONTROLE DE ACESSO - LOGOUT (API)
// =====================================================

// Headers para API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://asl.erpcondominios.com.br');
header('Access-Control-Allow-Credentials: true');

// Iniciar sessão ANTES de incluir config.php
session_start();

// Incluir arquivo de configuração
require_once 'config.php';

// Verificar se há usuário logado para registrar logout
if (isset($_SESSION['usuario_nome'])) {
    registrar_log('logout', "Logout realizado: {$_SESSION['usuario_email']}", $_SESSION['usuario_nome']);
}

// Destruir todas as variáveis de sessão
$_SESSION = array();

// Destruir o cookie de sessão
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}

// Destruir a sessão
session_destroy();

// Retornar sucesso via JSON
retornar_json(true, 'Logout realizado com sucesso!');
?>