-- ============================================================================
-- ERP Condomínios — Módulo Financeiro de Inadimplência
-- MySQL / MariaDB 5.7 — seguro para executar mais de uma vez
-- As tabelas também são verificadas pela API, mas esta migration deve ser
-- executada antes do primeiro uso em produção.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `inadimplencia_importacoes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `arquivo_id` int(11) DEFAULT NULL,
  `nome_arquivo` varchar(255) NOT NULL,
  `associacao_nome` varchar(255) DEFAULT NULL,
  `data_base` date DEFAULT NULL,
  `data_geracao_relatorio` datetime DEFAULT NULL,
  `indicador_correcao` varchar(100) DEFAULT NULL,
  `indicador_juros_pct` decimal(7,2) DEFAULT NULL,
  `indicador_multa_pct` decimal(7,2) DEFAULT NULL,
  `quantidade_unidades` int(11) NOT NULL DEFAULT 0,
  `total_lancado` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_projetado` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_lancado_relatorio` decimal(14,2) DEFAULT NULL,
  `total_projetado_relatorio` decimal(14,2) DEFAULT NULL,
  `totais_reconciliam` tinyint(1) NOT NULL DEFAULT 1,
  `alerta_reconciliacao` varchar(500) DEFAULT NULL,
  `total_lancamentos` int(11) NOT NULL DEFAULT 0,
  `total_sem_vinculo` int(11) NOT NULL DEFAULT 0,
  `status` enum('PROCESSANDO','CONCLUIDO','ERRO') NOT NULL DEFAULT 'PROCESSANDO',
  `mensagem_erro` text DEFAULT NULL,
  `usuario` varchar(100) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inad_tenant_data` (`tenant_id`,`data_base`),
  KEY `idx_inad_tenant_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inadimplencia_lancamentos` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `importacao_id` int(11) NOT NULL,
  `gleba_numero` varchar(20) NOT NULL,
  `carteira_status` varchar(160) DEFAULT NULL,
  `permite_receber` tinyint(1) NOT NULL DEFAULT 1,
  `participa_cobranca` tinyint(1) NOT NULL DEFAULT 1,
  `proprietario_nome` varchar(255) DEFAULT NULL,
  `proprietario_cpf` varchar(20) DEFAULT NULL,
  `proprietario_cpf_digitos` varchar(14) DEFAULT NULL,
  `identificador_lancamento` varchar(50) DEFAULT NULL,
  `chave_alternativa` varchar(255) DEFAULT NULL,
  `chave_comparacao` varchar(320) NOT NULL,
  `tipo_cobranca` varchar(80) DEFAULT NULL,
  `descricao_original` varchar(500) DEFAULT NULL,
  `mes_referencia` date DEFAULT NULL,
  `vencimento` date DEFAULT NULL,
  `valor` decimal(12,2) NOT NULL DEFAULT 0.00,
  `juros` decimal(12,2) NOT NULL DEFAULT 0.00,
  `multa` decimal(12,2) NOT NULL DEFAULT 0.00,
  `correcao` decimal(12,2) NOT NULL DEFAULT 0.00,
  `projecao_recebimento` decimal(12,2) NOT NULL DEFAULT 0.00,
  `morador_id` int(11) DEFAULT NULL,
  `unidade_id` int(11) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_inad_importacao` (`importacao_id`),
  KEY `idx_inad_tenant_gleba` (`tenant_id`,`gleba_numero`),
  KEY `idx_inad_tenant_chave` (`tenant_id`,`chave_comparacao`),
  KEY `idx_inad_morador` (`tenant_id`,`morador_id`),
  KEY `idx_inad_unidade` (`tenant_id`,`unidade_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registra a funcionalidade para instalações existentes. O INSERT IGNORE evita
-- duplicidade sem alterar outras permissões ou módulos.
INSERT IGNORE INTO `modulos_sistema`
  (`chave`,`nome`,`grupo`,`icone`,`descricao`,`permissao_minima`,`ordem`)
VALUES
  ('inadimplencia','Inadimplência','Financeiro','fas fa-user-clock','Análise histórica de inadimplência','gerente',45);

-- A concessão ao tenant deve seguir a política de módulos do ERP.
-- Super-Admin recebe acesso global conforme api_permissoes_modulos.php.
