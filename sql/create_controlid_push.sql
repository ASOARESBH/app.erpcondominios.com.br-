-- ============================================================
-- Módulo: Control ID — Push Mode & Online Mode
-- Versão: 2.0 — 2026-04-20
-- Descrição: Tabelas e colunas adicionais para suporte ao
--            Modo Push (polling de comandos) e Modo Online
--            (identificação em tempo real via HTTP push)
-- ============================================================

-- -----------------------------------------------------------
-- Fila de comandos a enviar para dispositivos via Push Mode
-- O servidor enfileira comandos; o dispositivo busca e executa
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `controlid_push_queue` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `dispositivo_id`  INT(11)      NOT NULL COMMENT 'FK → dispositivos_controlid.id',
  `device_id`       BIGINT       DEFAULT NULL COMMENT 'device_id reportado pelo equipamento',
  `endpoint`        VARCHAR(100) NOT NULL DEFAULT 'load_objects'
                    COMMENT 'Endpoint Control ID a executar (ex: load_objects, create_objects)',
  `verb`            ENUM('GET','POST') NOT NULL DEFAULT 'POST',
  `body`            TEXT         NOT NULL COMMENT 'JSON do comando a enviar',
  `status`          ENUM('pendente','enviado','executado','erro') NOT NULL DEFAULT 'pendente',
  `resultado`       TEXT         DEFAULT NULL COMMENT 'JSON retornado pelo equipamento',
  `tentativas`      TINYINT      NOT NULL DEFAULT 0,
  `criado_por`      INT(11)      DEFAULT NULL,
  `criado_em`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `enviado_em`      DATETIME     DEFAULT NULL,
  `executado_em`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_disp_status` (`dispositivo_id`, `status`),
  KEY `idx_criado_em` (`criado_em`),
  CONSTRAINT `fk_pushq_disp`
    FOREIGN KEY (`dispositivo_id`) REFERENCES `dispositivos_controlid` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fila de comandos push para dispositivos Control ID';

-- -----------------------------------------------------------
-- Log de todos os eventos recebidos via Push/Online Mode
-- (uhf_tag, card, qrcode, user_identified, heartbeat, etc.)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `controlid_push_eventos` (
  `id`                INT(11)      NOT NULL AUTO_INCREMENT,
  `dispositivo_id`    INT(11)      DEFAULT NULL COMMENT 'FK → dispositivos_controlid.id (se identificado)',
  `device_id`         BIGINT       DEFAULT NULL COMMENT 'device_id enviado pelo equipamento',
  `uuid`              VARCHAR(100) DEFAULT NULL,
  `tipo_evento`       VARCHAR(50)  NOT NULL
                      COMMENT 'uhf_tag | card | qrcode | user_identified | heartbeat | dao | door',
  `payload`           TEXT         NOT NULL COMMENT 'Payload raw JSON/form recebido',
  `tag_value`         VARCHAR(100) DEFAULT NULL,
  `card_value`        BIGINT       DEFAULT NULL,
  `qrcode_value`      VARCHAR(500) DEFAULT NULL,
  `controlid_user_id` BIGINT       DEFAULT NULL,
  `evento_codigo`     TINYINT      DEFAULT NULL COMMENT '6=negado 7=concedido (resposta server)',
  `veiculo_id`        INT(11)      DEFAULT NULL,
  `morador_id`        INT(11)      DEFAULT NULL,
  `acesso_liberado`   TINYINT(1)   NOT NULL DEFAULT 0,
  `resposta_enviada`  TEXT         DEFAULT NULL COMMENT 'JSON da resposta retornada ao equipamento',
  `portal_id`         INT(11)      DEFAULT NULL,
  `identifier_id`     INT(11)      DEFAULT NULL,
  `data_evento`       DATETIME     DEFAULT NULL COMMENT 'Timestamp do equipamento',
  `data_recebimento`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dispositivo` (`dispositivo_id`),
  KEY `idx_tag`         (`tag_value`),
  KEY `idx_data`        (`data_evento`),
  KEY `idx_tipo`        (`tipo_evento`),
  CONSTRAINT `fk_pushev_disp`
    FOREIGN KEY (`dispositivo_id`) REFERENCES `dispositivos_controlid` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Eventos recebidos via Push Mode e Online Mode do Control ID';

-- -----------------------------------------------------------
-- Adicionar colunas push/online em dispositivos_controlid
-- -----------------------------------------------------------
ALTER TABLE `dispositivos_controlid`
  ADD COLUMN IF NOT EXISTS `modo_operacao`
    ENUM('standalone','push','online_pro','online_enterprise') NOT NULL DEFAULT 'standalone'
    COMMENT 'Modo de operação: standalone=PULL, push=polling commands, online=events real-time'
    AFTER `ativo`,

  ADD COLUMN IF NOT EXISTS `push_ativo`
    TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1=Push Mode ativado e configurado no equipamento'
    AFTER `modo_operacao`,

  ADD COLUMN IF NOT EXISTS `push_device_id`
    BIGINT DEFAULT NULL
    COMMENT 'device_id interno do Control ID (enviado nos requests push)'
    AFTER `push_ativo`,

  ADD COLUMN IF NOT EXISTS `push_uuid`
    VARCHAR(100) DEFAULT NULL
    COMMENT 'Último UUID de polling do equipamento'
    AFTER `push_device_id`,

  ADD COLUMN IF NOT EXISTS `push_ultimo_contato`
    DATETIME DEFAULT NULL
    COMMENT 'Último request push recebido do equipamento'
    AFTER `push_uuid`,

  ADD COLUMN IF NOT EXISTS `push_server_url`
    VARCHAR(500) DEFAULT NULL
    COMMENT 'URL configurada no equipamento para push (ex: https://server.com/api/controlid)'
    AFTER `push_ultimo_contato`,

  ADD COLUMN IF NOT EXISTS `online_server_url`
    VARCHAR(500) DEFAULT NULL
    COMMENT 'URL servidor configurada no equipamento para online mode'
    AFTER `push_server_url`,

  ADD COLUMN IF NOT EXISTS `acao_acesso`
    ENUM('door','sec_box','catra') NOT NULL DEFAULT 'door'
    COMMENT 'Ação para liberar acesso: door=relay interno, sec_box=relay externo MAE, catra=catraca'
    AFTER `online_server_url`,

  ADD COLUMN IF NOT EXISTS `acao_acesso_params`
    VARCHAR(100) DEFAULT 'door=1'
    COMMENT 'Parâmetros da ação de liberação (ex: door=1, id=65793)'
    AFTER `acao_acesso`;

-- -----------------------------------------------------------
-- Estender ENUM de ações no sync_log para incluir push
-- -----------------------------------------------------------
ALTER TABLE `dispositivos_controlid_sync_log`
  MODIFY COLUMN `acao`
    ENUM('sincronizar_tags','testar_conexao','criar_usuario','remover_usuario',
         'criar_tag','remover_tag','coletar_logs',
         'configurar_push','configurar_online','enviar_comando','status_push')
    NOT NULL;
