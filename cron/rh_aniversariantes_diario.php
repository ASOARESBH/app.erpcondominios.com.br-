<?php
/**
 * Cron diário: dispara rh.aniversario_colaborador para todos os tenants
 * ativos que têm aniversariante hoje. Execute uma vez por dia via cron/CLI.
 *
 * Agendamento sugerido (cPanel > Cron Jobs), uma vez ao dia pela manhã:
 *   php /caminho/para/app/cron/rh_aniversariantes_diario.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI\n");
}

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/helpers/cron_rh_aniversariantes.php';

$conexao = conectar_banco();
try {
    $resultado = cron_rh_processar_todos($conexao);
    echo json_encode(['sucesso' => true] + $resultado, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    error_log('[CRON][RH_ANIVERSARIANTES] ' . $e->getMessage());
    fwrite(STDERR, "Falha no cron de aniversariantes\n");
    exit(1);
} finally {
    fechar_conexao($conexao);
}
