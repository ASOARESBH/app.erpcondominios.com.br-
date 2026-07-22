<?php
// =====================================================
// VERIFICAÇÃO DE SESSÃO DO MORADOR
// =====================================================

// Iniciar sessão
session_start();

// Verificar se o morador está logado
if (!isset($_SESSION['morador_logado']) || $_SESSION['morador_logado'] !== true) {
    // Morador não está logado - redirecionar para login
    header('Location: login_morador.html');
    exit;
}

// Verificar timeout da sessão (opcional - 2 horas)
$timeout = 7200; // 2 horas em segundos
if (isset($_SESSION['morador_login_timestamp']) && (time() - $_SESSION['morador_login_timestamp'] > $timeout)) {
    // Sessão expirada
    session_destroy();
    header('Location: login_morador.html?erro=4');
    exit;
}

// Atualizar timestamp da última atividade
$_SESSION['morador_login_timestamp'] = time();

