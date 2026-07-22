<?php
// =====================================================
// SISTEMA DE CONTROLE DE ACESSO - VERIFICAÇÃO DE SESSÃO (API)
// =====================================================

// Headers para API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://asl.erpcondominios.com.br');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Tratar requisições OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Iniciar sessão
session_start();

// Incluir arquivo de configuração
require_once 'config.php';

// Verificar se o usuário está logado
if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
    // Usuário não está logado
    retornar_json(false, 'Não autenticado');
}

// Verificar timeout da sessão (2 horas)
$timeout = 7200; // 2 horas em segundos
if (isset($_SESSION['login_timestamp']) && (time() - $_SESSION['login_timestamp'] > $timeout)) {
    // Sessão expirada
    session_destroy();
    retornar_json(false, 'Sessão expirada');
}

// Atualizar timestamp da última atividade
$_SESSION['login_timestamp'] = time();

// Retornar informações do usuário
retornar_json(true, 'Sessão válida', array(
    'usuario' => array(
        'id' => $_SESSION['usuario_id'],
        'nome' => $_SESSION['usuario_nome'],
        'email' => $_SESSION['usuario_email'],
        'funcao' => $_SESSION['usuario_funcao'],
        'departamento' => $_SESSION['usuario_departamento'],
        'permissao' => $_SESSION['usuario_permissao']
    )
));
?>