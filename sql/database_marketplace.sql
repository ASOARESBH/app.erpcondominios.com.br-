-- ================================================================================
-- SISTEMA DE MARKETPLACE - FORNECEDORES E MORADORES
-- Data: 02/12/2025
-- Versão: 1.1.0 (corrigido para MySQL 5.7 - views com subqueries)
-- ================================================================================

-- ================================================================================
-- 1. TABELA: ramos_atividade
-- Descrição: Categorias de atividades dos fornecedores
-- ================================================================================

CREATE TABLE IF NOT EXISTS ramos_atividade (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE,
    descricao TEXT,
    icone VARCHAR(50) DEFAULT 'fa-briefcase',
    ativo TINYINT(1) DEFAULT 1,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ativo (ativo),
    INDEX idx_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir ramos de atividade padrão
INSERT INTO ramos_atividade (nome, descricao, icone) VALUES
('Vidraçaria', 'Serviços de instalação e manutenção de vidros', 'fa-window-restore'),
('Encanador', 'Serviços hidráulicos e encanamento', 'fa-wrench'),
('Eletricista', 'Serviços elétricos e instalações', 'fa-bolt'),
('Pintor', 'Serviços de pintura residencial e comercial', 'fa-paint-roller'),
('Marceneiro', 'Móveis planejados e serviços de marcenaria', 'fa-hammer'),
('Jardineiro', 'Serviços de jardinagem e paisagismo', 'fa-leaf'),
('Limpeza', 'Serviços de limpeza residencial e comercial', 'fa-broom'),
('Ar Condicionado', 'Instalação e manutenção de ar condicionado', 'fa-fan'),
('Serralheiro', 'Serviços de serralheria e metalurgia', 'fa-tools'),
('Pedreiro', 'Serviços de construção e reforma', 'fa-hard-hat'),
('Chaveiro', 'Serviços de chaveiro 24h', 'fa-key'),
('Dedetização', 'Controle de pragas e dedetização', 'fa-bug'),
('Gás', 'Instalação e manutenção de gás', 'fa-fire'),
('Informática', 'Serviços de TI e suporte técnico', 'fa-laptop'),
('Delivery', 'Entrega de alimentos e produtos', 'fa-motorcycle'),
('Outros', 'Outros serviços e produtos', 'fa-ellipsis-h')
ON DUPLICATE KEY UPDATE nome=nome;

-- ================================================================================
-- 2. TABELA: fornecedores
-- Descrição: Cadastro de fornecedores do marketplace
-- ================================================================================

CREATE TABLE IF NOT EXISTS fornecedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cpf_cnpj VARCHAR(18) NOT NULL UNIQUE,
    nome_estabelecimento VARCHAR(200) NOT NULL,
    nome_responsavel VARCHAR(150),
    ramo_atividade_id INT NOT NULL,
    endereco TEXT,
    telefone VARCHAR(20),
    email VARCHAR(150) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    logo VARCHAR(255),
    descricao_negocio TEXT,
    horario_funcionamento VARCHAR(200),
    ativo TINYINT(1) DEFAULT 1,
    aprovado TINYINT(1) DEFAULT 0,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ultimo_acesso TIMESTAMP NULL,
    FOREIGN KEY (ramo_atividade_id) REFERENCES ramos_atividade(id),
    INDEX idx_cpf_cnpj (cpf_cnpj),
    INDEX idx_email (email),
    INDEX idx_ramo (ramo_atividade_id),
    INDEX idx_ativo (ativo),
    INDEX idx_aprovado (aprovado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================================
-- 3. TABELA: produtos_servicos
-- Descrição: Produtos e serviços oferecidos pelos fornecedores
-- ================================================================================

CREATE TABLE IF NOT EXISTS produtos_servicos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fornecedor_id INT NOT NULL,
    nome VARCHAR(200) NOT NULL,
    tipo ENUM('produto', 'servico') NOT NULL,
    descricao TEXT,
    valor DECIMAL(10,2) NULL,
    valor_negociavel TINYINT(1) DEFAULT 0,
    imagem VARCHAR(255),
    ativo TINYINT(1) DEFAULT 1,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id) ON DELETE CASCADE,
    INDEX idx_fornecedor (fornecedor_id),
    INDEX idx_tipo (tipo),
    INDEX idx_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================================
-- 4. TABELA: pedidos
-- Descrição: Pedidos realizados pelos moradores aos fornecedores
-- Status ENUM alinhado com o portal do morador e a API
-- ================================================================================

CREATE TABLE IF NOT EXISTS pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    morador_id INT NOT NULL,
    fornecedor_id INT NOT NULL,
    produto_servico_id INT NULL,
    descricao_pedido TEXT NOT NULL,
    valor_proposto DECIMAL(10,2) NULL,
    status ENUM(
        'enviado',
        'em_analise',
        'aceito',
        'recusado',
        'em_execucao',
        'finalizado_morador',
        'finalizado_fornecedor',
        'concluido',
        'cancelado'
    ) DEFAULT 'enviado',
    motivo_recusa TEXT NULL,
    data_pedido TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_aceite TIMESTAMP NULL,
    data_inicio_execucao TIMESTAMP NULL,
    data_finalizacao TIMESTAMP NULL,
    data_conclusao TIMESTAMP NULL,
    FOREIGN KEY (morador_id) REFERENCES moradores(id),
    FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id),
    FOREIGN KEY (produto_servico_id) REFERENCES produtos_servicos(id) ON DELETE SET NULL,
    INDEX idx_morador (morador_id),
    INDEX idx_fornecedor (fornecedor_id),
    INDEX idx_status (status),
    INDEX idx_data_pedido (data_pedido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================================
-- 5. TABELA: avaliacoes
-- Descrição: Avaliações mútuas entre moradores e fornecedores
-- ================================================================================

CREATE TABLE IF NOT EXISTS avaliacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pedido_id INT NOT NULL,
    avaliador_tipo ENUM('morador', 'fornecedor') NOT NULL,
    avaliador_id INT NOT NULL,
    avaliado_tipo ENUM('morador', 'fornecedor') NOT NULL,
    avaliado_id INT NOT NULL,
    nota INT NOT NULL,
    comentario TEXT,
    data_avaliacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
    INDEX idx_pedido (pedido_id),
    INDEX idx_avaliado (avaliado_tipo, avaliado_id),
    INDEX idx_nota (nota),
    UNIQUE KEY unique_avaliacao (pedido_id, avaliador_tipo, avaliador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================================
-- 6. TABELA: historico_status_pedido
-- Descrição: Histórico de mudanças de status dos pedidos
-- ================================================================================

CREATE TABLE IF NOT EXISTS historico_status_pedido (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pedido_id INT NOT NULL,
    status_anterior VARCHAR(50),
    status_novo VARCHAR(50) NOT NULL,
    usuario_tipo ENUM('morador', 'fornecedor', 'admin') NOT NULL,
    usuario_id INT NOT NULL,
    observacao TEXT,
    data_mudanca TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
    INDEX idx_pedido (pedido_id),
    INDEX idx_data (data_mudanca)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================================
-- 7. VIEW: v_fornecedores_completo
-- Descrição: View com dados completos dos fornecedores
-- NOTA MySQL 5.7: usa subqueries correlacionadas para evitar erro #1054
--   causado por conflito de alias em GROUP BY com múltiplos LEFT JOINs
-- ================================================================================

CREATE OR REPLACE VIEW v_fornecedores_completo AS
SELECT
    f.id,
    f.cpf_cnpj,
    f.nome_estabelecimento,
    f.nome_responsavel,
    f.ramo_atividade_id,
    r.nome  AS ramo_atividade,
    r.icone AS ramo_icone,
    f.endereco,
    f.telefone,
    f.email,
    f.logo,
    f.descricao_negocio,
    f.horario_funcionamento,
    f.ativo,
    f.aprovado,
    f.data_cadastro,
    f.ultimo_acesso,
    (SELECT COUNT(*) FROM produtos_servicos ps2
        WHERE ps2.fornecedor_id = f.id AND ps2.ativo = 1)        AS total_produtos_servicos,
    (SELECT COUNT(*) FROM pedidos p2
        WHERE p2.fornecedor_id = f.id)                           AS total_pedidos,
    COALESCE(
        (SELECT AVG(av2.nota) FROM avaliacoes av2
            WHERE av2.avaliado_id = f.id AND av2.avaliado_tipo = 'fornecedor'), 0
    )                                                            AS media_avaliacoes,
    (SELECT COUNT(*) FROM avaliacoes av3
        WHERE av3.avaliado_id = f.id AND av3.avaliado_tipo = 'fornecedor') AS total_avaliacoes
FROM fornecedores f
LEFT JOIN ramos_atividade r ON f.ramo_atividade_id = r.id;

-- ================================================================================
-- 8. VIEW: v_produtos_servicos_completo
-- Descrição: View com dados completos de produtos e serviços
-- NOTA MySQL 5.7: usa subqueries correlacionadas para evitar erro #1054
-- ================================================================================

CREATE OR REPLACE VIEW v_produtos_servicos_completo AS
SELECT
    ps.id,
    ps.fornecedor_id,
    f.nome_estabelecimento AS fornecedor_nome,
    f.telefone             AS fornecedor_telefone,
    f.email                AS fornecedor_email,
    r.nome                 AS ramo_atividade,
    ps.nome,
    ps.tipo,
    ps.descricao,
    ps.valor,
    ps.valor_negociavel,
    ps.imagem,
    ps.ativo,
    ps.data_criacao,
    COALESCE(
        (SELECT AVG(av2.nota) FROM avaliacoes av2
            WHERE av2.avaliado_id = f.id AND av2.avaliado_tipo = 'fornecedor'), 0
    )                      AS media_avaliacoes_fornecedor,
    (SELECT COUNT(*) FROM avaliacoes av3
        WHERE av3.avaliado_id = f.id AND av3.avaliado_tipo = 'fornecedor') AS total_avaliacoes_fornecedor
FROM produtos_servicos ps
INNER JOIN fornecedores f    ON ps.fornecedor_id = f.id
INNER JOIN ramos_atividade r ON f.ramo_atividade_id = r.id;

-- ================================================================================
-- 9. VIEW: v_pedidos_completo
-- Descrição: View com dados completos dos pedidos
-- ================================================================================

CREATE OR REPLACE VIEW v_pedidos_completo AS
SELECT
    p.id,
    p.morador_id,
    m.nome     AS morador_nome,
    m.unidade  AS morador_unidade,
    m.telefone AS morador_telefone,
    p.fornecedor_id,
    f.nome_estabelecimento AS fornecedor_nome,
    f.telefone             AS fornecedor_telefone,
    f.email                AS fornecedor_email,
    p.produto_servico_id,
    ps.nome AS produto_servico_nome,
    ps.tipo AS produto_servico_tipo,
    p.descricao_pedido,
    p.valor_proposto,
    p.status,
    p.motivo_recusa,
    p.data_pedido,
    p.data_aceite,
    p.data_inicio_execucao,
    p.data_finalizacao,
    p.data_conclusao,
    CASE
        WHEN p.status = 'concluido' THEN TIMESTAMPDIFF(DAY, p.data_pedido, p.data_conclusao)
        ELSE NULL
    END AS dias_para_conclusao
FROM pedidos p
INNER JOIN moradores m        ON p.morador_id = m.id
INNER JOIN fornecedores f     ON p.fornecedor_id = f.id
LEFT JOIN produtos_servicos ps ON p.produto_servico_id = ps.id;

-- ================================================================================
-- 10. VIEW: v_estatisticas_fornecedor
-- Descrição: Estatísticas por fornecedor
-- NOTA MySQL 5.7: usa subqueries correlacionadas para AVG/COUNT de avaliacoes
-- ================================================================================

CREATE OR REPLACE VIEW v_estatisticas_fornecedor AS
SELECT
    f.id AS fornecedor_id,
    f.nome_estabelecimento,
    (SELECT COUNT(*) FROM produtos_servicos ps2
        WHERE ps2.fornecedor_id = f.id AND ps2.ativo = 1)        AS total_produtos_servicos,
    (SELECT COUNT(*) FROM pedidos p2
        WHERE p2.fornecedor_id = f.id AND p2.status = 'enviado') AS pedidos_novos,
    (SELECT COUNT(*) FROM pedidos p2
        WHERE p2.fornecedor_id = f.id AND p2.status = 'em_analise') AS pedidos_em_analise,
    (SELECT COUNT(*) FROM pedidos p2
        WHERE p2.fornecedor_id = f.id AND p2.status IN ('aceito','em_execucao')) AS pedidos_em_andamento,
    (SELECT COUNT(*) FROM pedidos p2
        WHERE p2.fornecedor_id = f.id AND p2.status = 'concluido') AS pedidos_concluidos,
    (SELECT COUNT(*) FROM pedidos p2
        WHERE p2.fornecedor_id = f.id AND p2.status = 'recusado') AS pedidos_recusados,
    (SELECT COUNT(*) FROM pedidos p2
        WHERE p2.fornecedor_id = f.id AND p2.status = 'cancelado') AS pedidos_cancelados,
    COALESCE(
        (SELECT AVG(av2.nota) FROM avaliacoes av2
            WHERE av2.avaliado_id = f.id AND av2.avaliado_tipo = 'fornecedor'), 0
    )                                                            AS media_avaliacoes,
    (SELECT COUNT(*) FROM avaliacoes av3
        WHERE av3.avaliado_id = f.id AND av3.avaliado_tipo = 'fornecedor') AS total_avaliacoes,
    (SELECT COALESCE(SUM(p2.valor_proposto), 0) FROM pedidos p2
        WHERE p2.fornecedor_id = f.id AND p2.status = 'concluido'
          AND p2.valor_proposto IS NOT NULL)                      AS valor_total_vendas
FROM fornecedores f;

-- ================================================================================
-- FIM DO SCRIPT
-- ================================================================================

SELECT 'Tabelas e views criadas com sucesso!' AS Mensagem;
