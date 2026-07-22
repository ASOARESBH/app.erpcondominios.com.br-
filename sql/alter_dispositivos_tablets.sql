-- ============================================================
-- ATUALIZAÇÃO DA TABELA DISPOSITIVOS_TABLETS
-- Adiciona novos campos e remove vínculo com usuários
-- ============================================================

-- Adicionar novos campos
ALTER TABLE dispositivos_tablets
ADD COLUMN IF NOT EXISTS tipo_dispositivo ENUM('Tablet', 'Câmera', 'Totem', 'Outro') DEFAULT 'Tablet' AFTER nome,
ADD COLUMN IF NOT EXISTS responsavel VARCHAR(100) AFTER local,
ADD COLUMN IF NOT EXISTS observacao TEXT AFTER descricao;

-- Remover coluna criado_por (vínculo com usuário)
ALTER TABLE dispositivos_tablets
DROP FOREIGN KEY IF EXISTS dispositivos_tablets_ibfk_1;

ALTER TABLE dispositivos_tablets
DROP COLUMN IF EXISTS criado_por;

-- Atualizar view de estatísticas
DROP VIEW IF EXISTS view_estatisticas_dispositivos;

CREATE VIEW view_estatisticas_dispositivos AS
SELECT 
    COUNT(*) as total_dispositivos,
    SUM(CASE WHEN status = 'ativo' THEN 1 ELSE 0 END) as dispositivos_ativos,
    SUM(CASE WHEN status = 'inativo' THEN 1 ELSE 0 END) as dispositivos_inativos,
    (SELECT COUNT(*) FROM logs_validacoes_dispositivo WHERE DATE(validado_em) = CURDATE()) as validacoes_hoje,
    (SELECT COUNT(*) FROM logs_validacoes_dispositivo WHERE DATE(validado_em) = CURDATE() AND resultado = 'sucesso') as validacoes_sucesso_hoje,
    (SELECT COUNT(*) FROM logs_validacoes_dispositivo WHERE DATE(validado_em) = CURDATE() AND resultado = 'falha') as validacoes_falha_hoje,
    (SELECT ROUND((COUNT(CASE WHEN resultado = 'sucesso' THEN 1 END) * 100.0 / COUNT(*)), 2) 
     FROM logs_validacoes_dispositivo 
     WHERE DATE(validado_em) = CURDATE()) as taxa_sucesso
FROM dispositivos_tablets;

-- ============================================================
-- VERIFICAÇÃO
-- ============================================================

-- Verificar estrutura atualizada
DESCRIBE dispositivos_tablets;

-- Verificar view
SELECT * FROM view_estatisticas_dispositivos;
