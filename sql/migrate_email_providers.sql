-- =============================================================
-- MIGRAÇÃO: Suporte a Provedores de E-mail (Brevo / Resend / SMTP)
-- =============================================================
-- Versão : 1.0
-- Data   : 2026-06-24
-- Seguro : pode ser executado mais de uma vez (usa IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)
-- =============================================================

-- -------------------------------------------------------------
-- 1. Adicionar colunas novas à configuracao_smtp
-- -------------------------------------------------------------

-- Tipo de provider de transporte (Brevo API, Resend API, SMTP)
ALTER TABLE `configuracao_smtp`
  ADD COLUMN IF NOT EXISTS `email_provider`
    ENUM('brevo','resend','smtp') NOT NULL DEFAULT 'brevo'
    COMMENT 'Provider de transporte: brevo | resend | smtp'
    AFTER `smtp_ativo`;

-- API Key criptografada (Brevo / Resend)
ALTER TABLE `configuracao_smtp`
  ADD COLUMN IF NOT EXISTS `api_key`
    VARCHAR(1024) DEFAULT NULL
    COMMENT 'API Key criptografada (AES-256-CBC via EmailCrypto)'
    AFTER `email_provider`;

-- E-mail remetente para providers de API (pode diferir do smtp_de_email)
ALTER TABLE `configuracao_smtp`
  ADD COLUMN IF NOT EXISTS `sender_email`
    VARCHAR(255) DEFAULT NULL
    COMMENT 'E-mail remetente para Brevo/Resend'
    AFTER `api_key`;

-- Nome remetente para providers de API
ALTER TABLE `configuracao_smtp`
  ADD COLUMN IF NOT EXISTS `sender_name`
    VARCHAR(255) DEFAULT NULL
    COMMENT 'Nome remetente para Brevo/Resend'
    AFTER `sender_email`;

-- -------------------------------------------------------------
-- 2. Adicionar colunas de rastreio ao email_log
-- -------------------------------------------------------------

-- Qual provider enviou este e-mail
ALTER TABLE `email_log`
  ADD COLUMN IF NOT EXISTS `provider`
    VARCHAR(50) DEFAULT NULL
    COMMENT 'Provider usado: brevo | resend | smtp'
    AFTER `status`;

-- ID da mensagem retornado pelo provider (Brevo/Resend)
ALTER TABLE `email_log`
  ADD COLUMN IF NOT EXISTS `message_id`
    VARCHAR(255) DEFAULT NULL
    COMMENT 'ID da mensagem retornado pelo provider'
    AFTER `provider`;

-- Código HTTP retornado pelo provider (Brevo/Resend)
ALTER TABLE `email_log`
  ADD COLUMN IF NOT EXISTS `response_code`
    INT DEFAULT NULL
    COMMENT 'Código HTTP da resposta do provider'
    AFTER `message_id`;

-- Detalhe de erro completo do provider
ALTER TABLE `email_log`
  ADD COLUMN IF NOT EXISTS `erro_detalhado`
    TEXT DEFAULT NULL
    COMMENT 'Resposta completa de erro do provider'
    AFTER `response_code`;

-- -------------------------------------------------------------
-- 3. Preservar instalações existentes com SMTP configurado
--    Marca os registros que já têm Host SMTP como 'smtp'
--    para que não quebrem após a migração.
-- -------------------------------------------------------------
UPDATE `configuracao_smtp`
SET `email_provider` = 'smtp'
WHERE `email_provider` = 'brevo'
  AND `smtp_host`    IS NOT NULL AND `smtp_host`    != ''
  AND `smtp_usuario` IS NOT NULL AND `smtp_usuario` != '';

-- -------------------------------------------------------------
-- 4. Verificação pós-migração
-- -------------------------------------------------------------
SELECT
    id,
    email_provider,
    CASE WHEN api_key IS NOT NULL THEN 'API Key presente' ELSE 'Sem API Key' END AS api_key_status,
    smtp_host,
    smtp_de_email AS sender_email_smtp,
    sender_email,
    sender_name,
    smtp_ativo
FROM configuracao_smtp
ORDER BY id DESC;
