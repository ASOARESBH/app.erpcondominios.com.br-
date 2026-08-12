-- ============================================================================
-- PRÉ-VALIDAÇÃO — CONSOLIDAÇÃO ERP SERRA -> ERP CONDOR
-- Somente leitura. Compatível com MySQL/MariaDB 5.7.
-- Execute antes da migration principal.
-- ============================================================================

SELECT DATABASE() AS banco_contexto_atual,
       'inlaud99_erpserra' AS banco_origem_esperado,
       'inlaud99_erpcondor' AS banco_destino_esperado;

SELECT SCHEMA_NAME AS banco_encontrado
FROM INFORMATION_SCHEMA.SCHEMATA
WHERE BINARY SCHEMA_NAME IN (BINARY 'inlaud99_erpserra', BINARY 'inlaud99_erpcondor')
ORDER BY SCHEMA_NAME;

SELECT id, slug, status, nome_fantasia, razao_social, cnpj
FROM `inlaud99_erpcondor`.`tenants`
ORDER BY id;

SELECT
  (SELECT COUNT(*) FROM `inlaud99_erpserra`.`empresa`) AS empresas_origem,
  (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`empresa` WHERE tenant_id=1) AS empresas_destino_tenant_1,
  (SELECT COUNT(*) FROM `inlaud99_erpserra`.`usuarios`) AS usuarios_origem,
  (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`usuarios` WHERE tenant_id=1) AS usuarios_destino_tenant_1,
  (SELECT COUNT(*) FROM `inlaud99_erpserra`.`moradores`) AS moradores_origem,
  (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`moradores` WHERE tenant_id=1) AS moradores_destino_tenant_1,
  (SELECT COUNT(*) FROM `inlaud99_erpserra`.`unidades`) AS unidades_origem,
  (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`unidades` WHERE tenant_id=1) AS unidades_destino_tenant_1;

SELECT t.TABLE_NAME, t.TABLE_ROWS AS linhas_estimadas_origem,
       CASE WHEN d.TABLE_NAME IS NULL THEN 'AUSENTE_NO_DESTINO' ELSE 'COMPATIVEL' END AS situacao
FROM INFORMATION_SCHEMA.TABLES t
LEFT JOIN INFORMATION_SCHEMA.TABLES d
  ON BINARY d.TABLE_SCHEMA = BINARY 'inlaud99_erpcondor'
 AND BINARY d.TABLE_NAME = BINARY t.TABLE_NAME
WHERE BINARY t.TABLE_SCHEMA = BINARY 'inlaud99_erpserra'
  AND t.TABLE_TYPE = 'BASE TABLE'
ORDER BY situacao DESC, t.TABLE_NAME;

SELECT 'APROVADO PARA MIGRACAO SOMENTE SE:' AS instrucao,
       'ambos os bancos existem, tenant 1 existe e não há outros tenants no destino' AS criterio;
