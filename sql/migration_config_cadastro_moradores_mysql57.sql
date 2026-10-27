-- ============================================================================
-- ERP Condomínio — Configuração de campos de Moradores e Dependentes por tenant
-- Compatível com MySQL/MariaDB 5.7
-- Execute uma vez, após realizar backup do banco.
--
-- Regra inicial preservada:
--   campos estruturais permanecem obrigatórios;
--   demais campos seguem os defaults atuais;
--   um dependente continua limitado a um veículo até a opção ser ativada.
-- ============================================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `config_moradores_campos` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tenant_id` INT(11) NOT NULL,
    `grupo` VARCHAR(30) NOT NULL,
    `campo` VARCHAR(50) NOT NULL,
    `obrigatorio` TINYINT(1) NOT NULL DEFAULT 0,
    `atualizado_por_usuario_id` INT(11) DEFAULT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_config_moradores_tenant_grupo_campo` (`tenant_id`, `grupo`, `campo`),
    KEY `idx_config_moradores_tenant` (`tenant_id`),
    KEY `idx_config_moradores_usuario` (`atualizado_por_usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Campos obrigatórios configuráveis de moradores e dependentes por tenant';

CREATE TABLE IF NOT EXISTS `config_moradores_opcoes` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tenant_id` INT(11) NOT NULL,
    `opcao` VARCHAR(80) NOT NULL,
    `habilitado` TINYINT(1) NOT NULL DEFAULT 0,
    `atualizado_por_usuario_id` INT(11) DEFAULT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_config_moradores_tenant_opcao` (`tenant_id`, `opcao`),
    KEY `idx_config_moradores_opcoes_tenant` (`tenant_id`),
    KEY `idx_config_moradores_opcoes_usuario` (`atualizado_por_usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Regras operacionais configuráveis do módulo de moradores por tenant';

-- A semântica das chaves é validada exclusivamente pela API.
INSERT IGNORE INTO `config_moradores_campos` (`tenant_id`, `grupo`, `campo`, `obrigatorio`)
SELECT t.id, c.grupo, c.campo, c.obrigatorio
FROM `tenants` t
CROSS JOIN (
    SELECT 'moradores' AS grupo, 'nome' AS campo, 1 AS obrigatorio
    UNION ALL SELECT 'moradores', 'cpf', 1
    UNION ALL SELECT 'moradores', 'unidade', 1
    UNION ALL SELECT 'moradores', 'email', 1
    UNION ALL SELECT 'moradores', 'senha', 1
    UNION ALL SELECT 'moradores', 'telefone', 0
    UNION ALL SELECT 'moradores', 'celular', 0
    UNION ALL SELECT 'moradores', 'observacao', 0
    UNION ALL SELECT 'dependentes', 'morador_id', 1
    UNION ALL SELECT 'dependentes', 'nome_completo', 1
    UNION ALL SELECT 'dependentes', 'parentesco', 1
    UNION ALL SELECT 'dependentes', 'cpf', 1
    UNION ALL SELECT 'dependentes', 'email', 0
    UNION ALL SELECT 'dependentes', 'telefone', 0
    UNION ALL SELECT 'dependentes', 'celular', 0
    UNION ALL SELECT 'dependentes', 'observacao', 0
) c
WHERE t.id IS NOT NULL;

INSERT IGNORE INTO `config_moradores_opcoes` (`tenant_id`, `opcao`, `habilitado`)
SELECT t.id, 'dependente_multiplos_veiculos', 0
FROM `tenants` t
WHERE t.id IS NOT NULL;

COMMIT;

-- Auditoria pós-execução (somente leitura):
-- SELECT tenant_id, grupo, campo, obrigatorio
-- FROM config_moradores_campos
-- ORDER BY tenant_id, grupo, campo;
-- SELECT tenant_id, opcao, habilitado
-- FROM config_moradores_opcoes
-- ORDER BY tenant_id, opcao;

-- Reversão (somente se for necessário desfazer toda a funcionalidade):
-- DROP TABLE IF EXISTS config_moradores_opcoes;
-- DROP TABLE IF EXISTS config_moradores_campos;
