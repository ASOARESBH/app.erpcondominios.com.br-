-- ============================================================================
-- ERP CONDOMÍNIO — RBAC, GRUPOS, SESSÕES E AUDITORIA MULTI-TENANT
-- Compatível com MySQL/MariaDB 5.7
--
-- EXECUTAR APÓS BACKUP COMPLETO. O script é incremental e NÃO remove tabelas
-- ou permissões legadas. Não utiliza adição condicional de coluna, CTEs ou funções de janela.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------------------------
-- 1. CATÁLOGO GLOBAL DE MÓDULOS E SUBMÓDULOS REAIS
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbac_modulos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chave` VARCHAR(80) NOT NULL,
  `nome` VARCHAR(120) NOT NULL,
  `modulo_pai_id` INT UNSIGNED DEFAULT NULL,
  `pagina` VARCHAR(80) DEFAULT NULL,
  `grupo` VARCHAR(80) NOT NULL DEFAULT 'Sistema',
  `tipo` ENUM('MODULO','SUBMODULO','PAGINA','SISTEMA') NOT NULL DEFAULT 'MODULO',
  `icone` VARCHAR(80) NOT NULL DEFAULT 'fas fa-shield-alt',
  `descricao` VARCHAR(255) DEFAULT NULL,
  `perfil_compatibilidade` ENUM('visualizador','operador','gerente','admin') NOT NULL DEFAULT 'operador',
  `ordem` SMALLINT NOT NULL DEFAULT 0,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rbac_modulo_chave` (`chave`),
  KEY `idx_rbac_modulo_pai` (`modulo_pai_id`),
  KEY `idx_rbac_modulo_pagina` (`pagina`),
  CONSTRAINT `fk_rbac_modulo_pai` FOREIGN KEY (`modulo_pai_id`) REFERENCES `rbac_modulos` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Módulos de primeiro nível, extraídos das páginas e menus existentes.
INSERT INTO `rbac_modulos` (`chave`,`nome`,`pagina`,`grupo`,`tipo`,`icone`,`descricao`,`perfil_compatibilidade`,`ordem`) VALUES
('dashboard','Dashboard','dashboard','Core','MODULO','fas fa-chart-line','Painel principal do condomínio','visualizador',10),
('moradores','Moradores','moradores','Condomínio','MODULO','fas fa-users','Cadastro e gestão de moradores e dependentes','operador',20),
('veiculos','Veículos','veiculos','Condomínio','MODULO','fas fa-car','Veículos, tags e vínculos de dependentes','operador',21),
('visitantes','Visitantes','visitantes','Condomínio','MODULO','fas fa-user-friends','Cadastro, anexos e relatórios de visitantes','operador',22),
('acesso','Acesso e Portaria','acesso','Acesso','MODULO','fas fa-door-open','Controle de entrada, saída e monitoramento','operador',30),
('financeiro','Financeiro','financeiro','Financeiro','MODULO','fas fa-money-bill-wave','Gestão financeira e análise de inadimplência','gerente',40),
('manutencao','Manutenção','manutencao','Manutenção','MODULO','fas fa-tools','Ordens de serviço, patrimônio e operações','operador',50),
('administrativo','Administrativo','administrativa','Administrativo','MODULO','fas fa-briefcase','Protocolos, documentos, contratos e comunicação','gerente',60),
('recursos_humanos','Recursos Humanos','recursos_humanos','RH','MODULO','fas fa-id-card','Funcionários e gestão de pessoas','gerente',70),
('crm','CRM','crm','CRM','MODULO','fas fa-handshake','Relacionamento e atendimento','gerente',80),
('marketplace','Marketplace','marketplace','Marketplace','MODULO','fas fa-store','Marketplace e fornecedores','operador',90),
('sistema','Sistema e Segurança','sistema','Sistema','SISTEMA','fas fa-shield-alt','Configurações, identidade, acesso e segurança','admin',100),
('superadmin','Super Admin','superadmin','Plataforma','SISTEMA','fas fa-crown','Administração global da plataforma','admin',110)
ON DUPLICATE KEY UPDATE `nome`=VALUES(`nome`), `pagina`=VALUES(`pagina`), `grupo`=VALUES(`grupo`), `tipo`=VALUES(`tipo`), `icone`=VALUES(`icone`), `descricao`=VALUES(`descricao`), `perfil_compatibilidade`=VALUES(`perfil_compatibilidade`), `ordem`=VALUES(`ordem`), `ativo`=1;

-- Submódulos e páginas existentes; parent é resolvido por chave para evitar IDs fixos.
INSERT INTO `rbac_modulos` (`chave`,`nome`,`modulo_pai_id`,`pagina`,`grupo`,`tipo`,`icone`,`descricao`,`perfil_compatibilidade`,`ordem`)
SELECT x.chave,x.nome,p.id,x.pagina,x.grupo,'SUBMODULO',x.icone,x.descricao,x.perfil,x.ordem
FROM (
 SELECT 'unidades' chave,'Unidades e Glebas' nome,'moradores' pai,'unidades' pagina,'Condomínio' grupo,'fas fa-home' icone,'Gestão de unidades e glebas' descricao,'operador' perfil,201 ordem UNION ALL
 SELECT 'registro' ,'Registro Manual','acesso','registro','Acesso','fas fa-clipboard-list','Registro manual de entrada e saída','operador',301 UNION ALL
 SELECT 'lpr','LPR','acesso','lpr','Acesso','fas fa-camera','Leitura de placas e eventos LPR','operador',302 UNION ALL
 SELECT 'relatorios_acesso','Relatórios de Acesso','acesso','relatorios','Acesso','fas fa-file-alt','Relatórios de registros de acesso','gerente',303 UNION ALL
 SELECT 'rondas_vigilante','Rondas de Vigilante','acesso','rondas_vigilante','Acesso','fas fa-person-walking','Rotas, rondas e alertas de vigilância','operador',304 UNION ALL
 SELECT 'contas_bancarias','Contas Bancárias','financeiro','contas_bancarias','Financeiro','fas fa-building-columns','Contas e saldos bancários','gerente',401 UNION ALL
 SELECT 'conciliacao','Conciliação Bancária','financeiro','conciliacao','Financeiro','fas fa-scale-balanced','Conciliação de lançamentos','gerente',402 UNION ALL
 SELECT 'contas_pagar','Contas a Pagar','financeiro','contas_pagar','Financeiro','fas fa-arrow-up','Despesas e pagamentos','gerente',403 UNION ALL
 SELECT 'contas_receber','Contas a Receber','financeiro','contas_receber','Financeiro','fas fa-arrow-down','Receitas e cobranças','gerente',404 UNION ALL
 SELECT 'importacao_financeira','Importação Financeira','financeiro','importacao_financeira','Financeiro','fas fa-file-import','Importação financeira','gerente',405 UNION ALL
 SELECT 'inadimplencia','Inadimplência','financeiro','inadimplencia','Financeiro','fas fa-user-clock','Indicadores de inadimplência','gerente',406 UNION ALL
 SELECT 'relatorios_bancarios','Relatórios Bancários','financeiro','relatorios_bancarios','Financeiro','fas fa-chart-bar','Relatórios financeiros','gerente',407 UNION ALL
 SELECT 'logs_financeiro','Logs Financeiros','financeiro','logs_financeiro','Financeiro','fas fa-file-shield','Diagnóstico financeiro','admin',408 UNION ALL
 SELECT 'abastecimento','Abastecimento','manutencao','abastecimento','Manutenção','fas fa-gas-pump','Controle de abastecimento','operador',501 UNION ALL
 SELECT 'ordens_servico','Ordens de Serviço','manutencao','ordens_servico','Manutenção','fas fa-screwdriver-wrench','Chamados e ordens de serviço','operador',502 UNION ALL
 SELECT 'imprimir_os','Impressão de OS','manutencao','imprimir_os','Manutenção','fas fa-print','Impressão de ordem de serviço','operador',503 UNION ALL
 SELECT 'checklists','Checklists','manutencao','checklists','Manutenção','fas fa-list-check','Checklists operacionais','operador',504 UNION ALL
 SELECT 'estoque','Estoque','manutencao','estoque','Manutenção','fas fa-boxes','Materiais e insumos','operador',505 UNION ALL
 SELECT 'inventario','Inventário','manutencao','inventario','Manutenção','fas fa-clipboard-check','Patrimônio e inventário','operador',506 UNION ALL
 SELECT 'relatorios_inventario','Relatórios de Inventário','manutencao','relatorios_inventario','Manutenção','fas fa-chart-pie','Relatórios patrimoniais','gerente',507 UNION ALL
 SELECT 'hidrometro','Hidrômetros','manutencao','hidrometro','Manutenção','fas fa-tint','Cadastro de hidrômetros','operador',508 UNION ALL
 SELECT 'leitura','Leituras de Hidrômetro','manutencao','leitura','Manutenção','fas fa-gauge','Leituras de consumo','operador',509 UNION ALL
 SELECT 'relatorios_hidrometro','Relatórios de Hidrômetro','manutencao','relatorios_hidrometro','Manutenção','fas fa-chart-column','Relatórios de consumo','gerente',510 UNION ALL
 SELECT 'assembleia','Assembleias','administrativo','assembleia','Administrativo','fas fa-people-group','Assembleias e deliberações','operador',601 UNION ALL
 SELECT 'contratos','Contratos','administrativo','contratos','Administrativo','fas fa-file-contract','Contratos e prestadores','gerente',602 UNION ALL
 SELECT 'protocolos','Protocolos','administrativo','protocolos','Administrativo','fas fa-stamp','Protocolos de correspondência','operador',603 UNION ALL
 SELECT 'protocolo','Protocolo de Mercadorias','administrativo','protocolo','Administrativo','fas fa-box','Registro de mercadorias','operador',604 UNION ALL
 SELECT 'documentos','Documentos','administrativo','documentos','Administrativo','fas fa-folder-open','Gestão documental','operador',605 UNION ALL
 SELECT 'notificacoes','Notificações','administrativo','notificacoes','Administrativo','fas fa-bell','Comunicação com moradores','operador',606 UNION ALL
 SELECT 'notificacoes_push','Notificações Push','administrativo','notificacoes_push','Administrativo','fas fa-bullhorn','Envio de alertas push','operador',607 UNION ALL
 SELECT 'eventos','Eventos','administrativo','eventos','Administrativo','fas fa-calendar','Eventos e reservas','operador',608 UNION ALL
 SELECT 'departamentos','Departamentos','recursos_humanos','departamentos','RH','fas fa-sitemap','Cadastro de departamentos','gerente',701 UNION ALL
 SELECT 'marketplace_admin','Administração Marketplace','marketplace','marketplace_admin','Marketplace','fas fa-store','Gestão administrativa do marketplace','admin',901 UNION ALL
 SELECT 'empresa','Empresa','sistema','empresa','Sistema','fas fa-building','Dados da empresa/tenant','admin',1001 UNION ALL
 SELECT 'configuracao','Configurações','sistema','configuracao','Sistema','fas fa-cog','Configurações gerais','admin',1002 UNION ALL
 SELECT 'email_alertas','E-mail e Alertas','sistema','email_alertas','Sistema','fas fa-envelope','Configuração de e-mail e alertas','admin',1003 UNION ALL
 SELECT 'dispositivos','Dispositivos','sistema','dispositivos','Sistema','fas fa-microchip','Dispositivos e Monitoring','admin',1004 UNION ALL
 SELECT 'pwa_central','Central PWA','sistema','pwa_central','Sistema','fas fa-mobile-screen','Central PWA','admin',1005 UNION ALL
 SELECT 'aplicativos','Aplicativos','sistema','aplicativos','Sistema','fas fa-mobile-screen-button','Aplicativos e versões','admin',1006 UNION ALL
 SELECT 'manual','Manual do Sistema','sistema','manual','Sistema','fas fa-book-open','Base de conhecimento','visualizador',1007 UNION ALL
 SELECT 'meu_perfil','Meu Perfil','sistema','meu_perfil','Sistema','fas fa-user-circle','Perfil do usuário autenticado','visualizador',1008 UNION ALL
 SELECT 'usuarios','Usuários','sistema','usuarios','Sistema','fas fa-user-cog','Central de identidade e acesso','admin',1009 UNION ALL
 SELECT 'admin_sessoes','Sessões','sistema','admin-sessoes','Sistema','fas fa-right-left','Sessões e timeouts','admin',1010 UNION ALL
 SELECT 'seguranca','Segurança','sistema','seguranca','Sistema','fas fa-shield-halved','Políticas de segurança','admin',1011 UNION ALL
 SELECT 'auditoria','Auditoria','sistema',NULL,'Sistema','fas fa-clipboard-list','Central de auditoria','admin',1012 UNION ALL
 SELECT 'superadmin_aplicativos','Aplicativos da Plataforma','superadmin','aplicativos','Plataforma','fas fa-mobile-screen-button','Gestão global de aplicativos','admin',1101
) x
INNER JOIN rbac_modulos p ON p.chave = x.pai
ON DUPLICATE KEY UPDATE `nome`=VALUES(`nome`),`modulo_pai_id`=VALUES(`modulo_pai_id`),`pagina`=VALUES(`pagina`),`grupo`=VALUES(`grupo`),`icone`=VALUES(`icone`),`descricao`=VALUES(`descricao`),`perfil_compatibilidade`=VALUES(`perfil_compatibilidade`),`ordem`=VALUES(`ordem`),`ativo`=1;

-- --------------------------------------------------------------------------
-- 2. AÇÕES VÁLIDAS POR MÓDULO
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbac_permissoes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `modulo_id` INT UNSIGNED NOT NULL,
  `acao` VARCHAR(32) NOT NULL,
  `nome` VARCHAR(80) NOT NULL,
  `descricao` VARCHAR(255) DEFAULT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rbac_modulo_acao` (`modulo_id`,`acao`),
  KEY `idx_rbac_permissao_modulo` (`modulo_id`),
  CONSTRAINT `fk_rbac_permissao_modulo` FOREIGN KEY (`modulo_id`) REFERENCES `rbac_modulos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Toda página/módulo efetivo pode ser visualizado; nenhum recurso nasce liberado.
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'visualizar','Visualizar','Acessar tela, URL e dados do recurso' FROM rbac_modulos WHERE ativo=1;

-- CRUD somente para recursos operacionais existentes.
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'criar','Criar','Criar registros do recurso' FROM rbac_modulos WHERE chave IN ('moradores','unidades','veiculos','visitantes','registro','rondas_vigilante','contas_bancarias','contas_pagar','contas_receber','ordens_servico','checklists','estoque','inventario','hidrometro','leitura','assembleia','contratos','protocolos','protocolo','documentos','notificacoes','notificacoes_push','eventos','departamentos','recursos_humanos','crm','marketplace','usuarios','pwa_central','aplicativos');
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'editar','Editar','Alterar registros do recurso' FROM rbac_modulos WHERE chave IN ('moradores','unidades','veiculos','visitantes','registro','rondas_vigilante','contas_bancarias','contas_pagar','contas_receber','ordens_servico','checklists','estoque','inventario','hidrometro','leitura','assembleia','contratos','protocolos','protocolo','documentos','notificacoes','notificacoes_push','eventos','departamentos','recursos_humanos','crm','marketplace','usuarios','pwa_central','aplicativos');
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'excluir','Excluir','Excluir registros do recurso' FROM rbac_modulos WHERE chave IN ('moradores','unidades','veiculos','visitantes','rondas_vigilante','contas_pagar','contas_receber','ordens_servico','checklists','estoque','inventario','assembleia','contratos','protocolos','protocolo','documentos','eventos','departamentos','crm','marketplace');
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'exportar','Exportar','Exportar dados do recurso' FROM rbac_modulos WHERE chave IN ('visitantes','acesso','relatorios_acesso','financeiro','relatorios_bancarios','inadimplencia','relatorios_inventario','relatorios_hidrometro','logs_financeiro','auditoria');
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'imprimir','Imprimir','Imprimir ou gerar PDF' FROM rbac_modulos WHERE chave IN ('visitantes','acesso','relatorios_acesso','imprimir_os','ordens_servico','financeiro','relatorios_bancarios','relatorios_inventario','relatorios_hidrometro','auditoria');
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'importar','Importar','Importar arquivos ou dados externos' FROM rbac_modulos WHERE chave IN ('unidades','financeiro','importacao_financeira','inadimplencia','documentos','estoque','inventario');
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'executar','Executar','Executar ação operacional ou crítica' FROM rbac_modulos WHERE chave IN ('registro','acesso','lpr','rondas_vigilante','ordens_servico','notificacoes','notificacoes_push','admin_sessoes','dispositivos','pwa_central','aplicativos');
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'configurar','Configurar','Alterar configurações do recurso' FROM rbac_modulos WHERE chave IN ('empresa','configuracao','email_alertas','dispositivos','pwa_central','aplicativos','seguranca','admin_sessoes','usuarios','auditoria','superadmin');
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'aprovar','Aprovar','Aprovar fluxo sujeito a validação' FROM rbac_modulos WHERE chave IN ('contas_pagar','contas_receber','ordens_servico','protocolos','protocolo');
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'bloquear','Bloquear','Bloquear usuário, sessão ou recurso' FROM rbac_modulos WHERE chave IN ('usuarios','admin_sessoes','seguranca');
INSERT IGNORE INTO rbac_permissoes (`modulo_id`,`acao`,`nome`,`descricao`)
SELECT id,'desbloquear','Desbloquear','Restaurar acesso de usuário, sessão ou recurso' FROM rbac_modulos WHERE chave IN ('usuarios','admin_sessoes','seguranca');

-- --------------------------------------------------------------------------
-- 3. GRUPOS, VÍNCULOS E EXCEÇÕES INDIVIDUAIS
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbac_grupos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) DEFAULT NULL,
  `slug` VARCHAR(80) NOT NULL,
  `nome` VARCHAR(120) NOT NULL,
  `descricao` VARCHAR(255) DEFAULT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `protegido` TINYINT(1) NOT NULL DEFAULT 0,
  `criado_por_usuario_id` INT(11) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `excluido_em` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rbac_grupo_tenant_slug` (`tenant_id`,`slug`),
  KEY `idx_rbac_grupo_tenant_ativo` (`tenant_id`,`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rbac_grupo_permissoes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grupo_id` INT UNSIGNED NOT NULL,
  `permissao_id` INT UNSIGNED NOT NULL,
  `efeito` ENUM('PERMITIR','NEGAR') NOT NULL DEFAULT 'PERMITIR',
  `escopo_dados` ENUM('GLOBAL','DEPARTAMENTO','UNIDADE','PROPRIO','ATRIBUIDO') NOT NULL DEFAULT 'GLOBAL',
  `criado_por_usuario_id` INT(11) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rbac_grupo_permissao` (`grupo_id`,`permissao_id`),
  KEY `idx_rbac_gp_permissao` (`permissao_id`),
  CONSTRAINT `fk_rbac_gp_grupo` FOREIGN KEY (`grupo_id`) REFERENCES `rbac_grupos` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rbac_gp_permissao` FOREIGN KEY (`permissao_id`) REFERENCES `rbac_permissoes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rbac_usuario_grupos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` INT(11) NOT NULL,
  `tenant_id` INT(11) NOT NULL,
  `grupo_id` INT UNSIGNED NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `atribuido_por_usuario_id` INT(11) DEFAULT NULL,
  `atribuido_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `removido_em` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rbac_usuario_grupo_tenant` (`usuario_id`,`tenant_id`,`grupo_id`),
  KEY `idx_rbac_ug_tenant_usuario` (`tenant_id`,`usuario_id`),
  KEY `idx_rbac_ug_grupo` (`grupo_id`),
  CONSTRAINT `fk_rbac_ug_grupo` FOREIGN KEY (`grupo_id`) REFERENCES `rbac_grupos` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rbac_usuario_permissoes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id` INT(11) NOT NULL,
  `tenant_id` INT(11) NOT NULL,
  `permissao_id` INT UNSIGNED NOT NULL,
  `efeito` ENUM('PERMITIR','NEGAR') NOT NULL DEFAULT 'PERMITIR',
  `escopo_dados` ENUM('GLOBAL','DEPARTAMENTO','UNIDADE','PROPRIO','ATRIBUIDO') NOT NULL DEFAULT 'GLOBAL',
  `motivo` VARCHAR(255) DEFAULT NULL,
  `atribuido_por_usuario_id` INT(11) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `revogado_em` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rbac_usuario_permissao_tenant` (`usuario_id`,`tenant_id`,`permissao_id`),
  KEY `idx_rbac_up_tenant_usuario` (`tenant_id`,`usuario_id`),
  KEY `idx_rbac_up_permissao` (`permissao_id`),
  CONSTRAINT `fk_rbac_up_permissao` FOREIGN KEY (`permissao_id`) REFERENCES `rbac_permissoes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rbac_configuracoes` (
  `tenant_id` INT(11) NOT NULL,
  `revisao_permissoes` INT UNSIGNED NOT NULL DEFAULT 1,
  `timeout_padrao_minutos` INT UNSIGNED NOT NULL DEFAULT 480,
  `atualizado_por_usuario_id` INT(11) DEFAULT NULL,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- 4. SESSÕES E AUDITORIA IMUTÁVEL COM CADEIA DE INTEGRIDADE
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rbac_sessoes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) DEFAULT NULL,
  `usuario_id` INT(11) NOT NULL,
  `sessao_hash` CHAR(64) NOT NULL,
  `status` ENUM('ATIVA','ENCERRADA','EXPIRADA','REVOGADA') NOT NULL DEFAULT 'ATIVA',
  `sessao_inativa` TINYINT(1) NOT NULL DEFAULT 0,
  `timeout_minutos` INT UNSIGNED DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `dispositivo` VARCHAR(120) DEFAULT NULL,
  `navegador` VARCHAR(120) DEFAULT NULL,
  `sistema_operacional` VARCHAR(120) DEFAULT NULL,
  `iniciada_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultima_atividade_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `encerrada_em` DATETIME DEFAULT NULL,
  `motivo_encerramento` VARCHAR(120) DEFAULT NULL,
  `encerrado_por_usuario_id` INT(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rbac_sessao_hash` (`sessao_hash`),
  KEY `idx_rbac_sessao_tenant_usuario_status` (`tenant_id`,`usuario_id`,`status`),
  KEY `idx_rbac_sessao_atividade` (`ultima_atividade_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rbac_auditoria` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evento_uuid` CHAR(36) NOT NULL,
  `tenant_id` INT(11) DEFAULT NULL,
  `usuario_id` INT(11) DEFAULT NULL,
  `usuario_nome` VARCHAR(160) DEFAULT NULL,
  `grupo_nome` VARCHAR(120) DEFAULT NULL,
  `sessao_id` BIGINT UNSIGNED DEFAULT NULL,
  `modulo_chave` VARCHAR(80) DEFAULT NULL,
  `submodulo_chave` VARCHAR(80) DEFAULT NULL,
  `acao` VARCHAR(80) NOT NULL,
  `origem` VARCHAR(80) NOT NULL DEFAULT 'ERP_WEB',
  `metodo_http` VARCHAR(10) DEFAULT NULL,
  `endpoint` VARCHAR(255) DEFAULT NULL,
  `registro_tipo` VARCHAR(80) DEFAULT NULL,
  `registro_id` VARCHAR(80) DEFAULT NULL,
  `resultado` ENUM('SUCESSO','NEGADO','ERRO') NOT NULL,
  `status_http` SMALLINT UNSIGNED DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `dados_antes` LONGTEXT DEFAULT NULL,
  `dados_depois` LONGTEXT DEFAULT NULL,
  `motivo` VARCHAR(500) DEFAULT NULL,
  `hash_anterior` CHAR(64) DEFAULT NULL,
  `hash_evento` CHAR(64) NOT NULL,
  `ocorrido_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rbac_auditoria_uuid` (`evento_uuid`),
  UNIQUE KEY `uk_rbac_auditoria_hash` (`hash_evento`),
  KEY `idx_rbac_auditoria_tenant_data` (`tenant_id`,`ocorrido_em`),
  KEY `idx_rbac_auditoria_usuario_data` (`usuario_id`,`ocorrido_em`),
  KEY `idx_rbac_auditoria_modulo_acao` (`modulo_chave`,`acao`),
  KEY `idx_rbac_auditoria_resultado` (`resultado`,`ocorrido_em`),
  CONSTRAINT `fk_rbac_auditoria_sessao` FOREIGN KEY (`sessao_id`) REFERENCES `rbac_sessoes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Políticas de endpoint permitem enforcement central por script/ação.
CREATE TABLE IF NOT EXISTS `rbac_politicas_api` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `endpoint` VARCHAR(120) NOT NULL,
  `acao_requisicao` VARCHAR(80) NOT NULL DEFAULT '',
  `modulo_chave` VARCHAR(80) NOT NULL,
  `acao_permissao` VARCHAR(32) NOT NULL DEFAULT 'visualizar',
  `metodo_http` VARCHAR(10) DEFAULT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_rbac_politica` (`endpoint`,`acao_requisicao`,`metodo_http`),
  KEY `idx_rbac_politica_modulo` (`modulo_chave`,`acao_permissao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- 5. GRUPOS DE COMPATIBILIDADE E MIGRAÇÃO DOS USUÁRIOS ATUAIS
-- --------------------------------------------------------------------------
INSERT IGNORE INTO `rbac_configuracoes` (`tenant_id`,`revisao_permissoes`)
SELECT id,1 FROM tenants;

INSERT IGNORE INTO `rbac_grupos` (`tenant_id`,`slug`,`nome`,`descricao`,`ativo`,`protegido`)
SELECT t.id,'compat-visualizador','Visualizador (compatibilidade)','Grupo criado a partir do perfil legado visualizador',1,1 FROM tenants t
UNION ALL SELECT t.id,'compat-operador','Operador (compatibilidade)','Grupo criado a partir do perfil legado operador',1,1 FROM tenants t
UNION ALL SELECT t.id,'compat-gerente','Gerente (compatibilidade)','Grupo criado a partir do perfil legado gerente',1,1 FROM tenants t
UNION ALL SELECT t.id,'compat-admin','Administrador (compatibilidade)','Grupo criado a partir do perfil legado admin',1,1 FROM tenants t;

-- Admin recebe todas as permissões existentes; grupos legados recebem permissões
-- equivalentes ao comportamento anterior, sem exclusão automática.
INSERT IGNORE INTO `rbac_grupo_permissoes` (`grupo_id`,`permissao_id`,`efeito`,`escopo_dados`)
SELECT g.id,p.id,'PERMITIR','GLOBAL'
FROM rbac_grupos g CROSS JOIN rbac_permissoes p
WHERE g.slug='compat-admin' AND g.ativo=1;

INSERT IGNORE INTO `rbac_grupo_permissoes` (`grupo_id`,`permissao_id`,`efeito`,`escopo_dados`)
SELECT g.id,p.id,'PERMITIR','GLOBAL'
FROM rbac_grupos g
INNER JOIN rbac_permissoes p ON p.acao IN ('visualizar','criar','editar','exportar','imprimir')
INNER JOIN rbac_modulos m ON m.id=p.modulo_id
WHERE g.slug='compat-gerente' AND m.perfil_compatibilidade IN ('visualizador','operador','gerente');

-- Compatibilidade com a leitura atual da Central de Usuários, que permite
-- consulta a gerentes sem conceder alteração de segurança.
INSERT IGNORE INTO `rbac_grupo_permissoes` (`grupo_id`,`permissao_id`,`efeito`,`escopo_dados`)
SELECT g.id,p.id,'PERMITIR','GLOBAL'
FROM rbac_grupos g
INNER JOIN rbac_modulos m ON m.chave='usuarios'
INNER JOIN rbac_permissoes p ON p.modulo_id=m.id AND p.acao='visualizar'
WHERE g.slug='compat-gerente';

INSERT IGNORE INTO `rbac_grupo_permissoes` (`grupo_id`,`permissao_id`,`efeito`,`escopo_dados`)
SELECT g.id,p.id,'PERMITIR','GLOBAL'
FROM rbac_grupos g
INNER JOIN rbac_permissoes p ON p.acao IN ('visualizar','criar','editar','exportar','imprimir')
INNER JOIN rbac_modulos m ON m.id=p.modulo_id
WHERE g.slug='compat-operador' AND m.perfil_compatibilidade IN ('visualizador','operador');

INSERT IGNORE INTO `rbac_grupo_permissoes` (`grupo_id`,`permissao_id`,`efeito`,`escopo_dados`)
SELECT g.id,p.id,'PERMITIR','GLOBAL'
FROM rbac_grupos g
INNER JOIN rbac_permissoes p ON p.acao='visualizar'
INNER JOIN rbac_modulos m ON m.id=p.modulo_id
WHERE g.slug='compat-visualizador' AND m.perfil_compatibilidade='visualizador';

-- Usuários são associados no tenant por usuario_tenant. Super Admin não depende
-- deste grupo: é tratado de forma global pelo helper e todas as ações são auditadas.
INSERT IGNORE INTO `rbac_usuario_grupos` (`usuario_id`,`tenant_id`,`grupo_id`,`ativo`)
SELECT ut.usuario_id,ut.tenant_id,g.id,1
FROM usuario_tenant ut
INNER JOIN usuarios u ON u.id=ut.usuario_id
INNER JOIN rbac_grupos g ON g.tenant_id=ut.tenant_id
 AND g.slug=CONCAT('compat-', CASE WHEN u.permissao IN ('visualizador','operador','gerente','admin') THEN u.permissao ELSE 'operador' END)
WHERE ut.ativo=1;

-- Compatibilidade adicional para bases em que o vínculo operacional já esteja
-- gravado diretamente em usuarios.tenant_id.
INSERT IGNORE INTO `rbac_usuario_grupos` (`usuario_id`,`tenant_id`,`grupo_id`,`ativo`)
SELECT u.id,u.tenant_id,g.id,1
FROM usuarios u
INNER JOIN rbac_grupos g ON g.tenant_id=u.tenant_id
 AND g.slug=CONCAT('compat-', CASE WHEN u.permissao IN ('visualizador','operador','gerente','admin') THEN u.permissao ELSE 'operador' END)
WHERE u.tenant_id IS NOT NULL AND u.tenant_id > 0 AND COALESCE(u.ativo,1)=1;

-- Migra permissões individuais legadas quando usuario_modulos e tenant_id existirem.
DROP PROCEDURE IF EXISTS `sp_rbac_migrar_usuario_modulos`;
DELIMITER $$
CREATE PROCEDURE `sp_rbac_migrar_usuario_modulos`()
BEGIN
  DECLARE coluna_tenant INT DEFAULT 0;
  SELECT COUNT(*) INTO coluna_tenant FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuario_modulos' AND COLUMN_NAME='tenant_id';
  IF coluna_tenant > 0 THEN
    INSERT IGNORE INTO rbac_usuario_permissoes (usuario_id,tenant_id,permissao_id,efeito,escopo_dados,motivo)
    SELECT um.usuario_id,um.tenant_id,p.id,'PERMITIR','GLOBAL','Migração de usuario_modulos'
    FROM usuario_modulos um
    INNER JOIN rbac_modulos m ON m.chave=um.modulo_chave
    INNER JOIN rbac_permissoes p ON p.modulo_id=m.id
    WHERE (p.acao='visualizar' AND um.pode_acessar=1)
       OR (p.acao='criar' AND um.pode_criar=1)
       OR (p.acao='editar' AND um.pode_editar=1)
       OR (p.acao='excluir' AND um.pode_excluir=1)
       OR (p.acao='exportar' AND um.pode_exportar=1);
  ELSE
    INSERT IGNORE INTO rbac_usuario_permissoes (usuario_id,tenant_id,permissao_id,efeito,escopo_dados,motivo)
    SELECT um.usuario_id,ut.tenant_id,p.id,'PERMITIR','GLOBAL','Migração de usuario_modulos sem tenant histórico'
    FROM usuario_modulos um
    INNER JOIN usuario_tenant ut ON ut.usuario_id=um.usuario_id AND ut.ativo=1
    INNER JOIN rbac_modulos m ON m.chave=um.modulo_chave
    INNER JOIN rbac_permissoes p ON p.modulo_id=m.id
    WHERE (p.acao='visualizar' AND um.pode_acessar=1)
       OR (p.acao='criar' AND um.pode_criar=1)
       OR (p.acao='editar' AND um.pode_editar=1)
       OR (p.acao='excluir' AND um.pode_excluir=1)
       OR (p.acao='exportar' AND um.pode_exportar=1);
  END IF;
END$$
DELIMITER ;
CALL sp_rbac_migrar_usuario_modulos();
DROP PROCEDURE IF EXISTS `sp_rbac_migrar_usuario_modulos`;

-- Políticas prioritárias inicialmente cobertas pelo RBAC real.
INSERT INTO `rbac_politicas_api` (`endpoint`,`acao_requisicao`,`modulo_chave`,`acao_permissao`,`metodo_http`,`ativo`) VALUES
('api_usuarios.php','','usuarios','visualizar','GET',1),
('api_usuarios.php','','usuarios','criar','POST',1),
('api_usuarios.php','','usuarios','editar','PUT',1),
('api_usuarios.php','','usuarios','bloquear','PATCH',1),
('api_usuarios.php','','usuarios','excluir','DELETE',1),
('api_permissoes_modulos.php','listar_modulos','usuarios','visualizar','GET',1),
('api_permissoes_modulos.php','permissoes_usuario','usuarios','visualizar','GET',1),
('api_permissoes_modulos.php','salvar_permissoes','usuarios','configurar','POST',1),
('api_permissoes_modulos.php','resetar_para_perfil','usuarios','configurar','POST',1),
('api_admin_sessoes.php','','admin_sessoes','visualizar','GET',1),
('api_admin_sessoes.php','','admin_sessoes','executar','POST',1),
('api_logs_sistema.php','','auditoria','visualizar','GET',1)
ON DUPLICATE KEY UPDATE modulo_chave=VALUES(modulo_chave),acao_permissao=VALUES(acao_permissao),ativo=VALUES(ativo);

SET FOREIGN_KEY_CHECKS = 1;

-- FIM DA MIGRATION
