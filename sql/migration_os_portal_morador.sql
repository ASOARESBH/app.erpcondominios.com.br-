-- ============================================================
-- MIGRAÇÃO: Suporte a OS abertas pelo Portal do Morador
-- Data: 2026-06-23
-- Descrição: Adiciona campo origem_portal na tabela os_chamados
--            para identificar e sinalizar OS abertas pelo portal
-- ============================================================

-- 1. Adicionar campo origem_portal na tabela os_chamados
--    Valores: NULL ou 'interno' = aberto pelo sistema interno
--             'portal_morador'  = aberto pelo portal do morador
ALTER TABLE os_chamados
    ADD COLUMN IF NOT EXISTS origem_portal VARCHAR(30) NULL DEFAULT NULL
        COMMENT 'Origem da OS: NULL=interno, portal_morador=aberto pelo portal'
    AFTER criado_por_nome;

-- 2. Adicionar campo assumido_por_id e assumido_por_nome
--    (obrigatório para OS abertas pelo portal — atendente deve assumir)
ALTER TABLE os_chamados
    ADD COLUMN IF NOT EXISTS assumido_por_id   INT NULL DEFAULT NULL
        COMMENT 'ID do atendente que assumiu a OS do portal'
    AFTER origem_portal,
    ADD COLUMN IF NOT EXISTS assumido_por_nome VARCHAR(150) NULL DEFAULT NULL
        COMMENT 'Nome do atendente que assumiu a OS do portal'
    AFTER assumido_por_id,
    ADD COLUMN IF NOT EXISTS data_assumido     DATETIME NULL DEFAULT NULL
        COMMENT 'Data/hora em que a OS foi assumida pelo atendente'
    AFTER assumido_por_nome;

-- 3. Índice para filtrar rapidamente OS do portal
ALTER TABLE os_chamados
    ADD INDEX IF NOT EXISTS idx_origem_portal (origem_portal);

-- 4. Índice para filtrar por morador_id (para listar OS do morador no portal)
ALTER TABLE os_chamados
    ADD INDEX IF NOT EXISTS idx_morador_id (morador_id);

-- Verificar resultado
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'os_chamados'
  AND COLUMN_NAME IN ('origem_portal','assumido_por_id','assumido_por_nome','data_assumido')
ORDER BY ORDINAL_POSITION;
