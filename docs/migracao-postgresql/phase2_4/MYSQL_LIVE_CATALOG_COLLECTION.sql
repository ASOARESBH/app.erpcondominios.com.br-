-- ERP CONDOMINIOS — COLETA INCREMENTAL DE METADADOS MYSQL/PERCONA
-- Compatibilidade alvo: MySQL/Percona 5.7.44.
-- Todos os SELECT leem somente INFORMATION_SCHEMA/variaveis do servidor.
-- Os SHOW CREATE comentados sao comandos de exibicao: nunca execute o DDL retornado.
-- Execute uma instrucao por vez. Cada bloco e independente e tem schema explicito.
-- Schema fixo: inlaud99_erpcondor. Confirme o bloco 01 antes dos demais.
-- Nenhuma consulta seleciona registros de aplicacao ou linhas de moradores.
-- Para paginar, altere somente o OFFSET mantendo o LIMIT e a ordenacao do bloco.

-- 01. IDENTIFICACAO DO SERVIDOR E SCHEMA
SELECT
    VERSION() AS server_version,
    @@version_comment AS server_product,
    DATABASE() AS selected_schema,
    'inlaud99_erpcondor' AS requested_schema,
    @@character_set_server AS character_set_server,
    @@collation_server AS collation_server,
    @@time_zone AS session_time_zone,
    @@system_time_zone AS system_time_zone,
    @@sql_mode AS sql_mode,
    @@lower_case_table_names AS lower_case_table_names,
    @@default_storage_engine AS default_storage_engine,
    @@event_scheduler AS event_scheduler;

-- 02. LISTA DE TABELAS E VIEWS; executar em paginas de 50
SELECT
    TABLE_SCHEMA,
    TABLE_NAME,
    TABLE_TYPE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'inlaud99_erpcondor'
ORDER BY TABLE_NAME
LIMIT 50 OFFSET 0;

-- 03. ENGINE E COLLATION POR OBJETO; executar em paginas de 50
SELECT
    TABLE_SCHEMA,
    TABLE_NAME,
    TABLE_TYPE,
    ENGINE,
    ROW_FORMAT,
    TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'inlaud99_erpcondor'
ORDER BY TABLE_NAME
LIMIT 50 OFFSET 0;

-- 04. COLUNAS, TIPOS, DIMENSOES, NULLABILIDADE E GERACAO; paginas de 100
-- COLUMN_TYPE pode incluir labels ENUM/SET. Defaults de nomes potencialmente
-- sensiveis sao omitidos; os demais defaults sao metadados de schema, nao linhas.
SELECT
    TABLE_SCHEMA,
    TABLE_NAME,
    ORDINAL_POSITION,
    COLUMN_NAME,
    DATA_TYPE,
    COLUMN_TYPE,
    CHARACTER_MAXIMUM_LENGTH,
    CHARACTER_OCTET_LENGTH,
    NUMERIC_PRECISION,
    NUMERIC_SCALE,
    DATETIME_PRECISION,
    CHARACTER_SET_NAME,
    COLLATION_NAME,
    IS_NULLABLE,
    CASE
        WHEN COLUMN_DEFAULT IS NULL THEN 'NULL_OR_NO_DEFAULT'
        ELSE 'NON_NULL_DEFAULT_PRESENT'
    END AS COLUMN_DEFAULT_STATUS,
    EXTRA,
    CASE
        WHEN GENERATION_EXPRESSION IS NULL OR GENERATION_EXPRESSION = '' THEN 0
        ELSE 1
    END AS HAS_GENERATION_EXPRESSION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'inlaud99_erpcondor'
ORDER BY TABLE_NAME, ORDINAL_POSITION
LIMIT 100 OFFSET 0;

-- Para verificar default/expressao gerada em objeto especifico, use SHOW CREATE TABLE
-- individualmente depois de revisar/redigir a definicao antes de compartilhar.
-- SHOW CREATE TABLE `inlaud99_erpcondor`.`nome_da_tabela`;

-- 05. PRIMARY KEYS E ORDEM DAS COLUNAS; paginas de 100
SELECT
    tc.TABLE_SCHEMA,
    tc.TABLE_NAME,
    tc.CONSTRAINT_NAME,
    kcu.ORDINAL_POSITION,
    kcu.COLUMN_NAME
FROM information_schema.TABLE_CONSTRAINTS AS tc
JOIN information_schema.KEY_COLUMN_USAGE AS kcu
  ON kcu.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
 AND kcu.TABLE_SCHEMA = tc.TABLE_SCHEMA
 AND kcu.TABLE_NAME = tc.TABLE_NAME
 AND kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
WHERE tc.CONSTRAINT_SCHEMA = 'inlaud99_erpcondor'
  AND tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
ORDER BY tc.TABLE_NAME, kcu.ORDINAL_POSITION
LIMIT 100 OFFSET 0;

-- 06. INDICES SIMPLES/COMPOSTOS, PREFIXOS E ORDEM; paginas de 100
-- CARDINALITY deliberadamente omitida; nao usar esta consulta para estimar linhas.
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
WHERE TABLE_SCHEMA = 'inlaud99_erpcondor'
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
LIMIT 100 OFFSET 0;

-- 07. FOREIGN KEYS E REGRAS REFERENCIAIS; paginas de 100
SELECT
    kcu.CONSTRAINT_SCHEMA,
    kcu.TABLE_NAME,
    kcu.CONSTRAINT_NAME,
    kcu.ORDINAL_POSITION,
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_SCHEMA,
    kcu.REFERENCED_TABLE_NAME,
    kcu.REFERENCED_COLUMN_NAME,
    rc.MATCH_OPTION,
    rc.UPDATE_RULE,
    rc.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE AS kcu
LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS AS rc
  ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
 AND rc.TABLE_NAME = kcu.TABLE_NAME
 AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
WHERE kcu.TABLE_SCHEMA = 'inlaud99_erpcondor'
  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
LIMIT 100 OFFSET 0;

-- 08. TRIGGERS: EVENTO, TIMING, ORDEM E CORPO COMO TEXTO; paginas de 10
-- Nao retorna DEFINER. ACTION_STATEMENT e codigo-fonte; o trigger nao e executado.
SELECT
    TRIGGER_SCHEMA,
    TRIGGER_NAME,
    EVENT_OBJECT_TABLE,
    EVENT_MANIPULATION,
    ACTION_TIMING,
    ACTION_ORDER,
    ACTION_STATEMENT AS TRIGGER_BODY
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = 'inlaud99_erpcondor'
ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER
LIMIT 10 OFFSET 0;

-- Fallback individual se ACTION_STATEMENT estiver inacessivel:
-- SHOW CREATE TRIGGER `inlaud99_erpcondor`.`nome_do_trigger`;

-- 09. VIEWS E DEFINICOES COMO TEXTO; paginas de 10
-- VIEW_DEFINITION nao executa a view. Nao compartilhar definicao sem revisar literais.
SELECT
    TABLE_SCHEMA,
    TABLE_NAME,
    VIEW_DEFINITION,
    CHECK_OPTION,
    IS_UPDATABLE,
    SECURITY_TYPE,
    CHARACTER_SET_CLIENT,
    COLLATION_CONNECTION
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = 'inlaud99_erpcondor'
ORDER BY TABLE_NAME
LIMIT 10 OFFSET 0;

-- Fallback individual se VIEW_DEFINITION estiver NULL/inacessivel:
-- SHOW CREATE VIEW `inlaud99_erpcondor`.`nome_da_view`;

-- 10A. PROCEDURES E FUNCTIONS; definicoes como texto, paginas de 10
SELECT
    ROUTINE_SCHEMA,
    ROUTINE_NAME,
    ROUTINE_TYPE,
    DATA_TYPE,
    DTD_IDENTIFIER,
    IS_DETERMINISTIC,
    SQL_DATA_ACCESS,
    SECURITY_TYPE,
    SQL_MODE,
    ROUTINE_DEFINITION
FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA = 'inlaud99_erpcondor'
ORDER BY ROUTINE_TYPE, ROUTINE_NAME
LIMIT 10 OFFSET 0;

-- Fallback individual apos confirmar o tipo/nome no catalogo:
-- SHOW CREATE PROCEDURE `inlaud99_erpcondor`.`nome_da_procedure`;
-- SHOW CREATE FUNCTION `inlaud99_erpcondor`.`nome_da_function`;

-- 10B. EVENTS: AGENDA E DEFINICAO COMO TEXTO; paginas de 10
SELECT
    EVENT_SCHEMA,
    EVENT_NAME,
    EVENT_TYPE,
    STATUS,
    ON_COMPLETION,
    EXECUTE_AT,
    INTERVAL_VALUE,
    INTERVAL_FIELD,
    STARTS,
    ENDS,
    TIME_ZONE,
    SQL_MODE,
    EVENT_DEFINITION
FROM information_schema.EVENTS
WHERE EVENT_SCHEMA = 'inlaud99_erpcondor'
ORDER BY EVENT_NAME
LIMIT 10 OFFSET 0;

-- Fallback individual se EVENT_DEFINITION estiver NULL/inacessivel:
-- SHOW CREATE EVENT `inlaud99_erpcondor`.`nome_do_event`;
