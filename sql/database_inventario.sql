-- =====================================================
-- ESTRUTURA DO BANCO DE DADOS - MÓDULO INVENTÁRIO
-- Sistema de Controle de Acesso - Serra da Liberdade
-- =====================================================

-- Tabela de Inventário/Patrimônio
CREATE TABLE IF NOT EXISTS inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_patrimonio VARCHAR(50) NOT NULL UNIQUE COMMENT 'Código da etiqueta do patrimônio',
    nome_item VARCHAR(255) NOT NULL COMMENT 'Nome/Descrição do item',
    fabricante VARCHAR(100) COMMENT 'Fabricante do item',
    modelo VARCHAR(100) COMMENT 'Modelo do item',
    numero_serie VARCHAR(100) COMMENT 'Número de série',
    nf VARCHAR(50) COMMENT 'Número da Nota Fiscal',
    data_compra DATE COMMENT 'Data de aquisição',
    situacao ENUM('imobilizado', 'circulante') NOT NULL DEFAULT 'imobilizado' COMMENT 'Situação contábil',
    valor DECIMAL(10, 2) COMMENT 'Valor do produto',
    status ENUM('ativo', 'inativo') NOT NULL DEFAULT 'ativo' COMMENT 'Status do patrimônio',
    motivo_baixa TEXT COMMENT 'Motivo da baixa (quando status = inativo)',
    data_baixa DATE COMMENT 'Data da baixa',
    tutela_usuario_id INT COMMENT 'ID do usuário responsável',
    observacoes TEXT COMMENT 'Observações gerais',
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_numero_patrimonio (numero_patrimonio),
    INDEX idx_nf (nf),
    INDEX idx_situacao (situacao),
    INDEX idx_status (status),
    INDEX idx_tutela (tutela_usuario_id),
    
    FOREIGN KEY (tutela_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir dados de exemplo
INSERT INTO inventario (numero_patrimonio, nome_item, fabricante, modelo, numero_serie, nf, data_compra, situacao, valor, status, tutela_usuario_id) VALUES
('PAT-001', 'Notebook Dell Inspiron 15', 'Dell', 'Inspiron 15 3000', 'SN123456789', '12345', '2024-01-15', 'imobilizado', 3500.00, 'ativo', 1),
('PAT-002', 'Impressora HP LaserJet', 'HP', 'LaserJet Pro M404dn', 'SN987654321', '12346', '2024-02-20', 'imobilizado', 1800.00, 'ativo', 1),
('PAT-003', 'Cadeira Ergonômica', 'Flexform', 'Presidente Premium', NULL, '12347', '2024-03-10', 'circulante', 850.00, 'ativo', NULL),
('PAT-004', 'Monitor LG 24 polegadas', 'LG', '24MK430H', 'SN456789123', '12348', '2023-12-05', 'imobilizado', 650.00, 'inativo', NULL);

-- Atualizar item inativo com motivo de baixa
UPDATE inventario 
SET motivo_baixa = 'Monitor com defeito na tela. Enviado para descarte.', 
    data_baixa = '2024-10-15' 
WHERE numero_patrimonio = 'PAT-004';

-- Comentários sobre a estrutura
-- 
-- CAMPOS PRINCIPAIS:
-- - numero_patrimonio: Identificador único do patrimônio (código da etiqueta)
-- - nome_item: Descrição do item
-- - fabricante: Marca/Fabricante
-- - modelo: Modelo do produto
-- - numero_serie: Número de série (quando aplicável)
-- - nf: Número da Nota Fiscal
-- - data_compra: Data de aquisição
-- - situacao: imobilizado (ativo fixo) ou circulante (consumível)
-- - valor: Valor de aquisição
-- - status: ativo (em uso) ou inativo (baixado)
-- - motivo_baixa: Justificativa da baixa (obrigatório quando status = inativo)
-- - data_baixa: Data em que foi dado baixa
-- - tutela_usuario_id: Responsável pelo item (FK para tabela usuarios)
-- - observacoes: Informações adicionais
--
-- ÍNDICES:
-- - Criados para otimizar buscas por: número patrimônio, NF, situação, status e tutela
--
-- RELACIONAMENTOS:
-- - tutela_usuario_id → usuarios(id) com ON DELETE SET NULL
--   (se o usuário for excluído, o campo fica NULL)

