<?php
/**
 * ============================================================
 * HELPER DE LOG FINANCEIRO — log_financeiro_helper.php
 * ============================================================
 * Inclua este arquivo em qualquer API do módulo financeiro
 * para registrar logs de diagnóstico na tabela logs_financeiro.
 *
 * Uso:
 *   require_once 'log_financeiro_helper.php';
 *   log_fin('importacao', 'ERRO', 'importar', 'Mensagem', 'Detalhe opcional', $ref_id);
 *
 * Módulos disponíveis:
 *   importacao | contas_pagar | contas_receber | planos_contas | conciliacao | geral
 *
 * Níveis disponíveis:
 *   DEBUG | INFO | AVISO | ERRO | CRITICO
 *
 * @version 1.0.0
 */

if (!function_exists('log_fin')) {

    /**
     * Registra um log no módulo financeiro.
     *
     * @param string      $modulo    Módulo que gerou o log
     * @param string      $nivel     Nível: DEBUG|INFO|AVISO|ERRO|CRITICO
     * @param string|null $acao      Nome da ação/função
     * @param string      $mensagem  Mensagem principal
     * @param string|null $detalhe   Detalhe técnico, stack trace, JSON extra
     * @param int|null    $ref_id    ID do registro relacionado (lote, conta, etc.)
     * @param int|null    $duracao   Duração da operação em milissegundos
     */
    function log_fin(
        string  $modulo,
        string  $nivel,
        ?string $acao,
        string  $mensagem,
        ?string $detalhe  = null,
        ?int    $ref_id   = null,
        ?int    $duracao  = null
    ): void {
        global $conn, $usuario_nome;

        // Garantir que a tabela existe (criação lazy)
        static $tabela_ok = false;
        if (!$tabela_ok && $conn) {
            $conn->query("CREATE TABLE IF NOT EXISTS `logs_financeiro` (
              `id`             bigint(20)   NOT NULL AUTO_INCREMENT,
              `modulo`         varchar(50)  NOT NULL,
              `nivel`          enum('INFO','AVISO','ERRO','DEBUG','CRITICO') NOT NULL DEFAULT 'INFO',
              `acao`           varchar(100) DEFAULT NULL,
              `mensagem`       text         NOT NULL,
              `detalhe`        text         DEFAULT NULL,
              `usuario`        varchar(100) DEFAULT NULL,
              `ip`             varchar(45)  DEFAULT NULL,
              `user_agent`     varchar(255) DEFAULT NULL,
              `request_method` varchar(10)  DEFAULT NULL,
              `request_uri`    varchar(500) DEFAULT NULL,
              `post_data`      text         DEFAULT NULL,
              `referencia_id`  int(11)      DEFAULT NULL,
              `duracao_ms`     int(11)      DEFAULT NULL,
              `criado_em`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_modulo`  (`modulo`),
              KEY `idx_nivel`   (`nivel`),
              KEY `idx_criado`  (`criado_em`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $tabela_ok = true;
        }

        if (!$conn) return;

        // Contexto da requisição
        $ip      = $_SERVER['REMOTE_ADDR']      ?? null;
        $ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $method  = $_SERVER['REQUEST_METHOD']   ?? null;
        $uri     = substr($_SERVER['REQUEST_URI'] ?? '', 0, 500);
        $usuario = $usuario_nome ?? 'sistema';

        // POST data sanitizado (sem senhas/tokens/arquivos binários)
        $post_data = null;
        if (!empty($_POST)) {
            $safe = $_POST;
            foreach (['senha', 'password', 'token', 'secret', 'key', 'arquivo'] as $k) {
                if (isset($safe[$k])) $safe[$k] = '***';
            }
            $post_data = json_encode($safe, JSON_UNESCAPED_UNICODE);
            if (strlen($post_data) > 2000) $post_data = substr($post_data, 0, 2000) . '...[truncado]';
        }

        // Truncar detalhe se muito longo
        if ($detalhe && strlen($detalhe) > 5000) {
            $detalhe = substr($detalhe, 0, 5000) . '...[truncado]';
        }

        $nivel_upper = strtoupper($nivel);
        $modulo_safe = strtolower(trim($modulo));

        $stmt = $conn->prepare(
            "INSERT INTO logs_financeiro
             (modulo, nivel, acao, mensagem, detalhe, usuario, ip, user_agent,
              request_method, request_uri, post_data, referencia_id, duracao_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) return;

        $stmt->bind_param('sssssssssssii',
            $modulo_safe, $nivel_upper, $acao, $mensagem, $detalhe,
            $usuario, $ip, $ua, $method, $uri, $post_data,
            $ref_id, $duracao
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Registra um erro de exceção PHP com stack trace completo.
     */
    function log_fin_exception(string $modulo, string $acao, Throwable $e, ?int $ref_id = null): void {
        $detalhe = get_class($e) . ': ' . $e->getMessage()
                 . "\nArquivo: " . $e->getFile() . ':' . $e->getLine()
                 . "\nStack:\n" . $e->getTraceAsString();
        log_fin($modulo, 'CRITICO', $acao, 'Exceção PHP: ' . $e->getMessage(), $detalhe, $ref_id);
    }

    /**
     * Registra início de uma operação e retorna o timestamp para calcular duração.
     */
    function log_fin_inicio(string $modulo, string $acao, string $mensagem, ?int $ref_id = null): float {
        log_fin($modulo, 'DEBUG', $acao, '[INÍCIO] ' . $mensagem, null, $ref_id);
        return microtime(true);
    }

    /**
     * Registra fim de uma operação com duração calculada.
     */
    function log_fin_fim(string $modulo, string $acao, string $mensagem, float $inicio, ?int $ref_id = null): void {
        $duracao = (int)((microtime(true) - $inicio) * 1000);
        log_fin($modulo, 'DEBUG', $acao, '[FIM] ' . $mensagem, null, $ref_id, $duracao);
    }
}
