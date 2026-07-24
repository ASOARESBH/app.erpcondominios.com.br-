-- =========================================================================
-- CRIAR USUÁRIO SUPER-ADMIN — ERP Condomínio
-- =========================================================================
-- Usuário: admin@erpcondominios.com.br
-- Senha:   Admin259087@  (hash BCRYPT $2y$ abaixo)
-- Nível:   super_admin
-- ID:      99 (fixo, fora do range dos usuários normais)
--
-- COMO EXECUTAR:
--   phpMyAdmin → banco inlaud99_erpserra → SQL → Executar
--
-- SEGURO: usa INSERT ... ON DUPLICATE KEY — não duplica registros.
-- =========================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ─── PASSO 1: Garantir que a coluna aceita 'super_admin' ──────────────────
ALTER TABLE `usuarios`
  MODIFY COLUMN `permissao`
  ENUM('visualizador','operador','gerente','admin','super_admin')
  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'operador';

-- ─── PASSO 2: Remover usuário antigo com id=0 (se existir) ───────────────
DELETE FROM `usuario_tenant` WHERE `usuario_id` = 0;
DELETE FROM `usuarios` WHERE `id` = 0 AND `email` = 'admin@erpcondominios.com.br';

-- ─── PASSO 3: Criar (ou atualizar) o usuário Super-Admin com id=99 ────────
-- Senha: Admin259087@  →  hash BCRYPT $2y$ (cost=10, compatível com PHP)
INSERT INTO `usuarios`
  (`id`, `nome`, `email`, `senha`, `funcao`, `departamento`, `permissao`, `ativo`, `sessao_inativa`, `tenant_id`)
VALUES (
  99,
  'Administrador ERP',
  'admin@erpcondominios.com.br',
  '$2y$10$qQDXsTWCdIz9ENt1ih1X..Ma6FrJKB.5J789erSTSXnWfqIg8M6Kq',
  'Super Administrador',
  'SISTEMA',
  'super_admin',
  1,
  1,
  1
)
ON DUPLICATE KEY UPDATE
  `id`            = 99,
  `nome`          = 'Administrador ERP',
  `senha`         = '$2y$10$qQDXsTWCdIz9ENt1ih1X..Ma6FrJKB.5J789erSTSXnWfqIg8M6Kq',
  `permissao`     = 'super_admin',
  `ativo`         = 1,
  `sessao_inativa`= 1;

-- ─── PASSO 4: Vincular o super_admin ao tenant 1 ─────────────────────────
INSERT INTO `usuario_tenant` (`usuario_id`, `tenant_id`, `permissao`, `ativo`)
VALUES (99, 1, 'super_admin', 1)
ON DUPLICATE KEY UPDATE
  `permissao` = 'super_admin',
  `ativo`     = 1;

-- ─── PASSO 5: Verificação final ───────────────────────────────────────────
SELECT
  u.id,
  u.nome,
  u.email,
  u.permissao,
  u.ativo,
  t.slug        AS tenant_slug,
  t.nome_fantasia AS condominio
FROM usuarios u
LEFT JOIN usuario_tenant ut ON ut.usuario_id = u.id AND ut.ativo = 1
LEFT JOIN tenants t ON t.id = ut.tenant_id
WHERE u.email = 'admin@erpcondominios.com.br';

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================================
-- RESULTADO ESPERADO:
--   id  | nome               | email                        | permissao   | ativo
--   99  | Administrador ERP  | admin@erpcondominios.com.br  | super_admin | 1
--
-- COMO ACESSAR:
--   URL:   https://app.erpcondominios.com.br
--   Email: admin@erpcondominios.com.br
--   Senha: Admin259087@
--
-- Após login → Menu lateral → 👑 Super Admin
-- =========================================================================
