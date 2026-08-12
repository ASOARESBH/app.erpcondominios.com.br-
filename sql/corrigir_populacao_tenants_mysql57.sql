-- ============================================================================
-- CORREÇÃO DE POPULAÇÃO DO TENANT INICIAL
-- ERP Condomínio | MySQL/MariaDB 5.7
--
-- Execute APÓS o pre_requisito_multitenant_mysql57.sql.
-- Corrige o erro #1052: coluna ambígua em ON DUPLICATE KEY UPDATE.
-- Este arquivo não usa ON DUPLICATE KEY UPDATE.
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Garante um registro-base para o tenant inicial caso a importação anterior
-- tenha criado a estrutura e parado antes da migração dos dados.
INSERT IGNORE INTO `tenants` (
    `id`, `slug`, `razao_social`, `nome_fantasia`, `cnpj`, `plano`, `status`,
    `email_principal`, `data_criacao`, `data_atualizacao`
) VALUES (
    1, 'tenant-inicial', 'ERP Condomínio', 'ERP Condomínio', '00000000000000',
    'profissional', 'ativo', 'contato@erpcondominios.com.br', NOW(), NOW()
);

-- 2. Migra os dados do primeiro cadastro legado de empresa.
-- Todos os campos são qualificados por alias para evitar ambiguidade.
-- Dados já personalizados em tenants são preservados quando preenchidos.
UPDATE `tenants` AS t
INNER JOIN (
    SELECT
        e.`id`,
        e.`razao_social`,
        e.`nome_fantasia`,
        e.`cnpj`,
        e.`logo_url`,
        e.`email_principal`,
        e.`telefone`,
        e.`endereco_rua`,
        e.`endereco_numero`,
        e.`endereco_bairro`,
        e.`endereco_cidade`,
        e.`endereco_estado`,
        e.`situacao`
    FROM `empresa` AS e
    ORDER BY e.`id` ASC
    LIMIT 1
) AS e ON 1 = 1
SET
    t.`slug` = CASE
        WHEN BINARY t.`slug` = BINARY 'tenant-inicial' THEN 'serra-da-liberdade'
        ELSE t.`slug`
    END,
    t.`razao_social` = CASE
        WHEN CHAR_LENGTH(TRIM(t.`razao_social`)) = 0 OR BINARY t.`razao_social` = BINARY 'ERP Condomínio'
            THEN IF(CHAR_LENGTH(TRIM(e.`razao_social`)) > 0, TRIM(e.`razao_social`), 'ERP Condomínio')
        ELSE t.`razao_social`
    END,
    t.`nome_fantasia` = CASE
        WHEN CHAR_LENGTH(TRIM(IFNULL(t.`nome_fantasia`, ''))) = 0 OR BINARY IFNULL(t.`nome_fantasia`, '') = BINARY 'ERP Condomínio'
            THEN IF(CHAR_LENGTH(TRIM(e.`nome_fantasia`)) > 0, TRIM(e.`nome_fantasia`), t.`razao_social`)
        ELSE t.`nome_fantasia`
    END,
    t.`cnpj` = CASE
        WHEN CHAR_LENGTH(TRIM(t.`cnpj`)) = 0 OR BINARY t.`cnpj` = BINARY '00000000000000'
            THEN IF(CHAR_LENGTH(TRIM(e.`cnpj`)) > 0, TRIM(e.`cnpj`), t.`cnpj`)
        ELSE t.`cnpj`
    END,
    t.`status` = CASE WHEN BINARY e.`situacao` = BINARY 'inativo' THEN 'inativo' ELSE t.`status` END,
    t.`logo_url` = CASE
        WHEN CHAR_LENGTH(TRIM(IFNULL(t.`logo_url`, ''))) = 0 AND CHAR_LENGTH(TRIM(IFNULL(e.`logo_url`, ''))) > 0
            THEN TRIM(e.`logo_url`)
        ELSE t.`logo_url`
    END,
    t.`email_principal` = CASE
        WHEN CHAR_LENGTH(TRIM(IFNULL(t.`email_principal`, ''))) = 0 AND CHAR_LENGTH(TRIM(IFNULL(e.`email_principal`, ''))) > 0
            THEN TRIM(e.`email_principal`)
        ELSE t.`email_principal`
    END,
    t.`telefone` = CASE
        WHEN CHAR_LENGTH(TRIM(IFNULL(t.`telefone`, ''))) = 0 AND CHAR_LENGTH(TRIM(IFNULL(e.`telefone`, ''))) > 0
            THEN TRIM(e.`telefone`)
        ELSE t.`telefone`
    END,
    t.`endereco` = CASE
        WHEN CHAR_LENGTH(TRIM(IFNULL(t.`endereco`, ''))) = 0
            THEN CONCAT_WS(', ', IF(CHAR_LENGTH(TRIM(e.`endereco_rua`)) > 0, TRIM(e.`endereco_rua`), NULL), IF(CHAR_LENGTH(TRIM(e.`endereco_numero`)) > 0, TRIM(e.`endereco_numero`), NULL), IF(CHAR_LENGTH(TRIM(e.`endereco_bairro`)) > 0, TRIM(e.`endereco_bairro`), NULL))
        ELSE t.`endereco`
    END,
    t.`cidade` = CASE
        WHEN CHAR_LENGTH(TRIM(IFNULL(t.`cidade`, ''))) = 0 AND CHAR_LENGTH(TRIM(IFNULL(e.`endereco_cidade`, ''))) > 0
            THEN TRIM(e.`endereco_cidade`)
        ELSE t.`cidade`
    END,
    t.`estado` = CASE
        WHEN CHAR_LENGTH(TRIM(IFNULL(t.`estado`, ''))) = 0 AND CHAR_LENGTH(TRIM(IFNULL(e.`endereco_estado`, ''))) > 0
            THEN TRIM(e.`endereco_estado`)
        ELSE t.`estado`
    END
WHERE t.`id` = 1;

-- 3. Associar a empresa e os usuários existentes ao tenant inicial.
UPDATE `empresa` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL OR `tenant_id` = 0;
UPDATE `usuarios` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL OR `tenant_id` = 0;

-- 4. Criar a tabela de vínculos caso a importação anterior tenha parado antes dela.
CREATE TABLE IF NOT EXISTS `usuario_tenant` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` INT(11) NOT NULL,
  `tenant_id` INT(11) NOT NULL,
  `permissao` ENUM('admin','gerente','operador','visualizador') NOT NULL DEFAULT 'operador',
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usuario_tenant` (`usuario_id`, `tenant_id`),
  KEY `idx_usuario_tenant_tenant` (`tenant_id`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Vínculo entre usuários e tenants';

-- 5. Vincular todos os usuários existentes ao tenant correspondente.
INSERT IGNORE INTO `usuario_tenant` (`usuario_id`, `tenant_id`, `permissao`, `ativo`)
SELECT
    u.`id`,
    CASE WHEN u.`tenant_id` IS NULL OR u.`tenant_id` = 0 THEN 1 ELSE u.`tenant_id` END,
    CASE
        WHEN BINARY u.`permissao` = BINARY 'admin' OR BINARY u.`permissao` = BINARY 'super_admin' THEN 'admin'
        WHEN BINARY u.`permissao` = BINARY 'gerente' THEN 'gerente'
        WHEN BINARY u.`permissao` = BINARY 'visualizador' THEN 'visualizador'
        ELSE 'operador'
    END,
    CASE WHEN u.`ativo` = 1 THEN 1 ELSE 0 END
FROM `usuarios` AS u;

-- 6. Resultado de validação.
SELECT
    (SELECT COUNT(*) FROM `tenants`) AS total_tenants,
    (SELECT COUNT(*) FROM `empresa` WHERE `tenant_id` = 1) AS empresas_tenant_1,
    (SELECT COUNT(*) FROM `usuarios` WHERE `tenant_id` = 1) AS usuarios_tenant_1,
    (SELECT COUNT(*) FROM `usuario_tenant` WHERE `tenant_id` = 1) AS vinculos_tenant_1;

SELECT `id`, `slug`, `status`, `nome_fantasia`, `razao_social`, `cnpj`, `logo_url`
FROM `tenants`
ORDER BY `id`;
