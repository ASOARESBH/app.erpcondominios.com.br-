<?php
/**
 * Retenção do ERP CONDOMÍNIOS MONITORING.
 * Execute somente via cron/CLI após backup e validação do ambiente.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI\n");
}

require_once __DIR__ . '/../api/config.php';

$conexao = conectar_banco();
$conexao->begin_transaction();
try {
    $sql = "DELETE r
               FROM monitoramento_evento_revisoes r
               INNER JOIN monitoramento_eventos e ON e.id = r.evento_id AND e.tenant_id = r.tenant_id
               INNER JOIN monitoramento_configuracoes c ON c.tenant_id = e.tenant_id
              WHERE e.status_evento IN ('confirmado', 'arquivado')
                AND TIMESTAMPDIFF(DAY, e.capturado_em, UTC_TIMESTAMP()) >= c.retencao_dias";
    $conexao->query($sql);

    $sql = "DELETE x
               FROM monitoramento_evento_acesso x
               INNER JOIN monitoramento_eventos e ON e.id = x.evento_id AND e.tenant_id = x.tenant_id
               INNER JOIN monitoramento_configuracoes c ON c.tenant_id = e.tenant_id
              WHERE e.status_evento IN ('confirmado', 'arquivado')
                AND TIMESTAMPDIFF(DAY, e.capturado_em, UTC_TIMESTAMP()) >= c.retencao_dias";
    $conexao->query($sql);

    $sql = "DELETE e
               FROM monitoramento_eventos e
               INNER JOIN monitoramento_configuracoes c ON c.tenant_id = e.tenant_id
              WHERE e.status_evento IN ('confirmado', 'arquivado')
                AND TIMESTAMPDIFF(DAY, e.capturado_em, UTC_TIMESTAMP()) >= c.retencao_dias";
    $conexao->query($sql);
    $removed = $conexao->affected_rows;
    $conexao->commit();
    echo json_encode(['sucesso' => true, 'eventos_removidos' => (int)$removed], JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    $conexao->rollback();
    error_log('[MONITORING][RETENCAO] ' . $e->getMessage());
    fwrite(STDERR, "Falha na retenção\n");
    exit(1);
} finally {
    fechar_conexao($conexao);
}
