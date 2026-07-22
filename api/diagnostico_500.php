<?php
/**
 * DIAGNÓSTICO DE ERRO 500 — REMOVER APÓS USO
 * Acesse: https://asl.erpcondominios.com.br/api/diagnostico_500.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$resultado = [];

// 1. Versão PHP
$resultado['php_version'] = PHP_VERSION;

// 2. Extensões carregadas
$resultado['extensoes'] = [
    'mysqli'   => extension_loaded('mysqli'),
    'json'     => extension_loaded('json'),
    'session'  => extension_loaded('session'),
    'openssl'  => extension_loaded('openssl'),
];

// 3. Testar config.php
try {
    ob_start();
    require_once __DIR__ . '/config.php';
    $output = ob_get_clean();
    $resultado['config_php'] = 'OK';
    $resultado['config_output'] = $output ?: '(sem output)';
} catch (Throwable $e) {
    ob_end_clean();
    $resultado['config_php'] = 'ERRO: ' . $e->getMessage();
}

// 4. Testar conexão com banco
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        $resultado['banco'] = 'ERRO: ' . $conn->connect_error;
    } else {
        $resultado['banco'] = 'OK — conectado a ' . DB_NAME;
        $conn->close();
    }
} catch (Throwable $e) {
    $resultado['banco'] = 'EXCEÇÃO: ' . $e->getMessage();
}

// 5. Testar session_start
try {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $resultado['session'] = 'OK — ID: ' . session_id();
} catch (Throwable $e) {
    $resultado['session'] = 'ERRO: ' . $e->getMessage();
}

// 6. Verificar se mod_security está ativo
$resultado['mod_security_header'] = $_SERVER['HTTP_X_MOD_SECURITY'] ?? 'não detectado via header';
$resultado['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'desconhecido';

// 7. Testar include do verificar_sessao.php sem executar
$arquivo = __DIR__ . '/verificar_sessao.php';
$resultado['verificar_sessao_existe'] = file_exists($arquivo) ? 'SIM' : 'NÃO';
$resultado['verificar_sessao_legivel'] = is_readable($arquivo) ? 'SIM' : 'NÃO';

// 8. Testar include do api_verificar_tipo_login.php
$arquivo2 = __DIR__ . '/api_verificar_tipo_login.php';
$resultado['api_verificar_tipo_login_existe'] = file_exists($arquivo2) ? 'SIM' : 'NÃO';

// 9. Verificar logs de erro PHP
$log_path = ini_get('error_log');
$resultado['error_log_path'] = $log_path ?: 'não configurado';
if ($log_path && file_exists($log_path) && is_readable($log_path)) {
    $linhas = file($log_path);
    $ultimas = array_slice($linhas, -20);
    $resultado['ultimas_20_linhas_log'] = implode('', $ultimas);
} else {
    $resultado['ultimas_20_linhas_log'] = 'log não acessível';
}

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
