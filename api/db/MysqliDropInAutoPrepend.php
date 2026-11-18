<?php
declare(strict_types=1);

$bufferLevel = ob_get_level();
ob_start();
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }
    throw new ErrorException('Falha durante a inicialização do runtime de banco.');
});
try {
    require_once __DIR__ . '/MysqliDropIn.php';
    \ERP\Database\MysqliDropInBootstrap::install();
    restore_error_handler();
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
} catch (Throwable) {
    restore_error_handler();
    while (ob_get_level() > $bufferLevel) {
        ob_end_clean();
    }
    error_log('[ERP_DB] Inicialização do runtime de banco indisponível.');
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Serviço temporariamente indisponível.',
        'dados' => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
