-- ============================================================
-- MIGRAÇÃO: PWA Portal do Morador — FCM Push Notifications
-- Execute este script no banco: inlaud99_erpserra
-- ============================================================

-- ── 1. Tokens FCM dos dispositivos dos moradores ──────────────
CREATE TABLE IF NOT EXISTS `pwa_fcm_tokens` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `morador_id`    INT             NOT NULL,
    `fcm_token`     TEXT            NOT NULL COMMENT 'Token FCM do dispositivo',
    `device_info`   VARCHAR(512)    DEFAULT NULL COMMENT 'User-Agent ou info do dispositivo',
    `plataforma`    ENUM('web','android','ios') NOT NULL DEFAULT 'web',
    `ativo`         TINYINT(1)      NOT NULL DEFAULT 1,
    `criado_em`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `atualizado_em` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `ultimo_uso`    DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_morador` (`morador_id`),
    INDEX `idx_ativo`   (`ativo`),
    CONSTRAINT `fk_fcm_morador` FOREIGN KEY (`morador_id`) REFERENCES `moradores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tokens FCM dos dispositivos dos moradores para Push Notifications';

-- ── 2. Histórico de notificações push enviadas ────────────────
CREATE TABLE IF NOT EXISTS `pwa_notificacoes_push` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `titulo`            VARCHAR(255)    NOT NULL,
    `corpo`             TEXT            NOT NULL,
    `tipo`              ENUM('visitante','inadimplencia','comunicado','aviso','os','urgente','geral') NOT NULL DEFAULT 'geral',
    `url_destino`       VARCHAR(512)    DEFAULT '/frontend/portal_morador.html',
    `icone`             VARCHAR(512)    DEFAULT NULL,
    `tag`               VARCHAR(100)    DEFAULT NULL,
    `dados_extras`      JSON            DEFAULT NULL COMMENT 'Dados adicionais em JSON',
    `destinatario`      ENUM('todos','unidade','morador') NOT NULL DEFAULT 'todos',
    `morador_id`        INT             DEFAULT NULL COMMENT 'Nulo = todos os moradores',
    `unidade_id`        INT             DEFAULT NULL COMMENT 'Nulo = todas as unidades',
    `enviado_por`       INT             DEFAULT NULL COMMENT 'ID do usuário do ERP que enviou',
    `enviado_por_nome`  VARCHAR(150)    DEFAULT NULL,
    `total_tokens`      INT             DEFAULT 0 COMMENT 'Total de tokens tentados',
    `total_sucesso`     INT             DEFAULT 0 COMMENT 'Total de envios com sucesso',
    `total_falha`       INT             DEFAULT 0 COMMENT 'Total de envios com falha',
    `status`            ENUM('pendente','enviando','concluido','erro') NOT NULL DEFAULT 'pendente',
    `erro_detalhe`      TEXT            DEFAULT NULL,
    `criado_em`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `enviado_em`        DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_tipo`    (`tipo`),
    INDEX `idx_status`  (`status`),
    INDEX `idx_morador` (`morador_id`),
    INDEX `idx_criado`  (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Histórico de notificações push enviadas via FCM';

-- ── 3. Registro de recebimento por morador ────────────────────
CREATE TABLE IF NOT EXISTS `pwa_notificacoes_recebidas` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `notificacao_id`    INT UNSIGNED    NOT NULL,
    `morador_id`        INT             NOT NULL,
    `fcm_token_id`      INT UNSIGNED    DEFAULT NULL,
    `status_envio`      ENUM('sucesso','falha','invalido') NOT NULL DEFAULT 'sucesso',
    `lida`              TINYINT(1)      NOT NULL DEFAULT 0,
    `lida_em`           DATETIME        DEFAULT NULL,
    `erro_fcm`          VARCHAR(255)    DEFAULT NULL,
    `criado_em`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_notif`   (`notificacao_id`),
    INDEX `idx_morador` (`morador_id`),
    INDEX `idx_lida`    (`lida`),
    CONSTRAINT `fk_receb_notif` FOREIGN KEY (`notificacao_id`) REFERENCES `pwa_notificacoes_push` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro de recebimento de push por morador';

-- ── 4. Configurações FCM do sistema ──────────────────────────
CREATE TABLE IF NOT EXISTS `pwa_configuracoes` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `chave`         VARCHAR(100)    NOT NULL UNIQUE,
    `valor`         TEXT            DEFAULT NULL,
    `descricao`     VARCHAR(255)    DEFAULT NULL,
    `atualizado_em` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configurações do PWA e FCM';

-- Inserir configurações padrão (substitua os valores após configurar o Firebase)
INSERT INTO `pwa_configuracoes` (`chave`, `valor`, `descricao`) VALUES
('fcm_server_key',        'SUBSTITUA_PELO_SEU_FCM_SERVER_KEY',       'Chave do servidor FCM (Legacy) ou Service Account JSON'),
('fcm_project_id',        'SUBSTITUA_PELO_SEU_PROJECT_ID',           'ID do projeto Firebase'),
('fcm_api_key',           'SUBSTITUA_PELO_SEU_API_KEY',              'API Key do Firebase'),
('fcm_auth_domain',       'SUBSTITUA.firebaseapp.com',               'Auth Domain do Firebase'),
('fcm_messaging_sender_id','SUBSTITUA_PELO_SEU_SENDER_ID',           'Messaging Sender ID do Firebase'),
('fcm_app_id',            'SUBSTITUA_PELO_SEU_APP_ID',               'App ID do Firebase'),
('fcm_vapid_key',         'SUBSTITUA_PELA_SUA_VAPID_KEY',            'Chave VAPID pública para Web Push'),
('pwa_ativo',             '1',                                        'PWA ativo (1=sim, 0=não)'),
('push_visitante_ativo',  '1',                                        'Notificar visitante chegando'),
('push_inadimplencia_ativo','1',                                      'Notificar inadimplência'),
('push_comunicado_ativo', '1',                                        'Notificar novos comunicados'),
('push_os_ativo',         '1',                                        'Notificar atualização de OS')
ON DUPLICATE KEY UPDATE `descricao` = VALUES(`descricao`);
