-- =========================================================================
-- MIGRATION MULTI-TENANT FASE 1 — ERP CONDOMÍNIO
-- Versão: 3.0 (compatível com MySQL 5.7 / MariaDB 5.7)
-- Data: 2026-08-10
-- Banco: inlaud99_erpserra
--
-- INSTRUÇÕES:
--   1. Faça BACKUP completo do banco antes de executar
--   2. Execute no phpMyAdmin: Importar > Selecionar arquivo > Executar
--   3. Verifique o resultado com o SELECT no final
--
-- COMPATIBILIDADE: MySQL 5.7 / MariaDB 5.7
--   Não usa ADD COLUMN IF NOT EXISTS (não suportado no MySQL 5.7)
--   Usa STORED PROCEDURE com verificação via INFORMATION_SCHEMA
-- =========================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';

-- =========================================================================
-- SEÇÃO 1: CRIAR TABELA MESTRE DE CONDOMÍNIOS (tenants)
-- =========================================================================

CREATE TABLE IF NOT EXISTS `tenants` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `slug`                VARCHAR(50)   NOT NULL COMMENT 'Identificador unico na URL',
  `razao_social`        VARCHAR(255)  NOT NULL,
  `nome_fantasia`       VARCHAR(255)  DEFAULT NULL,
  `cnpj`                VARCHAR(20)   NOT NULL,
  `plano`               VARCHAR(20)   DEFAULT 'basico',
  `status`              VARCHAR(20)   DEFAULT 'ativo',
  `modulos_habilitados` TEXT          DEFAULT NULL,
  `logo_url`            VARCHAR(500)  DEFAULT NULL,
  `email_principal`     VARCHAR(255)  NOT NULL,
  `telefone`            VARCHAR(30)   DEFAULT NULL,
  `endereco`            VARCHAR(500)  DEFAULT NULL,
  `cidade`              VARCHAR(100)  DEFAULT NULL,
  `estado`              VARCHAR(2)    DEFAULT NULL,
  `data_criacao`        DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao`    DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_slug` (`slug`),
  KEY `idx_tenant_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- SEÇÃO 2: MIGRAR DADOS DA EMPRESA ATUAL PARA TENANTS
-- =========================================================================

INSERT INTO `tenants` (
  `id`, `slug`, `razao_social`, `nome_fantasia`, `cnpj`,
  `plano`, `status`, `logo_url`, `email_principal`,
  `telefone`, `cidade`, `estado`, `data_criacao`
)
SELECT
  e.id,
  LOWER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(e.nome_fantasia, e.razao_social)), ' ', '-'), '.', ''), '/', ''), '--', '-')) AS slug,
  e.razao_social,
  e.nome_fantasia,
  e.cnpj,
  'profissional',
  CASE WHEN e.situacao = 'ativo' THEN 'ativo' ELSE 'inativo' END,
  e.logo_url,
  e.email_principal,
  e.telefone,
  e.endereco_cidade,
  e.endereco_estado,
  e.data_criacao
FROM `empresa` e
ON DUPLICATE KEY UPDATE `slug` = VALUES(`slug`);

-- =========================================================================
-- SEÇÃO 3: CRIAR TABELA DE VÍNCULO USUÁRIO × CONDOMÍNIO
-- =========================================================================

CREATE TABLE IF NOT EXISTS `usuario_tenant` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT(11)      NOT NULL,
  `tenant_id`   INT(11)      NOT NULL,
  `permissao`   VARCHAR(50)  DEFAULT 'operador',
  `ativo`       TINYINT(1)   DEFAULT 1,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usuario_tenant` (`usuario_id`, `tenant_id`),
  KEY `idx_ut_tenant` (`tenant_id`),
  KEY `idx_ut_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vincular todos os usuários existentes ao tenant 1
INSERT IGNORE INTO `usuario_tenant` (`usuario_id`, `tenant_id`, `permissao`, `ativo`)
SELECT `id`, 1, `permissao`, `ativo` FROM `usuarios`;

-- =========================================================================
-- SEÇÃO 4: ADICIONAR tenant_id NAS TABELAS DE NEGÓCIO
-- Total: 148 tabelas
-- MySQL 5.7: usa STORED PROCEDURE para verificar existência da coluna
-- =========================================================================

-- Criar procedure auxiliar que adiciona tenant_id apenas se não existir
DROP PROCEDURE IF EXISTS `add_tenant_id`;

DELIMITER $$

CREATE PROCEDURE `add_tenant_id`(
  IN p_tabela VARCHAR(100),
  IN p_posicao VARCHAR(100)
)
BEGIN
  DECLARE v_count INT DEFAULT 0;
  SELECT COUNT(*) INTO v_count
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = p_tabela
    AND COLUMN_NAME = 'tenant_id';

  IF v_count = 0 THEN
    SET @sql = CONCAT(
      'ALTER TABLE `', p_tabela, '`
       ADD COLUMN `tenant_id` INT(11) NOT NULL DEFAULT 1 
       COMMENT ''ID do condominio (tenant)'' ', p_posicao
    );
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END $$

DELIMITER ;

-- Executar para cada tabela de negócio:

-- Tabelas com coluna id (tenant_id inserido AFTER `id`):
CALL add_tenant_id('abastecimento_lancamentos', 'AFTER `id`');
CALL add_tenant_id('abastecimento_recargas', 'AFTER `id`');
CALL add_tenant_id('abastecimento_saldo', 'AFTER `id`');
CALL add_tenant_id('abastecimento_veiculos', 'AFTER `id`');
CALL add_tenant_id('acessos_visitantes', 'AFTER `id`');
CALL add_tenant_id('alertas_estoque', 'AFTER `id`');
CALL add_tenant_id('avaliacoes', 'AFTER `id`');
CALL add_tenant_id('avaliacoes_backup', 'AFTER `id`');
CALL add_tenant_id('categorias_estoque', 'AFTER `id`');
CALL add_tenant_id('checklist_alertas_config', 'AFTER `id`');
CALL add_tenant_id('checklist_alertas_gerados', 'AFTER `id`');
CALL add_tenant_id('checklist_itens', 'AFTER `id`');
CALL add_tenant_id('checklist_km_acumulado', 'AFTER `id`');
CALL add_tenant_id('checklist_veicular', 'AFTER `id`');
CALL add_tenant_id('config_periodo_leitura', 'AFTER `id`');
CALL add_tenant_id('configuracao_smtp', 'AFTER `id`');
CALL add_tenant_id('configuracoes', 'AFTER `id`');
CALL add_tenant_id('contas_bancarias', 'AFTER `id`');
CALL add_tenant_id('contas_pagar', 'AFTER `id`');
CALL add_tenant_id('contas_receber', 'AFTER `id`');
CALL add_tenant_id('contrato_aditivos', 'AFTER `id`');
CALL add_tenant_id('contrato_documentos', 'AFTER `id`');
CALL add_tenant_id('contrato_orcamento_documentos', 'AFTER `id`');
CALL add_tenant_id('contrato_orcamentos', 'AFTER `id`');
CALL add_tenant_id('contratos', 'AFTER `id`');
CALL add_tenant_id('controlid_dispositivos', 'AFTER `id`');
CALL add_tenant_id('controlid_fila_comandos', 'AFTER `id`');
CALL add_tenant_id('controlid_push_eventos', 'AFTER `id`');
CALL add_tenant_id('controlid_push_queue', 'AFTER `id`');
CALL add_tenant_id('crm_anexos', 'AFTER `id`');
CALL add_tenant_id('crm_interacoes', 'AFTER `id`');
CALL add_tenant_id('crm_relacionamentos', 'AFTER `id`');
CALL add_tenant_id('departamentos', 'AFTER `id`');
CALL add_tenant_id('dependentes', 'AFTER `id`');
CALL add_tenant_id('dispositivos_console', 'AFTER `id`');
CALL add_tenant_id('dispositivos_controlid', 'AFTER `id`');
CALL add_tenant_id('dispositivos_controlid_leituras', 'AFTER `id`');
CALL add_tenant_id('dispositivos_controlid_sync_log', 'AFTER `id`');
CALL add_tenant_id('dispositivos_seguranca', 'AFTER `id`');
CALL add_tenant_id('dispositivos_tablets', 'AFTER `id`');
CALL add_tenant_id('documentos', 'AFTER `id`');
CALL add_tenant_id('documentos_acessos', 'AFTER `id`');
CALL add_tenant_id('documentos_compartilhamentos', 'AFTER `id`');
CALL add_tenant_id('documentos_departamentos_migrado_bkp', 'AFTER `id`');
CALL add_tenant_id('documentos_grupos', 'AFTER `id`');
CALL add_tenant_id('documentos_grupos_moradores', 'AFTER `id`');
CALL add_tenant_id('documentos_grupos_usuarios', 'AFTER `id`');
CALL add_tenant_id('documentos_logs', 'AFTER `id`');
CALL add_tenant_id('documentos_pastas', 'AFTER `id`');
CALL add_tenant_id('documentos_tipos', 'AFTER `id`');
CALL add_tenant_id('email_alertas', 'AFTER `id`');
CALL add_tenant_id('email_delivery_logs', 'AFTER `id`');
CALL add_tenant_id('email_log', 'AFTER `id`');
CALL add_tenant_id('email_providers', 'AFTER `id`');
CALL add_tenant_id('email_templates', 'AFTER `id`');
CALL add_tenant_id('empresa', 'AFTER `id`');
CALL add_tenant_id('empresa_log', 'AFTER `id`');
CALL add_tenant_id('face_descriptors', 'AFTER `id`');
CALL add_tenant_id('fornecedores', 'AFTER `id`');
CALL add_tenant_id('grupos_inventario', 'AFTER `id`');
CALL add_tenant_id('hidrometro', 'AFTER `id`');
CALL add_tenant_id('hidrometros', 'AFTER `id`');
CALL add_tenant_id('hidrometros_historico', 'AFTER `id`');
CALL add_tenant_id('historico_importacoes_ofx', 'AFTER `id`');
CALL add_tenant_id('historico_pagamentos', 'AFTER `id`');
CALL add_tenant_id('historico_status_pedido', 'AFTER `id`');
CALL add_tenant_id('importacoes_financeiras', 'AFTER `id`');
CALL add_tenant_id('importacoes_financeiras_itens', 'AFTER `id`');
CALL add_tenant_id('inventario', 'AFTER `id`');
CALL add_tenant_id('lancamentos_agua', 'AFTER `id`');
CALL add_tenant_id('leituras', 'AFTER `id`');
CALL add_tenant_id('leituras_fotos', 'AFTER `id`');
CALL add_tenant_id('local_acessos', 'AFTER `id`');
CALL add_tenant_id('local_acessos_log', 'AFTER `id`');
CALL add_tenant_id('local_acessos_tipos', 'AFTER `id`');
CALL add_tenant_id('log_reset_senha', 'AFTER `id`');
CALL add_tenant_id('logs_acesso_qrcode', 'AFTER `id`');
CALL add_tenant_id('logs_erro', 'AFTER `id`');
CALL add_tenant_id('logs_validacoes_dispositivo', 'AFTER `id`');
CALL add_tenant_id('manual_artigos', 'AFTER `id`');
CALL add_tenant_id('manual_avaliacoes', 'AFTER `id`');
CALL add_tenant_id('manual_buscas', 'AFTER `id`');
CALL add_tenant_id('manual_categorias', 'AFTER `id`');
CALL add_tenant_id('manual_historico', 'AFTER `id`');
CALL add_tenant_id('manual_modulos', 'AFTER `id`');
CALL add_tenant_id('marcas_dispositivo', 'AFTER `id`');
CALL add_tenant_id('media_avaliacoes_fornecedor', 'AFTER `id`');
CALL add_tenant_id('media_avaliacoes_produto', 'AFTER `id`');
CALL add_tenant_id('modelos_dispositivo', 'AFTER `id`');
CALL add_tenant_id('moradores', 'AFTER `id`');
CALL add_tenant_id('movimentacoes_estoque', 'AFTER `id`');
CALL add_tenant_id('notif_alertas', 'AFTER `id`');
CALL add_tenant_id('notif_destinatarios', 'AFTER `id`');
CALL add_tenant_id('notif_regras', 'AFTER `id`');
CALL add_tenant_id('notificacoes', 'AFTER `id`');
CALL add_tenant_id('notificacoes_downloads', 'AFTER `id`');
CALL add_tenant_id('notificacoes_visualizacoes', 'AFTER `id`');
CALL add_tenant_id('os_assuntos', 'AFTER `id`');
CALL add_tenant_id('os_chamados', 'AFTER `id`');
CALL add_tenant_id('os_config_homem_hora', 'AFTER `id`');
CALL add_tenant_id('os_etapas', 'AFTER `id`');
CALL add_tenant_id('os_interacao_fotos', 'AFTER `id`');
CALL add_tenant_id('os_interacoes', 'AFTER `id`');
CALL add_tenant_id('os_materiais_usados', 'AFTER `id`');
CALL add_tenant_id('os_recursos_humanos', 'AFTER `id`');
CALL add_tenant_id('pedidos', 'AFTER `id`');
CALL add_tenant_id('planos_contas', 'AFTER `id`');
CALL add_tenant_id('produtos_estoque', 'AFTER `id`');
CALL add_tenant_id('produtos_servicos', 'AFTER `id`');
CALL add_tenant_id('protocolos', 'AFTER `id`');
CALL add_tenant_id('publico_rate_limit', 'AFTER `id`');
CALL add_tenant_id('pwa_configuracoes', 'AFTER `id`');
CALL add_tenant_id('pwa_fcm_tokens', 'AFTER `id`');
CALL add_tenant_id('pwa_logs', 'AFTER `id`');
CALL add_tenant_id('pwa_notificacoes_push', 'AFTER `id`');
CALL add_tenant_id('pwa_notificacoes_recebidas', 'AFTER `id`');
CALL add_tenant_id('pwa_oauth_cache', 'AFTER `id`');
CALL add_tenant_id('pwa_versao', 'AFTER `id`');
CALL add_tenant_id('qrcode_tokens', 'AFTER `id`');
CALL add_tenant_id('qrcodes_temporarios', 'AFTER `id`');
CALL add_tenant_id('ramos_atividade', 'AFTER `id`');
CALL add_tenant_id('recebedores', 'AFTER `id`');
CALL add_tenant_id('recuperacao_senha_tokens', 'AFTER `id`');
CALL add_tenant_id('registros_acesso', 'AFTER `id`');
CALL add_tenant_id('rh_banco_horas', 'AFTER `id`');
CALL add_tenant_id('rh_colaboradores', 'AFTER `id`');
CALL add_tenant_id('rh_escala', 'AFTER `id`');
CALL add_tenant_id('rh_ponto_lancamento', 'AFTER `id`');
CALL add_tenant_id('rh_ponto_periodo', 'AFTER `id`');
CALL add_tenant_id('senha_recuperacao_logs', 'AFTER `id`');
CALL add_tenant_id('sessoes_portal', 'AFTER `id`');
CALL add_tenant_id('sessoes_usuarios', 'AFTER `id`');
CALL add_tenant_id('tipos_dispositivo', 'AFTER `id`');
CALL add_tenant_id('unidades', 'AFTER `id`');
CALL add_tenant_id('usuario_modulos', 'AFTER `id`');
CALL add_tenant_id('usuarios', 'AFTER `id`');
CALL add_tenant_id('validacoes_acesso', 'AFTER `id`');
CALL add_tenant_id('validacoes_face_id', 'AFTER `id`');
CALL add_tenant_id('veiculos', 'AFTER `id`');
CALL add_tenant_id('view_dispositivos_ativos', 'AFTER `id`');
CALL add_tenant_id('view_estatisticas_dispositivos', 'AFTER `id`');
CALL add_tenant_id('view_estatisticas_tokens', 'AFTER `id`');
CALL add_tenant_id('view_tokens_ativos', 'AFTER `id`');
CALL add_tenant_id('visitantes', 'AFTER `id`');

-- Tabelas sem coluna id (tenant_id inserido no início):
CALL add_tenant_id('conciliacoes', 'FIRST');
CALL add_tenant_id('controlid_eventos_acesso', 'FIRST');
CALL add_tenant_id('logs_financeiro', 'FIRST');
CALL add_tenant_id('movimentacoes_bancarias', 'FIRST');

-- Remover procedure auxiliar após uso
DROP PROCEDURE IF EXISTS `add_tenant_id`;

-- =========================================================================
-- SEÇÃO 5: ÍNDICES DE PERFORMANCE NAS TABELAS PRINCIPAIS
-- =========================================================================

DROP PROCEDURE IF EXISTS `add_tenant_index`;

DELIMITER $$

CREATE PROCEDURE `add_tenant_index`(IN p_tabela VARCHAR(100))
BEGIN
  DECLARE v_count INT DEFAULT 0;
  SELECT COUNT(*) INTO v_count
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = p_tabela
    AND INDEX_NAME = CONCAT('idx_', p_tabela, '_tenant');

  IF v_count = 0 THEN
    SET @sql = CONCAT(
      'ALTER TABLE `', p_tabela, '` ADD INDEX `idx_', p_tabela, '_tenant` (`tenant_id`)'
    );
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END $$

DELIMITER ;

CALL add_tenant_index('moradores');
CALL add_tenant_index('unidades');
CALL add_tenant_index('usuarios');
CALL add_tenant_index('visitantes');
CALL add_tenant_index('veiculos');
CALL add_tenant_index('registros_acesso');
CALL add_tenant_index('acessos_visitantes');
CALL add_tenant_index('contas_pagar');
CALL add_tenant_index('contas_receber');
CALL add_tenant_index('planos_contas');
CALL add_tenant_index('contas_bancarias');
CALL add_tenant_index('os_chamados');
CALL add_tenant_index('hidrometros');
CALL add_tenant_index('contratos');
CALL add_tenant_index('documentos');
CALL add_tenant_index('rh_colaboradores');
CALL add_tenant_index('configuracoes');
CALL add_tenant_index('empresa');

DROP PROCEDURE IF EXISTS `add_tenant_index`;

-- =========================================================================
-- SEÇÃO 6: CRIAR USUÁRIO SUPER ADMIN
-- =========================================================================

-- Remover usuário com id=0 se existir (criado por bug anterior)
DELETE FROM `usuarios` WHERE `id` = 0 AND `email` = 'admin@erpcondominios.com.br';

-- Criar usuário Super Admin (id=99)
INSERT IGNORE INTO `usuarios` (`id`, `nome`, `email`, `senha`, `funcao`, `departamento`, `permissao`, `ativo`)
VALUES (99, 'Administrador ERP', 'admin@erpcondominios.com.br',
        '$2y$10$KbB2IoVIAp3H0sJBfxWzOlakCpf.CLN3S6M2b5SluO3vHvxBGCVJO',
        'Super Administrador', 'SISTEMA', 'super_admin', 1);

-- Vincular super_admin ao tenant 1
INSERT IGNORE INTO `usuario_tenant` (`usuario_id`, `tenant_id`, `permissao`, `ativo`)
VALUES (99, 1, 'super_admin', 1);

-- =========================================================================
-- SEÇÃO 7: VERIFICAÇÃO — EXECUTE APÓS A MIGRATION
-- =========================================================================

SELECT
  (SELECT COUNT(*) FROM tenants)                               AS total_tenants,
  (SELECT COUNT(*) FROM usuario_tenant)                        AS total_vinculos,
  (SELECT COUNT(*) FROM usuarios WHERE permissao='super_admin') AS total_superadmin,
  (SELECT slug FROM tenants WHERE id = 1 LIMIT 1)             AS slug_tenant_1,
  (SELECT nome_fantasia FROM tenants WHERE id = 1 LIMIT 1)    AS nome_tenant_1,
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'tenant_id') AS tabelas_com_tenant_id;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================================
-- FIM DA MIGRATION
-- Total de tabelas com tenant_id: 148
-- =========================================================================