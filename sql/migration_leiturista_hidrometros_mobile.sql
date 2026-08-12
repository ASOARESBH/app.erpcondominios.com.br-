-- ================================================================
-- Portal do Colaborador: Leitura de Hidrômetros Móvel
-- Compatível com MySQL/MariaDB 5.7
--
-- Pré-requisito: migration_multitenant_fase1.sql já executada.
-- Esta migração pode ser executada novamente com segurança.
-- ================================================================

SET @db := DATABASE();

-- Valida a estrutura mínima. Sem tenant_id não é seguro sincronizar leituras.
SET @tem_tenant_leituras := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'leituras' AND COLUMN_NAME = 'tenant_id'
);
SET @tem_tenant_fotos := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'leituras_fotos' AND COLUMN_NAME = 'tenant_id'
);

SET @sql := IF(
    @tem_tenant_leituras = 1 AND @tem_tenant_fotos = 1,
    'SELECT ''Estrutura multi-tenant validada'' AS resultado',
    'SELECT ''ERRO: execute primeiro migration_multitenant_fase1.sql e a migracao de fotos de leituras.'' AS resultado'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- UUID gerado pelo aplicativo. Evita duplicidade quando uma pendência offline é reenviada.
SET @tem_client_uuid_leituras := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'leituras' AND COLUMN_NAME = 'client_uuid'
);
SET @sql := IF(
    @tem_client_uuid_leituras = 0,
    'ALTER TABLE leituras ADD COLUMN client_uuid VARCHAR(64) NULL AFTER data_leitura',
    'SELECT ''Coluna leituras.client_uuid ja existe'' AS resultado'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tem_idx_uuid_leituras := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'leituras' AND INDEX_NAME = 'uq_leituras_tenant_client_uuid'
);
SET @sql := IF(
    @tem_idx_uuid_leituras = 0,
    'ALTER TABLE leituras ADD UNIQUE KEY uq_leituras_tenant_client_uuid (tenant_id, client_uuid)',
    'SELECT ''Indice de idempotencia das leituras ja existe'' AS resultado'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- UUID também é armazenado na foto pendente para que um novo envio não duplique a evidência.
SET @tem_client_uuid_fotos := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'leituras_fotos' AND COLUMN_NAME = 'client_uuid'
);
SET @sql := IF(
    @tem_client_uuid_fotos = 0,
    'ALTER TABLE leituras_fotos ADD COLUMN client_uuid VARCHAR(64) NULL AFTER hidrometro_id',
    'SELECT ''Coluna leituras_fotos.client_uuid ja existe'' AS resultado'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @tem_idx_uuid_fotos := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'leituras_fotos' AND INDEX_NAME = 'uq_leituras_fotos_tenant_client_uuid'
);
SET @sql := IF(
    @tem_idx_uuid_fotos = 0,
    'ALTER TABLE leituras_fotos ADD UNIQUE KEY uq_leituras_fotos_tenant_client_uuid (tenant_id, client_uuid)',
    'SELECT ''Indice de idempotencia das fotos ja existe'' AS resultado'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verificação final.
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND ((TABLE_NAME = 'leituras' AND COLUMN_NAME = 'client_uuid')
       OR (TABLE_NAME = 'leituras_fotos' AND COLUMN_NAME = 'client_uuid'))
ORDER BY TABLE_NAME;
