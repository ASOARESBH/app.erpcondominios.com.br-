-- ================================================================
-- Control iD — Push Mode v2 — Schema limpo
-- ================================================================
-- Execute via phpMyAdmin no HostGator.
-- NÃO remove tabelas antigas (execute o bloco DROP só quando seguro).
--
-- Tabelas novas:
--   controlid_dispositivos    — cadastro de leitores
--   controlid_eventos_acesso  — log de eventos recebidos via Push
--   controlid_fila_comandos   — fila de comandos a entregar ao leitor
-- ================================================================

-- ----------------------------------------------------------------
-- 1. Dispositivos
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS controlid_dispositivos (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nome_dispositivo    VARCHAR(120)    NOT NULL,
    serial_number       VARCHAR(80)     NOT NULL,
    descricao           TEXT            NULL,
    ultimo_keep_alive   DATETIME        NULL,
    token_autenticacao  CHAR(64)        NOT NULL,
    ativo               TINYINT(1)      NOT NULL DEFAULT 1,
    criado_em           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE  KEY uq_serial   (serial_number),
    KEY         idx_token   (token_autenticacao(16)),
    KEY         idx_ativo   (ativo)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Leitores Control iD registrados — Push Mode';

-- ----------------------------------------------------------------
-- 2. Eventos de Acesso
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS controlid_eventos_acesso (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    serial_number   VARCHAR(80)     NOT NULL,
    user_id         INT             NOT NULL DEFAULT 0,
    data_hora       DATETIME        NOT NULL,
    tipo_evento     INT             NOT NULL DEFAULT 0,
    raw_payload     JSON            NULL     COMMENT 'Payload completo para auditoria',
    criado_em       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_serial_data (serial_number, data_hora),
    KEY idx_data_hora   (data_hora),
    KEY idx_user        (user_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Eventos de acesso recebidos via Push pelo Control iD';

-- ----------------------------------------------------------------
-- 3. Fila de Comandos
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS controlid_fila_comandos (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    serial_number   VARCHAR(80)     NOT NULL,
    verbo           VARCHAR(10)     NOT NULL DEFAULT 'POST',
    endpoint        VARCHAR(255)    NOT NULL,
    corpo_json      JSON            NULL,
    status          ENUM('pendente','enviado','cancelado')
                                    NOT NULL DEFAULT 'pendente',
    criado_em       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em      DATETIME        NULL,

    PRIMARY KEY (id),
    -- índice composto para o SELECT do endpoint Push (hot path)
    KEY idx_fila    (serial_number, status, criado_em)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Comandos a serem entregues ao leitor Control iD na próxima requisição Push';

-- ================================================================
-- Bloco opcional: remover tabelas legadas
-- Execute APENAS depois de validar que o novo sistema está estável.
-- ================================================================
/*
DROP TABLE IF EXISTS controlid_push_eventos;
DROP TABLE IF EXISTS controlid_push_queue;
DROP TABLE IF EXISTS dispositivos_controlid_leituras;
DROP TABLE IF EXISTS dispositivos_controlid_sync_log;
DROP TABLE IF EXISTS bridge_eventos_log;
DROP TABLE IF EXISTS bridge_fila_comandos;
DROP TABLE IF EXISTS dispositivos_controlid;
*/
