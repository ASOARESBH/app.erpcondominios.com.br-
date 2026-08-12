-- ============================================================================
-- DIAGNÓSTICO MÍNIMO — EXISTÊNCIA DA TABELA TENANTS
-- MySQL/MariaDB 5.7 | Somente leitura
-- Execute isoladamente no phpMyAdmin, no banco inlaud99_erpcondor.
-- ============================================================================

-- 1. Confirma o banco efetivamente selecionado nesta conexão.
SELECT DATABASE() AS banco_em_uso, @@collation_database AS collation_do_banco;

-- 2. Confirma a existência da tabela sem acessar tenants diretamente.
SELECT
    t.TABLE_SCHEMA,
    t.TABLE_NAME,
    t.ENGINE,
    t.TABLE_COLLATION,
    t.TABLE_ROWS,
    t.CREATE_TIME
FROM INFORMATION_SCHEMA.TABLES AS t
WHERE BINARY t.TABLE_SCHEMA = BINARY DATABASE()
  AND BINARY t.TABLE_NAME = BINARY 'tenants';

-- 3. Confirma se as colunas mínimas do tenant estão presentes.
SELECT
    c.COLUMN_NAME,
    c.COLUMN_TYPE,
    c.IS_NULLABLE,
    c.COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS AS c
WHERE BINARY c.TABLE_SCHEMA = BINARY DATABASE()
  AND BINARY c.TABLE_NAME = BINARY 'tenants'
ORDER BY c.ORDINAL_POSITION;
