-- ============================================================================
-- ERP CONDOMÍNIOS — Alertas da Unidade — MySQL 5.7
--
-- Migration incremental e idempotente. Não altera nenhuma tabela alertas_acesso_*.
-- A API do módulo é a única responsável por gravar/alterar os dados de domínio;
-- ciências e históricos são append-only na API.
-- ============================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- MySQL 5.7 não aceita ADD COLUMN IF NOT EXISTS. Estes procedimentos usam
-- INFORMATION_SCHEMA e PREPARE para que a migration possa ser reaplicada.
-- Comparações binárias evitam conflito entre a collation do schema e a metadata.
DROP PROCEDURE IF EXISTS au_add_column_if_missing;
DELIMITER $$
CREATE PROCEDURE au_add_column_if_missing(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  DECLARE v_table INT DEFAULT 0;
  DECLARE v_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO v_table
    FROM INFORMATION_SCHEMA.TABLES
   WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY p_table;
  SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.COLUMNS
   WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY p_table AND BINARY COLUMN_NAME = BINARY p_column;
  IF v_table > 0 AND v_exists = 0 THEN
    SET @au_sql = CONCAT('ALTER TABLE `', REPLACE(p_table,'`',''), '` ADD COLUMN `', REPLACE(p_column,'`',''), '` ', p_definition);
    PREPARE au_stmt FROM @au_sql;
    EXECUTE au_stmt;
    DEALLOCATE PREPARE au_stmt;
  END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS au_add_index_if_missing;
DELIMITER $$
CREATE PROCEDURE au_add_index_if_missing(IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_definition TEXT)
BEGIN
  DECLARE v_table INT DEFAULT 0;
  DECLARE v_exists INT DEFAULT 0;
  SELECT COUNT(*) INTO v_table
    FROM INFORMATION_SCHEMA.TABLES
   WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY p_table;
  SELECT COUNT(*) INTO v_exists
    FROM INFORMATION_SCHEMA.STATISTICS
   WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY p_table AND BINARY INDEX_NAME = BINARY p_index;
  IF v_table > 0 AND v_exists = 0 THEN
    SET @au_sql = CONCAT('ALTER TABLE `', REPLACE(p_table,'`',''), '` ADD ', p_definition);
    PREPARE au_stmt FROM @au_sql;
    EXECUTE au_stmt;
    DEALLOCATE PREPARE au_stmt;
  END IF;
END$$
DELIMITER ;

-- A sessão de portal precisa carregar o tenant que será conferido contra o
-- morador ativo. Bases já multitenant possuem esta coluna; bases antigas a
-- recebem de forma segura nesta migration.
CALL au_add_column_if_missing('sessoes_portal', 'tenant_id', 'INT(11) NULL AFTER id');
CALL au_add_index_if_missing('sessoes_portal', 'idx_sessoes_portal_tenant_token', 'KEY `idx_sessoes_portal_tenant_token` (`tenant_id`,`token`)');
UPDATE sessoes_portal sp
INNER JOIN moradores m ON m.id = sp.morador_id
SET sp.tenant_id = m.tenant_id
WHERE sp.tenant_id IS NULL AND m.tenant_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS `alertas_unidade` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `unidade` VARCHAR(50) NOT NULL,
  `titular_tipo` ENUM('MORADOR','DEPENDENTE') NOT NULL DEFAULT 'MORADOR',
  `morador_id` INT(11) NOT NULL,
  `dependente_id` INT(11) DEFAULT NULL,
  `titulo` VARCHAR(120) NOT NULL,
  `mensagem` VARCHAR(1000) NOT NULL,
  `nivel` ENUM('INFORMATIVO','ATENCAO','CRITICO') NOT NULL,
  `acao` ENUM('APENAS_AVISAR','EXIGIR_CIENTE','CONTATAR_MORADOR','BLOQUEAR') NOT NULL,
  `publico` ENUM('TODOS','VISITANTE','PRESTADOR','ENTREGADOR_AUTORIZADO','FAMILIAR','VEICULO') NOT NULL,
  `abrangencia` ENUM('TODOS_DO_TIPO','ESPECIFICO') NOT NULL DEFAULT 'TODOS_DO_TIPO',
  `vigencia_inicio` DATETIME NOT NULL,
  `vigencia_fim` DATETIME DEFAULT NULL,
  `dias_semana` VARCHAR(20) DEFAULT NULL,
  `hora_inicio` TIME DEFAULT NULL,
  `hora_fim` TIME DEFAULT NULL,
  `instrucao_operador` VARCHAR(500) DEFAULT NULL,
  `telefone_contato` VARCHAR(30) DEFAULT NULL,
  `observacao` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('ATIVO','PAUSADO','EXPIRADO','CANCELADO') NOT NULL DEFAULT 'ATIVO',
  `origem` ENUM('SISTEMA','PORTAL','APP') NOT NULL,
  `criado_por_tipo` ENUM('USUARIO','MORADOR','DEPENDENTE') NOT NULL,
  `criado_por_usuario_id` INT(11) DEFAULT NULL,
  `criado_por_morador_id` INT(11) DEFAULT NULL,
  `criado_por_dependente_id` INT(11) DEFAULT NULL,
  `cancelado_por_tipo` ENUM('USUARIO','MORADOR','DEPENDENTE') DEFAULT NULL,
  `cancelado_por_id` INT(11) DEFAULT NULL,
  `cancelado_em` DATETIME DEFAULT NULL,
  `motivo_cancelamento` VARCHAR(500) DEFAULT NULL,
  `total_acionamentos` INT(11) NOT NULL DEFAULT 0,
  `ultimo_acionamento_em` DATETIME DEFAULT NULL,
  `idempotency_key` VARCHAR(64) DEFAULT NULL,
  `idempotency_payload_hash` CHAR(64) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_au_tenant_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_au_tenant_unidade_status` (`tenant_id`,`unidade`,`status`),
  KEY `idx_au_tenant_status_vigencia` (`tenant_id`,`status`,`vigencia_inicio`,`vigencia_fim`),
  KEY `idx_au_tenant_morador` (`tenant_id`,`morador_id`),
  KEY `idx_au_tenant_origem` (`tenant_id`,`origem`,`criado_em`),
  KEY `idx_au_tenant_id` (`tenant_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_unidade_alvos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `alerta_id` BIGINT UNSIGNED NOT NULL,
  `tipo_alvo` ENUM('VISITANTE','DOCUMENTO','PLACA') NOT NULL,
  `visitante_id` INT(11) DEFAULT NULL,
  `tipo_documento` ENUM('CPF','RG','OUTRO') DEFAULT NULL,
  `documento_normalizado` VARCHAR(20) DEFAULT NULL,
  `placa_normalizada` VARCHAR(10) DEFAULT NULL,
  `nome_referencia` VARCHAR(150) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_aualvo_tenant_alerta` (`tenant_id`,`alerta_id`),
  KEY `idx_aualvo_tenant_documento` (`tenant_id`,`documento_normalizado`),
  KEY `idx_aualvo_tenant_placa` (`tenant_id`,`placa_normalizada`),
  KEY `idx_aualvo_tenant_visitante` (`tenant_id`,`visitante_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_unidade_acionamentos` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `alerta_id` BIGINT UNSIGNED NOT NULL,
  `registro_acesso_id` INT(11) DEFAULT NULL,
  `contexto` ENUM('REGISTRO_MANUAL','CONTROLE_ACESSO','RFID','CONVIDADOS','LPR','PORTAL') NOT NULL,
  `decisao` ENUM('CIENTE_LIBERADO','CIENTE_NAO_LIBERADO','CONTATO_MORADOR_AUTORIZOU','CONTATO_MORADOR_NEGOU','OVERRIDE_SUPERVISOR') NOT NULL,
  `usuario_id` INT(11) DEFAULT NULL,
  `usuario_nome` VARCHAR(150) DEFAULT NULL,
  `pessoa_nome` VARCHAR(200) DEFAULT NULL,
  `pessoa_documento_mascarado` VARCHAR(30) DEFAULT NULL,
  `tipo_pessoa` VARCHAR(50) DEFAULT NULL,
  `placa` VARCHAR(10) DEFAULT NULL,
  `divergencia_unidade` TINYINT(1) NOT NULL DEFAULT 0,
  `snapshot_json` MEDIUMTEXT NOT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_auac_tenant_alerta_data` (`tenant_id`,`alerta_id`,`criado_em`),
  KEY `idx_auac_tenant_registro` (`tenant_id`,`registro_acesso_id`),
  KEY `idx_auac_tenant_usuario_data` (`tenant_id`,`usuario_id`,`criado_em`),
  KEY `idx_auac_tenant_data` (`tenant_id`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `alertas_unidade_acionamentos` MODIFY COLUMN `decisao` ENUM('CIENTE_LIBERADO','CIENTE_NAO_LIBERADO','CONTATO_MORADOR_AUTORIZOU','CONTATO_MORADOR_NEGOU','OVERRIDE_SUPERVISOR','APENAS_AVISAR','CIENCIA_PENDENTE','BLOQUEADO_AUTOMATICO') NOT NULL;

CREATE TABLE IF NOT EXISTS `alertas_unidade_ciencia_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `usuario_id` INT(11) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `contexto_hash` CHAR(64) NOT NULL,
  `alertas_hash` CHAR(64) NOT NULL,
  `contexto_snapshot_json` MEDIUMTEXT NOT NULL,
  `decisoes_json` MEDIUMTEXT NOT NULL,
  `alertas_snapshot_json` MEDIUMTEXT NOT NULL,
  `expira_em` DATETIME NOT NULL,
  `usado` TINYINT(1) NOT NULL DEFAULT 0,
  `usado_em` DATETIME DEFAULT NULL,
  `registro_acesso_id` INT(11) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_autok_token_hash` (`token_hash`),
  KEY `idx_autok_tenant_usuario_expira` (`tenant_id`,`usuario_id`,`expira_em`),
  KEY `idx_autok_tenant_usado` (`tenant_id`,`usado`,`expira_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_unidade_ciencias` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `alerta_id` BIGINT UNSIGNED NOT NULL,
  `token_id` BIGINT UNSIGNED DEFAULT NULL,
  `contexto` ENUM('REGISTRO_MANUAL','CONTROLE_ACESSO','RFID','CONVIDADOS','LPR','PORTAL') NOT NULL,
  `registro_acesso_id` INT(11) DEFAULT NULL,
  `decisao` ENUM('CIENTE_LIBERADO','CIENTE_NAO_LIBERADO','CONTATO_MORADOR_AUTORIZOU','CONTATO_MORADOR_NEGOU','OVERRIDE_SUPERVISOR') NOT NULL,
  `justificativa` VARCHAR(500) DEFAULT NULL,
  `usuario_id` INT(11) NOT NULL,
  `usuario_nome` VARCHAR(150) DEFAULT NULL,
  `supervisor_usuario_id` INT(11) DEFAULT NULL,
  `pessoa_nome` VARCHAR(200) DEFAULT NULL,
  `pessoa_documento_mascarado` VARCHAR(30) DEFAULT NULL,
  `tipo_pessoa` VARCHAR(50) DEFAULT NULL,
  `placa` VARCHAR(10) DEFAULT NULL,
  `divergencia_unidade` TINYINT(1) NOT NULL DEFAULT 0,
  `snapshot_json` MEDIUMTEXT NOT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_auc_tenant_alerta_data` (`tenant_id`,`alerta_id`,`criado_em`),
  KEY `idx_auc_tenant_usuario_data` (`tenant_id`,`usuario_id`,`criado_em`),
  KEY `idx_auc_tenant_registro` (`tenant_id`,`registro_acesso_id`),
  KEY `idx_auc_tenant_data` (`tenant_id`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_unidade_historico` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `alerta_id` BIGINT UNSIGNED NOT NULL,
  `operacao` ENUM('CRIAR','EDITAR','PAUSAR','RETOMAR','CANCELAR','EXPIRAR') NOT NULL,
  `usuario_id` INT(11) DEFAULT NULL,
  `usuario_nome` VARCHAR(150) DEFAULT NULL,
  `origem` ENUM('SISTEMA','PORTAL','APP') NOT NULL,
  `dados_antes_json` MEDIUMTEXT DEFAULT NULL,
  `dados_depois_json` MEDIUMTEXT DEFAULT NULL,
  `motivo` VARCHAR(500) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_auh_tenant_alerta_data` (`tenant_id`,`alerta_id`,`criado_em`),
  KEY `idx_auh_tenant_usuario_data` (`tenant_id`,`usuario_id`,`criado_em`),
  KEY `idx_auh_tenant_data` (`tenant_id`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_unidade_rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `usuario_id` INT(11) NOT NULL,
  `unidade` VARCHAR(50) NOT NULL,
  `bucket_inicio` DATETIME NOT NULL,
  `contador` INT(11) NOT NULL DEFAULT 0,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_aurl_bucket` (`tenant_id`,`usuario_id`,`unidade`,`bucket_inicio`),
  KEY `idx_aurl_tenant_unidade_bucket` (`tenant_id`,`unidade`,`bucket_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_unidade_notificacoes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `alerta_id` BIGINT UNSIGNED NOT NULL,
  `registro_acesso_id` INT(11) DEFAULT NULL,
  `morador_id` INT(11) NOT NULL,
  `notificacao_morador_id` INT(11) UNSIGNED DEFAULT NULL,
  `janela_inicio` DATETIME NOT NULL,
  `tipo` VARCHAR(80) NOT NULL DEFAULT 'alerta_unidade_acionado',
  `titulo` VARCHAR(160) NOT NULL,
  `mensagem` VARCHAR(1000) NOT NULL,
  `push_status` VARCHAR(40) NOT NULL DEFAULT 'pendente',
  `push_detalhe` VARCHAR(255) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_aun_dedupe` (`tenant_id`,`alerta_id`,`morador_id`,`janela_inicio`),
  KEY `idx_aun_tenant_morador_data` (`tenant_id`,`morador_id`,`criado_em`),
  KEY `idx_aun_tenant_notificacao` (`tenant_id`,`notificacao_morador_id`),
  KEY `idx_aun_tenant_alerta_data` (`tenant_id`,`alerta_id`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_unidade_lembretes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT(11) NOT NULL,
  `alerta_id` BIGINT UNSIGNED NOT NULL,
  `morador_id` INT(11) NOT NULL,
  `tipo` ENUM('EXPIRACAO_24H') NOT NULL DEFAULT 'EXPIRACAO_24H',
  `vigencia_fim` DATETIME NOT NULL,
  `notificacao_morador_id` INT(11) UNSIGNED DEFAULT NULL,
  `status` ENUM('PENDENTE','CRIADO','SEM_CANAL') NOT NULL DEFAULT 'PENDENTE',
  `push_status` VARCHAR(40) NOT NULL DEFAULT 'pendente',
  `push_detalhe` VARCHAR(255) DEFAULT NULL,
  `criado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processado_em` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_aul_expiracao` (`tenant_id`,`alerta_id`,`tipo`,`vigencia_fim`),
  KEY `idx_aul_tenant_status_fim` (`tenant_id`,`status`,`vigencia_fim`),
  KEY `idx_aul_tenant_morador_data` (`tenant_id`,`morador_id`,`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_unidade_preferencias` (
  `tenant_id` INT(11) NOT NULL,
  `morador_id` INT(11) NOT NULL,
  `push_ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`,`morador_id`),
  KEY `idx_aup_tenant_push` (`tenant_id`,`push_ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `alertas_unidade_cache_ids` (
  `tenant_id` INT(11) NOT NULL,
  `unidade` VARCHAR(50) NOT NULL,
  `ids_json` MEDIUMTEXT NOT NULL,
  `atualizado_em` DATETIME NOT NULL,
  PRIMARY KEY (`tenant_id`,`unidade`),
  KEY `idx_auci_atualizado` (`tenant_id`,`atualizado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vincula ao inbox já consumido pelo Portal sem alterar eventos anteriores.
CALL au_add_column_if_missing('alertas_unidade_notificacoes', 'notificacao_morador_id', 'INT(11) UNSIGNED NULL AFTER morador_id');
CALL au_add_index_if_missing('alertas_unidade_notificacoes', 'idx_aun_tenant_notificacao', 'KEY `idx_aun_tenant_notificacao` (`tenant_id`,`notificacao_morador_id`)');
CALL au_add_column_if_missing('notificacoes_morador', 'alerta_unidade_id', 'BIGINT(20) UNSIGNED NULL AFTER protocolo_id');
CALL au_add_column_if_missing('notificacoes_morador', 'registro_acesso_id', 'INT(11) NULL AFTER alerta_unidade_id');

-- Garante novamente os índices essenciais quando uma instalação anterior criou
-- alguma tabela sem o catálogo completo de índices.
CALL au_add_index_if_missing('alertas_unidade', 'idx_au_tenant_unidade_status', 'KEY `idx_au_tenant_unidade_status` (`tenant_id`,`unidade`,`status`)');
CALL au_add_index_if_missing('alertas_unidade', 'idx_au_tenant_status_vigencia', 'KEY `idx_au_tenant_status_vigencia` (`tenant_id`,`status`,`vigencia_inicio`,`vigencia_fim`)');
CALL au_add_index_if_missing('alertas_unidade', 'idx_au_tenant_status_fim', 'KEY `idx_au_tenant_status_fim` (`tenant_id`,`status`,`vigencia_fim`)');
CALL au_add_index_if_missing('alertas_unidade_alvos', 'idx_aualvo_tenant_documento', 'KEY `idx_aualvo_tenant_documento` (`tenant_id`,`documento_normalizado`)');
CALL au_add_index_if_missing('alertas_unidade_alvos', 'idx_aualvo_tenant_placa', 'KEY `idx_aualvo_tenant_placa` (`tenant_id`,`placa_normalizada`)');
CALL au_add_index_if_missing('alertas_unidade_ciencias', 'idx_auc_tenant_data', 'KEY `idx_auc_tenant_data` (`tenant_id`,`criado_em`)');
CALL au_add_index_if_missing('alertas_unidade_historico', 'idx_auh_tenant_data', 'KEY `idx_auh_tenant_data` (`tenant_id`,`criado_em`)');

-- RBAC: gestão sob Moradores; ciência/validação sob Registro; override por
-- `moradores.aprovar`. Não cria um submódulo adicional nem concede acesso
-- implícito aos usuários do painel.
DROP PROCEDURE IF EXISTS au_seed_rbac;
DELIMITER $$
CREATE PROCEDURE au_seed_rbac()
BEGIN
  DECLARE v_parent INT DEFAULT 0;
  DECLARE v_rbac_completo INT DEFAULT 0;
  SELECT COUNT(*) INTO v_rbac_completo
    FROM INFORMATION_SCHEMA.TABLES
   WHERE BINARY TABLE_SCHEMA=BINARY DATABASE()
     AND BINARY TABLE_NAME IN ('rbac_modulos','rbac_permissoes','rbac_politicas_api');
  IF v_rbac_completo = 3 THEN
    SELECT id INTO v_parent FROM rbac_modulos WHERE chave='moradores' LIMIT 1;
    IF v_parent IS NOT NULL AND v_parent > 0 THEN
      INSERT IGNORE INTO rbac_permissoes (modulo_id,acao,nome,descricao)
      SELECT id,'exportar','Exportar','Exportar ciências de alertas da unidade' FROM rbac_modulos WHERE chave='moradores';
      INSERT IGNORE INTO rbac_permissoes (modulo_id,acao,nome,descricao)
      SELECT id,'aprovar','Aprovar','Autorizar override de bloqueio crítico em alertas da unidade' FROM rbac_modulos WHERE chave='moradores';
      INSERT IGNORE INTO rbac_politicas_api (endpoint,acao_requisicao,modulo_chave,acao_permissao,metodo_http,ativo) VALUES
        ('api_alertas_unidade.php','listar','moradores','visualizar','GET',1),
        ('api_alertas_unidade.php','kpis','moradores','visualizar','GET',1),
        ('api_alertas_unidade.php','opcoes','moradores','visualizar','GET',1),
        ('api_alertas_unidade.php','detalhe','moradores','visualizar','GET',1),
        ('api_alertas_unidade.php','buscar_alvo','moradores','visualizar','GET',1),
        ('api_alertas_unidade.php','ciencias','moradores','visualizar','GET',1),
        ('api_alertas_unidade.php','exportar','moradores','exportar','GET',1),
        ('api_alertas_unidade.php','salvar','moradores','criar','POST',1),
        ('api_alertas_unidade.php','alterar_status','moradores','editar','POST',1),
        ('api_alertas_unidade.php','verificar','registro','criar','POST',1),
        ('api_alertas_unidade.php','registrar_ciencia','registro','criar','POST',1)
      ON DUPLICATE KEY UPDATE modulo_chave=VALUES(modulo_chave),acao_permissao=VALUES(acao_permissao),ativo=1;
    END IF;
  END IF;
END$$
DELIMITER ;
CALL au_seed_rbac();
DROP PROCEDURE IF EXISTS au_seed_rbac;

-- Gerente é o grupo de compatibilidade equivalente a supervisor: recebe
-- `moradores.aprovar`, mas o grupo operador nunca recebe essa ação.
DROP PROCEDURE IF EXISTS au_seed_rbac_compat;
DELIMITER $$
CREATE PROCEDURE au_seed_rbac_compat()
BEGIN
  DECLARE v_count INT DEFAULT 0;
  SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.TABLES
   WHERE BINARY TABLE_SCHEMA=BINARY DATABASE() AND BINARY TABLE_NAME IN ('rbac_grupos','rbac_grupo_permissoes','rbac_permissoes','rbac_modulos');
  IF v_count = 4 THEN
    INSERT IGNORE INTO rbac_grupo_permissoes (grupo_id,permissao_id,efeito,escopo_dados)
    SELECT g.id,p.id,'PERMITIR','GLOBAL'
      FROM rbac_grupos g INNER JOIN rbac_permissoes p INNER JOIN rbac_modulos m ON m.id=p.modulo_id
     WHERE g.slug='compat-admin' AND m.chave='moradores' AND p.acao='aprovar';
    INSERT IGNORE INTO rbac_grupo_permissoes (grupo_id,permissao_id,efeito,escopo_dados)
    SELECT g.id,p.id,'PERMITIR','GLOBAL'
      FROM rbac_grupos g INNER JOIN rbac_permissoes p INNER JOIN rbac_modulos m ON m.id=p.modulo_id
     WHERE g.slug='compat-gerente' AND m.chave='moradores' AND p.acao='aprovar';
  END IF;
END$$
DELIMITER ;
CALL au_seed_rbac_compat();
DROP PROCEDURE IF EXISTS au_seed_rbac_compat;

-- Configuração de push independente da preferência de encomendas/controle de acesso.
DROP PROCEDURE IF EXISTS au_seed_push_alertas;
DELIMITER $$
CREATE PROCEDURE au_seed_push_alertas()
BEGIN
  DECLARE v_pwa INT DEFAULT 0;
  SELECT COUNT(*) INTO v_pwa FROM INFORMATION_SCHEMA.TABLES
   WHERE BINARY TABLE_SCHEMA=BINARY DATABASE() AND BINARY TABLE_NAME=BINARY 'pwa_configuracoes';
  IF v_pwa=1 THEN
    INSERT INTO pwa_configuracoes (tenant_id,chave,valor,descricao)
    SELECT DISTINCT tenant_id,'push_alertas_unidade_ativo','1','Enviar notificações push de Alertas da Unidade'
      FROM moradores WHERE tenant_id IS NOT NULL
    ON DUPLICATE KEY UPDATE chave=VALUES(chave),descricao=VALUES(descricao);
  END IF;
END$$
DELIMITER ;
CALL au_seed_push_alertas();
DROP PROCEDURE IF EXISTS au_seed_push_alertas;

DROP PROCEDURE IF EXISTS au_add_column_if_missing;
DROP PROCEDURE IF EXISTS au_add_index_if_missing;
SET FOREIGN_KEY_CHECKS = 1;
-- FIM DA MIGRATION
