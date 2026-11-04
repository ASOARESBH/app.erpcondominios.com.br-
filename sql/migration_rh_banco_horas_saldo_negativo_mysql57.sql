-- ============================================================================
-- RH — Banco de Horas: isolamento multi-tenant, saldo negativo e auditoria
-- Compatível com MySQL 5.7 / MariaDB 10.2+.
-- Idempotente sem SELECT em INFORMATION_SCHEMA: ignora somente coluna/índice
-- duplicado por handlers de erro nativo do MySQL (1060/1061).
-- Execute em janela de manutenção e faça backup antes de aplicar em produção.
-- Pré-requisitos: rh_colaboradores.tenant_id existente e preenchido; tabelas
-- RH já instaladas. A migration não corrige automaticamente tipos vazios.
-- ============================================================================

CREATE TABLE IF NOT EXISTS rh_banco_horas (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id INT NOT NULL,
    colaborador_id INT NOT NULL,
    lancamento_id INT NULL COMMENT 'ID em rh_ponto_lancamento; NULL = manual',
    data DATE NOT NULL,
    tipo ENUM('credito','debito','abatimento','pagamento') NOT NULL,
    minutos INT NOT NULL DEFAULT 0 COMMENT 'sempre positivo',
    descricao VARCHAR(255) NOT NULL DEFAULT '',
    usuario VARCHAR(100) NULL,
    saldo_anterior_min INT NULL,
    saldo_posterior_min INT NULL,
    saldo_negativo_confirmado TINYINT(1) NOT NULL DEFAULT 0,
    justificativa_saldo_negativo VARCHAR(500) NULL,
    usuario_id INT NULL,
    ip VARCHAR(45) NULL,
    idempotency_key VARCHAR(64) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rh_bh_tenant_colab_data (tenant_id, colaborador_id, data),
    KEY idx_rh_bh_lancamento (lancamento_id),
    UNIQUE KEY uq_rh_bh_tenant_idempotency (tenant_id, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A versão anterior podia deixar esta rotina criada se CALL falhasse.
DROP PROCEDURE IF EXISTS migration_rh_banco_horas_saldo_negativo;
DELIMITER $$
CREATE PROCEDURE migration_rh_banco_horas_saldo_negativo()
BEGIN
    -- Os dois erros são ignorados apenas para ADD COLUMN/CREATE INDEX repetidos.
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    DECLARE CONTINUE HANDLER FOR 1061 BEGIN END;

    -- A coluna antiga pode existir com DEFAULT 1; o backfill abaixo recalcula
    -- o tenant pelo colaborador e MODIFY remove qualquer default inseguro.
    ALTER TABLE rh_banco_horas ADD COLUMN tenant_id INT NULL AFTER id;

    IF EXISTS (
        SELECT 1
        FROM rh_banco_horas bh
        LEFT JOIN rh_colaboradores c ON c.id = bh.colaborador_id
        WHERE c.id IS NULL OR c.tenant_id IS NULL OR c.tenant_id <= 0
    ) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Há lançamentos sem colaborador/tenant válido; migration interrompida sem corrigir esses dados.';
    END IF;

    UPDATE rh_banco_horas bh
    INNER JOIN rh_colaboradores c ON c.id = bh.colaborador_id
    SET bh.tenant_id = c.tenant_id
    WHERE bh.tenant_id IS NULL OR bh.tenant_id <> c.tenant_id;
    ALTER TABLE rh_banco_horas MODIFY COLUMN tenant_id INT NOT NULL;

    -- Amplia o ENUM de modo idempotente. Valores históricos inválidos vazios
    -- permanecem vazios para diagnóstico e não são classificados como débito.
    ALTER TABLE rh_banco_horas
        MODIFY COLUMN tipo ENUM('credito','debito','abatimento','pagamento','excedente') NOT NULL;

    ALTER TABLE rh_banco_horas ADD COLUMN saldo_anterior_min INT NULL;
    ALTER TABLE rh_banco_horas ADD COLUMN saldo_posterior_min INT NULL;
    ALTER TABLE rh_banco_horas ADD COLUMN saldo_negativo_confirmado TINYINT(1) NOT NULL DEFAULT 0;
    ALTER TABLE rh_banco_horas ADD COLUMN justificativa_saldo_negativo VARCHAR(500) NULL;
    ALTER TABLE rh_banco_horas ADD COLUMN usuario_id INT NULL;
    ALTER TABLE rh_banco_horas ADD COLUMN ip VARCHAR(45) NULL;
    ALTER TABLE rh_banco_horas ADD COLUMN idempotency_key VARCHAR(64) NULL;

    CREATE INDEX idx_rh_bh_tenant_colab_data ON rh_banco_horas (tenant_id, colaborador_id, data);
    CREATE INDEX idx_rh_bh_lancamento ON rh_banco_horas (lancamento_id);
    CREATE UNIQUE INDEX uq_rh_bh_tenant_idempotency ON rh_banco_horas (tenant_id, idempotency_key);

    -- A escala é opcional em instalações que ainda não habilitaram RH.
    -- Ignora somente coluna duplicada ou tabela ausente; demais erros abortam.
    BEGIN
        DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
        DECLARE CONTINUE HANDLER FOR 1146 BEGIN END;
        ALTER TABLE rh_escala ADD COLUMN limite_saldo_negativo_min INT UNSIGNED NULL DEFAULT NULL
            COMMENT 'Limite opcional do saldo devedor em minutos; NULL = sem limite';
    END;
END$$
DELIMITER ;

CALL migration_rh_banco_horas_saldo_negativo();
DROP PROCEDURE IF EXISTS migration_rh_banco_horas_saldo_negativo;

-- Diagnóstico SOMENTE LEITURA de tipos legados inválidos/vazios.
-- Não executar UPDATE em produção sem aprovação, cópia de segurança e registro antes/depois.
SELECT id, tenant_id, colaborador_id, data, tipo, minutos, descricao, usuario, criado_em
FROM rh_banco_horas
WHERE tipo = '' OR tipo IS NULL
ORDER BY tenant_id, colaborador_id, data, id;

-- Conferência somente leitura sem INFORMATION_SCHEMA.
SHOW COLUMNS FROM rh_banco_horas;
SHOW INDEX FROM rh_banco_horas;
SHOW COLUMNS FROM rh_escala;
