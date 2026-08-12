-- ============================================================================
-- ERP CONDOMÍNIOS — APLICATIVOS E VERSIONAMENTO APK
-- MySQL / MariaDB 5.7 — Escopo GLOBAL, restrito ao Super-Admin
-- Banco alvo: inlaud99_erpcondor
-- ============================================================================
-- Execute após realizar backup do banco pelo phpMyAdmin.
-- Esta migration não altera dados de tenants nem tabelas operacionais.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `aplicativos_catalogo` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `chave` VARCHAR(60) NOT NULL COMMENT 'Identificador interno estável do aplicativo',
  `nome` VARCHAR(150) NOT NULL,
  `plataforma` ENUM('android','ios','web') NOT NULL DEFAULT 'android',
  `package_name` VARCHAR(180) DEFAULT NULL COMMENT 'Ex.: br.com.erpcondominios.app',
  `google_play_url` VARCHAR(500) DEFAULT NULL,
  `google_play_package` VARCHAR(180) DEFAULT NULL,
  `descricao` TEXT DEFAULT NULL,
  `status` ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `criado_por_usuario_id` INT(11) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_aplicativos_catalogo_chave` (`chave`),
  KEY `idx_aplicativos_catalogo_status` (`status`),
  KEY `idx_aplicativos_catalogo_plataforma` (`plataforma`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo global de aplicativos administrados pelo Super-Admin';

CREATE TABLE IF NOT EXISTS `aplicativos_versoes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `aplicativo_id` INT(11) NOT NULL,
  `versao_nome` VARCHAR(40) NOT NULL COMMENT 'Versão exibida, ex.: 1.4.0',
  `version_code` INT(11) NOT NULL COMMENT 'Código Android incremental, ex.: 140',
  `canal` ENUM('interno','teste','producao') NOT NULL DEFAULT 'interno',
  `status` ENUM('rascunho','publicado','arquivado') NOT NULL DEFAULT 'rascunho',
  `distribuicao` ENUM('apk_direto','google_play','ambos') NOT NULL DEFAULT 'apk_direto',
  `url_download_apk` VARCHAR(700) DEFAULT NULL COMMENT 'URL HTTPS temporária ou definitiva do APK',
  `tamanho_bytes` BIGINT(20) UNSIGNED DEFAULT NULL,
  `sha256` CHAR(64) DEFAULT NULL COMMENT 'Hash SHA-256 do APK para auditoria',
  `min_sdk` VARCHAR(20) DEFAULT NULL,
  `target_sdk` VARCHAR(20) DEFAULT NULL,
  `obrigatoria` TINYINT(1) NOT NULL DEFAULT 0,
  `notas_liberacao` TEXT DEFAULT NULL,
  `google_play_track` VARCHAR(40) DEFAULT NULL COMMENT 'internal, alpha, beta ou production',
  `google_play_release_id` VARCHAR(120) DEFAULT NULL COMMENT 'Reservado para integração futura com Google Play Developer API',
  `publicado_em` DATETIME DEFAULT NULL,
  `criado_por_usuario_id` INT(11) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_aplicativo_version_code` (`aplicativo_id`, `version_code`),
  KEY `idx_aplicativos_versoes_lista` (`aplicativo_id`, `status`, `canal`, `criado_em`),
  CONSTRAINT `fk_aplicativos_versoes_aplicativo`
    FOREIGN KEY (`aplicativo_id`) REFERENCES `aplicativos_catalogo` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico auditável de releases de aplicativos';

CREATE TABLE IF NOT EXISTS `aplicativos_versionamento_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `aplicativo_id` INT(11) DEFAULT NULL,
  `versao_id` INT(11) DEFAULT NULL,
  `usuario_id` INT(11) DEFAULT NULL,
  `acao` VARCHAR(60) NOT NULL,
  `descricao` VARCHAR(500) NOT NULL,
  `ip` VARCHAR(64) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aplicativos_log_aplicativo` (`aplicativo_id`, `criado_em`),
  KEY `idx_aplicativos_log_versao` (`versao_id`, `criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auditoria de ações administrativas sobre aplicativos e releases';

-- Registro inicial. O cadastro é idempotente e não sobrescreve dados preenchidos.
INSERT INTO `aplicativos_catalogo`
  (`chave`, `nome`, `plataforma`, `package_name`, `descricao`, `status`)
SELECT
  'erp_condominios_android',
  'ERP Condomínios Android',
  'android',
  NULL,
  'Aplicativo Android oficial do ERP Condomínios. Configure package, link Play Store e releases no Painel Super-Admin.',
  'ativo'
WHERE NOT EXISTS (
  SELECT 1 FROM `aplicativos_catalogo` WHERE `chave` = 'erp_condominios_android'
);

-- Auditoria somente leitura após a instalação:
-- SELECT a.nome, v.versao_nome, v.version_code, v.canal, v.status, v.publicado_em
-- FROM aplicativos_catalogo a
-- LEFT JOIN aplicativos_versoes v ON v.aplicativo_id = a.id
-- ORDER BY a.nome, v.version_code DESC;

-- ============================================================================
-- FIM — migration_aplicativos_versionamento_mysql57.sql
-- ============================================================================
