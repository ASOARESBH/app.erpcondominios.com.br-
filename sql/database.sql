-- =====================================================
-- SISTEMA DE CONTROLE DE ACESSO - PORTARIA
-- Banco de Dados: inlaud99_erpserra
-- Usuário: inlaud99_admin
-- Senha: Admin259087@
-- =====================================================

-- Criação da tabela de moradores
CREATE TABLE IF NOT EXISTS moradores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    cpf VARCHAR(14) NOT NULL UNIQUE,
    unidade VARCHAR(50) NOT NULL,
    email VARCHAR(200) NOT NULL,
    senha VARCHAR(255) NOT NULL,
    telefone VARCHAR(20),
    celular VARCHAR(20),
    ativo TINYINT(1) DEFAULT 1,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cpf (cpf),
    INDEX idx_unidade (unidade),
    INDEX idx_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Criação da tabela de veículos
CREATE TABLE IF NOT EXISTS veiculos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    placa VARCHAR(10) NOT NULL UNIQUE,
    modelo VARCHAR(100) NOT NULL,
    cor VARCHAR(50),
    tag VARCHAR(50) NOT NULL UNIQUE,
    morador_id INT NOT NULL,
    ativo TINYINT(1) DEFAULT 1,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (morador_id) REFERENCES moradores(id) ON DELETE CASCADE,
    INDEX idx_placa (placa),
    INDEX idx_tag (tag),
    INDEX idx_morador (morador_id),
    INDEX idx_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Criação da tabela de registros de acesso
CREATE TABLE IF NOT EXISTS registros_acesso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    data_hora DATETIME NOT NULL,
    placa VARCHAR(10) NOT NULL,
    modelo VARCHAR(100),
    cor VARCHAR(50),
    tag VARCHAR(50),
    tipo ENUM('Morador', 'Visitante', 'Prestador') NOT NULL,
    morador_id INT NULL,
    nome_visitante VARCHAR(200),
    unidade_destino VARCHAR(50),
    dias_permanencia INT,
    status VARCHAR(100),
    liberado TINYINT(1) DEFAULT 0,
    observacao TEXT,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_data_hora (data_hora),
    INDEX idx_placa (placa),
    INDEX idx_tag (tag),
    INDEX idx_tipo (tipo),
    INDEX idx_morador (morador_id),
    INDEX idx_liberado (liberado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Criação da tabela de logs do sistema
CREATE TABLE IF NOT EXISTS logs_sistema (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo VARCHAR(50) NOT NULL,
    descricao TEXT NOT NULL,
    usuario VARCHAR(100),
    ip VARCHAR(50),
    data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tipo (tipo),
    INDEX idx_data_hora (data_hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Criação da tabela de configurações do sistema
CREATE TABLE IF NOT EXISTS configuracoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chave VARCHAR(100) NOT NULL UNIQUE,
    valor TEXT,
    descricao VARCHAR(255),
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configurações iniciais
INSERT INTO configuracoes (chave, valor, descricao) VALUES
('rfid_ip', '', 'Endereço IP do leitor RFID Control iD iDUHF'),
('rfid_porta', '3000', 'Porta de comunicação do leitor RFID'),
('rfid_usuario', 'admin', 'Usuário do leitor RFID'),
('rfid_senha', '', 'Senha do leitor RFID'),
('liberacao_automatica', '1', 'Liberar cancela automaticamente (1=Sim, 0=Não)'),
('tempo_abertura_cancela', '5', 'Tempo de abertura da cancela em segundos'),
('nome_condominio', 'Serra da Liberdade', 'Nome do condomínio')
ON DUPLICATE KEY UPDATE valor=valor;

