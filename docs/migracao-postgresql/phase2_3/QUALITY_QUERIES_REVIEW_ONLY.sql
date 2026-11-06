-- Fase 2.3 — ORIGEM MYSQL/PERCONA 5.7 — SOMENTE REVISÃO
-- NÃO EXECUTAR ESTE ARQUIVO COMO SCRIPT. Nenhum bloco foi executado.
-- PostgreSQL é o destino definido, ainda não instalado nesta fase.
-- :source_schema/:validated_table/:validated_column e outros marcadores são
-- placeholders de revisão, não SQL válido pronto nem bind de identificadores.
-- Resolver identificadores por allowlist e quoting MySQL, nunca por payload.
-- Parâmetros de valores devem seguir prepared statements no cliente aprovado.
-- Uma tabela/coluna ou relação por vez. Nunca retornar identidades/amostras.
-- COUNT pode exigir scan completo; LIMIT não reduz esse custo.
-- Validar schema, TABLE_TYPE, colunas/tipos/nullabilidade, privilégios, índices
-- e custo antes de consultar dados. Não usar EXPLAIN ANALYZE, DML, DDL ou SET.
-- As tabelas-alvo dos templates devem ser BASE TABLE confirmadas. Views exigem
-- revisão separada da definição, permissões e escopo; nomes não provam tipo.

-- A1. Tipo real de um objeto no catálogo da ORIGEM.
SELECT COUNT(*) AS matching_object_count,
       COALESCE(SUM(TABLE_TYPE = 'BASE TABLE'), 0) AS base_table_count,
       COALESCE(SUM(TABLE_TYPE = 'VIEW'), 0) AS view_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = :source_schema AND TABLE_NAME = :table_name;

-- A2. PK instalada (não confundir ausência no CREATE inicial com ausência live).
SELECT COUNT(*) AS primary_key_count
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = :source_schema AND TABLE_NAME = :table_name
  AND CONSTRAINT_TYPE = 'PRIMARY KEY';

-- A3. Comparação de tipo/nullabilidade de uma coluna. COLUMN_TYPE é metadado;
-- seu contrato aprovado pode incluir argumentos e UNSIGNED, sem projeção de labels.
SELECT COUNT(*) AS column_count,
       COALESCE(SUM(NOT (COLUMN_TYPE <=> :expected_column_type)), 0) AS type_mismatch_count,
       COALESCE(SUM(NOT (IS_NULLABLE <=> :expected_is_nullable)), 0) AS nullability_mismatch_count,
       COALESCE(SUM(EXTRA LIKE '%auto_increment%'), 0) AS identity_attribute_count
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = :source_schema AND TABLE_NAME = :table_name
  AND COLUMN_NAME = :column_name;

-- A4. Contagem de índices por nome (STATISTICS contém uma linha por componente).
-- É triagem: conferir separadamente ordem, SUB_PART, collation/direção e cobertura.
SELECT COUNT(DISTINCT INDEX_NAME) AS index_count,
       COUNT(DISTINCT CASE WHEN NON_UNIQUE = 0 THEN INDEX_NAME END) AS unique_index_count,
       COUNT(DISTINCT CASE WHEN INDEX_NAME = 'PRIMARY' THEN INDEX_NAME END) AS primary_index_count,
       COALESCE(SUM(SUB_PART IS NOT NULL), 0) AS prefix_component_count
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = :source_schema AND TABLE_NAME = :table_name;

-- A5. Comparação estrutural de dois campos de uma relação comprovada.
-- Divergência é candidato de revisão, não prova de incompatibilidade de dados.
SELECT COUNT(*) AS matched_column_pair_count,
       COALESCE(SUM(NOT (c.DATA_TYPE <=> p.DATA_TYPE)), 0) AS family_mismatch_count,
       COALESCE(SUM(NOT (c.COLUMN_TYPE <=> p.COLUMN_TYPE)), 0) AS full_type_difference_count,
       COALESCE(SUM(NOT (c.CHARACTER_SET_NAME <=> p.CHARACTER_SET_NAME)), 0) AS charset_difference_count,
       COALESCE(SUM(NOT (c.COLLATION_NAME <=> p.COLLATION_NAME)), 0) AS collation_difference_count
FROM information_schema.COLUMNS AS c
JOIN information_schema.COLUMNS AS p
  ON p.TABLE_SCHEMA = :source_schema AND p.TABLE_NAME = :parent_table
 AND p.COLUMN_NAME = :parent_column
WHERE c.TABLE_SCHEMA = :source_schema AND c.TABLE_NAME = :child_table
  AND c.COLUMN_NAME = :child_column;

-- A6. Triagem de grants SELECT no catálogo visível ao usuário atual.
-- Não comprova privilégio efetivo: visibilidade, grants globais/schema/tabela,
-- usuário autenticado e política do operador precisam ser verificados separadamente.
SELECT COUNT(*) AS visible_select_grant_count
FROM (
    SELECT GRANTEE FROM information_schema.USER_PRIVILEGES
    WHERE GRANTEE = :approved_grantee AND PRIVILEGE_TYPE = 'SELECT'
    UNION ALL
    SELECT GRANTEE FROM information_schema.SCHEMA_PRIVILEGES
    WHERE GRANTEE = :approved_grantee AND PRIVILEGE_TYPE = 'SELECT'
      AND TABLE_SCHEMA = :source_schema
    UNION ALL
    SELECT GRANTEE FROM information_schema.TABLE_PRIVILEGES
    WHERE GRANTEE = :approved_grantee AND PRIVILEGE_TYPE = 'SELECT'
      AND TABLE_SCHEMA = :source_schema AND TABLE_NAME = :table_name
) AS visible_grants;

-- B. ÓRFÃOS — 11 FKs explícitas do export, ainda não confirmadas live.
-- Confirmar unicidade pai, tipos e índice/plano. Não inferir FK por sufixo _id.
-- FK NULL não é órfã. NOT EXISTS conta cada filho uma vez, não pares de JOIN.

-- B1. aplicativos_versoes -> aplicativos_catalogo
SELECT COUNT(*) AS orphan_row_count
FROM :source_schema.aplicativos_versoes AS c
WHERE c.aplicativo_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.aplicativos_catalogo AS p WHERE p.id = c.aplicativo_id
);

-- B2. dashboard_empresa_widgets -> dashboard_widgets_catalogo
SELECT COUNT(*) AS orphan_row_count
FROM :source_schema.dashboard_empresa_widgets AS c
WHERE c.widget_key IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.dashboard_widgets_catalogo AS p WHERE p.widget_key = c.widget_key
);

-- B3. dashboard_usuario_widgets -> dashboard_widgets_catalogo
SELECT COUNT(*) AS orphan_row_count
FROM :source_schema.dashboard_usuario_widgets AS c
WHERE c.widget_key IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.dashboard_widgets_catalogo AS p WHERE p.widget_key = c.widget_key
);

-- B4. moradores_anexos -> moradores
SELECT COUNT(*) AS orphan_row_count
FROM :source_schema.moradores_anexos AS c
WHERE c.morador_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.moradores AS p WHERE p.id = c.morador_id
);

-- B5. rbac_auditoria -> rbac_sessoes; FK nullable
SELECT COUNT(*) AS orphan_row_count
FROM :source_schema.rbac_auditoria AS c
WHERE c.sessao_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.rbac_sessoes AS p WHERE p.id = c.sessao_id
);

-- B6. rbac_grupo_permissoes -> rbac_grupos
SELECT COUNT(*) AS orphan_row_count
FROM :source_schema.rbac_grupo_permissoes AS c
WHERE c.grupo_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.rbac_grupos AS p WHERE p.id = c.grupo_id
);

-- B7. rbac_grupo_permissoes -> rbac_permissoes
SELECT COUNT(*) AS orphan_row_count
FROM :source_schema.rbac_grupo_permissoes AS c
WHERE c.permissao_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.rbac_permissoes AS p WHERE p.id = c.permissao_id
);

-- B8. rbac_modulos -> rbac_modulos; auto-relação nullable
SELECT COUNT(*) AS orphan_row_count
FROM :source_schema.rbac_modulos AS c
WHERE c.modulo_pai_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.rbac_modulos AS p WHERE p.id = c.modulo_pai_id
);

-- B9. rbac_permissoes -> rbac_modulos
SELECT COUNT(*) AS orphan_row_count
FROM :source_schema.rbac_permissoes AS c
WHERE c.modulo_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.rbac_modulos AS p WHERE p.id = c.modulo_id
);

-- B10. rbac_usuario_grupos -> rbac_grupos
SELECT COUNT(*) AS orphan_row_count
FROM :source_schema.rbac_usuario_grupos AS c
WHERE c.grupo_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.rbac_grupos AS p WHERE p.id = c.grupo_id
);

-- B11. rbac_usuario_permissoes -> rbac_permissoes
SELECT COUNT(*) AS orphan_row_count
FROM :source_schema.rbac_usuario_permissoes AS c
WHERE c.permissao_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.rbac_permissoes AS p WHERE p.id = c.permissao_id
);

-- C. TENANT MISMATCH — somente três FKs com tenant nas duas pontas no export.
-- Confirmar escopo e unicidade pai antes. NULL tenant tem contagem própria no bloco E;
-- não considerá-lo global nem substituí-lo por 1. EXISTS evita inflar contagem de filhos.
SELECT COUNT(*) AS tenant_mismatch_child_count
FROM :source_schema.moradores_anexos AS c
WHERE c.morador_id IS NOT NULL AND c.tenant_id IS NOT NULL AND EXISTS (
    SELECT 1 FROM :source_schema.moradores AS p
    WHERE p.id = c.morador_id AND p.tenant_id IS NOT NULL AND p.tenant_id <> c.tenant_id
);

SELECT COUNT(*) AS tenant_mismatch_child_count
FROM :source_schema.rbac_auditoria AS c
WHERE c.sessao_id IS NOT NULL AND c.tenant_id IS NOT NULL AND EXISTS (
    SELECT 1 FROM :source_schema.rbac_sessoes AS p
    WHERE p.id = c.sessao_id AND p.tenant_id IS NOT NULL AND p.tenant_id <> c.tenant_id
);

SELECT COUNT(*) AS tenant_mismatch_child_count
FROM :source_schema.rbac_usuario_grupos AS c
WHERE c.grupo_id IS NOT NULL AND c.tenant_id IS NOT NULL AND EXISTS (
    SELECT 1 FROM :source_schema.rbac_grupos AS p
    WHERE p.id = c.grupo_id AND p.tenant_id IS NOT NULL AND p.tenant_id <> c.tenant_id
);

-- D. DUPLICIDADE UNIQUE — confirmar colunas/ordem/collation/SUB_PART e escopo.
-- UNIQUE normal MySQL e PostgreSQL padrão permitem múltiplos NULLs: excluir
-- qualquer componente NULL. NULLS NOT DISTINCT no futuro seria decisão diferente.
-- Prefix index exige projeção específica de prefixo em consulta revisada separada;
-- os templates de igualdade plena abaixo não validam UNIQUE por prefixo.
SELECT COUNT(*) AS duplicate_key_group_count,
       COALESCE(SUM(d.n - 1), 0) AS excess_duplicate_row_count
FROM (
    SELECT COUNT(*) AS n FROM :source_schema.validated_table AS c
    WHERE c.key_col_1 IS NOT NULL
    GROUP BY c.key_col_1 HAVING COUNT(*) > 1
) AS d;

SELECT COUNT(*) AS duplicate_key_group_count,
       COALESCE(SUM(d.n - 1), 0) AS excess_duplicate_row_count
FROM (
    SELECT COUNT(*) AS n FROM :source_schema.validated_table AS c
    WHERE c.key_col_1 IS NOT NULL AND c.key_col_2 IS NOT NULL
    GROUP BY c.key_col_1, c.key_col_2 HAVING COUNT(*) > 1
) AS d;

-- E. NULL — incompatibilidade NOT NULL depende de constraint futura aprovada.
SELECT COUNT(*) AS row_count, COUNT(c.validated_column) AS non_null_count,
       COUNT(*) - COUNT(c.validated_column) AS null_count
FROM :source_schema.validated_table AS c;

-- F. TEMPORAIS MYSQL — CAST para CHAR, não cast para data PostgreSQL.
-- Só DATE/DATETIME/TIMESTAMP confirmados. TIME exige política separada (duração
-- MySQL pode exceder 24h). sentinelas completas/parciais não são correção automática.
SELECT COUNT(*) AS row_count,
       COALESCE(SUM(c.temporal_column IS NULL), 0) AS null_count,
       COALESCE(SUM(LEFT(CAST(c.temporal_column AS CHAR), 10) = '0000-00-00'), 0) AS full_zero_date_count,
       COALESCE(SUM(LEFT(CAST(c.temporal_column AS CHAR), 10) <> '0000-00-00'
         AND LEFT(CAST(c.temporal_column AS CHAR), 10) REGEXP '^[0-9]{4}-(00-[0-9]{2}|[0-9]{2}-00)$'), 0) AS partial_zero_date_count
FROM :source_schema.validated_table AS c;

-- G. JSON_VALID em MySQL 5.7, sem payload, sem cast abortivo. JSON válido MySQL
-- não prova que todo número/Unicode seja representável em jsonb PostgreSQL.
SELECT COUNT(*) AS row_count,
       COALESCE(SUM(c.json_column IS NULL), 0) AS null_count,
       COALESCE(SUM(c.json_column IS NOT NULL AND JSON_VALID(c.json_column) = 0), 0) AS invalid_json_count
FROM :source_schema.validated_table AS c;

-- H. FAIXA — apenas coluna numérica validada e limites aprovados do tipo destino.
-- Não converter texto implicitamente em número para obter contagem.
SELECT COUNT(*) AS row_count,
       COALESCE(SUM(c.numeric_column IS NOT NULL
         AND (c.numeric_column < :approved_lower_bound OR c.numeric_column > :approved_upper_bound)), 0) AS out_of_target_range_count
FROM :source_schema.validated_table AS c;

-- I. FK COMPOSTA — não há FK composta explícita no export catalogado.
-- Usar somente se for comprovada live e a regra for MATCH SIMPLE: qualquer NULL
-- dispensa teste de existência; composição/ordem/tipos são pré-requisitos.
SELECT COUNT(*) AS composite_orphan_row_count
FROM :source_schema.validated_child AS c
WHERE c.fk_col_1 IS NOT NULL AND c.fk_col_2 IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM :source_schema.validated_parent AS p
    WHERE p.pk_col_1 = c.fk_col_1 AND p.pk_col_2 = c.fk_col_2
);

SELECT COALESCE(SUM((c.fk_col_1 IS NULL) <> (c.fk_col_2 IS NULL)), 0) AS partial_null_component_count,
       COALESCE(SUM(c.fk_col_1 IS NULL AND c.fk_col_2 IS NULL), 0) AS all_null_component_count
FROM :source_schema.validated_child AS c;

-- ENCERRAMENTO: revisão estática de custo com catálogo/estatísticas já autorizadas,
-- índices de junção, cardinalidade estimada e janela. EXPLAIN simples somente em
-- etapa autorizada separada, sem ANALYZE. Estatísticas estimadas não são contagens
-- exatas. Não criar fixtures com produção nem consultar um destino inexistente.
-- Abortar por objeto/tipo divergente, grants não verificados, scan incompatível,
-- coerção implícita, resultado identificável ou necessidade de mutação. Uma eventual
-- validação PostgreSQL pós-carga será planejada separadamente após instalação/QA.
