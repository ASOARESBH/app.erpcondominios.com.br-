-- ============================================================================
-- MIGRATION: OBSERVAÇÃO NOS LANÇAMENTOS DE ABASTECIMENTO
-- Compatível com MySQL/MariaDB 5.7+
-- ============================================================================
-- A coluna é opcional para preservar todos os lançamentos existentes.
-- Execute no banco do ERP selecionado no phpMyAdmin.
-- ============================================================================

SET @db := DATABASE();

SET @tem_tabela := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'abastecimento_lancamentos'
);

SET @tem_observacao := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'abastecimento_lancamentos'
      AND COLUMN_NAME = 'observacao'
);

SET @sql := IF(
    @tem_tabela = 0,
    'SELECT ''ERRO: a tabela abastecimento_lancamentos não existe neste banco'' AS resultado',
    IF(
        @tem_observacao = 0,
        'ALTER TABLE `abastecimento_lancamentos` ADD COLUMN `observacao` TEXT NULL COMMENT ''Informações adicionais do abastecimento'' AFTER `usuario_logado`',
        'SELECT ''Coluna abastecimento_lancamentos.observacao já existe'' AS resultado'
    )
);

PREPARE stmt_observacao FROM @sql;
EXECUTE stmt_observacao;
DEALLOCATE PREPARE stmt_observacao;

SELECT
    TABLE_NAME AS tabela,
    COLUMN_NAME AS coluna,
    DATA_TYPE AS tipo,
    IS_NULLABLE AS aceita_nulo,
    ORDINAL_POSITION AS posicao
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'abastecimento_lancamentos'
  AND COLUMN_NAME = 'observacao';
