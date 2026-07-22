-- =====================================================
-- SISTEMA DE CONTROLE DE ACESSO - MÓDULO CHECKLIST VEICULAR
-- =====================================================
-- Execute este script no banco de dados para criar as tabelas necessárias

-- Tabela principal de Checklist Veicular
CREATE TABLE IF NOT EXISTS `checklist_veicular` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `veiculo_id` int(11) NOT NULL,
  `operador_id` int(11) NOT NULL,
  `km_inicial` int(11) NOT NULL,
  `data_hora_abertura` datetime NOT NULL,
  `status` enum('aberto','fechado') NOT NULL DEFAULT 'aberto',
  `km_final` int(11) DEFAULT NULL,
  `data_hora_fechamento` datetime DEFAULT NULL,
  `observacao_abertura` text DEFAULT NULL,
  `observacao_fechamento` text DEFAULT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `veiculo_id` (`veiculo_id`),
  KEY `operador_id` (`operador_id`),
  KEY `status` (`status`),
  KEY `data_hora_abertura` (`data_hora_abertura`),
  CONSTRAINT `fk_checklist_veiculo` FOREIGN KEY (`veiculo_id`) REFERENCES `abastecimento_veiculos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_checklist_operador` FOREIGN KEY (`operador_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Itens do Checklist
CREATE TABLE IF NOT EXISTS `checklist_itens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `checklist_id` int(11) NOT NULL,
  `tipo_item` enum('nivel','funcional') NOT NULL,
  `nome_item` varchar(100) NOT NULL,
  `categoria` varchar(50) NOT NULL,
  `valor_abertura` varchar(20) DEFAULT NULL,
  `valor_fechamento` varchar(20) DEFAULT NULL,
  `data_registro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `checklist_id` (`checklist_id`),
  KEY `categoria` (`categoria`),
  CONSTRAINT `fk_item_checklist` FOREIGN KEY (`checklist_id`) REFERENCES `checklist_veicular` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Configuração de Alertas
CREATE TABLE IF NOT EXISTS `checklist_alertas_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria` varchar(50) NOT NULL,
  `nome_alerta` varchar(100) NOT NULL,
  `km_alerta` int(11) NOT NULL,
  `descricao` text DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categoria` (`categoria`),
  KEY `ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Alertas Gerados
CREATE TABLE IF NOT EXISTS `checklist_alertas_gerados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `checklist_id` int(11) NOT NULL,
  `alerta_config_id` int(11) NOT NULL,
  `veiculo_id` int(11) NOT NULL,
  `km_atual` int(11) NOT NULL,
  `km_limite` int(11) NOT NULL,
  `categoria` varchar(50) NOT NULL,
  `descricao` text NOT NULL,
  `status` enum('pendente','resolvido','ignorado') NOT NULL DEFAULT 'pendente',
  `data_geracao` datetime NOT NULL,
  `data_resolucao` datetime DEFAULT NULL,
  `resolvido_por` int(11) DEFAULT NULL,
  `observacao_resolucao` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `checklist_id` (`checklist_id`),
  KEY `alerta_config_id` (`alerta_config_id`),
  KEY `veiculo_id` (`veiculo_id`),
  KEY `status` (`status`),
  KEY `data_geracao` (`data_geracao`),
  CONSTRAINT `fk_alerta_checklist` FOREIGN KEY (`checklist_id`) REFERENCES `checklist_veicular` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_alerta_config` FOREIGN KEY (`alerta_config_id`) REFERENCES `checklist_alertas_config` (`id`),
  CONSTRAINT `fk_alerta_veiculo` FOREIGN KEY (`veiculo_id`) REFERENCES `abastecimento_veiculos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_alerta_resolvido` FOREIGN KEY (`resolvido_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de Controle de KM por Categoria (para alertas preventivos)
CREATE TABLE IF NOT EXISTS `checklist_km_acumulado` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `veiculo_id` int(11) NOT NULL,
  `categoria` varchar(50) NOT NULL,
  `km_acumulado` int(11) NOT NULL DEFAULT 0,
  `ultimo_checklist_id` int(11) DEFAULT NULL,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `veiculo_categoria` (`veiculo_id`,`categoria`),
  KEY `ultimo_checklist_id` (`ultimo_checklist_id`),
  CONSTRAINT `fk_km_veiculo` FOREIGN KEY (`veiculo_id`) REFERENCES `abastecimento_veiculos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_km_checklist` FOREIGN KEY (`ultimo_checklist_id`) REFERENCES `checklist_veicular` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- INSERIR CONFIGURAÇÕES DE ALERTAS PADRÃO
-- =====================================================

INSERT INTO `checklist_alertas_config` (`categoria`, `nome_alerta`, `km_alerta`, `descricao`, `ativo`) VALUES
('nivel_oleo', 'Troca de Óleo', 5000, 'Alerta para troca de óleo do motor a cada 5.000 km', 1),
('nivel_agua', 'Verificação do Sistema de Arrefecimento', 10000, 'Verificação do nível e qualidade da água do radiador a cada 10.000 km', 1),
('pneu_dianteiro', 'Rodízio de Pneus Dianteiros', 8000, 'Rodízio e verificação de desgaste dos pneus dianteiros a cada 8.000 km', 1),
('pneu_traseiro', 'Rodízio de Pneus Traseiros', 8000, 'Rodízio e verificação de desgaste dos pneus traseiros a cada 8.000 km', 1),
('buzina', 'Verificação do Sistema de Buzina', 15000, 'Inspeção do sistema de buzina e conexões elétricas a cada 15.000 km', 1),
('farois', 'Manutenção do Sistema de Iluminação', 12000, 'Verificação e limpeza dos faróis, ajuste de altura a cada 12.000 km', 1),
('freios', 'Revisão do Sistema de Freios', 6000, 'Inspeção das pastilhas, discos e fluido de freio a cada 6.000 km', 1),
('cintos', 'Inspeção dos Cintos de Segurança', 20000, 'Verificação do estado e funcionamento dos cintos de segurança a cada 20.000 km', 1),
('limpadores', 'Troca de Palhetas dos Limpadores', 10000, 'Substituição das palhetas dos limpadores de para-brisa a cada 10.000 km', 1),
('extintor', 'Recarga do Extintor de Incêndio', 12000, 'Verificação da validade e recarga do extintor conforme normas a cada 12.000 km', 1)
ON DUPLICATE KEY UPDATE categoria = categoria;

-- =====================================================
-- ÍNDICES ADICIONAIS PARA PERFORMANCE
-- =====================================================

-- Índice composto para busca de checklists abertos
CREATE INDEX idx_checklist_status_data ON checklist_veicular(status, data_hora_abertura);

-- Índice para busca de alertas pendentes
CREATE INDEX idx_alertas_status_data ON checklist_alertas_gerados(status, data_geracao);

-- Índice para relatórios por veículo
CREATE INDEX idx_checklist_veiculo_data ON checklist_veicular(veiculo_id, data_hora_abertura);

-- =====================================================
-- VIEWS ÚTEIS
-- =====================================================

-- View de Checklists com informações completas
CREATE OR REPLACE VIEW vw_checklist_completo AS
SELECT 
    c.id,
    c.veiculo_id,
    v.placa,
    v.modelo as veiculo_modelo,
    c.operador_id,
    u.nome as operador_nome,
    c.km_inicial,
    c.km_final,
    (c.km_final - c.km_inicial) as km_percorrido,
    c.data_hora_abertura,
    c.data_hora_fechamento,
    c.status,
    c.observacao_abertura,
    c.observacao_fechamento
FROM checklist_veicular c
INNER JOIN abastecimento_veiculos v ON c.veiculo_id = v.id
INNER JOIN usuarios u ON c.operador_id = u.id
ORDER BY c.data_hora_abertura DESC;

-- View de Alertas Pendentes
CREATE OR REPLACE VIEW vw_alertas_pendentes AS
SELECT 
    a.id,
    a.veiculo_id,
    v.placa,
    v.modelo as veiculo_modelo,
    a.categoria,
    a.descricao,
    a.km_atual,
    a.km_limite,
    (a.km_atual - a.km_limite) as km_excedido,
    a.data_geracao,
    c.operador_id,
    u.nome as operador_nome
FROM checklist_alertas_gerados a
INNER JOIN abastecimento_veiculos v ON a.veiculo_id = v.id
INNER JOIN checklist_veicular c ON a.checklist_id = c.id
INNER JOIN usuarios u ON c.operador_id = u.id
WHERE a.status = 'pendente'
ORDER BY a.data_geracao DESC;

-- View de Estatísticas por Veículo
CREATE OR REPLACE VIEW vw_estatisticas_veiculo AS
SELECT 
    v.id as veiculo_id,
    v.placa,
    v.modelo,
    COUNT(c.id) as total_checklists,
    SUM(CASE WHEN c.status = 'aberto' THEN 1 ELSE 0 END) as checklists_abertos,
    SUM(CASE WHEN c.status = 'fechado' THEN 1 ELSE 0 END) as checklists_fechados,
    MAX(c.km_final) as ultimo_km_registrado,
    COUNT(a.id) as total_alertas,
    SUM(CASE WHEN a.status = 'pendente' THEN 1 ELSE 0 END) as alertas_pendentes
FROM abastecimento_veiculos v
LEFT JOIN checklist_veicular c ON v.id = c.veiculo_id
LEFT JOIN checklist_alertas_gerados a ON v.id = a.veiculo_id
GROUP BY v.id, v.placa, v.modelo;

-- =====================================================
-- TRIGGERS PARA AUTOMAÇÃO
-- =====================================================

-- Trigger para atualizar KM acumulado ao fechar checklist
DELIMITER $$

CREATE TRIGGER trg_atualizar_km_acumulado
AFTER UPDATE ON checklist_veicular
FOR EACH ROW
BEGIN
    IF NEW.status = 'fechado' AND OLD.status = 'aberto' THEN
        -- Atualizar KM acumulado para cada categoria de alerta ativa
        INSERT INTO checklist_km_acumulado (veiculo_id, categoria, km_acumulado, ultimo_checklist_id)
        SELECT 
            NEW.veiculo_id,
            categoria,
            (NEW.km_final - NEW.km_inicial) as km_percorrido,
            NEW.id
        FROM checklist_alertas_config
        WHERE ativo = 1
        ON DUPLICATE KEY UPDATE 
            km_acumulado = km_acumulado + (NEW.km_final - NEW.km_inicial),
            ultimo_checklist_id = NEW.id,
            data_atualizacao = CURRENT_TIMESTAMP;
    END IF;
END$$

DELIMITER ;

-- =====================================================
-- COMENTÁRIOS E DOCUMENTAÇÃO
-- =====================================================

-- Tabela checklist_veicular:
-- Armazena os checklists de veículos com status de abertura e fechamento.
-- Um checklist só pode ser fechado pelo operador que o abriu.
-- O KM final deve ser maior que o KM inicial.

-- Tabela checklist_itens:
-- Registra cada item verificado no checklist (abertura e fechamento).
-- Tipo 'nivel': valores possíveis são 'minimo', 'medio', 'maximo'
-- Tipo 'funcional': valores possíveis são 'sim', 'nao'

-- Tabela checklist_alertas_config:
-- Configuração das regras de alerta por categoria.
-- Define o KM limite para cada tipo de manutenção preventiva.

-- Tabela checklist_alertas_gerados:
-- Alertas gerados automaticamente quando o KM acumulado atinge o limite.
-- Podem ser marcados como resolvido, ignorado ou permanecer pendentes.

-- Tabela checklist_km_acumulado:
-- Controla o KM acumulado por veículo e categoria desde o último alerta.
-- Atualizada automaticamente via trigger ao fechar checklist.
