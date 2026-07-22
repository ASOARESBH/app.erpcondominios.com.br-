-- =====================================================
-- SISTEMA DE CONTROLE DE ACESSO - SERRA DA LIBERDADE
-- Módulo: Protocolo de Mercadorias
-- =====================================================

-- Tabela de protocolos de mercadorias
CREATE TABLE IF NOT EXISTS protocolos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unidade_id INT NOT NULL,
    morador_id INT NOT NULL,
    descricao_mercadoria VARCHAR(255) NOT NULL,
    codigo_nf VARCHAR(100),
    pagina INT,
    data_hora_recebimento DATETIME NOT NULL,
    recebedor_portaria VARCHAR(100) NOT NULL COMMENT 'Nome de quem recebeu na portaria',
    status ENUM('pendente', 'entregue') DEFAULT 'pendente',
    nome_recebedor_morador VARCHAR(100) COMMENT 'Nome de quem recebeu do morador',
    data_hora_entrega DATETIME COMMENT 'Data e hora que o morador recebeu',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (unidade_id) REFERENCES unidades(id),
    FOREIGN KEY (morador_id) REFERENCES moradores(id),
    INDEX idx_unidade (unidade_id),
    INDEX idx_morador (morador_id),
    INDEX idx_status (status),
    INDEX idx_data_recebimento (data_hora_recebimento),
    INDEX idx_data_entrega (data_hora_entrega)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de recebedores (porteiros/funcionários)
CREATE TABLE IF NOT EXISTS recebedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    cargo VARCHAR(50) DEFAULT 'Porteiro',
    ativo TINYINT(1) DEFAULT 1,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir recebedores padrão
INSERT INTO recebedores (nome, cargo) VALUES
('João Silva', 'Porteiro'),
('Maria Santos', 'Porteira'),
('Pedro Oliveira', 'Porteiro'),
('Ana Costa', 'Porteira')
ON DUPLICATE KEY UPDATE nome=nome;

-- =====================================================
-- FIM DO SCRIPT
-- =====================================================

