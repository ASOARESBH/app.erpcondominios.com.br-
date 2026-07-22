-- =====================================================
-- ATUALIZAÇÃO: Adicionar Campos de Origem do Lançamento
-- VERSÃO SEGURA - Ignora erros se campos já existirem
-- Sistema ERP Serra da Liberdade - Hidrômetros
-- =====================================================

-- Desabilitar verificação de erros temporariamente
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='';

-- Adicionar campos (ignora se já existir)
ALTER TABLE leituras ADD COLUMN lancamento_manual TINYINT(1) DEFAULT 0 COMMENT 'Se foi lançamento manual pelo morador';
ALTER TABLE leituras ADD COLUMN lancado_por_tipo ENUM('sistema', 'morador') DEFAULT 'sistema' COMMENT 'Tipo de usuário que fez o lançamento';
ALTER TABLE leituras ADD COLUMN lancado_por_id INT NULL COMMENT 'ID do usuário ou morador que fez o lançamento';
ALTER TABLE leituras ADD COLUMN lancado_por_nome VARCHAR(255) NULL COMMENT 'Nome de quem fez o lançamento';
ALTER TABLE leituras ADD COLUMN ip_lancamento VARCHAR(45) NULL COMMENT 'IP de onde foi feito o lançamento';
ALTER TABLE leituras ADD COLUMN requer_validacao TINYINT(1) DEFAULT 0 COMMENT 'Se lançamento manual precisa de validação';
ALTER TABLE leituras ADD COLUMN validado TINYINT(1) DEFAULT 1 COMMENT 'Se já foi validado';
ALTER TABLE leituras ADD COLUMN validado_por VARCHAR(255) NULL COMMENT 'Quem validou o lançamento';
ALTER TABLE leituras ADD COLUMN data_validacao DATETIME NULL COMMENT 'Data da validação';

-- Restaurar SQL_MODE
SET SQL_MODE=@OLD_SQL_MODE;

-- Criar índices (ignora se já existir)
CREATE INDEX IF NOT EXISTS idx_lancamento_manual ON leituras(lancamento_manual);
CREATE INDEX IF NOT EXISTS idx_lancado_por_tipo ON leituras(lancado_por_tipo);
CREATE INDEX IF NOT EXISTS idx_requer_validacao ON leituras(requer_validacao, validado);

-- Atualizar leituras existentes
UPDATE leituras 
SET lancamento_manual = COALESCE(lancamento_manual, 0),
    lancado_por_tipo = COALESCE(lancado_por_tipo, 'sistema'),
    validado = COALESCE(validado, 1)
WHERE lancamento_manual IS NULL OR lancado_por_tipo IS NULL OR validado IS NULL;

-- Verificar
SELECT 'Atualização concluída!' AS status;
DESCRIBE leituras;
