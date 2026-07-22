-- ============================================================
-- Migration: Control iD v2 — Campos de comunicação + limpeza
-- Versão: 2.1 — 2026-05-28
-- Compatível com: MySQL 5.7 (sem ADD COLUMN IF NOT EXISTS)
-- Descrição:
--   1. Adiciona campos de comunicação à tabela controlid_dispositivos
--   2. Cria tabelas novas se não existirem
--   3. Remove tabelas duplicadas/não utilizadas
-- ============================================================

-- ─────────────────────────────────────────────────────────────
-- PASSO 1: Procedure auxiliar para ADD COLUMN condicional
--          (MySQL 5.7 não suporta ADD COLUMN IF NOT EXISTS)
-- ─────────────────────────────────────────────────────────────

DROP PROCEDURE IF EXISTS sp_add_col;

DELIMITER $$

CREATE PROCEDURE sp_add_col(
    IN p_table  VARCHAR(64),
    IN p_col    VARCHAR(64),
    IN p_def    TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- ─────────────────────────────────────────────────────────────
-- PASSO 2: Criar tabelas novas se não existirem
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `controlid_dispositivos` (
    `id`                    INT(11)      NOT NULL AUTO_INCREMENT,
    `nome_dispositivo`      VARCHAR(200) NOT NULL,
    `serial_number`         VARCHAR(100) NOT NULL,
    `descricao`             TEXT         DEFAULT NULL,
    `token_autenticacao`    VARCHAR(255) DEFAULT NULL,
    `ultimo_keep_alive`     DATETIME     DEFAULT NULL,
    `ativo`                 TINYINT(1)   NOT NULL DEFAULT 1,
    `criado_em`             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_serial_number` (`serial_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `controlid_eventos_acesso` (
    `id`              INT(11)      NOT NULL AUTO_INCREMENT,
    `dispositivo_id`  INT(11)      NOT NULL DEFAULT 0,
    `log_id_externo`  INT(11)      NOT NULL DEFAULT 0,
    `user_id`         BIGINT       DEFAULT NULL,
    `card_value`      VARCHAR(100) DEFAULT NULL,
    `event_type`      TINYINT      DEFAULT NULL,
    `event_time`      DATETIME     NOT NULL,
    `door_id`         TINYINT      DEFAULT 0,
    `veiculo_id`      INT(11)      DEFAULT NULL,
    `morador_id`      INT(11)      DEFAULT NULL,
    `raw_payload`     TEXT         DEFAULT NULL,
    `processado`      TINYINT(1)   NOT NULL DEFAULT 0,
    `criado_em`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dispositivo` (`dispositivo_id`),
    KEY `idx_processado`  (`processado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `controlid_fila_comandos` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT,
    `dispositivo_id` INT(11)      NOT NULL,
    `tipo_comando`   VARCHAR(60)  NOT NULL,
    `payload`        TEXT         DEFAULT NULL,
    `status`         ENUM('pendente','enviado','cancelado') NOT NULL DEFAULT 'pendente',
    `criado_em`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `enviado_em`     DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_disp_status` (`dispositivo_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- PASSO 3: Adicionar campos de comunicação (condicional)
-- ─────────────────────────────────────────────────────────────

CALL sp_add_col('controlid_dispositivos', 'tipo_integracao',
    "ENUM('bridge_local','monitor_nativo','manual') NOT NULL DEFAULT 'bridge_local'");

CALL sp_add_col('controlid_dispositivos', 'modelo',
    "VARCHAR(100) NOT NULL DEFAULT ''");

CALL sp_add_col('controlid_dispositivos', 'tipo_leitor',
    "ENUM('uhf','rfid','facial','biometria','qrcode','outro') NOT NULL DEFAULT 'uhf'");

CALL sp_add_col('controlid_dispositivos', 'area_instalacao',
    "VARCHAR(150) NOT NULL DEFAULT ''");

CALL sp_add_col('controlid_dispositivos', 'sentido_acesso',
    "ENUM('ambos','entrada','saida') NOT NULL DEFAULT 'ambos'");

CALL sp_add_col('controlid_dispositivos', 'ip_local',
    "VARCHAR(45) NOT NULL DEFAULT ''");

CALL sp_add_col('controlid_dispositivos', 'porta_local',
    "SMALLINT UNSIGNED NOT NULL DEFAULT 80");

CALL sp_add_col('controlid_dispositivos', 'usuario_api',
    "VARCHAR(80) NOT NULL DEFAULT 'admin'");

CALL sp_add_col('controlid_dispositivos', 'senha_api',
    "VARCHAR(255) NOT NULL DEFAULT ''");

CALL sp_add_col('controlid_dispositivos', 'device_id_controlid',
    "INT(11) UNSIGNED NOT NULL DEFAULT 0");

CALL sp_add_col('controlid_dispositivos', 'bridge_api_key',
    "VARCHAR(100) NOT NULL DEFAULT ''");

-- Colunas de sincronização na tabela veiculos
CALL sp_add_col('veiculos', 'controlid_user_id',
    "BIGINT DEFAULT NULL");

CALL sp_add_col('veiculos', 'controlid_tag_id',
    "BIGINT DEFAULT NULL");

CALL sp_add_col('veiculos', 'controlid_sincronizado',
    "TINYINT(1) DEFAULT 0");

CALL sp_add_col('veiculos', 'controlid_sync_data',
    "DATETIME DEFAULT NULL");

-- Remove a procedure auxiliar após uso
DROP PROCEDURE IF EXISTS sp_add_col;

-- ─────────────────────────────────────────────────────────────
-- PASSO 4: Remover tabelas legadas não utilizadas
-- ─────────────────────────────────────────────────────────────

DROP TABLE IF EXISTS `dispositivos_controlid_leituras`;
DROP TABLE IF EXISTS `dispositivos_controlid_sync_log`;
DROP TABLE IF EXISTS `dispositivos_controlid`;
DROP TABLE IF EXISTS `dispositivos_seguranca`;

-- Nota: bridge_eventos_log e bridge_fila_comandos ainda são referenciadas
-- pelo bridge_receiver.php. Descomente apenas após migrar completamente.
-- DROP TABLE IF EXISTS `bridge_eventos_log`;
-- DROP TABLE IF EXISTS `bridge_fila_comandos`;
