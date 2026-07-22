-- ================================================================================
-- SCRIPT PARA ADICIONAR LOG DE LANÇAMENTO E CONFIGURAÇÃO DE PERÍODO
-- ================================================================================
-- Execute no phpMyAdmin
-- ================================================================================

-- ================================================================================
-- 1. ADICIONAR CAMPOS DE LOG NA TABELA LEITURAS
-- ================================================================================

-- Adicionar campo para identificar quem lançou (usuario ou morador)
ALTER TABLE leituras 
ADD COLUMN lancado_por_tipo ENUM('usuario', 'morador') DEFAULT 'usuario' AFTER morador_id;

-- Adicionar campo para ID de quem lançou
ALTER TABLE leituras 
ADD COLUMN lancado_por_id INT NULL AFTER lancado_por_tipo;

-- Adicionar campo para nome de quem lançou (para facilitar consultas)
ALTER TABLE leituras 
ADD COLUMN lancado_por_nome VARCHAR(255) NULL AFTER lancado_por_id;

-- Adicionar índice para melhorar performance
ALTER TABLE leituras 
ADD INDEX idx_lancado_por (lancado_por_tipo, lancado_por_id);

-- ================================================================================
-- 2. CRIAR TABELA DE CONFIGURAÇÃO DE PERÍODO DE LEITURA
-- ================================================================================

CREATE TABLE IF NOT EXISTS config_periodo_leitura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dia_inicio INT NOT NULL DEFAULT 1 COMMENT 'Dia inicial do período (1-31)',
    dia_fim INT NOT NULL DEFAULT 10 COMMENT 'Dia final do período (1-31)',
    ativo TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Ativo, 0 = Inativo',
    morador_pode_lancar TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Morador pode lançar, 0 = Apenas usuário',
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_dia_inicio CHECK (dia_inicio >= 1 AND dia_inicio <= 31),
    CONSTRAINT chk_dia_fim CHECK (dia_fim >= 1 AND dia_fim <= 31)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configuração do período para lançamento de leituras';

-- ================================================================================
-- 3. INSERIR CONFIGURAÇÃO PADRÃO
-- ================================================================================

INSERT INTO config_periodo_leitura (dia_inicio, dia_fim, ativo, morador_pode_lancar) 
VALUES (1, 10, 1, 1)
ON DUPLICATE KEY UPDATE dia_inicio = 1, dia_fim = 10;

-- ================================================================================
-- 4. ATUALIZAR LEITURAS EXISTENTES (OPCIONAL)
-- ================================================================================

-- Marcar leituras existentes como lançadas por usuário
-- Assumindo que todas as leituras antigas foram lançadas por usuários

UPDATE leituras 
SET 
    lancado_por_tipo = 'usuario',
    lancado_por_id = 1,  -- ID do usuário admin (ajustar se necessário)
    lancado_por_nome = 'Sistema'
WHERE lancado_por_tipo IS NULL;

-- ================================================================================
-- 5. CRIAR VIEW PARA FACILITAR CONSULTAS
-- ================================================================================

CREATE OR REPLACE VIEW view_leituras_completas AS
SELECT 
    l.id,
    l.hidrometro_id,
    l.morador_id,
    l.unidade,
    l.leitura_anterior,
    l.leitura_atual,
    l.consumo,
    l.valor,
    l.data_leitura,
    l.lancado_por_tipo,
    l.lancado_por_id,
    l.lancado_por_nome,
    h.numero_hidrometro,
    h.numero_lacre,
    m.nome as morador_nome,
    m.email as morador_email,
    DATE_FORMAT(l.data_leitura, '%d/%m/%Y %H:%i') as data_leitura_formatada,
    DATE_FORMAT(l.data_leitura, '%m/%Y') as mes_ano_leitura,
    CASE 
        WHEN l.lancado_por_tipo = 'usuario' THEN CONCAT('👤 ', l.lancado_por_nome, ' (Operador)')
        WHEN l.lancado_por_tipo = 'morador' THEN CONCAT('🏠 ', l.lancado_por_nome, ' (Morador)')
        ELSE 'Sistema'
    END as lancado_por_descricao
FROM leituras l
INNER JOIN hidrometros h ON l.hidrometro_id = h.id
INNER JOIN moradores m ON l.morador_id = m.id
ORDER BY l.data_leitura DESC;

-- ================================================================================
-- 6. CRIAR PROCEDURE PARA VERIFICAR SE PODE LANÇAR LEITURA
-- ================================================================================

DELIMITER $$

CREATE PROCEDURE sp_verificar_pode_lancar_leitura(
    IN p_hidrometro_id INT,
    IN p_mes INT,
    IN p_ano INT,
    OUT p_pode_lancar TINYINT,
    OUT p_mensagem VARCHAR(500),
    OUT p_lancado_por_tipo VARCHAR(20),
    OUT p_lancado_por_nome VARCHAR(255),
    OUT p_data_leitura DATETIME
)
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_tipo VARCHAR(20);
    DECLARE v_nome VARCHAR(255);
    DECLARE v_data DATETIME;
    
    -- Verificar se já existe leitura no mês/ano
    SELECT 
        COUNT(*),
        lancado_por_tipo,
        lancado_por_nome,
        data_leitura
    INTO 
        v_count,
        v_tipo,
        v_nome,
        v_data
    FROM leituras
    WHERE hidrometro_id = p_hidrometro_id
    AND MONTH(data_leitura) = p_mes
    AND YEAR(data_leitura) = p_ano
    LIMIT 1;
    
    IF v_count > 0 THEN
        SET p_pode_lancar = 0;
        SET p_mensagem = CONCAT('Leitura já lançada por ', v_nome, ' em ', DATE_FORMAT(v_data, '%d/%m/%Y %H:%i'));
        SET p_lancado_por_tipo = v_tipo;
        SET p_lancado_por_nome = v_nome;
        SET p_data_leitura = v_data;
    ELSE
        SET p_pode_lancar = 1;
        SET p_mensagem = 'Pode lançar leitura';
        SET p_lancado_por_tipo = NULL;
        SET p_lancado_por_nome = NULL;
        SET p_data_leitura = NULL;
    END IF;
END$$

DELIMITER ;

-- ================================================================================
-- 7. CRIAR FUNCTION PARA VERIFICAR SE ESTÁ NO PERÍODO
-- ================================================================================

DELIMITER $$

CREATE FUNCTION fn_esta_no_periodo_leitura() 
RETURNS TINYINT
DETERMINISTIC
BEGIN
    DECLARE v_dia_atual INT;
    DECLARE v_dia_inicio INT;
    DECLARE v_dia_fim INT;
    DECLARE v_ativo TINYINT;
    DECLARE v_morador_pode TINYINT;
    
    SET v_dia_atual = DAY(CURDATE());
    
    -- Buscar configuração
    SELECT dia_inicio, dia_fim, ativo, morador_pode_lancar
    INTO v_dia_inicio, v_dia_fim, v_ativo, v_morador_pode
    FROM config_periodo_leitura
    WHERE ativo = 1
    LIMIT 1;
    
    -- Se não houver configuração, retornar 0 (não está no período)
    IF v_dia_inicio IS NULL THEN
        RETURN 0;
    END IF;
    
    -- Se morador não pode lançar, retornar 0
    IF v_morador_pode = 0 THEN
        RETURN 0;
    END IF;
    
    -- Verificar se está no período
    IF v_dia_atual >= v_dia_inicio AND v_dia_atual <= v_dia_fim THEN
        RETURN 1;
    ELSE
        RETURN 0;
    END IF;
END$$

DELIMITER ;

-- ================================================================================
-- 8. QUERIES ÚTEIS
-- ================================================================================

-- Listar leituras com quem lançou
SELECT * FROM view_leituras_completas ORDER BY data_leitura DESC LIMIT 50;

-- Verificar configuração de período
SELECT * FROM config_periodo_leitura WHERE ativo = 1;

-- Verificar se está no período
SELECT fn_esta_no_periodo_leitura() as esta_no_periodo;

-- Verificar leituras duplicadas no mesmo mês
SELECT 
    hidrometro_id,
    DATE_FORMAT(data_leitura, '%m/%Y') as mes_ano,
    COUNT(*) as total_leituras,
    GROUP_CONCAT(CONCAT(lancado_por_nome, ' (', lancado_por_tipo, ')') SEPARATOR ', ') as lancado_por
FROM leituras
GROUP BY hidrometro_id, DATE_FORMAT(data_leitura, '%m/%Y')
HAVING total_leituras > 1;

-- Listar leituras por tipo de lançamento
SELECT 
    lancado_por_tipo,
    COUNT(*) as total,
    DATE_FORMAT(MIN(data_leitura), '%d/%m/%Y') as primeira_leitura,
    DATE_FORMAT(MAX(data_leitura), '%d/%m/%Y') as ultima_leitura
FROM leituras
GROUP BY lancado_por_tipo;

-- ================================================================================
-- FIM DO SCRIPT
-- ================================================================================

-- ⚠️ IMPORTANTE:
-- Após executar este script:
-- 1. Verificar se as colunas foram adicionadas: SHOW COLUMNS FROM leituras;
-- 2. Verificar se a tabela foi criada: SHOW TABLES LIKE 'config_periodo_leitura';
-- 3. Verificar se a view foi criada: SHOW CREATE VIEW view_leituras_completas;
-- 4. Verificar se a procedure foi criada: SHOW PROCEDURE STATUS WHERE Name = 'sp_verificar_pode_lancar_leitura';
-- 5. Verificar se a function foi criada: SHOW FUNCTION STATUS WHERE Name = 'fn_esta_no_periodo_leitura';
