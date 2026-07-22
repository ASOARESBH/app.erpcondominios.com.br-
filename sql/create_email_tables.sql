-- =====================================================
-- SCRIPT DE CRIAÇÃO/ATUALIZAÇÃO DAS TABELAS DE E-MAIL
-- =====================================================
-- Data: 29/12/2025
-- Descrição: Garante que as tabelas necessárias para
--            o sistema de e-mail existam e estejam atualizadas
-- =====================================================

-- Tabela de configuração SMTP
CREATE TABLE IF NOT EXISTS `configuracao_smtp` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `smtp_host` varchar(255) NOT NULL,
  `smtp_port` int(11) NOT NULL DEFAULT 587,
  `smtp_usuario` varchar(255) NOT NULL,
  `smtp_senha` varchar(255) NOT NULL,
  `smtp_de_email` varchar(255) NOT NULL,
  `smtp_de_nome` varchar(255) NOT NULL,
  `smtp_seguranca` enum('tls','ssl','none') NOT NULL DEFAULT 'tls',
  `smtp_ativo` tinyint(1) NOT NULL DEFAULT 1,
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de templates de e-mail
CREATE TABLE IF NOT EXISTS `email_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(50) NOT NULL COMMENT 'recuperacao_senha, boas_vindas, notificacao, etc',
  `assunto` varchar(255) NOT NULL,
  `corpo` text NOT NULL COMMENT 'HTML do e-mail com variáveis {{NOME}}, {{LINK}}, etc',
  `variaveis_disponiveis` text COMMENT 'JSON com lista de variáveis disponíveis',
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de log de e-mails enviados
CREATE TABLE IF NOT EXISTS `email_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `morador_id` int(11) DEFAULT NULL,
  `destinatario` varchar(255) NOT NULL,
  `assunto` varchar(255) NOT NULL,
  `tipo` varchar(50) NOT NULL COMMENT 'recuperacao_senha, notificacao, outro',
  `status` enum('enviado','erro','pendente') NOT NULL DEFAULT 'pendente',
  `erro_mensagem` text,
  `data_envio` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `morador_id` (`morador_id`),
  KEY `tipo` (`tipo`),
  KEY `status` (`status`),
  KEY `data_envio` (`data_envio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar coluna erro_mensagem se não existir
ALTER TABLE `email_log` 
ADD COLUMN IF NOT EXISTS `erro_mensagem` text AFTER `status`;

-- Inserir template padrão de recuperação de senha se não existir
INSERT IGNORE INTO `email_templates` (`tipo`, `assunto`, `corpo`, `variaveis_disponiveis`, `ativo`) VALUES
('recuperacao_senha', 'Recuperação de Senha - Serra da Liberdade', 
'<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); color: #fff; padding: 30px 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { background: #f8fafc; padding: 30px 20px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 20px 0; }
        .warning { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #64748b; padding: 20px; }
        .link-box { background: #e2e8f0; padding: 15px; border-radius: 4px; word-break: break-all; font-size: 12px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Recuperação de Senha</h1>
        </div>
        <div class="content">
            <p>Olá, <strong>{{NOME_MORADOR}}</strong>!</p>
            <p>Recebemos uma solicitação de recuperação de senha para sua conta no sistema <strong>Serra da Liberdade</strong>.</p>
            <p>Para redefinir sua senha, clique no botão abaixo:</p>
            <div style="text-align: center;">
                <a href="{{LINK_RECUPERACAO}}" class="button">Redefinir Minha Senha</a>
            </div>
            <div class="warning">
                <p><strong>⚠️ Importante:</strong></p>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>Este link é válido por <strong>{{TEMPO_EXPIRACAO}}</strong></li>
                    <li>Pode ser usado apenas <strong>uma vez</strong></li>
                    <li>Se você não solicitou esta recuperação, ignore este e-mail</li>
                    <li>Sua senha atual continuará válida até que você a altere</li>
                </ul>
            </div>
            <p><strong>Se o botão não funcionar</strong>, copie e cole o link abaixo no seu navegador:</p>
            <div class="link-box">{{LINK_RECUPERACAO}}</div>
        </div>
        <div class="footer">
            <p><strong>Serra da Liberdade</strong></p>
            <p>Sistema de Controle de Acesso</p>
            <p>Este é um e-mail automático, não responda.</p>
            <p>&copy; {{ANO}} - Todos os direitos reservados</p>
        </div>
    </div>
</body>
</html>', 
'{"variaveis": ["{{NOME_MORADOR}}", "{{LINK_RECUPERACAO}}", "{{TEMPO_EXPIRACAO}}", "{{ANO}}"], "descricao": {"{{NOME_MORADOR}}": "Nome do morador", "{{LINK_RECUPERACAO}}": "Link para redefinir senha", "{{TEMPO_EXPIRACAO}}": "Tempo de validade do link", "{{ANO}}": "Ano atual"}}', 
1);

-- Inserir template de boas-vindas se não existir
INSERT IGNORE INTO `email_templates` (`tipo`, `assunto`, `corpo`, `variaveis_disponiveis`, `ativo`) VALUES
('boas_vindas', 'Bem-vindo ao Sistema Serra da Liberdade', 
'<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); color: #fff; padding: 30px 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { background: #f8fafc; padding: 30px 20px; border-radius: 0 0 8px 8px; }
        .info-box { background: #dbeafe; border-left: 4px solid #3b82f6; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #64748b; padding: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏡 Bem-vindo!</h1>
        </div>
        <div class="content">
            <p>Olá, <strong>{{NOME_MORADOR}}</strong>!</p>
            <p>Seja bem-vindo ao <strong>Sistema Serra da Liberdade</strong>!</p>
            <p>Seu cadastro foi realizado com sucesso. Através do nosso portal, você poderá:</p>
            <ul>
                <li>Gerenciar visitantes e acessos</li>
                <li>Consultar protocolos de entregas</li>
                <li>Visualizar leituras de hidrômetro</li>
                <li>Receber notificações importantes</li>
                <li>E muito mais!</li>
            </ul>
            <div class="info-box">
                <p><strong>📱 Acesse o portal:</strong></p>
                <p><a href="{{LINK_PORTAL}}">{{LINK_PORTAL}}</a></p>
                <p><strong>👤 Seu CPF é seu usuário de acesso</strong></p>
            </div>
            <p>Em caso de dúvidas, entre em contato com a administração.</p>
        </div>
        <div class="footer">
            <p><strong>Serra da Liberdade</strong></p>
            <p>Sistema de Controle de Acesso</p>
            <p>&copy; {{ANO}} - Todos os direitos reservados</p>
        </div>
    </div>
</body>
</html>', 
'{"variaveis": ["{{NOME_MORADOR}}", "{{LINK_PORTAL}}", "{{ANO}}"], "descricao": {"{{NOME_MORADOR}}": "Nome do morador", "{{LINK_PORTAL}}": "Link do portal do morador", "{{ANO}}": "Ano atual"}}', 
1);

-- Verificar se há configuração SMTP ativa
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN 'Configuração SMTP encontrada'
        ELSE 'ATENÇÃO: Nenhuma configuração SMTP encontrada. Configure em config_smtp.html'
    END as status
FROM configuracao_smtp 
WHERE smtp_ativo = 1;

-- Estatísticas de e-mails
SELECT 
    tipo,
    status,
    COUNT(*) as total,
    MAX(data_envio) as ultimo_envio
FROM email_log
GROUP BY tipo, status
ORDER BY tipo, status;
