-- ============================================================================
-- CORREÇÃO DE INTEGRIDADE — ORDENS DE SERVIÇO COM ID ZERO
-- Compatível com MySQL/MariaDB 5.7
-- Banco alvo: inlaud99_erpcondor
--
-- Objetivo:
-- 1. Fazer backup lógico exclusivo das O.S. com id=0;
-- 2. Reatribuir IDs globais únicos aos registros principais em os_chamados;
-- 3. Restaurar PRIMARY KEY(id) e AUTO_INCREMENT;
-- 4. Auditar vínculos filhos que permaneceram com os_id=0.
--
-- IMPORTANTE:
-- Registros filhos com os_id=0 não podem ser atribuídos automaticamente a uma
-- O.S. específica, pois a chave original não era única. Eles são preservados
-- e registrados para revisão, sem exclusão ou alteração destrutiva.
-- Faça backup completo do banco antes de executar.
-- ============================================================================

USE `inlaud99_erpcondor`;

CREATE TABLE IF NOT EXISTS `mt_os_integridade_log` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `executado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('corrigido','pendente','ignorado') NOT NULL,
    `os_com_id_zero` INT NOT NULL DEFAULT 0,
    `ids_duplicados_nao_zero` INT NOT NULL DEFAULT 0,
    `interacoes_orfas_id_zero` INT NOT NULL DEFAULT 0,
    `materiais_orfaos_id_zero` INT NOT NULL DEFAULT 0,
    `recursos_orfaos_id_zero` INT NOT NULL DEFAULT 0,
    `fotos_orfas_id_zero` INT NOT NULL DEFAULT 0,
    `mensagem` VARCHAR(1000) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backup lógico somente dos registros afetados. Mantém todos os campos
-- originais para conferência e rollback manual, sem apagar dados produtivos.
CREATE TABLE IF NOT EXISTS `mt_os_chamados_id_zero_backup` LIKE `os_chamados`;
INSERT INTO `mt_os_chamados_id_zero_backup`
SELECT o.*
FROM `os_chamados` o
LEFT JOIN `mt_os_chamados_id_zero_backup` b
  ON b.tenant_id = o.tenant_id
 AND b.numero = o.numero
 AND b.data_abertura = o.data_abertura
WHERE o.id = 0
  AND b.numero IS NULL;

DROP PROCEDURE IF EXISTS `sp_corrigir_ids_os_mysql57`;
DELIMITER $$
CREATE PROCEDURE `sp_corrigir_ids_os_mysql57`()
proc: BEGIN
    DECLARE v_os_zero INT DEFAULT 0;
    DECLARE v_duplicados INT DEFAULT 0;
    DECLARE v_pk_id INT DEFAULT 0;
    DECLARE v_pk_outra INT DEFAULT 0;
    DECLARE v_auto_increment INT DEFAULT 0;
    DECLARE v_maior_id INT DEFAULT 0;
    DECLARE v_interacoes_zero INT DEFAULT 0;
    DECLARE v_materiais_zero INT DEFAULT 0;
    DECLARE v_recursos_zero INT DEFAULT 0;
    DECLARE v_fotos_zero INT DEFAULT 0;

    SELECT COUNT(*) INTO v_os_zero FROM `os_chamados` WHERE id = 0 OR id IS NULL;
    SELECT COUNT(*) - COUNT(DISTINCT id) INTO v_duplicados
      FROM `os_chamados` WHERE id IS NOT NULL AND id <> 0;

    SELECT COUNT(*) INTO v_pk_id
      FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'os_chamados'
       AND CONSTRAINT_NAME = 'PRIMARY'
       AND COLUMN_NAME = 'id';

    SELECT COUNT(*) INTO v_pk_outra
      FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'os_chamados'
       AND CONSTRAINT_NAME = 'PRIMARY'
       AND COLUMN_NAME <> 'id';

    SELECT COUNT(*) INTO v_auto_increment
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'os_chamados'
       AND COLUMN_NAME = 'id'
       AND EXTRA LIKE '%auto_increment%';

    SELECT COUNT(*) INTO v_interacoes_zero FROM `os_interacoes` WHERE os_id = 0;
    SELECT COUNT(*) INTO v_materiais_zero FROM `os_materiais_usados` WHERE os_id = 0;
    SELECT COUNT(*) INTO v_recursos_zero FROM `os_recursos_humanos` WHERE os_id = 0;
    SELECT COUNT(*) INTO v_fotos_zero
      FROM `os_interacao_fotos` f
      INNER JOIN `os_interacoes` i ON i.id = f.interacao_id
     WHERE i.os_id = 0;

    -- Não arrisca uma chave global se os IDs não zero já estiverem duplicados
    -- ou se outra coluna já for a chave primária.
    IF v_duplicados > 0 OR v_pk_outra > 0 THEN
        INSERT INTO `mt_os_integridade_log`
            (status, os_com_id_zero, ids_duplicados_nao_zero, interacoes_orfas_id_zero,
             materiais_orfaos_id_zero, recursos_orfaos_id_zero, fotos_orfas_id_zero, mensagem)
        VALUES
            ('pendente', v_os_zero, v_duplicados, v_interacoes_zero,
             v_materiais_zero, v_recursos_zero, v_fotos_zero,
             'Nenhuma alteração aplicada: existem IDs não zero duplicados ou chave primária incompatível. Revisão manual necessária.');
        LEAVE proc;
    END IF;

    -- Reatribui os registros que receberam 0 porque a tabela não possuía
    -- AUTO_INCREMENT. A ordem determinística mantém a cronologia por tenant.
    IF v_os_zero > 0 THEN
        SELECT COALESCE(MAX(id), 0) INTO v_maior_id FROM `os_chamados` WHERE id > 0;
        SET @mt_proximo_os_id := v_maior_id;
        UPDATE `os_chamados`
           SET id = (@mt_proximo_os_id := @mt_proximo_os_id + 1)
         WHERE id = 0 OR id IS NULL
         ORDER BY tenant_id, data_abertura, numero;
    END IF;

    IF v_pk_id = 0 THEN
        ALTER TABLE `os_chamados` ADD PRIMARY KEY (`id`);
    END IF;

    IF v_auto_increment = 0 THEN
        ALTER TABLE `os_chamados` MODIFY `id` INT(11) NOT NULL AUTO_INCREMENT;
    END IF;

    SELECT COALESCE(MAX(id), 0) + 1 INTO v_maior_id FROM `os_chamados`;
    SET @mt_sql_auto := CONCAT('ALTER TABLE `os_chamados` AUTO_INCREMENT = ', v_maior_id);
    PREPARE mt_stmt_auto FROM @mt_sql_auto;
    EXECUTE mt_stmt_auto;
    DEALLOCATE PREPARE mt_stmt_auto;

    INSERT INTO `mt_os_integridade_log`
        (status, os_com_id_zero, ids_duplicados_nao_zero, interacoes_orfas_id_zero,
         materiais_orfaos_id_zero, recursos_orfaos_id_zero, fotos_orfas_id_zero, mensagem)
    VALUES
        ('corrigido', v_os_zero, 0, v_interacoes_zero,
         v_materiais_zero, v_recursos_zero, v_fotos_zero,
         'IDs principais corrigidos; PRIMARY KEY(id) e AUTO_INCREMENT garantidos. Revise os vínculos filhos com os_id=0 antes de qualquer associação manual.');
END$$
DELIMITER ;

CALL `sp_corrigir_ids_os_mysql57`();
DROP PROCEDURE IF EXISTS `sp_corrigir_ids_os_mysql57`;

-- Resultado da execução e auditoria final. Não deve haver nenhum id=0 em os_chamados.
SELECT * FROM `mt_os_integridade_log` ORDER BY id DESC LIMIT 1;
SELECT tenant_id, id, numero, titulo, data_abertura
  FROM `os_chamados`
 WHERE id = 0 OR id IS NULL
 ORDER BY tenant_id, data_abertura;
SELECT COUNT(*) AS proximo_registro_id_esperado
  FROM `os_chamados`
 WHERE id > 0;
