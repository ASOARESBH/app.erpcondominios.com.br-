-- =====================================================
-- TABELAS DE HIDRÔMETROS E LEITURAS
-- =====================================================

-- Tabela de Hidrômetros
CREATE TABLE IF NOT EXISTS hidrometros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    morador_id INT NOT NULL,
    unidade VARCHAR(50) NOT NULL,
    numero_hidrometro VARCHAR(50) NOT NULL UNIQUE COMMENT 'Número alfanumérico do hidrômetro',
    numero_lacre VARCHAR(50) COMMENT 'Número do lacre',
    ativo TINYINT(1) DEFAULT 1,
    data_instalacao DATETIME NOT NULL,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_morador (morador_id),
    INDEX idx_unidade (unidade),
    INDEX idx_numero (numero_hidrometro),
    INDEX idx_ativo (ativo),
    FOREIGN KEY (morador_id) REFERENCES moradores(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Histórico de Edições de Hidrômetros
CREATE TABLE IF NOT EXISTS hidrometros_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hidrometro_id INT NOT NULL,
    campo_alterado VARCHAR(100),
    valor_anterior TEXT,
    valor_novo TEXT,
    observacao TEXT NOT NULL COMMENT 'Motivo da alteração',
    usuario VARCHAR(100),
    data_alteracao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hidrometro (hidrometro_id),
    INDEX idx_data (data_alteracao),
    FOREIGN KEY (hidrometro_id) REFERENCES hidrometros(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Leituras
CREATE TABLE IF NOT EXISTS leituras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hidrometro_id INT NOT NULL,
    morador_id INT NOT NULL,
    unidade VARCHAR(50) NOT NULL,
    leitura_anterior DECIMAL(10,2) DEFAULT 0,
    leitura_atual DECIMAL(10,2) NOT NULL,
    consumo DECIMAL(10,2) NOT NULL COMMENT 'Leitura atual - leitura anterior',
    valor_metro_cubico DECIMAL(10,2) DEFAULT 6.16,
    valor_minimo DECIMAL(10,2) DEFAULT 61.60,
    valor_total DECIMAL(10,2) NOT NULL,
    data_leitura DATETIME NOT NULL,
    observacao TEXT,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hidrometro (hidrometro_id),
    INDEX idx_morador (morador_id),
    INDEX idx_unidade (unidade),
    INDEX idx_data_leitura (data_leitura),
    FOREIGN KEY (hidrometro_id) REFERENCES hidrometros(id) ON DELETE RESTRICT,
    FOREIGN KEY (morador_id) REFERENCES moradores(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices adicionais para performance
CREATE INDEX idx_hidrometro_ativo ON hidrometros(ativo, unidade);
CREATE INDEX idx_leitura_periodo ON leituras(data_leitura, unidade);

-- Índice composto para o Relatório de Consumo Analítico (busca da última
-- leitura anterior/dentro do período por hidrômetro). Criado de forma
-- idempotente em tempo de execução por api_relatorios_hidrometro.php,
-- documentado aqui para refletir o schema alvo.
CREATE INDEX idx_hidrometro_data ON leituras(hidrometro_id, data_leitura);

