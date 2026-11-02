-- ERP CONDOMÍNIOS — LISTA DE CONVIDADOS
-- Compatível com MySQL/MariaDB 5.7.
-- A aplicação das regras de período, entradas e consistência dos ENUMs ocorre na API.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `convidados` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `unidade` VARCHAR(50) NOT NULL,
  `morador_id` INT(11) NOT NULL,
  `anfitriao_tipo` ENUM('MORADOR','DEPENDENTE') NOT NULL DEFAULT 'MORADOR',
  `dependente_id` INT(11) DEFAULT NULL,
  `visitante_id` INT(11) DEFAULT NULL,
  `tipo_documento` ENUM('CPF','RG') NOT NULL,
  `documento` VARCHAR(20) NOT NULL,
  `documento_normalizado` VARCHAR(20) NOT NULL,
  `nome` VARCHAR(200) NOT NULL,
  `tipo_convidado` ENUM('VISITANTE','PRESTADOR','ENTREGADOR_AUTORIZADO','FAMILIAR') NOT NULL,
  `data_prevista_chegada` DATE NOT NULL,
  `dias_permanencia` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `validade_fim` DATE NOT NULL,
  `modalidade_entradas` ENUM('UNICO','MULTIPLO','INDETERMINADO') NOT NULL DEFAULT 'UNICO',
  `limite_entradas` SMALLINT UNSIGNED DEFAULT NULL,
  `entradas_utilizadas` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `origem_cadastro` ENUM('EMAIL','WHATSAPP','PRESENCIAL','ADMINISTRATIVO','PORTAL_MORADOR') NOT NULL,
  `status` ENUM('PREVISTO','PRESENTE','FINALIZADO','CANCELADO') NOT NULL DEFAULT 'PREVISTO',
  `observacao` VARCHAR(500) DEFAULT NULL,
  `cadastrado_por_tipo` ENUM('USUARIO','MORADOR','DEPENDENTE') NOT NULL,
  `cadastrado_por_usuario_id` INT(11) DEFAULT NULL,
  `cadastrado_por_morador_id` INT(11) DEFAULT NULL,
  `cadastrado_por_dependente_id` INT(11) DEFAULT NULL,
  `cadastrado_por_nome` VARCHAR(150) NOT NULL,
  `atualizado_por_tipo` ENUM('USUARIO','MORADOR','DEPENDENTE') DEFAULT NULL,
  `atualizado_por_id` INT(11) DEFAULT NULL,
  `atualizado_por_nome` VARCHAR(150) DEFAULT NULL,
  `cancelado_por_nome` VARCHAR(150) DEFAULT NULL,
  `cancelado_em` DATETIME DEFAULT NULL,
  `motivo_cancelamento` VARCHAR(255) DEFAULT NULL,
  `primeira_entrada_em` DATETIME DEFAULT NULL,
  `ultima_movimentacao_em` DATETIME DEFAULT NULL,
  `idempotency_key` VARCHAR(36) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv_tenant_periodo` (`tenant_id`, `data_prevista_chegada`, `validade_fim`),
  KEY `idx_conv_tenant_doc` (`tenant_id`, `documento_normalizado`),
  KEY `idx_conv_tenant_morador` (`tenant_id`, `morador_id`),
  KEY `idx_conv_tenant_status` (`tenant_id`, `status`),
  UNIQUE KEY `uk_conv_tenant_idempotency` (`tenant_id`, `idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS `sp_migration_lista_convidados_mysql57`;
DELIMITER $$
CREATE PROCEDURE `sp_migration_lista_convidados_mysql57`()
BEGIN
    DECLARE v_tem_tabela INT DEFAULT 0;
    DECLARE v_tem_coluna INT DEFAULT 0;
    DECLARE v_tem_indice INT DEFAULT 0;
    DECLARE v_tipo_dado VARCHAR(32) DEFAULT NULL;
    DECLARE v_tipo_coluna TEXT DEFAULT NULL;
    DECLARE v_tipo_expandido TEXT DEFAULT NULL;
    DECLARE v_default VARCHAR(255) DEFAULT NULL;

    SELECT COUNT(*) INTO v_tem_tabela
      FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'registros_acesso'
       AND TABLE_TYPE = 'BASE TABLE';

    IF v_tem_tabela > 0 THEN
        SELECT COUNT(*) INTO v_tem_coluna
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'registros_acesso'
           AND COLUMN_NAME = 'convidado_id';

        IF v_tem_coluna = 0 THEN
            SET @sql_convidado_coluna = 'ALTER TABLE `registros_acesso` ADD COLUMN `convidado_id` INT(11) DEFAULT NULL';
            PREPARE stmt_convidado_coluna FROM @sql_convidado_coluna;
            EXECUTE stmt_convidado_coluna;
            DEALLOCATE PREPARE stmt_convidado_coluna;
        END IF;

        SELECT COUNT(*) INTO v_tem_indice
          FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'registros_acesso'
           AND INDEX_NAME = 'idx_registros_acesso_tenant_convidado';

        IF v_tem_indice = 0 THEN
            SELECT COUNT(*) INTO v_tem_coluna
              FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'registros_acesso'
               AND COLUMN_NAME = 'tenant_id';

            IF v_tem_coluna > 0 THEN
                SET @sql_convidado_indice = 'ALTER TABLE `registros_acesso` ADD INDEX `idx_registros_acesso_tenant_convidado` (`tenant_id`, `convidado_id`)';
                PREPARE stmt_convidado_indice FROM @sql_convidado_indice;
                EXECUTE stmt_convidado_indice;
                DEALLOCATE PREPARE stmt_convidado_indice;
            END IF;
        END IF;

        -- ENUMs antigos são ampliados preservando os valores já existentes.
        -- Colunas VARCHAR/CHAR/SET já aceitam os novos rótulos e não precisam de ALTER.
        SELECT DATA_TYPE, COLUMN_TYPE, COLUMN_DEFAULT
          INTO v_tipo_dado, v_tipo_coluna, v_default
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'registros_acesso'
           AND COLUMN_NAME = 'tipo'
         LIMIT 1;

        -- MariaDB pode devolver COLUMN_DEFAULT já entre aspas no INFORMATION_SCHEMA.
        IF v_default IS NOT NULL AND CHAR_LENGTH(v_default) >= 2
           AND LEFT(v_default, 1) = CHAR(39) AND RIGHT(v_default, 1) = CHAR(39) THEN
            SET v_default = SUBSTRING(v_default, 2, CHAR_LENGTH(v_default) - 2);
            SET v_default = REPLACE(v_default, CONCAT(CHAR(39), CHAR(39)), CHAR(39));
        END IF;

        IF v_tipo_dado = 'enum' AND v_tipo_coluna IS NOT NULL THEN
            SET v_tipo_expandido = v_tipo_coluna;
            IF v_tipo_coluna NOT LIKE '%''Entregador Autorizado''%' THEN
                SET v_tipo_expandido = CONCAT(LEFT(v_tipo_expandido, CHAR_LENGTH(v_tipo_expandido) - 1), ',''Entregador Autorizado'')');
            END IF;
            IF v_tipo_expandido NOT LIKE '%''Familiar''%' THEN
                SET v_tipo_expandido = CONCAT(LEFT(v_tipo_expandido, CHAR_LENGTH(v_tipo_expandido) - 1), ',''Familiar'')');
            END IF;

            SET @sql_tipo_acesso = CONCAT(
                'ALTER TABLE `registros_acesso` MODIFY COLUMN `tipo` ',
                v_tipo_expandido,
                CASE
                    WHEN (SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE()
                             AND TABLE_NAME = 'registros_acesso'
                             AND COLUMN_NAME = 'tipo' LIMIT 1) = 'YES'
                    THEN ' NULL'
                    ELSE ' NOT NULL'
                END,
                CASE
                    WHEN v_default IS NULL THEN ''
                    ELSE CONCAT(' DEFAULT ', QUOTE(v_default))
                END
            );
            PREPARE stmt_tipo_acesso FROM @sql_tipo_acesso;
            EXECUTE stmt_tipo_acesso;
            DEALLOCATE PREPARE stmt_tipo_acesso;
        END IF;
    END IF;
END$$
DELIMITER ;

CALL `sp_migration_lista_convidados_mysql57`();
DROP PROCEDURE IF EXISTS `sp_migration_lista_convidados_mysql57`;

-- Auditoria opcional pós-execução:
-- SELECT COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registros_acesso'
--   AND COLUMN_NAME IN ('tipo', 'convidado_id');
