-- =====================================================
-- ATUALIZAÇÃO: Adicionar Campos de Origem do Lançamento
-- Sistema ERP Serra da Liberdade - Hidrômetros
-- Compatível com MariaDB/MySQL
-- =====================================================

-- Adicionar campos para identificar origem do lançamento
-- Nota: Se algum campo já existir, comente a linha correspondente

ALTER TABLE leituras 
ADD COLUMN lancamento_manual TINYINT(1) DEFAULT 0 COMMENT 'Se foi lançamento manual pelo morador (0=sistema, 1=morador)';

ALTER TABLE leituras 
ADD COLUMN lancado_por_tipo ENUM('sistema', 'morador') DEFAULT 'sistema' COMMENT 'Tipo de usuário que fez o lançamento';

ALTER TABLE leituras 
ADD COLUMN lancado_por_id INT NULL COMMENT 'ID do usuário ou morador que fez o lançamento';

ALTER TABLE leituras 
ADD COLUMN lancado_por_nome VARCHAR(255) NULL COMMENT 'Nome de quem fez o lançamento';

ALTER TABLE leituras 
ADD COLUMN ip_lancamento VARCHAR(45) NULL COMMENT 'IP de onde foi feito o lançamento';

ALTER TABLE leituras 
ADD COLUMN requer_validacao TINYINT(1) DEFAULT 0 COMMENT 'Se lançamento manual precisa de validação pela administração';

ALTER TABLE leituras 
ADD COLUMN validado TINYINT(1) DEFAULT 1 COMMENT 'Se já foi validado (1=sim, 0=não)';

ALTER TABLE leituras 
ADD COLUMN validado_por VARCHAR(255) NULL COMMENT 'Quem validou o lançamento';

ALTER TABLE leituras 
ADD COLUMN data_validacao DATETIME NULL COMMENT 'Data da validação';

-- Criar índices para melhor performance
CREATE INDEX idx_lancamento_manual ON leituras(lancamento_manual);
CREATE INDEX idx_lancado_por_tipo ON leituras(lancado_por_tipo);
CREATE INDEX idx_requer_validacao ON leituras(requer_validacao, validado);

-- Atualizar leituras existentes como lançadas pelo sistema
UPDATE leituras 
SET lancamento_manual = 0,
    lancado_por_tipo = 'sistema',
    validado = 1
WHERE lancamento_manual IS NULL OR lancamento_manual = 0;

-- Verificar estrutura atualizada
DESCRIBE leituras;

-- Contar leituras por tipo
SELECT 
    lancado_por_tipo,
    COUNT(*) as total,
    SUM(CASE WHEN lancamento_manual = 1 THEN 1 ELSE 0 END) as manuais,
    SUM(CASE WHEN validado = 0 THEN 1 ELSE 0 END) as pendentes_validacao
FROM leituras
GROUP BY lancado_por_tipo;

-- =====================================================
-- MENSAGEM DE SUCESSO
-- =====================================================
SELECT 'Campos adicionados com sucesso! Estrutura atualizada.' AS status;
