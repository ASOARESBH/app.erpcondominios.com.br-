-- ERP CONDOMÍNIOS — Chat-In v2 — MySQL 8.4
-- Migration aditiva/idempotente. Não contém DROP de tabelas, DELETE ou limpeza de dados.
-- Execute somente após backup verificado e merge autorizado; nunca durante este PR.
-- Equivalente PostgreSQL: não existe diretório sql/postgresql nesta branch base; pendência registrada no PR/AI-CONTEXT.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Preflight e DDL condicional são encapsulados para manter reexecução segura.
-- Se o cliente parar após erro no CALL antes da última instrução, remova somente a rotina auxiliar com:
-- DROP PROCEDURE IF EXISTS `sp_erp_chatin_v2_20261114`;
DROP PROCEDURE IF EXISTS `sp_erp_chatin_v2_20261114`;
DELIMITER $$
CREATE PROCEDURE `sp_erp_chatin_v2_20261114`()
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_revisao_alterada INT DEFAULT 0;
    DECLARE v_permissoes_novas INT DEFAULT 0;
    DECLARE v_grupos_novos INT DEFAULT 0;
    DECLARE v_modulo_alterado INT DEFAULT 0;
    DECLARE v_push_ativo TINYINT DEFAULT 1;
    DECLARE v_push_tenant INT DEFAULT 0;
    DECLARE v_column_type TEXT DEFAULT '';
    DECLARE v_nullable VARCHAR(3) DEFAULT '';
    DECLARE v_default VARCHAR(64) DEFAULT NULL;
    DECLARE v_sql TEXT DEFAULT '';

    -- Falha antes de alterações no schema se a base Chat-In/RBAC esperada estiver incompleta.
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (
       'tenants','chatin_conversas','chatin_mensagens','chatin_preferencias','chatin_presenca',
       'rbac_modulos','rbac_permissoes','rbac_grupos','rbac_grupo_permissoes','rbac_configuracoes','rbac_auditoria'
     ) AND TABLE_TYPE = 'BASE TABLE';
    IF v_count <> 11 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: tabelas base/tenant/RBAC ausentes; nenhuma alteração foi aplicada.';
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_configuracoes' AND COLUMN_NAME = 'revisao_permissoes';
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: revisao_permissoes ausente em rbac_configuracoes.';
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_configuracoes' AND COLUMN_NAME IN ('tenant_id','revisao_permissoes');
    IF v_count <> 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: colunas base de rbac_configuracoes incompletas.'; END IF;
    SELECT COUNT(*) INTO v_count FROM (
      SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_configuracoes' AND NON_UNIQUE = 0
      GROUP BY INDEX_NAME HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME = 'tenant_id') = 1
    ) AS uq_rbac_config_tenant;
    IF v_count = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: rbac_configuracoes precisa de chave única tenant_id.'; END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_modulos'
       AND COLUMN_NAME IN ('id','chave','nome','pagina','grupo','tipo','icone','descricao','perfil_compatibilidade','ordem','ativo');
    IF v_count <> 11 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: colunas base de rbac_modulos incompletas.'; END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_permissoes'
       AND COLUMN_NAME IN ('id','modulo_id','acao','nome','descricao','ativo');
    IF v_count <> 6 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: colunas base de rbac_permissoes incompletas.'; END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_grupos' AND COLUMN_NAME IN ('id','tenant_id');
    IF v_count <> 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: colunas base de rbac_grupos incompletas.'; END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_grupo_permissoes'
       AND COLUMN_NAME IN ('id','grupo_id','permissao_id','efeito','escopo_dados');
    IF v_count <> 5 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: colunas base de rbac_grupo_permissoes incompletas.'; END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_auditoria'
       AND COLUMN_NAME IN ('evento_uuid','tenant_id','usuario_id','usuario_nome','grupo_nome','sessao_id','modulo_chave','submodulo_chave',
         'acao','origem','metodo_http','endpoint','registro_tipo','registro_id','resultado','status_http','ip','user_agent',
         'dados_antes','dados_depois','motivo','hash_anterior','hash_evento','ocorrido_em');
    IF v_count <> 24 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: estrutura de rbac_auditoria incompleta.';
    END IF;

    -- Pré-validação completa das estruturas e chaves antes de qualquer ALTER/CREATE de aplicação.
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias'
       AND COLUMN_NAME IN ('tenant_id','participante_tipo','participante_id','visivel_busca','mostrar_online','notificar_push');
    IF v_count <> 6 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: colunas base de chatin_preferencias incompletas.';
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'som_ativo'
       AND DATA_TYPE = 'tinyint' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT = '1';
    IF v_count = 0 AND EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'som_ativo')
        THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: chatin_preferencias.som_ativo possui definição divergente.'; END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'som_tipo'
       AND DATA_TYPE = 'varchar' AND CHARACTER_MAXIMUM_LENGTH = 20 AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IN ('classico', '''classico''');
    IF v_count = 0 AND EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'som_tipo')
        THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: chatin_preferencias.som_tipo possui definição divergente.'; END IF;
    IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'som_tipo') THEN
        SELECT COUNT(*) INTO v_count FROM `chatin_preferencias` WHERE `som_tipo` NOT IN ('classico','campainha','suave');
        IF v_count > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: existem valores de som_tipo fora do catálogo permitido.'; END IF;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'notificacao_navegador'
       AND DATA_TYPE = 'tinyint' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT = '0';
    IF v_count = 0 AND EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'notificacao_navegador')
        THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: chatin_preferencias.notificacao_navegador possui definição divergente.'; END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'permitir_cutucar'
       AND DATA_TYPE = 'tinyint' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT = '1';
    IF v_count = 0 AND EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'permitir_cutucar')
        THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: chatin_preferencias.permitir_cutucar possui definição divergente.'; END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'som_volume';
    IF v_count = 1 THEN
        SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'som_volume'
           AND DATA_TYPE = 'tinyint' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT = '70';
        IF v_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: chatin_preferencias.som_volume precisa ser TINYINT UNSIGNED NOT NULL.';
        END IF;
        SELECT COUNT(*) INTO v_count FROM `chatin_preferencias` WHERE `som_volume` > 100;
        IF v_count > 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: existem valores de som_volume fora da faixa 0..100.';
        END IF;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias'
       AND CONSTRAINT_NAME = 'chk_chatin_som_volume' AND CONSTRAINT_TYPE = 'CHECK';
    IF v_count = 1 THEN
        SELECT COUNT(*) INTO v_count
          FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc
          JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
         WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'chatin_preferencias'
           AND tc.CONSTRAINT_NAME = 'chk_chatin_som_volume'
           AND REPLACE(REPLACE(REPLACE(LOWER(cc.CHECK_CLAUSE), '`', ''), ' ', ''), '(', '') LIKE '%som_volumebetween0and100%';
        IF v_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: chk_chatin_som_volume existe com definição divergente.';
        END IF;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias'
       AND CONSTRAINT_NAME = 'chk_chatin_som_tipo' AND CONSTRAINT_TYPE = 'CHECK';
    IF v_count = 1 THEN
        SELECT COUNT(*) INTO v_count
          FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc
          JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
         WHERE tc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'chatin_preferencias'
           AND tc.CONSTRAINT_NAME = 'chk_chatin_som_tipo'
           AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(cc.CHECK_CLAUSE), '`', ''), ' ', ''), '(', ''), ')', '') LIKE '%som_tipoin''classico'',''campainha'',''suave''%';
        IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: chk_chatin_som_tipo existe com definição divergente.'; END IF;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca'
       AND COLUMN_NAME IN ('tenant_id','participante_tipo','participante_id','ultimo_ping','plataforma','conversa_aberta_id');
    IF v_count <> 6 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: colunas base de chatin_presenca incompletas.';
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca' AND COLUMN_NAME = 'digitando_conversa_id';
    IF v_count = 1 THEN
        SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca' AND COLUMN_NAME = 'digitando_conversa_id'
           AND DATA_TYPE = 'bigint' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE = 'YES'
           AND COALESCE(LOWER(COLUMN_DEFAULT), 'null') = 'null';
        IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: digitando_conversa_id possui definição divergente.'; END IF;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca' AND COLUMN_NAME = 'digitando_ate';
    IF v_count = 1 THEN
        SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca' AND COLUMN_NAME = 'digitando_ate'
           AND DATA_TYPE = 'datetime' AND IS_NULLABLE = 'YES'
           AND COALESCE(LOWER(COLUMN_DEFAULT), 'null') = 'null';
        IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: digitando_ate possui definição divergente.'; END IF;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca' AND INDEX_NAME = 'idx_chatin_presenca_digitando';
    IF v_count > 0 THEN
        SELECT COUNT(*) INTO v_count FROM (
          SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca' AND INDEX_NAME = 'idx_chatin_presenca_digitando'
           GROUP BY INDEX_NAME HAVING COUNT(*) = 3
             AND SUM(CASE WHEN SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'tenant_id' THEN 1 ELSE 0 END) = 1
             AND SUM(CASE WHEN SEQ_IN_INDEX = 2 AND COLUMN_NAME = 'digitando_conversa_id' THEN 1 ELSE 0 END) = 1
             AND SUM(CASE WHEN SEQ_IN_INDEX = 3 AND COLUMN_NAME = 'digitando_ate' THEN 1 ELSE 0 END) = 1
        ) AS ix_chatin_digitando_preflight;
        IF v_count = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: idx_chatin_presenca_digitando existe com definição divergente.'; END IF;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_mensagens'
       AND COLUMN_NAME IN ('tenant_id','conversa_id','remetente_tipo','remetente_id','remetente_nome','tipo','corpo','titulo','referencia_tipo','referencia_id','idempotency_key','criado_em');
    IF v_count <> 12 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: colunas base de chatin_mensagens incompletas.';
    END IF;
    SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT INTO v_column_type, v_nullable, v_default
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_mensagens' AND COLUMN_NAME = 'tipo' LIMIT 1;
    IF v_column_type IS NULL OR UPPER(REPLACE(v_column_type, ' ', '')) NOT IN ('ENUM(''TEXTO'',''AVISO'')','ENUM(''TEXTO'',''AVISO'',''CUTUCAR'')')
       OR v_nullable <> 'NO' OR COALESCE(v_default, '') NOT IN ('TEXTO', '''TEXTO''') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: definição, nulabilidade ou default de chatin_mensagens.tipo diverge do schema validado.';
    END IF;

    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_configuracoes' AND TABLE_TYPE = 'BASE TABLE';
    IF v_count = 1 THEN
        SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_configuracoes'
           AND COLUMN_NAME IN ('tenant_id','ativo','usuario_usuario','usuario_morador','morador_inicia_com_equipe','morador_morador',
             'recurso_sons','recurso_cutucar','recurso_digitando','recurso_emojis','recurso_push_app',
             'grupos_visiveis_moradores_json','filtro_moradores_json','atualizado_por','atualizado_em');
        IF v_count <> 15 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: chatin_configuracoes existente possui estrutura divergente.';
        END IF;
        SELECT COUNT(*) INTO v_count FROM (
          SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_configuracoes' AND INDEX_NAME = 'PRIMARY'
           GROUP BY INDEX_NAME HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME = 'tenant_id') = 1
        ) AS pk_chatin_config;
        IF v_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: chatin_configuracoes precisa de PRIMARY KEY(tenant_id).';
        END IF;
    END IF;

    -- Índices únicos usados por upserts e inserts idempotentes.
    SELECT COUNT(*) INTO v_count FROM (
      SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND NON_UNIQUE = 0
       GROUP BY INDEX_NAME HAVING COUNT(*) = 3
         AND SUM(CASE WHEN SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'tenant_id' THEN 1 ELSE 0 END) = 1
         AND SUM(CASE WHEN SEQ_IN_INDEX = 2 AND COLUMN_NAME = 'participante_tipo' THEN 1 ELSE 0 END) = 1
         AND SUM(CASE WHEN SEQ_IN_INDEX = 3 AND COLUMN_NAME = 'participante_id' THEN 1 ELSE 0 END) = 1
    ) AS uk_chat_pref;
    IF v_count = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: falta chave única tenant/participante em chatin_preferencias.'; END IF;
    SELECT COUNT(*) INTO v_count FROM (
      SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca' AND NON_UNIQUE = 0
       GROUP BY INDEX_NAME HAVING COUNT(*) = 3
         AND SUM(CASE WHEN SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'tenant_id' THEN 1 ELSE 0 END) = 1
         AND SUM(CASE WHEN SEQ_IN_INDEX = 2 AND COLUMN_NAME = 'participante_tipo' THEN 1 ELSE 0 END) = 1
         AND SUM(CASE WHEN SEQ_IN_INDEX = 3 AND COLUMN_NAME = 'participante_id' THEN 1 ELSE 0 END) = 1
    ) AS uk_chat_presence;
    IF v_count = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: falta chave única tenant/participante em chatin_presenca.'; END IF;
    SELECT COUNT(*) INTO v_count FROM (
      SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_modulos' AND NON_UNIQUE = 0
       GROUP BY INDEX_NAME HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME = 'chave') = 1
    ) AS uk_rbac_modulo;
    IF v_count = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: falta unicidade de rbac_modulos.chave.'; END IF;
    SELECT COUNT(*) INTO v_count FROM (
      SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_permissoes' AND NON_UNIQUE = 0
       GROUP BY INDEX_NAME HAVING COUNT(*) = 2
         AND SUM(CASE WHEN SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'modulo_id' THEN 1 ELSE 0 END) = 1
         AND SUM(CASE WHEN SEQ_IN_INDEX = 2 AND COLUMN_NAME = 'acao' THEN 1 ELSE 0 END) = 1
    ) AS uk_rbac_perm;
    IF v_count = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: falta unicidade modulo_id/acao em rbac_permissoes.'; END IF;
    SELECT COUNT(*) INTO v_count FROM (
      SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rbac_grupo_permissoes' AND NON_UNIQUE = 0
       GROUP BY INDEX_NAME HAVING COUNT(*) = 2
         AND SUM(CASE WHEN SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'grupo_id' THEN 1 ELSE 0 END) = 1
         AND SUM(CASE WHEN SEQ_IN_INDEX = 2 AND COLUMN_NAME = 'permissao_id' THEN 1 ELSE 0 END) = 1
    ) AS uk_rbac_grupo_perm;
    IF v_count = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: falta unicidade grupo_id/permissao_id em rbac_grupo_permissoes.'; END IF;

    -- Validar a configuração push antes da primeira alteração da aplicação.
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pwa_configuracoes' AND TABLE_TYPE = 'BASE TABLE';
    IF v_count > 0 THEN
        SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pwa_configuracoes' AND COLUMN_NAME IN ('chave','valor');
        IF v_count <> 2 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: pwa_configuracoes presente sem colunas chave/valor.';
        END IF;
        SELECT COUNT(*) INTO v_push_tenant FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pwa_configuracoes' AND COLUMN_NAME = 'tenant_id';
        IF v_push_tenant = 1 THEN
            SELECT COUNT(*) INTO v_count FROM (
              SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pwa_configuracoes' AND NON_UNIQUE = 0 AND SUB_PART IS NULL
               GROUP BY INDEX_NAME
               HAVING COUNT(*) = 2
                  AND SUM(CASE WHEN SEQ_IN_INDEX = 1 AND COLUMN_NAME = 'tenant_id' THEN 1 ELSE 0 END) = 1
                  AND SUM(CASE WHEN SEQ_IN_INDEX = 2 AND COLUMN_NAME = 'chave' THEN 1 ELSE 0 END) = 1
            ) AS uq_tenant_chave;
            IF v_count = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: pwa_configuracoes não garante unicidade completa tenant_id+chave.';
            END IF;
        ELSE
            SELECT COUNT(*) INTO v_count FROM (
              SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pwa_configuracoes' AND NON_UNIQUE = 0 AND SUB_PART IS NULL
               GROUP BY INDEX_NAME HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME = 'chave') = 1
            ) AS uq_global_chave;
            IF v_count = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: chave PWA global não é única.';
            END IF;
        END IF;
    END IF;

    -- Preferências de participante (painel e portal).
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'som_ativo';
    IF v_count = 0 THEN
        SET v_sql = 'ALTER TABLE `chatin_preferencias` ADD COLUMN `som_ativo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notificar_push`';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'som_tipo';
    IF v_count = 0 THEN
        SET v_sql = 'ALTER TABLE `chatin_preferencias` ADD COLUMN `som_tipo` VARCHAR(20) NOT NULL DEFAULT ''classico'' AFTER `som_ativo`';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias'
       AND CONSTRAINT_NAME = 'chk_chatin_som_tipo' AND CONSTRAINT_TYPE = 'CHECK';
    IF v_count = 0 THEN
        SET v_sql = 'ALTER TABLE `chatin_preferencias` ADD CONSTRAINT `chk_chatin_som_tipo` CHECK (`som_tipo` IN (''classico'',''campainha'',''suave''))';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'som_volume';
    IF v_count = 0 THEN
        SET v_sql = 'ALTER TABLE `chatin_preferencias` ADD COLUMN `som_volume` TINYINT UNSIGNED NOT NULL DEFAULT 70 AFTER `som_tipo`';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias'
       AND CONSTRAINT_NAME = 'chk_chatin_som_volume' AND CONSTRAINT_TYPE = 'CHECK';
    IF v_count = 0 THEN
        SET v_sql = 'ALTER TABLE `chatin_preferencias` ADD CONSTRAINT `chk_chatin_som_volume` CHECK (`som_volume` BETWEEN 0 AND 100)';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'notificacao_navegador';
    IF v_count = 0 THEN
        SET v_sql = 'ALTER TABLE `chatin_preferencias` ADD COLUMN `notificacao_navegador` TINYINT(1) NOT NULL DEFAULT 0 AFTER `som_volume`';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias' AND COLUMN_NAME = 'permitir_cutucar';
    IF v_count = 0 THEN
        SET v_sql = 'ALTER TABLE `chatin_preferencias` ADD COLUMN `permitir_cutucar` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notificacao_navegador`';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;

    -- Presença efêmera: digitando expira automaticamente após 6 segundos.
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca' AND COLUMN_NAME = 'digitando_conversa_id';
    IF v_count = 0 THEN
        SET v_sql = 'ALTER TABLE `chatin_presenca` ADD COLUMN `digitando_conversa_id` BIGINT UNSIGNED DEFAULT NULL AFTER `conversa_aberta_id`';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca' AND COLUMN_NAME = 'digitando_ate';
    IF v_count = 0 THEN
        SET v_sql = 'ALTER TABLE `chatin_presenca` ADD COLUMN `digitando_ate` DATETIME DEFAULT NULL AFTER `digitando_conversa_id`';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
    SELECT COUNT(*) INTO v_count FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca' AND INDEX_NAME = 'idx_chatin_presenca_digitando';
    IF v_count = 0 THEN
        SET v_sql = 'ALTER TABLE `chatin_presenca` ADD KEY `idx_chatin_presenca_digitando` (`tenant_id`,`digitando_conversa_id`,`digitando_ate`)';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;

    -- Preservar exatamente os dois membros históricos do ENUM; parar se houver variante não revisada.
    SELECT COLUMN_TYPE INTO v_column_type FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_mensagens' AND COLUMN_NAME = 'tipo' LIMIT 1;
    IF v_column_type IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: chatin_mensagens.tipo ausente.';
    END IF;
    IF UPPER(REPLACE(v_column_type, ' ', '')) = 'ENUM(''TEXTO'',''AVISO'')' THEN
        SET v_sql = 'ALTER TABLE `chatin_mensagens` MODIFY COLUMN `tipo` ENUM(''TEXTO'',''AVISO'',''CUTUCAR'') NOT NULL DEFAULT ''TEXTO''';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    ELSEIF UPPER(REPLACE(v_column_type, ' ', '')) <> 'ENUM(''TEXTO'',''AVISO'',''CUTUCAR'')' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preflight Chat-In v2: ENUM chatin_mensagens.tipo não corresponde ao schema validado.';
    END IF;

    -- Configuração tenant-scoped. JSON em texto para manter compatibilidade MySQL 8.4 e payload limitado.
    CREATE TABLE IF NOT EXISTS `chatin_configuracoes` (
      `tenant_id` INT(11) NOT NULL,
      `ativo` TINYINT(1) NOT NULL DEFAULT 1,
      `usuario_usuario` TINYINT(1) NOT NULL DEFAULT 1,
      `usuario_morador` TINYINT(1) NOT NULL DEFAULT 1,
      `morador_inicia_com_equipe` TINYINT(1) NOT NULL DEFAULT 1,
      `morador_morador` TINYINT(1) NOT NULL DEFAULT 1,
      `recurso_sons` TINYINT(1) NOT NULL DEFAULT 1,
      `recurso_cutucar` TINYINT(1) NOT NULL DEFAULT 1,
      `recurso_digitando` TINYINT(1) NOT NULL DEFAULT 1,
      `recurso_emojis` TINYINT(1) NOT NULL DEFAULT 1,
      `recurso_push_app` TINYINT(1) NOT NULL DEFAULT 1,
      `grupos_visiveis_moradores_json` MEDIUMTEXT NOT NULL,
      `filtro_moradores_json` MEDIUMTEXT NOT NULL,
      `atualizado_por` INT(11) DEFAULT NULL,
      `atualizado_em` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`tenant_id`),
      KEY `idx_chatin_config_ativo` (`tenant_id`,`ativo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Inserção inicial; todos os ramos/falhas de schema já foram validados acima.
    IF v_push_tenant = 1 THEN
        SET v_sql = 'INSERT IGNORE INTO `chatin_configuracoes` (`tenant_id`,`ativo`,`usuario_usuario`,`usuario_morador`,`morador_inicia_com_equipe`,`morador_morador`,`recurso_sons`,`recurso_cutucar`,`recurso_digitando`,`recurso_emojis`,`recurso_push_app`,`grupos_visiveis_moradores_json`,`filtro_moradores_json`) SELECT t.`id`,1,1,1,1,1,1,1,1,1,COALESCE(CASE WHEN LOWER(COALESCE(TRIM(p.`valor`),''1'')) IN (''1'',''true'',''on'',''sim'') THEN 1 ELSE 0 END,1),''[]'',''[]'' FROM `tenants` t LEFT JOIN `pwa_configuracoes` p ON p.`tenant_id`=t.`id` AND p.`chave`=''push_chatin_ativo''';
        SET @chatin_v2_sql = v_sql; PREPARE s FROM @chatin_v2_sql; EXECUTE s; DEALLOCATE PREPARE s;
    ELSE
        SELECT CASE
                 WHEN LOWER(COALESCE(TRIM(`valor`), '1')) IN ('1','true','on','sim') THEN 1
                 ELSE 0
               END
          INTO v_push_ativo
          FROM `pwa_configuracoes`
         WHERE `chave` = 'push_chatin_ativo'
         LIMIT 1;
        INSERT IGNORE INTO `chatin_configuracoes`
          (`tenant_id`,`ativo`,`usuario_usuario`,`usuario_morador`,`morador_inicia_com_equipe`,`morador_morador`,
           `recurso_sons`,`recurso_cutucar`,`recurso_digitando`,`recurso_emojis`,`recurso_push_app`,
           `grupos_visiveis_moradores_json`,`filtro_moradores_json`)
        SELECT `id`,1,1,1,1,1,1,1,1,1,v_push_ativo,'[]','[]' FROM `tenants`;
    END IF;

    -- Módulo RBAC e ações específicas; a tabela de permissões permanece a única fonte de decisão.
    INSERT INTO `rbac_modulos`
      (`chave`,`nome`,`pagina`,`grupo`,`tipo`,`icone`,`descricao`,`perfil_compatibilidade`,`ordem`,`ativo`)
    VALUES
      ('chatin','Chat-In','chatin','Sistema','MODULO','fas fa-comments','Comunicação interna entre equipe e moradores','operador',1013,1)
    ON DUPLICATE KEY UPDATE
      `nome`=VALUES(`nome`),`pagina`=VALUES(`pagina`),`grupo`=VALUES(`grupo`),`tipo`=VALUES(`tipo`),
      `icone`=VALUES(`icone`),`descricao`=VALUES(`descricao`),`perfil_compatibilidade`=VALUES(`perfil_compatibilidade`),`ordem`=VALUES(`ordem`),`ativo`=1;
    SET v_modulo_alterado = ROW_COUNT();

    INSERT INTO `rbac_permissoes` (`modulo_id`,`acao`,`nome`,`descricao`)
    SELECT m.`id`, a.`acao`, a.`nome`, a.`descricao`
      FROM `rbac_modulos` m
      JOIN (
        SELECT 'visualizar' AS `acao`, 'Visualizar' AS `nome`, 'Usar o Chat-In neste condomínio' AS `descricao`
        UNION ALL SELECT 'configurar','Configurar','Alterar a configuração tenant-scoped do Chat-In'
      ) a
     WHERE m.`chave`='chatin'
    ON DUPLICATE KEY UPDATE `nome`=VALUES(`nome`),`descricao`=VALUES(`descricao`),`ativo`=1;
    SET v_permissoes_novas = ROW_COUNT();

    -- A concessão inicial é somente visualizar e alcança todos os grupos existentes de todos os tenants.
    -- INSERT IGNORE preserva qualquer permissão/NEGAR previamente explícita.
    INSERT IGNORE INTO `rbac_grupo_permissoes` (`grupo_id`,`permissao_id`,`efeito`,`escopo_dados`)
    SELECT g.`id`, p.`id`, 'PERMITIR', 'GLOBAL'
      FROM `rbac_grupos` g
      JOIN `rbac_permissoes` p ON p.`acao`='visualizar'
      JOIN `rbac_modulos` m ON m.`id`=p.`modulo_id` AND m.`chave`='chatin'
     WHERE g.`tenant_id` IS NOT NULL;
    SET v_grupos_novos = ROW_COUNT();

    -- Invalidar caches somente quando a migration realmente añadió um módulo/permiso/grant.
    IF v_modulo_alterado > 0 OR v_permissoes_novas > 0 OR v_grupos_novos > 0 THEN
        UPDATE `rbac_configuracoes`
           SET `revisao_permissoes` = `revisao_permissoes` + 1;
    END IF;
END$$
DELIMITER ;

CALL `sp_erp_chatin_v2_20261114`();
DROP PROCEDURE IF EXISTS `sp_erp_chatin_v2_20261114`;
