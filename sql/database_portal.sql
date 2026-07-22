-- =====================================================
-- ESTRUTURA DO BANCO DE DADOS - PORTAL DO MORADOR
-- Sistema de Controle de Acesso - Serra da Liberdade
-- =====================================================

-- Tabela de Hidrometro
CREATE TABLE IF NOT EXISTS hidrometro (
    id INT AUTO_INCREMENT PRIMARY KEY,
    unidade_id INT NOT NULL,
    numero_hidrometro VARCHAR(50) NOT NULL UNIQUE COMMENT 'Número do hidrômetro',
    localizacao VARCHAR(255) COMMENT 'Localização física do hidrômetro',
    data_instalacao DATE COMMENT 'Data de instalação',
    status ENUM('ativo', 'inativo', 'manutencao') DEFAULT 'ativo',
    observacoes TEXT,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_unidade (unidade_id),
    INDEX idx_numero (numero_hidrometro),
    
    FOREIGN KEY (unidade_id) REFERENCES unidades(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Lançamentos de Água
CREATE TABLE IF NOT EXISTS lancamentos_agua (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hidrometro_id INT NOT NULL,
    unidade_id INT NOT NULL,
    mes_referencia VARCHAR(7) NOT NULL COMMENT 'Formato: YYYY-MM',
    leitura_anterior DECIMAL(10, 2) DEFAULT 0.00,
    leitura_atual DECIMAL(10, 2) NOT NULL,
    consumo DECIMAL(10, 2) GENERATED ALWAYS AS (leitura_atual - leitura_anterior) STORED,
    valor_m3 DECIMAL(10, 2) COMMENT 'Valor por m³',
    valor_total DECIMAL(10, 2) COMMENT 'Valor total da conta',
    data_leitura DATE NOT NULL,
    data_vencimento DATE,
    status_pagamento ENUM('pendente', 'pago', 'atrasado') DEFAULT 'pendente',
    observacoes TEXT,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_hidrometro (hidrometro_id),
    INDEX idx_unidade (unidade_id),
    INDEX idx_mes_referencia (mes_referencia),
    INDEX idx_status (status_pagamento),
    
    FOREIGN KEY (hidrometro_id) REFERENCES hidrometro(id) ON DELETE CASCADE,
    FOREIGN KEY (unidade_id) REFERENCES unidades(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_hidrometro_mes (hidrometro_id, mes_referencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Sessões do Portal (para controle de login)
CREATE TABLE IF NOT EXISTS sessoes_portal (
    id INT AUTO_INCREMENT PRIMARY KEY,
    morador_id INT NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    data_login TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_expiracao TIMESTAMP,
    ativo TINYINT(1) DEFAULT 1,
    
    INDEX idx_morador (morador_id),
    INDEX idx_token (token),
    INDEX idx_ativo (ativo),
    
    FOREIGN KEY (morador_id) REFERENCES moradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar campo senha na tabela moradores (se não existir)
ALTER TABLE moradores 
ADD COLUMN IF NOT EXISTS senha VARCHAR(255) COMMENT 'Senha criptografada para acesso ao portal';

-- Adicionar campo ultimo_acesso na tabela moradores
ALTER TABLE moradores 
ADD COLUMN IF NOT EXISTS ultimo_acesso TIMESTAMP NULL COMMENT 'Data do último acesso ao portal';

-- Inserir dados de exemplo para hidrometro
INSERT INTO hidrometro (unidade_id, numero_hidrometro, localizacao, data_instalacao, status) VALUES
(1, 'HID-001', 'Área externa - Gleba 133', '2024-01-15', 'ativo'),
(2, 'HID-002', 'Área externa - Gleba 2', '2024-01-15', 'ativo');

-- Inserir lançamentos de exemplo
INSERT INTO lancamentos_agua (hidrometro_id, unidade_id, mes_referencia, leitura_anterior, leitura_atual, valor_m3, valor_total, data_leitura, data_vencimento, status_pagamento) VALUES
(1, 1, '2025-09', 1000.00, 1015.50, 5.50, 85.25, '2025-09-28', '2025-10-10', 'pago'),
(1, 1, '2025-10', 1015.50, 1032.75, 5.50, 95.13, '2025-10-28', '2025-11-10', 'pendente'),
(2, 2, '2025-09', 800.00, 812.30, 5.50, 67.65, '2025-09-28', '2025-10-10', 'pago'),
(2, 2, '2025-10', 812.30, 825.80, 5.50, 74.25, '2025-10-28', '2025-11-10', 'pendente');

-- Atualizar senhas padrão para moradores existentes (senha: 123456)
-- Hash bcrypt de '123456': $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
UPDATE moradores 
SET senha = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' 
WHERE senha IS NULL OR senha = '';

-- Comentários sobre a estrutura
-- 
-- TABELA HIDROMETRO:
-- - Armazena informações dos hidrômetros de cada unidade
-- - Relacionamento 1:N com unidades (uma unidade pode ter múltiplos hidrômetros)
-- - Status: ativo, inativo, manutenção
--
-- TABELA LANCAMENTOS_AGUA:
-- - Armazena as leituras mensais de consumo de água
-- - Campo 'consumo' é calculado automaticamente (GENERATED ALWAYS)
-- - Unique constraint para evitar duplicação de lançamento no mesmo mês
-- - Relacionamento com hidrometro e unidade
--
-- TABELA SESSOES_PORTAL:
-- - Controla as sessões de login dos moradores
-- - Token único para autenticação
-- - Registro de IP e User Agent para segurança
-- - Expiração automática de sessões
--
-- CAMPOS ADICIONADOS EM MORADORES:
-- - senha: Hash bcrypt da senha do morador
-- - ultimo_acesso: Timestamp do último login

