-- ============================================================================
-- ERP CONDOMÍNIOS — CORREÇÃO DE INTERAÇÕES E FOTOS DE ORDENS DE SERVIÇO
-- MySQL / MariaDB 5.7 — Banco: inlaud99_erpcondor
-- ============================================================================
-- CAUSA: os_interacoes e os_interacao_fotos podem estar sem PRIMARY KEY(id)
-- e AUTO_INCREMENT. Em modo não estrito, novos INSERTs recebem id=0. Como
-- fotos dependem de interacao_id, todas as fotos com interacao_id=0 aparecem
-- em qualquer interação também criada com id=0.
--
-- SEGURANÇA: fotos com interacao_id=0 são copiadas para uma tabela de
-- quarentena e DESVINCULADAS (interacao_id=-1). Não é possível inferir de
-- forma confiável a qual interação id=0 cada foto pertencia. Essa etapa evita
-- exibir fotos erradas e preserva os arquivos para reclassificação manual.
--
-- Faça backup completo antes de executar no phpMyAdmin.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `os_interacao_fotos_quarentena` (
    `backup_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `foto_id_origem` INT NOT NULL,
    `tenant_id` INT NOT NULL,
    `interacao_id_origem` INT NOT NULL,
    `arquivo` VARCHAR(255) NOT NULL,
    `arquivo_nome_original` VARCHAR(255) DEFAULT NULL,
    `arquivo_tamanho` INT UNSIGNED DEFAULT 0,
    `criado_em_origem` DATETIME DEFAULT NULL,
    `motivo` VARCHAR(255) NOT NULL,
    `quarentenado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`backup_id`),
    KEY `idx_tenant_foto_origem` (`tenant_id`, `foto_id_origem`),
    KEY `idx_interacao_origem` (`interacao_id_origem`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `os_integridade_interacoes_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tabela` VARCHAR(64) NOT NULL,
    `ids_zero_antes` BIGINT NOT NULL DEFAULT 0,
    `duplicados_nao_zero_antes` BIGINT NOT NULL DEFAULT 0,
    `acao` VARCHAR(64) NOT NULL,
    `mensagem` VARCHAR(500) NOT NULL,
    `executado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_tabela_data` (`tabela`, `executado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS `sp_os_reparar_ids_interacoes`;
DELIMITER $$
CREATE PROCEDURE `sp_os_reparar_ids_interacoes`(IN p_tabela VARCHAR(64))
proc: BEGIN
    DECLARE v_existe INT DEFAULT 0;
    DECLARE v_col_reparo INT DEFAULT 0;
    DECLARE v_pk INT DEFAULT 0;
    DECLARE v_ai INT DEFAULT 0;
    DECLARE v_zeros BIGINT DEFAULT 0;
    DECLARE v_dups BIGINT DEFAULT 0;
    DECLARE v_max BIGINT DEFAULT 0;

    SELECT COUNT(*) INTO v_existe
      FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela;
    IF v_existe = 0 THEN
        INSERT INTO os_integridade_interacoes_log(tabela, acao, mensagem)
        VALUES (p_tabela, 'ignorada', 'Tabela não encontrada');
        LEAVE proc;
    END IF;

    SET @sql = CONCAT('SELECT COUNT(*) INTO @mt_zeros FROM `', p_tabela, '` WHERE id IS NULL OR id=0');
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    SET v_zeros = COALESCE(@mt_zeros, 0);

    SET @sql = CONCAT('SELECT COUNT(*) - COUNT(DISTINCT id) INTO @mt_dups FROM `', p_tabela, '` WHERE id IS NOT NULL AND id<>0');
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    SET v_dups = COALESCE(@mt_dups, 0);

    IF v_dups > 0 THEN
        INSERT INTO os_integridade_interacoes_log(tabela, ids_zero_antes, duplicados_nao_zero_antes, acao, mensagem)
        VALUES (p_tabela, v_zeros, v_dups, 'pendente', 'Há IDs positivos duplicados; nenhuma alteração automática foi aplicada');
        LEAVE proc;
    END IF;

    IF v_zeros > 0 THEN
        SELECT COUNT(*) INTO v_col_reparo
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela AND COLUMN_NAME = '_mt_reparo_linha';
        IF v_col_reparo = 0 THEN
            SET @sql = CONCAT('ALTER TABLE `', p_tabela, '` ADD COLUMN `_mt_reparo_linha` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT UNIQUE');
            PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        END IF;

        SET @sql = CONCAT('SELECT COALESCE(MAX(id),0) INTO @mt_max_id FROM `', p_tabela, '` WHERE id > 0');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
        SET v_max = COALESCE(@mt_max_id, 0);

        SET @sql = CONCAT('UPDATE `', p_tabela, '` SET id = ', v_max, ' + _mt_reparo_linha WHERE id IS NULL OR id=0');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

        SET @sql = CONCAT('ALTER TABLE `', p_tabela, '` DROP COLUMN `_mt_reparo_linha`');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SELECT COUNT(*) INTO v_pk
      FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela
       AND CONSTRAINT_NAME = 'PRIMARY' AND COLUMN_NAME = 'id';
    IF v_pk = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_tabela, '` ADD PRIMARY KEY (`id`)');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    SELECT COUNT(*) INTO v_ai
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabela
       AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%';
    IF v_ai = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_tabela, '` MODIFY `id` INT NOT NULL AUTO_INCREMENT');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;

    INSERT INTO os_integridade_interacoes_log(tabela, ids_zero_antes, duplicados_nao_zero_antes, acao, mensagem)
    VALUES (p_tabela, v_zeros, v_dups, 'corrigida', 'IDs normalizados; PRIMARY KEY(id) e AUTO_INCREMENT garantidos');
END$$
DELIMITER ;

-- 1) Preservar e retirar somente vínculos ambíguos de foto (interacao_id=0).
INSERT INTO os_interacao_fotos_quarentena
    (foto_id_origem, tenant_id, interacao_id_origem, arquivo, arquivo_nome_original, arquivo_tamanho, criado_em_origem, motivo)
SELECT f.id, f.tenant_id, f.interacao_id, f.arquivo, f.arquivo_nome_original, f.arquivo_tamanho, f.criado_em,
       'Interação de origem com id=0; vínculo não pode ser reconstruído automaticamente'
  FROM os_interacao_fotos f
 WHERE f.interacao_id = 0;

UPDATE os_interacao_fotos
   SET interacao_id = -1
 WHERE interacao_id = 0;

-- 2) Reparar os identificadores das duas tabelas.
CALL sp_os_reparar_ids_interacoes('os_interacoes');
CALL sp_os_reparar_ids_interacoes('os_interacao_fotos');

DROP PROCEDURE IF EXISTS `sp_os_reparar_ids_interacoes`;

-- 3) Auditoria final (somente leitura):
SELECT tabela, ids_zero_antes, duplicados_nao_zero_antes, acao, mensagem, executado_em
  FROM os_integridade_interacoes_log
 ORDER BY id DESC;

SELECT tenant_id, COUNT(*) AS fotos_em_quarentena
  FROM os_interacao_fotos_quarentena
 GROUP BY tenant_id
 ORDER BY tenant_id;
-- ============================================================================
