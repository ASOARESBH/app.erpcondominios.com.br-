-- ============================================================================
-- ERP CONDOMÍNIOS — MÓDULO VIGILANTE / RONDAS COM QR CODE
-- MySQL / MariaDB 5.7 — Multi-Tenant
-- ============================================================================
-- Execute no banco inlaud99_erpcondor após realizar backup.
-- Todas as tabelas operacionais possuem tenant_id e devem ser acessadas
-- exclusivamente com o tenant ativo da sessão, exceto a validação pública do
-- token QR, que resolve o tenant pelo próprio token seguro do ponto.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `ronda_rotas` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `nome` VARCHAR(120) NOT NULL,
    `descricao` VARCHAR(500) DEFAULT NULL,
    `hora_inicio` TIME NOT NULL DEFAULT '00:00:00',
    `hora_fim` TIME DEFAULT NULL,
    `intervalo_minutos` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    `repeticoes_por_dia` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `tolerancia_minutos` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    `dias_semana` VARCHAR(32) NOT NULL DEFAULT '0,1,2,3,4,5,6',
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `criado_por_usuario_id` INT DEFAULT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ronda_tenant_ativo` (`tenant_id`, `ativo`),
    KEY `idx_ronda_tenant_dias` (`tenant_id`, `dias_semana`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ronda_pontos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `rota_id` INT UNSIGNED NOT NULL,
    `nome` VARCHAR(120) NOT NULL,
    `localizacao` VARCHAR(255) DEFAULT NULL,
    `instrucoes` VARCHAR(500) DEFAULT NULL,
    `ordem` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `token_qr` CHAR(64) NOT NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ronda_ponto_token` (`token_qr`),
    UNIQUE KEY `uk_ronda_ponto_ordem` (`tenant_id`, `rota_id`, `ordem`),
    KEY `idx_ronda_ponto_rota` (`tenant_id`, `rota_id`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ronda_vigilantes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `rota_id` INT UNSIGNED NOT NULL,
    `colaborador_id` INT NOT NULL,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `vinculado_por_usuario_id` INT DEFAULT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ronda_vigilante` (`tenant_id`, `rota_id`, `colaborador_id`),
    KEY `idx_ronda_vigilante_colaborador` (`tenant_id`, `colaborador_id`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ronda_registros` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `rota_id` INT UNSIGNED NOT NULL,
    `ponto_id` INT UNSIGNED NOT NULL,
    `colaborador_id` INT NOT NULL,
    `ciclo_chave` CHAR(64) NOT NULL,
    `previsto_em` DATETIME DEFAULT NULL,
    `registrado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status_sla` ENUM('no_prazo','atrasado','manual') NOT NULL DEFAULT 'no_prazo',
    `atraso_minutos` INT UNSIGNED NOT NULL DEFAULT 0,
    `latitude` DECIMAL(10,7) DEFAULT NULL,
    `longitude` DECIMAL(10,7) DEFAULT NULL,
    `precisao_metros` DECIMAL(10,2) DEFAULT NULL,
    `ip` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ronda_registro_ponto_ciclo` (`tenant_id`, `ponto_id`, `colaborador_id`, `ciclo_chave`),
    KEY `idx_ronda_registro_rota_data` (`tenant_id`, `rota_id`, `registrado_em`),
    KEY `idx_ronda_registro_vigilante_data` (`tenant_id`, `colaborador_id`, `registrado_em`),
    KEY `idx_ronda_registro_sla` (`tenant_id`, `status_sla`, `registrado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ronda_auditoria` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `rota_id` INT UNSIGNED DEFAULT NULL,
    `usuario_id` INT DEFAULT NULL,
    `acao` VARCHAR(80) NOT NULL,
    `descricao` VARCHAR(500) NOT NULL,
    `dados_json` TEXT DEFAULT NULL,
    `ip` VARCHAR(45) DEFAULT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ronda_auditoria_tenant_data` (`tenant_id`, `criado_em`),
    KEY `idx_ronda_auditoria_rota` (`tenant_id`, `rota_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auditoria inicial: a saída deve ser 0 até que rotas e pontos sejam criados.
SELECT
    (SELECT COUNT(*) FROM ronda_rotas) AS total_rotas,
    (SELECT COUNT(*) FROM ronda_pontos) AS total_pontos_qr,
    (SELECT COUNT(*) FROM ronda_vigilantes WHERE ativo=1) AS total_vigilantes_vinculados,
    (SELECT COUNT(*) FROM ronda_registros WHERE DATE(registrado_em)=CURDATE()) AS leituras_hoje;
-- ============================================================================
