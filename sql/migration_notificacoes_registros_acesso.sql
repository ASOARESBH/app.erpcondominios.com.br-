-- ====================================================================
-- ERP CONDOMÍNIOS — Notificações de Entrada e Saída (CORRIGIDA)
-- MariaDB 5.7 / MySQL compatível e idempotente
--
-- Compatível com bancos que possuem notificações de protocolos, mas ainda
-- NÃO possuem as colunas veiculo_id e registro_acesso_id.
-- Faça backup antes de executar no phpMyAdmin.
-- ====================================================================

-- Cria a tabela completa somente em instalações novas. Em instalações já
-- existentes, os ALTERs abaixo adicionam somente o que estiver faltando.
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
    `push_status` VARCHAR(30) NOT NULL DEFAULT 'pendente',
    `push_detalhe` VARCHAR(255) DEFAULT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notif_morador_listagem` (`tenant_id`,`morador_id`,`lida`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1. Garante a coluna usada pelo evento de veículo, se ainda não existir.
SET @sql_add_veiculo := IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notificacoes_morador'
             AND COLUMN_NAME = 'veiculo_id'),
    'SELECT 1',
    'ALTER TABLE notificacoes_morador ADD COLUMN veiculo_id INT NULL AFTER protocolo_id'
);
PREPARE stmt_add_veiculo FROM @sql_add_veiculo;
EXECUTE stmt_add_veiculo;
DEALLOCATE PREPARE stmt_add_veiculo;

-- 2. Adiciona o vínculo do novo evento de entrada/saída.
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

-- 3. O campo tipo precisa aceitar os novos eventos acesso_entrada e acesso_saida.
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

-- 4. Índices de consulta e não duplicidade por registro.
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

-- Verificação após a execução:
-- SHOW COLUMNS FROM notificacoes_morador;
-- SHOW INDEX FROM notificacoes_morador;
