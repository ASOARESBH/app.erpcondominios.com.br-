-- ============================================================================
-- CONSOLIDAÇÃO ERP SERRA (LEGADO) -> ERP CONDOR (MULTI-TENANT)
-- Compatível com MySQL/MariaDB 5.7
-- Gerado a partir dos dumps reais em inlaud99_erpserra(4).sql e inlaud99_erpcondor(1).sql
--
-- PRÉ-REQUISITOS
-- 1. Backup completo de `inlaud99_erpcondor`.
-- 2. O dump legado deve ser importado como banco separado `inlaud99_erpserra`.
-- 3. Executar no phpMyAdmin por IMPORTAR, não colar parcialmente no editor.
-- 4. Todos os dados de negócio da origem serão associados ao tenant 1.
-- ============================================================================

SET NAMES utf8mb4;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;
SET SESSION group_concat_max_len = 1048576;

-- Se a versão anterior parou no meio de uma transação nesta mesma conexão,
-- desfaz a cópia parcial antes de iniciar a execução idempotente corrigida.
ROLLBACK;

-- Falha cedo caso um dos dois bancos não esteja disponível.
DROP PROCEDURE IF EXISTS `inlaud99_erpcondor`.`mt_validar_consolidacao`;
DELIMITER $$
CREATE PROCEDURE `inlaud99_erpcondor`.`mt_validar_consolidacao`()
BEGIN
    DECLARE v_origem INT DEFAULT 0;
    DECLARE v_destino INT DEFAULT 0;
    DECLARE v_tenant INT DEFAULT 0;
    DECLARE v_outros_tenants INT DEFAULT 0;

    SELECT COUNT(*) INTO v_origem FROM INFORMATION_SCHEMA.SCHEMATA
     WHERE BINARY SCHEMA_NAME = BINARY 'inlaud99_erpserra';
    SELECT COUNT(*) INTO v_destino FROM INFORMATION_SCHEMA.SCHEMATA
     WHERE BINARY SCHEMA_NAME = BINARY 'inlaud99_erpcondor';
    SELECT COUNT(*) INTO v_tenant FROM `inlaud99_erpcondor`.`tenants` WHERE id = 1;
    SELECT COUNT(*) INTO v_outros_tenants FROM `inlaud99_erpcondor`.`tenants` WHERE id <> 1;

    IF v_origem = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Banco legado inlaud99_erpserra nao encontrado'; END IF;
    IF v_destino = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Banco destino inlaud99_erpcondor nao encontrado'; END IF;
    IF v_tenant = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tenant 1 nao existe no banco destino'; END IF;
    IF v_outros_tenants > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Destino possui outros tenants; exige plano de mapeamento de IDs dedicado'; END IF;
END$$
DELIMITER ;
CALL `inlaud99_erpcondor`.`mt_validar_consolidacao`();
DROP PROCEDURE `inlaud99_erpcondor`.`mt_validar_consolidacao`;

USE `inlaud99_erpcondor`;

CREATE TABLE IF NOT EXISTS `mt_consolidacao_tabelas` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tabela` VARCHAR(128) NOT NULL,
  `tenant_id` INT NOT NULL,
  `registros_origem` BIGINT NOT NULL DEFAULT 0,
  `registros_destino_antes` BIGINT NOT NULL DEFAULT 0,
  `registros_destino_depois` BIGINT NOT NULL DEFAULT 0,
  `status` ENUM('concluida','ignorada','erro') NOT NULL,
  `mensagem` TEXT NULL,
  `executado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mt_consolidacao_tabela` (`tabela`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mt_consolidacao_exclusoes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tabela` VARCHAR(128) NOT NULL,
  `motivo` VARCHAR(500) NOT NULL,
  `registrado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mt_exclusao_tabela` (`tabela`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registra de forma explícita as tabelas não transferidas por serem infraestrutura transitória.
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('bancos_brasileiros', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('bridge_fila_comandos', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('config_sessao', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('controlid_fila_comandos', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('controlid_push_queue', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('modulos_sistema', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('publico_rate_limit', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('pwa_fcm_tokens', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('pwa_oauth_cache', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('qrcode_tokens', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('qrcodes_temporarios', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('recuperacao_senha_tokens', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('senha_recuperacao_logs', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('sessoes_portal', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('sessoes_usuarios', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('usuarios', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');
INSERT IGNORE INTO mt_consolidacao_exclusoes (tabela, motivo) VALUES ('view_dispositivos_ativos', 'Infraestrutura, sessão, token, fila ou referência global; mantida apenas no backup legado.');

-- Motor genérico: usa apenas colunas compartilhadas entre origem e destino.
-- Se a tabela de destino tiver tenant_id, injeta o tenant fixo 1.
DROP PROCEDURE IF EXISTS `mt_consolidar_tabela`;
DELIMITER $$
CREATE PROCEDURE `mt_consolidar_tabela`(IN p_tabela VARCHAR(128))
proc: BEGIN
    DECLARE v_compartilhadas INT DEFAULT 0;
    DECLARE v_tem_tenant INT DEFAULT 0;
    DECLARE v_origem BIGINT DEFAULT 0;
    DECLARE v_antes BIGINT DEFAULT 0;
    DECLARE v_depois BIGINT DEFAULT 0;
    DECLARE v_concluida INT DEFAULT 0;

    -- Se a tabela já foi consolidada, não repete INSERTs sem chave única.
    SELECT COUNT(*) INTO v_concluida
      FROM mt_consolidacao_tabelas
     WHERE tabela = p_tabela AND tenant_id = 1 AND status = 'concluida';
    IF v_concluida > 0 THEN
        LEAVE proc;
    END IF;

    SELECT COUNT(*) INTO v_compartilhadas
      FROM INFORMATION_SCHEMA.COLUMNS s
      INNER JOIN INFORMATION_SCHEMA.COLUMNS d
        ON BINARY d.TABLE_SCHEMA = BINARY 'inlaud99_erpcondor'
       AND BINARY d.TABLE_NAME = BINARY p_tabela
       AND BINARY d.COLUMN_NAME = BINARY s.COLUMN_NAME
     WHERE BINARY s.TABLE_SCHEMA = BINARY 'inlaud99_erpserra'
       AND BINARY s.TABLE_NAME = BINARY p_tabela;

    IF v_compartilhadas = 0 THEN
        INSERT INTO mt_consolidacao_tabelas
          (tabela, tenant_id, status, mensagem)
        VALUES (p_tabela, 1, 'ignorada', 'Sem colunas compatíveis entre origem e destino')
        ON DUPLICATE KEY UPDATE status=VALUES(status), mensagem=VALUES(mensagem), executado_em=NOW();
        LEAVE proc;
    END IF;

    SELECT COUNT(*) INTO v_tem_tenant
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE BINARY TABLE_SCHEMA = BINARY 'inlaud99_erpcondor'
       AND BINARY TABLE_NAME = BINARY p_tabela
       AND BINARY COLUMN_NAME = BINARY 'tenant_id';

    SELECT GROUP_CONCAT(CONCAT('`', d.COLUMN_NAME, '`') ORDER BY d.ORDINAL_POSITION SEPARATOR ',') INTO @mt_cols
      FROM INFORMATION_SCHEMA.COLUMNS d
      INNER JOIN INFORMATION_SCHEMA.COLUMNS s
        ON BINARY s.TABLE_SCHEMA = BINARY 'inlaud99_erpserra'
       AND BINARY s.TABLE_NAME = BINARY p_tabela
       AND BINARY s.COLUMN_NAME = BINARY d.COLUMN_NAME
     WHERE BINARY d.TABLE_SCHEMA = BINARY 'inlaud99_erpcondor'
       AND BINARY d.TABLE_NAME = BINARY p_tabela
       AND BINARY d.COLUMN_NAME <> BINARY 'tenant_id'
       AND UPPER(IFNULL(d.EXTRA, '')) NOT LIKE '%GENERATED%';

    SELECT GROUP_CONCAT(CONCAT('s.`', d.COLUMN_NAME, '`') ORDER BY d.ORDINAL_POSITION SEPARATOR ',') INTO @mt_vals
      FROM INFORMATION_SCHEMA.COLUMNS d
      INNER JOIN INFORMATION_SCHEMA.COLUMNS s
        ON BINARY s.TABLE_SCHEMA = BINARY 'inlaud99_erpserra'
       AND BINARY s.TABLE_NAME = BINARY p_tabela
       AND BINARY s.COLUMN_NAME = BINARY d.COLUMN_NAME
     WHERE BINARY d.TABLE_SCHEMA = BINARY 'inlaud99_erpcondor'
       AND BINARY d.TABLE_NAME = BINARY p_tabela
       AND BINARY d.COLUMN_NAME <> BINARY 'tenant_id'
       AND UPPER(IFNULL(d.EXTRA, '')) NOT LIKE '%GENERATED%';

    SELECT GROUP_CONCAT(CONCAT('`', d.COLUMN_NAME, '`=VALUES(`', d.COLUMN_NAME, '`)') ORDER BY d.ORDINAL_POSITION SEPARATOR ',') INTO @mt_updates
      FROM INFORMATION_SCHEMA.COLUMNS d
      INNER JOIN INFORMATION_SCHEMA.COLUMNS s
        ON BINARY s.TABLE_SCHEMA = BINARY 'inlaud99_erpserra'
       AND BINARY s.TABLE_NAME = BINARY p_tabela
       AND BINARY s.COLUMN_NAME = BINARY d.COLUMN_NAME
     WHERE BINARY d.TABLE_SCHEMA = BINARY 'inlaud99_erpcondor'
       AND BINARY d.TABLE_NAME = BINARY p_tabela
       AND BINARY d.COLUMN_NAME <> BINARY 'tenant_id'
       AND UPPER(IFNULL(d.EXTRA, '')) NOT LIKE '%GENERATED%';

    IF v_tem_tenant > 0 THEN
        SET @mt_cols = CONCAT(@mt_cols, ',`tenant_id`');
        SET @mt_vals = CONCAT(@mt_vals, ',1');
        SET @mt_updates = CONCAT(@mt_updates, ',`tenant_id`=1');
    END IF;

    SET @mt_count_sql = CONCAT('SELECT COUNT(*) INTO @mt_qtd FROM `inlaud99_erpserra`.`', p_tabela, '`');
    PREPARE mt_stmt FROM @mt_count_sql; EXECUTE mt_stmt; DEALLOCATE PREPARE mt_stmt;
    SET v_origem = COALESCE(@mt_qtd, 0);

    SET @mt_count_sql = CONCAT('SELECT COUNT(*) INTO @mt_qtd FROM `inlaud99_erpcondor`.`', p_tabela, '`');
    PREPARE mt_stmt FROM @mt_count_sql; EXECUTE mt_stmt; DEALLOCATE PREPARE mt_stmt;
    SET v_antes = COALESCE(@mt_qtd, 0);

    SET @mt_sql = CONCAT(
      'INSERT INTO `inlaud99_erpcondor`.`', p_tabela, '` (', @mt_cols, ') ',
      'SELECT ', @mt_vals, ' FROM `inlaud99_erpserra`.`', p_tabela, '` s ',
      'ON DUPLICATE KEY UPDATE ', @mt_updates
    );
    PREPARE mt_stmt FROM @mt_sql; EXECUTE mt_stmt; DEALLOCATE PREPARE mt_stmt;

    SET @mt_count_sql = CONCAT('SELECT COUNT(*) INTO @mt_qtd FROM `inlaud99_erpcondor`.`', p_tabela, '`');
    PREPARE mt_stmt FROM @mt_count_sql; EXECUTE mt_stmt; DEALLOCATE PREPARE mt_stmt;
    SET v_depois = COALESCE(@mt_qtd, 0);

    INSERT INTO mt_consolidacao_tabelas
      (tabela, tenant_id, registros_origem, registros_destino_antes, registros_destino_depois, status, mensagem)
    VALUES (p_tabela, 1, v_origem, v_antes, v_depois, 'concluida', 'Dados mesclados; origem prevalece em conflitos de chave')
    ON DUPLICATE KEY UPDATE
      registros_origem=VALUES(registros_origem),
      registros_destino_antes=VALUES(registros_destino_antes),
      registros_destino_depois=VALUES(registros_destino_depois),
      status=VALUES(status), mensagem=VALUES(mensagem), executado_em=NOW();
END$$
DELIMITER ;

-- A cópia de dados ocorre em transação; em caso de falha, execute ROLLBACK.
START TRANSACTION;

-- Usuários: preserva o privilégio super_admin que já existir no destino.
UPDATE `inlaud99_erpcondor`.`usuarios` d
INNER JOIN `inlaud99_erpserra`.`usuarios` s ON d.id = s.id
SET d.tenant_id = 1,
    d.nome = s.nome,
    d.email = s.email,
    d.senha = s.senha,
    d.funcao = s.funcao,
    d.departamento = s.departamento,
    d.permissao = IF(d.permissao = 'super_admin', 'super_admin', s.permissao),
    d.ativo = s.ativo,
    d.sessao_inativa = s.sessao_inativa,
    d.data_criacao = s.data_criacao,
    d.data_atualizacao = s.data_atualizacao;

INSERT INTO `inlaud99_erpcondor`.`usuarios`
  (`id`,`tenant_id`,`nome`,`email`,`senha`,`funcao`,`departamento`,`permissao`,`ativo`,`sessao_inativa`,`data_criacao`,`data_atualizacao`)
SELECT s.`id`, 1, s.`nome`, s.`email`, s.`senha`, s.`funcao`, s.`departamento`, s.`permissao`, s.`ativo`, s.`sessao_inativa`, s.`data_criacao`, s.`data_atualizacao`
FROM `inlaud99_erpserra`.`usuarios` s
LEFT JOIN `inlaud99_erpcondor`.`usuarios` d ON d.id = s.id
WHERE d.id IS NULL;

INSERT INTO `mt_consolidacao_tabelas`
  (`tabela`,`tenant_id`,`registros_origem`,`registros_destino_antes`,`registros_destino_depois`,`status`,`mensagem`)
SELECT 'usuarios', 1,
       (SELECT COUNT(*) FROM `inlaud99_erpserra`.`usuarios`),
       0,
       (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`usuarios` WHERE tenant_id=1),
       'concluida', 'Usuários mesclados; super_admin do destino preservado'
ON DUPLICATE KEY UPDATE registros_origem=VALUES(registros_origem), registros_destino_depois=VALUES(registros_destino_depois), status=VALUES(status), mensagem=VALUES(mensagem), executado_em=NOW();

-- Tabelas de negócio e histórico compatíveis.
CALL mt_consolidar_tabela('abastecimento_lancamentos');
CALL mt_consolidar_tabela('abastecimento_recargas');
CALL mt_consolidar_tabela('abastecimento_saldo');
CALL mt_consolidar_tabela('abastecimento_veiculos');
CALL mt_consolidar_tabela('acessos_visitantes');
CALL mt_consolidar_tabela('alertas_estoque');
CALL mt_consolidar_tabela('avaliacoes');
CALL mt_consolidar_tabela('avaliacoes_backup');
CALL mt_consolidar_tabela('bridge_eventos_log');
CALL mt_consolidar_tabela('bridge_status');
CALL mt_consolidar_tabela('categorias_estoque');
CALL mt_consolidar_tabela('checklist_alertas_config');
CALL mt_consolidar_tabela('checklist_alertas_gerados');
CALL mt_consolidar_tabela('checklist_itens');
CALL mt_consolidar_tabela('checklist_km_acumulado');
CALL mt_consolidar_tabela('checklist_veicular');
CALL mt_consolidar_tabela('conciliacoes');
CALL mt_consolidar_tabela('config_periodo_leitura');
CALL mt_consolidar_tabela('configuracao_smtp');
CALL mt_consolidar_tabela('configuracoes');
CALL mt_consolidar_tabela('contas_bancarias');
CALL mt_consolidar_tabela('contas_pagar');
CALL mt_consolidar_tabela('contas_receber');
CALL mt_consolidar_tabela('contrato_aditivos');
CALL mt_consolidar_tabela('contrato_documentos');
CALL mt_consolidar_tabela('contrato_orcamento_documentos');
CALL mt_consolidar_tabela('contrato_orcamentos');
CALL mt_consolidar_tabela('contratos');
CALL mt_consolidar_tabela('controlid_dispositivos');
CALL mt_consolidar_tabela('controlid_eventos_acesso');
CALL mt_consolidar_tabela('controlid_push_eventos');
CALL mt_consolidar_tabela('crm_anexos');
CALL mt_consolidar_tabela('crm_interacoes');
CALL mt_consolidar_tabela('crm_relacionamentos');
CALL mt_consolidar_tabela('crm_sequencia');
CALL mt_consolidar_tabela('departamentos');
CALL mt_consolidar_tabela('dependentes');
CALL mt_consolidar_tabela('dispositivos_console');
CALL mt_consolidar_tabela('dispositivos_controlid');
CALL mt_consolidar_tabela('dispositivos_controlid_leituras');
CALL mt_consolidar_tabela('dispositivos_controlid_sync_log');
CALL mt_consolidar_tabela('dispositivos_seguranca');
CALL mt_consolidar_tabela('dispositivos_tablets');
CALL mt_consolidar_tabela('documentos');
CALL mt_consolidar_tabela('documentos_acessos');
CALL mt_consolidar_tabela('documentos_compartilhamentos');
CALL mt_consolidar_tabela('documentos_departamentos_migrado_bkp');
CALL mt_consolidar_tabela('documentos_grupos');
CALL mt_consolidar_tabela('documentos_grupos_moradores');
CALL mt_consolidar_tabela('documentos_grupos_usuarios');
CALL mt_consolidar_tabela('documentos_logs');
CALL mt_consolidar_tabela('documentos_pastas');
CALL mt_consolidar_tabela('documentos_tipos');
CALL mt_consolidar_tabela('email_alertas');
CALL mt_consolidar_tabela('email_delivery_logs');
CALL mt_consolidar_tabela('email_log');
CALL mt_consolidar_tabela('email_providers');
CALL mt_consolidar_tabela('email_templates');
CALL mt_consolidar_tabela('empresa');
CALL mt_consolidar_tabela('empresa_log');
CALL mt_consolidar_tabela('face_descriptors');
CALL mt_consolidar_tabela('fornecedores');
CALL mt_consolidar_tabela('grupos_inventario');
CALL mt_consolidar_tabela('hidrometro');
CALL mt_consolidar_tabela('hidrometros');
CALL mt_consolidar_tabela('hidrometros_historico');
CALL mt_consolidar_tabela('historico_importacoes_ofx');
CALL mt_consolidar_tabela('historico_pagamentos');
CALL mt_consolidar_tabela('historico_status_pedido');
CALL mt_consolidar_tabela('importacoes_financeiras');
CALL mt_consolidar_tabela('importacoes_financeiras_itens');
CALL mt_consolidar_tabela('inventario');
CALL mt_consolidar_tabela('lancamentos_agua');
CALL mt_consolidar_tabela('leituras');
CALL mt_consolidar_tabela('leituras_fotos');
CALL mt_consolidar_tabela('local_acessos');
CALL mt_consolidar_tabela('local_acessos_log');
CALL mt_consolidar_tabela('local_acessos_tipos');
CALL mt_consolidar_tabela('log_reset_senha');
CALL mt_consolidar_tabela('logs_acesso_qrcode');
CALL mt_consolidar_tabela('logs_erro');
CALL mt_consolidar_tabela('logs_financeiro');
CALL mt_consolidar_tabela('logs_sistema');
CALL mt_consolidar_tabela('logs_validacoes_dispositivo');
CALL mt_consolidar_tabela('manual_artigos');
CALL mt_consolidar_tabela('manual_avaliacoes');
CALL mt_consolidar_tabela('manual_buscas');
CALL mt_consolidar_tabela('manual_categorias');
CALL mt_consolidar_tabela('manual_favoritos');
CALL mt_consolidar_tabela('manual_historico');
CALL mt_consolidar_tabela('manual_modulos');
CALL mt_consolidar_tabela('marcas_dispositivo');
CALL mt_consolidar_tabela('media_avaliacoes_fornecedor');
CALL mt_consolidar_tabela('media_avaliacoes_produto');
CALL mt_consolidar_tabela('modelos_dispositivo');
CALL mt_consolidar_tabela('moradores');
CALL mt_consolidar_tabela('movimentacoes_bancarias');
CALL mt_consolidar_tabela('movimentacoes_estoque');
CALL mt_consolidar_tabela('notif_alertas');
CALL mt_consolidar_tabela('notif_destinatarios');
CALL mt_consolidar_tabela('notif_regras');
CALL mt_consolidar_tabela('notificacoes');
CALL mt_consolidar_tabela('notificacoes_downloads');
CALL mt_consolidar_tabela('notificacoes_visualizacoes');
CALL mt_consolidar_tabela('os_assuntos');
CALL mt_consolidar_tabela('os_chamados');
CALL mt_consolidar_tabela('os_config_homem_hora');
CALL mt_consolidar_tabela('os_etapas');
CALL mt_consolidar_tabela('os_interacao_fotos');
CALL mt_consolidar_tabela('os_interacoes');
CALL mt_consolidar_tabela('os_materiais_usados');
CALL mt_consolidar_tabela('os_recursos_humanos');
CALL mt_consolidar_tabela('pedidos');
CALL mt_consolidar_tabela('planos_contas');
CALL mt_consolidar_tabela('produtos_estoque');
CALL mt_consolidar_tabela('produtos_servicos');
CALL mt_consolidar_tabela('protocolos');
CALL mt_consolidar_tabela('pwa_configuracoes');
CALL mt_consolidar_tabela('pwa_logs');
CALL mt_consolidar_tabela('pwa_notificacoes_push');
CALL mt_consolidar_tabela('pwa_notificacoes_recebidas');
CALL mt_consolidar_tabela('pwa_versao');
CALL mt_consolidar_tabela('ramos_atividade');
CALL mt_consolidar_tabela('recebedores');
CALL mt_consolidar_tabela('registros_acesso');
CALL mt_consolidar_tabela('rh_banco_horas');
CALL mt_consolidar_tabela('rh_colaboradores');
CALL mt_consolidar_tabela('rh_escala');
CALL mt_consolidar_tabela('rh_ponto_lancamento');
CALL mt_consolidar_tabela('rh_ponto_periodo');
CALL mt_consolidar_tabela('tipos_dispositivo');
CALL mt_consolidar_tabela('unidades');
CALL mt_consolidar_tabela('usuario_modulos');
CALL mt_consolidar_tabela('validacoes_acesso');
CALL mt_consolidar_tabela('validacoes_face_id');
CALL mt_consolidar_tabela('veiculos');
CALL mt_consolidar_tabela('visitantes');

-- Mapeia a tabela exclusiva do legado para a estrutura atual de auditoria de documentos.
INSERT INTO `inlaud99_erpcondor`.`documentos_acessos`
  (`tenant_id`,`documento_id`,`tipo`,`origem`,`usuario_id`,`created_at`)
SELECT 1, s.documento_id, 'visualizacao', 'interno', s.usuario_id, s.created_at
FROM `inlaud99_erpserra`.`documentos_usuarios_acesso` s
WHERE NOT EXISTS (
  SELECT 1 FROM `inlaud99_erpcondor`.`documentos_acessos` d
  WHERE d.tenant_id=1
    AND d.documento_id=s.documento_id
    AND d.usuario_id=s.usuario_id
    AND d.tipo='visualizacao'
    AND d.created_at=s.created_at
);

INSERT INTO `mt_consolidacao_tabelas`
  (`tabela`,`tenant_id`,`registros_origem`,`registros_destino_antes`,`registros_destino_depois`,`status`,`mensagem`)
SELECT 'documentos_usuarios_acesso', 1,
       (SELECT COUNT(*) FROM `inlaud99_erpserra`.`documentos_usuarios_acesso`),
       0,
       (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`documentos_acessos` WHERE tenant_id=1),
       'concluida', 'Mapeado para documentos_acessos como visualizacao interna'
ON DUPLICATE KEY UPDATE registros_origem=VALUES(registros_origem), registros_destino_depois=VALUES(registros_destino_depois), status=VALUES(status), mensagem=VALUES(mensagem), executado_em=NOW();

-- Reconstrói vínculos usuário x tenant sem duplicar registros existentes.
INSERT IGNORE INTO `inlaud99_erpcondor`.`usuario_tenant` (`usuario_id`,`tenant_id`,`permissao`,`ativo`,`criado_em`)
SELECT u.id, 1,
       CASE WHEN u.permissao='super_admin' THEN 'admin' ELSE u.permissao END,
       u.ativo, NOW()
FROM `inlaud99_erpcondor`.`usuarios` u
WHERE u.tenant_id=1;

-- Sincroniza o cadastro mestre do tenant a partir do cadastro operacional consolidado.
UPDATE `inlaud99_erpcondor`.`tenants` t
INNER JOIN `inlaud99_erpcondor`.`empresa` e ON e.tenant_id=t.id
SET t.razao_social = e.razao_social,
    t.nome_fantasia = e.nome_fantasia,
    t.cnpj = e.cnpj,
    t.logo_url = e.logo_url,
    t.email_principal = e.email_principal,
    t.telefone = e.telefone,
    t.cidade = e.endereco_cidade,
    t.estado = e.endereco_estado,
    t.status = IF(e.situacao='ativo','ativo','suspenso'),
    t.data_atualizacao = NOW()
WHERE t.id=1;

DROP PROCEDURE `mt_consolidar_tabela`;
COMMIT;
SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

-- Verificação final: execute também o arquivo de auditoria entregue no pacote.
SELECT tabela, registros_origem, registros_destino_antes, registros_destino_depois, status, mensagem, executado_em
FROM `mt_consolidacao_tabelas`
WHERE tenant_id=1
ORDER BY tabela;

SELECT t.id, t.slug, t.nome_fantasia, t.status, t.cnpj,
       (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`usuarios` u WHERE u.tenant_id=t.id) AS usuarios,
       (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`moradores` m WHERE m.tenant_id=t.id) AS moradores,
       (SELECT COUNT(*) FROM `inlaud99_erpcondor`.`unidades` un WHERE un.tenant_id=t.id) AS unidades
FROM `inlaud99_erpcondor`.`tenants` t
WHERE t.id=1;
