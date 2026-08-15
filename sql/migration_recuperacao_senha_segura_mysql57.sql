-- ============================================================================
-- RECUPERAÇÃO DE SENHA SEGURA — ERP CONDOMÍNIOS MULTI-TENANT
-- Compatível com MySQL/MariaDB 5.7
--
-- Execute uma única vez no banco de produção antes do deploy do código.
-- Não altera senhas, usuários, moradores ou dados de negócio existentes.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `recuperacao_senha_tokens_v2` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT(11) NULL DEFAULT NULL COMMENT 'Tenant do morador; NULL para usuário global do ERP',
    `tipo_conta` ENUM('usuario','morador') NOT NULL,
    `conta_id` INT(11) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `token_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 do token; o token puro nunca é persistido',
    `ip_solicitacao` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(512) NULL DEFAULT NULL,
    `solicitado_em` DATETIME NOT NULL,
    `expira_em` DATETIME NOT NULL,
    `usado_em` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_recuperacao_token_hash` (`token_hash`),
    KEY `idx_recuperacao_conta` (`tipo_conta`, `conta_id`, `usado_em`),
    KEY `idx_recuperacao_ip_data` (`ip_solicitacao`, `solicitado_em`),
    KEY `idx_recuperacao_expira` (`expira_em`),
    KEY `idx_recuperacao_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limpeza opcional de tokens já consumidos ou expirados há mais de 30 dias.
-- Pode ser executada periodicamente pelo administrador.
-- DELETE FROM `recuperacao_senha_tokens_v2`
-- WHERE (`usado_em` IS NOT NULL OR `expira_em` < NOW())
--   AND `solicitado_em` < DATE_SUB(NOW(), INTERVAL 30 DAY);
