-- ============================================================================
-- PRÉ-REQUISITO MULTI-TENANT — ERP Condomínio
-- Compatível com MySQL 5.7 / MariaDB 5.x
--
-- Objetivo: criar as tabelas centrais que estão ausentes no banco de produção
-- e vincular os dados legados ao tenant inicial sem duplicar registros.
--
-- IMPORTANTE: gere um backup completo antes de executar no phpMyAdmin.
-- Banco de destino: inlaud99_erpcondor.
-- ============================================================================

USE `inlaud99_erpcondor`;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1. Procedimento compatível para adicionar coluna somente se ela não existir.
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS mt_adicionar_coluna;
DELIMITER $$
CREATE PROCEDURE mt_adicionar_coluna(
    IN p_tabela VARCHAR(64),
    IN p_coluna VARCHAR(64),
    IN p_definicao TEXT
)
BEGIN
    DECLARE v_existe INT DEFAULT 0;

    SELECT COUNT(*) INTO v_existe
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE BINARY TABLE_SCHEMA = BINARY DATABASE()
      AND BINARY TABLE_NAME = BINARY p_tabela
      AND BINARY COLUMN_NAME = BINARY p_coluna;

    IF v_existe = 0 THEN
        SET @sql_mt = CONCAT('ALTER TABLE `', p_tabela, '` ADD COLUMN `', p_coluna, '` ', p_definicao);
        PREPARE mt_stmt FROM @sql_mt;
        EXECUTE mt_stmt;
        DEALLOCATE PREPARE mt_stmt;
    END IF;
END$$
DELIMITER ;

-- A aplicação Multi-Tenant precisa destes dois contextos mínimos no banco legado.
CALL mt_adicionar_coluna('empresa', 'tenant_id', 'INT(11) NOT NULL DEFAULT 1 AFTER `id`');
CALL mt_adicionar_coluna('usuarios', 'tenant_id', 'INT(11) NOT NULL DEFAULT 1 AFTER `id`');

-- ---------------------------------------------------------------------------
-- 2. Cadastro mestre de condomínios/empresas.
-- modulos_habilitados foi definido como LONGTEXT para máxima compatibilidade.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenants` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Cadastro mestre Multi-Tenant';

-- ---------------------------------------------------------------------------
-- 3. Migrar o cadastro de empresa legado para o tenant inicial (id = 1).
-- Não sobrescreve informações já ajustadas em tenants.
-- ---------------------------------------------------------------------------
INSERT INTO `tenants` (
    `id`, `slug`, `razao_social`, `nome_fantasia`, `cnpj`, `plano`, `status`,
    `logo_url`, `email_principal`, `telefone`, `endereco`, `cidade`, `estado`
)
SELECT
    1,
    'serra-da-liberdade',
    IF(CHAR_LENGTH(TRIM(e.razao_social)) > 0, TRIM(e.razao_social), 'ERP Condomínio'),
    IF(CHAR_LENGTH(TRIM(e.nome_fantasia)) > 0, TRIM(e.nome_fantasia), NULL),
    IF(CHAR_LENGTH(TRIM(e.cnpj)) > 0, TRIM(e.cnpj), '00000000000000'),
    'profissional',
    CASE WHEN BINARY e.situacao = BINARY 'inativo' THEN 'inativo' ELSE 'ativo' END,
    IF(CHAR_LENGTH(TRIM(e.logo_url)) > 0, TRIM(e.logo_url), NULL),
    IF(CHAR_LENGTH(TRIM(e.email_principal)) > 0, TRIM(e.email_principal), NULL),
    IF(CHAR_LENGTH(TRIM(e.telefone)) > 0, TRIM(e.telefone), NULL),
    CONCAT_WS(', ', IF(CHAR_LENGTH(TRIM(e.endereco_rua)) > 0, TRIM(e.endereco_rua), NULL), IF(CHAR_LENGTH(TRIM(e.endereco_numero)) > 0, TRIM(e.endereco_numero), NULL), IF(CHAR_LENGTH(TRIM(e.endereco_bairro)) > 0, TRIM(e.endereco_bairro), NULL)),
    IF(CHAR_LENGTH(TRIM(e.endereco_cidade)) > 0, TRIM(e.endereco_cidade), NULL),
    IF(CHAR_LENGTH(TRIM(e.endereco_estado)) > 0, TRIM(e.endereco_estado), NULL)
FROM `empresa` e
ORDER BY e.id ASC
LIMIT 1
ON DUPLICATE KEY UPDATE
    `razao_social` = IF(CHAR_LENGTH(TRIM(`razao_social`)) > 0, `razao_social`, VALUES(`razao_social`)),
    `nome_fantasia` = IF(CHAR_LENGTH(TRIM(`nome_fantasia`)) > 0, `nome_fantasia`, VALUES(`nome_fantasia`)),
    `logo_url` = IF(CHAR_LENGTH(TRIM(`logo_url`)) > 0, `logo_url`, VALUES(`logo_url`)),
    `email_principal` = IF(CHAR_LENGTH(TRIM(`email_principal`)) > 0, `email_principal`, VALUES(`email_principal`)),
    `telefone` = IF(CHAR_LENGTH(TRIM(`telefone`)) > 0, `telefone`, VALUES(`telefone`)),
    `cidade` = IF(CHAR_LENGTH(TRIM(`cidade`)) > 0, `cidade`, VALUES(`cidade`)),
    `estado` = IF(CHAR_LENGTH(TRIM(`estado`)) > 0, `estado`, VALUES(`estado`));

-- Proteção para instalações sem registro em empresa.
INSERT IGNORE INTO `tenants` (
    `id`, `slug`, `razao_social`, `nome_fantasia`, `cnpj`, `plano`, `status`,
    `email_principal`, `data_criacao`, `data_atualizacao`
) VALUES (
    1, 'tenant-inicial', 'ERP Condomínio', 'ERP Condomínio', '00000000000000',
    'profissional', 'ativo', 'contato@erpcondominios.com.br', NOW(), NOW()
);

-- Associar os registros legados ao primeiro tenant.
UPDATE `empresa` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL OR `tenant_id` = 0;
UPDATE `usuarios` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL OR `tenant_id` = 0;

-- ---------------------------------------------------------------------------
-- 4. Vínculo usuário × tenant, necessário para login e Super Admin.
-- ---------------------------------------------------------------------------
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

INSERT IGNORE INTO `usuario_tenant` (`usuario_id`, `tenant_id`, `permissao`, `ativo`)
SELECT
    u.id,
    COALESCE(NULLIF(u.tenant_id, 0), 1),
    CASE
        WHEN u.permissao IN ('admin', 'super_admin') THEN 'admin'
        WHEN u.permissao = 'gerente' THEN 'gerente'
        WHEN u.permissao = 'visualizador' THEN 'visualizador'
        ELSE 'operador'
    END,
    CASE WHEN u.ativo = 1 THEN 1 ELSE 0 END
FROM `usuarios` u;

-- ---------------------------------------------------------------------------
-- 5. Índices para os acessos mais frequentes. A procedure evita duplicidade.
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS mt_adicionar_indice;
DELIMITER $$
CREATE PROCEDURE mt_adicionar_indice(
    IN p_tabela VARCHAR(64),
    IN p_indice VARCHAR(64),
    IN p_colunas VARCHAR(255)
)
BEGIN
    DECLARE v_existe INT DEFAULT 0;

    SELECT COUNT(*) INTO v_existe
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE BINARY TABLE_SCHEMA = BINARY DATABASE()
      AND BINARY TABLE_NAME = BINARY p_tabela
      AND BINARY INDEX_NAME = BINARY p_indice;

    IF v_existe = 0 THEN
        SET @sql_idx = CONCAT('ALTER TABLE `', p_tabela, '` ADD INDEX `', p_indice, '` ', p_colunas);
        PREPARE mt_idx_stmt FROM @sql_idx;
        EXECUTE mt_idx_stmt;
        DEALLOCATE PREPARE mt_idx_stmt;
    END IF;
END$$
DELIMITER ;

CALL mt_adicionar_indice('empresa', 'idx_empresa_tenant', '(`tenant_id`)');
CALL mt_adicionar_indice('usuarios', 'idx_usuarios_tenant', '(`tenant_id`, `ativo`)');

DROP PROCEDURE IF EXISTS mt_adicionar_coluna;
DROP PROCEDURE IF EXISTS mt_adicionar_indice;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- 6. Verificação final. Execute a auditoria completa depois deste resultado.
-- ---------------------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM tenants) AS total_tenants,
    (SELECT COUNT(*) FROM usuario_tenant) AS total_vinculos_usuario_tenant,
    (SELECT COUNT(*) FROM empresa WHERE tenant_id = 1) AS empresas_tenant_inicial,
    (SELECT COUNT(*) FROM usuarios WHERE tenant_id = 1) AS usuarios_tenant_inicial;

SELECT id, slug, status, nome_fantasia, razao_social, logo_url
FROM tenants
ORDER BY id;
