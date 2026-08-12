-- ============================================================================
-- CORREÇÃO DEDICADA: PROTOCOLOS COM ID ZERO
-- MySQL/MariaDB 5.7 | Banco: inlaud99_erpcondor
-- Renumera registros id=0 sem descartar o protocolo salvo e restaura a chave.
-- ============================================================================

SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `inlaud99_erpcondor`.`mt_protocolos_id_zero_backup` LIKE `inlaud99_erpcondor`.`protocolos`;
INSERT INTO `inlaud99_erpcondor`.`mt_protocolos_id_zero_backup`
SELECT * FROM `inlaud99_erpcondor`.`protocolos` WHERE id=0;

DROP PROCEDURE IF EXISTS `inlaud99_erpcondor`.`mt_corrigir_protocolos_id`;
DELIMITER $$
CREATE PROCEDURE `inlaud99_erpcondor`.`mt_corrigir_protocolos_id`()
BEGIN
 DECLARE v_invalidos BIGINT DEFAULT 0;
 DECLARE v_dups BIGINT DEFAULT 0;
 DECLARE v_pk INT DEFAULT 0;
 DECLARE v_ai INT DEFAULT 0;
 SELECT COUNT(*) INTO v_invalidos FROM `inlaud99_erpcondor`.`protocolos` WHERE id IS NULL OR id=0;
 SELECT COUNT(*)-COUNT(DISTINCT id) INTO v_dups FROM `inlaud99_erpcondor`.`protocolos` WHERE id IS NOT NULL AND id<>0;
 IF v_dups>0 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Protocolos possuem IDs duplicados não-zero; limpeza manual obrigatória antes de criar chave primária';
 END IF;
 IF v_invalidos>0 THEN
   SET @novo_id=(SELECT COALESCE(MAX(id),0) FROM `inlaud99_erpcondor`.`protocolos`);
   UPDATE `inlaud99_erpcondor`.`protocolos`
      SET id=(@novo_id:=@novo_id+1)
    WHERE id=0
    ORDER BY criado_em, unidade_id, morador_id;
 END IF;
 SELECT COUNT(*) INTO v_pk FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA='inlaud99_erpcondor' AND TABLE_NAME='protocolos' AND CONSTRAINT_NAME='PRIMARY' AND COLUMN_NAME='id';
 IF v_pk=0 THEN
   ALTER TABLE `inlaud99_erpcondor`.`protocolos` ADD PRIMARY KEY (`id`);
 END IF;
 SELECT COUNT(*) INTO v_ai FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA='inlaud99_erpcondor' AND TABLE_NAME='protocolos' AND COLUMN_NAME='id' AND EXTRA LIKE '%auto_increment%';
 IF v_ai=0 THEN
   ALTER TABLE `inlaud99_erpcondor`.`protocolos` MODIFY `id` INT(11) NOT NULL AUTO_INCREMENT;
 END IF;
END$$
DELIMITER ;
CALL `inlaud99_erpcondor`.`mt_corrigir_protocolos_id`();
DROP PROCEDURE `inlaud99_erpcondor`.`mt_corrigir_protocolos_id`;
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

SELECT id,tenant_id,unidade_id,morador_id,descricao_mercadoria,status,criado_em
FROM `inlaud99_erpcondor`.`protocolos`
ORDER BY id DESC LIMIT 20;
