-- ============================================================================
-- AUDITORIA PÓS-CONSOLIDAÇÃO — ERP SERRA -> ERP CONDOR
-- Somente leitura. Compatível com MySQL/MariaDB 5.7.
-- ============================================================================

USE `inlaud99_erpcondor`;

-- 1. Resultado por tabela da própria migration.
SELECT tabela, registros_origem, registros_destino_antes, registros_destino_depois,
       (registros_destino_depois - registros_destino_antes) AS variacao_destino,
       status, mensagem, executado_em
FROM mt_consolidacao_tabelas
WHERE tenant_id = 1
ORDER BY status, tabela;

-- 2. Tabelas deliberadamente não migradas e motivo.
SELECT tabela, motivo, registrado_em
FROM mt_consolidacao_exclusoes
ORDER BY tabela;

-- 3. Integridade do tenant 1 e principais volumes.
SELECT t.id, t.slug, t.status, t.nome_fantasia, t.cnpj,
       (SELECT COUNT(*) FROM usuarios u WHERE u.tenant_id=t.id) AS usuarios,
       (SELECT COUNT(*) FROM usuario_tenant ut WHERE ut.tenant_id=t.id) AS vinculos_usuarios,
       (SELECT COUNT(*) FROM empresa e WHERE e.tenant_id=t.id) AS empresas,
       (SELECT COUNT(*) FROM moradores m WHERE m.tenant_id=t.id) AS moradores,
       (SELECT COUNT(*) FROM unidades un WHERE un.tenant_id=t.id) AS unidades,
       (SELECT COUNT(*) FROM veiculos v WHERE v.tenant_id=t.id) AS veiculos,
       (SELECT COUNT(*) FROM os_chamados os WHERE os.tenant_id=t.id) AS ordens_servico
FROM tenants t
WHERE t.id=1;

-- 4. Nenhum registro de tabela Multi-Tenant deve ficar sem tenant.
SELECT TABLE_NAME,
       CONCAT('SELECT COUNT(*) FROM `', TABLE_NAME, '` WHERE tenant_id IS NULL OR tenant_id=0') AS consulta_manual
FROM INFORMATION_SCHEMA.COLUMNS
WHERE BINARY TABLE_SCHEMA = BINARY 'inlaud99_erpcondor'
  AND BINARY COLUMN_NAME = BINARY 'tenant_id'
ORDER BY TABLE_NAME;

-- 5. Divergência entre identidade mestre e cadastro operacional.
SELECT t.id AS tenant_id, t.slug, t.nome_fantasia AS nome_tenant,
       e.nome_fantasia AS nome_empresa, t.logo_url AS logo_tenant, e.logo_url AS logo_empresa,
       CASE
           WHEN e.id IS NULL THEN 'SEM_EMPRESA_OPERACIONAL'
           WHEN IFNULL(CHAR_LENGTH(t.logo_url),0)=0 THEN 'SEM_LOGO_TENANT'
           WHEN IFNULL(CHAR_LENGTH(e.logo_url),0)=0 THEN 'LOGO_SOMENTE_TENANT'
           WHEN BINARY t.logo_url <> BINARY e.logo_url THEN 'LOGOS_DIVERGENTES'
           ELSE 'SINCRONIZADO'
       END AS situacao_branding
FROM tenants t
LEFT JOIN empresa e ON e.tenant_id=t.id
WHERE t.id=1;

-- 6. Confirmação de que o sistema possui apenas o tenant consolidado.
SELECT id, slug, status, nome_fantasia, razao_social, data_atualizacao
FROM tenants
ORDER BY id;
