-- ============================================================
-- Módulo: Dispositivos Control ID
-- Versão: 1.0 — 2026-04-19
-- Descrição: Tabelas para cadastro de leitores Control ID
--            (IDUHF UHF 15m e demais modelos), sincronização
--            de TAGs RFID veiculares e log de leituras.
-- ============================================================

-- -----------------------------------------------------------
-- Tabela principal de dispositivos Control ID
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dispositivos_controlid` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `nome`             VARCHAR(200) NOT NULL COMMENT 'Nome identificador (ex: Leitor UHF Portaria)',
  `modelo`           VARCHAR(100) NOT NULL COMMENT 'Modelo do equipamento (ex: IDUHF, iDAccess Nano)',
  `tipo`             ENUM('uhf','rfid','facial','biometria','qrcode','outro') NOT NULL DEFAULT 'uhf',
  `ip_address`       VARCHAR(45)  NOT NULL COMMENT 'IP do equipamento na rede local',
  `porta`            INT(11)      NOT NULL DEFAULT 80 COMMENT 'Porta HTTP da API (padrão 80)',
  `usuario_api`      VARCHAR(100) NOT NULL DEFAULT 'admin' COMMENT 'Usuário da API Control ID',
  `senha_api`        VARCHAR(255) NOT NULL COMMENT 'Senha da API (armazenada criptografada)',
  `area_instalacao`  VARCHAR(200) DEFAULT NULL COMMENT 'Local de instalação (ex: Portaria Principal)',
  `descricao`        TEXT         DEFAULT NULL,
  `ativo`            TINYINT(1)   NOT NULL DEFAULT 1,
  `status_online`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=Online 0=Offline',
  `ultimo_ping`      DATETIME     DEFAULT NULL COMMENT 'Último teste de conexão bem-sucedido',
  `session_token`    VARCHAR(255) DEFAULT NULL COMMENT 'Token de sessão ativo na API Control ID',
  `session_expiry`   DATETIME     DEFAULT NULL COMMENT 'Expiração do token de sessão',
  `data_cadastro`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ip_porta` (`ip_address`, `porta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Leitores Control ID cadastrados no sistema';

-- -----------------------------------------------------------
-- Tabela de log de sincronizações de TAGs com Control ID
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dispositivos_controlid_sync_log` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `dispositivo_id`  INT(11)      NOT NULL,
  `acao`            ENUM('sincronizar_tags','testar_conexao','criar_usuario','remover_usuario','criar_tag','remover_tag','coletar_logs') NOT NULL,
  `status`          ENUM('sucesso','erro','parcial') NOT NULL,
  `detalhes`        TEXT         DEFAULT NULL COMMENT 'JSON com detalhes da operação',
  `total_enviados`  INT(11)      DEFAULT 0,
  `total_erros`     INT(11)      DEFAULT 0,
  `data_hora`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dispositivo_id` (`dispositivo_id`),
  KEY `idx_data_hora` (`data_hora`),
  CONSTRAINT `fk_sync_dispositivo` FOREIGN KEY (`dispositivo_id`)
    REFERENCES `dispositivos_controlid` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Log de sincronizações entre ERP e dispositivos Control ID';

-- -----------------------------------------------------------
-- Tabela de leituras recebidas do Control ID (access_logs)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dispositivos_controlid_leituras` (
  `id`              INT(11)      NOT NULL AUTO_INCREMENT,
  `dispositivo_id`  INT(11)      NOT NULL,
  `controlid_log_id` BIGINT     DEFAULT NULL COMMENT 'ID do log no equipamento Control ID',
  `data_hora`       DATETIME     NOT NULL COMMENT 'Horário da leitura (do equipamento)',
  `tipo_evento`     TINYINT(1)   DEFAULT NULL COMMENT '6=Acesso concedido, 5=Acesso negado, etc.',
  `tag_value`       VARCHAR(100) DEFAULT NULL COMMENT 'Valor da TAG UHF/RFID lida',
  `card_value`      BIGINT       DEFAULT NULL COMMENT 'Valor do cartão Wiegand',
  `controlid_user_id` BIGINT    DEFAULT NULL COMMENT 'ID do usuário no Control ID',
  `veiculo_id`      INT(11)      DEFAULT NULL COMMENT 'Veículo identificado no ERP',
  `morador_id`      INT(11)      DEFAULT NULL COMMENT 'Morador vinculado',
  `acesso_liberado` TINYINT(1)   DEFAULT 0,
  `processado`      TINYINT(1)   DEFAULT 0 COMMENT '1=Já registrado em registros_acesso',
  `data_importacao` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dispositivo_id` (`dispositivo_id`),
  KEY `idx_tag_value` (`tag_value`),
  KEY `idx_data_hora` (`data_hora`),
  CONSTRAINT `fk_leitura_dispositivo` FOREIGN KEY (`dispositivo_id`)
    REFERENCES `dispositivos_controlid` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Leituras de acesso coletadas dos dispositivos Control ID';

-- -----------------------------------------------------------
-- Adicionar coluna controlid_user_id na tabela veiculos
-- (para rastrear o ID do usuário criado no Control ID)
-- -----------------------------------------------------------
ALTER TABLE `veiculos`
  ADD COLUMN IF NOT EXISTS `controlid_user_id` BIGINT DEFAULT NULL
    COMMENT 'ID do usuário criado no equipamento Control ID',
  ADD COLUMN IF NOT EXISTS `controlid_tag_id` BIGINT DEFAULT NULL
    COMMENT 'ID da tag UHF criada no equipamento Control ID',
  ADD COLUMN IF NOT EXISTS `controlid_sincronizado` TINYINT(1) DEFAULT 0
    COMMENT '1=TAG sincronizada com Control ID',
  ADD COLUMN IF NOT EXISTS `controlid_sync_data` DATETIME DEFAULT NULL
    COMMENT 'Data da última sincronização com Control ID';
