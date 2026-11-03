-- Índice composto para consultas de O.S. por tenant e unidade.
-- Compatível com MySQL/MariaDB 5.7; pode ser executado novamente com segurança.
SET @os_unidade_tem_tabela = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'os_chamados'
      AND TABLE_TYPE = 'BASE TABLE'
);
SET @os_unidade_tem_colunas = (
    SELECT COUNT(DISTINCT COLUMN_NAME)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'os_chamados'
      AND COLUMN_NAME IN ('tenant_id', 'morador_unidade')
);
SET @os_unidade_tem_indice = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'os_chamados'
      AND INDEX_NAME = 'idx_os_tenant_unidade'
);
SET @os_unidade_sql = IF(
    @os_unidade_tem_tabela = 1 AND @os_unidade_tem_colunas = 2 AND @os_unidade_tem_indice = 0,
    'ALTER TABLE `os_chamados` ADD INDEX `idx_os_tenant_unidade` (`tenant_id`, `morador_unidade`)',
    'SELECT 1'
);
PREPARE stmt_os_unidade_indice FROM @os_unidade_sql;
EXECUTE stmt_os_unidade_indice;
DEALLOCATE PREPARE stmt_os_unidade_indice;
