-- =============================================
-- SISTEMA DE GESTÃO DE ESTOQUE
-- Condomínio Serra da Liberdade
-- =============================================

-- Tabela: Categorias de Produtos
CREATE TABLE IF NOT EXISTS `categorias_estoque` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  `descricao` text,
  `cor` varchar(20) DEFAULT '#667eea',
  `ativo` tinyint(1) DEFAULT 1,
  `data_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela: Produtos/Materiais
CREATE TABLE IF NOT EXISTS `produtos_estoque` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) NOT NULL,
  `nome` varchar(200) NOT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `unidade_medida` enum('Unidade','Metro','Kg','Litro','Caixa','Pacote','Rolo','Saco','Outro') NOT NULL DEFAULT 'Unidade',
  `descricao` text,
  `preco_unitario` decimal(10,2) DEFAULT 0.00,
  `quantidade_estoque` decimal(10,2) DEFAULT 0.00,
  `estoque_minimo` decimal(10,2) DEFAULT 0.00,
  `estoque_maximo` decimal(10,2) DEFAULT 0.00,
  `localizacao` varchar(100) DEFAULT NULL COMMENT 'Local físico do armazenamento',
  `codigo_barras` varchar(50) DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `fornecedor` varchar(200) DEFAULT NULL,
  `observacoes` text,
  `ativo` tinyint(1) DEFAULT 1,
  `data_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `categoria_id` (`categoria_id`),
  KEY `idx_nome` (`nome`),
  KEY `idx_estoque` (`quantidade_estoque`),
  CONSTRAINT `fk_produto_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_estoque` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela: Movimentações de Estoque
CREATE TABLE IF NOT EXISTS `movimentacoes_estoque` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `produto_id` int(11) NOT NULL,
  `tipo_movimentacao` enum('Entrada','Saida','Ajuste','Devolucao') NOT NULL,
  `quantidade` decimal(10,2) NOT NULL,
  `quantidade_anterior` decimal(10,2) NOT NULL COMMENT 'Estoque antes da movimentação',
  `quantidade_posterior` decimal(10,2) NOT NULL COMMENT 'Estoque após a movimentação',
  `tipo_destino` enum('Morador','Administracao','Manutencao','Limpeza','Outro') DEFAULT NULL,
  `morador_id` int(11) DEFAULT NULL COMMENT 'Se tipo_destino = Morador',
  `usuario_responsavel` varchar(200) DEFAULT NULL COMMENT 'Quem realizou a movimentação',
  `motivo` varchar(500) DEFAULT NULL,
  `nota_fiscal` varchar(100) DEFAULT NULL,
  `valor_unitario` decimal(10,2) DEFAULT 0.00,
  `valor_total` decimal(10,2) DEFAULT 0.00,
  `fornecedor` varchar(200) DEFAULT NULL,
  `data_movimentacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `observacoes` text,
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `morador_id` (`morador_id`),
  KEY `idx_tipo` (`tipo_movimentacao`),
  KEY `idx_data` (`data_movimentacao`),
  KEY `idx_destino` (`tipo_destino`),
  CONSTRAINT `fk_movimentacao_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos_estoque` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_movimentacao_morador` FOREIGN KEY (`morador_id`) REFERENCES `moradores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela: Alertas de Estoque
CREATE TABLE IF NOT EXISTS `alertas_estoque` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `produto_id` int(11) NOT NULL,
  `tipo_alerta` enum('Estoque_Minimo','Estoque_Zerado','Estoque_Maximo','Validade_Proxima') NOT NULL,
  `mensagem` varchar(500) NOT NULL,
  `lido` tinyint(1) DEFAULT 0,
  `data_alerta` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `produto_id` (`produto_id`),
  KEY `idx_lido` (`lido`),
  CONSTRAINT `fk_alerta_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos_estoque` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- DADOS DE EXEMPLO
-- =============================================

-- Categorias
INSERT INTO `categorias_estoque` (`nome`, `descricao`, `cor`) VALUES
('Material de Construção', 'Cimento, areia, tijolos, etc.', '#ef4444'),
('Material Elétrico', 'Fios, disjuntores, lâmpadas, etc.', '#f59e0b'),
('Material Hidráulico', 'Canos, conexões, registros, etc.', '#3b82f6'),
('Material de Limpeza', 'Detergentes, desinfetantes, vassouras, etc.', '#10b981'),
('Ferramentas', 'Martelos, chaves, furadeiras, etc.', '#6b7280'),
('Jardinagem', 'Adubo, sementes, ferramentas de jardim, etc.', '#22c55e'),
('Pintura', 'Tintas, pincéis, rolos, etc.', '#8b5cf6'),
('EPIs', 'Equipamentos de Proteção Individual', '#f97316');

-- Produtos de Exemplo
INSERT INTO `produtos_estoque` (`codigo`, `nome`, `categoria_id`, `unidade_medida`, `descricao`, `preco_unitario`, `quantidade_estoque`, `estoque_minimo`, `estoque_maximo`, `localizacao`, `fornecedor`) VALUES
('PROD-001', 'Cimento CP-II 50kg', 1, 'Saco', 'Cimento Portland CP-II para construção civil', 32.50, 25.00, 10.00, 50.00, 'Depósito A - Prateleira 1', 'Votorantim Cimentos'),
('PROD-002', 'Areia Média (m³)', 1, 'Metro', 'Areia média para construção e acabamento', 85.00, 5.00, 2.00, 10.00, 'Área Externa - Baias', 'Areião do João'),
('PROD-003', 'Fio 2,5mm Azul (rolo 100m)', 2, 'Rolo', 'Fio elétrico flexível 2,5mm² cor azul', 125.00, 8.00, 3.00, 15.00, 'Depósito B - Prateleira 2', 'Pirelli Cabos'),
('PROD-004', 'Lâmpada LED 12W Branca', 2, 'Unidade', 'Lâmpada LED bulbo 12W luz branca 6500K', 15.90, 45.00, 20.00, 100.00, 'Depósito B - Caixa 5', 'Philips'),
('PROD-005', 'Cano PVC 25mm (6m)', 3, 'Unidade', 'Cano PVC soldável 25mm barra de 6 metros', 18.50, 30.00, 10.00, 50.00, 'Depósito C - Suporte Vertical', 'Tigre'),
('PROD-006', 'Registro Gaveta 3/4"', 3, 'Unidade', 'Registro gaveta metálico 3/4 polegada', 42.00, 12.00, 5.00, 20.00, 'Depósito C - Prateleira 1', 'Deca'),
('PROD-007', 'Desinfetante 5L', 4, 'Litro', 'Desinfetante concentrado aroma lavanda 5 litros', 22.90, 18.00, 10.00, 30.00, 'Almoxarifado - Prateleira A', 'Veja'),
('PROD-008', 'Vassoura de Piaçava', 4, 'Unidade', 'Vassoura de piaçava com cabo de madeira', 12.50, 15.00, 8.00, 25.00, 'Almoxarifado - Gancho Parede', 'Condor'),
('PROD-009', 'Luva de Segurança (par)', 8, 'Unidade', 'Luva de segurança em raspa tamanho G', 8.90, 25.00, 15.00, 50.00, 'Almoxarifado - Caixa EPIs', 'CA 12345'),
('PROD-010', 'Tinta Látex Branca 18L', 7, 'Litro', 'Tinta látex PVA branca fosca 18 litros', 185.00, 6.00, 3.00, 12.00, 'Depósito A - Prateleira 3', 'Suvinil');

-- Movimentações de Exemplo (Entradas)
INSERT INTO `movimentacoes_estoque` (`produto_id`, `tipo_movimentacao`, `quantidade`, `quantidade_anterior`, `quantidade_posterior`, `tipo_destino`, `usuario_responsavel`, `motivo`, `nota_fiscal`, `valor_unitario`, `valor_total`, `fornecedor`, `data_movimentacao`) VALUES
(1, 'Entrada', 25.00, 0.00, 25.00, NULL, 'Admin', 'Compra inicial de estoque', 'NF-12345', 32.50, 812.50, 'Votorantim Cimentos', '2025-10-01 08:30:00'),
(2, 'Entrada', 5.00, 0.00, 5.00, NULL, 'Admin', 'Compra inicial de estoque', 'NF-12346', 85.00, 425.00, 'Areião do João', '2025-10-01 09:00:00'),
(4, 'Entrada', 50.00, 0.00, 50.00, NULL, 'Admin', 'Compra inicial de estoque', 'NF-12347', 15.90, 795.00, 'Philips', '2025-10-01 10:15:00');

-- Movimentações de Exemplo (Saídas)
INSERT INTO `movimentacoes_estoque` (`produto_id`, `tipo_movimentacao`, `quantidade`, `quantidade_anterior`, `quantidade_posterior`, `tipo_destino`, `morador_id`, `usuario_responsavel`, `motivo`, `valor_unitario`, `valor_total`, `data_movimentacao`) VALUES
(4, 'Saida', 3.00, 50.00, 47.00, 'Morador', 185, 'Porteiro João', 'Troca de lâmpadas queimadas na unidade', 15.90, 47.70, '2025-10-10 14:20:00'),
(4, 'Saida', 2.00, 47.00, 45.00, 'Administracao', NULL, 'Zelador Pedro', 'Manutenção da área comum - portaria', 15.90, 31.80, '2025-10-15 16:45:00'),
(7, 'Saida', 5.00, 23.00, 18.00, 'Limpeza', NULL, 'Equipe de Limpeza', 'Limpeza semanal das áreas comuns', 22.90, 114.50, '2025-10-18 07:30:00');

-- Alertas de Exemplo
INSERT INTO `alertas_estoque` (`produto_id`, `tipo_alerta`, `mensagem`, `lido`) VALUES
(2, 'Estoque_Minimo', 'Areia Média (m³) está abaixo do estoque mínimo: 5.00 m³ (mínimo: 2.00 m³)', 0),
(10, 'Estoque_Minimo', 'Tinta Látex Branca 18L está abaixo do estoque mínimo: 6.00 L (mínimo: 3.00 L)', 0);

-- =============================================
-- ÍNDICES E OTIMIZAÇÕES
-- =============================================

-- Índice para busca rápida por código de barras
CREATE INDEX idx_codigo_barras ON produtos_estoque(codigo_barras);

-- Índice para busca por fornecedor
CREATE INDEX idx_fornecedor ON produtos_estoque(fornecedor);

-- Índice composto para relatórios de movimentação por período
CREATE INDEX idx_movimentacao_periodo ON movimentacoes_estoque(tipo_movimentacao, data_movimentacao);

-- =============================================
-- VIEWS ÚTEIS
-- =============================================

-- View: Produtos com Estoque Baixo
CREATE OR REPLACE VIEW vw_produtos_estoque_baixo AS
SELECT 
    p.id,
    p.codigo,
    p.nome,
    c.nome AS categoria,
    p.quantidade_estoque,
    p.estoque_minimo,
    p.unidade_medida,
    (p.estoque_minimo - p.quantidade_estoque) AS deficit,
    p.preco_unitario,
    (p.estoque_minimo - p.quantidade_estoque) * p.preco_unitario AS valor_reposicao
FROM produtos_estoque p
LEFT JOIN categorias_estoque c ON p.categoria_id = c.id
WHERE p.quantidade_estoque <= p.estoque_minimo
  AND p.ativo = 1
ORDER BY (p.estoque_minimo - p.quantidade_estoque) DESC;

-- View: Valor Total do Estoque
CREATE OR REPLACE VIEW vw_valor_total_estoque AS
SELECT 
    SUM(quantidade_estoque * preco_unitario) AS valor_total,
    COUNT(*) AS total_produtos,
    SUM(CASE WHEN quantidade_estoque <= estoque_minimo THEN 1 ELSE 0 END) AS produtos_baixo_estoque,
    SUM(CASE WHEN quantidade_estoque = 0 THEN 1 ELSE 0 END) AS produtos_zerados
FROM produtos_estoque
WHERE ativo = 1;

-- View: Movimentações com Detalhes
CREATE OR REPLACE VIEW vw_movimentacoes_detalhadas AS
SELECT 
    m.id,
    m.tipo_movimentacao,
    p.codigo AS produto_codigo,
    p.nome AS produto_nome,
    c.nome AS categoria,
    m.quantidade,
    p.unidade_medida,
    m.quantidade_anterior,
    m.quantidade_posterior,
    m.tipo_destino,
    mo.nome AS morador_nome,
    mo.unidade AS morador_unidade,
    m.usuario_responsavel,
    m.motivo,
    m.nota_fiscal,
    m.valor_unitario,
    m.valor_total,
    m.fornecedor,
    m.data_movimentacao,
    m.observacoes
FROM movimentacoes_estoque m
INNER JOIN produtos_estoque p ON m.produto_id = p.id
LEFT JOIN categorias_estoque c ON p.categoria_id = c.id
LEFT JOIN moradores mo ON m.morador_id = mo.id
ORDER BY m.data_movimentacao DESC;

-- View: Consumo por Morador
CREATE OR REPLACE VIEW vw_consumo_por_morador AS
SELECT 
    mo.id AS morador_id,
    mo.nome AS morador_nome,
    mo.unidade AS morador_unidade,
    COUNT(m.id) AS total_retiradas,
    SUM(m.quantidade) AS quantidade_total,
    SUM(m.valor_total) AS valor_total_consumido,
    MAX(m.data_movimentacao) AS ultima_retirada
FROM moradores mo
INNER JOIN movimentacoes_estoque m ON mo.id = m.morador_id
WHERE m.tipo_movimentacao = 'Saida'
GROUP BY mo.id, mo.nome, mo.unidade
ORDER BY valor_total_consumido DESC;

-- =============================================
-- TRIGGERS
-- =============================================

-- Trigger: Atualizar estoque após entrada
DELIMITER $$
CREATE TRIGGER trg_entrada_estoque
AFTER INSERT ON movimentacoes_estoque
FOR EACH ROW
BEGIN
    IF NEW.tipo_movimentacao = 'Entrada' THEN
        UPDATE produtos_estoque 
        SET quantidade_estoque = quantidade_estoque + NEW.quantidade
        WHERE id = NEW.produto_id;
    END IF;
END$$
DELIMITER ;

-- Trigger: Criar alerta de estoque baixo
DELIMITER $$
CREATE TRIGGER trg_alerta_estoque_baixo
AFTER UPDATE ON produtos_estoque
FOR EACH ROW
BEGIN
    IF NEW.quantidade_estoque <= NEW.estoque_minimo AND NEW.ativo = 1 THEN
        INSERT INTO alertas_estoque (produto_id, tipo_alerta, mensagem)
        VALUES (
            NEW.id,
            IF(NEW.quantidade_estoque = 0, 'Estoque_Zerado', 'Estoque_Minimo'),
            CONCAT(NEW.nome, ' está ', IF(NEW.quantidade_estoque = 0, 'ZERADO', 'abaixo do estoque mínimo'), ': ', NEW.quantidade_estoque, ' ', NEW.unidade_medida, ' (mínimo: ', NEW.estoque_minimo, ' ', NEW.unidade_medida, ')')
        );
    END IF;
END$$
DELIMITER ;

-- =============================================
-- STORED PROCEDURES
-- =============================================

-- Procedure: Registrar Movimentação
DELIMITER $$
CREATE PROCEDURE sp_registrar_movimentacao(
    IN p_produto_id INT,
    IN p_tipo_movimentacao ENUM('Entrada','Saida','Ajuste','Devolucao'),
    IN p_quantidade DECIMAL(10,2),
    IN p_tipo_destino VARCHAR(50),
    IN p_morador_id INT,
    IN p_usuario_responsavel VARCHAR(200),
    IN p_motivo VARCHAR(500),
    IN p_nota_fiscal VARCHAR(100),
    IN p_valor_unitario DECIMAL(10,2),
    IN p_fornecedor VARCHAR(200),
    IN p_observacoes TEXT
)
BEGIN
    DECLARE v_quantidade_anterior DECIMAL(10,2);
    DECLARE v_quantidade_posterior DECIMAL(10,2);
    DECLARE v_valor_total DECIMAL(10,2);
    
    -- Obter quantidade anterior
    SELECT quantidade_estoque INTO v_quantidade_anterior
    FROM produtos_estoque
    WHERE id = p_produto_id;
    
    -- Calcular quantidade posterior
    IF p_tipo_movimentacao = 'Entrada' OR p_tipo_movimentacao = 'Devolucao' THEN
        SET v_quantidade_posterior = v_quantidade_anterior + p_quantidade;
    ELSE
        SET v_quantidade_posterior = v_quantidade_anterior - p_quantidade;
    END IF;
    
    -- Calcular valor total
    SET v_valor_total = p_quantidade * p_valor_unitario;
    
    -- Inserir movimentação
    INSERT INTO movimentacoes_estoque (
        produto_id, tipo_movimentacao, quantidade,
        quantidade_anterior, quantidade_posterior,
        tipo_destino, morador_id, usuario_responsavel,
        motivo, nota_fiscal, valor_unitario, valor_total,
        fornecedor, observacoes
    ) VALUES (
        p_produto_id, p_tipo_movimentacao, p_quantidade,
        v_quantidade_anterior, v_quantidade_posterior,
        p_tipo_destino, p_morador_id, p_usuario_responsavel,
        p_motivo, p_nota_fiscal, p_valor_unitario, v_valor_total,
        p_fornecedor, p_observacoes
    );
    
    -- Atualizar estoque
    UPDATE produtos_estoque
    SET quantidade_estoque = v_quantidade_posterior
    WHERE id = p_produto_id;
END$$
DELIMITER ;

-- =============================================
-- PERMISSÕES E COMENTÁRIOS
-- =============================================

-- Comentários nas tabelas
ALTER TABLE categorias_estoque COMMENT = 'Categorias para organização dos produtos';
ALTER TABLE produtos_estoque COMMENT = 'Cadastro de produtos/materiais do estoque';
ALTER TABLE movimentacoes_estoque COMMENT = 'Histórico de todas as movimentações (entradas e saídas)';
ALTER TABLE alertas_estoque COMMENT = 'Alertas automáticos de estoque baixo/zerado';

-- =============================================
-- FIM DO SCRIPT
-- =============================================

