-- =========================================================================
-- FIX: CRIAR TABELAS MULTI-TENANT (tenants + usuario_tenant)
-- =========================================================================
-- Execute este script se a migration principal parou antes de criar
-- as tabelas tenants e usuario_tenant.
--
-- COMO EXECUTAR:
--   1. phpMyAdmin → banco inlaud99_erpserra → SQL → Cole e execute
--   2. Ou: Importar este arquivo diretamente
--
-- SEGURO: usa IF NOT EXISTS — não apaga dados existentes.
-- =========================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';

-- =========================================================================
-- PASSO 1: CRIAR TABELA tenants (condomínios)
-- =========================================================================

CREATE TABLE IF NOT EXISTS `tenants` (
  `id`                  int(11)      NOT NULL AUTO_INCREMENT,
  `slug`                varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL
                        COMMENT 'Identificador único na URL. Ex: serra, valedoipe',
  `razao_social`        varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nome_fantasia`       varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cnpj`                varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `plano`               enum('basico','profissional','enterprise')
                        COLLATE utf8mb4_unicode_ci DEFAULT 'basico'
                        COMMENT 'Plano contratado pelo condomínio',
  `status`              enum('ativo','inativo','suspenso')
                        COLLATE utf8mb4_unicode_ci DEFAULT 'ativo',
  `modulos_habilitados` json         DEFAULT NULL,
  `logo_url`            varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_principal`     varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefone`            varchar(30)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `endereco`            varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cidade`              varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado`              varchar(2)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_criacao`        datetime     DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao`    datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_slug` (`slug`),
  KEY `idx_tenant_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tabela mestre de condomínios (tenants) do sistema Multi-Tenant';

-- =========================================================================
-- PASSO 2: MIGRAR DADOS DA EMPRESA ATUAL PARA tenants
-- =========================================================================
-- Usa INSERT IGNORE para não duplicar se já existir

INSERT IGNORE INTO `tenants` (
  `id`,
  `slug`,
  `razao_social`,
  `nome_fantasia`,
  `cnpj`,
  `plano`,
  `status`,
  `logo_url`,
  `email_principal`,
  `telefone`,
  `cidade`,
  `estado`,
  `data_criacao`
)
SELECT
  e.id,
  'serra',
  e.razao_social,
  e.nome_fantasia,
  e.cnpj,
  'profissional',
  COALESCE(e.situacao, 'ativo'),
  e.logo_url,
  e.email_principal,
  e.telefone,
  e.endereco_cidade,
  e.endereco_estado,
  COALESCE(e.data_criacao, NOW())
FROM `empresa` e
WHERE e.id IS NOT NULL
LIMIT 1;

-- =========================================================================
-- PASSO 3: CRIAR TABELA usuario_tenant
-- =========================================================================

CREATE TABLE IF NOT EXISTS `usuario_tenant` (
  `id`         int(11)  NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11)  NOT NULL,
  `tenant_id`  int(11)  NOT NULL,
  `permissao`  varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'operador'
               COMMENT 'Permissão do usuário neste condomínio específico',
  `ativo`      tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usuario_tenant` (`usuario_id`, `tenant_id`),
  KEY `idx_ut_tenant`  (`tenant_id`),
  KEY `idx_ut_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Relacionamento de usuários com seus respectivos condomínios';

-- =========================================================================
-- PASSO 4: VINCULAR TODOS OS USUÁRIOS EXISTENTES AO TENANT 1
-- =========================================================================
-- Migra todos os usuários para o condomínio Serra da Liberdade (id=1)

INSERT IGNORE INTO `usuario_tenant` (`usuario_id`, `tenant_id`, `permissao`, `ativo`)
SELECT
  u.id,
  1,
  CASE
    WHEN u.permissao IN ('admin','gerente','operador','visualizador','super_admin') THEN u.permissao
    ELSE 'operador'
  END,
  COALESCE(u.ativo, 1)
FROM `usuarios` u
WHERE u.id IS NOT NULL;

-- =========================================================================
-- PASSO 5: VERIFICAÇÃO FINAL
-- =========================================================================

SELECT
  (SELECT COUNT(*) FROM tenants)        AS total_tenants,
  (SELECT COUNT(*) FROM usuario_tenant) AS total_vinculos,
  (SELECT COUNT(*) FROM usuarios)       AS total_usuarios,
  (SELECT slug FROM tenants WHERE id = 1 LIMIT 1) AS slug_tenant_1;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================================
-- RESULTADO ESPERADO:
--   total_tenants  = 1
--   total_vinculos = (mesmo número de usuários cadastrados)
--   total_usuarios = (número de usuários)
--   slug_tenant_1  = 'serra'
-- =========================================================================
