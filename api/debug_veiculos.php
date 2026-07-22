<?php
/**
 * ARQUIVO DE DEBUG PARA API DE VEÍCULOS
 * Este arquivo registra todas as chamadas e erros para facilitar debug
 */

// Criar diretório de logs se não existir
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

$log_file = $log_dir . '/api_veiculos_debug.log';

function registrar_debug($tipo, $mensagem, $dados = array()) {
    global $log_file;
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'desconhecido';
    $uri = $_SERVER['REQUEST_URI'] ?? 'desconhecido';
    
    $log_entry = "[$timestamp] [$tipo] [$metodo] $uri\n";
    $log_entry .= "  IP: $ip\n";
    $log_entry .= "  Mensagem: $mensagem\n";
    
    if (!empty($dados)) {
        $log_entry .= "  Dados: " . json_encode($dados, JSON_UNESCAPED_UNICODE) . "\n";
    }
    
    $log_entry .= "  GET: " . json_encode($_GET, JSON_UNESCAPED_UNICODE) . "\n";
    $log_entry .= "  POST: " . json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n";
    $log_entry .= "---\n";
    
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// Registrar chamada
registrar_debug('CHAMADA_API', 'Requisição recebida', array(
    'get_params' => $_GET,
    'metodo' => $_SERVER['REQUEST_METHOD']
));
