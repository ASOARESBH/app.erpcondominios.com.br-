-- =====================================================
-- MÓDULO DE CONTRATOS
-- =====================================================

-- Tabela principal de contratos
CREATE TABLE IF NOT EXISTS `contratos` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `numero_contrato`  VARCHAR(20)  NOT NULL UNIQUE,
  `fornecedor_id`    INT          DEFAULT NULL,
  `fornecedor_nome`  VARCHAR(255) NOT NULL,
  `fornecedor_cnpj`  VARCHAR(20)  DEFAULT NULL,
  `tipo_servico`     ENUM('prestacao_servico','venda') NOT NULL,
  `nome_contrato`    VARCHAR(255) NOT NULL,
  `data_inicio`      DATE         NOT NULL,
  `data_fim`         DATE         NOT NULL,
  `recorrencia`      ENUM('unica','mensal','anual','diaria') NOT NULL DEFAULT 'unica',
  `valor_total`      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `data_vencimento`  DATE         NOT NULL,
  `plano_conta_id`   INT          NOT NULL,
  `observacoes`      TEXT         DEFAULT NULL,
  `status`           ENUM('ativo','aguardando','encerrado','cancelado') NOT NULL DEFAULT 'ativo',
  `ativo`            TINYINT(1)   NOT NULL DEFAULT 1,
  `data_criacao`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status`      (`status`),
  INDEX `idx_fornecedor`  (`fornecedor_nome`),
  INDEX `idx_data_fim`    (`data_fim`),
  INDEX `idx_ativo`       (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar coluna contrato_id em contas_pagar
ALTER TABLE `contas_pagar`
  ADD COLUMN `contrato_id` INT DEFAULT NULL AFTER `observacoes`;
ALTER TABLE `contas_pagar`
  ADD INDEX `idx_contrato_id` (`contrato_id`);

-- Tabela de documentos do contrato
CREATE TABLE IF NOT EXISTS `contrato_documentos` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `contrato_id`     INT          NOT NULL,
  `nome_documento`  VARCHAR(255) NOT NULL,
  `tipo_documento`  ENUM('contrato','aditivo','ata','outros') NOT NULL DEFAULT 'outros',
  `nome_arquivo`    VARCHAR(255) NOT NULL,
  `url_arquivo`     VARCHAR(500) NOT NULL,
  `tamanho`         BIGINT       DEFAULT NULL,
  `mime_type`       VARCHAR(100) DEFAULT NULL,
  `ativo`           TINYINT(1)   NOT NULL DEFAULT 1,
  `data_upload`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_contrato_id` (`contrato_id`),
  INDEX `idx_ativo`       (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de orçamentos do contrato
CREATE TABLE IF NOT EXISTS `contrato_orcamentos` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `contrato_id`   INT           NOT NULL,
  `fornecedor`    VARCHAR(255)  NOT NULL,
  `descricao`     TEXT          NOT NULL,
  `valor`         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `justificativa` TEXT          DEFAULT NULL,
  `data_criacao`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_contrato_id` (`contrato_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de documentos vinculados a um orçamento
CREATE TABLE IF NOT EXISTS `contrato_orcamento_documentos` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `orcamento_id`    INT          NOT NULL,
  `nome_documento`  VARCHAR(255) NOT NULL,
  `tipo_documento`  VARCHAR(50)  NOT NULL,
  `nome_arquivo`    VARCHAR(255) NOT NULL,
  `url_arquivo`     VARCHAR(500) NOT NULL,
  `tamanho`         BIGINT       DEFAULT NULL,
  `mime_type`       VARCHAR(100) DEFAULT NULL,
  `ativo`           TINYINT(1)   NOT NULL DEFAULT 1,
  `data_upload`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_orcamento_id` (`orcamento_id`),
  INDEX `idx_ativo`        (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
