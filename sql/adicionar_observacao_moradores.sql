-- =====================================================
-- ADICIONAR COLUNA OBSERVACAO NA TABELA MORADORES
-- =====================================================

-- Verificar se a coluna já existe e adicionar se não existir
ALTER TABLE moradores 
ADD COLUMN IF NOT EXISTS observacao TEXT COMMENT 'Observações adicionais do morador';

-- Confirmar que a coluna foi adicionada
SHOW COLUMNS FROM moradores LIKE 'observacao';

-- =====================================================
-- FIM DO SCRIPT
-- =====================================================
