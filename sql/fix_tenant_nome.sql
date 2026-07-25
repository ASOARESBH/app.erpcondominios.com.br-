-- =========================================================================
-- FIX: Atualizar nome do tenant para ERP Condomínio
-- =========================================================================
-- O tenant id=1 ainda tem o nome "ASSOCIAÇÃO SERRA DA LIBERDADE"
-- Este script atualiza para o branding correto do ERP Condomínio
-- =========================================================================

-- Verificar o estado atual
SELECT id, slug, razao_social, nome_fantasia, status FROM tenants WHERE id = 1;

-- Atualizar o nome do tenant principal
UPDATE tenants SET
    nome_fantasia = 'ERP Condomínio — Demo',
    slug = 'erpcondominios'
WHERE id = 1 AND (nome_fantasia IS NULL OR nome_fantasia = '' OR nome_fantasia LIKE '%Serra%' OR nome_fantasia LIKE '%SERRA%');

-- Confirmar resultado
SELECT id, slug, razao_social, nome_fantasia, status FROM tenants WHERE id = 1;

