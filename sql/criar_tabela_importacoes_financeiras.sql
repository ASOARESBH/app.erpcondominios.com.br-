-- ============================================================
-- TABELA: importacoes_financeiras
-- Armazena os lotes de importação de arquivos CSV/PDF externos
-- ============================================================
CREATE TABLE IF NOT EXISTS `importacoes_financeiras` (
  `id`                int(11) NOT NULL AUTO_INCREMENT,
  `nome_arquivo`      varchar(255) NOT NULL COMMENT 'Nome original do arquivo importado',
  `tipo_arquivo`      enum('CSV','PDF') NOT NULL DEFAULT 'CSV',
  `tipo_conta`        enum('PAGAR','RECEBER') NOT NULL DEFAULT 'PAGAR',
  `data_inicio`       date DEFAULT NULL COMMENT 'Filtro: data inicial do periodo',
  `data_fim`          date DEFAULT NULL COMMENT 'Filtro: data final do periodo',
  `total_registros`   int(11) DEFAULT 0,
  `total_importados`  int(11) DEFAULT 0,
  `total_duplicatas`  int(11) DEFAULT 0,
  `total_erros`       int(11) DEFAULT 0,
  `status`            enum('PROCESSANDO','CONCLUIDO','ERRO') DEFAULT 'PROCESSANDO',
  `usuario`           varchar(100) DEFAULT NULL,
  `data_importacao`   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lotes de importação de arquivos financeiros externos';

-- ============================================================
-- TABELA: importacoes_financeiras_itens
-- Armazena cada linha importada e seu status de conciliação
-- ============================================================
CREATE TABLE IF NOT EXISTS `importacoes_financeiras_itens` (
  `id`                    int(11) NOT NULL AUTO_INCREMENT,
  `importacao_id`         int(11) NOT NULL,
  `linha_original`        int(11) DEFAULT NULL COMMENT 'Numero da linha no arquivo',
  `numero_documento`      varchar(100) DEFAULT NULL,
  `fornecedor_nome`       varchar(255) DEFAULT NULL,
  `classificacao_despesa` varchar(255) DEFAULT NULL,
  `centro_custo`          varchar(100) DEFAULT NULL,
  `observacao`            text DEFAULT NULL,
  `valor`                 decimal(12,2) DEFAULT NULL,
  `data_vencimento`       date DEFAULT NULL,
  `data_pagamento`        date DEFAULT NULL,
  `status_original`       varchar(50) DEFAULT NULL COMMENT 'Status no arquivo (Pago, Pendente...)',
  `aprovada`              tinyint(1) DEFAULT 0,
  `status_importacao`     enum('PENDENTE','IMPORTADO','DUPLICATA','ERRO','CONCILIADO') DEFAULT 'PENDENTE',
  `conta_id`              int(11) DEFAULT NULL COMMENT 'ID da conta criada em contas_pagar/receber',
  `duplicata_conta_id`    int(11) DEFAULT NULL COMMENT 'ID da conta duplicada detectada',
  `mensagem_erro`         text DEFAULT NULL,
  `conciliado_por`        varchar(100) DEFAULT NULL,
  `data_conciliacao`      timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_importacao_id` (`importacao_id`),
  CONSTRAINT `fk_imp_itens_lote` FOREIGN KEY (`importacao_id`) REFERENCES `importacoes_financeiras` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Itens individuais de cada lote de importação financeira';
