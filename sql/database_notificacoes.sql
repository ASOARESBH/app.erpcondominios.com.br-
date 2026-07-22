-- =====================================================
-- SISTEMA DE NOTIFICAÇÕES PARA MORADORES
-- =====================================================

-- Tabela de Notificações
CREATE TABLE IF NOT EXISTS notificacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_sequencial INT NOT NULL UNIQUE COMMENT 'Número sequencial da notificação',
    data_hora DATETIME NOT NULL,
    assunto VARCHAR(255) NOT NULL,
    resumo TEXT NOT NULL,
    anexo_nome VARCHAR(255) COMMENT 'Nome original do arquivo anexado',
    anexo_caminho VARCHAR(500) COMMENT 'Caminho do arquivo no servidor',
    anexo_tipo VARCHAR(50) COMMENT 'Tipo MIME do arquivo (PDF, imagem)',
    ativo TINYINT(1) DEFAULT 1,
    criado_por VARCHAR(100),
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_numero (numero_sequencial),
    INDEX idx_data (data_hora),
    INDEX idx_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Visualizações de Notificações
CREATE TABLE IF NOT EXISTS notificacoes_visualizacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notificacao_id INT NOT NULL,
    morador_id INT NOT NULL,
    data_visualizacao DATETIME NOT NULL,
    ip_address VARCHAR(50),
    INDEX idx_notificacao (notificacao_id),
    INDEX idx_morador (morador_id),
    INDEX idx_data (data_visualizacao),
    FOREIGN KEY (notificacao_id) REFERENCES notificacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (morador_id) REFERENCES moradores(id) ON DELETE CASCADE,
    UNIQUE KEY uk_notificacao_morador (notificacao_id, morador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Downloads de Anexos
CREATE TABLE IF NOT EXISTS notificacoes_downloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notificacao_id INT NOT NULL,
    morador_id INT NOT NULL,
    data_download DATETIME NOT NULL,
    ip_address VARCHAR(50),
    INDEX idx_notificacao (notificacao_id),
    INDEX idx_morador (morador_id),
    INDEX idx_data (data_download),
    FOREIGN KEY (notificacao_id) REFERENCES notificacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (morador_id) REFERENCES moradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Criar diretório para uploads (executar no servidor)
-- mkdir -p uploads/notificacoes
-- chmod 755 uploads/notificacoes

-- =====================================================
-- FIM DO SCRIPT
-- =====================================================

