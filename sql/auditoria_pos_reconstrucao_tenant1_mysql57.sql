-- ============================================================================
-- AUDITORIA PÓS-RECONSTRUÇÃO DO TENANT 1
-- Todas as tabelas são qualificadas para não depender do contexto do phpMyAdmin.
-- Somente leitura. Compatível com MySQL/MariaDB 5.7.
-- ============================================================================

SELECT 'inlaud99_erpcondor' AS banco_auditado,
       (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`tenants` WHERE id=1) AS tenant_1_encontrado,
       (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`mt_reconstrucao_tabelas` WHERE tenant_id=1 AND status='concluida') AS tabelas_reconstruidas;

SELECT tabela, registros_origem, registros_removidos, registros_importados, status, mensagem, executado_em
FROM `inlaud99_erpcondor`.`mt_reconstrucao_tabelas`
WHERE tenant_id=1
ORDER BY tabela;

SELECT t.id AS tenant_id, t.slug, t.nome_fantasia AS nome_tenant,
       e.nome_fantasia AS nome_empresa, t.logo_url AS logo_tenant, e.logo_url AS logo_empresa,
       CASE
           WHEN e.id IS NULL THEN 'SEM_EMPRESA_OPERACIONAL'
           WHEN IFNULL(CHAR_LENGTH(t.logo_url),0)=0 THEN 'SEM_LOGO_TENANT'
           WHEN IFNULL(CHAR_LENGTH(e.logo_url),0)=0 THEN 'LOGO_SOMENTE_TENANT'
           WHEN BINARY t.logo_url <> BINARY e.logo_url THEN 'LOGOS_DIVERGENTES'
           ELSE 'SINCRONIZADO'
       END AS situacao_branding
FROM `inlaud99_erpcondor`.`tenants` t
LEFT JOIN `inlaud99_erpcondor`.`empresa` e ON e.tenant_id=t.id
WHERE t.id=1;

SELECT
  (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`moradores` WHERE tenant_id=1) AS moradores_destino,
  (SELECT COUNT(*) FROM `inlaud99_erpserra`.`moradores`) AS moradores_origem,
  (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`unidades` WHERE tenant_id=1) AS unidades_destino,
  (SELECT COUNT(*) FROM `inlaud99_erpserra`.`unidades`) AS unidades_origem,
  (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`leituras` WHERE tenant_id=1) AS leituras_destino,
  (SELECT COUNT(*) FROM `inlaud99_erpserra`.`leituras`) AS leituras_origem,
  (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`protocolos` WHERE tenant_id=1) AS protocolos_destino,
  (SELECT COUNT(*) FROM `inlaud99_erpserra`.`protocolos`) AS protocolos_origem,
  (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`registros_acesso` WHERE tenant_id=1) AS acessos_destino,
  (SELECT COUNT(*) FROM `inlaud99_erpserra`.`registros_acesso`) AS acessos_origem;

SELECT tabela, motivo, registrado_em
FROM `inlaud99_erpcondor`.`mt_reconstrucao_exclusoes`
ORDER BY tabela;
