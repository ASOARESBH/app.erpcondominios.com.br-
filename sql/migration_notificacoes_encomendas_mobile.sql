-- ================================================================
-- ERP CONDOMÍNIOS — Notificações de Encomendas no Aplicativo
-- MIGRAÇÃO COMPATÍVEL COM MYSQL / MARIADB 5.7
-- ================================================================
--
-- Esta migração não cria chaves estrangeiras em notificacoes_morador.
-- O banco legado pode ter tipos, engines ou índices distintos nas tabelas
-- moradores/protocolos, o que gera o erro #1215. O isolamento e a integridade
-- são preservados por tenant_id, pelos índices compostos e pelas APIs PHP.
--
-- Pode ser executada no phpMyAdmin. Faça backup antes de iniciar.
-- ================================================================

-- ----------------------------------------------------------------
-- 1. Histórico individual das notificações do morador.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notificacoes_morador` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `morador_id` INT NOT NULL,
    `protocolo_id` INT NOT NULL,
    `tipo` ENUM('mercadoria_chegou','mercadoria_entregue') NOT NULL,
    `titulo` VARCHAR(150) NOT NULL,
    `mensagem` VARCHAR(500) NOT NULL,
    `descricao_mercadoria` VARCHAR(255) NOT NULL,
    `nome_recebedor` VARCHAR(150) DEFAULT NULL,
    `lida` TINYINT(1) NOT NULL DEFAULT 0,
    `lida_em` DATETIME DEFAULT NULL,
    `push_status` ENUM('pendente','enviado','falhou','sem_token','desativado','nao_configurado') NOT NULL DEFAULT 'pendente',
    `push_detalhe` VARCHAR(255) DEFAULT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notif_morador_protocolo_evento` (`tenant_id`,`morador_id`,`protocolo_id`,`tipo`),
    KEY `idx_notif_morador_listagem` (`tenant_id`,`morador_id`,`lida`,`criado_em`),
    KEY `idx_notif_protocolo` (`tenant_id`,`protocolo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Eventos de encomendas visíveis no aplicativo do morador';

-- ----------------------------------------------------------------
-- 2. Acrescenta tenant_id à configuração PWA somente se ainda não existir.
-- ----------------------------------------------------------------
SET @sql_add_tenant_config := IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pwa_configuracoes'
          AND COLUMN_NAME = 'tenant_id'
    ),
    'SELECT 1',
    'ALTER TABLE `pwa_configuracoes` ADD COLUMN `tenant_id` INT NOT NULL DEFAULT 1 AFTER `id`'
);
PREPARE stmt_add_tenant_config FROM @sql_add_tenant_config;
EXECUTE stmt_add_tenant_config;
DEALLOCATE PREPARE stmt_add_tenant_config;

-- ----------------------------------------------------------------
-- 3. Transforma a chave de configuração em única por tenant.
--    Executa cada alteração somente quando necessária.
-- ----------------------------------------------------------------
SET @sql_drop_legacy_unique := IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pwa_configuracoes'
          AND INDEX_NAME = 'chave'
          AND NON_UNIQUE = 0
    ),
    'ALTER TABLE `pwa_configuracoes` DROP INDEX `chave`',
    'SELECT 1'
);
PREPARE stmt_drop_legacy_unique FROM @sql_drop_legacy_unique;
EXECUTE stmt_drop_legacy_unique;
DEALLOCATE PREPARE stmt_drop_legacy_unique;

SET @sql_add_tenant_unique := IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pwa_configuracoes'
          AND INDEX_NAME = 'uq_pwa_config_tenant_chave'
    ),
    'SELECT 1',
    'ALTER TABLE `pwa_configuracoes` ADD UNIQUE KEY `uq_pwa_config_tenant_chave` (`tenant_id`, `chave`)'
);
PREPARE stmt_add_tenant_unique FROM @sql_add_tenant_unique;
EXECUTE stmt_add_tenant_unique;
DEALLOCATE PREPARE stmt_add_tenant_unique;

-- ----------------------------------------------------------------
-- 4. Acrescenta tenant_id e índice para os tokens FCM quando necessário.
-- ----------------------------------------------------------------
SET @sql_add_tenant_token := IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pwa_fcm_tokens'
          AND COLUMN_NAME = 'tenant_id'
    ),
    'SELECT 1',
    'ALTER TABLE `pwa_fcm_tokens` ADD COLUMN `tenant_id` INT NOT NULL DEFAULT 1 AFTER `id`'
);
PREPARE stmt_add_tenant_token FROM @sql_add_tenant_token;
EXECUTE stmt_add_tenant_token;
DEALLOCATE PREPARE stmt_add_tenant_token;

SET @sql_add_fcm_index := IF(
    EXISTS(
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'pwa_fcm_tokens'
          AND INDEX_NAME = 'idx_fcm_tenant_morador_ativo'
    ),
    'SELECT 1',
    'ALTER TABLE `pwa_fcm_tokens` ADD KEY `idx_fcm_tenant_morador_ativo` (`tenant_id`, `morador_id`, `ativo`)'
);
PREPARE stmt_add_fcm_index FROM @sql_add_fcm_index;
EXECUTE stmt_add_fcm_index;
DEALLOCATE PREPARE stmt_add_fcm_index;

-- ----------------------------------------------------------------
-- 5. Habilita alertas de encomenda para todos os tenants existentes.
-- ----------------------------------------------------------------
INSERT INTO `pwa_configuracoes` (`tenant_id`, `chave`, `valor`, `descricao`)
SELECT DISTINCT `tenant_id`, 'push_encomenda_ativo', '1',
       'Exibir push quando uma encomenda chegar ou for entregue'
FROM `moradores`
WHERE `tenant_id` IS NOT NULL
ON DUPLICATE KEY UPDATE
    `valor` = VALUES(`valor`),
    `descricao` = VALUES(`descricao`),
    `atualizado_em` = NOW();

-- ----------------------------------------------------------------
-- VERIFICAÇÃO PÓS-MIGRAÇÃO
-- ----------------------------------------------------------------
-- SHOW CREATE TABLE notificacoes_morador;
-- SELECT tenant_id, chave, valor
-- FROM pwa_configuracoes
-- WHERE chave = 'push_encomenda_ativo';
-- ================================================================
