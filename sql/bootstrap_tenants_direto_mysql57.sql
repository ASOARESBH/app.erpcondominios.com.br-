-- ============================================================================
-- BOOTSTRAP DIRETO DA TABELA TENANTS
-- ERP Condomínio | MySQL/MariaDB 5.7
--
-- Este script usa nomes totalmente qualificados. Pode ser executado mesmo se
-- o phpMyAdmin estiver aberto em INFORMATION_SCHEMA ou em outra tabela.
-- Não usa procedures, DELIMITER, ON DUPLICATE KEY UPDATE ou ALTER TABLE.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `inlaud99_erpcondor`.`tenants` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(80) NOT NULL,
  `razao_social` VARCHAR(255) NOT NULL,
  `nome_fantasia` VARCHAR(255) DEFAULT NULL,
  `cnpj` VARCHAR(20) NOT NULL,
  `plano` ENUM('basico','profissional','enterprise') NOT NULL DEFAULT 'profissional',
  `status` ENUM('ativo','inativo','suspenso') NOT NULL DEFAULT 'ativo',
  `modulos_habilitados` LONGTEXT DEFAULT NULL,
  `logo_url` VARCHAR(500) DEFAULT NULL,
  `email_principal` VARCHAR(255) DEFAULT NULL,
  `telefone` VARCHAR(30) DEFAULT NULL,
  `endereco` VARCHAR(500) DEFAULT NULL,
  `cidade` VARCHAR(100) DEFAULT NULL,
  `estado` VARCHAR(2) DEFAULT NULL,
  `data_criacao` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenants_slug` (`slug`),
  KEY `idx_tenants_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabela mestre Multi-Tenant';

-- Copia o primeiro cadastro empresarial legado apenas se o tenant 1 ainda não existir.
INSERT IGNORE INTO `inlaud99_erpcondor`.`tenants` (
    `id`, `slug`, `razao_social`, `nome_fantasia`, `cnpj`, `plano`, `status`,
    `logo_url`, `email_principal`, `telefone`, `cidade`, `estado`,
    `data_criacao`, `data_atualizacao`
)
SELECT
    1,
    'serra-da-liberdade',
    IF(CHAR_LENGTH(TRIM(e.`razao_social`)) > 0, TRIM(e.`razao_social`), 'ERP Condomínio'),
    IF(CHAR_LENGTH(TRIM(e.`nome_fantasia`)) > 0, TRIM(e.`nome_fantasia`), 'ERP Condomínio'),
    IF(CHAR_LENGTH(TRIM(e.`cnpj`)) > 0, TRIM(e.`cnpj`), '00000000000000'),
    'profissional',
    CASE WHEN BINARY e.`situacao` = BINARY 'inativo' THEN 'inativo' ELSE 'ativo' END,
    IF(CHAR_LENGTH(TRIM(e.`logo_url`)) > 0, TRIM(e.`logo_url`), NULL),
    IF(CHAR_LENGTH(TRIM(e.`email_principal`)) > 0, TRIM(e.`email_principal`), 'contato@erpcondominios.com.br'),
    IF(CHAR_LENGTH(TRIM(e.`telefone`)) > 0, TRIM(e.`telefone`), NULL),
    IF(CHAR_LENGTH(TRIM(e.`endereco_cidade`)) > 0, TRIM(e.`endereco_cidade`), NULL),
    IF(CHAR_LENGTH(TRIM(e.`endereco_estado`)) > 0, TRIM(e.`endereco_estado`), NULL),
    NOW(), NOW()
FROM `inlaud99_erpcondor`.`empresa` AS e
ORDER BY e.`id` ASC
LIMIT 1;

-- Fallback caso a tabela empresa esteja vazia.
INSERT IGNORE INTO `inlaud99_erpcondor`.`tenants` (
    `id`, `slug`, `razao_social`, `nome_fantasia`, `cnpj`, `plano`, `status`,
    `email_principal`, `data_criacao`, `data_atualizacao`
) VALUES (
    1, 'tenant-inicial', 'ERP Condomínio', 'ERP Condomínio', '00000000000000',
    'profissional', 'ativo', 'contato@erpcondominios.com.br', NOW(), NOW()
);

-- Validação: também usa nome qualificado, sem depender de USE.
SELECT
    t.`id`, t.`slug`, t.`status`, t.`nome_fantasia`, t.`razao_social`,
    t.`cnpj`, t.`logo_url`, t.`email_principal`
FROM `inlaud99_erpcondor`.`tenants` AS t
ORDER BY t.`id`;
