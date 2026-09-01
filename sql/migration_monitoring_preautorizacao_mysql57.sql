-- Pré-autorização segura do pareamento do ERP CONDOMÍNIOS MONITORING.
-- Compatível com MySQL/MariaDB 5.7. Executar após backup.
SET NAMES utf8mb4;
SET SQL_MODE = '';

CREATE TABLE IF NOT EXISTS `monitoramento_pareamento_autorizacoes` (
  `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
  `usuario_id` INT(11) NOT NULL,
  `tenant_id` INT(11) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `install_id` VARCHAR(64) NOT NULL,
  `hardware_fingerprint_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_monitoring_pairing_auth_token` (`token_hash`),
  KEY `idx_monitoring_pairing_auth_expiry` (`expires_at`, `used_at`),
  KEY `idx_monitoring_pairing_auth_install` (`install_id`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX `idx_monitoramento_agentes_install_status`
  ON `monitoramento_agentes` (`install_id`, `status`);

SELECT COUNT(*) AS autorizacoes_pareamento
  FROM `monitoramento_pareamento_autorizacoes`;

INSERT INTO `monitoramento_configuracoes`
  (`tenant_id`, `versao_minima_agente`, `modulo_ativo`)
SELECT `id`, '0.2.1', 1
  FROM `tenants`
 WHERE `status` = 'ativo'
   AND NOT EXISTS (
     SELECT 1 FROM `monitoramento_configuracoes` mc
      WHERE mc.`tenant_id` = `tenants`.`id`
   );
