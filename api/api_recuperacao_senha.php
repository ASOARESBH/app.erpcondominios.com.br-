<?php
/**
 * Compatibilidade da recuperação de senha.
 *
 * O fluxo legado foi substituído por api_recuperar_senha.php para eliminar
 * consultas interpoladas, enumeração de contas e redefinição por MD5.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Método não permitido. Solicite um novo link pela tela de login.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/api_recuperar_senha.php';
