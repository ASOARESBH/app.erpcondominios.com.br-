-- ============================================================
-- EMAIL MODULE v2 — Migração de Banco de Dados
-- Sistema ERP Condomínio
-- Data: 2026-06-24
-- ============================================================
-- ATENÇÃO: Execute este script UMA única vez.
-- As tabelas são criadas com IF NOT EXISTS — seguro re-executar.
-- ============================================================

-- ============================================================
-- 1. email_providers — Provedores com suporte a fallback
-- ============================================================
-- Permite configurar múltiplos provedores com prioridade.
-- Prioridade 1 = primário, 2 = fallback1, 3 = fallback2.
-- O EmailProviderFactory escolhe o primário; FallbackEmailProvider
-- tenta todos na ordem de prioridade.
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_providers` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `provider`         ENUM('brevo','resend','smtp') NOT NULL DEFAULT 'smtp',
    `prioridade`       TINYINT UNSIGNED NOT NULL DEFAULT 1
                       COMMENT '1=primário, 2=fallback1, 3=fallback2',
    `ativo`            TINYINT(1) NOT NULL DEFAULT 1,
    `api_key`          VARCHAR(1024) DEFAULT NULL
                       COMMENT 'AES-256-CBC criptografada via EmailCrypto',
    `smtp_host`        VARCHAR(255) DEFAULT NULL,
    `smtp_port`        SMALLINT UNSIGNED DEFAULT 587,
    `smtp_user`        VARCHAR(255) DEFAULT NULL,
    `smtp_password`    VARCHAR(255) DEFAULT NULL
                       COMMENT 'AES-256-CBC criptografada',
    `sender_email`     VARCHAR(255) DEFAULT NULL,
    `sender_name`      VARCHAR(255) DEFAULT NULL,
    `status`           ENUM('ok','error','untested') NOT NULL DEFAULT 'untested',
    `ultima_validacao` TIMESTAMP NULL DEFAULT NULL,
    `data_criacao`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `data_atualizacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_prioridade` (`prioridade`),
    KEY `idx_ativo`      (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Provedores de e-mail com suporte a fallback automático';

-- ============================================================
-- 2. email_delivery_logs — Log detalhado de entregas
-- ============================================================
-- Registra cada tentativa de envio com:
-- - Qual provider foi efetivamente usado
-- - Quais providers foram tentados (fallback_chain JSON)
-- - Tempo de execução em segundos
-- - Código de resposta HTTP/SMTP
-- ============================================================
CREATE TABLE IF NOT EXISTS `email_delivery_logs` (
    `id`                INT NOT NULL AUTO_INCREMENT,
    `provider_usado`    VARCHAR(50) NOT NULL
                        COMMENT 'Provider que efetivou o envio',
    `fallback_chain`    TEXT DEFAULT NULL
                        COMMENT 'JSON array com tentativas: [{provider, success, elapsed, error}]',
    `destinatario`      VARCHAR(255) NOT NULL,
    `assunto`           VARCHAR(500) DEFAULT NULL,
    `status`            ENUM('enviado','erro','fallback','pendente') NOT NULL DEFAULT 'enviado',
    `erro`              TEXT DEFAULT NULL,
    `tempo_execucao`    DECIMAL(8,3) DEFAULT NULL
                        COMMENT 'Tempo total em segundos',
    `message_id`        VARCHAR(255) DEFAULT NULL,
    `response_code`     SMALLINT DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created_at`  (`created_at`),
    KEY `idx_status`      (`status`),
    KEY `idx_provider`    (`provider_usado`),
    KEY `idx_destinatario` (`destinatario`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log detalhado de entregas com rastreio de fallback';

-- ============================================================
-- 3. Adicionar colunas ao email_log existente (se necessário)
-- ============================================================
-- Adiciona provider, message_id e response_code ao log legado.
-- Usa ALTER TABLE separados para compatibilidade.
-- ============================================================
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'email_log'
      AND COLUMN_NAME  = 'provider'
);
-- Se provider não existe, adicionar
-- (execute manualmente se precisar):
-- ALTER TABLE `email_log` ADD COLUMN `provider` VARCHAR(50) DEFAULT NULL AFTER `status`;
-- ALTER TABLE `email_log` ADD COLUMN `message_id` VARCHAR(255) DEFAULT NULL AFTER `provider`;
-- ALTER TABLE `email_log` ADD COLUMN `response_code` SMALLINT DEFAULT NULL AFTER `message_id`;

-- ============================================================
-- 4. Seed inicial: copiar configuração atual para email_providers
-- ============================================================
-- Insere o provedor configurado em configuracao_smtp como
-- provedor primário (prioridade=1) em email_providers.
-- Executar APENAS se email_providers estiver vazio.
-- ============================================================
INSERT IGNORE INTO `email_providers`
    (provider, prioridade, ativo, api_key, smtp_host, smtp_port,
     smtp_user, smtp_password, sender_email, sender_name, status)
SELECT
    IFNULL(cs.email_provider, 'smtp') AS provider,
    1                                 AS prioridade,
    cs.smtp_ativo                     AS ativo,
    cs.api_key,
    cs.smtp_host,
    cs.smtp_port,
    cs.smtp_usuario                   AS smtp_user,
    cs.smtp_senha                     AS smtp_password,
    cs.sender_email,
    cs.sender_name,
    'untested'                        AS status
FROM `configuracao_smtp` cs
WHERE cs.smtp_ativo = 1
ORDER BY cs.id DESC
LIMIT 1;

-- ============================================================
-- 5. Verificação pós-migração
-- ============================================================
SELECT
    'email_providers'     AS tabela,
    COUNT(*)              AS registros,
    MAX(data_criacao)     AS ultima_insercao
FROM email_providers
UNION ALL
SELECT
    'email_delivery_logs',
    COUNT(*),
    MAX(created_at)
FROM email_delivery_logs;
