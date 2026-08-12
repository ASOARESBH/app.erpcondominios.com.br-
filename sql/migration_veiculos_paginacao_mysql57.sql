-- ============================================================================
-- ERP CONDOMÍNIOS — ESTABILIZAÇÃO E PAGINAÇÃO DE VEÍCULOS
-- MySQL / MariaDB 5.7 — Banco: inlaud99_erpcondor
-- ============================================================================
-- Execute após backup pelo phpMyAdmin. A migration é idempotente.
-- A coluna tipo é criada fora da API para impedir ALTER TABLE durante requisições.
-- ============================================================================

DROP PROCEDURE IF EXISTS `sp_veiculos_garantir_coluna_tipo`;
DELIMITER $$
CREATE PROCEDURE `sp_veiculos_garantir_coluna_tipo`()
BEGIN
    DECLARE v_existe INT DEFAULT 0;

    SELECT COUNT(*) INTO v_existe
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'veiculos'
      AND COLUMN_NAME = 'tipo';

    IF v_existe = 0 THEN
        ALTER TABLE `veiculos`
            ADD COLUMN `tipo` VARCHAR(50) DEFAULT NULL AFTER `cor`;
    END IF;
END$$
DELIMITER ;

CALL `sp_veiculos_garantir_coluna_tipo`();
DROP PROCEDURE IF EXISTS `sp_veiculos_garantir_coluna_tipo`;

-- Auditoria somente leitura após a instalação:
-- SELECT tenant_id, COUNT(*) AS total_veiculos
-- FROM veiculos
-- GROUP BY tenant_id
-- ORDER BY tenant_id;
-- ============================================================================
