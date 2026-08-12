-- ================================================================
-- ERP CONDOMÍNIOS — PORTAL DO COLABORADOR MOBILE
-- Sessões Bearer isoladas por usuário e tenant
-- Compatível com MySQL/MariaDB 5.7
-- ================================================================
--
-- A senha nunca é armazenada nesta tabela. O token retornado ao aplicativo é
-- gravado somente como SHA-256, impedindo reutilização caso o banco seja lido.
-- Não há chaves estrangeiras para compatibilidade com instalações legadas.
-- ================================================================

CREATE TABLE IF NOT EXISTS `sessoes_colaborador_mobile` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `usuario_id` INT NOT NULL,
    `tenant_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `dispositivo` VARCHAR(500) DEFAULT NULL,
    `data_criacao` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `data_expiracao` DATETIME NOT NULL,
    `ultimo_uso` DATETIME DEFAULT NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sessao_colaborador_token` (`token_hash`),
    KEY `idx_sessao_colaborador_usuario_tenant` (`usuario_id`, `tenant_id`, `ativo`),
    KEY `idx_sessao_colaborador_expiracao` (`data_expiracao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Sessões Bearer do Portal do Colaborador Mobile';

-- Revogação de sessões vencidas. Pode ser executada periodicamente pela rotina
-- de limpeza já existente no servidor.
UPDATE `sessoes_colaborador_mobile`
SET `ativo` = 0
WHERE `ativo` = 1 AND `data_expiracao` < NOW();

-- VERIFICAÇÃO:
-- SHOW CREATE TABLE sessoes_colaborador_mobile;
