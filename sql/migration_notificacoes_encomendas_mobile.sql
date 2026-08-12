-- ================================================================
-- ERP CONDOMÍNIOS — Notificações de Encomendas no Aplicativo
-- Banco MySQL/MariaDB 5.7+
--
-- Execute UMA VEZ no banco de produção, depois de aplicar a migração
-- multi-tenant fase 1. Faça backup do banco antes da execução.
-- ================================================================

START TRANSACTION;

-- ----------------------------------------------------------------
-- 1. Histórico individual de notificações do morador.
--    A tabela é a fonte de verdade da tela do aplicativo. O campo
--    push_status é apenas diagnóstico do envio complementar via FCM.
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notificacoes_morador` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` INT NOT NULL,
    `morador_id` INT NOT NULL,
    `protocolo_id` INT NOT NULL,
    `tipo` ENUM('mercadoria_chegou','mercadoria_entregue') NOT NULL,
    `titulo` VARCHAR(150) NOT NULL,
    `mensagem` VARCHAR(500) NOT NULL,
    `descricao_mercadoria` VARCHAR(255) NOT NULL,
    `nome_recebedor` VARCHAR(150) DEFAULT NULL,
    `lida` TINYINT(1) NOT NULL DEFAULT 0,
    `lida_em` DATETIME DEFAULT NULL,
    `push_status` ENUM('pendente','enviado','falhou','sem_token','desativado','nao_configurado') NOT NULL DEFAULT 'pendente',
    `push_detalhe` VARCHAR(255) DEFAULT NULL,
    `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notif_morador_protocolo_evento` (`tenant_id`,`morador_id`,`protocolo_id`,`tipo`),
    KEY `idx_notif_morador_listagem` (`tenant_id`,`morador_id`,`lida`,`criado_em`),
    KEY `idx_notif_protocolo` (`tenant_id`,`protocolo_id`),
    CONSTRAINT `fk_notif_morador_morador`
        FOREIGN KEY (`morador_id`) REFERENCES `moradores` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_morador_protocolo`
        FOREIGN KEY (`protocolo_id`) REFERENCES `protocolos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Eventos de encomendas visíveis no aplicativo do morador';

-- ----------------------------------------------------------------
-- 2. Configuração por tenant. A antiga chave única global "chave"
--    impede que condomínios tenham configurações independentes.
--    Se a migração multi-tenant já foi aplicada, execute as duas linhas.
-- ----------------------------------------------------------------
ALTER TABLE `pwa_configuracoes`
    DROP INDEX `chave`,
    ADD UNIQUE KEY `uq_pwa_config_tenant_chave` (`tenant_id`, `chave`);

-- ----------------------------------------------------------------
-- 3. Habilita alertas de encomenda por padrão para cada tenant já
--    cadastrado. O administrador pode alterar a chave para 0 em caso
--    de necessidade. A inserção é idempotente.
-- ----------------------------------------------------------------
INSERT INTO `pwa_configuracoes` (`tenant_id`, `chave`, `valor`, `descricao`)
SELECT DISTINCT `tenant_id`, 'push_encomenda_ativo', '1',
       'Exibir push quando uma encomenda chegar ou for entregue'
FROM `moradores`
ON DUPLICATE KEY UPDATE `descricao` = VALUES(`descricao`);

-- ----------------------------------------------------------------
-- 4. Índices de desempenho para busca de tokens no envio push.
-- ----------------------------------------------------------------
ALTER TABLE `pwa_fcm_tokens`
    ADD KEY `idx_fcm_tenant_morador_ativo` (`tenant_id`, `morador_id`, `ativo`);

COMMIT;

-- ================================================================
-- VERIFICAÇÃO PÓS-MIGRAÇÃO
-- ================================================================
-- SHOW CREATE TABLE notificacoes_morador;
-- SELECT tenant_id, chave, valor
-- FROM pwa_configuracoes
-- WHERE chave = 'push_encomenda_ativo';
-- ================================================================
