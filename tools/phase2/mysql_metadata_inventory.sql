-- ERP CONDOMINIOS — INVENTARIO DE METADADOS MYSQL/MARIADB
-- Somente SELECT sobre INFORMATION_SCHEMA e variaveis de servidor.
-- Nao executar sem aprovacao operacional separada e selecao manual do schema ERP.
-- Nao usar em modo Export Data. Este arquivo nao extrai linhas de negocio.
-- Nao inclui SHOW CREATE, corpos de rotinas, definicoes de views nem valores de celulas.
-- O operador deve guardar as saidas por canal privado, redigir segredos e registrar SHA-256.
-- Resultado vazio ou erro de privilegio significa nao observado/inacessivel, nao ausente.

-- 1. Versao/configuracao visivel no servidor e schema atualmente selecionado.
SELECT
    VERSION() AS server_version,
    @@version_comment AS server_product,
    DATABASE() AS selected_schema,
    @@character_set_server AS character_set_server,
    @@collation_server AS collation_server,
    @@time_zone AS session_time_zone,
    @@system_time_zone AS system_time_zone,
    @@sql_mode AS sql_mode,
    @@lower_case_table_names AS lower_case_table_names,
    @@default_storage_engine AS default_storage_engine,
    @@event_scheduler AS event_scheduler;

-- 2. Tabelas/views e atributos estruturais; TABLE_ROWS/cardinality sao omitidos.
SELECT
    TABLE_SCHEMA,
    TABLE_NAME,
    TABLE_TYPE,
    ENGINE,
    ROW_FORMAT,
    TABLE_COLLATION,
    CASE WHEN AUTO_INCREMENT IS NULL THEN 0 ELSE 1 END AS HAS_AUTO_INCREMENT,
    CREATE_OPTIONS
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;

-- 3. Colunas e atributos estruturais sem valores de DEFAULT ou expressoes geradas.
-- COLUMN_TYPE pode listar labels de ENUM/SET (metadado de schema): revisar/redigir.
SELECT
    TABLE_SCHEMA,
    TABLE_NAME,
    ORDINAL_POSITION,
    COLUMN_NAME,
    CASE WHEN COLUMN_DEFAULT IS NULL THEN 0 ELSE 1 END AS HAS_SCHEMA_DEFAULT,
    IS_NULLABLE,
    DATA_TYPE,
    COLUMN_TYPE,
    CHARACTER_MAXIMUM_LENGTH,
    CHARACTER_OCTET_LENGTH,
    NUMERIC_PRECISION,
    NUMERIC_SCALE,
    DATETIME_PRECISION,
    CHARACTER_SET_NAME,
    COLLATION_NAME,
    COLUMN_KEY,
    EXTRA,
    CASE WHEN GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '' THEN 0 ELSE 1 END AS HAS_GENERATED_EXPRESSION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 4. Indices, sequencia de colunas e comprimento de prefixo; cardinality omitida.
SELECT
    TABLE_SCHEMA,
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME,
    SUB_PART,
    COLLATION,
    INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- 5. Constraints e relacoes explicitamente declaradas.
SELECT
    tc.TABLE_SCHEMA,
    tc.TABLE_NAME,
    tc.CONSTRAINT_NAME,
    tc.CONSTRAINT_TYPE,
    kcu.ORDINAL_POSITION,
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_SCHEMA,
    kcu.REFERENCED_TABLE_NAME,
    kcu.REFERENCED_COLUMN_NAME,
    rc.MATCH_OPTION,
    rc.UPDATE_RULE,
    rc.DELETE_RULE
FROM information_schema.TABLE_CONSTRAINTS AS tc
LEFT JOIN information_schema.KEY_COLUMN_USAGE AS kcu
    ON kcu.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
   AND kcu.TABLE_SCHEMA = tc.TABLE_SCHEMA
   AND kcu.TABLE_NAME = tc.TABLE_NAME
   AND kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS AS rc
    ON rc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
   AND rc.TABLE_NAME = tc.TABLE_NAME
   AND rc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
WHERE tc.TABLE_SCHEMA = DATABASE()
ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION;

-- 6. Objetos programaveis: schema, tipo, nome, status/timing/evento; nunca corpos.
SELECT TABLE_SCHEMA AS OBJECT_SCHEMA, 'VIEW' AS OBJECT_TYPE, TABLE_NAME AS OBJECT_NAME,
       CAST(NULL AS CHAR(32)) AS OBJECT_STATUS, CAST(NULL AS CHAR(32)) AS ACTION_TIMING,
       CAST(NULL AS CHAR(32)) AS OBJECT_EVENT
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE()
UNION ALL
SELECT ROUTINE_SCHEMA AS OBJECT_SCHEMA, ROUTINE_TYPE AS OBJECT_TYPE, ROUTINE_NAME AS OBJECT_NAME,
       CAST(NULL AS CHAR(32)) AS OBJECT_STATUS, CAST(NULL AS CHAR(32)) AS ACTION_TIMING,
       CAST(NULL AS CHAR(32)) AS OBJECT_EVENT
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = DATABASE()
UNION ALL
SELECT TRIGGER_SCHEMA AS OBJECT_SCHEMA, 'TRIGGER' AS OBJECT_TYPE, TRIGGER_NAME AS OBJECT_NAME,
       CAST(NULL AS CHAR(32)) AS OBJECT_STATUS, ACTION_TIMING, EVENT_MANIPULATION AS OBJECT_EVENT
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
UNION ALL
SELECT EVENT_SCHEMA AS OBJECT_SCHEMA, 'EVENT' AS OBJECT_TYPE, EVENT_NAME AS OBJECT_NAME,
       STATUS AS OBJECT_STATUS, CAST(NULL AS CHAR(32)) AS ACTION_TIMING,
       EVENT_TYPE AS OBJECT_EVENT
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = DATABASE()
ORDER BY OBJECT_SCHEMA, OBJECT_TYPE, OBJECT_NAME;

-- 7. Candidatos de migration ledger: nomes/tipos apenas. Nenhum DDL/registro do ledger.
SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      LOWER(TABLE_NAME) LIKE '%migration%'
      OR LOWER(TABLE_NAME) LIKE '%schema%history%'
      OR LOWER(TABLE_NAME) LIKE '%version%'
  )
ORDER BY TABLE_NAME;
