-- ============================================================
-- MIGRATION PWA v2.0 — Central PWA / Firebase HTTP v1
-- Data: 2026-06-29
-- Autor: Sistema
-- Depende de: migration_pwa_fcm.sql (v1)
-- ============================================================
-- Execute: mysql -u root -p nome_do_banco < sql/migration_pwa_v2.sql
-- Idempotente: pode ser executado múltiplas vezes sem dano
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ─── TABELA: pwa_versao ──────────────────────────────────────
-- Rastreamento de versões do PWA com histórico completo
CREATE TABLE IF NOT EXISTS pwa_versao (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    versao          VARCHAR(20)      NOT NULL,
    cache_version   VARCHAR(50)      NOT NULL,
    changelog       TEXT             NULL,
    tipo            ENUM('major','minor','patch','build') NOT NULL DEFAULT 'patch',
    publicado_por   INT              NULL,
    publicado_em    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ativo           TINYINT(1)       NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_ativo (ativo),
    KEY idx_publicado_em (publicado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versão inicial se tabela estava vazia
INSERT IGNORE INTO pwa_versao (id, versao, cache_version, changelog, tipo)
VALUES (1, '1.0.0', 'portal-morador-v1.0.0', 'Versão inicial — migração para arquitetura v2', 'major');

-- ─── TABELA: pwa_logs ────────────────────────────────────────
-- Log centralizado de todos os eventos PWA
CREATE TABLE IF NOT EXISTS pwa_logs (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    tipo            ENUM(
                        'instalacao','atualizacao','push_enviado','push_recebido',
                        'push_erro','permissao_concedida','permissao_negada',
                        'token_registrado','token_removido','token_refresh',
                        'firebase_init','firebase_erro','cache_atualizado',
                        'cache_limpo','sw_registrado','sw_atualizado','sw_erro',
                        'manifest_ok','manifest_erro','health_check',
                        'login','logout','config_salva','erro_geral'
                    ) NOT NULL,
    nivel           ENUM('info','aviso','erro') NOT NULL DEFAULT 'info',
    morador_id      INT              NULL,
    token_id        INT UNSIGNED     NULL,
    descricao       VARCHAR(500)     NOT NULL,
    extras          JSON             NULL,
    ip              VARCHAR(45)      NULL,
    user_agent      VARCHAR(512)     NULL,
    plataforma      ENUM('web','android','ios','desktop') NULL,
    criado_em       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tipo (tipo),
    KEY idx_nivel (nivel),
    KEY idx_morador (morador_id),
    KEY idx_token (token_id),
    KEY idx_criado_em (criado_em),
    KEY idx_tipo_nivel (tipo, nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── TABELA: pwa_oauth_cache ─────────────────────────────────
-- Cache do token OAuth2 para FCM HTTP v1 (evita reauth a cada envio)
CREATE TABLE IF NOT EXISTS pwa_oauth_cache (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    access_token    TEXT             NOT NULL,
    expires_at      DATETIME         NOT NULL,
    criado_em       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── COLUNAS NOVAS EM pwa_fcm_tokens ────────────────────────
-- Adiciona campos de análise de dispositivo (idempotente via INFORMATION_SCHEMA)

-- device_model: modelo do dispositivo (iPhone 14, Galaxy S23, etc.)
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pwa_fcm_tokens' AND COLUMN_NAME = 'device_model');
SET @sql = IF(@col = 0,
    'ALTER TABLE pwa_fcm_tokens ADD COLUMN device_model VARCHAR(100) NULL AFTER device_info',
    'SELECT "device_model já existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- device_os: sistema operacional (Android 14, iOS 17, Windows 11)
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pwa_fcm_tokens' AND COLUMN_NAME = 'device_os');
SET @sql = IF(@col = 0,
    'ALTER TABLE pwa_fcm_tokens ADD COLUMN device_os VARCHAR(100) NULL AFTER device_model',
    'SELECT "device_os já existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- device_browser: navegador (Chrome 125, Firefox 126, Safari 17)
SET @col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pwa_fcm_tokens' AND COLUMN_NAME = 'device_browser');
SET @sql = IF(@col = 0,
    'ALTER TABLE pwa_fcm_tokens ADD COLUMN device_browser VARCHAR(100) NULL AFTER device_os',
    'SELECT "device_browser já existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── NOVAS CHAVES EM pwa_configuracoes ──────────────────────
-- Configura o caminho do Service Account e metadados FCM v1

INSERT IGNORE INTO pwa_configuracoes (chave, valor, descricao) VALUES
-- FCM HTTP v1 (Service Account)
('fcm_service_account_path',  '',          'Caminho do arquivo service-account.json (relativo à raiz do projeto PHP)'),
('fcm_service_account_email', '',          'client_email do Service Account (apenas informativo)'),
('fcm_project_number',        '',          'Número do projeto Firebase (usado para diagnóstico)'),
-- PWA App Identity
('pwa_versao',                '1.0.0',     'Versão atual do PWA (formato semver)'),
('pwa_cache_version',         'portal-morador-v1.0.0', 'Nome da versão do cache do Service Worker'),
('pwa_install_url',           '/frontend/portal_morador.html', 'URL de instalação do PWA (usada no QR Code)'),
('pwa_nome_app',              'Portal do Morador', 'Nome do aplicativo exibido no banner de instalação'),
('pwa_tema_cor',              '#2563eb',   'Cor principal do PWA (theme_color do manifest)'),
-- Feature flags
('push_urgente_ativo',        '1',         'Ativar push para notificações urgentes'),
('pwa_modo_manutencao',       '0',         'Ativar modo manutenção (bloqueia acesso ao portal)');

-- Remove chave obsoleta fcm_server_key se ainda existir com valor legacy
-- (mantém a chave no banco para registro histórico, mas ela não é mais utilizada)
UPDATE pwa_configuracoes
SET descricao = '[OBSOLETO — substituído por Service Account para FCM v1] Chave de servidor FCM Legacy (encerrada em 22/06/2024)'
WHERE chave = 'fcm_server_key'
  AND descricao NOT LIKE '%OBSOLETO%';

-- ─── MÓDULO RBAC: pwa_central ───────────────────────────────
-- Registra o módulo Central PWA no sistema de permissões
INSERT IGNORE INTO modulos_sistema (chave, nome, grupo, icone, descricao, permissao_minima, ordem)
VALUES ('pwa_central', 'Central PWA', 'Sistema', 'fas fa-broadcast-tower',
        'Painel administrativo do Portal do Morador — Firebase, dispositivos, notificações push, versão e logs',
        'operador', 395);

SET foreign_key_checks = 1;

-- ─── VERIFICAÇÃO FINAL ───────────────────────────────────────
SELECT 'migration_pwa_v2.sql executada com sucesso' AS status;
SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (
    'pwa_versao','pwa_logs','pwa_oauth_cache','pwa_fcm_tokens','pwa_configuracoes'
);
