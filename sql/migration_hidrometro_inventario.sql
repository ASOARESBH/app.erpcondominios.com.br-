-- =====================================================
-- MIGRAÇÃO: Vinculação Hidrômetro ↔ Inventário
-- =====================================================
-- Execute este script no banco de dados para adicionar
-- o campo inventario_id na tabela hidrometros.
-- =====================================================

-- 1. Adicionar coluna inventario_id na tabela hidrometros
ALTER TABLE `hidrometros`
    ADD COLUMN `inventario_id` INT(11) NULL DEFAULT NULL
        COMMENT 'Referência ao item no inventário/patrimônio'
        AFTER `numero_lacre`;

-- 2. Adicionar índice para performance
ALTER TABLE `hidrometros`
    ADD INDEX `idx_inventario_id` (`inventario_id`);

-- 3. Adicionar chave estrangeira (opcional — remova se preferir sem FK)
-- ALTER TABLE `hidrometros`
--     ADD CONSTRAINT `fk_hidrometro_inventario`
--     FOREIGN KEY (`inventario_id`) REFERENCES `inventario` (`id`)
--     ON DELETE SET NULL ON UPDATE CASCADE;

-- =====================================================
-- MIGRAÇÃO: Grupos de Inventário (se ainda não executado)
-- =====================================================
-- Execute também o arquivo: database_grupos_inventario.sql
-- para criar a tabela grupos_inventario e adicionar
-- grupo_id na tabela inventario.
-- =====================================================
