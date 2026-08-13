-- =====================================================================
-- MIGRAÇÃO V2 — Notificações de Veículos e Controle de Acesso
-- MariaDB/MySQL 5.7. Incremental, idempotente e compatível com base legada.
-- Objetivo: permitir notificações que não possuem protocolo_id.
-- Faça backup antes de importar no phpMyAdmin.
-- =====================================================================

-- 1. Cria a referência de veículo caso uma instalação anterior não a possua.
SET @tem_veiculo := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notificacoes_morador'
    AND COLUMN_NAME = 'veiculo_id'
);
SET @sql := IF(
  @tem_veiculo = 0,
  'ALTER TABLE notificacoes_morador ADD COLUMN veiculo_id INT(11) NULL AFTER protocolo_id',
  'SELECT 1'
);
PREPARE stmt_add_veiculo FROM @sql;
EXECUTE stmt_add_veiculo;
DEALLOCATE PREPARE stmt_add_veiculo;

-- 2. A tabela foi originalmente criada para protocolos; protocolo_id NÃO pode
--    permanecer obrigatório, pois veículos e acessos não pertencem a protocolo.
SET @protocolo_permite_nulo := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notificacoes_morador'
    AND COLUMN_NAME = 'protocolo_id'
  LIMIT 1
);
SET @sql := IF(
  @protocolo_permite_nulo = 'NO',
  'ALTER TABLE notificacoes_morador MODIFY COLUMN protocolo_id INT(11) NULL DEFAULT NULL',
  'SELECT 1'
);
PREPARE stmt_protocol_nulo FROM @sql;
EXECUTE stmt_protocol_nulo;
DEALLOCATE PREPARE stmt_protocol_nulo;

-- 3. Garante a referência ao registro de acesso para instalações que ainda não
--    receberam a migração anterior.
SET @tem_registro := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notificacoes_morador'
    AND COLUMN_NAME = 'registro_acesso_id'
);
SET @sql := IF(
  @tem_registro = 0,
  'ALTER TABLE notificacoes_morador ADD COLUMN registro_acesso_id INT(11) NULL AFTER veiculo_id',
  'SELECT 1'
);
PREPARE stmt_add_registro FROM @sql;
EXECUTE stmt_add_registro;
DEALLOCATE PREPARE stmt_add_registro;

-- 4. Deduplicação de veículo: uma notificação por veículo/morador/tipo.
SET @tem_indice_veiculo := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notificacoes_morador'
    AND INDEX_NAME = 'uq_notif_morador_veiculo_evento'
);
SET @sql := IF(
  @tem_indice_veiculo = 0,
  'ALTER TABLE notificacoes_morador ADD UNIQUE KEY uq_notif_morador_veiculo_evento (tenant_id, morador_id, veiculo_id, tipo)',
  'SELECT 1'
);
PREPARE stmt_indice_veiculo FROM @sql;
EXECUTE stmt_indice_veiculo;
DEALLOCATE PREPARE stmt_indice_veiculo;

-- 5. Índice de consulta dos eventos de acesso, se ausente.
SET @tem_indice_registro := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'notificacoes_morador'
    AND INDEX_NAME = 'idx_notif_registro_acesso'
);
SET @sql := IF(
  @tem_indice_registro = 0,
  'ALTER TABLE notificacoes_morador ADD KEY idx_notif_registro_acesso (tenant_id, registro_acesso_id)',
  'SELECT 1'
);
PREPARE stmt_indice_registro FROM @sql;
EXECUTE stmt_indice_registro;
DEALLOCATE PREPARE stmt_indice_registro;

-- 6. Evidência final esperada: protocolo_id com Null=YES e índice de veículo.
SHOW COLUMNS FROM notificacoes_morador;
SHOW INDEX FROM notificacoes_morador;
