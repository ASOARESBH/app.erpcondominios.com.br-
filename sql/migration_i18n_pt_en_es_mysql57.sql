-- Preferências de idioma do ERP: pt-BR, en-US e es-ES
-- Compatível com MySQL 5.7+; executa cada alteração somente se a coluna ainda não existir.
DELIMITER $$
CREATE PROCEDURE migrar_i18n_locale()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tenants' AND COLUMN_NAME='locale') THEN
        ALTER TABLE tenants ADD COLUMN locale VARCHAR(10) NOT NULL DEFAULT 'pt-BR';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='locale') THEN
        ALTER TABLE usuarios ADD COLUMN locale VARCHAR(10) NULL DEFAULT NULL;
    END IF;
END$$
DELIMITER ;
CALL migrar_i18n_locale();
DROP PROCEDURE migrar_i18n_locale;
