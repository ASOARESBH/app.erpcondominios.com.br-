-- ============================================
-- TABELA DE TOKENS DE QR CODE
-- Sistema de tokens seguros com validade e uso único
-- ============================================

CREATE TABLE IF NOT EXISTS `qrcode_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `acesso_id` int(11) NOT NULL COMMENT 'ID do acesso de visitante',
  `token` varchar(64) NOT NULL COMMENT 'Token único e seguro (32 caracteres hex)',
  `expira_em` datetime NOT NULL COMMENT 'Data/hora de expiração do token',
  `usado` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Se o token já foi usado (uso único)',
  `usado_em` datetime DEFAULT NULL COMMENT 'Data/hora em que foi usado',
  `invalidado_manualmente` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Se foi invalidado manualmente',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data/hora de criação',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `acesso_id` (`acesso_id`),
  KEY `expira_em` (`expira_em`),
  KEY `usado` (`usado`),
  CONSTRAINT `fk_qrcode_tokens_acesso` FOREIGN KEY (`acesso_id`) REFERENCES `acessos_visitantes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tokens seguros para QR Code com validade e uso único';

-- ============================================
-- TABELA DE LOG DE USO DE TOKENS
-- Registra cada vez que um token é validado
-- ============================================

CREATE TABLE IF NOT EXISTS `logs_acesso_qrcode` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL COMMENT 'Token que foi usado',
  `acesso_id` int(11) NOT NULL COMMENT 'ID do acesso de visitante',
  `usado_em` datetime NOT NULL COMMENT 'Data/hora do uso',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP de onde foi validado',
  `user_agent` text DEFAULT NULL COMMENT 'User agent do dispositivo',
  `local_validacao` varchar(100) DEFAULT NULL COMMENT 'Local onde foi validado (portaria, cancela, etc)',
  PRIMARY KEY (`id`),
  KEY `token` (`token`),
  KEY `acesso_id` (`acesso_id`),
  KEY `usado_em` (`usado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Log de validações de tokens de QR Code';

-- ============================================
-- ADICIONAR COLUNA NA TABELA acessos_visitantes
-- Para armazenar o token atual do acesso
-- ============================================

ALTER TABLE `acessos_visitantes` 
ADD COLUMN `token_atual` varchar(64) DEFAULT NULL COMMENT 'Token atual do QR Code' AFTER `qr_code_imagem`,
ADD KEY `token_atual` (`token_atual`);

-- ============================================
-- ÍNDICES ADICIONAIS PARA PERFORMANCE
-- ============================================

-- Índice composto para busca rápida de tokens válidos
CREATE INDEX idx_tokens_validos ON qrcode_tokens(usado, expira_em);

-- Índice para busca por data de uso
CREATE INDEX idx_usado_em ON qrcode_tokens(usado_em);

-- ============================================
-- TRIGGER PARA ATUALIZAR token_atual
-- Quando um novo token é criado, atualiza o acesso
-- ============================================

DELIMITER $$

CREATE TRIGGER after_qrcode_token_insert
AFTER INSERT ON qrcode_tokens
FOR EACH ROW
BEGIN
    UPDATE acessos_visitantes 
    SET token_atual = NEW.token 
    WHERE id = NEW.acesso_id;
END$$

DELIMITER ;

-- ============================================
-- PROCEDURE PARA LIMPEZA AUTOMÁTICA
-- Remove tokens expirados há mais de 30 dias
-- ============================================

DELIMITER $$

CREATE PROCEDURE limpar_tokens_expirados()
BEGIN
    DECLARE tokens_removidos INT;
    
    -- Deletar tokens expirados há mais de 30 dias
    DELETE FROM qrcode_tokens 
    WHERE expira_em < NOW() 
    AND DATE(expira_em) < DATE_SUB(CURDATE(), INTERVAL 30 DAY);
    
    SET tokens_removidos = ROW_COUNT();
    
    -- Retornar resultado
    SELECT tokens_removidos as 'Tokens Removidos', NOW() as 'Data Execução';
END$$

DELIMITER ;

-- ============================================
-- VIEW PARA TOKENS ATIVOS
-- Facilita consultas de tokens válidos
-- ============================================

CREATE OR REPLACE VIEW view_tokens_ativos AS
SELECT 
    t.id,
    t.token,
    t.acesso_id,
    t.expira_em,
    t.criado_em,
    a.qr_code,
    a.tipo_acesso,
    a.data_inicial,
    a.data_final,
    v.nome_completo as visitante_nome,
    v.documento as visitante_documento,
    TIMESTAMPDIFF(HOUR, NOW(), t.expira_em) as horas_restantes
FROM qrcode_tokens t
INNER JOIN acessos_visitantes a ON t.acesso_id = a.id
INNER JOIN visitantes v ON a.visitante_id = v.id
WHERE t.usado = 0 
AND t.expira_em > NOW()
AND CURDATE() BETWEEN a.data_inicial AND a.data_final
ORDER BY t.expira_em ASC;

-- ============================================
-- VIEW PARA ESTATÍSTICAS DE USO
-- Dashboard de tokens
-- ============================================

CREATE OR REPLACE VIEW view_estatisticas_tokens AS
SELECT 
    COUNT(*) as total_tokens,
    SUM(CASE WHEN usado = 0 AND expira_em > NOW() THEN 1 ELSE 0 END) as tokens_ativos,
    SUM(CASE WHEN usado = 1 THEN 1 ELSE 0 END) as tokens_usados,
    SUM(CASE WHEN usado = 0 AND expira_em < NOW() THEN 1 ELSE 0 END) as tokens_expirados,
    SUM(CASE WHEN usado = 1 AND DATE(usado_em) = CURDATE() THEN 1 ELSE 0 END) as tokens_usados_hoje,
    SUM(CASE WHEN usado = 1 AND WEEK(usado_em) = WEEK(NOW()) THEN 1 ELSE 0 END) as tokens_usados_semana,
    SUM(CASE WHEN usado = 1 AND MONTH(usado_em) = MONTH(NOW()) THEN 1 ELSE 0 END) as tokens_usados_mes
FROM qrcode_tokens;

-- ============================================
-- DADOS INICIAIS / TESTES
-- ============================================

-- Inserir comentário de versão
INSERT INTO logs_sistema (usuario_id, acao, tabela, descricao, ip_address, data_hora)
VALUES (1, 'SISTEMA', 'qrcode_tokens', 'Tabelas de tokens de QR Code criadas com sucesso - Versão 1.0', '127.0.0.1', NOW());

-- ============================================
-- FIM DO SCRIPT
-- ============================================
