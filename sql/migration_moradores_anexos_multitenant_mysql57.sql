-- Correção multi-tenant dos anexos de moradores.
-- Compatível com MySQL 5.7 e usuários HostGator sem permissão em information_schema.
-- Execute no banco selecionado pelo phpMyAdmin.

-- A tabela moradores_anexos precisa existir previamente.
-- Se ela ainda não existir, execute antes create_moradores_anexos.sql.

DELIMITER $$

DROP PROCEDURE IF EXISTS corrigir_moradores_anexos_tenant$$
CREATE PROCEDURE corrigir_moradores_anexos_tenant()
BEGIN
    -- 1060 = coluna duplicada: significa que a migração já foi aplicada.
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    ALTER TABLE moradores_anexos
        ADD COLUMN tenant_id INT NOT NULL DEFAULT 1 AFTER id;
END$$

CALL corrigir_moradores_anexos_tenant()$$
DROP PROCEDURE corrigir_moradores_anexos_tenant$$

DROP PROCEDURE IF EXISTS corrigir_indice_moradores_anexos$$
CREATE PROCEDURE corrigir_indice_moradores_anexos()
BEGIN
    -- 1061 = índice duplicado: significa que o índice já existe.
    DECLARE CONTINUE HANDLER FOR 1061 BEGIN END;
    ALTER TABLE moradores_anexos
        ADD INDEX idx_tenant_morador (tenant_id, morador_id);
END$$

CALL corrigir_indice_moradores_anexos()$$
DROP PROCEDURE corrigir_indice_moradores_anexos$$

DELIMITER ;

-- Conferência sem consultar information_schema.
SHOW COLUMNS FROM moradores_anexos;
SHOW INDEX FROM moradores_anexos;

-- Registros antigos recebem tenant_id=1 pelo DEFAULT da coluna.
-- Se existirem vários condomínios no mesmo banco, revise os registros antigos
-- antes de liberar o módulo para os demais tenants.

SELECT 'Migração de moradores_anexos concluída' AS status;

-- Importante: o upload também requer a tabela tenant_arquivos.
-- Se ela não existir, importe sql/migration_arquivos_tenant_mysql57.sql.

-- Fim da migração.

-- Diagnóstico: o arquivo anterior falhava somente na consulta final ao
-- information_schema por falta de privilégio do usuário MySQL do HostGator.
-- As alterações anteriores podem ter sido aplicadas; por isso esta versão
-- ignora com segurança coluna e índice já existentes.
