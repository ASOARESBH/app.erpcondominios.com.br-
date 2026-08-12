-- ================================================================
-- CONFIGURAÇÃO FIREBASE CLOUD MESSAGING — ERP CONDOMÍNIOS
-- Projeto Firebase: erp-condominios
--
-- Pré-requisito: executar antes migration_notificacoes_encomendas_mobile.sql
-- (ela adiciona tenant_id e a chave única tenant_id + chave).
--
-- Este script é idempotente: pode ser executado novamente sem duplicar dados.
-- ================================================================

START TRANSACTION;

-- Vincula o projeto Firebase central a cada condomínio (tenant) que possui
-- moradores. O isolamento da entrega continua pelo tenant_id e morador_id.
INSERT INTO pwa_configuracoes (tenant_id, chave, valor, descricao)
SELECT DISTINCT
       moradores.tenant_id,
       'fcm_project_id',
       'erp-condominios',
       'Projeto Firebase central das notificações mobile'
FROM moradores
WHERE moradores.tenant_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    valor = VALUES(valor),
    descricao = VALUES(descricao),
    atualizado_em = NOW();

-- Habilita, por padrão, alertas de chegada e entrega de encomendas.
INSERT INTO pwa_configuracoes (tenant_id, chave, valor, descricao)
SELECT DISTINCT
       moradores.tenant_id,
       'push_encomenda_ativo',
       '1',
       'Exibir push quando uma encomenda chegar ou for entregue'
FROM moradores
WHERE moradores.tenant_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    valor = VALUES(valor),
    descricao = VALUES(descricao),
    atualizado_em = NOW();

COMMIT;

-- VERIFICAÇÃO:
-- SELECT tenant_id, chave, valor
-- FROM pwa_configuracoes
-- WHERE chave IN ('fcm_project_id', 'push_encomenda_ativo')
-- ORDER BY tenant_id, chave;
