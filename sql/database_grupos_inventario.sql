-- =====================================================
-- ESTRUTURA DO BANCO DE DADOS - GRUPOS DE INVENTÁRIO
-- Sistema ERP Condomínio - ASL
-- =====================================================

-- Tabela de Grupos de Inventário
CREATE TABLE IF NOT EXISTS grupos_inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE COMMENT 'Nome do grupo (ex: Hidrômetros, Móveis, TI)',
    descricao TEXT COMMENT 'Descrição opcional do grupo',
    ativo TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = ativo, 0 = inativo',
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_nome (nome),
    INDEX idx_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir grupo padrão "Hidrômetros"
INSERT IGNORE INTO grupos_inventario (nome, descricao) VALUES
('Hidrômetros', 'Medidores de consumo de água das unidades'),
('Móveis', 'Mobiliário e equipamentos de escritório'),
('TI', 'Equipamentos de tecnologia da informação'),
('Infraestrutura', 'Equipamentos de infraestrutura predial');

-- Adicionar coluna grupo_id na tabela inventario (se não existir)
ALTER TABLE inventario
    ADD COLUMN IF NOT EXISTS grupo_id INT NULL COMMENT 'FK para grupos_inventario' AFTER tutela_usuario_id,
    ADD INDEX IF NOT EXISTS idx_grupo (grupo_id);

-- Adicionar FK (executar separadamente se ALTER TABLE acima não suportar ADD CONSTRAINT com IF NOT EXISTS)
-- ALTER TABLE inventario
--     ADD CONSTRAINT fk_inventario_grupo
--     FOREIGN KEY (grupo_id) REFERENCES grupos_inventario(id) ON DELETE SET NULL;
