-- ============================================================================
-- CORREÇÃO SEGURA DE CHAVES E AUTO_INCREMENT — MYSQL/MARIADB 5.7
-- Corrige somente tabelas cuja coluna id já esteja íntegra: sem 0, NULL ou
-- valores duplicados. Tabelas problemáticas são registradas e NÃO alteradas.
-- Execute depois de reconstruir o tenant 1 a partir da origem ERP Serra.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `inlaud99_erpcondor`.`mt_integridade_ids_log` (
 id INT NOT NULL AUTO_INCREMENT,
 tabela VARCHAR(128) NOT NULL,
 primary_key_id TINYINT(1) NOT NULL DEFAULT 0,
 auto_increment_id TINYINT(1) NOT NULL DEFAULT 0,
 ids_invalidos BIGINT NOT NULL DEFAULT 0,
 ids_duplicados BIGINT NOT NULL DEFAULT 0,
 acao ENUM('corrigida','pendente','ignorada') NOT NULL,
 mensagem VARCHAR(500) NOT NULL,
 executado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id), UNIQUE KEY uk_mt_integridade_tabela(tabela)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE IF EXISTS `inlaud99_erpcondor`.`mt_corrigir_id_seguro`;
DELIMITER $$
CREATE PROCEDURE `inlaud99_erpcondor`.`mt_corrigir_id_seguro`(IN p_tabela VARCHAR(128))
proc: BEGIN
 DECLARE v_id_tipo VARCHAR(100);
 DECLARE v_pk INT DEFAULT 0;
 DECLARE v_pk_outro INT DEFAULT 0;
 DECLARE v_ai INT DEFAULT 0;
 DECLARE v_invalidos BIGINT DEFAULT 0;
 DECLARE v_duplicados BIGINT DEFAULT 0;
 SELECT COLUMN_TYPE, IF(EXTRA LIKE '%auto_increment%',1,0) INTO v_id_tipo,v_ai
 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='inlaud99_erpcondor' AND TABLE_NAME=p_tabela AND COLUMN_NAME='id' LIMIT 1;
 IF v_id_tipo IS NULL THEN
   LEAVE proc;
 END IF;
 SELECT COUNT(*) INTO v_pk FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA='inlaud99_erpcondor' AND TABLE_NAME=p_tabela AND CONSTRAINT_NAME='PRIMARY' AND COLUMN_NAME='id';
 SELECT COUNT(*) INTO v_pk_outro FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA='inlaud99_erpcondor' AND TABLE_NAME=p_tabela AND CONSTRAINT_NAME='PRIMARY' AND COLUMN_NAME<>'id';
 SET @q=CONCAT('SELECT COUNT(*) INTO @n FROM `inlaud99_erpcondor`.`',p_tabela,'` WHERE id IS NULL OR id=0'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s; SET v_invalidos=COALESCE(@n,0);
 SET @q=CONCAT('SELECT COUNT(*)-COUNT(DISTINCT id) INTO @n FROM `inlaud99_erpcondor`.`',p_tabela,'` WHERE id IS NOT NULL AND id<>0'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s; SET v_duplicados=COALESCE(@n,0);
 IF v_pk_outro>0 THEN
   INSERT INTO mt_integridade_ids_log(tabela,primary_key_id,auto_increment_id,ids_invalidos,ids_duplicados,acao,mensagem)
   VALUES(p_tabela,v_pk,v_ai,v_invalidos,v_duplicados,'ignorada','Tabela possui chave primária em outra coluna; revisão manual necessária')
   ON DUPLICATE KEY UPDATE primary_key_id=VALUES(primary_key_id),auto_increment_id=VALUES(auto_increment_id),ids_invalidos=VALUES(ids_invalidos),ids_duplicados=VALUES(ids_duplicados),acao=VALUES(acao),mensagem=VALUES(mensagem),executado_em=NOW();
   LEAVE proc;
 END IF;
 IF v_invalidos>0 OR v_duplicados>0 THEN
   INSERT INTO mt_integridade_ids_log(tabela,primary_key_id,auto_increment_id,ids_invalidos,ids_duplicados,acao,mensagem)
   VALUES(p_tabela,v_pk,v_ai,v_invalidos,v_duplicados,'pendente','Há id 0/NULL ou duplicado; não foi alterada para preservar relacionamentos')
   ON DUPLICATE KEY UPDATE primary_key_id=VALUES(primary_key_id),auto_increment_id=VALUES(auto_increment_id),ids_invalidos=VALUES(ids_invalidos),ids_duplicados=VALUES(ids_duplicados),acao=VALUES(acao),mensagem=VALUES(mensagem),executado_em=NOW();
   LEAVE proc;
 END IF;
 IF v_pk=0 THEN
   SET @q=CONCAT('ALTER TABLE `inlaud99_erpcondor`.`',p_tabela,'` ADD PRIMARY KEY (`id`)'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s; SET v_pk=1;
 END IF;
 IF v_ai=0 THEN
   SET @q=CONCAT('ALTER TABLE `inlaud99_erpcondor`.`',p_tabela,'` MODIFY `id` ',v_id_tipo,' NOT NULL AUTO_INCREMENT'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s; SET v_ai=1;
 END IF;
 INSERT INTO mt_integridade_ids_log(tabela,primary_key_id,auto_increment_id,ids_invalidos,ids_duplicados,acao,mensagem)
 VALUES(p_tabela,v_pk,v_ai,0,0,'corrigida','PRIMARY KEY(id) e AUTO_INCREMENT garantidos')
 ON DUPLICATE KEY UPDATE primary_key_id=VALUES(primary_key_id),auto_increment_id=VALUES(auto_increment_id),ids_invalidos=VALUES(ids_invalidos),ids_duplicados=VALUES(ids_duplicados),acao=VALUES(acao),mensagem=VALUES(mensagem),executado_em=NOW();
END$$
DELIMITER ;

-- Protocolos é tratado em arquivo próprio depois da reconstrução, para preservar o registro id=0.
CALL mt_corrigir_id_seguro('abastecimento_lancamentos');
CALL mt_corrigir_id_seguro('abastecimento_recargas');
CALL mt_corrigir_id_seguro('abastecimento_saldo');
CALL mt_corrigir_id_seguro('abastecimento_veiculos');
CALL mt_corrigir_id_seguro('acessos_visitantes');
CALL mt_corrigir_id_seguro('alertas_estoque');
CALL mt_corrigir_id_seguro('avaliacoes');
CALL mt_corrigir_id_seguro('avaliacoes_backup');
CALL mt_corrigir_id_seguro('bancos_brasileiros');
CALL mt_corrigir_id_seguro('bridge_eventos_log');
CALL mt_corrigir_id_seguro('bridge_fila_comandos');
CALL mt_corrigir_id_seguro('bridge_status');
CALL mt_corrigir_id_seguro('categorias_estoque');
CALL mt_corrigir_id_seguro('checklist_alertas_config');
CALL mt_corrigir_id_seguro('checklist_alertas_gerados');
CALL mt_corrigir_id_seguro('checklist_itens');
CALL mt_corrigir_id_seguro('checklist_km_acumulado');
CALL mt_corrigir_id_seguro('checklist_veicular');
CALL mt_corrigir_id_seguro('conciliacoes');
CALL mt_corrigir_id_seguro('configuracao_smtp');
CALL mt_corrigir_id_seguro('configuracoes');
CALL mt_corrigir_id_seguro('config_periodo_leitura');
CALL mt_corrigir_id_seguro('config_sessao');
CALL mt_corrigir_id_seguro('contas_bancarias');
CALL mt_corrigir_id_seguro('contas_pagar');
CALL mt_corrigir_id_seguro('contas_receber');
CALL mt_corrigir_id_seguro('contratos');
CALL mt_corrigir_id_seguro('contrato_aditivos');
CALL mt_corrigir_id_seguro('contrato_documentos');
CALL mt_corrigir_id_seguro('contrato_orcamentos');
CALL mt_corrigir_id_seguro('contrato_orcamento_documentos');
CALL mt_corrigir_id_seguro('controlid_dispositivos');
CALL mt_corrigir_id_seguro('controlid_eventos_acesso');
CALL mt_corrigir_id_seguro('controlid_fila_comandos');
CALL mt_corrigir_id_seguro('controlid_push_eventos');
CALL mt_corrigir_id_seguro('controlid_push_queue');
CALL mt_corrigir_id_seguro('crm_anexos');
CALL mt_corrigir_id_seguro('crm_interacoes');
CALL mt_corrigir_id_seguro('crm_relacionamentos');
CALL mt_corrigir_id_seguro('departamentos');
CALL mt_corrigir_id_seguro('dependentes');
CALL mt_corrigir_id_seguro('dispositivos_console');
CALL mt_corrigir_id_seguro('dispositivos_controlid');
CALL mt_corrigir_id_seguro('dispositivos_controlid_leituras');
CALL mt_corrigir_id_seguro('dispositivos_controlid_sync_log');
CALL mt_corrigir_id_seguro('dispositivos_seguranca');
CALL mt_corrigir_id_seguro('dispositivos_tablets');
CALL mt_corrigir_id_seguro('documentos');
CALL mt_corrigir_id_seguro('documentos_acessos');
CALL mt_corrigir_id_seguro('documentos_compartilhamentos');
CALL mt_corrigir_id_seguro('documentos_departamentos_migrado_bkp');
CALL mt_corrigir_id_seguro('documentos_grupos');
CALL mt_corrigir_id_seguro('documentos_grupos_moradores');
CALL mt_corrigir_id_seguro('documentos_grupos_usuarios');
CALL mt_corrigir_id_seguro('documentos_logs');
CALL mt_corrigir_id_seguro('documentos_pastas');
CALL mt_corrigir_id_seguro('documentos_tipos');
CALL mt_corrigir_id_seguro('documentos_usuarios_acesso');
CALL mt_corrigir_id_seguro('email_alertas');
CALL mt_corrigir_id_seguro('email_delivery_logs');
CALL mt_corrigir_id_seguro('email_log');
CALL mt_corrigir_id_seguro('email_providers');
CALL mt_corrigir_id_seguro('email_templates');
CALL mt_corrigir_id_seguro('empresa');
CALL mt_corrigir_id_seguro('empresa_log');
CALL mt_corrigir_id_seguro('face_descriptors');
CALL mt_corrigir_id_seguro('fornecedores');
CALL mt_corrigir_id_seguro('grupos_inventario');
CALL mt_corrigir_id_seguro('hidrometro');
CALL mt_corrigir_id_seguro('hidrometros');
CALL mt_corrigir_id_seguro('hidrometros_historico');
CALL mt_corrigir_id_seguro('historico_importacoes_ofx');
CALL mt_corrigir_id_seguro('historico_pagamentos');
CALL mt_corrigir_id_seguro('historico_status_pedido');
CALL mt_corrigir_id_seguro('importacoes_financeiras');
CALL mt_corrigir_id_seguro('importacoes_financeiras_itens');
CALL mt_corrigir_id_seguro('inventario');
CALL mt_corrigir_id_seguro('lancamentos_agua');
CALL mt_corrigir_id_seguro('leituras');
CALL mt_corrigir_id_seguro('leituras_fotos');
CALL mt_corrigir_id_seguro('local_acessos');
CALL mt_corrigir_id_seguro('local_acessos_log');
CALL mt_corrigir_id_seguro('local_acessos_tipos');
CALL mt_corrigir_id_seguro('logs_acesso_qrcode');
CALL mt_corrigir_id_seguro('logs_erro');
CALL mt_corrigir_id_seguro('logs_financeiro');
CALL mt_corrigir_id_seguro('logs_sistema');
CALL mt_corrigir_id_seguro('logs_validacoes_dispositivo');
CALL mt_corrigir_id_seguro('log_reset_senha');
CALL mt_corrigir_id_seguro('manual_artigos');
CALL mt_corrigir_id_seguro('manual_avaliacoes');
CALL mt_corrigir_id_seguro('manual_buscas');
CALL mt_corrigir_id_seguro('manual_categorias');
CALL mt_corrigir_id_seguro('manual_historico');
CALL mt_corrigir_id_seguro('manual_modulos');
CALL mt_corrigir_id_seguro('marcas_dispositivo');
CALL mt_corrigir_id_seguro('modelos_dispositivo');
CALL mt_corrigir_id_seguro('modulos_sistema');
CALL mt_corrigir_id_seguro('moradores');
CALL mt_corrigir_id_seguro('movimentacoes_bancarias');
CALL mt_corrigir_id_seguro('movimentacoes_estoque');
CALL mt_corrigir_id_seguro('mt_consolidacao_exclusoes');
CALL mt_corrigir_id_seguro('mt_consolidacao_tabelas');
CALL mt_corrigir_id_seguro('notificacoes');
CALL mt_corrigir_id_seguro('notificacoes_downloads');
CALL mt_corrigir_id_seguro('notificacoes_visualizacoes');
CALL mt_corrigir_id_seguro('notif_alertas');
CALL mt_corrigir_id_seguro('notif_destinatarios');
CALL mt_corrigir_id_seguro('notif_regras');
CALL mt_corrigir_id_seguro('os_assuntos');
CALL mt_corrigir_id_seguro('os_chamados');
CALL mt_corrigir_id_seguro('os_config_homem_hora');
CALL mt_corrigir_id_seguro('os_etapas');
CALL mt_corrigir_id_seguro('os_interacao_fotos');
CALL mt_corrigir_id_seguro('os_interacoes');
CALL mt_corrigir_id_seguro('os_materiais_usados');
CALL mt_corrigir_id_seguro('os_recursos_humanos');
CALL mt_corrigir_id_seguro('pedidos');
CALL mt_corrigir_id_seguro('planos_contas');
CALL mt_corrigir_id_seguro('produtos_estoque');
CALL mt_corrigir_id_seguro('produtos_servicos');
CALL mt_corrigir_id_seguro('publico_rate_limit');
CALL mt_corrigir_id_seguro('pwa_configuracoes');
CALL mt_corrigir_id_seguro('pwa_fcm_tokens');
CALL mt_corrigir_id_seguro('pwa_logs');
CALL mt_corrigir_id_seguro('pwa_notificacoes_push');
CALL mt_corrigir_id_seguro('pwa_notificacoes_recebidas');
CALL mt_corrigir_id_seguro('pwa_oauth_cache');
CALL mt_corrigir_id_seguro('pwa_versao');
CALL mt_corrigir_id_seguro('qrcodes_temporarios');
CALL mt_corrigir_id_seguro('qrcode_tokens');
CALL mt_corrigir_id_seguro('ramos_atividade');
CALL mt_corrigir_id_seguro('recebedores');
CALL mt_corrigir_id_seguro('recuperacao_senha_tokens');
CALL mt_corrigir_id_seguro('registros_acesso');
CALL mt_corrigir_id_seguro('rh_banco_horas');
CALL mt_corrigir_id_seguro('rh_colaboradores');
CALL mt_corrigir_id_seguro('rh_escala');
CALL mt_corrigir_id_seguro('rh_ponto_lancamento');
CALL mt_corrigir_id_seguro('rh_ponto_periodo');
CALL mt_corrigir_id_seguro('senha_recuperacao_logs');
CALL mt_corrigir_id_seguro('sessoes_portal');
CALL mt_corrigir_id_seguro('sessoes_usuarios');
CALL mt_corrigir_id_seguro('tenants');
CALL mt_corrigir_id_seguro('tipos_dispositivo');
CALL mt_corrigir_id_seguro('unidades');
CALL mt_corrigir_id_seguro('usuarios');
CALL mt_corrigir_id_seguro('usuario_modulos');
CALL mt_corrigir_id_seguro('usuario_tenant');
CALL mt_corrigir_id_seguro('validacoes_acesso');
CALL mt_corrigir_id_seguro('validacoes_face_id');
CALL mt_corrigir_id_seguro('veiculos');
CALL mt_corrigir_id_seguro('view_dispositivos_ativos');
CALL mt_corrigir_id_seguro('view_tokens_ativos');
CALL mt_corrigir_id_seguro('visitantes');
CALL mt_corrigir_id_seguro('vw_alertas_pendentes');
CALL mt_corrigir_id_seguro('vw_checklist_completo');
CALL mt_corrigir_id_seguro('vw_consumo_veiculos');
CALL mt_corrigir_id_seguro('vw_dependentes_ativos');
CALL mt_corrigir_id_seguro('vw_dependentes_completo');
CALL mt_corrigir_id_seguro('vw_extrato_bancario');
CALL mt_corrigir_id_seguro('vw_movimentacoes_detalhadas');
CALL mt_corrigir_id_seguro('vw_pendencias_conciliacao');
CALL mt_corrigir_id_seguro('vw_produtos_estoque_baixo');
CALL mt_corrigir_id_seguro('vw_saldo_contas');
CALL mt_corrigir_id_seguro('vw_ultimos_abastecimentos');
CALL mt_corrigir_id_seguro('v_emails_recentes');
DROP PROCEDURE `inlaud99_erpcondor`.`mt_corrigir_id_seguro`;
SELECT * FROM `inlaud99_erpcondor`.`mt_integridade_ids_log` ORDER BY acao,tabela;
