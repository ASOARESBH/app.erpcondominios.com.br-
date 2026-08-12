-- ============================================================================
-- RECONSTRUÇÃO CONTROLADA DO TENANT 1 COM A ORIGEM ERP SERRA
-- Compatível com MySQL/MariaDB 5.7
-- Esta versão remove dados duplicados no ERP Condor e preserva o ERP Serra
-- como fonte única dos dados de negócio.
--
-- ATENÇÃO: execute apenas após exportar um backup novo de inlaud99_erpcondor.
-- Os dois bancos devem existir no mesmo servidor MySQL.
-- ============================================================================

SET NAMES utf8mb4;
SET @CONFIRMAR_RECONSTRUCAO = 'SIM'; -- mantenha SIM somente após exportar o backup atual.
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;
SET SESSION group_concat_max_len = 1048576;
ROLLBACK;

DROP PROCEDURE IF EXISTS `inlaud99_erpcondor`.`mt_validar_reconstrucao`;
DELIMITER $$
CREATE PROCEDURE `inlaud99_erpcondor`.`mt_validar_reconstrucao`()
BEGIN
  DECLARE v_origem INT DEFAULT 0;
  DECLARE v_destino INT DEFAULT 0;
  DECLARE v_tenant INT DEFAULT 0;
  DECLARE v_outros INT DEFAULT 0;
  SELECT COUNT(*) INTO v_origem FROM INFORMATION_SCHEMA.SCHEMATA WHERE BINARY SCHEMA_NAME=BINARY 'inlaud99_erpserra';
  SELECT COUNT(*) INTO v_destino FROM INFORMATION_SCHEMA.SCHEMATA WHERE BINARY SCHEMA_NAME=BINARY 'inlaud99_erpcondor';
  SELECT COUNT(*) INTO v_tenant FROM `inlaud99_erpcondor`.`tenants` WHERE id=1;
  SELECT COUNT(*) INTO v_outros FROM `inlaud99_erpcondor`.`tenants` WHERE id<>1;
  IF @CONFIRMAR_RECONSTRUCAO <> 'SIM' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reconstrucao nao confirmada; gere backup e defina SIM'; END IF;
  IF v_origem=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Banco origem inlaud99_erpserra nao encontrado'; END IF;
  IF v_destino=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Banco destino inlaud99_erpcondor nao encontrado'; END IF;
  IF v_tenant=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tenant 1 nao encontrado no destino'; END IF;
  IF v_outros>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Existem outros tenants; reconstrucao bloqueada para protege-los'; END IF;
END$$
DELIMITER ;
CALL `inlaud99_erpcondor`.`mt_validar_reconstrucao`();
DROP PROCEDURE `inlaud99_erpcondor`.`mt_validar_reconstrucao`;
USE `inlaud99_erpcondor`;

CREATE TABLE IF NOT EXISTS `mt_reconstrucao_tabelas` (
  id INT NOT NULL AUTO_INCREMENT,
  tabela VARCHAR(128) NOT NULL,
  tenant_id INT NOT NULL,
  registros_origem BIGINT NOT NULL DEFAULT 0,
  registros_removidos BIGINT NOT NULL DEFAULT 0,
  registros_importados BIGINT NOT NULL DEFAULT 0,
  status ENUM('concluida','ignorada','erro') NOT NULL,
  mensagem TEXT NULL,
  executado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uk_mt_reconstrucao(tabela,tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mt_reconstrucao_exclusoes` (
  id INT NOT NULL AUTO_INCREMENT,
  tabela VARCHAR(128) NOT NULL,
  motivo VARCHAR(500) NOT NULL,
  registrado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uk_mt_reconstrucao_exclusao(tabela)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('bancos_brasileiros','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('bridge_fila_comandos','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('config_sessao','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('controlid_fila_comandos','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('controlid_push_queue','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('modulos_sistema','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('publico_rate_limit','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('pwa_fcm_tokens','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('pwa_oauth_cache','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('qrcode_tokens','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('qrcodes_temporarios','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('recuperacao_senha_tokens','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('senha_recuperacao_logs','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('sessoes_portal','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('sessoes_usuarios','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');
INSERT IGNORE INTO mt_reconstrucao_exclusoes (tabela,motivo) VALUES ('view_dispositivos_ativos','Infraestrutura, token, cache, sessão, fila ou referência global preservada no destino.');

DROP PROCEDURE IF EXISTS `mt_limpar_tabela`;
DELIMITER $$
CREATE PROCEDURE `mt_limpar_tabela`(IN p_tabela VARCHAR(128))
proc: BEGIN
  DECLARE v_tenant INT DEFAULT 0;
  DECLARE v_antes BIGINT DEFAULT 0;
  SELECT COUNT(*) INTO v_tenant FROM INFORMATION_SCHEMA.COLUMNS
   WHERE BINARY TABLE_SCHEMA=BINARY 'inlaud99_erpcondor' AND BINARY TABLE_NAME=BINARY p_tabela AND BINARY COLUMN_NAME=BINARY 'tenant_id';
  SET @mt_count=CONCAT('SELECT COUNT(*) INTO @mt_qtd FROM `inlaud99_erpcondor`.`',p_tabela,'`');
  PREPARE mt_stmt FROM @mt_count; EXECUTE mt_stmt; DEALLOCATE PREPARE mt_stmt;
  SET v_antes=COALESCE(@mt_qtd,0);
  IF v_tenant>0 THEN
    SET @mt_delete=CONCAT('DELETE FROM `inlaud99_erpcondor`.`',p_tabela,'` WHERE tenant_id=',1);
  ELSE
    SET @mt_delete=CONCAT('DELETE FROM `inlaud99_erpcondor`.`',p_tabela,'`');
  END IF;
  PREPARE mt_stmt FROM @mt_delete; EXECUTE mt_stmt; DEALLOCATE PREPARE mt_stmt;
  INSERT INTO mt_reconstrucao_tabelas(tabela,tenant_id,registros_removidos,status,mensagem)
  VALUES(p_tabela,1,v_antes,'ignorada','Limpeza concluida; aguardando importacao da origem')
  ON DUPLICATE KEY UPDATE registros_removidos=VALUES(registros_removidos),status=VALUES(status),mensagem=VALUES(mensagem),executado_em=NOW();
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `mt_importar_origem`;
DELIMITER $$
CREATE PROCEDURE `mt_importar_origem`(IN p_tabela VARCHAR(128))
proc: BEGIN
  DECLARE v_comp INT DEFAULT 0;
  DECLARE v_tenant INT DEFAULT 0;
  DECLARE v_origem BIGINT DEFAULT 0;
  DECLARE v_importados BIGINT DEFAULT 0;
  SELECT COUNT(*) INTO v_comp FROM INFORMATION_SCHEMA.COLUMNS s
   INNER JOIN INFORMATION_SCHEMA.COLUMNS d
     ON BINARY d.TABLE_SCHEMA=BINARY 'inlaud99_erpcondor' AND BINARY d.TABLE_NAME=BINARY p_tabela AND BINARY d.COLUMN_NAME=BINARY s.COLUMN_NAME
   WHERE BINARY s.TABLE_SCHEMA=BINARY 'inlaud99_erpserra' AND BINARY s.TABLE_NAME=BINARY p_tabela;
  IF v_comp=0 THEN
    INSERT INTO mt_reconstrucao_tabelas(tabela,tenant_id,status,mensagem) VALUES(p_tabela,1,'ignorada','Sem colunas compatíveis')
    ON DUPLICATE KEY UPDATE status=VALUES(status),mensagem=VALUES(mensagem),executado_em=NOW();
    LEAVE proc;
  END IF;
  SELECT COUNT(*) INTO v_tenant FROM INFORMATION_SCHEMA.COLUMNS
   WHERE BINARY TABLE_SCHEMA=BINARY 'inlaud99_erpcondor' AND BINARY TABLE_NAME=BINARY p_tabela AND BINARY COLUMN_NAME=BINARY 'tenant_id';
  SELECT GROUP_CONCAT(CONCAT('`',d.COLUMN_NAME,'`') ORDER BY d.ORDINAL_POSITION SEPARATOR ',') INTO @mt_cols
   FROM INFORMATION_SCHEMA.COLUMNS d INNER JOIN INFORMATION_SCHEMA.COLUMNS s
    ON BINARY s.TABLE_SCHEMA=BINARY 'inlaud99_erpserra' AND BINARY s.TABLE_NAME=BINARY p_tabela AND BINARY s.COLUMN_NAME=BINARY d.COLUMN_NAME
   WHERE BINARY d.TABLE_SCHEMA=BINARY 'inlaud99_erpcondor' AND BINARY d.TABLE_NAME=BINARY p_tabela
     AND BINARY d.COLUMN_NAME<>BINARY 'tenant_id' AND UPPER(IFNULL(d.EXTRA,'')) NOT LIKE '%GENERATED%';
  SELECT GROUP_CONCAT(CONCAT('s.`',d.COLUMN_NAME,'`') ORDER BY d.ORDINAL_POSITION SEPARATOR ',') INTO @mt_vals
   FROM INFORMATION_SCHEMA.COLUMNS d INNER JOIN INFORMATION_SCHEMA.COLUMNS s
    ON BINARY s.TABLE_SCHEMA=BINARY 'inlaud99_erpserra' AND BINARY s.TABLE_NAME=BINARY p_tabela AND BINARY s.COLUMN_NAME=BINARY d.COLUMN_NAME
   WHERE BINARY d.TABLE_SCHEMA=BINARY 'inlaud99_erpcondor' AND BINARY d.TABLE_NAME=BINARY p_tabela
     AND BINARY d.COLUMN_NAME<>BINARY 'tenant_id' AND UPPER(IFNULL(d.EXTRA,'')) NOT LIKE '%GENERATED%';
  IF v_tenant>0 THEN SET @mt_cols=CONCAT(@mt_cols,',`tenant_id`'); SET @mt_vals=CONCAT(@mt_vals,',1'); END IF;
  SET @mt_count=CONCAT('SELECT COUNT(*) INTO @mt_qtd FROM `inlaud99_erpserra`.`',p_tabela,'`');
  PREPARE mt_stmt FROM @mt_count; EXECUTE mt_stmt; DEALLOCATE PREPARE mt_stmt; SET v_origem=COALESCE(@mt_qtd,0);
  SET @mt_insert=CONCAT('INSERT INTO `inlaud99_erpcondor`.`',p_tabela,'` (',@mt_cols,') SELECT ',@mt_vals,' FROM `inlaud99_erpserra`.`',p_tabela,'` s');
  PREPARE mt_stmt FROM @mt_insert; EXECUTE mt_stmt; DEALLOCATE PREPARE mt_stmt;
  SET @mt_count=CONCAT('SELECT COUNT(*) INTO @mt_qtd FROM `inlaud99_erpcondor`.`',p_tabela,'`');
  PREPARE mt_stmt FROM @mt_count; EXECUTE mt_stmt; DEALLOCATE PREPARE mt_stmt; SET v_importados=COALESCE(@mt_qtd,0);
  INSERT INTO mt_reconstrucao_tabelas(tabela,tenant_id,registros_origem,registros_importados,status,mensagem)
  VALUES(p_tabela,1,v_origem,v_importados,'concluida','Destino limpo e recopiado da origem ERP Serra')
  ON DUPLICATE KEY UPDATE registros_origem=VALUES(registros_origem),registros_importados=VALUES(registros_importados),status=VALUES(status),mensagem=VALUES(mensagem),executado_em=NOW();
END$$
DELIMITER ;

START TRANSACTION;
DELETE FROM `mt_reconstrucao_tabelas` WHERE tenant_id=1;
DELETE FROM `mt_consolidacao_tabelas` WHERE tenant_id=1;

-- Remove dados de negócio do tenant, deixando a estrutura Multi-Tenant e o tenant mestre preservados.
CALL mt_limpar_tabela('abastecimento_lancamentos');
CALL mt_limpar_tabela('abastecimento_recargas');
CALL mt_limpar_tabela('abastecimento_saldo');
CALL mt_limpar_tabela('abastecimento_veiculos');
CALL mt_limpar_tabela('acessos_visitantes');
CALL mt_limpar_tabela('alertas_estoque');
CALL mt_limpar_tabela('avaliacoes');
CALL mt_limpar_tabela('avaliacoes_backup');
CALL mt_limpar_tabela('bridge_eventos_log');
CALL mt_limpar_tabela('bridge_status');
CALL mt_limpar_tabela('categorias_estoque');
CALL mt_limpar_tabela('checklist_alertas_config');
CALL mt_limpar_tabela('checklist_alertas_gerados');
CALL mt_limpar_tabela('checklist_itens');
CALL mt_limpar_tabela('checklist_km_acumulado');
CALL mt_limpar_tabela('checklist_veicular');
CALL mt_limpar_tabela('conciliacoes');
CALL mt_limpar_tabela('config_periodo_leitura');
CALL mt_limpar_tabela('configuracao_smtp');
CALL mt_limpar_tabela('configuracoes');
CALL mt_limpar_tabela('contas_bancarias');
CALL mt_limpar_tabela('contas_pagar');
CALL mt_limpar_tabela('contas_receber');
CALL mt_limpar_tabela('contrato_aditivos');
CALL mt_limpar_tabela('contrato_documentos');
CALL mt_limpar_tabela('contrato_orcamento_documentos');
CALL mt_limpar_tabela('contrato_orcamentos');
CALL mt_limpar_tabela('contratos');
CALL mt_limpar_tabela('controlid_dispositivos');
CALL mt_limpar_tabela('controlid_eventos_acesso');
CALL mt_limpar_tabela('controlid_push_eventos');
CALL mt_limpar_tabela('crm_anexos');
CALL mt_limpar_tabela('crm_interacoes');
CALL mt_limpar_tabela('crm_relacionamentos');
CALL mt_limpar_tabela('crm_sequencia');
CALL mt_limpar_tabela('departamentos');
CALL mt_limpar_tabela('dependentes');
CALL mt_limpar_tabela('dispositivos_console');
CALL mt_limpar_tabela('dispositivos_controlid');
CALL mt_limpar_tabela('dispositivos_controlid_leituras');
CALL mt_limpar_tabela('dispositivos_controlid_sync_log');
CALL mt_limpar_tabela('dispositivos_seguranca');
CALL mt_limpar_tabela('dispositivos_tablets');
CALL mt_limpar_tabela('documentos');
CALL mt_limpar_tabela('documentos_acessos');
CALL mt_limpar_tabela('documentos_compartilhamentos');
CALL mt_limpar_tabela('documentos_departamentos_migrado_bkp');
CALL mt_limpar_tabela('documentos_grupos');
CALL mt_limpar_tabela('documentos_grupos_moradores');
CALL mt_limpar_tabela('documentos_grupos_usuarios');
CALL mt_limpar_tabela('documentos_logs');
CALL mt_limpar_tabela('documentos_pastas');
CALL mt_limpar_tabela('documentos_tipos');
CALL mt_limpar_tabela('documentos_usuarios_acesso');
CALL mt_limpar_tabela('email_alertas');
CALL mt_limpar_tabela('email_delivery_logs');
CALL mt_limpar_tabela('email_log');
CALL mt_limpar_tabela('email_providers');
CALL mt_limpar_tabela('email_templates');
CALL mt_limpar_tabela('empresa');
CALL mt_limpar_tabela('empresa_log');
CALL mt_limpar_tabela('face_descriptors');
CALL mt_limpar_tabela('fornecedores');
CALL mt_limpar_tabela('grupos_inventario');
CALL mt_limpar_tabela('hidrometro');
CALL mt_limpar_tabela('hidrometros');
CALL mt_limpar_tabela('hidrometros_historico');
CALL mt_limpar_tabela('historico_importacoes_ofx');
CALL mt_limpar_tabela('historico_pagamentos');
CALL mt_limpar_tabela('historico_status_pedido');
CALL mt_limpar_tabela('importacoes_financeiras');
CALL mt_limpar_tabela('importacoes_financeiras_itens');
CALL mt_limpar_tabela('inventario');
CALL mt_limpar_tabela('lancamentos_agua');
CALL mt_limpar_tabela('leituras');
CALL mt_limpar_tabela('leituras_fotos');
CALL mt_limpar_tabela('local_acessos');
CALL mt_limpar_tabela('local_acessos_log');
CALL mt_limpar_tabela('local_acessos_tipos');
CALL mt_limpar_tabela('log_reset_senha');
CALL mt_limpar_tabela('logs_acesso_qrcode');
CALL mt_limpar_tabela('logs_erro');
CALL mt_limpar_tabela('logs_financeiro');
CALL mt_limpar_tabela('logs_sistema');
CALL mt_limpar_tabela('logs_validacoes_dispositivo');
CALL mt_limpar_tabela('manual_artigos');
CALL mt_limpar_tabela('manual_avaliacoes');
CALL mt_limpar_tabela('manual_buscas');
CALL mt_limpar_tabela('manual_categorias');
CALL mt_limpar_tabela('manual_favoritos');
CALL mt_limpar_tabela('manual_historico');
CALL mt_limpar_tabela('manual_modulos');
CALL mt_limpar_tabela('marcas_dispositivo');
CALL mt_limpar_tabela('media_avaliacoes_fornecedor');
CALL mt_limpar_tabela('media_avaliacoes_produto');
CALL mt_limpar_tabela('modelos_dispositivo');
CALL mt_limpar_tabela('moradores');
CALL mt_limpar_tabela('movimentacoes_bancarias');
CALL mt_limpar_tabela('movimentacoes_estoque');
CALL mt_limpar_tabela('notif_alertas');
CALL mt_limpar_tabela('notif_destinatarios');
CALL mt_limpar_tabela('notif_regras');
CALL mt_limpar_tabela('notificacoes');
CALL mt_limpar_tabela('notificacoes_downloads');
CALL mt_limpar_tabela('notificacoes_visualizacoes');
CALL mt_limpar_tabela('os_assuntos');
CALL mt_limpar_tabela('os_chamados');
CALL mt_limpar_tabela('os_config_homem_hora');
CALL mt_limpar_tabela('os_etapas');
CALL mt_limpar_tabela('os_interacao_fotos');
CALL mt_limpar_tabela('os_interacoes');
CALL mt_limpar_tabela('os_materiais_usados');
CALL mt_limpar_tabela('os_recursos_humanos');
CALL mt_limpar_tabela('pedidos');
CALL mt_limpar_tabela('planos_contas');
CALL mt_limpar_tabela('produtos_estoque');
CALL mt_limpar_tabela('produtos_servicos');
CALL mt_limpar_tabela('protocolos');
CALL mt_limpar_tabela('pwa_configuracoes');
CALL mt_limpar_tabela('pwa_logs');
CALL mt_limpar_tabela('pwa_notificacoes_push');
CALL mt_limpar_tabela('pwa_notificacoes_recebidas');
CALL mt_limpar_tabela('pwa_versao');
CALL mt_limpar_tabela('ramos_atividade');
CALL mt_limpar_tabela('recebedores');
CALL mt_limpar_tabela('registros_acesso');
CALL mt_limpar_tabela('rh_banco_horas');
CALL mt_limpar_tabela('rh_colaboradores');
CALL mt_limpar_tabela('rh_escala');
CALL mt_limpar_tabela('rh_ponto_lancamento');
CALL mt_limpar_tabela('rh_ponto_periodo');
CALL mt_limpar_tabela('tipos_dispositivo');
CALL mt_limpar_tabela('unidades');
CALL mt_limpar_tabela('usuario_modulos');
CALL mt_limpar_tabela('validacoes_acesso');
CALL mt_limpar_tabela('validacoes_face_id');
CALL mt_limpar_tabela('veiculos');
CALL mt_limpar_tabela('visitantes');

-- Usuários operacionais: a exceção é o usuário global da plataforma para administração Multi-Tenant.
DELETE FROM `inlaud99_erpcondor`.`usuario_tenant` WHERE tenant_id=1;
DELETE FROM `inlaud99_erpcondor`.`usuarios` WHERE tenant_id=1 AND email<>'admin@erpcondominios.com.br';
DELETE FROM `inlaud99_erpcondor`.`documentos_acessos` WHERE tenant_id=1;

-- Recopia usuários diretamente da origem, sem rebaixar a conta global da plataforma.
INSERT INTO `inlaud99_erpcondor`.`usuarios` (`id`,`tenant_id`,`nome`,`email`,`senha`,`funcao`,`departamento`,`permissao`,`ativo`,`sessao_inativa`,`data_criacao`,`data_atualizacao`)
SELECT s.id,1,s.nome,s.email,s.senha,s.funcao,s.departamento,s.permissao,s.ativo,s.sessao_inativa,s.data_criacao,s.data_atualizacao
FROM `inlaud99_erpserra`.`usuarios` s;

-- Recopia cada tabela de negócio com uma única versão vinda da origem.
CALL mt_importar_origem('abastecimento_lancamentos');
CALL mt_importar_origem('abastecimento_recargas');
CALL mt_importar_origem('abastecimento_saldo');
CALL mt_importar_origem('abastecimento_veiculos');
CALL mt_importar_origem('acessos_visitantes');
CALL mt_importar_origem('alertas_estoque');
CALL mt_importar_origem('avaliacoes');
CALL mt_importar_origem('avaliacoes_backup');
CALL mt_importar_origem('bridge_eventos_log');
CALL mt_importar_origem('bridge_status');
CALL mt_importar_origem('categorias_estoque');
CALL mt_importar_origem('checklist_alertas_config');
CALL mt_importar_origem('checklist_alertas_gerados');
CALL mt_importar_origem('checklist_itens');
CALL mt_importar_origem('checklist_km_acumulado');
CALL mt_importar_origem('checklist_veicular');
CALL mt_importar_origem('conciliacoes');
CALL mt_importar_origem('config_periodo_leitura');
CALL mt_importar_origem('configuracao_smtp');
CALL mt_importar_origem('configuracoes');
CALL mt_importar_origem('contas_bancarias');
CALL mt_importar_origem('contas_pagar');
CALL mt_importar_origem('contas_receber');
CALL mt_importar_origem('contrato_aditivos');
CALL mt_importar_origem('contrato_documentos');
CALL mt_importar_origem('contrato_orcamento_documentos');
CALL mt_importar_origem('contrato_orcamentos');
CALL mt_importar_origem('contratos');
CALL mt_importar_origem('controlid_dispositivos');
CALL mt_importar_origem('controlid_eventos_acesso');
CALL mt_importar_origem('controlid_push_eventos');
CALL mt_importar_origem('crm_anexos');
CALL mt_importar_origem('crm_interacoes');
CALL mt_importar_origem('crm_relacionamentos');
CALL mt_importar_origem('crm_sequencia');
CALL mt_importar_origem('departamentos');
CALL mt_importar_origem('dependentes');
CALL mt_importar_origem('dispositivos_console');
CALL mt_importar_origem('dispositivos_controlid');
CALL mt_importar_origem('dispositivos_controlid_leituras');
CALL mt_importar_origem('dispositivos_controlid_sync_log');
CALL mt_importar_origem('dispositivos_seguranca');
CALL mt_importar_origem('dispositivos_tablets');
CALL mt_importar_origem('documentos');
CALL mt_importar_origem('documentos_acessos');
CALL mt_importar_origem('documentos_compartilhamentos');
CALL mt_importar_origem('documentos_departamentos_migrado_bkp');
CALL mt_importar_origem('documentos_grupos');
CALL mt_importar_origem('documentos_grupos_moradores');
CALL mt_importar_origem('documentos_grupos_usuarios');
CALL mt_importar_origem('documentos_logs');
CALL mt_importar_origem('documentos_pastas');
CALL mt_importar_origem('documentos_tipos');
CALL mt_importar_origem('documentos_usuarios_acesso');
CALL mt_importar_origem('email_alertas');
CALL mt_importar_origem('email_delivery_logs');
CALL mt_importar_origem('email_log');
CALL mt_importar_origem('email_providers');
CALL mt_importar_origem('email_templates');
CALL mt_importar_origem('empresa');
CALL mt_importar_origem('empresa_log');
CALL mt_importar_origem('face_descriptors');
CALL mt_importar_origem('fornecedores');
CALL mt_importar_origem('grupos_inventario');
CALL mt_importar_origem('hidrometro');
CALL mt_importar_origem('hidrometros');
CALL mt_importar_origem('hidrometros_historico');
CALL mt_importar_origem('historico_importacoes_ofx');
CALL mt_importar_origem('historico_pagamentos');
CALL mt_importar_origem('historico_status_pedido');
CALL mt_importar_origem('importacoes_financeiras');
CALL mt_importar_origem('importacoes_financeiras_itens');
CALL mt_importar_origem('inventario');
CALL mt_importar_origem('lancamentos_agua');
CALL mt_importar_origem('leituras');
CALL mt_importar_origem('leituras_fotos');
CALL mt_importar_origem('local_acessos');
CALL mt_importar_origem('local_acessos_log');
CALL mt_importar_origem('local_acessos_tipos');
CALL mt_importar_origem('log_reset_senha');
CALL mt_importar_origem('logs_acesso_qrcode');
CALL mt_importar_origem('logs_erro');
CALL mt_importar_origem('logs_financeiro');
CALL mt_importar_origem('logs_sistema');
CALL mt_importar_origem('logs_validacoes_dispositivo');
CALL mt_importar_origem('manual_artigos');
CALL mt_importar_origem('manual_avaliacoes');
CALL mt_importar_origem('manual_buscas');
CALL mt_importar_origem('manual_categorias');
CALL mt_importar_origem('manual_favoritos');
CALL mt_importar_origem('manual_historico');
CALL mt_importar_origem('manual_modulos');
CALL mt_importar_origem('marcas_dispositivo');
CALL mt_importar_origem('media_avaliacoes_fornecedor');
CALL mt_importar_origem('media_avaliacoes_produto');
CALL mt_importar_origem('modelos_dispositivo');
CALL mt_importar_origem('moradores');
CALL mt_importar_origem('movimentacoes_bancarias');
CALL mt_importar_origem('movimentacoes_estoque');
CALL mt_importar_origem('notif_alertas');
CALL mt_importar_origem('notif_destinatarios');
CALL mt_importar_origem('notif_regras');
CALL mt_importar_origem('notificacoes');
CALL mt_importar_origem('notificacoes_downloads');
CALL mt_importar_origem('notificacoes_visualizacoes');
CALL mt_importar_origem('os_assuntos');
CALL mt_importar_origem('os_chamados');
CALL mt_importar_origem('os_config_homem_hora');
CALL mt_importar_origem('os_etapas');
CALL mt_importar_origem('os_interacao_fotos');
CALL mt_importar_origem('os_interacoes');
CALL mt_importar_origem('os_materiais_usados');
CALL mt_importar_origem('os_recursos_humanos');
CALL mt_importar_origem('pedidos');
CALL mt_importar_origem('planos_contas');
CALL mt_importar_origem('produtos_estoque');
CALL mt_importar_origem('produtos_servicos');
CALL mt_importar_origem('protocolos');
CALL mt_importar_origem('pwa_configuracoes');
CALL mt_importar_origem('pwa_logs');
CALL mt_importar_origem('pwa_notificacoes_push');
CALL mt_importar_origem('pwa_notificacoes_recebidas');
CALL mt_importar_origem('pwa_versao');
CALL mt_importar_origem('ramos_atividade');
CALL mt_importar_origem('recebedores');
CALL mt_importar_origem('registros_acesso');
CALL mt_importar_origem('rh_banco_horas');
CALL mt_importar_origem('rh_colaboradores');
CALL mt_importar_origem('rh_escala');
CALL mt_importar_origem('rh_ponto_lancamento');
CALL mt_importar_origem('rh_ponto_periodo');
CALL mt_importar_origem('tipos_dispositivo');
CALL mt_importar_origem('unidades');
CALL mt_importar_origem('usuario_modulos');
CALL mt_importar_origem('validacoes_acesso');
CALL mt_importar_origem('validacoes_face_id');
CALL mt_importar_origem('veiculos');
CALL mt_importar_origem('visitantes');

-- Preserva permissões individuais de documentos em formato de auditoria atual.
INSERT INTO `inlaud99_erpcondor`.`documentos_acessos` (`tenant_id`,`documento_id`,`tipo`,`origem`,`usuario_id`,`created_at`)
SELECT 1,s.documento_id,'visualizacao','interno',s.usuario_id,s.created_at
FROM `inlaud99_erpserra`.`documentos_usuarios_acesso` s;

-- Recria vínculos de acesso de todos os usuários importados e da conta global.
INSERT INTO `inlaud99_erpcondor`.`usuario_tenant` (`usuario_id`,`tenant_id`,`permissao`,`ativo`,`criado_em`)
SELECT u.id,1,CASE WHEN u.permissao='super_admin' THEN 'admin' ELSE u.permissao END,u.ativo,NOW()
FROM `inlaud99_erpcondor`.`usuarios` u WHERE u.tenant_id=1;

-- Sincroniza o cadastro mestre do tenant conforme a empresa reimportada da origem.
UPDATE `inlaud99_erpcondor`.`tenants` t INNER JOIN `inlaud99_erpcondor`.`empresa` e ON e.tenant_id=t.id
SET t.razao_social=e.razao_social,t.nome_fantasia=e.nome_fantasia,t.cnpj=e.cnpj,
    t.logo_url=e.logo_url,t.email_principal=e.email_principal,t.telefone=e.telefone,
    t.cidade=e.endereco_cidade,t.estado=e.endereco_estado,
    t.status=IF(e.situacao='ativo','ativo','suspenso'),t.data_atualizacao=NOW()
WHERE t.id=1;

COMMIT;
DROP PROCEDURE `mt_limpar_tabela`;
DROP PROCEDURE `mt_importar_origem`;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;

SELECT tabela,registros_origem,registros_removidos,registros_importados,status,mensagem,executado_em
FROM mt_reconstrucao_tabelas WHERE tenant_id=1 ORDER BY tabela;
