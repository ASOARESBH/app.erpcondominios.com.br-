-- ============================================================
-- MIGRATION: RH - Horas Extras + Escala Alternada
-- Compatível com MySQL 5.7+ (sem IF NOT EXISTS no ADD COLUMN)
-- Execute no phpMyAdmin ou via linha de comando
-- ============================================================

-- Passo 1: Atualizar ENUM de tipo_dia no lançamento de ponto
-- (adiciona 'horas_extras' ao ENUM existente)
ALTER TABLE rh_ponto_lancamento
    MODIFY COLUMN tipo_dia
        ENUM('normal','folga','falta','feriado','meio_periodo','afastamento','horas_extras')
        NOT NULL DEFAULT 'normal';

-- Passo 2: Atualizar ENUM de tipo na escala
-- (adiciona 'alternada' ao ENUM existente)
ALTER TABLE rh_escala
    MODIFY COLUMN tipo
        ENUM('livre','controle_jornada','alternada')
        NOT NULL DEFAULT 'livre';

-- Passo 3: Adicionar colunas de escala alternada (MySQL 5.7 seguro)
-- Usamos PROCEDURE para verificar se a coluna já existe antes de adicionar

DROP PROCEDURE IF EXISTS rh_add_col_alternada;

DELIMITER $$
CREATE PROCEDURE rh_add_col_alternada()
BEGIN

    -- alternada_ativa
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'rh_escala'
          AND COLUMN_NAME  = 'alternada_ativa'
    ) THEN
        ALTER TABLE rh_escala
            ADD COLUMN alternada_ativa TINYINT(1) NOT NULL DEFAULT 0
            AFTER intervalo_almoco_min;
    END IF;

    -- alternada_dia_inicio
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'rh_escala'
          AND COLUMN_NAME  = 'alternada_dia_inicio'
    ) THEN
        ALTER TABLE rh_escala
            ADD COLUMN alternada_dia_inicio DATE DEFAULT NULL
            AFTER alternada_ativa;
    END IF;

    -- alternada_semana_a
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'rh_escala'
          AND COLUMN_NAME  = 'alternada_semana_a'
    ) THEN
        ALTER TABLE rh_escala
            ADD COLUMN alternada_semana_a TEXT DEFAULT NULL
            AFTER alternada_dia_inicio;
    END IF;

    -- alternada_semana_b
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'rh_escala'
          AND COLUMN_NAME  = 'alternada_semana_b'
    ) THEN
        ALTER TABLE rh_escala
            ADD COLUMN alternada_semana_b TEXT DEFAULT NULL
            AFTER alternada_semana_a;
    END IF;

    -- alternada_tipo_folga
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'rh_escala'
          AND COLUMN_NAME  = 'alternada_tipo_folga'
    ) THEN
        ALTER TABLE rh_escala
            ADD COLUMN alternada_tipo_folga
                ENUM('folga','falta','feriado') NOT NULL DEFAULT 'folga'
            AFTER alternada_semana_b;
    END IF;

END$$
DELIMITER ;

-- Executa a procedure
CALL rh_add_col_alternada();

-- Limpa a procedure após uso
DROP PROCEDURE IF EXISTS rh_add_col_alternada;

-- ============================================================
-- Verificação final (opcional): mostre as colunas adicionadas
-- ============================================================
-- SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT
-- FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME   = 'rh_escala'
-- ORDER BY ORDINAL_POSITION;
