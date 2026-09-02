-- Alerta de Acesso — migration MySQL 5.7
-- Executar uma vez no banco do ERP. Todas as tabelas são isoladas por tenant_id.

CREATE TABLE IF NOT EXISTS alertas_acesso (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    descricao TEXT NULL,
    severidade ENUM('informativo','atencao','critico') NOT NULL DEFAULT 'atencao',
    canais_json TEXT NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_por_usuario_id INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_alertas_tenant_ativo (tenant_id, ativo),
    INDEX idx_alertas_tenant_nome (tenant_id, nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alertas_acesso_criterios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    alerta_id INT NOT NULL,
    tipo ENUM('veiculo','pessoa','contexto') NOT NULL,
    campo VARCHAR(50) NOT NULL,
    operador ENUM('igual','contem','comeca_com') NOT NULL DEFAULT 'igual',
    valor VARCHAR(255) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_criterios_alerta (tenant_id, alerta_id),
    INDEX idx_criterios_busca (tenant_id, tipo, campo, valor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alertas_acesso_eventos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    alerta_id INT NOT NULL,
    evento_uuid VARCHAR(80) NOT NULL,
    origem VARCHAR(40) NOT NULL,
    dados_json MEDIUMTEXT NULL,
    detectado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pendente','notificado','reconhecido','ignorado') NOT NULL DEFAULT 'pendente',
    reconhecido_por_usuario_id INT NULL,
    reconhecido_em DATETIME NULL,
    UNIQUE KEY uk_alerta_evento (tenant_id, alerta_id, evento_uuid),
    INDEX idx_eventos_tenant_data (tenant_id, detectado_em),
    INDEX idx_eventos_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alertas_acesso_entregas (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    evento_id BIGINT NOT NULL,
    canal ENUM('sistema','email','whatsapp') NOT NULL,
    destinatario VARCHAR(255) NULL,
    status ENUM('pendente','enviado','falhou','nao_configurado') NOT NULL DEFAULT 'pendente',
    detalhe VARCHAR(500) NULL,
    enviado_em DATETIME NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_evento_canal_destinatario (evento_id, canal, destinatario),
    INDEX idx_entregas_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permite que o sino do sistema consulte o evento como alerta operacional.
CREATE TABLE IF NOT EXISTS alertas_acesso_leituras (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    evento_id BIGINT NOT NULL,
    usuario_id INT NOT NULL,
    lido TINYINT(1) NOT NULL DEFAULT 0,
    lido_em DATETIME NULL,
    UNIQUE KEY uk_evento_usuario (evento_id, usuario_id),
    INDEX idx_leituras_usuario (tenant_id, usuario_id, lido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A integração com RBAC deve ser cadastrada pela migration RBAC vigente do ERP.
-- O endpoint mantém a proteção administrativa legada quando essa camada não existe.
