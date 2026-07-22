-- ============================================================
-- Tabelas do Control ID Bridge
-- Execute no phpMyAdmin antes de usar o bridge
-- ============================================================

-- Adicionar colunas de status do bridge na tabela dispositivos_controlid
ALTER TABLE `dispositivos_controlid`
  ADD COLUMN IF NOT EXISTS `online` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ativo`,
  ADD COLUMN IF NOT EXISTS `ultimo_contato` DATETIME NULL AFTER `online`,
  ADD COLUMN IF NOT EXISTS `erros_consecutivos` INT NOT NULL DEFAULT 0 AFTER `ultimo_contato`,
  ADD COLUMN IF NOT EXISTS `bridge_id` VARCHAR(32) NULL AFTER `erros_consecutivos`,
  ADD COLUMN IF NOT EXISTS `bridge_versao` VARCHAR(20) NULL AFTER `bridge_id`,
  ADD COLUMN IF NOT EXISTS `ultimo_log_id` INT NOT NULL DEFAULT 0 AFTER `bridge_versao`;

-- Tabela de eventos recebidos do bridge (access_logs do Control ID)
CREATE TABLE IF NOT EXISTS `bridge_eventos_log` (
  `id`                INT(11) NOT NULL AUTO_INCREMENT,
  `dispositivo_id`    INT(11) NOT NULL,
  `log_id_externo`    INT(11) NOT NULL COMMENT 'ID do access_log no Control ID',
  `user_id_controlid` INT(11) NULL,
  `card_value`        VARCHAR(64) NULL COMMENT 'EPC da TAG UHF ou código do cartão',
  `event_type`        INT(11) NULL COMMENT '0=entrada, 1=saída',
  `event_time`        DATETIME NULL,
  `door_id`           INT(11) NULL,
  `veiculo_id`        INT(11) NULL COMMENT 'Veículo identificado pela TAG',
  `morador_id`        INT(11) NULL COMMENT 'Morador do veículo',
  `processado`        TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_disp_log` (`dispositivo_id`, `log_id_externo`),
  KEY `idx_veiculo` (`veiculo_id`),
  KEY `idx_morador` (`morador_id`),
  KEY `idx_event_time` (`event_time`),
  KEY `idx_card_value` (`card_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Eventos de acesso recebidos dos equipamentos Control ID via bridge';

-- Tabela de fila de comandos para os equipamentos
CREATE TABLE IF NOT EXISTS `bridge_fila_comandos` (
  `id`             INT(11) NOT NULL AUTO_INCREMENT,
  `dispositivo_id` INT(11) NOT NULL,
  `endpoint`       VARCHAR(128) NOT NULL COMMENT 'Endpoint da API Control ID (ex: create_objects.fcgi)',
  `verb`           ENUM('GET','POST','PUT','DELETE') NOT NULL DEFAULT 'POST',
  `body`           TEXT NULL COMMENT 'JSON do corpo da requisição',
  `status`         ENUM('pendente','executado','erro','cancelado') NOT NULL DEFAULT 'pendente',
  `resultado`      TEXT NULL COMMENT 'JSON da resposta do equipamento',
  `executado_em`   DATETIME NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by`     INT(11) NULL COMMENT 'ID do usuário admin que criou o comando',
  PRIMARY KEY (`id`),
  KEY `idx_disp_status` (`dispositivo_id`, `status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fila de comandos a serem executados nos equipamentos Control ID';

-- Adicionar coluna de origem na tabela registros_acesso (se não existir)
ALTER TABLE `registros_acesso`
  ADD COLUMN IF NOT EXISTS `origem` ENUM('manual','controlid','portal','sistema') NOT NULL DEFAULT 'manual' AFTER `observacao`;

-- Configuração da chave de autenticação do bridge
INSERT IGNORE INTO `configuracoes` (`chave`, `valor`, `descricao`, `created_at`)
VALUES (
  'bridge_api_key',
  '',
  'Chave de autenticação do Control ID Bridge. Deixe em branco para desabilitar autenticação (não recomendado em produção).',
  NOW()
);

-- ============================================================
-- Para gerar uma chave segura, execute no terminal:
--   python3 -c "import secrets; print(secrets.token_hex(32))"
-- E atualize com:
--   UPDATE configuracoes SET valor = 'SUA_CHAVE_AQUI' WHERE chave = 'bridge_api_key';
-- ============================================================
