-- ============================================================
-- MÓDULO GED — Gestão Eletrônica de Documentos
-- Migração completa: execute UMA única vez
-- IF NOT EXISTS em todas as tabelas — re-executável com segurança
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ============================================================
-- 1. DEPARTAMENTOS
-- ============================================================
CREATE TABLE IF NOT EXISTS `documentos_departamentos` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `nome`        VARCHAR(100) NOT NULL,
    `descricao`   TEXT,
    `icone`       VARCHAR(60)  DEFAULT 'fas fa-folder',
    `cor`         VARCHAR(7)   DEFAULT '#2563eb',
    `ativo`       TINYINT(1)   NOT NULL DEFAULT 1,
    `criado_por`  INT DEFAULT NULL,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Departamentos para organização de documentos';

-- Seed: departamentos padrão
INSERT IGNORE INTO `documentos_departamentos` (`id`, `nome`, `descricao`, `icone`, `cor`) VALUES
(1,  'Administrativo',    'Documentos administrativos gerais',           'fas fa-briefcase',        '#2563eb'),
(2,  'Financeiro',        'Balancetes, boletos e prestações de contas',  'fas fa-dollar-sign',      '#16a34a'),
(3,  'Jurídico',          'Contratos, estatutos e atas',                 'fas fa-gavel',            '#7c3aed'),
(4,  'Portaria',          'Documentos da portaria',                      'fas fa-door-open',        '#f59e0b'),
(5,  'Manutenção',        'Ordens de serviço e relatórios técnicos',     'fas fa-tools',            '#ea580c'),
(6,  'Diretoria',         'Documentos da diretoria',                     '#fas fa-users-cog',       '#0891b2'),
(7,  'Assembleias',       'Atas e convocações de assembleias',           'fas fa-landmark',         '#db2777'),
(8,  'RH',                'Documentos de recursos humanos',              'fas fa-user-tie',         '#65a30d'),
(9,  'Compras',           'Orçamentos e pedidos de compra',              'fas fa-shopping-cart',    '#d97706'),
(10, 'TI',                'Documentos de tecnologia',                    'fas fa-laptop-code',      '#6366f1');

-- ============================================================
-- 2. GRUPOS DE ACESSO
-- ============================================================
CREATE TABLE IF NOT EXISTS `documentos_grupos` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `nome`        VARCHAR(100) NOT NULL,
    `descricao`   TEXT,
    `acesso_tipo` ENUM('todos','moradores','administradores','conselho','diretoria',
                       'financeiro','juridico','portaria','manutencao',
                       'prestadores','visitantes','personalizado')
                  NOT NULL DEFAULT 'todos',
    `ativo`       TINYINT(1) NOT NULL DEFAULT 1,
    `criado_por`  INT DEFAULT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grupos de acesso para controle de visibilidade dos documentos';

-- Seed: grupos padrão
INSERT IGNORE INTO `documentos_grupos` (`id`, `nome`, `descricao`, `acesso_tipo`) VALUES
(1,  'Todos',           'Acesso público para todos os usuários e moradores', 'todos'),
(2,  'Moradores',       'Somente moradores cadastrados',                     'moradores'),
(3,  'Administradores', 'Somente administradores do sistema',                'administradores'),
(4,  'Conselho',        'Membros do conselho',                               'conselho'),
(5,  'Diretoria',       'Membros da diretoria',                              'diretoria'),
(6,  'Financeiro',      'Equipe financeira',                                 'financeiro'),
(7,  'Jurídico',        'Equipe jurídica',                                   'juridico'),
(8,  'Portaria',        'Funcionários da portaria',                          'portaria'),
(9,  'Manutenção',      'Equipe de manutenção',                              'manutencao'),
(10, 'Prestadores',     'Prestadores de serviço',                            'prestadores'),
(11, 'Visitantes',      'Visitantes autorizados',                            'visitantes');

-- ============================================================
-- 3. VÍNCULO GRUPO ↔ USUÁRIOS
-- ============================================================
CREATE TABLE IF NOT EXISTS `documentos_grupos_usuarios` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `grupo_id`    INT NOT NULL,
    `usuario_id`  INT NOT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_grupo_usuario` (`grupo_id`, `usuario_id`),
    KEY `idx_grupo_id`   (`grupo_id`),
    KEY `idx_usuario_id` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Vínculo entre grupos de acesso e usuários internos';

-- ============================================================
-- 4. VÍNCULO GRUPO ↔ MORADORES
-- ============================================================
CREATE TABLE IF NOT EXISTS `documentos_grupos_moradores` (
    `id`          INT NOT NULL AUTO_INCREMENT,
    `grupo_id`    INT NOT NULL,
    `morador_id`  INT NOT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_grupo_morador` (`grupo_id`, `morador_id`),
    KEY `idx_grupo_id`   (`grupo_id`),
    KEY `idx_morador_id` (`morador_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Vínculo entre grupos de acesso e moradores';

-- ============================================================
-- 5. PASTAS (estrutura hierárquica)
-- ============================================================
CREATE TABLE IF NOT EXISTS `documentos_pastas` (
    `id`               INT NOT NULL AUTO_INCREMENT,
    `nome`             VARCHAR(200) NOT NULL,
    `departamento_id`  INT DEFAULT NULL,
    `pasta_pai_id`     INT DEFAULT NULL,
    `descricao`        TEXT,
    `ordem`            SMALLINT UNSIGNED DEFAULT 0,
    `ativo`            TINYINT(1) NOT NULL DEFAULT 1,
    `criado_por`       INT DEFAULT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_departamento` (`departamento_id`),
    KEY `idx_pasta_pai`    (`pasta_pai_id`),
    KEY `idx_ativo`        (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Estrutura hierárquica de pastas para organização de documentos';

-- Seed: pastas de exemplo
INSERT IGNORE INTO `documentos_pastas` (`id`, `nome`, `departamento_id`, `pasta_pai_id`) VALUES
(1,  'Estatutos',              1, NULL),
(2,  'Atas',                   1, NULL),
(3,  'Contratos',              1, NULL),
(4,  'Prestação de Contas',    2, NULL),
(5,  'Boletos',                2, NULL),
(6,  'Balancetes',             2, NULL),
(7,  'Atas de Assembleia',     7, NULL),
(8,  'Convocações',            7, NULL);

-- ============================================================
-- 6. DOCUMENTOS (tabela principal)
-- ============================================================
CREATE TABLE IF NOT EXISTS `documentos` (
    `id`                    INT NOT NULL AUTO_INCREMENT,
    `nome`                  VARCHAR(300) NOT NULL,
    `descricao`             TEXT,
    `departamento_id`       INT DEFAULT NULL,
    `pasta_id`              INT DEFAULT NULL,
    `grupo_id`              INT DEFAULT NULL,
    `tags`                  TEXT COMMENT 'Palavras-chave separadas por vírgula',
    `arquivo`               VARCHAR(600) DEFAULT NULL COMMENT 'Caminho relativo em uploads/documentos/',
    `arquivo_tipo`          VARCHAR(100) DEFAULT NULL COMMENT 'MIME type',
    `arquivo_tamanho`       BIGINT       DEFAULT 0   COMMENT 'Bytes',
    `arquivo_nome_original` VARCHAR(500) DEFAULT NULL,
    `link_externo`          VARCHAR(1000) DEFAULT NULL COMMENT 'Link externo (Drive, SharePoint, etc.)',
    `status`                ENUM('ativo','inativo','expirado','rascunho') NOT NULL DEFAULT 'ativo',
    `data_publicacao`       DATE DEFAULT NULL,
    `data_expiracao`        DATE DEFAULT NULL,
    `total_downloads`       INT UNSIGNED DEFAULT 0,
    `total_visualizacoes`   INT UNSIGNED DEFAULT 0,
    `criado_por`            INT DEFAULT NULL,
    `atualizado_por`        INT DEFAULT NULL,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_departamento`  (`departamento_id`),
    KEY `idx_pasta`         (`pasta_id`),
    KEY `idx_grupo`         (`grupo_id`),
    KEY `idx_status`        (`status`),
    KEY `idx_created_at`    (`created_at`),
    FULLTEXT KEY `ft_busca` (`nome`, `descricao`, `tags`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Documentos do GED com suporte a arquivo físico e link externo';

-- ============================================================
-- 7. COMPARTILHAMENTOS (links únicos)
-- ============================================================
CREATE TABLE IF NOT EXISTS `documentos_compartilhamentos` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `documento_id`   INT NOT NULL,
    `token`          VARCHAR(64) NOT NULL,
    `descricao`      VARCHAR(300) DEFAULT NULL,
    `expira_em`      DATETIME DEFAULT NULL,
    `limite_acessos` INT UNSIGNED DEFAULT NULL COMMENT 'NULL = ilimitado',
    `total_acessos`  INT UNSIGNED DEFAULT 0,
    `ativo`          TINYINT(1) NOT NULL DEFAULT 1,
    `criado_por`     INT DEFAULT NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_token` (`token`),
    KEY `idx_documento_id` (`documento_id`),
    KEY `idx_ativo`        (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Links únicos para compartilhamento externo de documentos';

-- ============================================================
-- 8. ACESSOS (rastreabilidade completa)
-- ============================================================
CREATE TABLE IF NOT EXISTS `documentos_acessos` (
    `id`                     INT NOT NULL AUTO_INCREMENT,
    `documento_id`           INT NOT NULL,
    `tipo`                   ENUM('visualizacao','download','compartilhamento') NOT NULL DEFAULT 'visualizacao',
    `origem`                 ENUM('interno','externo') NOT NULL DEFAULT 'interno',
    `usuario_id`             INT DEFAULT NULL,
    `usuario_nome`           VARCHAR(200) DEFAULT NULL,
    `usuario_perfil`         VARCHAR(100) DEFAULT NULL,
    `morador_id`             INT DEFAULT NULL,
    `token_compartilhamento` VARCHAR(64) DEFAULT NULL,
    `ip`                     VARCHAR(45) DEFAULT NULL,
    `user_agent`             TEXT,
    `referer`                VARCHAR(500) DEFAULT NULL,
    `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_documento_id`  (`documento_id`),
    KEY `idx_usuario_id`    (`usuario_id`),
    KEY `idx_tipo`          (`tipo`),
    KEY `idx_created_at`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rastreabilidade completa de acessos a documentos';

-- ============================================================
-- 9. LOGS DE OPERAÇÕES
-- ============================================================
CREATE TABLE IF NOT EXISTS `documentos_logs` (
    `id`            INT NOT NULL AUTO_INCREMENT,
    `documento_id`  INT DEFAULT NULL,
    `usuario_id`    INT DEFAULT NULL,
    `acao`          ENUM('criacao','edicao','exclusao','download',
                         'visualizacao','compartilhamento','expiracao',
                         'restauracao','upload') NOT NULL,
    `descricao`     TEXT,
    `dados_antes`   JSON DEFAULT NULL,
    `dados_depois`  JSON DEFAULT NULL,
    `ip`            VARCHAR(45) DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_documento_id` (`documento_id`),
    KEY `idx_usuario_id`   (`usuario_id`),
    KEY `idx_acao`         (`acao`),
    KEY `idx_created_at`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auditoria completa de operações no módulo de documentos';

-- ============================================================
-- 10. INSERIR MÓDULO NO SISTEMA DE PERMISSÕES
-- ============================================================
INSERT IGNORE INTO `modulos_sistema` (`chave`, `nome`, `descricao`, `icone`, `categoria`)
VALUES ('documentos', 'Documentos (GED)', 'Gestão Eletrônica de Documentos', 'fas fa-folder-open', 'administrativa')
ON DUPLICATE KEY UPDATE `nome` = VALUES(`nome`);

-- ============================================================
-- 11. CRIAR DIRETÓRIO DE UPLOADS (via PHP — ver README)
-- ============================================================
-- Execute via PHP: mkdir(DOCUMENT_ROOT . '/uploads/documentos', 0755, true)
-- O .htaccess do diretório bloqueia acesso direto; todos os downloads
-- passam pela API (api_documentos.php?acao=download&id=X) que valida permissão.

SET foreign_key_checks = 1;

-- ============================================================
-- Verificação pós-migração
-- ============================================================
SELECT
    TABLE_NAME,
    TABLE_ROWS,
    CREATE_TIME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'documentos_departamentos','documentos_grupos','documentos_grupos_usuarios',
    'documentos_grupos_moradores','documentos_pastas','documentos',
    'documentos_compartilhamentos','documentos_acessos','documentos_logs'
  )
ORDER BY TABLE_NAME;
