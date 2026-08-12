-- ====================================================================
-- ERP CONDOMÍNIOS — Notificações de Veículos / Controle de Acesso
-- MariaDB 5.7 / MySQL compatível e idempotente
-- Faça backup antes de executar no phpMyAdmin.
-- ====================================================================
-- Não há chaves estrangeiras: instalações legadas podem ter engines e tipos
-- distintos. A integridade é aplicada pelas APIs e pelos índices por tenant.

CREATE TABLE IF NOT EXISTS `notificacoes_morador` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `morador_id` INT NOT NULL,
    `protocolo_id` INT NULL,
    `veiculo_id` INT NULL,
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
    KEY `idx_notif_morador_listagem` (`tenant_id`,`morador_id`,`lida`,`criado_em`),
    KEY `idx_notif_protocolo` (`tenant_id`,`protocolo_id`),
    KEY `idx_notif_veiculo` (`tenant_id`,`veiculo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Eventos persistentes do Portal do Morador';

-- --------------------------------------------------------------------
-- Atualização da tabela já criada pela migração de encomendas.
-- --------------------------------------------------------------------
SET @sql_add_veiculo := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificacoes_morador' AND COLUMN_NAME = 'veiculo_id'),
    'SELECT 1',
    'ALTER TABLE notificacoes_morador ADD COLUMN veiculo_id INT NULL AFTER protocolo_id'
);
PREPARE stmt_add_veiculo FROM @sql_add_veiculo;
EXECUTE stmt_add_veiculo;
DEALLOCATE PREPARE stmt_add_veiculo;

-- Permite eventos que não pertencem a protocolos, como veículo cadastrado.
ALTER TABLE `notificacoes_morador` MODIFY COLUMN `protocolo_id` INT NULL;

-- O ENUM antigo aceita somente eventos de encomenda; VARCHAR mantém os dois
-- eventos antigos e aceita `veiculo_cadastrado` sem apagar histórico.
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

SET @sql_idx_veiculo := IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificacoes_morador'
             AND INDEX_NAME = 'idx_notif_veiculo'),
    'SELECT 1',
    'ALTER TABLE notificacoes_morador ADD KEY idx_notif_veiculo (tenant_id, veiculo_id)'
);
PREPARE stmt_idx_veiculo FROM @sql_idx_veiculo;
EXECUTE stmt_idx_veiculo;
DEALLOCATE PREPARE stmt_idx_veiculo;

SET @sql_uq_veiculo := IF(
    EXISTS(SELECT 1 FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificacoes_morador'
             AND INDEX_NAME = 'uq_notif_morador_veiculo_evento'),
    'SELECT 1',
    'ALTER TABLE notificacoes_morador ADD UNIQUE KEY uq_notif_morador_veiculo_evento (tenant_id, morador_id, veiculo_id, tipo)'
);
PREPARE stmt_uq_veiculo FROM @sql_uq_veiculo;
EXECUTE stmt_uq_veiculo;
DEALLOCATE PREPARE stmt_uq_veiculo;

-- Habilita apenas a categoria Controle de Acesso. Não altera a preferência
-- existente de encomendas.
INSERT INTO `pwa_configuracoes` (`tenant_id`, `chave`, `valor`, `descricao`)
SELECT DISTINCT `tenant_id`, 'push_controle_acesso_ativo', '1',
       'Exibir push quando um veículo for cadastrado para a unidade'
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
