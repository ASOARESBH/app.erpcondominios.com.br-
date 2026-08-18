-- ============================================================================
-- ERP Condomínio — Comparações persistentes de inadimplência
-- Compatível com MySQL/MariaDB 5.7
--
-- Registra o resultado gerencial de cada snapshot em relação ao imediatamente
-- anterior do mesmo tenant. Não altera dados financeiros operacionais.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `inadimplencia_comparacoes` (
    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `tenant_id` INT(11) NOT NULL,
    `importacao_atual_id` INT(11) NOT NULL,
    `importacao_anterior_id` INT(11) DEFAULT NULL,
    `status_comparacao` ENUM('PRIMEIRO_SNAPSHOT','SEM_MUDANCA','ATUALIZADO') NOT NULL DEFAULT 'PRIMEIRO_SNAPSHOT',
    `delta_total_projetado` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `variacao_pct` DECIMAL(9,4) DEFAULT NULL,
    `total_novas_glebas` INT(11) NOT NULL DEFAULT 0,
    `total_evoluindo` INT(11) NOT NULL DEFAULT 0,
    `total_corrigidas` INT(11) NOT NULL DEFAULT 0,
    `total_quitadas` INT(11) NOT NULL DEFAULT 0,
    `total_risco_alto` INT(11) NOT NULL DEFAULT 0,
    `resumo_json` LONGTEXT DEFAULT NULL,
    `criado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_inad_comparacao_atual` (`tenant_id`,`importacao_atual_id`),
    KEY `idx_inad_comparacao_anterior` (`tenant_id`,`importacao_anterior_id`),
    KEY `idx_inad_comparacao_status` (`tenant_id`,`status_comparacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Validação pós-deploy (somente leitura):
-- SELECT tenant_id, importacao_atual_id, importacao_anterior_id, status_comparacao,
--        delta_total_projetado, total_novas_glebas, total_evoluindo, total_risco_alto
-- FROM inadimplencia_comparacoes
-- ORDER BY id DESC;
