-- =========================================================================
-- MIGRATION MULTI-TENANT FASE 1 — ERP CONDOMÍNIO
-- Versão: 2.0 (definitiva, baseada no dump real do banco)
-- Data: 2026-08-10
-- Banco: inlaud99_erpserra
--
-- INSTRUÇÕES:
--   1. Faça BACKUP completo do banco antes de executar
--   2. Execute no phpMyAdmin: Importar > Selecionar arquivo > Executar
--   3. Execute o SELECT de verificação no final para confirmar
-- =========================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- =========================================================================
-- SEÇÃO 1: CRIAR TABELA MESTRE DE CONDOMÍNIOS (tenants)
-- =========================================================================

CREATE TABLE IF NOT EXISTS `tenants` (
  `id`                  INT(11)       NOT NULL AUTO_INCREMENT,
  `slug`                VARCHAR(50)   NOT NULL COMMENT 'Identificador único na URL. Ex: serra, valedoipe',
  `razao_social`        VARCHAR(255)  NOT NULL,
  `nome_fantasia`       VARCHAR(255)  DEFAULT NULL,
  `cnpj`                VARCHAR(20)   NOT NULL,
  `plano`               ENUM('basico','profissional','enterprise') DEFAULT 'basico',
  `status`              ENUM('ativo','inativo','suspenso') DEFAULT 'ativo',
  `modulos_habilitados` JSON          DEFAULT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tabela mestre de condomínios (tenants) do sistema Multi-Tenant';

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
  LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(e.nome_fantasia, e.razao_social)), ' ', '-'), '.', ''), '/', '')) AS slug,
  e.razao_social,
  e.nome_fantasia,
  e.cnpj,
  'profissional' AS plano,
  CASE WHEN e.situacao = 'ativo' THEN 'ativo' ELSE 'inativo' END AS status,
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
-- Todas com DEFAULT 1 para compatibilidade com dados existentes
-- =========================================================================

-- Tabelas com coluna id (tenant_id inserido AFTER `id`):

ALTER TABLE `abastecimento_lancamentos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `abastecimento_recargas`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `abastecimento_saldo`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `abastecimento_veiculos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `acessos_visitantes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `alertas_estoque`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `avaliacoes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `avaliacoes_backup`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `categorias_estoque`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `checklist_alertas_config`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `checklist_alertas_gerados`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `checklist_itens`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `checklist_km_acumulado`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `checklist_veicular`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `config_periodo_leitura`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `configuracao_smtp`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `configuracoes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `contas_bancarias`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `contas_pagar`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `contas_receber`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `contrato_aditivos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `contrato_documentos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `contrato_orcamento_documentos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `contrato_orcamentos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `contratos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `controlid_dispositivos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `controlid_fila_comandos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `controlid_push_eventos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `controlid_push_queue`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `crm_anexos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `crm_interacoes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `crm_relacionamentos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `departamentos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `dependentes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `dispositivos_console`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `dispositivos_controlid`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `dispositivos_controlid_leituras`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `dispositivos_controlid_sync_log`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `dispositivos_seguranca`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `dispositivos_tablets`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `documentos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `documentos_acessos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `documentos_compartilhamentos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `documentos_departamentos_migrado_bkp`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `documentos_grupos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `documentos_grupos_moradores`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `documentos_grupos_usuarios`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `documentos_logs`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `documentos_pastas`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `documentos_tipos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `email_alertas`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `email_delivery_logs`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `email_log`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `email_providers`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `email_templates`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `empresa`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `empresa_log`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `face_descriptors`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `fornecedores`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `grupos_inventario`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `hidrometro`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `hidrometros`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `hidrometros_historico`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `historico_importacoes_ofx`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `historico_pagamentos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `historico_status_pedido`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `importacoes_financeiras`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `importacoes_financeiras_itens`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `inventario`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `lancamentos_agua`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `leituras`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `leituras_fotos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `local_acessos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `local_acessos_log`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `local_acessos_tipos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `log_reset_senha`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `logs_acesso_qrcode`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `logs_erro`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `logs_validacoes_dispositivo`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `manual_artigos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `manual_avaliacoes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `manual_buscas`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `manual_categorias`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `manual_historico`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `manual_modulos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `marcas_dispositivo`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `media_avaliacoes_fornecedor`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `media_avaliacoes_produto`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `modelos_dispositivo`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `moradores`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `movimentacoes_estoque`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `notif_alertas`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `notif_destinatarios`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `notif_regras`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `notificacoes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `notificacoes_downloads`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `notificacoes_visualizacoes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `os_assuntos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `os_chamados`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `os_config_homem_hora`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `os_etapas`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `os_interacao_fotos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `os_interacoes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `os_materiais_usados`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `os_recursos_humanos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `pedidos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `planos_contas`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `produtos_estoque`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `produtos_servicos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `protocolos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `publico_rate_limit`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `pwa_configuracoes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `pwa_fcm_tokens`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `pwa_logs`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `pwa_notificacoes_push`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `pwa_notificacoes_recebidas`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `pwa_oauth_cache`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `pwa_versao`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `qrcode_tokens`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `qrcodes_temporarios`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `ramos_atividade`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `recebedores`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `recuperacao_senha_tokens`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `registros_acesso`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `rh_banco_horas`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `rh_colaboradores`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `rh_escala`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `rh_ponto_lancamento`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `rh_ponto_periodo`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `senha_recuperacao_logs`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `sessoes_portal`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `sessoes_usuarios`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `tipos_dispositivo`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `unidades`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `usuario_modulos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `usuarios`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `validacoes_acesso`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `validacoes_face_id`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `veiculos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `view_dispositivos_ativos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `view_estatisticas_dispositivos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `view_estatisticas_tokens`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `view_tokens_ativos`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

ALTER TABLE `visitantes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' AFTER `id`;

-- Tabelas sem coluna id (tenant_id inserido no início):

ALTER TABLE `conciliacoes`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' FIRST;

ALTER TABLE `controlid_eventos_acesso`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' FIRST;

ALTER TABLE `logs_financeiro`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' FIRST;

ALTER TABLE `movimentacoes_bancarias`
  ADD COLUMN IF NOT EXISTS `tenant_id` INT(11) NOT NULL DEFAULT 1 COMMENT 'ID do condomínio (tenant)' FIRST;

-- =========================================================================
-- SEÇÃO 5: AJUSTES NA TABELA USUARIOS PARA MULTI-TENANT
-- =========================================================================

-- Adicionar valor super_admin na coluna permissao (se for ENUM)
-- Verificar tipo da coluna antes de executar:
-- SHOW COLUMNS FROM usuarios LIKE 'permissao';

-- Se a coluna for VARCHAR, nenhuma alteração necessária.
-- Se for ENUM, executar:
-- ALTER TABLE `usuarios` MODIFY COLUMN `permissao` ENUM('operador','visualizador','gerente','admin','super_admin') DEFAULT 'operador';

-- Criar usuário Super Admin (id=99) se não existir:
INSERT IGNORE INTO `usuarios` (`id`, `nome`, `email`, `senha`, `funcao`, `departamento`, `permissao`, `ativo`)
VALUES (99, 'Administrador ERP', 'admin@erpcondominios.com.br',
        '$2y$10$KbB2IoVIAp3H0sJBfxWzOlakCpf.CLN3S6M2b5SluO3vHvxBGCVJO',
        'Super Administrador', 'SISTEMA', 'super_admin', 1);

-- Vincular super_admin ao tenant 1
INSERT IGNORE INTO `usuario_tenant` (`usuario_id`, `tenant_id`, `permissao`, `ativo`)
VALUES (99, 1, 'super_admin', 1);

-- =========================================================================
-- SEÇÃO 6: ÍNDICES DE PERFORMANCE NAS TABELAS PRINCIPAIS
-- =========================================================================

ALTER TABLE `moradores` ADD INDEX IF NOT EXISTS `idx_moradores_tenant` (`tenant_id`);
ALTER TABLE `unidades` ADD INDEX IF NOT EXISTS `idx_unidades_tenant` (`tenant_id`);
ALTER TABLE `usuarios` ADD INDEX IF NOT EXISTS `idx_usuarios_tenant` (`tenant_id`);
ALTER TABLE `visitantes` ADD INDEX IF NOT EXISTS `idx_visitantes_tenant` (`tenant_id`);
ALTER TABLE `veiculos` ADD INDEX IF NOT EXISTS `idx_veiculos_tenant` (`tenant_id`);
ALTER TABLE `registros_acesso` ADD INDEX IF NOT EXISTS `idx_registros_acesso_tenant` (`tenant_id`);
ALTER TABLE `acessos_visitantes` ADD INDEX IF NOT EXISTS `idx_acessos_visitantes_tenant` (`tenant_id`);
ALTER TABLE `contas_pagar` ADD INDEX IF NOT EXISTS `idx_contas_pagar_tenant` (`tenant_id`);
ALTER TABLE `contas_receber` ADD INDEX IF NOT EXISTS `idx_contas_receber_tenant` (`tenant_id`);
ALTER TABLE `planos_contas` ADD INDEX IF NOT EXISTS `idx_planos_contas_tenant` (`tenant_id`);
ALTER TABLE `contas_bancarias` ADD INDEX IF NOT EXISTS `idx_contas_bancarias_tenant` (`tenant_id`);
ALTER TABLE `os_chamados` ADD INDEX IF NOT EXISTS `idx_os_chamados_tenant` (`tenant_id`);
ALTER TABLE `hidrometros` ADD INDEX IF NOT EXISTS `idx_hidrometros_tenant` (`tenant_id`);
ALTER TABLE `contratos` ADD INDEX IF NOT EXISTS `idx_contratos_tenant` (`tenant_id`);
ALTER TABLE `documentos` ADD INDEX IF NOT EXISTS `idx_documentos_tenant` (`tenant_id`);
ALTER TABLE `rh_colaboradores` ADD INDEX IF NOT EXISTS `idx_rh_colaboradores_tenant` (`tenant_id`);
ALTER TABLE `configuracoes` ADD INDEX IF NOT EXISTS `idx_configuracoes_tenant` (`tenant_id`);
ALTER TABLE `empresa` ADD INDEX IF NOT EXISTS `idx_empresa_tenant` (`tenant_id`);

-- =========================================================================
-- SEÇÃO 7: VERIFICAÇÃO — EXECUTE APÓS A MIGRATION
-- =========================================================================

SELECT
  (SELECT COUNT(*) FROM tenants)                          AS total_tenants,
  (SELECT COUNT(*) FROM usuario_tenant)                   AS total_vinculos,
  (SELECT COUNT(*) FROM usuarios WHERE permissao='super_admin') AS total_superadmin,
  (SELECT slug FROM tenants WHERE id = 1 LIMIT 1)        AS slug_tenant_1,
  (SELECT nome_fantasia FROM tenants WHERE id = 1 LIMIT 1) AS nome_tenant_1,
  (SELECT COUNT(*) FROM moradores WHERE tenant_id = 1)   AS moradores_tenant_1,
  (SELECT COUNT(*) FROM unidades WHERE tenant_id = 1)    AS unidades_tenant_1;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================================
-- FIM DA MIGRATION
-- Total de tabelas com tenant_id: 148
-- =========================================================================