-- =====================================================================
-- VALIDAÇÃO PÓS-DEPLOY — ControlID -> Controle de Acesso -> FCM
-- MariaDB/MySQL 5.7. SOMENTE LEITURA. Não altera dados.
-- Execute após provocar UMA entrada liberada pela catraca para o morador 185.
-- =====================================================================

SET @tenant_id := 1;
SET @morador_id := 185;

-- 1. Último acesso automático (a observação deve conter Control ID e a
--    unidade_destino deve ser Gleba 133).
SELECT
    r.id AS registro_acesso_id,
    r.data_hora,
    r.placa,
    r.modelo,
    r.morador_id,
    r.unidade_destino,
    r.status,
    r.observacao
FROM registros_acesso r
WHERE r.morador_id = @morador_id
  AND LOWER(COALESCE(r.observacao, '')) LIKE '%control id%'
ORDER BY r.data_hora DESC, r.id DESC
LIMIT 5;

-- 2. Eventos persistentes que devem corresponder ao último acesso automático.
SELECT
    n.id AS notificacao_id,
    n.registro_acesso_id,
    n.tenant_id,
    n.morador_id,
    n.tipo,
    n.titulo,
    n.push_status,
    n.push_detalhe,
    n.criado_em
FROM notificacoes_morador n
WHERE n.tenant_id = @tenant_id
  AND n.morador_id = @morador_id
  AND n.tipo IN ('acesso_entrada', 'acesso_saida')
ORDER BY n.criado_em DESC, n.id DESC
LIMIT 10;

-- 3. Prova do vínculo 1:1 entre registro e evento (não pode retornar
--    duplicidade para a combinação tenant/morador/registro/tipo).
SELECT
    n.tenant_id,
    n.morador_id,
    n.registro_acesso_id,
    n.tipo,
    COUNT(*) AS quantidade
FROM notificacoes_morador n
WHERE n.tenant_id = @tenant_id
  AND n.morador_id = @morador_id
  AND n.tipo IN ('acesso_entrada', 'acesso_saida')
GROUP BY n.tenant_id, n.morador_id, n.registro_acesso_id, n.tipo
HAVING COUNT(*) > 1;

-- 4. Logs de auditoria no HostGator (via cPanel/FTP):
-- logs/access_notification.log
-- Procure, para o mesmo registro: destinatarios_resolvidos,
-- evento_persistido, tokens_encontrados, fcm_resultado e registro_processado.
