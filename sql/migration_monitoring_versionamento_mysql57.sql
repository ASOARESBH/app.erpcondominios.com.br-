-- ERP CONDOMÍNIOS — VERSIONAMENTO DO AGENTE WINDOWS MONITORING
-- Escopo global, administrado exclusivamente pelo Super-Admin.
-- Execute após backup do banco.

CREATE TABLE IF NOT EXISTS `monitoring_releases` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `version_name` VARCHAR(40) NOT NULL,
  `version_code` INT(11) NOT NULL,
  `channel` ENUM('interno','teste','producao') NOT NULL DEFAULT 'interno',
  `status` ENUM('rascunho','publicado','arquivado') NOT NULL DEFAULT 'rascunho',
  `download_url` VARCHAR(700) NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `size_bytes` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `mandatory` TINYINT(1) NOT NULL DEFAULT 0,
  `minimum_version_code` INT(11) NOT NULL DEFAULT 0,
  `release_notes` TEXT DEFAULT NULL,
  `published_at` DATETIME DEFAULT NULL,
  `created_by_user_id` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_monitoring_release_code_channel` (`version_code`, `channel`),
  KEY `idx_monitoring_release_status_channel` (`status`, `channel`, `version_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Releases assinadas do agente Windows ERP Monitoring';

CREATE TABLE IF NOT EXISTS `monitoring_update_log` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `release_id` INT(11) DEFAULT NULL,
  `agent_id` INT(11) DEFAULT NULL,
  `current_version_code` INT(11) DEFAULT NULL,
  `action` VARCHAR(40) NOT NULL,
  `detail` VARCHAR(500) DEFAULT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_monitoring_update_log_release` (`release_id`, `created_at`),
  KEY `idx_monitoring_update_log_agent` (`agent_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auditoria de consultas e atualizações do agente Monitoring';

INSERT INTO `monitoring_releases`
  (`version_name`, `version_code`, `channel`, `status`, `download_url`, `sha256`, `size_bytes`, `mandatory`, `release_notes`)
SELECT '0.2.1', 201, 'interno', 'rascunho', '', REPEAT('0', 64), 0, 0,
       'Release inicial do agente Windows Monitoring. Preencha a URL HTTPS e o SHA-256 antes de publicar.'
WHERE NOT EXISTS (SELECT 1 FROM `monitoring_releases` WHERE `version_code` = 201 AND `channel` = 'interno');

-- FIM

/* Contrato do manifesto para o agente:
GET /api/api_monitoring_update.php?action=manifest
Authorization: Bearer <token do agente>
*/

-- O agente só deve instalar um pacote após validar HTTPS, SHA-256,
-- version_code maior que a versão atual e status publicado.
