-- =====================================================
-- TABELA DE UNIDADES
-- =====================================================

CREATE TABLE IF NOT EXISTS unidades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(50) NOT NULL UNIQUE COMMENT 'Nome/Número da unidade',
    descricao VARCHAR(255) COMMENT 'Descrição adicional',
    bloco VARCHAR(20) COMMENT 'Bloco ou torre',
    ativo TINYINT(1) DEFAULT 1,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_nome (nome),
    INDEX idx_ativo (ativo),
    INDEX idx_bloco (bloco)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir algumas unidades de exemplo
INSERT INTO unidades (nome, descricao, bloco) VALUES
('101', 'Apartamento 101', 'Bloco A'),
('102', 'Apartamento 102', 'Bloco A'),
('103', 'Apartamento 103', 'Bloco A'),
('201', 'Apartamento 201', 'Bloco B'),
('202', 'Apartamento 202', 'Bloco B'),
('GLEBA 133', 'Gleba 133', 'Glebas')
ON DUPLICATE KEY UPDATE nome=nome;

