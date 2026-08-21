-- ============================================================================
-- ERP CONDOMÍNIO — AUTORIA DO CADASTRO DE VISITANTES
-- Compatível com MySQL / MariaDB 5.7
-- Execute após backup do banco e antes de publicar os arquivos PHP/JS.
-- ============================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS migrar_autoria_visitantes_mysql57$$
CREATE PROCEDURE migrar_autoria_visitantes_mysql57()
BEGIN
    -- Origem do cadastro: usuário/funcionário, morador ou registro histórico.
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visitantes'
          AND COLUMN_NAME = 'cadastrado_por_tipo'
    ) THEN
        ALTER TABLE visitantes
            ADD COLUMN cadastrado_por_tipo ENUM('FUNCIONARIO', 'MORADOR', 'LEGADO') NOT NULL DEFAULT 'LEGADO'
            COMMENT 'Origem do cadastro do visitante'
            AFTER morador_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visitantes'
          AND COLUMN_NAME = 'cadastrado_por_usuario_id'
    ) THEN
        ALTER TABLE visitantes
            ADD COLUMN cadastrado_por_usuario_id INT(11) NULL
            COMMENT 'Usuário/funcionário autenticado que criou o visitante'
            AFTER cadastrado_por_tipo;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visitantes'
          AND COLUMN_NAME = 'cadastrado_por_morador_id'
    ) THEN
        ALTER TABLE visitantes
            ADD COLUMN cadastrado_por_morador_id INT(11) NULL
            COMMENT 'Morador autenticado que criou o visitante no portal'
            AFTER cadastrado_por_usuario_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visitantes'
          AND COLUMN_NAME = 'cadastrado_por_nome'
    ) THEN
        ALTER TABLE visitantes
            ADD COLUMN cadastrado_por_nome VARCHAR(255) NULL
            COMMENT 'Nome histórico exibido de quem realizou o cadastro'
            AFTER cadastrado_por_morador_id;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visitantes'
          AND INDEX_NAME = 'idx_visitantes_tenant_autoria'
    ) THEN
        ALTER TABLE visitantes
            ADD KEY idx_visitantes_tenant_autoria (tenant_id, cadastrado_por_tipo);
    END IF;
END$$

DELIMITER ;

CALL migrar_autoria_visitantes_mysql57();
DROP PROCEDURE IF EXISTS migrar_autoria_visitantes_mysql57;

-- Preserva a autoria possível dos registros antigos já associados a morador.
UPDATE visitantes v
LEFT JOIN moradores m
       ON m.id = v.morador_id
      AND m.tenant_id = v.tenant_id
SET v.cadastrado_por_tipo = CASE WHEN v.morador_id IS NOT NULL THEN 'MORADOR' ELSE 'LEGADO' END,
    v.cadastrado_por_morador_id = CASE WHEN v.morador_id IS NOT NULL THEN v.morador_id ELSE NULL END,
    v.cadastrado_por_nome = CASE
        WHEN v.morador_id IS NOT NULL AND m.nome IS NOT NULL AND m.nome <> '' THEN m.nome
        ELSE 'Cadastro legado'
    END
WHERE v.cadastrado_por_nome IS NULL OR v.cadastrado_por_nome = '' OR v.cadastrado_por_tipo = 'LEGADO';

-- Auditoria pós-migration (somente leitura).
SELECT cadastrado_por_tipo, COUNT(*) AS total
FROM visitantes
GROUP BY cadastrado_por_tipo
ORDER BY cadastrado_por_tipo;

SELECT v.id, v.tenant_id, v.nome_completo, v.documento,
       v.cadastrado_por_nome, v.cadastrado_por_tipo,
       v.cadastrado_por_usuario_id, v.cadastrado_por_morador_id
FROM visitantes v
ORDER BY v.tenant_id, v.id DESC
LIMIT 100;
