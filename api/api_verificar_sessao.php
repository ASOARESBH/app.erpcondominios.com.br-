<?php
// =====================================================
// API PARA VERIFICAR STATUS DA SESSÃO
// =====================================================

// Configurações de sessão ANTES de session_start
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');

// Iniciar sessão
session_start();

// Configurar header JSON
// Função para retornar JSON
if (!function_exists('retornar_json')) {
    function retornar_json($sucesso, $mensagem, $dados = null) {
        header('Content-Type: application/json; charset=utf-8');
        $resposta = array('sucesso' => $sucesso, 'mensagem' => $mensagem);
        if ($dados !== null) $resposta['dados'] = $dados;
        echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');

// Verificar se o usuário está logado
if (!isset($_SESSION['usuario_logado']) || $_SESSION['usuario_logado'] !== true) {
    http_response_code(401);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Sessão inválida ou expirada',
        'logado' => false
    ]);
    exit;
}

// Verificar timeout da sessão (2 horas)
$timeout = 7200; // 2 horas em segundos
if (isset($_SESSION['login_timestamp']) && (time() - $_SESSION['login_timestamp'] > $timeout)) {
    // Sessão expirada
    session_destroy();
    http_response_code(401);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Sessão expirada por inatividade',
        'logado' => false
    ]);
    exit;
}

// Atualizar timestamp da última atividade
$_SESSION['login_timestamp'] = time();

// Retornar informações da sessão
echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Sessão ativa',
    'logado' => true,
    'dados' => [
        'nome' => $_SESSION['usuario_nome'],
        'email' => $_SESSION['usuario_email'],
        'permissao' => $_SESSION['usuario_permissao'],
        'tempo_restante' => $timeout - (time() - $_SESSION['login_timestamp'])
    ]
]);
