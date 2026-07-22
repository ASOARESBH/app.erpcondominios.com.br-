-- ================================================================================
-- MIGRAÇÃO: rh_escala v2 — Novas colunas para regras de jornada
-- Data: 2026-05-07
-- Compatível com MySQL 5.7+
-- Seguro para executar múltiplas vezes (IF NOT EXISTS)
-- ================================================================================

-- 1. Carga horária mensal (para regime 12x alternado)
--    Armazena o total de minutos que o funcionário deve trabalhar no mês.
--    Para 12x36: ~15 dias × 720 min = 10.800 min/mês
ALTER TABLE rh_escala
    ADD COLUMN IF NOT EXISTS carga_horaria_mensal_min INT DEFAULT 0
        COMMENT 'Total de minutos de trabalho esperados no mês (usado em 12x alternado)';

-- 2. Descanso mínimo entre jornadas (em minutos)
--    CLT: mínimo 11h (660 min) entre jornadas
--    12x36: mínimo 36h (2160 min) entre jornadas
ALTER TABLE rh_escala
    ADD COLUMN IF NOT EXISTS descanso_interjornada_min INT DEFAULT 0
        COMMENT 'Descanso mínimo entre jornadas em minutos (CLT=660, 12x36=2160)';

-- 3. Flag de regime 12x36
--    Quando ativo, o sistema aplica regras específicas do regime 12x36:
--    - Carga diária de 12h (720 min)
--    - 36h de descanso entre jornadas
--    - Carga mensal calculada automaticamente (~15 dias/mês)
ALTER TABLE rh_escala
    ADD COLUMN IF NOT EXISTS regime_12x36 TINYINT(1) DEFAULT 0
        COMMENT '1 = regime 12x36 ativo (aplica regras específicas de descanso e carga mensal)';

-- 4. Atualizar escalas existentes do tipo 'alternada' com valores padrão
--    Para escalas alternadas já cadastradas, define carga mensal e descanso padrão
UPDATE rh_escala
SET
    carga_horaria_mensal_min = CASE
        WHEN carga_horaria_diaria_min >= 720 THEN carga_horaria_diaria_min * 15
        ELSE carga_horaria_diaria_min * 22
    END,
    descanso_interjornada_min = CASE
        WHEN carga_horaria_diaria_min >= 720 THEN 2160  -- 36h para 12x
        ELSE 660                                         -- 11h CLT padrão
    END,
    regime_12x36 = CASE
        WHEN carga_horaria_diaria_min >= 720 THEN 1
        ELSE 0
    END
WHERE tipo = 'alternada'
  AND (carga_horaria_mensal_min IS NULL OR carga_horaria_mensal_min = 0);

-- 5. Para escalas de controle_jornada, definir descanso CLT padrão
UPDATE rh_escala
SET descanso_interjornada_min = 660
WHERE tipo = 'controle_jornada'
  AND (descanso_interjornada_min IS NULL OR descanso_interjornada_min = 0);

-- ================================================================================
-- Verificação final
-- ================================================================================
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'rh_escala'
  AND COLUMN_NAME IN ('carga_horaria_mensal_min', 'descanso_interjornada_min', 'regime_12x36')
ORDER BY ORDINAL_POSITION;
