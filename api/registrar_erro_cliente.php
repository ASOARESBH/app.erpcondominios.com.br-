<?php
/**
 * =====================================================
 * API: registrar_erro_cliente.php
 * =====================================================
 * 
 * Endpoint para registrar erros do lado do cliente
 * no arquivo de log do servidor.
 * 
 * @author Sistema ERP Serra da Liberdade
 * @date 25/01/2026
 * @version 1.0
 */

// Incluir error logger
require_once 'error_logger.php';

// Verificar se é requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido'
    ]);
    exit;
}

// Obter dados JSON
$input = file_get_contents('php://input');
$dados = json_decode($input, true);

if (!$dados) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Dados inválidos'
    ]);
    exit;
}

// Extrair informações
$funcao = $dados['funcao'] ?? 'UNKNOWN';
$mensagem = $dados['mensagem'] ?? 'Erro desconhecido';
$contexto = $dados['contexto'] ?? '{}';
$url = $dados['url'] ?? 'UNKNOWN';
$timestamp = $dados['timestamp'] ?? date('Y-m-d H:i:s');

// Preparar contexto para log
$contextoArray = [
    'funcao' => $funcao,
    'url' => $url,
    'timestamp_cliente' => $timestamp,
    'contexto_adicional' => $contexto,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN'
];

// Registrar erro
$errorLogger->registrar(
    "Erro do Cliente: $mensagem (Função: $funcao)",
    'CLIENT_ERROR',
    $contextoArray
);

// Retornar resposta
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);
echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Erro registrado com sucesso'
]);

?>
