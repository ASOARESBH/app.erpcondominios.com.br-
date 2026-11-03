<?php
/**
 * Rotina do módulo Alertas da Unidade: expira regras vencidas e enfileira
 * lembretes 24 horas antes do fim da vigência. Agendar a cada 10 minutos.
 * Execute somente via CLI após aplicar a migration Alertas.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI\n");
}

set_time_limit(120);
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/helpers/alertas_unidade_helper.php';

$conexao = conectar_banco();
$codigoSaida = 0;
try {
    if (!alertas_unidade_migration_instalada($conexao)) {
        fwrite(STDERR, "Migration Alertas da Unidade pendente\n");
        $codigoSaida = 2;
    } else {
        $resultado = alertas_unidade_processar_rotina_expiracao($conexao);
        echo json_encode(['sucesso' => true] + $resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        if (!empty($resultado['falhas'])) $codigoSaida = 1;
    }
} catch (Throwable $erro) {
    error_log('[CRON][ALERTAS_UNIDADE] Falha na rotina de expiração.');
    fwrite(STDERR, "Falha na rotina Alertas da Unidade\n");
    $codigoSaida = 1;
} finally {
    fechar_conexao($conexao);
}

exit($codigoSaida);
