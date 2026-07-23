-- =========================================================================
-- CRIAR USUÁRIO SUPER-ADMIN — ERP Condomínio
-- =========================================================================
-- Usuário: admin@erpcondominios.com.br
-- Senha:   Admin259087@  (hash BCRYPT abaixo)
-- Nível:   super_admin
--
-- COMO EXECUTAR:
--   phpMyAdmin → banco inlaud99_erpserra → SQL → Executar
--
-- SEGURO: usa INSERT IGNORE + ON DUPLICATE KEY — não duplica registros.
-- =========================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ─── PASSO 1: Garantir que a coluna aceita 'super_admin' ──────────────────
ALTER TABLE `usuarios`
  MODIFY COLUMN `permissao`
  ENUM('visualizador','operador','gerente','admin','super_admin')
  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'operador';

-- ─── PASSO 2: Criar (ou atualizar) o usuário Super-Admin ─────────────────
-- Senha: Admin259087@  →  hash BCRYPT (cost=10)
INSERT INTO `usuarios`
  (`nome`, `email`, `senha`, `funcao`, `departamento`, `permissao`, `ativo`, `sessao_inativa`, `tenant_id`)
VALUES (
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
  `nome`          = 'Administrador ERP',
  `senha`         = '$2y$10$qQDXsTWCdIz9ENt1ih1X..Ma6FrJKB.5J789erSTSXnWfqIg8M6Kq',
  `permissao`     = 'super_admin',
  `ativo`         = 1,
  `sessao_inativa`= 1;

-- ─── PASSO 3: Vincular o super_admin ao tenant 1 ─────────────────────────
INSERT INTO `usuario_tenant` (`usuario_id`, `tenant_id`, `permissao`, `ativo`)
SELECT u.id, 1, 'super_admin', 1
FROM `usuarios` u
WHERE u.email = 'admin@erpcondominios.com.br'
ON DUPLICATE KEY UPDATE
  `permissao` = 'super_admin',
  `ativo`     = 1;

-- ─── PASSO 4: Verificação final ───────────────────────────────────────────
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
--   XX  | Administrador ERP  | admin@erpcondominios.com.br  | super_admin | 1
--
-- COMO ACESSAR:
--   URL:   https://app.erpcondominios.com.br
--   Email: admin@erpcondominios.com.br
--   Senha: Admin259087@
--
-- Após login → Menu lateral → 👑 Super Admin
-- =========================================================================
