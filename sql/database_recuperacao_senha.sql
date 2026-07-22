-- =====================================================
-- SISTEMA DE RECUPERAÇÃO DE SENHA
-- =====================================================
-- Data: 02/12/2025
-- Descrição: Tabelas e estruturas para recuperação de senha de moradores
-- =====================================================

-- Tabela para armazenar tokens de recuperação de senha
CREATE TABLE IF NOT EXISTS recuperacao_senha_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    morador_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(200) NOT NULL,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_expiracao TIMESTAMP NOT NULL,
    usado TINYINT(1) DEFAULT 0,
    ip_solicitacao VARCHAR(45),
    user_agent TEXT,
    INDEX idx_token (token),
    INDEX idx_morador (morador_id),
    INDEX idx_usado (usado),
    INDEX idx_expiracao (data_expiracao),
    FOREIGN KEY (morador_id) REFERENCES moradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para configuração SMTP
CREATE TABLE IF NOT EXISTS configuracao_smtp (
    id INT AUTO_INCREMENT PRIMARY KEY,
    smtp_host VARCHAR(255) NOT NULL DEFAULT 'smtp.gmail.com',
    smtp_port INT NOT NULL DEFAULT 587,
    smtp_usuario VARCHAR(255) NOT NULL,
    smtp_senha VARCHAR(255) NOT NULL,
    smtp_de_email VARCHAR(255) NOT NULL,
    smtp_de_nome VARCHAR(255) NOT NULL DEFAULT 'Serra da Liberdade',
    smtp_seguranca ENUM('tls', 'ssl', 'none') DEFAULT 'tls',
    smtp_ativo TINYINT(1) DEFAULT 1,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir configuração SMTP padrão (precisa ser configurada pelo administrador)
INSERT INTO configuracao_smtp (smtp_host, smtp_port, smtp_usuario, smtp_senha, smtp_de_email, smtp_de_nome, smtp_seguranca, smtp_ativo)
VALUES 
('smtp.gmail.com', 587, 'seu-email@gmail.com', 'sua-senha-de-app', 'noreply@serraliberdade.com.br', 'Serra da Liberdade - Sistema', 'tls', 0)
ON DUPLICATE KEY UPDATE id=id;

-- Tabela para templates de e-mail
CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('recuperacao_senha', 'boas_vindas', 'notificacao') NOT NULL UNIQUE,
    assunto VARCHAR(255) NOT NULL,
    corpo TEXT NOT NULL,
    variaveis TEXT COMMENT 'JSON com variáveis disponíveis',
    ativo TINYINT(1) DEFAULT 1,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir template padrão de recuperação de senha
INSERT INTO email_templates (tipo, assunto, corpo, variaveis, ativo)
VALUES 
('recuperacao_senha', 
 'Recuperação de Senha - Serra da Liberdade',
 '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperação de Senha</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: #fff; margin: 0; font-size: 24px;">Serra da Liberdade</h1>
        <p style="color: #fff; margin: 10px 0 0 0; opacity: 0.9;">Portal do Morador</p>
    </div>
    
    <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
        <h2 style="color: #667eea; margin-top: 0;">Recuperação de Senha</h2>
        
        <p>Olá, <strong>{{NOME_MORADOR}}</strong>!</p>
        
        <p>Recebemos uma solicitação para redefinir a senha da sua conta no Portal do Morador.</p>
        
        <p>Para criar uma nova senha, clique no botão abaixo:</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{LINK_RECUPERACAO}}" 
               style="display: inline-block; 
                      padding: 15px 40px; 
                      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                      color: #fff; 
                      text-decoration: none; 
                      border-radius: 8px; 
                      font-weight: bold;
                      font-size: 16px;">
                Redefinir Senha
            </a>
        </div>
        
        <p style="font-size: 14px; color: #666;">Ou copie e cole o link abaixo no seu navegador:</p>
        <p style="background: #fff; padding: 10px; border-radius: 5px; word-break: break-all; font-size: 12px; color: #667eea;">
            {{LINK_RECUPERACAO}}
        </p>
        
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 5px;">
            <p style="margin: 0; font-size: 14px; color: #856404;">
                <strong>⚠️ Importante:</strong> Este link é válido por <strong>{{TEMPO_EXPIRACAO}}</strong>. 
                Após esse período, será necessário solicitar uma nova recuperação.
            </p>
        </div>
        
        <div style="background: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0; border-radius: 5px;">
            <p style="margin: 0; font-size: 14px; color: #721c24;">
                <strong>🔒 Segurança:</strong> Se você não solicitou esta recuperação de senha, 
                ignore este e-mail. Sua senha permanecerá inalterada.
            </p>
        </div>
        
        <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">
        
        <p style="font-size: 12px; color: #999; text-align: center; margin: 0;">
            © {{ANO}} Serra da Liberdade - Todos os direitos reservados<br>
            Este é um e-mail automático, por favor não responda.
        </p>
    </div>
</body>
</html>',
 '{"NOME_MORADOR": "Nome do morador", "LINK_RECUPERACAO": "Link para redefinir senha", "TEMPO_EXPIRACAO": "Tempo de validade", "ANO": "Ano atual"}',
 1)
ON DUPLICATE KEY UPDATE 
    assunto = VALUES(assunto),
    corpo = VALUES(corpo),
    variaveis = VALUES(variaveis);

-- Tabela para log de e-mails enviados
CREATE TABLE IF NOT EXISTS email_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    morador_id INT,
    destinatario VARCHAR(255) NOT NULL,
    assunto VARCHAR(255) NOT NULL,
    tipo ENUM('recuperacao_senha', 'boas_vindas', 'notificacao', 'outro') NOT NULL,
    status ENUM('enviado', 'erro', 'pendente') DEFAULT 'pendente',
    mensagem_erro TEXT,
    data_envio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_origem VARCHAR(45),
    INDEX idx_morador (morador_id),
    INDEX idx_status (status),
    INDEX idx_tipo (tipo),
    INDEX idx_data (data_envio),
    FOREIGN KEY (morador_id) REFERENCES moradores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SCRIPT PARA RESETAR TODAS AS SENHAS PARA 123456
-- =====================================================
-- ATENÇÃO: Execute este script apenas se tiver certeza!
-- Isso irá alterar a senha de TODOS os moradores para 123456
-- =====================================================

-- Senha: 123456 (hash MD5)
-- UPDATE moradores SET senha = MD5('123456');

-- OU se usar password_hash (recomendado):
-- UPDATE moradores SET senha = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

-- =====================================================
-- VIEWS ÚTEIS
-- =====================================================

-- View para tokens ativos (não expirados e não usados)
CREATE OR REPLACE VIEW v_tokens_ativos AS
SELECT 
    t.id,
    t.token,
    t.morador_id,
    m.nome as morador_nome,
    m.cpf as morador_cpf,
    t.email,
    t.data_criacao,
    t.data_expiracao,
    TIMESTAMPDIFF(MINUTE, NOW(), t.data_expiracao) as minutos_restantes,
    t.ip_solicitacao
FROM recuperacao_senha_tokens t
INNER JOIN moradores m ON t.morador_id = m.id
WHERE t.usado = 0 
  AND t.data_expiracao > NOW()
ORDER BY t.data_criacao DESC;

-- View para estatísticas de recuperação de senha
CREATE OR REPLACE VIEW v_estatisticas_recuperacao AS
SELECT 
    COUNT(*) as total_solicitacoes,
    SUM(CASE WHEN usado = 1 THEN 1 ELSE 0 END) as tokens_usados,
    SUM(CASE WHEN usado = 0 AND data_expiracao > NOW() THEN 1 ELSE 0 END) as tokens_ativos,
    SUM(CASE WHEN usado = 0 AND data_expiracao <= NOW() THEN 1 ELSE 0 END) as tokens_expirados,
    COUNT(DISTINCT morador_id) as moradores_distintos,
    DATE(MAX(data_criacao)) as ultima_solicitacao
FROM recuperacao_senha_tokens;

-- View para log de e-mails recentes
CREATE OR REPLACE VIEW v_emails_recentes AS
SELECT 
    e.id,
    e.destinatario,
    e.assunto,
    e.tipo,
    e.status,
    e.data_envio,
    m.nome as morador_nome,
    m.unidade as morador_unidade
FROM email_log e
LEFT JOIN moradores m ON e.morador_id = m.id
ORDER BY e.data_envio DESC
LIMIT 100;

-- =====================================================
-- LIMPEZA AUTOMÁTICA DE TOKENS EXPIRADOS
-- =====================================================
-- Criar evento para limpar tokens expirados automaticamente (executar a cada dia)

DELIMITER $$

CREATE EVENT IF NOT EXISTS limpar_tokens_expirados
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP
DO
BEGIN
    -- Deletar tokens expirados há mais de 7 dias
    DELETE FROM recuperacao_senha_tokens 
    WHERE data_expiracao < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- Deletar logs de e-mail antigos (mais de 90 dias)
    DELETE FROM email_log 
    WHERE data_envio < DATE_SUB(NOW(), INTERVAL 90 DAY);
END$$

DELIMITER ;

-- =====================================================
-- ÍNDICES ADICIONAIS PARA PERFORMANCE
-- =====================================================

-- Índice composto para busca rápida de tokens válidos
CREATE INDEX idx_token_valido ON recuperacao_senha_tokens(token, usado, data_expiracao);

-- Índice para busca por e-mail
CREATE INDEX idx_email ON recuperacao_senha_tokens(email);

-- =====================================================
-- COMENTÁRIOS NAS TABELAS
-- =====================================================

ALTER TABLE recuperacao_senha_tokens 
COMMENT = 'Armazena tokens para recuperação de senha dos moradores';

ALTER TABLE configuracao_smtp 
COMMENT = 'Configurações do servidor SMTP para envio de e-mails';

ALTER TABLE email_templates 
COMMENT = 'Templates de e-mails do sistema';

ALTER TABLE email_log 
COMMENT = 'Log de todos os e-mails enviados pelo sistema';

-- =====================================================
-- FIM DO SCRIPT
-- =====================================================
