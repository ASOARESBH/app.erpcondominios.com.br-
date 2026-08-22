-- ============================================================================
-- ERP Condomínio — Configuração de campos do cadastro de visitantes por tenant
-- Compatível com MySQL/MariaDB 5.7
-- Execute uma vez, após realizar backup do banco.
--
-- Regra inicial preservada:
--   nome, tipo de documento, documento e telefone são obrigatórios;
--   pelo menos um anexo (foto OU documento digitalizado) é obrigatório;
--   foto e documento digitalizado individualmente permanecem opcionais.
-- ============================================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `config_visitantes_campos` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `tenant_id` INT(11) NOT NULL,
    `campo` VARCHAR(50) NOT NULL,
    `obrigatorio` TINYINT(1) NOT NULL DEFAULT 0,
    `atualizado_por_usuario_id` INT(11) DEFAULT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_config_visitantes_tenant_campo` (`tenant_id`, `campo`),
    KEY `idx_config_visitantes_tenant` (`tenant_id`),
    KEY `idx_config_visitantes_usuario` (`atualizado_por_usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Campos obrigatórios configuráveis do cadastro de visitantes por tenant';

-- A semântica de cada chave é validada exclusivamente pela API. Não aceitar
-- tenant_id ou nomes de campo enviados pelo navegador fora do catálogo oficial.
INSERT IGNORE INTO `config_visitantes_campos`
    (`tenant_id`, `campo`, `obrigatorio`)
SELECT t.id, c.campo, c.obrigatorio
FROM `tenants` t
CROSS JOIN (
    SELECT 'nome_completo' AS campo, 1 AS obrigatorio
    UNION ALL SELECT 'tipo_documento', 1
    UNION ALL SELECT 'documento', 1
    UNION ALL SELECT 'telefone_contato', 1
    UNION ALL SELECT 'celular', 0
    UNION ALL SELECT 'email', 0
    UNION ALL SELECT 'observacao', 0
    UNION ALL SELECT 'foto', 0
    UNION ALL SELECT 'documento_digitalizado', 0
    UNION ALL SELECT 'anexo_evidencia', 1
) c
WHERE t.id IS NOT NULL;

COMMIT;

-- Auditoria pós-execução (somente leitura):
-- SELECT tenant_id, campo, obrigatorio
-- FROM config_visitantes_campos
-- ORDER BY tenant_id, campo;

-- Reversão (somente se for necessário desfazer toda a funcionalidade):
-- DROP TABLE IF EXISTS config_visitantes_campos;
