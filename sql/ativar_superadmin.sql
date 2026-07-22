-- =========================================================================
-- ATIVAR SUPER-ADMIN — ERP Condomínio
-- =========================================================================
-- Execute este script no phpMyAdmin para habilitar o acesso ao
-- Painel Super-Admin no sistema.
--
-- PASSO 1: Verificar usuários existentes
-- PASSO 2: Alterar a coluna permissao para aceitar 'super_admin'
-- PASSO 3: Promover o usuário administrador a super_admin
-- PASSO 4: Verificar o resultado
-- =========================================================================

-- ─── PASSO 1: Ver usuários admin existentes ───────────────────────────────
SELECT id, nome, email, permissao, ativo
FROM usuarios
WHERE permissao = 'admin' AND ativo = 1
ORDER BY id;

-- ─── PASSO 2: Alterar a coluna para aceitar 'super_admin' ─────────────────
-- A coluna permissao é ENUM — precisa incluir o novo valor
ALTER TABLE `usuarios`
  MODIFY COLUMN `permissao`
  ENUM('visualizador','operador','gerente','admin','super_admin')
  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'operador';

-- ─── PASSO 3: Promover o usuário ID=1 a super_admin ──────────────────────
-- Substitua "1" pelo ID do usuário que será o Super-Admin
-- (verifique o resultado do PASSO 1 acima)
UPDATE `usuarios`
SET `permissao` = 'super_admin'
WHERE `id` = 1;

-- ─── PASSO 4: Verificar resultado ────────────────────────────────────────
SELECT
    u.id,
    u.nome,
    u.email,
    u.permissao,
    u.ativo,
    t.nome_fantasia AS condominio,
    t.slug
FROM usuarios u
LEFT JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.ativo = 1
LEFT JOIN tenants t ON t.id = ut.tenant_id
WHERE u.permissao = 'super_admin';

-- =========================================================================
-- RESULTADO ESPERADO:
--   id  | nome              | email                        | permissao   | ativo
--   1   | ANDRE SOARES...   | serradaliberdade@outlook.com | super_admin | 1
-- =========================================================================
--
-- APÓS EXECUTAR:
--   1. Faça login normalmente com seu e-mail e senha atuais
--   2. O item "Super Admin" (ícone de coroa dourada) aparecerá no menu
--   3. Acesse: app.erpcondominios.com.br → Login → Menu → Super Admin
-- =========================================================================
