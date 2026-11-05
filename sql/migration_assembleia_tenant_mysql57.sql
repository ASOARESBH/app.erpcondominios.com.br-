-- ============================================================================
-- ERP CONDOMÍNIOS — Assembleias multi-tenant
-- Compatível com MySQL 5.7 / MariaDB 10.2+. Idempotente SEM consultar
-- INFORMATION_SCHEMA (o phpMyAdmin compartilhado retorna #1044 nesse caso):
-- só os erros nativos de coluna/índice duplicado (1060/1061) são ignorados.
-- ----------------------------------------------------------------------------
-- Motivo: api_assembleia.php passou a isolar assembleias por condomínio
-- (listar/obter/criar/atualizar/excluir/status/notificar). Antes a listagem
-- mostrava assembleias de todos os tenants e a criação falhava sempre.
--
-- Backfill (apenas linhas com tenant_id NULL ou 0), nesta ordem:
--   1) usuarios.tenant_id do criador (assembleias.criado_por);
--   2) usuario_tenant do criador, quando ele pertence a UM único tenant;
--   3) instalação com um único registro em `tenants`.
-- Linhas que continuarem sem tenant ficam invisíveis na API (seguro); corrija
-- manualmente: UPDATE assembleias SET tenant_id = <tenant> WHERE id = <id>;
-- Faça backup antes de aplicar em produção.
-- ============================================================================

DROP PROCEDURE IF EXISTS migration_assembleia_tenant;
DELIMITER $$
CREATE PROCEDURE migration_assembleia_tenant()
BEGIN
    -- Ignorados apenas para ADD COLUMN / ADD INDEX repetidos.
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    DECLARE CONTINUE HANDLER FOR 1061 BEGIN END;

    ALTER TABLE assembleias ADD COLUMN tenant_id INT NULL AFTER id;
    ALTER TABLE assembleias ADD INDEX idx_assembleias_tenant_data (tenant_id, data_assembleia);

    -- 1) tenant do criador
    UPDATE assembleias a
     INNER JOIN usuarios u ON u.id = a.criado_por
       SET a.tenant_id = u.tenant_id
     WHERE (a.tenant_id IS NULL OR a.tenant_id = 0)
       AND u.tenant_id IS NOT NULL AND u.tenant_id > 0;

    -- 2) criador vinculado a um único tenant
    UPDATE assembleias a
     INNER JOIN (SELECT usuario_id, MIN(tenant_id) AS tenant_id
                   FROM usuario_tenant
                  GROUP BY usuario_id
                 HAVING COUNT(DISTINCT tenant_id) = 1) ut ON ut.usuario_id = a.criado_por
       SET a.tenant_id = ut.tenant_id
     WHERE a.tenant_id IS NULL OR a.tenant_id = 0;

    -- 3) instalação com um único tenant
    IF (SELECT COUNT(*) FROM tenants) = 1 THEN
        UPDATE assembleias SET tenant_id = (SELECT id FROM tenants LIMIT 1)
         WHERE tenant_id IS NULL OR tenant_id = 0;
    END IF;
END$$
DELIMITER ;

CALL migration_assembleia_tenant();
DROP PROCEDURE IF EXISTS migration_assembleia_tenant;

-- Conferência: deve retornar 0 antes de liberar o módulo em produção.
SELECT COUNT(*) AS assembleias_sem_tenant FROM assembleias WHERE tenant_id IS NULL OR tenant_id = 0;
