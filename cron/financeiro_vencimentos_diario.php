<?php
/**
 * Cron diário: dispara financeiro.conta_vencendo / financeiro.conta_vencida
 * para todos os tenants ativos. Execute uma vez por dia via cron/CLI.
 *
 * Agendamento sugerido (cPanel > Cron Jobs), uma vez ao dia pela manhã:
 *   php /caminho/para/app/cron/financeiro_vencimentos_diario.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI\n");
}

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/helpers/cron_financeiro_vencimentos.php';

$conexao = conectar_banco();
try {
    $resultado = cron_financeiro_processar_todos($conexao);
    echo json_encode(['sucesso' => true] + $resultado, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    error_log('[CRON][FINANCEIRO_VENCIMENTOS] ' . $e->getMessage());
    fwrite(STDERR, "Falha no cron de vencimentos financeiros\n");
    exit(1);
} finally {
    fechar_conexao($conexao);
}
