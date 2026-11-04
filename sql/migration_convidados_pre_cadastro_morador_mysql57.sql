-- ERP CONDOMÍNIOS — CONVIDADOS: PRÉ-CADASTRO PELO MORADOR (Portal + Aplicativo)
-- Compatível com MySQL/MariaDB 5.7. Idempotente: pode ser executada mais de uma vez.
-- Pré-requisito: sql/migration_lista_convidados_mysql57.sql já aplicada.
--
-- 1. Acrescenta 'APP_MORADOR' ao ENUM convidados.origem_cadastro, preservando os valores existentes.
--    Sem esta migration, o pré-cadastro feito pelo aplicativo é gravado como PORTAL_MORADOR
--    (a API detecta o ENUM em tempo de execução) — nada quebra.
-- 2. Cria índice para o limite de pré-cadastros ativos por morador e a listagem do Portal/App.

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `sp_migration_convidados_pre_cadastro_morador`;
DELIMITER $$
CREATE PROCEDURE `sp_migration_convidados_pre_cadastro_morador`()
BEGIN
    DECLARE v_tem_tabela INT DEFAULT 0;
    DECLARE v_tipo_coluna TEXT DEFAULT NULL;
    DECLARE v_nulo VARCHAR(3) DEFAULT 'NO';
    DECLARE v_tem_indice INT DEFAULT 0;

    SELECT COUNT(*) INTO v_tem_tabela
      FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convidados' AND TABLE_TYPE = 'BASE TABLE';

    IF v_tem_tabela > 0 THEN
        SELECT COLUMN_TYPE, IS_NULLABLE INTO v_tipo_coluna, v_nulo
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convidados' AND COLUMN_NAME = 'origem_cadastro'
         LIMIT 1;

        IF v_tipo_coluna IS NOT NULL AND LOCATE('''APP_MORADOR''', v_tipo_coluna) = 0 THEN
            -- Acrescenta o novo valor ao final do ENUM existente (preserva ordem e valores atuais).
            SET @sql_origem = CONCAT(
                'ALTER TABLE `convidados` MODIFY COLUMN `origem_cadastro` ',
                LEFT(v_tipo_coluna, CHAR_LENGTH(v_tipo_coluna) - 1),
                ',''APP_MORADOR'')',
                IF(v_nulo = 'YES', ' NULL', ' NOT NULL')
            );
            PREPARE stmt_origem FROM @sql_origem;
            EXECUTE stmt_origem;
            DEALLOCATE PREPARE stmt_origem;
        END IF;

        SELECT COUNT(*) INTO v_tem_indice
          FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convidados' AND INDEX_NAME = 'idx_conv_tenant_autor_morador';

        IF v_tem_indice = 0 THEN
            SET @sql_indice = 'ALTER TABLE `convidados` ADD INDEX `idx_conv_tenant_autor_morador` (`tenant_id`, `cadastrado_por_morador_id`, `status`)';
            PREPARE stmt_indice FROM @sql_indice;
            EXECUTE stmt_indice;
            DEALLOCATE PREPARE stmt_indice;
        END IF;
    END IF;
END$$
DELIMITER ;

CALL `sp_migration_convidados_pre_cadastro_morador`();
DROP PROCEDURE IF EXISTS `sp_migration_convidados_pre_cadastro_morador`;
