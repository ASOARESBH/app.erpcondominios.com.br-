-- =====================================================
-- TRIGGERS DE RECÁLCULO AUTOMÁTICO DE SALDO
-- Módulo: Abastecimento
-- =====================================================
-- Execute este script no banco de dados para criar os
-- triggers que mantêm o saldo sempre consistente.
--
-- Lógica:
--   saldo = SUM(abastecimento_recargas.valor_recarga)
--         - SUM(abastecimento_lancamentos.valor)
--
-- Os triggers disparam automaticamente após qualquer
-- INSERT, UPDATE ou DELETE nas tabelas de lançamentos
-- e recargas — inclusive operações manuais via phpMyAdmin.
-- =====================================================

DELIMITER $$

-- =====================================================
-- PROCEDURE CENTRAL DE RECÁLCULO
-- =====================================================
DROP PROCEDURE IF EXISTS proc_recalcular_saldo_abastecimento$$

CREATE PROCEDURE proc_recalcular_saldo_abastecimento()
BEGIN
    DECLARE v_total_recargas    DECIMAL(10,2) DEFAULT 0.00;
    DECLARE v_total_lancamentos DECIMAL(10,2) DEFAULT 0.00;
    DECLARE v_saldo_correto     DECIMAL(10,2) DEFAULT 0.00;

    -- Soma todas as recargas
    SELECT COALESCE(SUM(valor_recarga), 0)
      INTO v_total_recargas
      FROM abastecimento_recargas;

    -- Soma todos os lançamentos
    SELECT COALESCE(SUM(valor), 0)
      INTO v_total_lancamentos
      FROM abastecimento_lancamentos;

    -- Calcula saldo correto
    SET v_saldo_correto = v_total_recargas - v_total_lancamentos;

    -- Atualiza (ou cria) o registro único de saldo
    INSERT INTO abastecimento_saldo (id, valor, data_atualizacao)
    VALUES (1, v_saldo_correto, NOW())
    ON DUPLICATE KEY UPDATE
        valor            = v_saldo_correto,
        data_atualizacao = NOW();
END$$

-- =====================================================
-- TRIGGERS: abastecimento_lancamentos
-- =====================================================

-- Após INSERT de lançamento
DROP TRIGGER IF EXISTS trg_lancamento_after_insert$$
CREATE TRIGGER trg_lancamento_after_insert
AFTER INSERT ON abastecimento_lancamentos
FOR EACH ROW
BEGIN
    CALL proc_recalcular_saldo_abastecimento();
END$$

-- Após UPDATE de lançamento
DROP TRIGGER IF EXISTS trg_lancamento_after_update$$
CREATE TRIGGER trg_lancamento_after_update
AFTER UPDATE ON abastecimento_lancamentos
FOR EACH ROW
BEGIN
    CALL proc_recalcular_saldo_abastecimento();
END$$

-- Após DELETE de lançamento (cobre exclusão manual no phpMyAdmin)
DROP TRIGGER IF EXISTS trg_lancamento_after_delete$$
CREATE TRIGGER trg_lancamento_after_delete
AFTER DELETE ON abastecimento_lancamentos
FOR EACH ROW
BEGIN
    CALL proc_recalcular_saldo_abastecimento();
END$$

-- =====================================================
-- TRIGGERS: abastecimento_recargas
-- =====================================================

-- Após INSERT de recarga
DROP TRIGGER IF EXISTS trg_recarga_after_insert$$
CREATE TRIGGER trg_recarga_after_insert
AFTER INSERT ON abastecimento_recargas
FOR EACH ROW
BEGIN
    CALL proc_recalcular_saldo_abastecimento();
END$$

-- Após UPDATE de recarga
DROP TRIGGER IF EXISTS trg_recarga_after_update$$
CREATE TRIGGER trg_recarga_after_update
AFTER UPDATE ON abastecimento_recargas
FOR EACH ROW
BEGIN
    CALL proc_recalcular_saldo_abastecimento();
END$$

-- Após DELETE de recarga (cobre exclusão manual no phpMyAdmin)
DROP TRIGGER IF EXISTS trg_recarga_after_delete$$
CREATE TRIGGER trg_recarga_after_delete
AFTER DELETE ON abastecimento_recargas
FOR EACH ROW
BEGIN
    CALL proc_recalcular_saldo_abastecimento();
END$$

DELIMITER ;

-- =====================================================
-- RECÁLCULO IMEDIATO (executar junto com os triggers)
-- =====================================================
-- Corrige o saldo atual com base nos registros reais:
INSERT INTO abastecimento_saldo (id, valor, data_atualizacao)
SELECT
    1,
    COALESCE((SELECT SUM(valor_recarga) FROM abastecimento_recargas), 0)
    - COALESCE((SELECT SUM(valor)       FROM abastecimento_lancamentos), 0),
    NOW()
ON DUPLICATE KEY UPDATE
    valor            = COALESCE((SELECT SUM(valor_recarga) FROM abastecimento_recargas), 0)
                     - COALESCE((SELECT SUM(valor)         FROM abastecimento_lancamentos), 0),
    data_atualizacao = NOW();

-- =====================================================
-- VERIFICAÇÃO (opcional — execute para confirmar)
-- =====================================================
-- SELECT
--     (SELECT COALESCE(SUM(valor_recarga), 0) FROM abastecimento_recargas)    AS total_recargas,
--     (SELECT COALESCE(SUM(valor), 0)         FROM abastecimento_lancamentos) AS total_lancamentos,
--     (SELECT valor                           FROM abastecimento_saldo WHERE id = 1) AS saldo_atual;
