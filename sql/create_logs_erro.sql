-- Tabela de Logs de Erro para Debug
-- Registra erros de JavaScript, PHP e outros para facilitar debug

CREATE TABLE IF NOT EXISTS `logs_erro` (
  `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
  `tipo` ENUM('javascript', 'php', 'api', 'banco', 'outro') NOT NULL DEFAULT 'outro',
  `nivel` ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'error',
  `arquivo` VARCHAR(255) NULL COMMENT 'Arquivo onde ocorreu o erro',
  `funcao` VARCHAR(255) NULL COMMENT 'Função onde ocorreu o erro',
  `linha` INT(11) NULL COMMENT 'Linha onde ocorreu o erro',
  `mensagem` TEXT NOT NULL COMMENT 'Mensagem de erro',
  `stack_trace` TEXT NULL COMMENT 'Stack trace completo',
  `contexto` JSON NULL COMMENT 'Dados adicionais em JSON',
  `url` VARCHAR(500) NULL COMMENT 'URL onde ocorreu o erro',
  `user_agent` TEXT NULL COMMENT 'User agent do navegador',
  `usuario_id` INT(11) NULL COMMENT 'ID do usuário (se logado)',
  `ip_address` VARCHAR(45) NULL COMMENT 'IP de origem',
  `data_hora` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_tipo` (`tipo`),
  INDEX `idx_nivel` (`nivel`),
  INDEX `idx_data_hora` (`data_hora`),
  INDEX `idx_arquivo` (`arquivo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Logs de erro para debug e análise';

-- Inserir alguns exemplos para teste
INSERT INTO `logs_erro` 
(tipo, nivel, arquivo, funcao, mensagem, contexto, url, data_hora)
VALUES 
('javascript', 'error', 'visitantes.html', 'gerarQRCode', 'Erro ao gerar QR Code de teste', 
 '{"visitante_id": 1, "tipo_acesso": "portaria"}', 
 '/visitantes.html', NOW() - INTERVAL 1 HOUR),

('php', 'error', 'api_acessos_visitantes.php', 'cadastrar', 'Erro ao inserir no banco de dados', 
 '{"sql_error": "Duplicate entry", "table": "acessos_visitantes"}', 
 '/api_acessos_visitantes.php', NOW() - INTERVAL 30 MINUTE),

('api', 'warning', 'api_portal_morador.php', 'obter_hidrometro', 'Hidrômetro não encontrado', 
 '{"morador_id": 5}', 
 '/api_portal_morador.php', NOW() - INTERVAL 15 MINUTE);
