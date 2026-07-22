-- ============================================================
-- Módulo de E-mail e Alertas — Tabelas
-- Sistema ERP ERP Condomínio
-- ============================================================

-- Tabela de configuração SMTP (estende a existente)
CREATE TABLE IF NOT EXISTS `configuracao_smtp` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `provedor`         VARCHAR(50)  NOT NULL DEFAULT 'custom'
                     COMMENT 'gmail|outlook|sendgrid|mailgun|brevo|custom',
  `smtp_host`        VARCHAR(255) NOT NULL,
  `smtp_port`        INT(11)      NOT NULL DEFAULT 587,
  `smtp_usuario`     VARCHAR(255) NOT NULL,
  `smtp_senha`       VARCHAR(255) NOT NULL,
  `smtp_de_email`    VARCHAR(255) NOT NULL,
  `smtp_de_nome`     VARCHAR(255) NOT NULL DEFAULT 'Sistema ERP',
  `smtp_seguranca`   ENUM('tls','ssl','none') NOT NULL DEFAULT 'tls',
  `smtp_ativo`       TINYINT(1)   NOT NULL DEFAULT 1,
  `timeout`          INT(11)      NOT NULL DEFAULT 30,
  `data_criacao`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar coluna provedor se não existir (migração)
ALTER TABLE `configuracao_smtp`
  ADD COLUMN IF NOT EXISTS `provedor` VARCHAR(50) NOT NULL DEFAULT 'custom' AFTER `id`,
  ADD COLUMN IF NOT EXISTS `timeout`  INT(11)     NOT NULL DEFAULT 30       AFTER `smtp_seguranca`;

-- Tabela de alertas configuráveis por módulo
CREATE TABLE IF NOT EXISTS `email_alertas` (
  `id`                  INT(11)      NOT NULL AUTO_INCREMENT,
  `codigo`              VARCHAR(80)  NOT NULL UNIQUE
                        COMMENT 'Identificador único do alerta: modulo.evento',
  `modulo`              VARCHAR(50)  NOT NULL
                        COMMENT 'financeiro|hidrometro|moradores|visitantes|rh|sistema|acesso',
  `evento`              VARCHAR(80)  NOT NULL,
  `nome`                VARCHAR(150) NOT NULL,
  `descricao`           TEXT,
  `ativo`               TINYINT(1)   NOT NULL DEFAULT 0,
  `assunto`             VARCHAR(255) NOT NULL DEFAULT '',
  `corpo_html`          LONGTEXT,
  `variaveis`           TEXT         COMMENT 'JSON com lista de variáveis disponíveis',
  `destinatario_tipo`   ENUM('morador','admin','email_fixo','todos_admins') NOT NULL DEFAULT 'admin',
  `destinatario_email`  VARCHAR(255) DEFAULT NULL
                        COMMENT 'Usado quando destinatario_tipo = email_fixo',
  `cc_emails`           TEXT         COMMENT 'Lista de CCs separados por vírgula',
  `data_criacao`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_modulo` (`modulo`),
  KEY `idx_ativo`  (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de log de envio de e-mails (estende a existente)
CREATE TABLE IF NOT EXISTS `email_log` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `alerta_codigo`   VARCHAR(80)  DEFAULT NULL,
  `morador_id`      INT(11)      DEFAULT NULL,
  `destinatario`    VARCHAR(255) NOT NULL,
  `assunto`         VARCHAR(255) NOT NULL,
  `tipo`            VARCHAR(80)  NOT NULL,
  `status`          ENUM('enviado','erro','pendente') NOT NULL DEFAULT 'pendente',
  `erro_mensagem`   TEXT,
  `dados_contexto`  TEXT         COMMENT 'JSON com dados usados no envio',
  `data_envio`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_alerta`   (`alerta_codigo`),
  KEY `idx_morador`  (`morador_id`),
  KEY `idx_tipo`     (`tipo`),
  KEY `idx_status`   (`status`),
  KEY `idx_data`     (`data_envio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adicionar colunas novas ao email_log se não existirem (migração)
ALTER TABLE `email_log`
  ADD COLUMN IF NOT EXISTS `alerta_codigo`  VARCHAR(80) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `dados_contexto` TEXT        DEFAULT NULL AFTER `erro_mensagem`;

-- ============================================================
-- Alertas padrão do sistema (INSERT IGNORE para não duplicar)
-- ============================================================
INSERT IGNORE INTO `email_alertas`
  (`codigo`, `modulo`, `evento`, `nome`, `descricao`, `ativo`, `assunto`, `corpo_html`, `variaveis`, `destinatario_tipo`)
VALUES

-- SISTEMA
('sistema.reset_senha', 'sistema', 'reset_senha', 'Reset de Senha',
 'Enviado ao usuário quando solicita redefinição de senha.',
 1,
 'Redefinição de Senha — {{sistema_nome}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)"><div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:32px 24px;text-align:center"><img src="{{logo_url}}" alt="Logo" style="height:60px;margin-bottom:16px"><h1 style="color:#fff;margin:0;font-size:22px">Redefinição de Senha</h1></div><div style="padding:32px 24px"><p style="color:#334155;font-size:16px">Olá, <strong>{{nome_usuario}}</strong>!</p><p style="color:#64748b">Recebemos uma solicitação para redefinir a senha da sua conta no sistema <strong>{{sistema_nome}}</strong>.</p><div style="text-align:center;margin:32px 0"><a href="{{link_reset}}" style="background:#2563eb;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-size:16px;font-weight:600">Redefinir Minha Senha</a></div><p style="color:#94a3b8;font-size:13px">Este link expira em <strong>{{expira_em}}</strong>. Se você não solicitou a redefinição, ignore este e-mail.</p></div><div style="background:#f8fafc;padding:16px 24px;text-align:center;color:#94a3b8;font-size:12px">{{sistema_nome}} — {{data_envio}}</div></div>',
 '["nome_usuario","link_reset","expira_em","sistema_nome","logo_url","data_envio"]',
 'morador'),

('sistema.novo_usuario', 'sistema', 'novo_usuario', 'Boas-vindas — Novo Usuário',
 'Enviado quando um novo usuário é cadastrado no sistema.',
 0,
 'Bem-vindo ao {{sistema_nome}}!',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)"><div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:32px 24px;text-align:center"><img src="{{logo_url}}" alt="Logo" style="height:60px;margin-bottom:16px"><h1 style="color:#fff;margin:0;font-size:22px">Bem-vindo!</h1></div><div style="padding:32px 24px"><p style="color:#334155;font-size:16px">Olá, <strong>{{nome_usuario}}</strong>!</p><p style="color:#64748b">Sua conta foi criada no sistema <strong>{{sistema_nome}}</strong>. Abaixo estão seus dados de acesso:</p><div style="background:#f1f5f9;border-radius:8px;padding:16px;margin:24px 0"><p style="margin:4px 0;color:#334155"><strong>E-mail:</strong> {{email_usuario}}</p><p style="margin:4px 0;color:#334155"><strong>Perfil:</strong> {{perfil_usuario}}</p></div><div style="text-align:center;margin:24px 0"><a href="{{link_sistema}}" style="background:#2563eb;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-size:16px;font-weight:600">Acessar o Sistema</a></div></div><div style="background:#f8fafc;padding:16px 24px;text-align:center;color:#94a3b8;font-size:12px">{{sistema_nome}} — {{data_envio}}</div></div>',
 '["nome_usuario","email_usuario","perfil_usuario","link_sistema","sistema_nome","logo_url","data_envio"]',
 'morador'),

-- HIDRÔMETRO
('hidrometro.leitura_realizada', 'hidrometro', 'leitura_realizada', 'Leitura Realizada — Notificação ao Morador',
 'Enviado ao morador após o lançamento da leitura do hidrômetro.',
 0,
 'Leitura do Hidrômetro — {{mes_referencia}} — {{sistema_nome}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)"><div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:32px 24px;text-align:center"><img src="{{logo_url}}" alt="Logo" style="height:60px;margin-bottom:16px"><h1 style="color:#fff;margin:0;font-size:22px">💧 Leitura do Hidrômetro</h1></div><div style="padding:32px 24px"><p style="color:#334155;font-size:16px">Olá, <strong>{{nome_morador}}</strong>!</p><p style="color:#64748b">A leitura do hidrômetro da sua unidade foi realizada.</p><div style="background:#eff6ff;border-radius:8px;padding:20px;margin:24px 0;border-left:4px solid #2563eb"><table style="width:100%;border-collapse:collapse"><tr><td style="color:#64748b;padding:6px 0">Unidade</td><td style="color:#1e3a8a;font-weight:600;text-align:right">{{unidade}}</td></tr><tr><td style="color:#64748b;padding:6px 0">Hidrômetro Nº</td><td style="color:#1e3a8a;font-weight:600;text-align:right">{{numero_hidrometro}}</td></tr><tr><td style="color:#64748b;padding:6px 0">Leitura Anterior</td><td style="color:#1e3a8a;font-weight:600;text-align:right">{{leitura_anterior}} m³</td></tr><tr><td style="color:#64748b;padding:6px 0">Leitura Atual</td><td style="color:#1e3a8a;font-weight:600;text-align:right">{{leitura_atual}} m³</td></tr><tr><td style="color:#64748b;padding:6px 0">Consumo</td><td style="color:#1e3a8a;font-weight:600;text-align:right">{{consumo}} m³</td></tr><tr style="border-top:2px solid #2563eb"><td style="color:#334155;font-weight:700;padding:10px 0">Valor Total</td><td style="color:#2563eb;font-weight:700;font-size:18px;text-align:right">R$ {{valor_total}}</td></tr></table></div><p style="color:#94a3b8;font-size:13px">Data da leitura: {{data_leitura}}</p></div><div style="background:#f8fafc;padding:16px 24px;text-align:center;color:#94a3b8;font-size:12px">{{sistema_nome}} — {{data_envio}}</div></div>',
 '["nome_morador","unidade","numero_hidrometro","leitura_anterior","leitura_atual","consumo","valor_total","data_leitura","mes_referencia","sistema_nome","logo_url","data_envio"]',
 'morador'),

('hidrometro.consumo_alto', 'hidrometro', 'consumo_alto', 'Alerta de Consumo Elevado',
 'Enviado ao admin quando o consumo de uma unidade excede o limite configurado.',
 0,
 '⚠️ Consumo Elevado — Unidade {{unidade}} — {{sistema_nome}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)"><div style="background:linear-gradient(135deg,#dc2626,#ef4444);padding:32px 24px;text-align:center"><img src="{{logo_url}}" alt="Logo" style="height:60px;margin-bottom:16px"><h1 style="color:#fff;margin:0;font-size:22px">⚠️ Consumo Elevado</h1></div><div style="padding:32px 24px"><p style="color:#334155;font-size:16px">Alerta gerado automaticamente pelo sistema.</p><div style="background:#fef2f2;border-radius:8px;padding:20px;margin:24px 0;border-left:4px solid #dc2626"><p style="margin:4px 0;color:#334155"><strong>Unidade:</strong> {{unidade}}</p><p style="margin:4px 0;color:#334155"><strong>Morador:</strong> {{nome_morador}}</p><p style="margin:4px 0;color:#334155"><strong>Consumo atual:</strong> {{consumo}} m³</p><p style="margin:4px 0;color:#dc2626"><strong>Limite configurado:</strong> {{limite}} m³</p></div></div><div style="background:#f8fafc;padding:16px 24px;text-align:center;color:#94a3b8;font-size:12px">{{sistema_nome}} — {{data_envio}}</div></div>',
 '["nome_morador","unidade","consumo","limite","sistema_nome","logo_url","data_envio"]',
 'admin'),

-- FINANCEIRO
('financeiro.conta_vencendo', 'financeiro', 'conta_vencendo', 'Conta a Vencer em Breve',
 'Enviado ao admin X dias antes do vencimento de uma conta a pagar.',
 0,
 '⏰ Conta Vencendo em {{dias_para_vencer}} dias — {{sistema_nome}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)"><div style="background:linear-gradient(135deg,#d97706,#f59e0b);padding:32px 24px;text-align:center"><img src="{{logo_url}}" alt="Logo" style="height:60px;margin-bottom:16px"><h1 style="color:#fff;margin:0;font-size:22px">⏰ Conta Vencendo</h1></div><div style="padding:32px 24px"><p style="color:#334155">A conta abaixo vence em <strong>{{dias_para_vencer}} dias</strong>.</p><div style="background:#fffbeb;border-radius:8px;padding:20px;margin:24px 0;border-left:4px solid #f59e0b"><p style="margin:4px 0;color:#334155"><strong>Fornecedor:</strong> {{fornecedor}}</p><p style="margin:4px 0;color:#334155"><strong>Descrição:</strong> {{descricao}}</p><p style="margin:4px 0;color:#334155"><strong>Vencimento:</strong> {{data_vencimento}}</p><p style="margin:4px 0;color:#d97706;font-size:18px;font-weight:700"><strong>Valor:</strong> R$ {{valor}}</p></div></div><div style="background:#f8fafc;padding:16px 24px;text-align:center;color:#94a3b8;font-size:12px">{{sistema_nome}} — {{data_envio}}</div></div>',
 '["fornecedor","descricao","data_vencimento","valor","dias_para_vencer","sistema_nome","logo_url","data_envio"]',
 'todos_admins'),

('financeiro.conta_vencida', 'financeiro', 'conta_vencida', 'Conta Vencida',
 'Enviado ao admin quando uma conta a pagar vence sem pagamento.',
 0,
 '🔴 Conta Vencida — {{fornecedor}} — {{sistema_nome}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)"><div style="background:linear-gradient(135deg,#dc2626,#ef4444);padding:32px 24px;text-align:center"><img src="{{logo_url}}" alt="Logo" style="height:60px;margin-bottom:16px"><h1 style="color:#fff;margin:0;font-size:22px">🔴 Conta Vencida</h1></div><div style="padding:32px 24px"><div style="background:#fef2f2;border-radius:8px;padding:20px;margin:24px 0;border-left:4px solid #dc2626"><p style="margin:4px 0;color:#334155"><strong>Fornecedor:</strong> {{fornecedor}}</p><p style="margin:4px 0;color:#334155"><strong>Descrição:</strong> {{descricao}}</p><p style="margin:4px 0;color:#334155"><strong>Vencimento:</strong> {{data_vencimento}}</p><p style="margin:4px 0;color:#dc2626;font-size:18px;font-weight:700"><strong>Valor:</strong> R$ {{valor}}</p></div></div><div style="background:#f8fafc;padding:16px 24px;text-align:center;color:#94a3b8;font-size:12px">{{sistema_nome}} — {{data_envio}}</div></div>',
 '["fornecedor","descricao","data_vencimento","valor","sistema_nome","logo_url","data_envio"]',
 'todos_admins'),

-- ACESSO / VISITANTES
('acesso.visitante_registrado', 'acesso', 'visitante_registrado', 'Visitante Registrado',
 'Enviado ao morador quando um visitante é registrado para sua unidade.',
 0,
 '🔔 Visitante Registrado — Unidade {{unidade}} — {{sistema_nome}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)"><div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:32px 24px;text-align:center"><img src="{{logo_url}}" alt="Logo" style="height:60px;margin-bottom:16px"><h1 style="color:#fff;margin:0;font-size:22px">🔔 Visitante Registrado</h1></div><div style="padding:32px 24px"><p style="color:#334155;font-size:16px">Olá, <strong>{{nome_morador}}</strong>!</p><p style="color:#64748b">Um visitante foi registrado para sua unidade.</p><div style="background:#eff6ff;border-radius:8px;padding:20px;margin:24px 0;border-left:4px solid #2563eb"><p style="margin:4px 0;color:#334155"><strong>Visitante:</strong> {{nome_visitante}}</p><p style="margin:4px 0;color:#334155"><strong>Documento:</strong> {{documento_visitante}}</p><p style="margin:4px 0;color:#334155"><strong>Data/Hora:</strong> {{data_hora}}</p><p style="margin:4px 0;color:#334155"><strong>Registrado por:</strong> {{operador}}</p></div></div><div style="background:#f8fafc;padding:16px 24px;text-align:center;color:#94a3b8;font-size:12px">{{sistema_nome}} — {{data_envio}}</div></div>',
 '["nome_morador","unidade","nome_visitante","documento_visitante","data_hora","operador","sistema_nome","logo_url","data_envio"]',
 'morador'),

-- RH
('rh.aniversario_colaborador', 'rh', 'aniversario_colaborador', 'Aniversário de Colaborador',
 'Enviado ao admin com a lista de colaboradores aniversariantes do dia.',
 0,
 '🎂 Aniversariantes do Dia — {{sistema_nome}}',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)"><div style="background:linear-gradient(135deg,#7c3aed,#a855f7);padding:32px 24px;text-align:center"><img src="{{logo_url}}" alt="Logo" style="height:60px;margin-bottom:16px"><h1 style="color:#fff;margin:0;font-size:22px">🎂 Aniversariantes do Dia</h1></div><div style="padding:32px 24px"><p style="color:#334155">Os seguintes colaboradores fazem aniversário hoje:</p><div style="background:#faf5ff;border-radius:8px;padding:20px;margin:24px 0;border-left:4px solid #7c3aed">{{lista_aniversariantes}}</div></div><div style="background:#f8fafc;padding:16px 24px;text-align:center;color:#94a3b8;font-size:12px">{{sistema_nome}} — {{data_envio}}</div></div>',
 '["lista_aniversariantes","sistema_nome","logo_url","data_envio"]',
 'todos_admins'),

-- MORADORES
('moradores.cadastro_novo', 'moradores', 'cadastro_novo', 'Boas-vindas ao Morador',
 'Enviado ao morador quando seu cadastro é criado no sistema.',
 0,
 'Bem-vindo ao {{sistema_nome}}!',
 '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)"><div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);padding:32px 24px;text-align:center"><img src="{{logo_url}}" alt="Logo" style="height:60px;margin-bottom:16px"><h1 style="color:#fff;margin:0;font-size:22px">Bem-vindo!</h1></div><div style="padding:32px 24px"><p style="color:#334155;font-size:16px">Olá, <strong>{{nome_morador}}</strong>!</p><p style="color:#64748b">Seu cadastro foi realizado com sucesso no sistema <strong>{{sistema_nome}}</strong>.</p><div style="background:#eff6ff;border-radius:8px;padding:16px;margin:24px 0;border-left:4px solid #2563eb"><p style="margin:4px 0;color:#334155"><strong>Unidade:</strong> {{unidade}}</p><p style="margin:4px 0;color:#334155"><strong>CPF:</strong> {{cpf}}</p></div></div><div style="background:#f8fafc;padding:16px 24px;text-align:center;color:#94a3b8;font-size:12px">{{sistema_nome}} — {{data_envio}}</div></div>',
 '["nome_morador","unidade","cpf","sistema_nome","logo_url","data_envio"]',
 'morador');
