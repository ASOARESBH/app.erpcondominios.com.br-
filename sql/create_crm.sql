-- ============================================================
-- Módulo CRM — Relacionamentos e Tarefas Diretivas
-- Execute via phpMyAdmin no banco do HostGator
-- ============================================================

-- Relacionamentos / Tarefas
CREATE TABLE IF NOT EXISTS crm_relacionamentos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero              VARCHAR(20)  NOT NULL UNIQUE,          -- CRM-2026-0001
    remetente_id        INT UNSIGNED NOT NULL,
    remetente_nome      VARCHAR(150) NOT NULL,
    destinatario_tipo   ENUM('usuario','morador') NOT NULL DEFAULT 'usuario',
    destinatario_id     INT UNSIGNED NOT NULL,
    destinatario_nome   VARCHAR(150) NOT NULL,
    departamento        ENUM('ADMINISTRATIVO','MANUTENCAO','FINANCEIRO','JURIDICO','CONTABIL') NOT NULL,
    assunto             VARCHAR(255) NOT NULL,
    descricao           TEXT         DEFAULT NULL,
    status              ENUM('aberto','em_andamento','aguardando_retorno','finalizado','cancelado') NOT NULL DEFAULT 'aberto',
    prioridade          ENUM('baixa','media','alta','urgente') NOT NULL DEFAULT 'media',
    data_limite         DATETIME     DEFAULT NULL,             -- SLA deadline
    data_finalizacao    DATETIME     DEFAULT NULL,
    sla_alertado        TINYINT(1)   NOT NULL DEFAULT 0,
    ativo               TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status        (status),
    INDEX idx_departamento  (departamento),
    INDEX idx_remetente     (remetente_id),
    INDEX idx_destinatario  (destinatario_id, destinatario_tipo),
    INDEX idx_data_limite   (data_limite),
    INDEX idx_ativo         (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Interações (chat)
CREATE TABLE IF NOT EXISTS crm_interacoes (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    relacionamento_id   INT UNSIGNED NOT NULL,
    usuario_id          INT UNSIGNED NOT NULL,
    usuario_nome        VARCHAR(150) NOT NULL,
    mensagem            TEXT         NOT NULL,
    tipo                ENUM('comentario','mudanca_status','mudanca_prioridade','anexo','sistema') NOT NULL DEFAULT 'comentario',
    status_anterior     VARCHAR(50)  DEFAULT NULL,
    status_novo         VARCHAR(50)  DEFAULT NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_inter_rel FOREIGN KEY (relacionamento_id) REFERENCES crm_relacionamentos(id) ON DELETE CASCADE,
    INDEX idx_inter_rel (relacionamento_id),
    INDEX idx_inter_user (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Anexos
CREATE TABLE IF NOT EXISTS crm_anexos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    relacionamento_id   INT UNSIGNED NOT NULL,
    interacao_id        INT UNSIGNED DEFAULT NULL,
    usuario_id          INT UNSIGNED NOT NULL,
    usuario_nome        VARCHAR(150) NOT NULL,
    nome_documento      VARCHAR(200) NOT NULL,
    nome_arquivo        VARCHAR(200) NOT NULL,
    nome_original       VARCHAR(200) NOT NULL,
    caminho             VARCHAR(400) NOT NULL,
    tipo_mime           VARCHAR(100) NOT NULL,
    tamanho_bytes       INT UNSIGNED NOT NULL DEFAULT 0,
    ativo               TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_anex_rel  FOREIGN KEY (relacionamento_id) REFERENCES crm_relacionamentos(id) ON DELETE CASCADE,
    CONSTRAINT fk_anex_inter FOREIGN KEY (interacao_id) REFERENCES crm_interacoes(id) ON DELETE SET NULL,
    INDEX idx_anex_rel  (relacionamento_id),
    INDEX idx_anex_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sequência de numeração automática
CREATE TABLE IF NOT EXISTS crm_sequencia (
    ano     SMALLINT UNSIGNED NOT NULL,
    ultimo  INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (ano)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
