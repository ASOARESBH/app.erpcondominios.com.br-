-- ============================================================
-- MIGRATION: Contas Bancárias → Enterprise Banking v2.0
-- Versão:    2.0  |  Data: 2026-06-25
-- Banco:     inlaud99_erpserra
-- Compatível: MySQL 5.7.7+ / MariaDB 10.x
-- IDEMPOTENTE — pode ser re-executada sem erros
-- ============================================================
-- CLI:       mysql -u inlaud99_admin -p inlaud99_erpserra < migration_contas_bancarias_v2.sql
-- phpMyAdmin: SQL → defina delimiter como ;; → cole e execute
-- ============================================================
-- Estratégia de idempotência MySQL 5.7 (não tem IF NOT EXISTS em ALTER):
--   • Stored procedure com CONTINUE HANDLER FOR 1060 (coluna já existe)
--     e CONTINUE HANDLER FOR 1061 (índice já existe).
--   • Cada ALTER TABLE em statement separado dentro do BEGIN..END
--     para que o HANDLER continue para o próximo statement.
--   • JSON não existe no 5.7.7 → substituído por LONGTEXT.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- BLOCO 1 — ALTER TABLE via stored procedure idempotente
-- ─────────────────────────────────────────────────────────────

DROP PROCEDURE IF EXISTS _mig_cb_v2;

DELIMITER ;;
CREATE PROCEDURE _mig_cb_v2()
BEGIN
    -- 1060 = ER_DUP_FIELDNAME : coluna já existe → ignorar e continuar
    -- 1061 = ER_DUP_KEYNAME   : índice já existe → ignorar e continuar
    DECLARE CONTINUE HANDLER FOR 1060 BEGIN END;
    DECLARE CONTINUE HANDLER FOR 1061 BEGIN END;

    -- ══════════════════════════════════════════════════════
    -- 1. movimentacoes_bancarias — 10 novas colunas
    --    Cada ADD em statement próprio: o HANDLER precisa disso
    --    para continuar no próximo ALTER quando um falha.
    -- ══════════════════════════════════════════════════════

    ALTER TABLE movimentacoes_bancarias
        ADD COLUMN favorecido VARCHAR(255) NULL
        COMMENT 'Favorecido/pagador: NAME > PAYEE > parsed MEMO do OFX'
        AFTER descricao;

    ALTER TABLE movimentacoes_bancarias
        ADD COLUMN memo TEXT NULL
        COMMENT 'Campo MEMO bruto do arquivo OFX'
        AFTER favorecido;

    ALTER TABLE movimentacoes_bancarias
        ADD COLUMN payee VARCHAR(255) NULL
        COMMENT 'Campo PAYEE do arquivo OFX'
        AFTER memo;

    ALTER TABLE movimentacoes_bancarias
        ADD COLUMN numero_documento VARCHAR(60) NULL
        COMMENT 'Nº do documento (NF, boleto, cheque — distinto de checknum)'
        AFTER checknum;

    ALTER TABLE movimentacoes_bancarias
        ADD COLUMN centro_custo VARCHAR(80) NULL
        COMMENT 'Centro de custo para rateio'
        AFTER categoria;

    ALTER TABLE movimentacoes_bancarias
        ADD COLUMN status ENUM('pendente','conciliado','ignorado','divergente')
        NOT NULL DEFAULT 'pendente'
        COMMENT 'Status de conciliação bancária'
        AFTER conciliado;

    ALTER TABLE movimentacoes_bancarias
        ADD COLUMN conta_receber_id INT UNSIGNED NULL
        COMMENT 'FK contas_receber.id — título quitado por esta movimentação'
        AFTER importacao_id;

    ALTER TABLE movimentacoes_bancarias
        ADD COLUMN conta_pagar_id INT UNSIGNED NULL
        COMMENT 'FK contas_pagar.id — título pago por esta movimentação'
        AFTER conta_receber_id;

    ALTER TABLE movimentacoes_bancarias
        ADD COLUMN data_importacao DATETIME NULL
        COMMENT 'Timestamp exato em que o OFX foi importado'
        AFTER criado_em;

    ALTER TABLE movimentacoes_bancarias
        ADD COLUMN usuario_importacao VARCHAR(80) NULL
        COMMENT 'Login do usuário que realizou a importação OFX'
        AFTER data_importacao;

    -- Índices para as novas colunas
    ALTER TABLE movimentacoes_bancarias ADD INDEX idx_mb_status      (status);
    ALTER TABLE movimentacoes_bancarias ADD INDEX idx_mb_cr_id       (conta_receber_id);
    ALTER TABLE movimentacoes_bancarias ADD INDEX idx_mb_cp_id       (conta_pagar_id);
    ALTER TABLE movimentacoes_bancarias ADD INDEX idx_mb_data_imp    (data_importacao);
    ALTER TABLE movimentacoes_bancarias ADD INDEX idx_mb_favorecido  (favorecido(60));

    -- ══════════════════════════════════════════════════════
    -- 2. contas_receber — 5 colunas de conciliação bancária
    -- ══════════════════════════════════════════════════════

    ALTER TABLE contas_receber
        ADD COLUMN movimentacao_bancaria_id BIGINT UNSIGNED NULL
        COMMENT 'FK movimentacoes_bancarias.id — movimentação que quitou este título';

    ALTER TABLE contas_receber
        ADD COLUMN conta_bancaria_id INT UNSIGNED NULL
        COMMENT 'FK contas_bancarias.id — conta onde o recebimento ocorreu';

    ALTER TABLE contas_receber
        ADD COLUMN conciliado TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '0 = pendente de conciliação  |  1 = conciliado';

    ALTER TABLE contas_receber
        ADD COLUMN data_conciliacao DATETIME NULL
        COMMENT 'Data/hora em que a conciliação foi realizada';

    ALTER TABLE contas_receber
        ADD COLUMN usuario_conciliacao VARCHAR(80) NULL
        COMMENT 'Login do usuário que realizou a conciliação';

    ALTER TABLE contas_receber ADD INDEX idx_cr_conciliado (conciliado);
    ALTER TABLE contas_receber ADD INDEX idx_cr_mov_id     (movimentacao_bancaria_id);

    -- ══════════════════════════════════════════════════════
    -- 3. contas_pagar — 5 colunas de conciliação bancária
    -- ══════════════════════════════════════════════════════

    ALTER TABLE contas_pagar
        ADD COLUMN movimentacao_bancaria_id BIGINT UNSIGNED NULL
        COMMENT 'FK movimentacoes_bancarias.id — movimentação que liquidou este título';

    ALTER TABLE contas_pagar
        ADD COLUMN conta_bancaria_id INT UNSIGNED NULL
        COMMENT 'FK contas_bancarias.id — conta de onde saiu o pagamento';

    ALTER TABLE contas_pagar
        ADD COLUMN conciliado TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '0 = pendente de conciliação  |  1 = conciliado';

    ALTER TABLE contas_pagar
        ADD COLUMN data_conciliacao DATETIME NULL
        COMMENT 'Data/hora em que a conciliação foi realizada';

    ALTER TABLE contas_pagar
        ADD COLUMN usuario_conciliacao VARCHAR(80) NULL
        COMMENT 'Login do usuário que realizou a conciliação';

    ALTER TABLE contas_pagar ADD INDEX idx_cp_conciliado (conciliado);
    ALTER TABLE contas_pagar ADD INDEX idx_cp_mov_id     (movimentacao_bancaria_id);

    -- ══════════════════════════════════════════════════════
    -- 4. historico_importacoes_ofx — 4 colunas de diagnóstico
    -- ══════════════════════════════════════════════════════

    ALTER TABLE historico_importacoes_ofx
        ADD COLUMN conciliadas_auto INT UNSIGNED NULL DEFAULT 0
        COMMENT 'Transações auto-conciliadas pelo motor nesta importação';

    ALTER TABLE historico_importacoes_ofx
        ADD COLUMN pendentes INT UNSIGNED NULL DEFAULT 0
        COMMENT 'Transações sem match (aguardam conciliação manual)';

    ALTER TABLE historico_importacoes_ofx
        ADD COLUMN formato_ofx ENUM('sgml','xml','desconhecido') NULL DEFAULT 'desconhecido'
        COMMENT 'Formato detectado: SGML (maioria BR) ou XML (Itaú, Nubank, Inter)';

    ALTER TABLE historico_importacoes_ofx
        ADD COLUMN tempo_ms INT UNSIGNED NULL
        COMMENT 'Tempo total de processamento da importação em milissegundos';

END;;
DELIMITER ;

CALL _mig_cb_v2();
DROP PROCEDURE IF EXISTS _mig_cb_v2;

-- ─────────────────────────────────────────────────────────────
-- BLOCO 2 — Nova tabela: conciliacoes
--   JSON não existe no MySQL 5.7.7 → campo criterios = LONGTEXT
--   O PHP armazena json_encode() e lê com json_decode()
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS conciliacoes (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    movimentacao_id  BIGINT UNSIGNED NOT NULL
                     COMMENT 'FK movimentacoes_bancarias.id',
    tipo_titulo      ENUM('receber','pagar') NOT NULL
                     COMMENT 'De qual tabela é o título conciliado',
    titulo_id        INT UNSIGNED NOT NULL
                     COMMENT 'PK em contas_receber ou contas_pagar (depende de tipo_titulo)',
    tipo_conciliacao ENUM('automatica','manual') NOT NULL DEFAULT 'manual',
    score            DECIMAL(5,2) NULL
                     COMMENT 'Score 0-100 do motor automático; NULL para conciliação manual',
    criterios        LONGTEXT NULL
                     COMMENT 'JSON dos critérios que geraram o match: ["valor_exato","data_3d",...]',
    conciliado_por   VARCHAR(80) NULL,
    conciliado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    desfeito_por     VARCHAR(80) NULL,
    desfeito_em      DATETIME NULL,
    ativa            TINYINT(1) NOT NULL DEFAULT 1
                     COMMENT '1 = ativa  |  0 = desfeita (nunca deletar — audit trail)',

    INDEX idx_con_mov     (movimentacao_id),
    INDEX idx_con_titulo  (tipo_titulo, titulo_id),
    INDEX idx_con_ativa   (ativa),
    INDEX idx_con_data    (conciliado_em),
    INDEX idx_con_tipo    (tipo_conciliacao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail imutável de conciliações bancárias';

-- ─────────────────────────────────────────────────────────────
-- BLOCO 3 — Views
--   CREATE OR REPLACE VIEW: disponível desde MySQL 5.0.1 ✓
-- ─────────────────────────────────────────────────────────────

-- 3a. KPIs do mês corrente — alimenta os cards de financeiro.html
CREATE OR REPLACE VIEW vw_dashboard_financeiro AS
SELECT
    DATE_FORMAT(NOW(), '%Y-%m')                                           AS mes_referencia,

    COALESCE((
        SELECT SUM(saldo_atual) FROM contas_bancarias WHERE ativo = 1
    ), 0)                                                                 AS saldo_bancario_total,

    (SELECT COUNT(*) FROM contas_bancarias WHERE ativo = 1)              AS total_contas_ativas,

    COALESCE(SUM(CASE
        WHEN mb.tipo = 'credito'
         AND DATE_FORMAT(mb.data_lancamento, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
        THEN mb.valor ELSE 0
    END), 0)                                                             AS entradas_mes,

    COALESCE(SUM(CASE
        WHEN mb.tipo = 'debito'
         AND DATE_FORMAT(mb.data_lancamento, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
        THEN mb.valor ELSE 0
    END), 0)                                                             AS saidas_mes,

    COUNT(CASE WHEN mb.status = 'pendente'   THEN 1 END)                AS pendentes_conciliacao,
    COUNT(CASE WHEN mb.status = 'conciliado' THEN 1 END)                AS conciliados_total,

    COALESCE((
        SELECT SUM(cr.valor_original)
        FROM contas_receber cr
        WHERE cr.ativo = 1 AND cr.status IN ('PENDENTE','PARCIAL')
          AND DATE_FORMAT(cr.data_vencimento, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
    ), 0)                                                                AS a_receber_mes,

    COALESCE((
        SELECT SUM(cp.valor_original)
        FROM contas_pagar cp
        WHERE cp.ativo = 1 AND cp.status IN ('PENDENTE','PARCIAL')
          AND DATE_FORMAT(cp.data_vencimento, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
    ), 0)                                                                AS a_pagar_mes,

    COALESCE((
        SELECT SUM(cr.saldo_devedor)
        FROM contas_receber cr
        WHERE cr.ativo = 1 AND cr.status IN ('PENDENTE','PARCIAL')
          AND cr.data_vencimento < DATE_FORMAT(NOW(), '%Y-%m-01')
    ), 0)                                                                AS inadimplencia_total,

    COALESCE((
        SELECT SUM(cp.saldo_devedor)
        FROM contas_pagar cp
        WHERE cp.ativo = 1 AND cp.status IN ('PENDENTE','PARCIAL')
          AND cp.data_vencimento < DATE_FORMAT(NOW(), '%Y-%m-01')
    ), 0)                                                                AS vencidas_a_pagar

FROM movimentacoes_bancarias mb;


-- 3b. Pendências de conciliação — fila de trabalho da aba Conciliação
CREATE OR REPLACE VIEW vw_pendencias_conciliacao AS
SELECT
    mb.id,
    mb.conta_id,
    cb.nome                   AS conta_nome,
    cb.banco_nome,
    mb.data_lancamento,
    mb.tipo,
    mb.valor,
    mb.descricao,
    mb.favorecido,
    mb.checknum,
    mb.numero_documento,
    mb.memo,
    mb.origem,
    mb.data_importacao,
    mb.criado_em,

    (SELECT COUNT(*) FROM contas_receber cr
     WHERE cr.ativo = 1 AND cr.conciliado = 0
       AND cr.status IN ('PENDENTE','PARCIAL')
       AND ABS(cr.valor_original - mb.valor) <= GREATEST(mb.valor * 0.05, 0.02)
       AND cr.data_vencimento BETWEEN
           DATE_SUB(mb.data_lancamento, INTERVAL 15 DAY) AND
           DATE_ADD(mb.data_lancamento, INTERVAL 15 DAY)
    )                         AS candidatos_receber,

    (SELECT COUNT(*) FROM contas_pagar cp
     WHERE cp.ativo = 1 AND cp.conciliado = 0
       AND cp.status IN ('PENDENTE','PARCIAL')
       AND ABS(cp.valor_original - mb.valor) <= GREATEST(mb.valor * 0.05, 0.02)
       AND cp.data_vencimento BETWEEN
           DATE_SUB(mb.data_lancamento, INTERVAL 15 DAY) AND
           DATE_ADD(mb.data_lancamento, INTERVAL 15 DAY)
    )                         AS candidatos_pagar

FROM movimentacoes_bancarias mb
JOIN contas_bancarias cb ON cb.id = mb.conta_id
WHERE mb.status = 'pendente'
ORDER BY mb.data_lancamento DESC;


-- 3c. Fluxo de caixa mensal — realizado (bancário) vs. previsto (títulos)
--     12 meses: 6 passados + mês atual + 5 futuros
CREATE OR REPLACE VIEW vw_fluxo_caixa AS
SELECT
    cal.mes,
    cal.mes_label,

    COALESCE(SUM(CASE WHEN mb.tipo = 'credito' THEN mb.valor END), 0)   AS entradas_realizadas,
    COALESCE(SUM(CASE WHEN mb.tipo = 'debito'  THEN mb.valor END), 0)   AS saidas_realizadas,

    COALESCE((
        SELECT SUM(cr.valor_original - COALESCE(cr.valor_recebido, 0))
        FROM contas_receber cr
        WHERE cr.ativo = 1 AND cr.status IN ('PENDENTE','PARCIAL')
          AND DATE_FORMAT(cr.data_vencimento, '%Y-%m') = cal.mes
    ), 0)                                                                AS a_receber_previsto,

    COALESCE((
        SELECT SUM(cp.valor_original - COALESCE(cp.valor_pago, 0))
        FROM contas_pagar cp
        WHERE cp.ativo = 1 AND cp.status IN ('PENDENTE','PARCIAL')
          AND DATE_FORMAT(cp.data_vencimento, '%Y-%m') = cal.mes
    ), 0)                                                                AS a_pagar_previsto

FROM (
    SELECT
        DATE_FORMAT(DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL (n - 6) MONTH), '%Y-%m')   AS mes,
        DATE_FORMAT(DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL (n - 6) MONTH), '%b/%Y')   AS mes_label
    FROM (
        SELECT 0  AS n UNION ALL SELECT 1  UNION ALL SELECT 2  UNION ALL
        SELECT 3       UNION ALL SELECT 4  UNION ALL SELECT 5  UNION ALL
        SELECT 6       UNION ALL SELECT 7  UNION ALL SELECT 8  UNION ALL
        SELECT 9       UNION ALL SELECT 10 UNION ALL SELECT 11
    ) nums
) cal
LEFT JOIN movimentacoes_bancarias mb
    ON DATE_FORMAT(mb.data_lancamento, '%Y-%m') = cal.mes
GROUP BY cal.mes, cal.mes_label
ORDER BY cal.mes;


-- 3d. Extrato bancário completo com vínculos de conciliação
CREATE OR REPLACE VIEW vw_extrato_bancario AS
SELECT
    mb.id,
    mb.conta_id,
    cb.nome                     AS conta_nome,
    cb.banco_nome,
    cb.banco_codigo,
    mb.data_lancamento,
    mb.tipo,
    mb.valor,
    CASE WHEN mb.tipo = 'credito' THEN mb.valor ELSE -mb.valor END       AS valor_sinal,
    mb.descricao,
    mb.favorecido,
    mb.checknum,
    mb.numero_documento,
    mb.categoria,
    mb.centro_custo,
    mb.status                   AS status_conciliacao,
    mb.origem,
    mb.importacao_id,
    mb.conta_receber_id,
    cr.numero_documento         AS doc_receber,
    cr.morador_nome             AS nome_receber,
    cr.data_vencimento          AS venc_receber,
    cr.status                   AS status_receber,
    mb.conta_pagar_id,
    cp.numero_documento         AS doc_pagar,
    cp.fornecedor_nome          AS nome_pagar,
    cp.data_vencimento          AS venc_pagar,
    cp.status                   AS status_pagar,
    mb.data_importacao,
    mb.usuario_importacao,
    mb.criado_em

FROM movimentacoes_bancarias mb
JOIN  contas_bancarias cb ON cb.id = mb.conta_id
LEFT JOIN contas_receber cr ON cr.id = mb.conta_receber_id
LEFT JOIN contas_pagar   cp ON cp.id = mb.conta_pagar_id
ORDER BY mb.data_lancamento DESC, mb.id DESC;


-- 3e. Corrigir vw_saldo_contas (v1 tinha saldo_inicial duplicado)
--     GROUP BY explícito para compatibilidade com ONLY_FULL_GROUP_BY (MySQL 5.7 default)
CREATE OR REPLACE VIEW vw_saldo_contas AS
SELECT
    cb.id,
    cb.nome,
    cb.banco_nome,
    cb.banco_codigo,
    cb.agencia,
    cb.conta_numero,
    cb.conta_tipo,
    cb.moeda,
    cb.saldo_inicial,
    COALESCE(SUM(CASE WHEN mb.tipo = 'credito' THEN mb.valor ELSE 0 END), 0) AS total_creditos,
    COALESCE(SUM(CASE WHEN mb.tipo = 'debito'  THEN mb.valor ELSE 0 END), 0) AS total_debitos,
    cb.saldo_inicial
        + COALESCE(SUM(CASE WHEN mb.tipo = 'credito' THEN mb.valor ELSE 0 END), 0)
        - COALESCE(SUM(CASE WHEN mb.tipo = 'debito'  THEN mb.valor ELSE 0 END), 0) AS saldo_calculado,
    cb.saldo_atual,
    cb.ativo
FROM contas_bancarias cb
LEFT JOIN movimentacoes_bancarias mb ON mb.conta_id = cb.id
WHERE cb.ativo = 1
GROUP BY
    cb.id,  cb.nome,        cb.banco_nome,  cb.banco_codigo,
    cb.agencia, cb.conta_numero, cb.conta_tipo,  cb.moeda,
    cb.saldo_inicial, cb.saldo_atual, cb.ativo;

-- ─────────────────────────────────────────────────────────────
-- BLOCO 4 — Seeds RBAC
--   INSERT IGNORE: idempotente — não duplica se já existir ✓
-- ─────────────────────────────────────────────────────────────

INSERT IGNORE INTO modulos_sistema (chave, nome, descricao, icone, grupo, ordem)
VALUES
    ('contas_bancarias',
     'Contas Bancárias',
     'Cadastro de contas bancárias e importação de extrato OFX',
     'fas fa-university', 'financeiro', 55),

    ('conciliacao',
     'Conciliação Bancária',
     'Motor de conciliação automática entre movimentações bancárias e títulos financeiros',
     'fas fa-balance-scale', 'financeiro', 56),

    ('relatorios_bancarios',
     'Relatórios Bancários',
     'Extrato bancário, fluxo de caixa e DRE simplificado',
     'fas fa-chart-bar', 'financeiro', 57),

    ('logs_financeiro',
     'Logs Financeiro',
     'Auditoria e rastreabilidade de operações financeiras',
     'fas fa-history', 'financeiro', 58);

-- ─────────────────────────────────────────────────────────────
SET FOREIGN_KEY_CHECKS = 1;
-- ─────────────────────────────────────────────────────────────

-- ============================================================
-- FIM — migration_contas_bancarias_v2.sql
-- ─────────────────────────────────────────────────────────────
-- Compatibilidade MySQL 5.7.7:
--   ADD COLUMN IF NOT EXISTS    → stored procedure + CONTINUE HANDLER FOR 1060
--   CREATE INDEX IF NOT EXISTS  → CONTINUE HANDLER FOR 1061
--   JSON                        → LONGTEXT (json_encode/decode no PHP)
--   ONLY_FULL_GROUP_BY          → GROUP BY explícito em todas as views
--   CREATE OR REPLACE VIEW      → suportado desde MySQL 5.0.1
-- ─────────────────────────────────────────────────────────────
-- Objetos criados/alterados:
--   movimentacoes_bancarias  +10 colunas, +5 índices
--   contas_receber           +5 colunas,  +2 índices
--   contas_pagar             +5 colunas,  +2 índices
--   historico_importacoes_ofx +4 colunas
--   conciliacoes             nova tabela (audit trail)
--   vw_dashboard_financeiro  nova view
--   vw_pendencias_conciliacao nova view
--   vw_fluxo_caixa           nova view
--   vw_extrato_bancario      nova view
--   vw_saldo_contas          atualizada
--   modulos_sistema          +3 módulos RBAC
-- ============================================================
