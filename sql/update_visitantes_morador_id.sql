-- =====================================================
-- ATUALIZAÇÃO DA TABELA VISITANTES
-- Adicionar campo morador_id para vincular visitantes aos moradores
-- =====================================================

-- Adicionar coluna morador_id na tabela visitantes
ALTER TABLE `visitantes` 
ADD COLUMN `morador_id` INT(11) NULL AFTER `id`,
ADD INDEX `idx_morador_id` (`morador_id`);

-- Adicionar chave estrangeira (opcional, para integridade referencial)
ALTER TABLE `visitantes`
ADD CONSTRAINT `fk_visitantes_morador`
FOREIGN KEY (`morador_id`) REFERENCES `moradores`(`id`)
ON DELETE CASCADE
ON UPDATE CASCADE;

-- Comentário na coluna
ALTER TABLE `visitantes` 
MODIFY COLUMN `morador_id` INT(11) NULL COMMENT 'ID do morador que cadastrou o visitante';
