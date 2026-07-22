-- =========================================================================
-- AUDITORIA MULTI-TENANT — VERIFICAÇÃO DE COBERTURA
-- =========================================================================
-- Execute este script no phpMyAdmin após a migration da Fase 1
-- para verificar se o isolamento de dados está funcionando corretamente.
--
-- COMO USAR:
--   1. Execute no banco inlaud99_erpserra
--   2. Verifique se todos os resultados mostram tenant_id = 1
--   3. Após adicionar um segundo condomínio, verifique o isolamento
-- =========================================================================

-- ─── 1. VERIFICAR TABELA TENANTS ─────────────────────────────────────────
SELECT 
    '=== TENANTS CADASTRADOS ===' AS verificacao;

SELECT 
    id, slug, razao_social, nome_fantasia, plano, status, data_criacao
FROM tenants
ORDER BY id;

-- ─── 2. VERIFICAR COBERTURA DE tenant_id NAS TABELAS PRINCIPAIS ──────────
SELECT 
    '=== COBERTURA POR TABELA ===' AS verificacao;

SELECT 
    'moradores' AS tabela,
    COUNT(*) AS total_registros,
    COUNT(DISTINCT tenant_id) AS total_tenants,
    GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id) AS tenant_ids
FROM moradores

UNION ALL

SELECT 'unidades', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM unidades

UNION ALL

SELECT 'veiculos', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM veiculos

UNION ALL

SELECT 'visitantes', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM visitantes

UNION ALL

SELECT 'registros_acesso', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM registros_acesso

UNION ALL

SELECT 'contas_pagar', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM contas_pagar

UNION ALL

SELECT 'contas_receber', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM contas_receber

UNION ALL

SELECT 'os_chamados', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM os_chamados

UNION ALL

SELECT 'hidrometros', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM hidrometros

UNION ALL

SELECT 'leituras', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM leituras

UNION ALL

SELECT 'contratos', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM contratos

UNION ALL

SELECT 'usuarios', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM usuarios

UNION ALL

SELECT 'rh_colaboradores', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM rh_colaboradores

UNION ALL

SELECT 'produtos_estoque', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM produtos_estoque

UNION ALL

SELECT 'documentos', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM documentos

UNION ALL

SELECT 'notificacoes', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM notificacoes

UNION ALL

SELECT 'protocolos', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM protocolos

UNION ALL

SELECT 'fornecedores', COUNT(*), COUNT(DISTINCT tenant_id), GROUP_CONCAT(DISTINCT tenant_id ORDER BY tenant_id)
FROM fornecedores

ORDER BY tabela;

-- ─── 3. VERIFICAR TABELAS SEM tenant_id (DEVEM SER GLOBAIS) ──────────────
SELECT 
    '=== TABELAS GLOBAIS (sem tenant_id) ===' AS verificacao;

SELECT TABLE_NAME AS tabela_global
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME NOT IN (
    SELECT TABLE_NAME 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND COLUMN_NAME = 'tenant_id'
  )
ORDER BY TABLE_NAME;

-- ─── 4. VERIFICAR USUARIO_TENANT ─────────────────────────────────────────
SELECT 
    '=== VÍNCULOS USUÁRIO × TENANT ===' AS verificacao;

SELECT 
    u.nome AS usuario,
    u.email,
    u.permissao,
    t.slug AS tenant_slug,
    t.nome_fantasia AS condominio,
    ut.permissao AS permissao_tenant,
    ut.ativo
FROM usuario_tenant ut
INNER JOIN usuarios u ON u.id = ut.usuario_id
INNER JOIN tenants t ON t.id = ut.tenant_id
ORDER BY u.nome, t.slug;

-- ─── 5. TESTE DE ISOLAMENTO (simula acesso de 2 tenants) ─────────────────
SELECT 
    '=== TESTE DE ISOLAMENTO (tenant_id = 1) ===' AS verificacao;

SELECT COUNT(*) AS moradores_tenant_1 FROM moradores WHERE tenant_id = 1;
SELECT COUNT(*) AS veiculos_tenant_1  FROM veiculos  WHERE tenant_id = 1;
SELECT COUNT(*) AS os_tenant_1        FROM os_chamados WHERE tenant_id = 1;

-- ─── 6. RESUMO GERAL ─────────────────────────────────────────────────────
SELECT 
    '=== RESUMO GERAL ===' AS verificacao;

SELECT
    (SELECT COUNT(*) FROM tenants WHERE status = 'ativo') AS tenants_ativos,
    (SELECT COUNT(*) FROM usuario_tenant WHERE ativo = 1) AS vinculos_usuario_tenant,
    (SELECT COUNT(*) FROM moradores WHERE tenant_id IS NOT NULL) AS moradores_com_tenant,
    (SELECT COUNT(*) FROM moradores WHERE tenant_id IS NULL) AS moradores_sem_tenant,
    (SELECT COUNT(*) FROM veiculos WHERE tenant_id IS NOT NULL) AS veiculos_com_tenant,
    (SELECT COUNT(*) FROM os_chamados WHERE tenant_id IS NOT NULL) AS os_com_tenant;
