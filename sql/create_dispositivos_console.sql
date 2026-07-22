-- =====================================================
-- TABELA DE DISPOSITIVOS AUTORIZADOS PARA CONSOLE
-- =====================================================

CREATE TABLE IF NOT EXISTS `dispositivos_console` (
  `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
  `nome_dispositivo` VARCHAR(200) NOT NULL COMMENT 'Nome identificador do dispositivo',
  `token_acesso` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Token simples de acesso (6-8 caracteres)',
  `tipo_dispositivo` ENUM('tablet', 'smartphone', 'outro') DEFAULT 'tablet',
  `localizacao` VARCHAR(200) NULL COMMENT 'Localização física do dispositivo',
  `responsavel` VARCHAR(200) NULL COMMENT 'Nome do responsável pelo dispositivo',
  `user_agent` TEXT NULL COMMENT 'User agent do navegador',
  `ip_cadastro` VARCHAR(45) NULL COMMENT 'IP no momento do cadastro',
  `ip_ultimo_acesso` VARCHAR(45) NULL COMMENT 'IP do último acesso',
  `data_ultimo_acesso` DATETIME NULL COMMENT 'Data e hora do último acesso',
  `total_acessos` INT(11) DEFAULT 0 COMMENT 'Total de acessos realizados',
  `ativo` TINYINT(1) DEFAULT 1 COMMENT '1=Ativo, 0=Inativo',
  `observacao` TEXT NULL,
  `data_cadastro` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  INDEX `idx_token_acesso` (`token_acesso`),
  INDEX `idx_ativo` (`ativo`),
  INDEX `idx_data_ultimo_acesso` (`data_ultimo_acesso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dispositivos autorizados para acessar o console';

-- =====================================================
-- INSERIR DISPOSITIVO PADRÃO PARA TESTES
-- =====================================================

INSERT INTO `dispositivos_console` 
(nome_dispositivo, token_acesso, tipo_dispositivo, localizacao, responsavel, ativo)
VALUES 
('Tablet Portaria Principal', 'PORT001', 'tablet', 'Portaria Principal', 'Equipe de Segurança', 1);

-- =====================================================
-- ATUALIZAR TABELA DE VALIDAÇÕES DE ACESSO
-- Adicionar referência ao dispositivo
-- =====================================================

ALTER TABLE `validacoes_acesso`
ADD COLUMN `dispositivo_id` INT(11) NULL COMMENT 'ID do dispositivo que realizou a validação' AFTER `console_usuario`,
ADD INDEX `idx_dispositivo_id` (`dispositivo_id`),
ADD CONSTRAINT `fk_validacoes_dispositivo` 
  FOREIGN KEY (`dispositivo_id`) 
  REFERENCES `dispositivos_console` (`id`) 
  ON DELETE SET NULL;
