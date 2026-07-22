-- =====================================================
-- ATUALIZAÇÃO DA TABELA DE ACESSOS DE VISITANTES
-- Adiciona campos para integração com controle de acesso
-- =====================================================

-- Adicionar campos de veículo
ALTER TABLE `acessos_visitantes` 
ADD COLUMN `placa` VARCHAR(10) NULL COMMENT 'Placa do veículo' AFTER `tipo_acesso`,
ADD COLUMN `modelo` VARCHAR(100) NULL COMMENT 'Modelo do veículo' AFTER `placa`,
ADD COLUMN `cor` VARCHAR(50) NULL COMMENT 'Cor do veículo' AFTER `modelo`;

-- Adicionar campo de tipo de visitante
ALTER TABLE `acessos_visitantes`
ADD COLUMN `tipo_visitante` ENUM('visitante', 'prestador') NOT NULL DEFAULT 'visitante' COMMENT 'Tipo: visitante ou prestador' AFTER `cor`;

-- Adicionar campo de morador responsável
ALTER TABLE `acessos_visitantes`
ADD COLUMN `morador_id` INT(11) NULL COMMENT 'ID do morador responsável' AFTER `tipo_visitante`;

-- Adicionar campo de unidade destino
ALTER TABLE `acessos_visitantes`
ADD COLUMN `unidade_destino` VARCHAR(50) NULL COMMENT 'Unidade de destino' AFTER `morador_id`;

-- Adicionar campo para vincular com registro de acesso
ALTER TABLE `acessos_visitantes`
ADD COLUMN `registro_acesso_id` INT(11) NULL COMMENT 'ID do registro de acesso' AFTER `unidade_destino`;

-- Adicionar índices
ALTER TABLE `acessos_visitantes`
ADD INDEX `idx_morador_id` (`morador_id`),
ADD INDEX `idx_tipo_visitante` (`tipo_visitante`),
ADD INDEX `idx_placa` (`placa`),
ADD INDEX `idx_registro_acesso` (`registro_acesso_id`);

-- Adicionar foreign key para morador (se não existir)
ALTER TABLE `acessos_visitantes`
ADD CONSTRAINT `fk_acessos_morador` 
  FOREIGN KEY (`morador_id`) 
  REFERENCES `moradores` (`id`) 
  ON DELETE SET NULL 
  ON UPDATE CASCADE;

-- Adicionar foreign key para registro de acesso (se não existir)
ALTER TABLE `acessos_visitantes`
ADD CONSTRAINT `fk_acessos_registro` 
  FOREIGN KEY (`registro_acesso_id`) 
  REFERENCES `registros_acesso` (`id`) 
  ON DELETE SET NULL 
  ON UPDATE CASCADE;

-- Comentário da tabela atualizado
ALTER TABLE `acessos_visitantes` 
COMMENT = 'Controle de acessos de visitantes com QR Code e integração com controle de acesso';
