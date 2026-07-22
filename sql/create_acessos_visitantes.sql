-- =====================================================
-- TABELA DE ACESSOS DE VISITANTES
-- Controle de período de permanência e tipos de acesso
-- =====================================================

CREATE TABLE IF NOT EXISTS `acessos_visitantes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `visitante_id` INT(11) NOT NULL COMMENT 'ID do visitante',
  `data_inicial` DATE NOT NULL COMMENT 'Data inicial do acesso',
  `data_final` DATE NOT NULL COMMENT 'Data final do acesso',
  `dias_permanencia` INT(11) NOT NULL COMMENT 'Dias de permanência (calculado)',
  `tipo_acesso` ENUM('portaria', 'externo', 'lagoa') NOT NULL COMMENT 'Tipo de acesso permitido',
  `qr_code` VARCHAR(255) NOT NULL COMMENT 'Código único para QR Code',
  `qr_code_imagem` TEXT NULL COMMENT 'QR Code em base64',
  `ativo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Ativo, 0=Inativo',
  `data_cadastro` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `qr_code` (`qr_code`),
  KEY `idx_visitante_id` (`visitante_id`),
  KEY `idx_data_inicial` (`data_inicial`),
  KEY `idx_data_final` (`data_final`),
  KEY `idx_qr_code` (`qr_code`),
  KEY `idx_ativo` (`ativo`),
  CONSTRAINT `fk_acessos_visitante` 
    FOREIGN KEY (`visitante_id`) 
    REFERENCES `visitantes` (`id`) 
    ON DELETE CASCADE 
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Controle de acessos de visitantes com QR Code';

-- Índice composto para consultas de período
CREATE INDEX `idx_periodo_acesso` ON `acessos_visitantes` (`data_inicial`, `data_final`, `ativo`);

-- Índice para validação de QR Code
CREATE INDEX `idx_qr_validacao` ON `acessos_visitantes` (`qr_code`, `ativo`, `data_inicial`, `data_final`);
