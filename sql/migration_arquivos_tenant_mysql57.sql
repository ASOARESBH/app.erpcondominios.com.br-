-- ============================================================================
-- ARMAZENAMENTO CENTRALIZADO DE ARQUIVOS MULTI-TENANT
-- Banco alvo: inlaud99_erpcondor | MySQL/MariaDB 5.7
-- Execute via phpMyAdmin > Importar após backup completo.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `inlaud99_erpcondor`.`tenant_arquivos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `tipo` VARCHAR(60) NOT NULL,
  `nome_original` VARCHAR(500) NOT NULL,
  `extensao` VARCHAR(20) DEFAULT NULL,
  `mime_type` VARCHAR(150) NOT NULL,
  `tamanho_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sha256` CHAR(64) NOT NULL,
  `conteudo` LONGBLOB NOT NULL,
  `publico` TINYINT(1) NOT NULL DEFAULT 0,
  `token_publico` CHAR(48) DEFAULT NULL,
  `caminho_legado` VARCHAR(1024) DEFAULT NULL,
  `criado_por_usuario_id` INT(11) DEFAULT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_arquivo_legado` (`tenant_id`, `caminho_legado`(191)),
  UNIQUE KEY `uk_tenant_arquivo_token` (`token_publico`),
  KEY `idx_tenant_arquivo_tipo` (`tenant_id`, `tipo`, `ativo`),
  KEY `idx_tenant_arquivo_sha` (`tenant_id`, `sha256`),
  KEY `idx_tenant_arquivo_criado` (`tenant_id`, `criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inlaud99_erpcondor`.`tenant_arquivo_referencias` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `arquivo_id` BIGINT UNSIGNED NOT NULL,
  `modulo` VARCHAR(80) NOT NULL,
  `registro_id` BIGINT UNSIGNED DEFAULT NULL,
  `campo_origem` VARCHAR(100) DEFAULT NULL,
  `origem_legado` VARCHAR(1024) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_referencia_arquivo` (`tenant_id`, `arquivo_id`, `modulo`, `registro_id`, `campo_origem`),
  KEY `idx_referencia_registro` (`tenant_id`, `modulo`, `registro_id`),
  KEY `idx_referencia_arquivo` (`arquivo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inlaud99_erpcondor`.`tenant_arquivos_migracao_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `caminho_legado` VARCHAR(1024) NOT NULL,
  `arquivo_id` BIGINT UNSIGNED DEFAULT NULL,
  `status` ENUM('importado','ignorado','erro') NOT NULL,
  `mensagem` VARCHAR(1000) DEFAULT NULL,
  `executado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migracao_caminho` (`tenant_id`, `caminho_legado`(191)),
  KEY `idx_migracao_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificação de estrutura.
SELECT TABLE_NAME, TABLE_ROWS
FROM INFORMATION_SCHEMA.TABLES
WHERE BINARY TABLE_SCHEMA = BINARY 'inlaud99_erpcondor'
  AND BINARY TABLE_NAME IN (BINARY 'tenant_arquivos', BINARY 'tenant_arquivo_referencias', BINARY 'tenant_arquivos_migracao_log')
ORDER BY TABLE_NAME;


-- Compatibilidade do módulo Contratos: documentos anexados a orçamentos.
-- A tabela pode já existir em instalações antigas, criada pelo módulo anterior.
CREATE TABLE IF NOT EXISTS `inlaud99_erpcondor`.`contrato_orcamento_documentos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL DEFAULT 1,
  `orcamento_id` INT(11) NOT NULL,
  `nome_documento` VARCHAR(255) NOT NULL,
  `tipo_documento` VARCHAR(50) NOT NULL,
  `nome_arquivo` VARCHAR(255) NOT NULL,
  `url_arquivo` VARCHAR(500) NOT NULL,
  `tamanho` BIGINT DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `data_upload` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_orcamento` (`tenant_id`, `orcamento_id`),
  KEY `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
DROP PROCEDURE IF EXISTS `inlaud99_erpcondor`.`sp_arquivos_adicionar_tenant_orc_docs`$$
CREATE PROCEDURE `inlaud99_erpcondor`.`sp_arquivos_adicionar_tenant_orc_docs`()
BEGIN
  DECLARE v_coluna INT DEFAULT 0;
  DECLARE v_indice INT DEFAULT 0;

  SELECT COUNT(*) INTO v_coluna
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = 'inlaud99_erpcondor'
    AND TABLE_NAME = 'contrato_orcamento_documentos'
    AND COLUMN_NAME = 'tenant_id';

  IF v_coluna = 0 THEN
    ALTER TABLE `inlaud99_erpcondor`.`contrato_orcamento_documentos`
      ADD COLUMN `tenant_id` INT(11) NOT NULL DEFAULT 1 AFTER `id`;
  END IF;

  SELECT COUNT(*) INTO v_indice
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = 'inlaud99_erpcondor'
    AND TABLE_NAME = 'contrato_orcamento_documentos'
    AND INDEX_NAME = 'idx_tenant_orcamento';

  IF v_indice = 0 THEN
    ALTER TABLE `inlaud99_erpcondor`.`contrato_orcamento_documentos`
      ADD KEY `idx_tenant_orcamento` (`tenant_id`, `orcamento_id`);
  END IF;
END$$
CALL `inlaud99_erpcondor`.`sp_arquivos_adicionar_tenant_orc_docs`()$$
DROP PROCEDURE IF EXISTS `inlaud99_erpcondor`.`sp_arquivos_adicionar_tenant_orc_docs`$$
DELIMITER ;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'inlaud99_erpcondor'
  AND TABLE_NAME = 'contrato_orcamento_documentos'
  AND COLUMN_NAME = 'tenant_id';
