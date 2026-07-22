-- ============================================
-- TABELA DE DISPOSITIVOS (TABLETS)
-- Sistema de autenticação de tablets para portaria
-- ============================================

CREATE TABLE IF NOT EXISTS `dispositivos_tablets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL COMMENT 'Nome do dispositivo (ex: Tablet Portaria Principal)',
  `token` varchar(12) NOT NULL COMMENT 'Token único de 12 caracteres alfanuméricos',
  `secret` varchar(32) NOT NULL COMMENT 'Contra-chave para segurança adicional',
  `status` enum('ativo','inativo') NOT NULL DEFAULT 'ativo' COMMENT 'Status do dispositivo',
  `local` varchar(100) DEFAULT NULL COMMENT 'Local onde está instalado (ex: Portaria Principal)',
  `descricao` text DEFAULT NULL COMMENT 'Descrição adicional do dispositivo',
  `ultimo_acesso` datetime DEFAULT NULL COMMENT 'Data/hora do último uso',
  `total_validacoes` int(11) NOT NULL DEFAULT 0 COMMENT 'Total de validações realizadas',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data/hora de cadastro',
  `criado_por` int(11) DEFAULT NULL COMMENT 'ID do usuário que cadastrou',
  `atualizado_em` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT 'Data/hora da última atualização',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `status` (`status`),
  KEY `criado_por` (`criado_por`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dispositivos autorizados para validação de QR Code';

-- ============================================
-- TABELA DE LOG DE VALIDAÇÕES POR DISPOSITIVO
-- Registra cada validação feita por cada tablet
-- ============================================

CREATE TABLE IF NOT EXISTS `logs_validacoes_dispositivo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dispositivo_id` int(11) NOT NULL COMMENT 'ID do dispositivo',
  `token_qrcode` varchar(64) DEFAULT NULL COMMENT 'Token do QR Code validado',
  `acesso_id` int(11) DEFAULT NULL COMMENT 'ID do acesso de visitante',
  `resultado` enum('sucesso','falha') NOT NULL COMMENT 'Resultado da validação',
  `motivo_falha` varchar(255) DEFAULT NULL COMMENT 'Motivo da falha (se houver)',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP do dispositivo',
  `user_agent` text DEFAULT NULL COMMENT 'User agent do navegador',
  `validado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data/hora da validação',
  PRIMARY KEY (`id`),
  KEY `dispositivo_id` (`dispositivo_id`),
  KEY `token_qrcode` (`token_qrcode`),
  KEY `acesso_id` (`acesso_id`),
  KEY `validado_em` (`validado_em`),
  CONSTRAINT `fk_logs_validacoes_dispositivo` FOREIGN KEY (`dispositivo_id`) REFERENCES `dispositivos_tablets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de validações realizadas por cada dispositivo';

-- ============================================
-- ÍNDICES ADICIONAIS PARA PERFORMANCE
-- ============================================

CREATE INDEX idx_dispositivo_status ON dispositivos_tablets(status, ultimo_acesso);
CREATE INDEX idx_log_resultado ON logs_validacoes_dispositivo(resultado, validado_em);

-- ============================================
-- TRIGGER PARA ATUALIZAR ÚLTIMO ACESSO
-- Quando uma validação é registrada, atualiza o dispositivo
-- ============================================

DELIMITER $$

CREATE TRIGGER after_validacao_dispositivo_insert
AFTER INSERT ON logs_validacoes_dispositivo
FOR EACH ROW
BEGIN
    UPDATE dispositivos_tablets 
    SET 
        ultimo_acesso = NEW.validado_em,
        total_validacoes = total_validacoes + 1
    WHERE id = NEW.dispositivo_id;
END$$

DELIMITER ;

-- ============================================
-- VIEW PARA DISPOSITIVOS ATIVOS
-- Facilita consultas de dispositivos em uso
-- ============================================

CREATE OR REPLACE VIEW view_dispositivos_ativos AS
SELECT 
    d.id,
    d.nome,
    d.token,
    d.local,
    d.status,
    d.ultimo_acesso,
    d.total_validacoes,
    d.criado_em,
    TIMESTAMPDIFF(MINUTE, d.ultimo_acesso, NOW()) as minutos_desde_ultimo_uso,
    COUNT(l.id) as validacoes_hoje
FROM dispositivos_tablets d
LEFT JOIN logs_validacoes_dispositivo l ON d.id = l.dispositivo_id 
    AND DATE(l.validado_em) = CURDATE()
WHERE d.status = 'ativo'
GROUP BY d.id
ORDER BY d.ultimo_acesso DESC;

-- ============================================
-- VIEW PARA ESTATÍSTICAS DE DISPOSITIVOS
-- Dashboard de uso dos tablets
-- ============================================

CREATE OR REPLACE VIEW view_estatisticas_dispositivos AS
SELECT 
    COUNT(*) as total_dispositivos,
    SUM(CASE WHEN status = 'ativo' THEN 1 ELSE 0 END) as dispositivos_ativos,
    SUM(CASE WHEN status = 'inativo' THEN 1 ELSE 0 END) as dispositivos_inativos,
    SUM(total_validacoes) as total_validacoes_geral,
    (SELECT COUNT(*) FROM logs_validacoes_dispositivo WHERE DATE(validado_em) = CURDATE()) as validacoes_hoje,
    (SELECT COUNT(*) FROM logs_validacoes_dispositivo WHERE resultado = 'sucesso' AND DATE(validado_em) = CURDATE()) as validacoes_sucesso_hoje,
    (SELECT COUNT(*) FROM logs_validacoes_dispositivo WHERE resultado = 'falha' AND DATE(validado_em) = CURDATE()) as validacoes_falha_hoje
FROM dispositivos_tablets;

-- ============================================
-- PROCEDURE PARA GERAR TOKEN DE DISPOSITIVO
-- Gera token único de 12 caracteres alfanuméricos
-- ============================================

DELIMITER $$

CREATE PROCEDURE gerar_token_dispositivo()
BEGIN
    DECLARE novo_token VARCHAR(12);
    DECLARE token_existe INT;
    
    -- Loop até gerar token único
    REPEAT
        -- Gerar token aleatório de 12 caracteres (A-Z, 0-9)
        SET novo_token = CONCAT(
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1),
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1),
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1),
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1),
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1),
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1),
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1),
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1),
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1),
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1),
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1),
            SUBSTRING('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', FLOOR(1 + RAND() * 32), 1)
        );
        
        -- Verificar se token já existe
        SELECT COUNT(*) INTO token_existe 
        FROM dispositivos_tablets 
        WHERE token = novo_token;
        
    UNTIL token_existe = 0
    END REPEAT;
    
    -- Retornar token gerado
    SELECT novo_token as token;
END$$

DELIMITER ;

-- ============================================
-- PROCEDURE PARA GERAR SECRET
-- Gera secret de 32 caracteres
-- ============================================

DELIMITER $$

CREATE PROCEDURE gerar_secret_dispositivo()
BEGIN
    DECLARE novo_secret VARCHAR(32);
    
    -- Gerar secret aleatório de 32 caracteres
    SET novo_secret = MD5(CONCAT(UUID(), RAND(), NOW()));
    
    -- Retornar secret gerado
    SELECT novo_secret as secret;
END$$

DELIMITER ;

-- ============================================
-- DADOS INICIAIS / EXEMPLO
-- ============================================

-- Inserir dispositivo de exemplo (comentado para não criar automaticamente)
-- CALL gerar_token_dispositivo();
-- CALL gerar_secret_dispositivo();
-- INSERT INTO dispositivos_tablets (nome, token, secret, local, descricao, criado_por)
-- VALUES ('Tablet Portaria Principal', 'A9F3K7L2Q8M4', 'xP9dQ2aM7ZkL3nR5tY8wC1vB6hJ4gF2s', 'Portaria Principal', 'Tablet Samsung Galaxy Tab A7 - Portaria Principal', 1);

-- Inserir log no sistema
INSERT INTO logs_sistema (usuario_id, acao, tabela, descricao, ip_address, data_hora)
VALUES (1, 'SISTEMA', 'dispositivos_tablets', 'Tabelas de dispositivos tablets criadas com sucesso - Versão 1.0', '127.0.0.1', NOW());

-- ============================================
-- FIM DO SCRIPT
-- ============================================
