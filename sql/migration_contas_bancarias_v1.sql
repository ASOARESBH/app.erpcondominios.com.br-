-- ============================================================
-- MIGRATION: Contas Bancárias + Movimentações OFX
-- Versão: 1.0  |  Data: 2026-06-08
-- Banco: inlaud99_erpserra
-- ============================================================

-- ─────────────────────────────────────────────────────────────
-- 1. CONTAS BANCÁRIAS
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS contas_bancarias (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(120) NOT NULL COMMENT 'Nome/apelido da conta (ex: Bradesco Corrente)',
    banco_codigo    VARCHAR(10)  NOT NULL COMMENT 'Código do banco (ex: 0237)',
    banco_nome      VARCHAR(80)  NOT NULL COMMENT 'Nome do banco (ex: Banco Bradesco S.A.)',
    agencia         VARCHAR(20)  NOT NULL,
    conta_numero    VARCHAR(30)  NOT NULL,
    conta_tipo      ENUM('corrente','poupanca','investimento','caixa') NOT NULL DEFAULT 'corrente',
    moeda           CHAR(3)      NOT NULL DEFAULT 'BRL',
    saldo_inicial   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    saldo_atual     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    ativo           TINYINT(1)   NOT NULL DEFAULT 1,
    observacoes     TEXT,
    criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_conta (banco_codigo, agencia, conta_numero)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Contas bancárias cadastradas no sistema';

-- ─────────────────────────────────────────────────────────────
-- 2. MOVIMENTAÇÕES BANCÁRIAS (transações OFX + manuais)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS movimentacoes_bancarias (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conta_id        INT UNSIGNED NOT NULL,
    fitid           VARCHAR(80)  NULL COMMENT 'ID único OFX (FITID) — NULL para lançamentos manuais',
    tipo            ENUM('credito','debito') NOT NULL,
    valor           DECIMAL(15,2) NOT NULL COMMENT 'Sempre positivo; tipo indica crédito/débito',
    data_lancamento DATE         NOT NULL,
    descricao       VARCHAR(500) NOT NULL,
    checknum        VARCHAR(30)  NULL COMMENT 'Número do cheque/documento (CHECKNUM do OFX)',
    categoria       VARCHAR(80)  NULL COMMENT 'Categoria manual para relatórios',
    conciliado      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '0=pendente, 1=conciliado',
    origem          ENUM('ofx','manual','importacao') NOT NULL DEFAULT 'ofx',
    importacao_id   INT UNSIGNED NULL COMMENT 'FK para historico_importacoes_ofx',
    observacoes     TEXT,
    criado_em       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_mov_conta FOREIGN KEY (conta_id) REFERENCES contas_bancarias(id) ON DELETE CASCADE,
    UNIQUE KEY uq_fitid_conta (conta_id, fitid),
    INDEX idx_conta_data (conta_id, data_lancamento),
    INDEX idx_tipo (tipo),
    INDEX idx_conciliado (conciliado),
    INDEX idx_importacao (importacao_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Movimentações bancárias (OFX + manuais)';

-- ─────────────────────────────────────────────────────────────
-- 3. HISTÓRICO DE IMPORTAÇÕES OFX
--    Controla qual foi o último arquivo importado por conta
--    para importar apenas o restante nas próximas importações
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS historico_importacoes_ofx (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conta_id            INT UNSIGNED NOT NULL,
    nome_arquivo        VARCHAR(255) NOT NULL,
    banco_id_ofx        VARCHAR(20)  NULL COMMENT 'BANKID do arquivo OFX',
    acct_id_ofx         VARCHAR(40)  NULL COMMENT 'ACCTID do arquivo OFX',
    dt_inicio_ofx       DATE         NULL COMMENT 'DTSTART do arquivo OFX',
    dt_fim_ofx          DATE         NULL COMMENT 'DTEND do arquivo OFX',
    ultimo_fitid        VARCHAR(80)  NULL COMMENT 'FITID da última transação importada',
    ultima_data         DATE         NULL COMMENT 'Data da última transação importada',
    total_transacoes    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total de transações no arquivo',
    importadas          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Novas transações importadas',
    duplicadas          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Transações já existentes (ignoradas)',
    saldo_final_ofx     DECIMAL(15,2) NULL COMMENT 'BALAMT do arquivo OFX',
    importado_por       VARCHAR(80)  NULL COMMENT 'Usuário que fez a importação',
    importado_em        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_imp_conta FOREIGN KEY (conta_id) REFERENCES contas_bancarias(id) ON DELETE CASCADE,
    INDEX idx_conta_data (conta_id, importado_em),
    INDEX idx_ultimo_fitid (conta_id, ultimo_fitid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico de importações OFX por conta bancária';

-- ─────────────────────────────────────────────────────────────
-- 4. ADICIONAR FK de movimentacoes_bancarias → historico_importacoes_ofx
-- ─────────────────────────────────────────────────────────────
-- (Feito após criar ambas as tabelas para evitar erro de FK)
ALTER TABLE movimentacoes_bancarias
    ADD CONSTRAINT fk_mov_importacao
    FOREIGN KEY (importacao_id) REFERENCES historico_importacoes_ofx(id)
    ON DELETE SET NULL;

-- ─────────────────────────────────────────────────────────────
-- 5. SEED: Módulo no catálogo de permissões
-- ─────────────────────────────────────────────────────────────
INSERT IGNORE INTO modulos_sistema (chave, nome, descricao, icone, grupo, ordem)
VALUES
    ('contas_bancarias', 'Contas Bancárias', 'Cadastro de contas bancárias e importação OFX', 'fas fa-university', 'financeiro', 55);

-- ─────────────────────────────────────────────────────────────
-- 6. VIEW auxiliar para saldo por conta (facilita relatórios)
-- ─────────────────────────────────────────────────────────────
CREATE OR REPLACE VIEW vw_saldo_contas AS
SELECT
    cb.id,
    cb.nome,
    cb.banco_nome,
    cb.agencia,
    cb.conta_numero,
    cb.conta_tipo,
    cb.saldo_inicial,
    COALESCE(SUM(CASE WHEN mb.tipo = 'credito' THEN mb.valor ELSE 0 END), 0) AS total_creditos,
    COALESCE(SUM(CASE WHEN mb.tipo = 'debito'  THEN mb.valor ELSE 0 END), 0) AS total_debitos,
    cb.saldo_inicial
        + COALESCE(SUM(CASE WHEN mb.tipo = 'credito' THEN mb.valor ELSE 0 END), 0)
        - COALESCE(SUM(CASE WHEN mb.tipo = 'debito'  THEN mb.valor ELSE 0 END), 0) AS saldo_calculado
FROM contas_bancarias cb
LEFT JOIN movimentacoes_bancarias mb ON mb.conta_id = cb.id
WHERE cb.ativo = 1
GROUP BY cb.id;

-- ─────────────────────────────────────────────────────────────
-- 7. TABELA: Bancos Brasileiros (autocomplete)
-- Execute também: seed_bancos_brasileiros.sql
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `bancos_brasileiros` (
  `id`        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `codigo`    VARCHAR(10)  NOT NULL COMMENT 'Código COMPE (3 dígitos) ou ISPB',
  `ispb`      VARCHAR(8)   DEFAULT NULL COMMENT 'Código ISPB (8 dígitos)',
  `nome`      VARCHAR(120) NOT NULL,
  `nome_curto` VARCHAR(60) DEFAULT NULL,
  `ativo`     TINYINT(1)   NOT NULL DEFAULT 1,
  UNIQUE KEY `uk_codigo` (`codigo`),
  KEY `idx_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tabela de bancos brasileiros — COMPE/BCB';
-- IMPORTANTE: Após executar esta migration, execute também seed_bancos_brasileiros.sql
-- para popular a tabela com os 332 bancos brasileiros.
-- OU acesse: https://app.erpcondominios.com.br/api/api_contas_bancarias.php?acao=migration_bancos
