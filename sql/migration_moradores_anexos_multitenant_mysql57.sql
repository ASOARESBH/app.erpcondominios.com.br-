-- Correção multi-tenant dos anexos de moradores (MySQL 5.7+).
-- Execute uma vez no banco do ERP após backup.

SET @db_name = DATABASE();

SET @table_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'moradores_anexos'
);

SET @sql = IF(@table_exists = 0,
  'CREATE TABLE moradores_anexos (id INT NOT NULL AUTO_INCREMENT, tenant_id INT NOT NULL DEFAULT 1, morador_id INT NOT NULL, nome_documento VARCHAR(200) NOT NULL, nome_arquivo VARCHAR(255) NOT NULL, nome_original VARCHAR(255) NOT NULL, caminho VARCHAR(500) NOT NULL, tipo_mime VARCHAR(100) NOT NULL, tamanho_bytes INT NOT NULL DEFAULT 0, ativo TINYINT(1) NOT NULL DEFAULT 1, criado_por VARCHAR(200) DEFAULT NULL, data_cadastro TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, data_atualizacao TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY idx_tenant_morador (tenant_id, morador_id), KEY idx_ativo (ativo)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_tenant = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'moradores_anexos' AND COLUMN_NAME = 'tenant_id'
);
SET @sql = IF(@has_tenant = 0,
  'ALTER TABLE moradores_anexos ADD COLUMN tenant_id INT NOT NULL DEFAULT 1 AFTER id',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_index = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'moradores_anexos' AND INDEX_NAME = 'idx_tenant_morador'
);
SET @sql = IF(@has_index = 0,
  'ALTER TABLE moradores_anexos ADD KEY idx_tenant_morador (tenant_id, morador_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Registros antigos recebem tenant_id=1 por padrão.
-- Em bancos com mais de um condomínio, revise essa atribuição antes do uso.
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'moradores_anexos'
ORDER BY ORDINAL_POSITION;

