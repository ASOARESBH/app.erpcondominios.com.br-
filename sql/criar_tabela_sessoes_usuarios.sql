-- =====================================================
-- SCRIPT PARA CRIAR TABELA DE SESSÕES DE USUÁRIOS
-- =====================================================
-- Este script cria a tabela necessária para rastrear
-- sessões de usuários e tempo de expiração

-- Criar tabela de sessões
CREATE TABLE IF NOT EXISTS `sessoes_usuarios` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `usuario_id` INT(11) NOT NULL,
    `session_id` VARCHAR(255) NOT NULL UNIQUE,
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'Endereço IP do cliente',
    `user_agent` TEXT COMMENT 'User Agent do navegador',
    `data_login` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data e hora do login',
    `data_expiracao` TIMESTAMP NOT NULL COMMENT 'Data e hora de expiração da sessão',
    `ultima_atividade` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última atividade do usuário',
    `ativo` TINYINT(1) DEFAULT 1 COMMENT '1 = Ativa, 0 = Encerrada',
    
    -- Chave estrangeira para tabela de usuários
    CONSTRAINT `fk_sessoes_usuarios_id` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
    
    -- Índices para melhor performance
    INDEX `idx_usuario_id` (`usuario_id`),
    INDEX `idx_session_id` (`session_id`),
    INDEX `idx_ativo` (`ativo`),
    INDEX `idx_data_expiracao` (`data_expiracao`)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabela para rastrear sessões ativas de usuários';

-- =====================================================
-- CRIAR ÍNDICE COMPOSTO PARA MELHOR PERFORMANCE
-- =====================================================
CREATE INDEX idx_usuario_ativo ON `sessoes_usuarios` (`usuario_id`, `ativo`, `data_expiracao`);

-- =====================================================
-- CRIAR VIEW PARA SESSÕES ATIVAS
-- =====================================================
CREATE OR REPLACE VIEW `v_sessoes_ativas` AS
SELECT 
    s.id,
    s.usuario_id,
    s.session_id,
    s.ip_address,
    s.data_login,
    s.data_expiracao,
    s.ultima_atividade,
    u.nome AS usuario_nome,
    u.email AS usuario_email,
    u.permissao,
    TIMESTAMPDIFF(SECOND, NOW(), s.data_expiracao) AS segundos_restantes,
    SEC_TO_TIME(TIMESTAMPDIFF(SECOND, NOW(), s.data_expiracao)) AS tempo_restante_formatado,
    TIMESTAMPDIFF(SECOND, s.data_login, NOW()) AS segundos_decorridos,
    SEC_TO_TIME(TIMESTAMPDIFF(SECOND, s.data_login, NOW())) AS tempo_decorrido_formatado
FROM `sessoes_usuarios` s
INNER JOIN `usuarios` u ON s.usuario_id = u.id
WHERE s.ativo = 1 AND s.data_expiracao > NOW();

-- =====================================================
-- CRIAR VIEW PARA HISTÓRICO DE SESSÕES
-- =====================================================
CREATE OR REPLACE VIEW `v_historico_sessoes` AS
SELECT 
    s.id,
    s.usuario_id,
    u.nome AS usuario_nome,
    u.email AS usuario_email,
    s.ip_address,
    s.data_login,
    s.data_expiracao,
    s.ultima_atividade,
    s.ativo,
    TIMESTAMPDIFF(MINUTE, s.data_login, COALESCE(s.ultima_atividade, s.data_login)) AS duracao_minutos
FROM `sessoes_usuarios` s
INNER JOIN `usuarios` u ON s.usuario_id = u.id
ORDER BY s.data_login DESC;

-- =====================================================
-- CRIAR PROCEDURE PARA LIMPAR SESSÕES EXPIRADAS
-- =====================================================
DELIMITER $$

CREATE PROCEDURE IF NOT EXISTS `limpar_sessoes_expiradas`()
BEGIN
    DECLARE sessoes_removidas INT;
    
    DELETE FROM `sessoes_usuarios` 
    WHERE data_expiracao < NOW() OR ativo = 0;
    
    SET sessoes_removidas = ROW_COUNT();
    
    -- Log da limpeza
    INSERT INTO `logs_sistema` (tipo, descricao, usuario, ip)
    VALUES ('limpeza_sessoes', CONCAT('Sessões expiradas removidas: ', sessoes_removidas), 'SISTEMA', 'localhost');
    
    SELECT sessoes_removidas AS total_removidas;
END$$

DELIMITER ;

-- =====================================================
-- CRIAR TRIGGER PARA AUDITORIA
-- =====================================================
DELIMITER $$

CREATE TRIGGER IF NOT EXISTS `tr_auditoria_sessoes_insert`
AFTER INSERT ON `sessoes_usuarios`
FOR EACH ROW
BEGIN
    INSERT INTO `logs_sistema` (tipo, descricao, usuario, ip)
    VALUES ('nova_sessao', CONCAT('Nova sessão criada para usuário ID: ', NEW.usuario_id), 'SISTEMA', NEW.ip_address);
END$$

CREATE TRIGGER IF NOT EXISTS `tr_auditoria_sessoes_delete`
AFTER DELETE ON `sessoes_usuarios`
FOR EACH ROW
BEGIN
    INSERT INTO `logs_sistema` (tipo, descricao, usuario, ip)
    VALUES ('sessao_encerrada', CONCAT('Sessão encerrada para usuário ID: ', OLD.usuario_id), 'SISTEMA', OLD.ip_address);
END$$

DELIMITER ;

-- =====================================================
-- ADICIONAR COLUNA DE SESSÃO NA TABELA USUARIOS (OPCIONAL)
-- =====================================================
-- Descomente se quiser adicionar rastreamento de sessão ativa na tabela usuarios
/*
ALTER TABLE `usuarios` ADD COLUMN `ultima_sessao_id` INT(11) DEFAULT NULL;
ALTER TABLE `usuarios` ADD COLUMN `ultima_sessao_data` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `usuarios` ADD CONSTRAINT `fk_usuarios_ultima_sessao` 
    FOREIGN KEY (`ultima_sessao_id`) REFERENCES `sessoes_usuarios`(`id`) ON DELETE SET NULL;
*/

-- =====================================================
-- VERIFICAÇÃO
-- =====================================================
-- Verificar se a tabela foi criada com sucesso
SELECT 'Tabela sessoes_usuarios criada com sucesso!' AS status;
SELECT COUNT(*) AS total_colunas FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'sessoes_usuarios';
