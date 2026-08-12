-- ============================================================================
-- AUDITORIA DE BRANDING E MULTI-TENANCY
-- ERP Condomínio | Compatível com MySQL/MariaDB 5.7
-- Somente leitura: não altera dados.
-- Banco de destino: inlaud99_erpcondor.
-- ============================================================================

USE `inlaud99_erpcondor`;

-- 1. Confirmar as estruturas centrais e colunas necessárias.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE BINARY TABLE_SCHEMA = BINARY DATABASE()
  AND BINARY TABLE_NAME IN (BINARY 'tenants', BINARY 'empresa', BINARY 'usuarios', BINARY 'usuario_tenant', BINARY 'empresa_log')
  AND BINARY COLUMN_NAME IN (BINARY 'id', BINARY 'tenant_id', BINARY 'slug', BINARY 'status', BINARY 'situacao', BINARY 'logo_url', BINARY 'ativo')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 2. Visão consolidada das empresas/tenants e suas logos.
SELECT
    t.id AS tenant_id,
    t.slug,
    t.status AS status_tenant,
    t.nome_fantasia AS nome_tenant,
    t.razao_social AS razao_tenant,
    t.logo_url AS logo_tenant,
    e.id AS empresa_id,
    e.situacao AS situacao_empresa,
    e.nome_fantasia AS nome_empresa,
    e.logo_url AS logo_empresa,
    CASE
        WHEN IFNULL(CHAR_LENGTH(t.logo_url), 0) = 0 THEN 'SEM_LOGO_TENANT'
        WHEN e.id IS NULL THEN 'SEM_CADASTRO_EMPRESA'
        WHEN IFNULL(CHAR_LENGTH(e.logo_url), 0) = 0 THEN 'LOGO_SOMENTE_TENANT'
        WHEN BINARY t.logo_url <> BINARY e.logo_url THEN 'LOGOS_DIVERGENTES'
        ELSE 'SINCRONIZADO'
    END AS situacao_branding
FROM tenants t
LEFT JOIN empresa e ON e.tenant_id = t.id
ORDER BY t.id;

-- 3. Usuários e vínculos por tenant.
SELECT
    t.id AS tenant_id,
    t.nome_fantasia AS tenant,
    COUNT(DISTINCT u.id) AS usuarios_diretos,
    COUNT(DISTINCT ut.id) AS vinculos_multitenant,
    SUM(CASE WHEN u.ativo = 1 THEN 1 ELSE 0 END) AS usuarios_diretos_ativos
FROM tenants t
LEFT JOIN usuarios u ON u.tenant_id = t.id
LEFT JOIN usuario_tenant ut ON ut.tenant_id = t.id
GROUP BY t.id, t.nome_fantasia
ORDER BY t.id;

-- 4. Cobertura de tenant_id nas tabelas do banco.
SELECT
    COUNT(*) AS tabelas_com_tenant_id
FROM INFORMATION_SCHEMA.COLUMNS
WHERE BINARY TABLE_SCHEMA = BINARY DATABASE()
  AND BINARY COLUMN_NAME = BINARY 'tenant_id';

-- 5. Tabelas de negócio sem tenant_id: revisar antes de considerá-las globais.
SELECT TABLE_NAME
FROM INFORMATION_SCHEMA.TABLES t
WHERE BINARY t.TABLE_SCHEMA = BINARY DATABASE()
  AND BINARY t.TABLE_TYPE = BINARY 'BASE TABLE'
  AND t.TABLE_NAME NOT IN (
      SELECT c.TABLE_NAME
      FROM INFORMATION_SCHEMA.COLUMNS c
      WHERE BINARY c.TABLE_SCHEMA = BINARY DATABASE()
        AND BINARY c.COLUMN_NAME = BINARY 'tenant_id'
  )
ORDER BY t.TABLE_NAME;

-- 6. Auditoria de divergência de logo a ser corrigida pelo módulo Empresa.
SELECT
    t.id AS tenant_id,
    t.slug,
    t.logo_url AS logo_cadastro_mestre,
    e.logo_url AS logo_cadastro_operacional,
    e.data_atualizacao AS empresa_atualizada_em
FROM tenants t
INNER JOIN empresa e ON e.tenant_id = t.id
WHERE BINARY IFNULL(t.logo_url, '') <> BINARY IFNULL(e.logo_url, '')
ORDER BY t.id;
