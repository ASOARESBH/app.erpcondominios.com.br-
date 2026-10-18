-- ERP Condomínios — URL pública personalizada de Projetos por tenant
-- MySQL 5.7 / MariaDB
-- Aplicar uma vez no banco da aplicação.
-- NULL mantém tenants sem URL personalizada e preserva a rota legada /projetos.

SET @db := DATABASE();

SET @tem_coluna := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'tenants'
      AND COLUMN_NAME = 'projetos_slug'
);
SET @sql := IF(
    @tem_coluna = 0,
    'ALTER TABLE `tenants` ADD COLUMN `projetos_slug` VARCHAR(60) NULL DEFAULT NULL AFTER `slug`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tem_indice := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'tenants'
      AND INDEX_NAME = 'uk_tenants_projetos_slug'
);
SET @sql := IF(
    @tem_indice = 0,
    'ALTER TABLE `tenants` ADD UNIQUE KEY `uk_tenants_projetos_slug` (`projetos_slug`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Exemplo de configuração manual, sem sobrescrever valores existentes:
-- UPDATE tenants SET projetos_slug = 'associacao-exemplo' WHERE id = 1 AND (projetos_slug IS NULL OR projetos_slug = '');
