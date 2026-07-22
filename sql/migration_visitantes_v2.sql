-- ================================================================================
-- MIGRATION: visitantes v2
-- Adiciona: foto, documento_arquivo, telefone_contato, placa_veiculo
-- Remove dependência de unidade do cadastro base (unidade fica no acesso)
-- ================================================================================

-- 1. Adicionar campo foto do visitante
ALTER TABLE visitantes
    ADD COLUMN IF NOT EXISTS foto VARCHAR(500) NULL COMMENT 'Caminho da foto do visitante' AFTER observacao;

-- 2. Adicionar campo documento/arquivo anexo
ALTER TABLE visitantes
    ADD COLUMN IF NOT EXISTS documento_arquivo VARCHAR(500) NULL COMMENT 'Caminho do documento digitalizado' AFTER foto;

-- 3. Adicionar telefone de contato (além do telefone/celular já existentes)
ALTER TABLE visitantes
    ADD COLUMN IF NOT EXISTS telefone_contato VARCHAR(20) NULL COMMENT 'Telefone de contato principal' AFTER celular;

-- 4. Adicionar placa do veículo habitual do visitante
ALTER TABLE visitantes
    ADD COLUMN IF NOT EXISTS placa_veiculo VARCHAR(10) NULL COMMENT 'Placa do veículo habitual' AFTER telefone_contato;

-- 5. Garantir que tipo_documento seja ENUM com RG e CPF
-- (já existe na tabela, mas garantir o padrão)
ALTER TABLE visitantes
    MODIFY COLUMN tipo_documento ENUM('RG','CPF') NOT NULL DEFAULT 'CPF';

-- 6. Criar pasta de uploads se não existir (executar no servidor)
-- mkdir -p public_html/uploads/visitantes/fotos
-- mkdir -p public_html/uploads/visitantes/documentos

-- 7. Índice para busca por placa
ALTER TABLE visitantes
    ADD INDEX IF NOT EXISTS idx_placa_veiculo (placa_veiculo);

-- ================================================================================
-- MIGRATION: registros_acesso v2
-- Adiciona: visitante_id (FK), documento_visitante
-- ================================================================================

-- 8. Adicionar visitante_id na tabela de registros de acesso
ALTER TABLE registros_acesso
    ADD COLUMN IF NOT EXISTS visitante_id INT NULL COMMENT 'ID do visitante cadastrado' AFTER morador_id;

-- 9. Adicionar documento do visitante no registro
ALTER TABLE registros_acesso
    ADD COLUMN IF NOT EXISTS documento_visitante VARCHAR(30) NULL COMMENT 'CPF ou RG do visitante/prestador' AFTER nome_visitante;

-- 10. Índice para busca por visitante_id
ALTER TABLE registros_acesso
    ADD INDEX IF NOT EXISTS idx_visitante_id (visitante_id);

-- ================================================================================
-- Verificar resultado
-- ================================================================================
SELECT 
    COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'visitantes'
ORDER BY ORDINAL_POSITION;

SELECT 
    COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'registros_acesso'
ORDER BY ORDINAL_POSITION;
