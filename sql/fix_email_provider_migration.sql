-- ============================================================
-- CORREÇÃO: Migração destrutiva de email_provider
-- ============================================================
-- Contexto:
--   Um UPDATE executado a cada request estava resetando
--   email_provider='brevo' → email_provider='smtp' para qualquer
--   linha que tivesse smtp_host e smtp_usuario preenchidos —
--   o que inclui usuários que configuraram o Brevo SMTP Relay
--   antes de migrarem para a API REST do Brevo.
--
-- Efeito: EmailProviderFactory sempre instanciava SmtpProvider,
--         resultando em "SMTP Error: Could not authenticate".
--
-- PASSO 1 — Diagnóstico: ver o estado atual
-- ============================================================

SELECT
    id,
    email_provider,
    provedor,
    smtp_host,
    smtp_usuario,
    CASE WHEN api_key IS NULL OR api_key = '' THEN 'SEM api_key' ELSE 'TEM api_key' END AS api_key_status,
    sender_email,
    sender_name,
    smtp_ativo
FROM configuracao_smtp
ORDER BY id DESC
LIMIT 5;

-- ============================================================
-- PASSO 2 — Restaurar linhas que foram incorretamente
--           convertidas de 'brevo' para 'smtp' mas que
--           possuem api_key configurada (instalações Brevo API)
-- ============================================================
-- ATENÇÃO: Execute este UPDATE APENAS se o diagnóstico acima
--          mostrar linhas com api_key preenchida e email_provider='smtp'
--          causado pela migração incorreta.
--
-- Critério seguro:
--   - email_provider = 'smtp'          (foi sobrescrito)
--   - api_key IS NOT NULL AND != ''    (tinha Brevo API configurada)
--   - smtp_host LIKE '%brevo%'         (confirma origem Brevo)
-- ============================================================

-- Verificar antes de executar:
SELECT id, email_provider, smtp_host, api_key IS NOT NULL AS tem_api_key
FROM configuracao_smtp
WHERE email_provider = 'smtp'
  AND api_key IS NOT NULL
  AND api_key != ''
  AND smtp_host LIKE '%brevo%';

-- Se a query acima retornar linhas, executar:
-- UPDATE configuracao_smtp
-- SET email_provider = 'brevo'
-- WHERE email_provider = 'smtp'
--   AND api_key IS NOT NULL
--   AND api_key != ''
--   AND smtp_host LIKE '%brevo%';

-- ============================================================
-- PASSO 3 — Restauração manual (caso acima não se aplique)
--
-- Se o usuário quer usar Brevo API REST (não SMTP relay):
--   1. Acesse o painel: layout-base.html?page=email_alertas
--   2. Selecione "Brevo" no seletor de provedor
--   3. Preencha API Key, E-mail Remetente, Nome Remetente
--   4. Clique em "Salvar Configuração"
--   O campo email_provider será salvo como 'brevo' com api_key
--   preenchida, e a nova migração protegida NÃO vai resetar.
-- ============================================================

-- ============================================================
-- PASSO 4 — Verificação pós-correção
-- ============================================================
SELECT
    id,
    email_provider,
    provedor,
    CASE email_provider
        WHEN 'brevo'  THEN 'BrevoProvider (API REST)'
        WHEN 'resend' THEN 'ResendProvider (API REST)'
        ELSE               'SmtpProvider (PHPMailer)'
    END AS factory_vai_instanciar,
    CASE WHEN api_key IS NULL OR api_key = '' THEN '⚠ SEM api_key' ELSE '✓ COM api_key' END AS api_key_status,
    sender_email,
    sender_name,
    smtp_host,
    smtp_ativo
FROM configuracao_smtp
ORDER BY id DESC
LIMIT 5;

-- ============================================================
-- RESULTADO ESPERADO APÓS CORREÇÃO:
--   Brevo API:  email_provider='brevo',  api_key preenchida,
--               sender_email preenchido, sender_name preenchido
--   SMTP relay: email_provider='smtp',   smtp_host preenchido,
--               smtp_usuario preenchido, smtp_senha preenchida
-- ============================================================
