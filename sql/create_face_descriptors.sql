-- ========================================
-- TABELA DE DESCRITORES FACIAIS
-- ========================================

CREATE TABLE IF NOT EXISTS face_descriptors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visitante_id INT NOT NULL,
    acesso_id INT NULL,
    descritor TEXT NOT NULL COMMENT 'JSON com array de 128 dimensões do face-api.js',
    foto_url VARCHAR(500) NULL COMMENT 'URL da foto usada para gerar descritor',
    token_cadastro VARCHAR(64) UNIQUE NOT NULL COMMENT 'Token único para link de cadastro',
    token_usado TINYINT(1) DEFAULT 0 COMMENT '0=não usado, 1=usado',
    data_cadastro DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_expiracao DATETIME NULL COMMENT 'Data de expiração do token',
    ip_cadastro VARCHAR(45) NULL,
    user_agent TEXT NULL,
    ativo TINYINT(1) DEFAULT 1,
    data_inativacao DATETIME NULL,
    motivo_inativacao VARCHAR(255) NULL,
    
    INDEX idx_visitante (visitante_id),
    INDEX idx_acesso (acesso_id),
    INDEX idx_token (token_cadastro),
    INDEX idx_ativo (ativo),
    
    FOREIGN KEY (visitante_id) REFERENCES visitantes(id) ON DELETE CASCADE,
    FOREIGN KEY (acesso_id) REFERENCES acessos_visitantes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- TABELA DE VALIDAÇÕES DE FACE ID
-- ========================================

CREATE TABLE IF NOT EXISTS validacoes_face_id (
    id INT AUTO_INCREMENT PRIMARY KEY,
    face_descriptor_id INT NOT NULL,
    visitante_id INT NOT NULL,
    acesso_id INT NULL,
    dispositivo_id INT NULL,
    similaridade DECIMAL(5,4) NOT NULL COMMENT 'Valor de 0.0000 a 1.0000 (quanto mais próximo de 0, mais similar)',
    threshold_usado DECIMAL(5,4) DEFAULT 0.6000 COMMENT 'Threshold usado na comparação',
    resultado ENUM('sucesso', 'falha') NOT NULL,
    motivo_falha VARCHAR(255) NULL,
    data_validacao DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_validacao VARCHAR(45) NULL,
    
    INDEX idx_face_descriptor (face_descriptor_id),
    INDEX idx_visitante (visitante_id),
    INDEX idx_resultado (resultado),
    INDEX idx_data (data_validacao),
    
    FOREIGN KEY (face_descriptor_id) REFERENCES face_descriptors(id) ON DELETE CASCADE,
    FOREIGN KEY (visitante_id) REFERENCES visitantes(id) ON DELETE CASCADE,
    FOREIGN KEY (acesso_id) REFERENCES acessos_visitantes(id) ON DELETE SET NULL,
    FOREIGN KEY (dispositivo_id) REFERENCES dispositivos_console(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ========================================
-- ADICIONAR CAMPO TIPO_ACESSO EM ACESSOS_VISITANTES
-- ========================================

ALTER TABLE acessos_visitantes 
ADD COLUMN tipo_identificacao ENUM('qrcode', 'face_id') DEFAULT 'qrcode' 
AFTER tipo_acesso;

-- ========================================
-- VIEWS ÚTEIS
-- ========================================

-- View de descritores ativos
CREATE OR REPLACE VIEW vw_face_descriptors_ativos AS
SELECT 
    fd.id,
    fd.visitante_id,
    v.nome AS visitante_nome,
    v.documento AS visitante_documento,
    fd.acesso_id,
    fd.token_cadastro,
    fd.token_usado,
    fd.data_cadastro,
    fd.data_expiracao,
    CASE 
        WHEN fd.data_expiracao IS NULL THEN 'Sem expiração'
        WHEN fd.data_expiracao < NOW() THEN 'Expirado'
        ELSE 'Válido'
    END AS status_token,
    fd.ativo,
    fd.foto_url
FROM face_descriptors fd
INNER JOIN visitantes v ON fd.visitante_id = v.id
WHERE fd.ativo = 1;

-- View de estatísticas de Face ID
CREATE OR REPLACE VIEW vw_estatisticas_face_id AS
SELECT 
    COUNT(DISTINCT fd.id) AS total_descritores,
    COUNT(DISTINCT fd.visitante_id) AS total_visitantes_com_face,
    COUNT(DISTINCT CASE WHEN fd.token_usado = 1 THEN fd.id END) AS tokens_usados,
    COUNT(DISTINCT CASE WHEN fd.token_usado = 0 THEN fd.id END) AS tokens_pendentes,
    COUNT(DISTINCT CASE WHEN fd.data_expiracao < NOW() THEN fd.id END) AS tokens_expirados,
    COUNT(DISTINCT vf.id) AS total_validacoes,
    COUNT(DISTINCT CASE WHEN vf.resultado = 'sucesso' THEN vf.id END) AS validacoes_sucesso,
    COUNT(DISTINCT CASE WHEN vf.resultado = 'falha' THEN vf.id END) AS validacoes_falha,
    AVG(CASE WHEN vf.resultado = 'sucesso' THEN vf.similaridade END) AS media_similaridade_sucesso
FROM face_descriptors fd
LEFT JOIN validacoes_face_id vf ON fd.id = vf.face_descriptor_id;

-- ========================================
-- DADOS INICIAIS (OPCIONAL)
-- ========================================

-- Nenhum dado inicial necessário

-- ========================================
-- COMENTÁRIOS
-- ========================================

-- Descritor facial: Array de 128 dimensões gerado pelo face-api.js
-- Formato JSON: [0.123, -0.456, 0.789, ...]
-- Similaridade: Distância euclidiana entre descritores (0 = idêntico, 1 = totalmente diferente)
-- Threshold padrão: 0.6 (valores abaixo de 0.6 são considerados a mesma pessoa)
