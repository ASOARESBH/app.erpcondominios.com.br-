-- ============================================
-- SQL para Criar Tabelas do Portal do Morador
-- Sistema de Controle de Acesso - Serra da Liberdade
-- ============================================

-- Tabela: sessoes_portal (OBRIGATÓRIA)
CREATE TABLE IF NOT EXISTS `sessoes_portal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `morador_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `data_login` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_expiracao` datetime NOT NULL,
  `ativo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `morador_id` (`morador_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Adicionar campos em moradores (se não existirem)
ALTER TABLE `moradores` 
ADD COLUMN IF NOT EXISTS `senha` VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `ultimo_acesso` DATETIME DEFAULT NULL;

-- Tabela: hidrometro (OPCIONAL - para funcionalidade de hidrometro)
CREATE TABLE IF NOT EXISTS `hidrometro` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `unidade_id` int(11) NOT NULL,
  `numero_hidrometro` varchar(50) NOT NULL,
  `localizacao` varchar(255) DEFAULT NULL,
  `data_instalacao` date DEFAULT NULL,
  `status` enum('ativo','inativo','manutencao') DEFAULT 'ativo',
  `observacoes` text DEFAULT NULL,
  `data_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_hidrometro` (`numero_hidrometro`),
  KEY `unidade_id` (`unidade_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela: lancamentos_agua (OPCIONAL - para funcionalidade de hidrometro)
CREATE TABLE IF NOT EXISTS `lancamentos_agua` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hidrometro_id` int(11) NOT NULL,
  `unidade_id` int(11) NOT NULL,
  `mes_referencia` varchar(7) NOT NULL COMMENT 'Formato: YYYY-MM',
  `leitura_anterior` decimal(10,2) NOT NULL,
  `leitura_atual` decimal(10,2) NOT NULL,
  `consumo` decimal(10,2) GENERATED ALWAYS AS (`leitura_atual` - `leitura_anterior`) STORED,
  `valor_m3` decimal(10,2) DEFAULT NULL,
  `valor_total` decimal(10,2) DEFAULT NULL,
  `data_leitura` date NOT NULL,
  `data_vencimento` date DEFAULT NULL,
  `status_pagamento` enum('pendente','pago','atrasado') DEFAULT 'pendente',
  `data_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hidrometro_mes` (`hidrometro_id`,`mes_referencia`),
  KEY `unidade_id` (`unidade_id`),
  KEY `hidrometro_id` (`hidrometro_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================
-- Dados de Exemplo (OPCIONAL)
-- ============================================

-- Exemplo de hidrometro
INSERT IGNORE INTO `hidrometro` (`id`, `unidade_id`, `numero_hidrometro`, `localizacao`, `data_instalacao`, `status`) VALUES
(1, 1, 'HID-001', 'Entrada principal', '2024-01-01', 'ativo');

-- Exemplo de lançamento de água
INSERT IGNORE INTO `lancamentos_agua` (`hidrometro_id`, `unidade_id`, `mes_referencia`, `leitura_anterior`, `leitura_atual`, `valor_m3`, `valor_total`, `data_leitura`, `data_vencimento`, `status_pagamento`) VALUES
(1, 1, '2025-10', 1000.00, 1015.00, 5.50, 82.50, '2025-10-15', '2025-10-25', 'pendente');

-- ============================================
-- Verificação Final
-- ============================================

-- Verificar se as tabelas foram criadas
SHOW TABLES LIKE 'sessoes_portal';
SHOW TABLES LIKE 'hidrometro';
SHOW TABLES LIKE 'lancamentos_agua';

-- Verificar campos em moradores
DESCRIBE moradores;

