-- ====================================================================
-- ERP CONDOMÍNIOS — Notificações de Entrada e Saída
-- MariaDB 5.7 / MySQL compatível e idempotente
-- Faça backup antes de executar no phpMyAdmin.
-- ====================================================================

CREATE TABLE IF NOT EXISTS `notificacoes_morador` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `morador_id` INT NOT NULL,
    `protocolo_id` INT NULL,
    `veiculo_id` INT NULL,
    `registro_acesso_id` INT NULL,
    `tipo` VARCHAR(60) NOT NULL,
    `titulo` VARCHAR(150) NOT NULL,
    `mensagem` VARCHAR(500) NOT NULL,
    `descricao_mercadoria` VARCHAR(255) NOT NULL DEFAULT '',
    `nome_recebedor` VARCHAR(150) DEFAULT NULL,
    `lida` TINYINT(1) NOT NULL DEFAULT 0,
    `lida_em` DATETIME DEFAULT NULL,
    `push_status` ENUM('pendente','enviado','falhou','sem_token','desativado','nao_configurado') NOT NULL DEFAULT 'pendente',
    `push_detalhe` VARCHAR(255) DEFAULT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notif_morador_protocolo_evento` (`tenant_id`,`morador_id`,`protocolo_id`,`tipo`),
    UNIQUE KEY `uq_notif_morador_veiculo_evento` (`tenant_id`,`morador_id`,`veiculo_id`,`tipo`),
    UNIQUE KEY `uq_notif_morador_registro_evento` (`tenant_id`,`morador_id`,`registro_acesso_id`,`tipo`),
    KEY `idx_notif_morador_listagem` (`tenant_id`,`morador_id`,`lida`,`criado_em`),
    KEY `idx_notif_registro_acesso` (`tenant_id`,`registro_acesso_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Eventos persistentes do Portal do Morador';

-- Amplia instalações que já possuem notificações de encomendas e veículos.
SET @sql_add_registro := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificacoes_morador'
             AND COLUMN_NAME = 'registro_acesso_id'),
    'SELECT 1',
    'ALTER TABLE notificacoes_morador ADD COLUMN registro_acesso_id INT NULL AFTER veiculo_id'
);
PREPARE stmt_add_registro FROM @sql_add_registro;
EXECUTE stmt_add_registro;
DEALLOCATE PREPARE stmt_add_registro;

ALTER TABLE `notificacoes_morador` MODIFY COLUMN `protocolo_id` INT NULL;

SET @sql_tipo_generico := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificacoes_morador'
             AND COLUMN_NAME = 'tipo' AND DATA_TYPE <> 'varchar'),
    'ALTER TABLE notificacoes_morador MODIFY COLUMN tipo VARCHAR(60) NOT NULL',
    'SELECT 1'
);
PREPARE stmt_tipo_generico FROM @sql_tipo_generico;
EXECUTE stmt_tipo_generico;
DEALLOCATE PREPARE stmt_tipo_generico;

SET @sql_idx_registro := IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificacoes_morador'
             AND INDEX_NAME = 'idx_notif_registro_acesso'),
    'SELECT 1',
    'ALTER TABLE notificacoes_morador ADD KEY idx_notif_registro_acesso (tenant_id, registro_acesso_id)'
);
PREPARE stmt_idx_registro FROM @sql_idx_registro;
EXECUTE stmt_idx_registro;
DEALLOCATE PREPARE stmt_idx_registro;

SET @sql_uq_registro := IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificacoes_morador'
             AND INDEX_NAME = 'uq_notif_morador_registro_evento'),
    'SELECT 1',
    'ALTER TABLE notificacoes_morador ADD UNIQUE KEY uq_notif_morador_registro_evento (tenant_id, morador_id, registro_acesso_id, tipo)'
);
PREPARE stmt_uq_registro FROM @sql_uq_registro;
EXECUTE stmt_uq_registro;
DEALLOCATE PREPARE stmt_uq_registro;

INSERT INTO `pwa_configuracoes` (`tenant_id`, `chave`, `valor`, `descricao`)
SELECT DISTINCT `tenant_id`, 'push_controle_acesso_ativo', '1',
       'Exibir push para entradas e saídas vinculadas à unidade'
FROM `moradores`
WHERE `tenant_id` IS NOT NULL
ON DUPLICATE KEY UPDATE
    `valor` = VALUES(`valor`),
    `descricao` = VALUES(`descricao`),
    `atualizado_em` = NOW();

-- Verificação pós-migração:
-- SHOW COLUMNS FROM notificacoes_morador;
-- SELECT tenant_id, chave, valor FROM pwa_configuracoes
-- WHERE chave = 'push_controle_acesso_ativo';
