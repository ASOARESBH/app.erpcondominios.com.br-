-- =====================================================
-- TABELAS DO MÓDULO DE ABASTECIMENTO
-- =====================================================
-- Execute este script no banco de dados para criar as tabelas necessárias

-- Tabela de Veículos
CREATE TABLE IF NOT EXISTS `abastecimento_veiculos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `placa` varchar(8) NOT NULL,
  `modelo` varchar(100) NOT NULL,
  `ano` int(4) NOT NULL,
  `cor` varchar(50) NOT NULL,
  `km_inicial` int(11) NOT NULL,
  `data_cadastro` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `placa` (`placa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Lançamentos de Abastecimento
CREATE TABLE IF NOT EXISTS `abastecimento_lancamentos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `veiculo_id` int(11) NOT NULL,
  `data_abastecimento` datetime NOT NULL,
  `km_abastecimento` int(11) NOT NULL,
  `litros` decimal(10,2) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `tipo_combustivel` enum('Gasolina','Álcool','Diesel') NOT NULL,
  `operador_id` int(11) NOT NULL,
  `usuario_logado` varchar(100) NOT NULL,
  `data_registro` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `veiculo_id` (`veiculo_id`),
  KEY `operador_id` (`operador_id`),
  KEY `data_abastecimento` (`data_abastecimento`),
  CONSTRAINT `fk_abast_veiculo` FOREIGN KEY (`veiculo_id`) REFERENCES `abastecimento_veiculos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_abast_operador` FOREIGN KEY (`operador_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Recargas
CREATE TABLE IF NOT EXISTS `abastecimento_recargas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `data_recarga` datetime NOT NULL,
  `valor_recarga` decimal(10,2) NOT NULL,
  `valor_minimo` decimal(10,2) NOT NULL,
  `nf` varchar(50) DEFAULT NULL,
  `saldo_apos` decimal(10,2) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `data_registro` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `data_recarga` (`data_recarga`),
  CONSTRAINT `fk_recarga_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Saldo (registro único)
CREATE TABLE IF NOT EXISTS `abastecimento_saldo` (
  `id` int(11) NOT NULL DEFAULT 1,
  `valor` decimal(10,2) NOT NULL DEFAULT 0.00,
  `valor_minimo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `data_atualizacao` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir registro inicial de saldo
INSERT INTO `abastecimento_saldo` (`id`, `valor`, `valor_minimo`, `data_atualizacao`) 
VALUES (1, 0.00, 0.00, NOW())
ON DUPLICATE KEY UPDATE id = id;

-- =====================================================
-- ÍNDICES ADICIONAIS PARA PERFORMANCE
-- =====================================================

-- Índice composto para relatórios
CREATE INDEX idx_abast_relatorio ON abastecimento_lancamentos(veiculo_id, data_abastecimento, tipo_combustivel);

-- Índice para busca por placa
CREATE INDEX idx_veiculo_placa ON abastecimento_veiculos(placa);

-- =====================================================
-- VIEWS ÚTEIS (OPCIONAL)
-- =====================================================

-- View de Consumo por Veículo
CREATE OR REPLACE VIEW vw_consumo_veiculos AS
SELECT 
    v.id,
    v.placa,
    v.modelo,
    v.ano,
    COUNT(a.id) as total_abastecimentos,
    SUM(a.litros) as total_litros,
    SUM(a.valor) as total_gasto,
    AVG(a.valor / a.litros) as preco_medio_litro,
    MAX(a.km_abastecimento) as km_atual
FROM abastecimento_veiculos v
LEFT JOIN abastecimento_lancamentos a ON v.id = a.veiculo_id
GROUP BY v.id, v.placa, v.modelo, v.ano;

-- View de Últimos Abastecimentos
CREATE OR REPLACE VIEW vw_ultimos_abastecimentos AS
SELECT 
    a.id,
    a.data_abastecimento,
    v.placa,
    v.modelo,
    a.km_abastecimento,
    a.litros,
    a.valor,
    a.tipo_combustivel,
    u.nome as operador,
    a.usuario_logado
FROM abastecimento_lancamentos a
INNER JOIN abastecimento_veiculos v ON a.veiculo_id = v.id
INNER JOIN usuarios u ON a.operador_id = u.id
ORDER BY a.data_abastecimento DESC
LIMIT 50;

-- =====================================================
-- COMENTÁRIOS
-- =====================================================

-- Tabela abastecimento_veiculos:
-- Armazena os veículos cadastrados. Após cadastro, os dados não podem ser editados.
-- A placa é única e aceita formatos antigo (ABC-1234) e Mercosul (ABC1D23).

-- Tabela abastecimento_lancamentos:
-- Registra cada abastecimento realizado.
-- O campo usuario_logado armazena quem estava logado no momento do registro (log de auditoria).
-- O campo operador_id indica quem realizou fisicamente o abastecimento.

-- Tabela abastecimento_recargas:
-- Sistema de crédito/débito para controlar o saldo disponível.
-- O valor_minimo define quando o sistema deve alertar sobre saldo baixo.

-- Tabela abastecimento_saldo:
-- Tabela com registro único (id=1) que mantém o saldo atual do sistema.
-- É atualizada a cada abastecimento (débito) e recarga (crédito).
