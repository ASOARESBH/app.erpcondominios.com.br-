-- ============================================================
-- MIGRAÇÃO v3.1 — Sessão Inativa (Nunca Expira)
-- Compatível com: MariaDB 5.7 / MySQL 5.7
-- Data: 2026-05-13
-- ============================================================
-- Execute este script no phpMyAdmin ou via MySQL CLI.
-- É seguro executar múltiplas vezes (idempotente).
-- NOTA: ALTER TABLE ... ADD COLUMN IF NOT EXISTS não existe
--       no MariaDB 5.7. Usamos PROCEDURE para verificar antes.
-- ============================================================

-- ─── 1. Adicionar campo sessao_inativa (idempotente via PROCEDURE) ────────────

DROP PROCEDURE IF EXISTS sp_add_sessao_inativa;

DELIMITER $$

CREATE PROCEDURE sp_add_sessao_inativa()
BEGIN
    -- Verifica se a coluna já existe antes de tentar adicionar
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'usuarios'
          AND COLUMN_NAME  = 'sessao_inativa'
    ) THEN
        ALTER TABLE `usuarios`
            ADD COLUMN `sessao_inativa` TINYINT(1) NOT NULL DEFAULT 0
            COMMENT 'Se 1, o sistema nunca faz logout automatico deste usuario'
            AFTER `ativo`;
    END IF;
END$$

DELIMITER ;

CALL sp_add_sessao_inativa();
DROP PROCEDURE IF EXISTS sp_add_sessao_inativa;

-- ─── 2. Criar tabela config_sessao (timeouts globais) ─────────────────────────

CREATE TABLE IF NOT EXISTS `config_sessao` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `chave`      VARCHAR(60)   NOT NULL,
    `valor`      VARCHAR(255)  NOT NULL,
    `descricao`  VARCHAR(255)  DEFAULT NULL,
    `atualizado` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_chave` (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Configuracoes globais de sessao do sistema';

-- ─── 3. Inserir/atualizar configurações padrão de timeout ─────────────────────

INSERT INTO `config_sessao` (`chave`, `valor`, `descricao`)
VALUES ('timeout_total_min', '60', 'Tempo maximo de sessao em minutos (0 = sem limite)')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);

INSERT INTO `config_sessao` (`chave`, `valor`, `descricao`)
VALUES ('timeout_inatividade_min', '0', 'Tempo de inatividade em minutos (0 = desabilitado)')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);

INSERT INTO `config_sessao` (`chave`, `valor`, `descricao`)
VALUES ('aviso_expiracao_min', '5', 'Minutos antes da expiracao para exibir aviso')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);

-- ─── 4. Verificação final ─────────────────────────────────────────────────────

SELECT
    'usuarios'     AS tabela,
    COLUMN_NAME    AS coluna,
    COLUMN_TYPE    AS tipo,
    COLUMN_DEFAULT AS padrao,
    COLUMN_COMMENT AS comentario
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME   = 'usuarios'
  AND COLUMN_NAME  = 'sessao_inativa';

SELECT 'Migracao v3.1 concluida com sucesso!' AS status;
