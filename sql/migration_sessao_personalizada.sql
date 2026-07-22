-- =====================================================================
-- MIGRAÇÃO: Sistema de Sessão Personalizada por Usuário
-- Versão: 3.0 | Data: 2026-05-10
-- =====================================================================
-- Execute este script UMA VEZ no banco de dados de produção.
-- Todos os comandos usam IF NOT EXISTS / ON DUPLICATE KEY UPDATE
-- para serem idempotentes (seguros para executar mais de uma vez).
-- =====================================================================

-- ── 1. Tabela de configuração global de sessão ────────────────────────────
CREATE TABLE IF NOT EXISTS `config_sessao` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `chave`        VARCHAR(100) NOT NULL,
    `valor`        VARCHAR(255) NOT NULL,
    `descricao`    TEXT         DEFAULT NULL,
    `atualizado_em` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_chave` (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configurações globais do sistema de sessão';

-- Valores padrão: 30 min total, 10 min inatividade, aviso 5 min
INSERT INTO `config_sessao` (`chave`, `valor`, `descricao`) VALUES
    ('timeout_total_min',       '30',  'Tempo total máximo de sessão em minutos (padrão: 30)'),
    ('timeout_inatividade_min', '10',  'Tempo de inatividade antes de encerrar sessão em minutos (padrão: 10)'),
    ('aviso_expiracao_min',     '5',   'Minutos antes de expirar para exibir aviso visual (padrão: 5)'),
    ('renovacao_automatica',    '1',   '1 = renovar automaticamente ao detectar atividade do usuário')
ON DUPLICATE KEY UPDATE `descricao` = VALUES(`descricao`);

-- ── 2. Colunas de sessão personalizada na tabela moradores ───────────────
ALTER TABLE `moradores`
    ADD COLUMN IF NOT EXISTS `sessao_personalizada`    TINYINT(1)        DEFAULT 0
        COMMENT '1 = usa timeout personalizado; 0 = usa configuração global',
    ADD COLUMN IF NOT EXISTS `timeout_total_min`       SMALLINT UNSIGNED DEFAULT NULL
        COMMENT 'Timeout total personalizado em minutos (NULL = usa global)',
    ADD COLUMN IF NOT EXISTS `timeout_inatividade_min` SMALLINT UNSIGNED DEFAULT NULL
        COMMENT 'Timeout de inatividade personalizado em minutos (NULL = usa global)';

-- ── 3. Colunas adicionais na tabela sessoes_portal ───────────────────────
ALTER TABLE `sessoes_portal`
    ADD COLUMN IF NOT EXISTS `ultimo_ativo`            DATETIME          DEFAULT NULL
        COMMENT 'Última vez que o usuário fez alguma ação (ping de atividade)',
    ADD COLUMN IF NOT EXISTS `timeout_total_min`       SMALLINT UNSIGNED DEFAULT 30
        COMMENT 'Timeout total aplicado nesta sessão (copiado no login)',
    ADD COLUMN IF NOT EXISTS `timeout_inatividade_min` SMALLINT UNSIGNED DEFAULT 10
        COMMENT 'Timeout de inatividade aplicado nesta sessão (copiado no login)';

-- Inicializar ultimo_ativo com data_login para sessões existentes
UPDATE `sessoes_portal`
SET `ultimo_ativo` = `data_login`
WHERE `ultimo_ativo` IS NULL AND `data_login` IS NOT NULL;

-- ── 4. Índices de performance ─────────────────────────────────────────────
ALTER TABLE `sessoes_portal`
    ADD INDEX IF NOT EXISTS `idx_sessoes_token_ativo`   (`token`(64), `ativo`),
    ADD INDEX IF NOT EXISTS `idx_sessoes_morador_ativo` (`morador_id`, `ativo`),
    ADD INDEX IF NOT EXISTS `idx_sessoes_expiracao`     (`data_expiracao`, `ativo`),
    ADD INDEX IF NOT EXISTS `idx_ultimo_ativo`          (`ultimo_ativo`);

-- ── 5. Verificação final ──────────────────────────────────────────────────
SELECT 'Migração v3.0 concluída com sucesso!' AS status;

SELECT 'config_sessao' AS tabela, COUNT(*) AS registros FROM `config_sessao`
UNION ALL
SELECT 'sessoes_portal (ativas)' AS tabela, COUNT(*) AS registros
    FROM `sessoes_portal` WHERE ativo = 1
UNION ALL
SELECT 'moradores (com sessao personalizada)' AS tabela, COUNT(*) AS registros
    FROM `moradores` WHERE sessao_personalizada = 1;

SELECT * FROM `config_sessao` ORDER BY chave;

-- =====================================================================
-- FIM DA MIGRAÇÃO
-- =====================================================================
