-- ERP CONDOMÍNIOS MONITORING — hub local multimodal.
-- Compatível com MySQL/MariaDB 5.7.
-- Executar após backup. Esta migration é aditiva e não remove as tabelas LPR legadas.

SET NAMES utf8mb4;
SET SQL_MODE = '';

CREATE TABLE IF NOT EXISTS `monitoramento_dispositivos_local` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `agente_id` INT(11) NOT NULL,
  `external_key` VARCHAR(80) NOT NULL,
  `nome` VARCHAR(120) NOT NULL,
  `tipo_dispositivo` VARCHAR(40) NOT NULL DEFAULT 'outro',
  `protocolo` VARCHAR(40) NOT NULL DEFAULT 'http',
  `fabricante` VARCHAR(80) DEFAULT NULL,
  `modelo` VARCHAR(120) DEFAULT NULL,
  `host_configurado` TINYINT(1) NOT NULL DEFAULT 0,
  `porta` INT(11) DEFAULT NULL,
  `sentido` VARCHAR(20) NOT NULL DEFAULT 'indeterminado',
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `ultimo_status` VARCHAR(30) NOT NULL DEFAULT 'aguardando',
  `ultimo_heartbeat_at` DATETIME DEFAULT NULL,
  `ultimo_evento_at` DATETIME DEFAULT NULL,
  `ultimo_erro_code` VARCHAR(80) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_monitoramento_local_device` (`tenant_id`, `agente_id`, `external_key`),
  KEY `idx_monitoramento_local_device_tenant` (`tenant_id`, `ativo`),
  KEY `idx_monitoramento_local_device_status` (`tenant_id`, `ultimo_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `monitoramento_eventos_hub` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `agente_id` INT(11) NOT NULL,
  `dispositivo_id` INT(11) DEFAULT NULL,
  `device_external_key` VARCHAR(80) NOT NULL,
  `event_uuid` CHAR(36) NOT NULL,
  `capturado_em` DATETIME NOT NULL,
  `source_type` VARCHAR(30) NOT NULL DEFAULT 'outro',
  `source_protocol` VARCHAR(50) NOT NULL DEFAULT 'unknown',
  `direcao` VARCHAR(20) NOT NULL DEFAULT 'indeterminado',
  `decisao` VARCHAR(30) NOT NULL DEFAULT 'unknown',
  `identifier_type` VARCHAR(30) DEFAULT NULL,
  `identifier_value` VARCHAR(128) DEFAULT NULL,
  `identifier_hash` CHAR(64) DEFAULT NULL,
  `subject_external_id` VARCHAR(128) DEFAULT NULL,
  `confianca` DECIMAL(5,4) DEFAULT NULL,
  `metadata_json` JSON DEFAULT NULL,
  `placa_raw` VARCHAR(32) DEFAULT NULL,
  `placa_normalizada` VARCHAR(16) DEFAULT NULL,
  `engine` VARCHAR(50) DEFAULT NULL,
  `engine_version` VARCHAR(50) DEFAULT NULL,
  `snapshot_sha256` CHAR(64) DEFAULT NULL,
  `status_evento` VARCHAR(30) NOT NULL DEFAULT 'confirmado',
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_monitoramento_hub_event_tenant_uuid` (`tenant_id`, `agente_id`, `event_uuid`),
  KEY `idx_monitoramento_hub_tenant_time` (`tenant_id`, `capturado_em`),
  KEY `idx_monitoramento_hub_source_time` (`tenant_id`, `source_type`, `capturado_em`),
  KEY `idx_monitoramento_hub_identifier` (`tenant_id`, `identifier_type`, `identifier_hash`),
  KEY `idx_monitoramento_hub_device_time` (`tenant_id`, `device_external_key`, `capturado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verificação pós-migration.
SELECT
  (SELECT COUNT(*) FROM `monitoramento_dispositivos_local`) AS dispositivos_hub,
  (SELECT COUNT(*) FROM `monitoramento_eventos_hub`) AS eventos_hub;
