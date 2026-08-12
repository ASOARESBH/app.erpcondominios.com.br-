-- ============================================================================
-- MIGRATION ANTERIOR DESATIVADA POR SEGURANÇA
-- ============================================================================
-- Esta versão foi substituída porque INSERT ... ON DUPLICATE KEY UPDATE não
-- protege tabelas que não possuem PRIMARY KEY ou UNIQUE KEY operacional.
-- Sua execução pode duplicar registros no tenant 1.
--
-- NÃO USE ESTE ARQUIVO PARA IMPORTAR DADOS.
--
-- Fluxo correto:
-- 1. previa_reconstrucao_tenant1_mysql57.sql      (somente leitura)
-- 2. Exportar backup novo de inlaud99_erpcondor
-- 3. reconstruir_tenant1_com_origem_erpserra_mysql57.sql
-- 4. auditoria_pos_reconstrucao_tenant1_mysql57.sql
-- ============================================================================

SELECT
  'MIGRATION BLOQUEADA POR SEGURANÇA' AS status,
  'Use reconstruir_tenant1_com_origem_erpserra_mysql57.sql após executar a prévia e gerar backup.' AS instrucao;
