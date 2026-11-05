-- ERP CONDOMÍNIOS — CHAT-IN (comunicação interna) + CANAIS DE NOTIFICAÇÃO + EVENTOS
-- Compatível com MySQL/MariaDB 5.7. Idempotente (CREATE TABLE IF NOT EXISTS).
-- Todas as tabelas são isoladas por tenant_id e os índices começam por tenant_id.
--
-- Participantes são identificados por (tipo, id):
--   USUARIO  = usuarios.id (painel)      MORADOR = moradores.id (portal/app)
--   SISTEMA  = remetente automático (Portaria / Administração), id 0
-- Dados expostos no chat: somente nome + unidade (morador) ou nome + função (usuário).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `chatin_conversas` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `tipo` ENUM('DIRETA','AVISOS') NOT NULL DEFAULT 'DIRETA',
  `chave_unica` VARCHAR(80) NOT NULL COMMENT 'DIRETA: participantes ordenados (ex.: M12|U3); AVISOS: AVISOS|M12',
  `ultima_mensagem_id` BIGINT UNSIGNED DEFAULT NULL,
  `ultima_mensagem_em` DATETIME DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chatin_conversa_chave` (`tenant_id`, `chave_unica`),
  KEY `idx_chatin_conversa_tenant_ult` (`tenant_id`, `ultima_mensagem_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatin_participantes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `conversa_id` BIGINT UNSIGNED NOT NULL,
  `participante_tipo` ENUM('USUARIO','MORADOR','SISTEMA') NOT NULL,
  `participante_id` INT(11) NOT NULL,
  `ultima_leitura_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `silenciado` TINYINT(1) NOT NULL DEFAULT 0,
  `arquivado` TINYINT(1) NOT NULL DEFAULT 0,
  `entrou_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chatin_part` (`conversa_id`, `participante_tipo`, `participante_id`),
  KEY `idx_chatin_part_pessoa` (`tenant_id`, `participante_tipo`, `participante_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatin_mensagens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `conversa_id` BIGINT UNSIGNED NOT NULL,
  `remetente_tipo` ENUM('USUARIO','MORADOR','SISTEMA') NOT NULL,
  `remetente_id` INT(11) NOT NULL DEFAULT 0,
  `remetente_nome` VARCHAR(150) NOT NULL COMMENT 'Instantâneo do nome exibido no envio',
  `tipo` ENUM('TEXTO','AVISO') NOT NULL DEFAULT 'TEXTO',
  `corpo` TEXT NOT NULL,
  `titulo` VARCHAR(150) DEFAULT NULL COMMENT 'Avisos: título do aviso',
  `referencia_tipo` VARCHAR(30) DEFAULT NULL COMMENT 'acesso, documento, assembleia, evento',
  `referencia_id` INT(11) DEFAULT NULL,
  `idempotency_key` VARCHAR(40) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chatin_msg_conversa` (`tenant_id`, `conversa_id`, `id`),
  KEY `idx_chatin_msg_remetente` (`tenant_id`, `remetente_tipo`, `remetente_id`, `criado_em`),
  UNIQUE KEY `uk_chatin_msg_idem` (`tenant_id`, `remetente_tipo`, `remetente_id`, `idempotency_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatin_presenca` (
  `tenant_id` INT(11) NOT NULL,
  `participante_tipo` ENUM('USUARIO','MORADOR') NOT NULL,
  `participante_id` INT(11) NOT NULL,
  `ultimo_ping` DATETIME NOT NULL,
  `plataforma` VARCHAR(20) NOT NULL DEFAULT 'web' COMMENT 'painel, portal, app',
  `conversa_aberta_id` BIGINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`tenant_id`, `participante_tipo`, `participante_id`),
  KEY `idx_chatin_presenca_ping` (`tenant_id`, `ultimo_ping`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatin_preferencias` (
  `tenant_id` INT(11) NOT NULL,
  `participante_tipo` ENUM('USUARIO','MORADOR') NOT NULL,
  `participante_id` INT(11) NOT NULL,
  `visivel_busca` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = não aparece na busca de outros moradores',
  `mostrar_online` TINYINT(1) NOT NULL DEFAULT 1,
  `notificar_push` TINYINT(1) NOT NULL DEFAULT 1,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `participante_tipo`, `participante_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chatin_bloqueios` (
  `tenant_id` INT(11) NOT NULL,
  `bloqueador_tipo` ENUM('USUARIO','MORADOR') NOT NULL,
  `bloqueador_id` INT(11) NOT NULL,
  `bloqueado_tipo` ENUM('USUARIO','MORADOR') NOT NULL,
  `bloqueado_id` INT(11) NOT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `bloqueador_tipo`, `bloqueador_id`, `bloqueado_tipo`, `bloqueado_id`),
  KEY `idx_chatin_bloqueado` (`tenant_id`, `bloqueado_tipo`, `bloqueado_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Canais de notificação por evento do sistema (Sistema > Controle de Acesso).
-- canal: EMAIL, APP, CHATIN hoje; WHATSAPP e SMS reservados para integração futura
-- (config_json guarda credenciais/modelos do provedor quando existir).
CREATE TABLE IF NOT EXISTS `notificacao_canais_config` (
  `tenant_id` INT(11) NOT NULL,
  `evento` VARCHAR(60) NOT NULL COMMENT 'ex.: acesso.visitante_chegou',
  `canal` VARCHAR(20) NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 0,
  `config_json` TEXT DEFAULT NULL,
  `atualizado_por` INT(11) DEFAULT NULL,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `evento`, `canal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eventos do condomínio (Administrativo > Eventos).
CREATE TABLE IF NOT EXISTS `eventos_condominio` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `titulo` VARCHAR(150) NOT NULL,
  `descricao` TEXT DEFAULT NULL,
  `local` VARCHAR(150) DEFAULT NULL,
  `data_inicio` DATETIME NOT NULL,
  `data_fim` DATETIME DEFAULT NULL,
  `publico` ENUM('TODOS','UNIDADES') NOT NULL DEFAULT 'TODOS',
  `unidades_json` TEXT DEFAULT NULL COMMENT 'Lista de unidades (moradores.unidade) quando publico = UNIDADES',
  `status` ENUM('AGENDADO','CANCELADO','REALIZADO') NOT NULL DEFAULT 'AGENDADO',
  `notificado_chatin_em` DATETIME DEFAULT NULL,
  `notificados_total` INT(11) NOT NULL DEFAULT 0,
  `criado_por` INT(11) DEFAULT NULL,
  `criado_por_nome` VARCHAR(150) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eventos_tenant_data` (`tenant_id`, `data_inicio`),
  KEY `idx_eventos_tenant_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
