-- ============================================================================
-- AUDITORIA DE ARQUIVOS MULTI-TENANT — SOMENTE LEITURA
-- Banco alvo: inlaud99_erpcondor | MySQL/MariaDB 5.7
-- ============================================================================

SELECT
  ta.tenant_id,
  ta.tipo,
  COUNT(*) AS arquivos,
  ROUND(SUM(ta.tamanho_bytes) / 1024 / 1024, 2) AS total_mb,
  SUM(CASE WHEN ta.publico = 1 THEN 1 ELSE 0 END) AS arquivos_publicos,
  SUM(CASE WHEN ta.ativo = 1 THEN 1 ELSE 0 END) AS arquivos_ativos
FROM `inlaud99_erpcondor`.`tenant_arquivos` ta
GROUP BY ta.tenant_id, ta.tipo
ORDER BY ta.tenant_id, ta.tipo;

SELECT
  ml.tenant_id,
  ml.status,
  COUNT(*) AS total,
  MAX(ml.executado_em) AS ultima_execucao
FROM `inlaud99_erpcondor`.`tenant_arquivos_migracao_log` ml
GROUP BY ml.tenant_id, ml.status
ORDER BY ml.tenant_id, ml.status;

SELECT
  ml.tenant_id,
  ml.caminho_legado,
  ml.mensagem,
  ml.executado_em
FROM `inlaud99_erpcondor`.`tenant_arquivos_migracao_log` ml
WHERE ml.status = 'erro'
ORDER BY ml.executado_em DESC, ml.id DESC
LIMIT 100;

SELECT
  ta.id,
  ta.tenant_id,
  ta.tipo,
  ta.nome_original,
  ta.mime_type,
  ta.tamanho_bytes,
  ta.caminho_legado,
  ta.criado_em
FROM `inlaud99_erpcondor`.`tenant_arquivos` ta
ORDER BY ta.id DESC
LIMIT 100;
