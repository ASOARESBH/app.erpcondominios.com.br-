-- ERP CONDOMÍNIOS MONITORING — FASE 1
-- Domínio de agentes locais, LPR e ingestão idempotente.
-- Compatível com MySQL/MariaDB 5.7.
-- Executar somente após backup e validação do schema atual.
-- Não contém credenciais.

SET NAMES utf8mb4;
SET SQL_MODE = '';

CREATE TABLE IF NOT EXISTS `monitoramento_configuracoes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `modulo_ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `retencao_dias` INT(11) NOT NULL DEFAULT 30,
  `lpr_engine` VARCHAR(30) NOT NULL DEFAULT 'fastalpr',
  `onnx_backend` VARCHAR(30) NOT NULL DEFAULT 'cpu',
  `confidence_min` DECIMAL(5,4) NOT NULL DEFAULT 0.8000,
  `dedup_seconds` INT(11) NOT NULL DEFAULT 20,
  `versao_minima_agente` VARCHAR(30) DEFAULT '0.1.0',
  `config_version` INT(11) NOT NULL DEFAULT 1,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_monitoramento_config_tenant` (`tenant_id`),
  KEY `idx_monitoramento_config_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitoramento_agentes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) DEFAULT NULL,
  `install_id` CHAR(36) NOT NULL,
  `nome` VARCHAR(120) DEFAULT NULL,
  `local` VARCHAR(160) DEFAULT NULL,
  `responsavel` VARCHAR(160) DEFAULT NULL,
  `observacao` TEXT DEFAULT NULL,
  `hardware_fingerprint_hash` CHAR(64) NOT NULL,
  `pairing_code_hash` VARCHAR(255) DEFAULT NULL,
  `pairing_code_preview` VARCHAR(12) DEFAULT NULL,
  `pairing_expires_at` DATETIME DEFAULT NULL,
  `agent_secret_hash` VARCHAR(255) DEFAULT NULL,
  `agent_secret_last4` CHAR(4) DEFAULT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'solicitado',
  `agent_version` VARCHAR(30) DEFAULT NULL,
  `api_contract_version` VARCHAR(20) DEFAULT NULL,
  `lpr_engine` VARCHAR(40) DEFAULT NULL,
  `lpr_engine_version` VARCHAR(40) DEFAULT NULL,
  `onnx_backend` VARCHAR(30) DEFAULT NULL,
  `last_heartbeat_at` DATETIME DEFAULT NULL,
  `last_ip` VARCHAR(45) DEFAULT NULL,
  `last_error_code` VARCHAR(60) DEFAULT NULL,
  `blocked_reason` VARCHAR(255) DEFAULT NULL,
  `activated_at` DATETIME DEFAULT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_monitoramento_agent_install` (`tenant_id`, `install_id`),
  UNIQUE KEY `uk_monitoramento_install_global` (`install_id`),
  KEY `idx_monitoramento_agent_tenant_status` (`tenant_id`, `status`),
  KEY `idx_monitoramento_agent_heartbeat` (`tenant_id`, `last_heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitoramento_sessoes` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `agente_id` INT(11) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME DEFAULT NULL,
  `last_seen_at` DATETIME DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_monitoramento_session_token` (`token_hash`),
  KEY `idx_monitoramento_session_agent` (`agente_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitoramento_cameras` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `agente_id` INT(11) NOT NULL,
  `external_key` VARCHAR(80) NOT NULL,
  `nome` VARCHAR(120) NOT NULL,
  `fabricante` VARCHAR(60) DEFAULT NULL,
  `modelo` VARCHAR(120) DEFAULT NULL,
  `origem_stream` VARCHAR(30) NOT NULL DEFAULT 'camera_rtsp',
  `canal` VARCHAR(30) DEFAULT NULL,
  `sentido` VARCHAR(20) NOT NULL DEFAULT 'indeterminado',
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `config_version` INT(11) NOT NULL DEFAULT 1,
  `ultimo_status` VARCHAR(20) DEFAULT 'offline',
  `ultimo_frame_at` DATETIME DEFAULT NULL,
  `frames_perdidos` INT(11) NOT NULL DEFAULT 0,
  `ultimo_erro_code` VARCHAR(60) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_monitoramento_camera_agent_key` (`agente_id`, `external_key`),
  KEY `idx_monitoramento_camera_tenant` (`tenant_id`, `ativo`),
  KEY `idx_monitoramento_camera_agent` (`agente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitoramento_eventos` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `agente_id` INT(11) NOT NULL,
  `camera_id` INT(11) DEFAULT NULL,
  `camera_external_key` VARCHAR(80) DEFAULT NULL,
  `event_uuid` CHAR(36) NOT NULL,
  `capturado_em` DATETIME NOT NULL,
  `placa_raw` VARCHAR(32) NOT NULL,
  `placa_normalizada` VARCHAR(16) NOT NULL,
  `placa_corrigida` VARCHAR(16) DEFAULT NULL,
  `detection_confidence` DECIMAL(5,4) DEFAULT NULL,
  `ocr_confidence` DECIMAL(5,4) DEFAULT NULL,
  `direcao` VARCHAR(20) NOT NULL DEFAULT 'indeterminado',
  `tipo_evento` VARCHAR(30) NOT NULL DEFAULT 'lpr',
  `motor` VARCHAR(40) NOT NULL DEFAULT 'fastalpr',
  `motor_versao` VARCHAR(40) DEFAULT NULL,
  `match_status` VARCHAR(40) NOT NULL DEFAULT 'inconclusivo',
  `veiculo_id` INT(11) DEFAULT NULL,
  `morador_id` INT(11) DEFAULT NULL,
  `snapshot_ref` VARCHAR(500) DEFAULT NULL,
  `snapshot_sha256` CHAR(64) DEFAULT NULL,
  `status_evento` VARCHAR(30) NOT NULL DEFAULT 'capturado',
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_monitoramento_event_tenant_uuid` (`tenant_id`, `agente_id`, `event_uuid`),
  KEY `idx_monitoramento_event_tenant_time` (`tenant_id`, `capturado_em`),
  KEY `idx_monitoramento_event_plate_time` (`tenant_id`, `placa_normalizada`, `capturado_em`),
  KEY `idx_monitoramento_event_camera_time` (`tenant_id`, `camera_id`, `capturado_em`),
  KEY `idx_monitoramento_event_status` (`tenant_id`, `status_evento`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitoramento_evento_revisoes` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `evento_id` BIGINT(20) NOT NULL,
  `usuario_id` INT(11) DEFAULT NULL,
  `campo` VARCHAR(60) NOT NULL,
  `valor_anterior` TEXT DEFAULT NULL,
  `valor_novo` TEXT DEFAULT NULL,
  `motivo` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_monitoramento_revision_event` (`tenant_id`, `evento_id`, `criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitoramento_evento_acesso` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `evento_id` BIGINT(20) NOT NULL,
  `registro_acesso_id` INT(11) NOT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_monitoramento_access_event` (`tenant_id`, `evento_id`),
  UNIQUE KEY `uk_monitoramento_access_record` (`tenant_id`, `registro_acesso_id`),
  KEY `idx_monitoramento_access_tenant` (`tenant_id`, `criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuração inicial por tenant já existente. A cláusula evita duplicidade.
INSERT INTO `monitoramento_configuracoes` (`tenant_id`, `modulo_ativo`, `retencao_dias`)
SELECT t.id, 1, 30
FROM `tenants` t
LEFT JOIN `monitoramento_configuracoes` c ON c.tenant_id = t.id
WHERE c.id IS NULL;

-- Verificação rápida pós-migration.
SELECT
  (SELECT COUNT(*) FROM `monitoramento_configuracoes`) AS configuracoes_monitoramento,
  (SELECT COUNT(*) FROM `monitoramento_agentes`) AS agentes_monitoramento,
  (SELECT COUNT(*) FROM `monitoramento_eventos`) AS eventos_monitoramento;
