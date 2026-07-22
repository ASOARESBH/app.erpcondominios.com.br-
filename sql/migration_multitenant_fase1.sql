-- =========================================================================
-- ERP CONDOMÍNIOS — MIGRATION MULTI-TENANT (FASE 1)
-- =========================================================================
-- Versão: 1.0.0
-- Data: 2026-07-22
-- Repositório: https://github.com/ASOARESBH/app.erpcondominios.com.br-
-- =========================================================================
-- OBJETIVO:
--   Preparar o banco de dados Single-Tenant para suportar múltiplos
--   condomínios (Multi-Tenant) usando a estratégia de banco único com
--   isolamento lógico por tenant_id.
--
-- O QUE ESTE SCRIPT FAZ:
--   1. Cria a tabela `tenants` (mestre de condomínios)
--   2. Migra os dados da tabela `empresa` para `tenants`
--   3. Cria a tabela `usuario_tenant` (usuários x condomínios)
--   4. Adiciona a coluna `tenant_id` em 133 tabelas de negócio
--   5. Cria índices de performance para as tabelas principais
--
-- COMO EXECUTAR NO HOSTGATOR:
--   1. Acesse o phpMyAdmin no cPanel
--   2. Selecione o banco de dados
--   3. Clique em "Importar" e selecione este arquivo
--   4. Clique em "Executar"
--
-- IMPORTANTE:
--   - Execute este script UMA ÚNICA VEZ no banco de produção
--   - Faça backup completo do banco ANTES de executar
--   - Todos os dados existentes serão preservados (tenant_id = 1)
--   - O condomínio atual (ERP Condomínio) receberá id = 1
-- =========================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';

-- =========================================================================
-- SEÇÃO 1: TABELA MESTRE DE TENANTS (CONDOMÍNIOS)
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
  `modulos_habilitados` json         DEFAULT NULL
                        COMMENT 'JSON com lista de chaves de módulos ativos para este tenant',
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
-- SEÇÃO 2: MIGRAÇÃO DOS DADOS DA EMPRESA ATUAL PARA TENANTS
-- =========================================================================
-- O condomínio atual receberá o slug 'erpcondominios' (pode ser alterado pelo Super-Admin)
-- e manterá o id = 1 para compatibilidade com todos os dados existentes.

INSERT INTO `tenants` (
  `id`, `slug`, `razao_social`, `nome_fantasia`, `cnpj`,
  `plano`, `status`, `logo_url`, `email_principal`,
  `telefone`, `cidade`, `estado`, `data_criacao`
)
SELECT
  e.id,
  'erpcondominios',
  e.razao_social,
  e.nome_fantasia,
  e.cnpj,
  'profissional',
  e.situacao,
  e.logo_url,
  e.email_principal,
  e.telefone,
  e.endereco_cidade,
  e.endereco_estado,
  e.data_criacao
FROM `empresa` e
ON DUPLICATE KEY UPDATE `slug` = VALUES(`slug`);

-- =========================================================================
-- SEÇÃO 3: TABELA DE RELACIONAMENTO USUÁRIO × TENANT
-- =========================================================================
-- Permite que um usuário (ex: administradora) acesse múltiplos condomínios
-- com permissões diferentes em cada um.

CREATE TABLE IF NOT EXISTS `usuario_tenant` (
  `id`         int(11)  NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11)  NOT NULL,
  `tenant_id`  int(11)  NOT NULL,
  `permissao`  enum('admin','gerente','operador','visualizador')
               COLLATE utf8mb4_unicode_ci DEFAULT 'operador',
  `ativo`      tinyint(1) DEFAULT 1,
  `criado_em`  datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usuario_tenant` (`usuario_id`, `tenant_id`),
  KEY `idx_ut_tenant` (`tenant_id`),
  KEY `idx_ut_usuario` (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Relacionamento de usuários com seus respectivos condomínios';

-- Migrar todos os usuários existentes para o tenant 1 (ERP Condomínio)
INSERT IGNORE INTO `usuario_tenant` (`usuario_id`, `tenant_id`, `permissao`, `ativo`)
SELECT `id`, 1, `permissao`, `ativo`
FROM `usuarios`;

-- =========================================================================
-- SEÇÃO 4: ADICIONAR tenant_id NAS TABELAS DE NEGÓCIO
-- =========================================================================
-- DEFAULT 1 garante que todos os dados existentes pertençam ao tenant atual.
-- A coluna é inserida logo após o `id` de cada tabela.

-- --- MÓDULO: ABASTECIMENTO ---
ALTER TABLE `abastecimento_lancamentos`       ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `abastecimento_recargas`          ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `abastecimento_saldo`             ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `abastecimento_veiculos`          ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: CONTROLE DE ACESSO ---
ALTER TABLE `acessos_visitantes`              ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `local_acessos`                   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `local_acessos_log`               ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `local_acessos_tipos`             ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `logs_acesso_qrcode`              ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `registros_acesso`                ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `validacoes_acesso`               ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `validacoes_face_id`              ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `face_descriptors`                ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `qrcode_tokens`                   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `qrcodes_temporarios`             ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: ESTOQUE E INVENTÁRIO ---
ALTER TABLE `alertas_estoque`                 ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `categorias_estoque`              ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `grupos_inventario`               ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `inventario`                      ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `movimentacoes_estoque`           ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `produtos_estoque`                ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `produtos_servicos`               ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: AVALIAÇÕES ---
ALTER TABLE `avaliacoes`                      ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: BRIDGE (INTEGRAÇÃO LOCAL) ---
ALTER TABLE `bridge_eventos_log`              ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `bridge_fila_comandos`            ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `bridge_status`                   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: CHECKLIST ---
ALTER TABLE `checklist_alertas_config`        ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `checklist_alertas_gerados`       ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `checklist_itens`                 ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `checklist_km_acumulado`          ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `checklist_veicular`              ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: FINANCEIRO ---
ALTER TABLE `conciliacoes`                    ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `contas_bancarias`                ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `contas_pagar`                    ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `contas_receber`                  ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `historico_importacoes_ofx`       ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `historico_pagamentos`            ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `importacoes_financeiras`         ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `importacoes_financeiras_itens`   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `logs_financeiro`                 ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `movimentacoes_bancarias`         ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `planos_contas`                   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `recebedores`                     ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: CONFIGURAÇÕES ---
ALTER TABLE `config_periodo_leitura`          ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `configuracao_smtp`               ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `configuracoes`                   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: CONTRATOS ---
ALTER TABLE `contrato_aditivos`               ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `contrato_documentos`             ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `contrato_orcamento_documentos`   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `contrato_orcamentos`             ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `contratos`                       ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: CONTROLID (INTEGRAÇÃO HARDWARE) ---
ALTER TABLE `controlid_dispositivos`          ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `controlid_eventos_acesso`        ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `controlid_fila_comandos`         ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `controlid_push_eventos`          ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `controlid_push_queue`            ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: CRM ---
ALTER TABLE `crm_anexos`                      ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `crm_interacoes`                  ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `crm_relacionamentos`             ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `crm_sequencia`                   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 FIRST;

-- --- MÓDULO: SISTEMA / EMPRESA ---
ALTER TABLE `departamentos`                   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `empresa`                         ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `empresa_log`                     ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: MORADORES / CONDOMÍNIO ---
ALTER TABLE `dependentes`                     ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `moradores`                       ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `unidades`                        ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `veiculos`                        ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `visitantes`                      ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: DISPOSITIVOS ---
ALTER TABLE `dispositivos_console`            ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `dispositivos_controlid`          ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `dispositivos_controlid_leituras` ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `dispositivos_controlid_sync_log` ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `dispositivos_seguranca`          ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `dispositivos_tablets`            ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `logs_validacoes_dispositivo`     ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: DOCUMENTOS (GED) ---
ALTER TABLE `documentos`                      ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `documentos_acessos`              ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `documentos_compartilhamentos`    ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `documentos_grupos`               ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `documentos_grupos_moradores`     ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `documentos_grupos_usuarios`      ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `documentos_logs`                 ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `documentos_pastas`               ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `documentos_tipos`                ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: EMAIL ---
ALTER TABLE `email_alertas`                   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `email_delivery_logs`             ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `email_log`                       ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `email_providers`                 ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `email_templates`                 ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: FORNECEDORES ---
ALTER TABLE `fornecedores`                    ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `media_avaliacoes_fornecedor`     ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `fornecedor_id`;

-- --- MÓDULO: HIDRÔMETROS ---
ALTER TABLE `hidrometro`                      ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `hidrometros`                     ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `hidrometros_historico`           ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `lancamentos_agua`                ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `leituras`                        ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `leituras_fotos`                  ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: MANUAL DO SISTEMA ---
ALTER TABLE `manual_artigos`                  ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `manual_avaliacoes`               ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `manual_buscas`                   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `manual_categorias`               ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `manual_favoritos`                ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 FIRST;
ALTER TABLE `manual_historico`                ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `manual_modulos`                  ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: NOTIFICAÇÕES ---
ALTER TABLE `notif_alertas`                   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `notif_destinatarios`             ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `notif_regras`                    ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `notificacoes`                    ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `notificacoes_downloads`          ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `notificacoes_visualizacoes`      ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: ORDENS DE SERVIÇO ---
ALTER TABLE `os_assuntos`                     ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `os_chamados`                     ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `os_config_homem_hora`            ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `os_etapas`                       ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `os_interacao_fotos`              ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `os_interacoes`                   ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `os_materiais_usados`             ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `os_recursos_humanos`             ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: MARKETPLACE / PEDIDOS ---
ALTER TABLE `historico_status_pedido`         ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `pedidos`                         ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: PROTOCOLOS ---
ALTER TABLE `protocolos`                      ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: PWA (APP DO MORADOR) ---
ALTER TABLE `pwa_configuracoes`               ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `pwa_fcm_tokens`                  ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `pwa_logs`                        ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `pwa_notificacoes_push`           ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `pwa_notificacoes_recebidas`      ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: RH ---
ALTER TABLE `rh_banco_horas`                  ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `rh_colaboradores`                ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `rh_escala`                       ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `rh_ponto_lancamento`             ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `rh_ponto_periodo`                ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: PORTAL DO MORADOR ---
ALTER TABLE `sessoes_portal`                  ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- --- MÓDULO: USUÁRIOS E PERMISSÕES ---
ALTER TABLE `usuario_modulos`                 ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `usuarios`                        ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;

-- =========================================================================
-- SEÇÃO 5: ÍNDICES DE PERFORMANCE
-- =========================================================================
-- Criados nas tabelas de maior volume para garantir que as queries
-- filtradas por tenant_id sejam executadas com eficiência.

-- Tabelas críticas (alto volume de consultas)
ALTER TABLE `usuarios`          ADD INDEX `idx_usuarios_tenant`         (`tenant_id`);
ALTER TABLE `moradores`         ADD INDEX `idx_moradores_tenant`         (`tenant_id`);
ALTER TABLE `veiculos`          ADD INDEX `idx_veiculos_tenant`          (`tenant_id`);
ALTER TABLE `visitantes`        ADD INDEX `idx_visitantes_tenant`        (`tenant_id`);
ALTER TABLE `dependentes`       ADD INDEX `idx_dependentes_tenant`       (`tenant_id`);
ALTER TABLE `unidades`          ADD INDEX `idx_unidades_tenant`          (`tenant_id`);

-- Financeiro
ALTER TABLE `contas_pagar`      ADD INDEX `idx_contas_pagar_tenant`      (`tenant_id`);
ALTER TABLE `contas_receber`    ADD INDEX `idx_contas_receber_tenant`     (`tenant_id`);
ALTER TABLE `movimentacoes_bancarias` ADD INDEX `idx_movbancarias_tenant` (`tenant_id`);
ALTER TABLE `planos_contas`     ADD INDEX `idx_planos_contas_tenant`      (`tenant_id`);

-- Manutenção
ALTER TABLE `os_chamados`       ADD INDEX `idx_os_chamados_tenant`        (`tenant_id`);
ALTER TABLE `produtos_estoque`  ADD INDEX `idx_produtos_estoque_tenant`   (`tenant_id`);
ALTER TABLE `leituras`          ADD INDEX `idx_leituras_tenant`           (`tenant_id`);
ALTER TABLE `hidrometros`       ADD INDEX `idx_hidrometros_tenant`        (`tenant_id`);

-- Acesso e Segurança
ALTER TABLE `registros_acesso`  ADD INDEX `idx_registros_acesso_tenant`   (`tenant_id`);
ALTER TABLE `acessos_visitantes` ADD INDEX `idx_acessos_vis_tenant`       (`tenant_id`);

-- Documentos e Contratos
ALTER TABLE `documentos`        ADD INDEX `idx_documentos_tenant`         (`tenant_id`);
ALTER TABLE `contratos`         ADD INDEX `idx_contratos_tenant`          (`tenant_id`);
ALTER TABLE `protocolos`        ADD INDEX `idx_protocolos_tenant`         (`tenant_id`);

-- RH
ALTER TABLE `rh_colaboradores`  ADD INDEX `idx_rh_colaboradores_tenant`   (`tenant_id`);
ALTER TABLE `rh_ponto_lancamento` ADD INDEX `idx_rh_ponto_tenant`         (`tenant_id`);

-- =========================================================================
-- SEÇÃO 6: VERIFICAÇÃO FINAL
-- =========================================================================
-- Execute este SELECT para confirmar que a migração foi bem-sucedida:
--
-- SELECT
--   (SELECT COUNT(*) FROM tenants) AS total_tenants,
--   (SELECT COUNT(*) FROM usuario_tenant) AS total_usuario_tenant,
--   (SELECT COUNT(*) FROM usuarios WHERE tenant_id = 1) AS usuarios_tenant1,
--   (SELECT COUNT(*) FROM moradores WHERE tenant_id = 1) AS moradores_tenant1;
--
-- O resultado esperado é:
--   total_tenants = 1
--   total_usuario_tenant = (número de usuários existentes)
--   usuarios_tenant1 = (número de usuários existentes)
--   moradores_tenant1 = (número de moradores existentes)

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================================
-- FIM DA MIGRATION FASE 1
-- =========================================================================

-- =========================================================================
-- SEÇÃO 6: ADICIONAR PERMISSÃO super_admin
-- =========================================================================
-- Atualiza a coluna permissao para aceitar o valor 'super_admin'
-- Execute este bloco apenas se a coluna for ENUM

-- Se a coluna for VARCHAR, nenhuma alteração é necessária.
-- Se for ENUM, execute o ALTER abaixo:
-- ALTER TABLE `usuarios` MODIFY COLUMN `permissao` ENUM('visualizador','operador','gerente','admin','super_admin') NOT NULL DEFAULT 'operador';

-- Para promover um usuário a super_admin (substitua pelo ID real):
-- UPDATE usuarios SET permissao = 'super_admin' WHERE id = 1;
