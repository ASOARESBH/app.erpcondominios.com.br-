-- =====================================================
-- TABELA DE QR CODES TEMPORÁRIOS PARA DELIVERY
-- =====================================================

CREATE TABLE IF NOT EXISTS `qrcodes_temporarios` (
  `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
  `qr_code` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Código único do QR Code',
  `token` VARCHAR(255) NOT NULL UNIQUE COMMENT 'Token de validação',
  `nome_entregador` VARCHAR(200) NULL COMMENT 'Nome do entregador',
  `empresa` VARCHAR(200) NULL COMMENT 'Empresa de delivery',
  `telefone` VARCHAR(20) NULL COMMENT 'Telefone do entregador',
  `placa` VARCHAR(10) NULL COMMENT 'Placa do veículo',
  `unidade_destino` VARCHAR(50) NULL COMMENT 'Unidade de destino',
  `hora_inicial` TIME NOT NULL COMMENT 'Hora de início do acesso',
  `hora_final` TIME NOT NULL COMMENT 'Hora de término do acesso',
  `data_acesso` DATE NOT NULL COMMENT 'Data do acesso',
  `tipo_acesso` ENUM('portaria', 'externo', 'lagoa') NOT NULL DEFAULT 'portaria',
  `usado` TINYINT(1) DEFAULT 0 COMMENT '0=Não usado, 1=Já usado',
  `data_uso` DATETIME NULL COMMENT 'Data e hora do uso',
  `ip_uso` VARCHAR(45) NULL COMMENT 'IP de onde foi usado',
  `ativo` TINYINT(1) DEFAULT 1 COMMENT '1=Ativo, 0=Inativo',
  `observacao` TEXT NULL,
  `data_cadastro` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  INDEX `idx_qr_code` (`qr_code`),
  INDEX `idx_token` (`token`),
  INDEX `idx_data_acesso` (`data_acesso`),
  INDEX `idx_usado` (`usado`),
  INDEX `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='QR Codes temporários para delivery e acessos rápidos';

-- =====================================================
-- ATUALIZAR TABELA DE ACESSOS VISITANTES
-- Adicionar campo para QR Code temporário
-- =====================================================

ALTER TABLE `acessos_visitantes`
ADD COLUMN `temporario` TINYINT(1) DEFAULT 0 COMMENT '0=Normal, 1=Temporário (delivery)' AFTER `tipo_visitante`,
ADD COLUMN `hora_inicial` TIME NULL COMMENT 'Hora inicial (para temporários)' AFTER `data_final`,
ADD COLUMN `hora_final` TIME NULL COMMENT 'Hora final (para temporários)' AFTER `hora_inicial`,
ADD COLUMN `token_acesso` VARCHAR(255) NULL UNIQUE COMMENT 'Token único para validação' AFTER `qr_code`;

-- Adicionar índices
ALTER TABLE `acessos_visitantes`
ADD INDEX `idx_temporario` (`temporario`),
ADD INDEX `idx_token_acesso` (`token_acesso`);

-- =====================================================
-- TABELA DE VALIDAÇÕES DE ACESSO
-- Registra cada validação de QR Code
-- =====================================================

CREATE TABLE IF NOT EXISTS `validacoes_acesso` (
  `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
  `tipo_validacao` ENUM('visitante', 'temporario', 'morador') NOT NULL COMMENT 'Tipo de validação',
  `acesso_id` INT(11) NULL COMMENT 'ID do acesso de visitante',
  `qrcode_temporario_id` INT(11) NULL COMMENT 'ID do QR Code temporário',
  `qr_code` VARCHAR(255) NOT NULL COMMENT 'Código QR validado',
  `token` VARCHAR(255) NULL COMMENT 'Token validado',
  `resultado` ENUM('permitido', 'negado') NOT NULL COMMENT 'Resultado da validação',
  `motivo` VARCHAR(255) NULL COMMENT 'Motivo (se negado)',
  `data_hora` DATETIME NOT NULL COMMENT 'Data e hora da validação',
  `ip_validacao` VARCHAR(45) NULL COMMENT 'IP de onde foi validado',
  `user_agent` TEXT NULL COMMENT 'User agent do dispositivo',
  `console_usuario` VARCHAR(100) NULL COMMENT 'Usuário do console',
  `observacao` TEXT NULL,
  
  INDEX `idx_tipo_validacao` (`tipo_validacao`),
  INDEX `idx_resultado` (`resultado`),
  INDEX `idx_data_hora` (`data_hora`),
  INDEX `idx_qr_code` (`qr_code`),
  
  FOREIGN KEY (`acesso_id`) REFERENCES `acessos_visitantes` (`id`) ON DELETE SET NULL,
  FOREIGN KEY (`qrcode_temporario_id`) REFERENCES `qrcodes_temporarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Histórico de validações de QR Code';
