-- REVIEW ARTIFACT: DO NOT APPLY until report.json has executable_complete=true and required reviews are approved.
-- Generator code SHA-256: 6681924b7a259c8b048c739ef2e30ea124cbda787061d09cf62ad5f41b865a58
-- Collation overrides SHA-256: 44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a

-- Generator version: 1.3.0

-- Catalog SHA-256: 9112484a2fd7ed128a51f4b39e167f6e025d6560ab78db24d341fc52e666c7f4

CREATE SCHEMA "erp";

SET search_path TO "erp", pg_catalog;

CREATE COLLATION "erp"."ci" (provider = icu, locale = 'und-u-ks-level1', deterministic = false);

CREATE TABLE "erp"."_p2_autoincrement_state" (sequence_name TEXT PRIMARY KEY, low_sequence REGCLASS, high_sequence REGCLASS, post_load_sync_complete BOOLEAN NOT NULL DEFAULT FALSE);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('abastecimento_lancamentos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('abastecimento_recargas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('abastecimento_saldo.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('abastecimento_veiculos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('administradoras_importacao.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('administradoras_layouts.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_acesso.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_acesso_criterios.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_acesso_entregas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_acesso_eventos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_acesso_leituras.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_estoque.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_alertas_unidade_acionamentos_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_alertas_unidade_acionamentos_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_alertas_unidade_acionamentos_id", "erp"."p2_u64_hi_alertas_unidade_acionamentos_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_unidade_acionamentos.id', '"erp"."p2_u64_lo_alertas_unidade_acionamentos_id"'::regclass, '"erp"."p2_u64_hi_alertas_unidade_acionamentos_id"'::regclass);

CREATE SEQUENCE "erp"."p2_u64_lo_alertas_unidade_alvos_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_alertas_unidade_alvos_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_alertas_unidade_alvos_id", "erp"."p2_u64_hi_alertas_unidade_alvos_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_unidade_alvos.id', '"erp"."p2_u64_lo_alertas_unidade_alvos_id"'::regclass, '"erp"."p2_u64_hi_alertas_unidade_alvos_id"'::regclass);

CREATE SEQUENCE "erp"."p2_u64_lo_alertas_unidade_ciencias_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_alertas_unidade_ciencias_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_alertas_unidade_ciencias_id", "erp"."p2_u64_hi_alertas_unidade_ciencias_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_unidade_ciencias.id', '"erp"."p2_u64_lo_alertas_unidade_ciencias_id"'::regclass, '"erp"."p2_u64_hi_alertas_unidade_ciencias_id"'::regclass);

CREATE SEQUENCE "erp"."p2_u64_lo_alertas_unidade_historico_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_alertas_unidade_historico_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_alertas_unidade_historico_id", "erp"."p2_u64_hi_alertas_unidade_historico_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_unidade_historico.id', '"erp"."p2_u64_lo_alertas_unidade_historico_id"'::regclass, '"erp"."p2_u64_hi_alertas_unidade_historico_id"'::regclass);

CREATE SEQUENCE "erp"."p2_u64_lo_alertas_unidade_lembretes_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_alertas_unidade_lembretes_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_alertas_unidade_lembretes_id", "erp"."p2_u64_hi_alertas_unidade_lembretes_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_unidade_lembretes.id', '"erp"."p2_u64_lo_alertas_unidade_lembretes_id"'::regclass, '"erp"."p2_u64_hi_alertas_unidade_lembretes_id"'::regclass);

CREATE SEQUENCE "erp"."p2_u64_lo_alertas_unidade_notificacoes_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_alertas_unidade_notificacoes_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_alertas_unidade_notificacoes_id", "erp"."p2_u64_hi_alertas_unidade_notificacoes_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_unidade_notificacoes.id', '"erp"."p2_u64_lo_alertas_unidade_notificacoes_id"'::regclass, '"erp"."p2_u64_hi_alertas_unidade_notificacoes_id"'::regclass);

CREATE SEQUENCE "erp"."p2_u64_lo_alertas_unidade_rate_limits_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_alertas_unidade_rate_limits_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_alertas_unidade_rate_limits_id", "erp"."p2_u64_hi_alertas_unidade_rate_limits_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('alertas_unidade_rate_limits.id', '"erp"."p2_u64_lo_alertas_unidade_rate_limits_id"'::regclass, '"erp"."p2_u64_hi_alertas_unidade_rate_limits_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('aplicativos_catalogo.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('aplicativos_versionamento_log.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('aplicativos_versoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('assembleias.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('avaliacoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('avaliacoes_backup.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('bancos_brasileiros.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('bridge_eventos_log.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('bridge_fila_comandos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('bridge_status.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('categorias_estoque.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_chatin_conversas_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_chatin_conversas_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_chatin_conversas_id", "erp"."p2_u64_hi_chatin_conversas_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('chatin_conversas.id', '"erp"."p2_u64_lo_chatin_conversas_id"'::regclass, '"erp"."p2_u64_hi_chatin_conversas_id"'::regclass);

CREATE SEQUENCE "erp"."p2_u64_lo_chatin_mensagens_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_chatin_mensagens_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_chatin_mensagens_id", "erp"."p2_u64_hi_chatin_mensagens_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('chatin_mensagens.id', '"erp"."p2_u64_lo_chatin_mensagens_id"'::regclass, '"erp"."p2_u64_hi_chatin_mensagens_id"'::regclass);

CREATE SEQUENCE "erp"."p2_u64_lo_chatin_participantes_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_chatin_participantes_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_chatin_participantes_id", "erp"."p2_u64_hi_chatin_participantes_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('chatin_participantes.id', '"erp"."p2_u64_lo_chatin_participantes_id"'::regclass, '"erp"."p2_u64_hi_chatin_participantes_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('checklist_alertas_config.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('checklist_alertas_gerados.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('checklist_itens.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('checklist_km_acumulado.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('checklist_veicular.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_conciliacoes_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_conciliacoes_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_conciliacoes_id", "erp"."p2_u64_hi_conciliacoes_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('conciliacoes.id', '"erp"."p2_u64_lo_conciliacoes_id"'::regclass, '"erp"."p2_u64_hi_conciliacoes_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('config_moradores_campos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('config_moradores_opcoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('config_periodo_leitura.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('config_sessao.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('config_visitantes_campos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('configuracoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('contas_bancarias.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('contas_pagar.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('contas_receber.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('contrato_aditivos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('contrato_documentos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('contrato_orcamento_documentos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('contrato_orcamentos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('contratos.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_controlid_eventos_acesso_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_controlid_eventos_acesso_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_controlid_eventos_acesso_id", "erp"."p2_u64_hi_controlid_eventos_acesso_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('controlid_eventos_acesso.id', '"erp"."p2_u64_lo_controlid_eventos_acesso_id"'::regclass, '"erp"."p2_u64_hi_controlid_eventos_acesso_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('controlid_fila_comandos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('controlid_push_queue.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('convidados.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('crm_interacoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('crm_relacionamentos.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_dashboard_empresa_widgets_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_dashboard_empresa_widgets_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_dashboard_empresa_widgets_id", "erp"."p2_u64_hi_dashboard_empresa_widgets_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('dashboard_empresa_widgets.id', '"erp"."p2_u64_lo_dashboard_empresa_widgets_id"'::regclass, '"erp"."p2_u64_hi_dashboard_empresa_widgets_id"'::regclass);

CREATE SEQUENCE "erp"."p2_u64_lo_dashboard_usuario_widgets_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_dashboard_usuario_widgets_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_dashboard_usuario_widgets_id", "erp"."p2_u64_hi_dashboard_usuario_widgets_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('dashboard_usuario_widgets.id', '"erp"."p2_u64_lo_dashboard_usuario_widgets_id"'::regclass, '"erp"."p2_u64_hi_dashboard_usuario_widgets_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('dashboard_widgets_catalogo.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('departamentos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('dependentes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('dispositivos_controlid_leituras.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('dispositivos_controlid_sync_log.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('documentos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('documentos_departamentos_migrado_bkp.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('documentos_grupos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('documentos_grupos_moradores.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('documentos_grupos_usuarios.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('documentos_logs.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('documentos_pastas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('documentos_tipos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('documentos_usuarios_acesso.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('email_alertas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('email_delivery_logs.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('email_log.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('email_templates.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('empresa.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('empresa_administradora.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('empresa_administradora_layout.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('eventos_condominio.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('grupos_inventario.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('hidrometro.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('hidrometros.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('hidrometros_historico.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('historico_importacoes_ofx.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('historico_pagamentos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('historico_status_pedido.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('importacoes_financeiras.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('importacoes_financeiras_itens.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('inadimplencia_comparacoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('inadimplencia_importacoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('inadimplencia_lancamentos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('inventario.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('leituras.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('local_acessos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('local_acessos_log.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('local_acessos_tipos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('logs_erro.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('logs_financeiro.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('manual_artigos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('manual_avaliacoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('manual_buscas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('manual_categorias.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('manual_historico.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('manual_modulos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('marcas_dispositivo.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('modelos_dispositivo.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('modulos_sistema.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('monitoramento_cameras.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('monitoramento_configuracoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('monitoramento_dispositivos_local.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('monitoramento_evento_acesso.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('monitoramento_evento_revisoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('monitoramento_eventos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('monitoring_releases.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('monitoring_update_log.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_movimentacoes_bancarias_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_movimentacoes_bancarias_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_movimentacoes_bancarias_id", "erp"."p2_u64_hi_movimentacoes_bancarias_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('movimentacoes_bancarias.id', '"erp"."p2_u64_lo_movimentacoes_bancarias_id"'::regclass, '"erp"."p2_u64_hi_movimentacoes_bancarias_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('movimentacoes_estoque.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('mt_consolidacao_exclusoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('mt_consolidacao_tabelas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('mt_integridade_ids_log.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('mt_os_integridade_log.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('mt_reconstrucao_exclusoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('mt_reconstrucao_tabelas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('notif_regras.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('notificacoes_downloads.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('notificacoes_morador.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('notificacoes_visualizacoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_assuntos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_chamados.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_config_homem_hora.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_etapas.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_os_integridade_interacoes_log_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_os_integridade_interacoes_log_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_os_integridade_interacoes_log_id", "erp"."p2_u64_hi_os_integridade_interacoes_log_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_integridade_interacoes_log.id', '"erp"."p2_u64_lo_os_integridade_interacoes_log_id"'::regclass, '"erp"."p2_u64_hi_os_integridade_interacoes_log_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_interacao_fotos.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_os_interacao_fotos_quarentena_backup_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_os_interacao_fotos_quarentena_backup_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_os_interacao_fotos_quarentena_backup_id", "erp"."p2_u64_hi_os_interacao_fotos_quarentena_backup_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_interacao_fotos_quarentena.backup_id', '"erp"."p2_u64_lo_os_interacao_fotos_quarentena_backup_id"'::regclass, '"erp"."p2_u64_hi_os_interacao_fotos_quarentena_backup_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_interacoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_materiais_usados.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_permissoes_departamento.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_recursos_humanos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('os_usuarios_config.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('pedidos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('planos_contas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('produtos_estoque.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('produtos_servicos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('protocolos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('publico_rate_limit.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('pwa_logs.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('pwa_notificacoes_push.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('pwa_notificacoes_recebidas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('pwa_versao.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('ramos_atividade.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('rbac_grupo_permissoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('rbac_grupos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('rbac_modulos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('rbac_permissoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('rbac_politicas_api.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('rbac_usuario_grupos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('rbac_usuario_permissoes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('recebedores.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('rh_banco_horas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('rh_escala.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('rh_ponto_lancamento.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('rh_ponto_periodo.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_ronda_auditoria_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_ronda_auditoria_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_ronda_auditoria_id", "erp"."p2_u64_hi_ronda_auditoria_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('ronda_auditoria.id', '"erp"."p2_u64_lo_ronda_auditoria_id"'::regclass, '"erp"."p2_u64_hi_ronda_auditoria_id"'::regclass);

CREATE SEQUENCE "erp"."p2_u64_lo_ronda_registros_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_ronda_registros_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_ronda_registros_id", "erp"."p2_u64_hi_ronda_registros_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('ronda_registros.id', '"erp"."p2_u64_lo_ronda_registros_id"'::regclass, '"erp"."p2_u64_hi_ronda_registros_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('ronda_rotas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('ronda_vigilantes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('sessoes_usuarios.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_tenant_arquivo_referencias_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_tenant_arquivo_referencias_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_tenant_arquivo_referencias_id", "erp"."p2_u64_hi_tenant_arquivo_referencias_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('tenant_arquivo_referencias.id', '"erp"."p2_u64_lo_tenant_arquivo_referencias_id"'::regclass, '"erp"."p2_u64_hi_tenant_arquivo_referencias_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('tenants.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('tipos_dispositivo.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('unidades.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('usuario_modulos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('usuario_tenant.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('v_emails_recentes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('validacoes_face_id.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('veiculos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('visitantes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('vw_alertas_pendentes.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('vw_checklist_completo.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('vw_consumo_veiculos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('vw_dependentes_ativos.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('vw_dependentes_completo.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_vw_extrato_bancario_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_vw_extrato_bancario_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_vw_extrato_bancario_id", "erp"."p2_u64_hi_vw_extrato_bancario_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('vw_extrato_bancario.id', '"erp"."p2_u64_lo_vw_extrato_bancario_id"'::regclass, '"erp"."p2_u64_hi_vw_extrato_bancario_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('vw_movimentacoes_detalhadas.id', NULL, NULL);

CREATE SEQUENCE "erp"."p2_u64_lo_vw_pendencias_conciliacao_id" MINVALUE 1 MAXVALUE 9223372036854775807 START WITH 1 CACHE 1;

CREATE SEQUENCE "erp"."p2_u64_hi_vw_pendencias_conciliacao_id" MINVALUE 0 MAXVALUE 9223372036854775807 START WITH 0 CACHE 1;

REVOKE ALL ON SEQUENCE "erp"."p2_u64_lo_vw_pendencias_conciliacao_id", "erp"."p2_u64_hi_vw_pendencias_conciliacao_id" FROM PUBLIC;

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('vw_pendencias_conciliacao.id', '"erp"."p2_u64_lo_vw_pendencias_conciliacao_id"'::regclass, '"erp"."p2_u64_hi_vw_pendencias_conciliacao_id"'::regclass);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('vw_produtos_estoque_baixo.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('vw_saldo_contas.id', NULL, NULL);

INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence) VALUES ('vw_ultimos_abastecimentos.id', NULL, NULL);

CREATE FUNCTION "erp"."_p2_next_unsigned_id"(p_sequence_name TEXT) RETURNS NUMERIC(20,0) LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS $$
DECLARE v_low_sequence regclass; v_high_sequence regclass; v_value BIGINT;
BEGIN
  SELECT low_sequence, high_sequence INTO v_low_sequence, v_high_sequence FROM "erp"."_p2_autoincrement_state" WHERE sequence_name = p_sequence_name;
  IF NOT FOUND OR v_low_sequence IS NULL OR v_high_sequence IS NULL THEN RAISE EXCEPTION 'unsigned identity state missing'; END IF;
  BEGIN
    v_value := pg_catalog.nextval(v_low_sequence);
    RETURN v_value::numeric;
  EXCEPTION WHEN SQLSTATE '2200H' THEN
    v_value := pg_catalog.nextval(v_high_sequence);
    RETURN 9223372036854775808::numeric + v_value::numeric;
  END;
END;
$$;

REVOKE ALL ON FUNCTION "erp"."_p2_next_unsigned_id"(TEXT) FROM PUBLIC;

REVOKE ALL ON TABLE "erp"."_p2_autoincrement_state" FROM PUBLIC;

CREATE TABLE "erp"."abastecimento_lancamentos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "veiculo_id" INTEGER NOT NULL,
  "data_abastecimento" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "km_abastecimento" INTEGER NOT NULL,
  "litros" NUMERIC(10,2) NOT NULL,
  "valor" NUMERIC(10,2) NOT NULL,
  "tipo_combustivel" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_abastecimento_lancamentos_tipo_combustivel_8" CHECK ("tipo_combustivel" IN ('Gasolina', 'Álcool', 'Diesel')),
  "operador_id" INTEGER NOT NULL,
  "usuario_logado" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "observacao" TEXT COLLATE ci,
  "data_registro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."abastecimento_recargas" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "data_recarga" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "valor_recarga" NUMERIC(10,2) NOT NULL,
  "valor_minimo" NUMERIC(10,2) NOT NULL,
  "nf" CHARACTER VARYING(50) COLLATE ci,
  "saldo_apos" NUMERIC(10,2) NOT NULL,
  "usuario_id" INTEGER NOT NULL,
  "data_registro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."abastecimento_saldo" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "valor" NUMERIC(10,2) NOT NULL DEFAULT 0.00,
  "valor_minimo" NUMERIC(10,2) NOT NULL DEFAULT 0.00,
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."abastecimento_veiculos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "placa" CHARACTER VARYING(8) COLLATE ci NOT NULL,
  "modelo" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "ano" INTEGER NOT NULL,
  "cor" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "km_inicial" INTEGER NOT NULL,
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."administradoras_importacao" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "slug" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_administradoras_importacao_ativo_5" CHECK ("ativo" BETWEEN -128 AND 127),
  "ordem" INTEGER NOT NULL DEFAULT 0,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."administradoras_layouts" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "administradora_id" INTEGER NOT NULL,
  "codigo" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "modulo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_administradoras_layouts_modulo_4" CHECK ("modulo" IN ('CONTAS_RECEBER', 'INADIMPLENCIA', 'CONTAS_PAGAR', 'EXTRATO_BANCARIO')),
  "nome" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "formato_aceito" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "status_implantacao" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'CONFIGURADO',
  CONSTRAINT "ck_administradoras_layouts_status_implantacao_9" CHECK ("status_implantacao" IN ('PRONTO', 'CONFIGURADO', 'PLANEJADO')),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_administradoras_layouts_ativo_11" CHECK ("ativo" BETWEEN -128 AND 127),
  "ordem" INTEGER NOT NULL DEFAULT 0,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_acesso" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "nome" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "severidade" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'atencao',
  CONSTRAINT "ck_alertas_acesso_severidade_5" CHECK ("severidade" IN ('informativo', 'atencao', 'critico')),
  "canais_json" TEXT COLLATE ci NOT NULL,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_alertas_acesso_ativo_8" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_acesso_criterios" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "alerta_id" INTEGER NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_alertas_acesso_criterios_tipo_4" CHECK ("tipo" IN ('veiculo', 'pessoa', 'contexto')),
  "campo" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "operador" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'igual',
  CONSTRAINT "ck_alertas_acesso_criterios_operador_7" CHECK ("operador" IN ('igual', 'contem', 'comeca_com')),
  "valor" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_acesso_entregas" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "evento_id" BIGINT NOT NULL,
  "canal" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_alertas_acesso_entregas_canal_4" CHECK ("canal" IN ('sistema', 'email', 'whatsapp')),
  "destinatario" CHARACTER VARYING(255) COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'pendente',
  CONSTRAINT "ck_alertas_acesso_entregas_status_7" CHECK ("status" IN ('pendente', 'enviado', 'falhou', 'nao_configurado')),
  "detalhe" CHARACTER VARYING(500) COLLATE ci,
  "enviado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_acesso_eventos" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "alerta_id" INTEGER NOT NULL,
  "evento_uuid" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "origem" CHARACTER VARYING(40) COLLATE ci NOT NULL,
  "dados_json" TEXT COLLATE ci,
  "detectado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'pendente',
  CONSTRAINT "ck_alertas_acesso_eventos_status_8" CHECK ("status" IN ('pendente', 'notificado', 'reconhecido', 'ignorado')),
  "reconhecido_por_usuario_id" INTEGER,
  "reconhecido_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_acesso_leituras" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "evento_id" BIGINT NOT NULL,
  "usuario_id" INTEGER NOT NULL,
  "lido" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_alertas_acesso_leituras_lido_5" CHECK ("lido" BETWEEN -128 AND 127),
  "lido_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_estoque" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "produto_id" INTEGER NOT NULL,
  "tipo_alerta" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_alertas_estoque_tipo_alerta_4" CHECK ("tipo_alerta" IN ('Estoque_Minimo', 'Estoque_Zerado', 'Estoque_Maximo', 'Validade_Proxima')),
  "mensagem" CHARACTER VARYING(500) COLLATE ci NOT NULL,
  "lido" SMALLINT DEFAULT 0,
  CONSTRAINT "ck_alertas_estoque_lido_7" CHECK ("lido" BETWEEN -128 AND 127),
  "data_alerta" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_unidade_acionamentos" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('alertas_unidade_acionamentos.id') NOT NULL,
  CONSTRAINT "ck_alertas_unidade_acionamentos_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "alerta_id" NUMERIC(20,0) NOT NULL,
  CONSTRAINT "ck_alertas_unidade_acionamentos_alerta_id_4" CHECK ("alerta_id" BETWEEN 0 AND 18446744073709551615),
  "registro_acesso_id" INTEGER,
  "contexto" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_alertas_unidade_acionamentos_contexto_7" CHECK ("contexto" IN ('REGISTRO_MANUAL', 'CONTROLE_ACESSO', 'RFID', 'CONVIDADOS', 'LPR', 'PORTAL')),
  "decisao" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_alertas_unidade_acionamentos_decisao_9" CHECK ("decisao" IN ('CIENTE_LIBERADO', 'CIENTE_NAO_LIBERADO', 'CONTATO_MORADOR_AUTORIZOU', 'CONTATO_MORADOR_NEGOU', 'OVERRIDE_SUPERVISOR', 'APENAS_AVISAR', 'CIENCIA_PENDENTE', 'BLOQUEADO_AUTOMATICO')),
  "usuario_id" INTEGER,
  "usuario_nome" CHARACTER VARYING(150) COLLATE ci,
  "pessoa_nome" CHARACTER VARYING(200) COLLATE ci,
  "pessoa_documento_mascarado" CHARACTER VARYING(30) COLLATE ci,
  "tipo_pessoa" CHARACTER VARYING(50) COLLATE ci,
  "placa" CHARACTER VARYING(10) COLLATE ci,
  "divergencia_unidade" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_alertas_unidade_acionamentos_divergencia_unidade_17" CHECK ("divergencia_unidade" BETWEEN -128 AND 127),
  "snapshot_json" TEXT COLLATE ci NOT NULL,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_unidade_alvos" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('alertas_unidade_alvos.id') NOT NULL,
  CONSTRAINT "ck_alertas_unidade_alvos_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "alerta_id" NUMERIC(20,0) NOT NULL,
  CONSTRAINT "ck_alertas_unidade_alvos_alerta_id_4" CHECK ("alerta_id" BETWEEN 0 AND 18446744073709551615),
  "tipo_alvo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_alertas_unidade_alvos_tipo_alvo_6" CHECK ("tipo_alvo" IN ('VISITANTE', 'DOCUMENTO', 'PLACA')),
  "visitante_id" INTEGER,
  "tipo_documento" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_alertas_unidade_alvos_tipo_documento_9" CHECK ("tipo_documento" IN ('CPF', 'RG', 'OUTRO')),
  "documento_normalizado" CHARACTER VARYING(20) COLLATE ci,
  "placa_normalizada" CHARACTER VARYING(10) COLLATE ci,
  "nome_referencia" CHARACTER VARYING(150) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_unidade_cache_ids" (
  "tenant_id" INTEGER NOT NULL,
  "unidade" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "ids_json" TEXT COLLATE ci NOT NULL,
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("tenant_id", "unidade")
);

CREATE TABLE "erp"."alertas_unidade_ciencias" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('alertas_unidade_ciencias.id') NOT NULL,
  CONSTRAINT "ck_alertas_unidade_ciencias_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "alerta_id" NUMERIC(20,0) NOT NULL,
  CONSTRAINT "ck_alertas_unidade_ciencias_alerta_id_4" CHECK ("alerta_id" BETWEEN 0 AND 18446744073709551615),
  "token_id" NUMERIC(20,0),
  CONSTRAINT "ck_alertas_unidade_ciencias_token_id_6" CHECK ("token_id" BETWEEN 0 AND 18446744073709551615),
  "contexto" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_alertas_unidade_ciencias_contexto_8" CHECK ("contexto" IN ('REGISTRO_MANUAL', 'CONTROLE_ACESSO', 'RFID', 'CONVIDADOS', 'LPR', 'PORTAL')),
  "registro_acesso_id" INTEGER,
  "decisao" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_alertas_unidade_ciencias_decisao_11" CHECK ("decisao" IN ('CIENTE_LIBERADO', 'CIENTE_NAO_LIBERADO', 'CONTATO_MORADOR_AUTORIZOU', 'CONTATO_MORADOR_NEGOU', 'OVERRIDE_SUPERVISOR')),
  "justificativa" CHARACTER VARYING(500) COLLATE ci,
  "usuario_id" INTEGER NOT NULL,
  "usuario_nome" CHARACTER VARYING(150) COLLATE ci,
  "supervisor_usuario_id" INTEGER,
  "pessoa_nome" CHARACTER VARYING(200) COLLATE ci,
  "pessoa_documento_mascarado" CHARACTER VARYING(30) COLLATE ci,
  "tipo_pessoa" CHARACTER VARYING(50) COLLATE ci,
  "placa" CHARACTER VARYING(10) COLLATE ci,
  "divergencia_unidade" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_alertas_unidade_ciencias_divergencia_unidade_21" CHECK ("divergencia_unidade" BETWEEN -128 AND 127),
  "snapshot_json" TEXT COLLATE ci NOT NULL,
  "ip" CHARACTER VARYING(45) COLLATE ci,
  "user_agent" CHARACTER VARYING(255) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_unidade_historico" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('alertas_unidade_historico.id') NOT NULL,
  CONSTRAINT "ck_alertas_unidade_historico_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "alerta_id" NUMERIC(20,0) NOT NULL,
  CONSTRAINT "ck_alertas_unidade_historico_alerta_id_4" CHECK ("alerta_id" BETWEEN 0 AND 18446744073709551615),
  "operacao" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_alertas_unidade_historico_operacao_6" CHECK ("operacao" IN ('CRIAR', 'EDITAR', 'PAUSAR', 'RETOMAR', 'CANCELAR', 'EXPIRAR')),
  "usuario_id" INTEGER,
  "usuario_nome" CHARACTER VARYING(150) COLLATE ci,
  "origem" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_alertas_unidade_historico_origem_10" CHECK ("origem" IN ('SISTEMA', 'PORTAL', 'APP')),
  "dados_antes_json" TEXT COLLATE ci,
  "dados_depois_json" TEXT COLLATE ci,
  "motivo" CHARACTER VARYING(500) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_unidade_lembretes" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('alertas_unidade_lembretes.id') NOT NULL,
  CONSTRAINT "ck_alertas_unidade_lembretes_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "alerta_id" NUMERIC(20,0) NOT NULL,
  CONSTRAINT "ck_alertas_unidade_lembretes_alerta_id_4" CHECK ("alerta_id" BETWEEN 0 AND 18446744073709551615),
  "morador_id" INTEGER NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'EXPIRACAO_24H',
  CONSTRAINT "ck_alertas_unidade_lembretes_tipo_7" CHECK ("tipo" IN ('EXPIRACAO_24H')),
  "vigencia_fim" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "notificacao_morador_id" BIGINT,
  CONSTRAINT "ck_alertas_unidade_lembretes_notificacao_morador_id_10" CHECK ("notificacao_morador_id" BETWEEN 0 AND 4294967295),
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'PENDENTE',
  CONSTRAINT "ck_alertas_unidade_lembretes_status_12" CHECK ("status" IN ('PENDENTE', 'CRIADO', 'SEM_CANAL')),
  "push_status" CHARACTER VARYING(40) COLLATE ci NOT NULL,
  "push_detalhe" CHARACTER VARYING(255) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "processado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_unidade_notificacoes" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('alertas_unidade_notificacoes.id') NOT NULL,
  CONSTRAINT "ck_alertas_unidade_notificacoes_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "alerta_id" NUMERIC(20,0) NOT NULL,
  CONSTRAINT "ck_alertas_unidade_notificacoes_alerta_id_4" CHECK ("alerta_id" BETWEEN 0 AND 18446744073709551615),
  "registro_acesso_id" INTEGER,
  "morador_id" INTEGER NOT NULL,
  "notificacao_morador_id" BIGINT,
  CONSTRAINT "ck_alertas_unidade_notificacoes_notificacao_morador_id_8" CHECK ("notificacao_morador_id" BETWEEN 0 AND 4294967295),
  "janela_inicio" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "tipo" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "titulo" CHARACTER VARYING(160) COLLATE ci NOT NULL,
  "mensagem" CHARACTER VARYING(1000) COLLATE ci NOT NULL,
  "push_status" CHARACTER VARYING(40) COLLATE ci NOT NULL,
  "push_detalhe" CHARACTER VARYING(255) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."alertas_unidade_preferencias" (
  "tenant_id" INTEGER NOT NULL,
  "morador_id" INTEGER NOT NULL,
  "push_ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_alertas_unidade_preferencias_push_ativo_3" CHECK ("push_ativo" BETWEEN -128 AND 127),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("tenant_id", "morador_id")
);

CREATE TABLE "erp"."alertas_unidade_rate_limits" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('alertas_unidade_rate_limits.id') NOT NULL,
  CONSTRAINT "ck_alertas_unidade_rate_limits_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "usuario_id" INTEGER NOT NULL,
  "unidade" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "bucket_inicio" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "contador" INTEGER NOT NULL DEFAULT 0,
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."aplicativos_catalogo" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "chave" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "plataforma" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'android',
  CONSTRAINT "ck_aplicativos_catalogo_plataforma_4" CHECK ("plataforma" IN ('android', 'ios', 'web')),
  "package_name" CHARACTER VARYING(180) COLLATE ci,
  "google_play_url" CHARACTER VARYING(500) COLLATE ci,
  "google_play_package" CHARACTER VARYING(180) COLLATE ci,
  "descricao" TEXT COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'ativo',
  CONSTRAINT "ck_aplicativos_catalogo_status_10" CHECK ("status" IN ('ativo', 'inativo')),
  "criado_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."aplicativos_versionamento_log" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "aplicativo_id" INTEGER,
  "versao_id" INTEGER,
  "usuario_id" INTEGER,
  "acao" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(500) COLLATE ci NOT NULL,
  "ip" CHARACTER VARYING(64) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."aplicativos_versoes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "aplicativo_id" INTEGER NOT NULL,
  "versao_nome" CHARACTER VARYING(40) COLLATE ci NOT NULL,
  "version_code" INTEGER NOT NULL,
  "canal" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'interno',
  CONSTRAINT "ck_aplicativos_versoes_canal_5" CHECK ("canal" IN ('interno', 'teste', 'producao')),
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'rascunho',
  CONSTRAINT "ck_aplicativos_versoes_status_7" CHECK ("status" IN ('rascunho', 'publicado', 'arquivado')),
  "distribuicao" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'apk_direto',
  CONSTRAINT "ck_aplicativos_versoes_distribuicao_9" CHECK ("distribuicao" IN ('apk_direto', 'google_play', 'ambos')),
  "url_download_apk" CHARACTER VARYING(700) COLLATE ci,
  "tamanho_bytes" NUMERIC(20,0),
  CONSTRAINT "ck_aplicativos_versoes_tamanho_bytes_12" CHECK ("tamanho_bytes" BETWEEN 0 AND 18446744073709551615),
  "sha256" CHARACTER(64) COLLATE ci,
  "min_sdk" CHARACTER VARYING(20) COLLATE ci,
  "target_sdk" CHARACTER VARYING(20) COLLATE ci,
  "obrigatoria" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_aplicativos_versoes_obrigatoria_17" CHECK ("obrigatoria" BETWEEN -128 AND 127),
  "notas_liberacao" TEXT COLLATE ci,
  "google_play_track" CHARACTER VARYING(40) COLLATE ci,
  "google_play_release_id" CHARACTER VARYING(120) COLLATE ci,
  "publicado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "criado_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."assembleias" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_assembleias_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER,
  "nome" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'ordinaria',
  CONSTRAINT "ck_assembleias_tipo_5" CHECK ("tipo" IN ('ordinaria', 'extraordinaria', 'deliberacao')),
  "data_assembleia" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "local_realizacao" CHARACTER VARYING(255) COLLATE ci,
  "descricao" TEXT COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'rascunho',
  CONSTRAINT "ck_assembleias_status_10" CHECK ("status" IN ('rascunho', 'convocada', 'em_andamento', 'encerrada', 'cancelada')),
  "quorum_minimo" BIGINT DEFAULT 0,
  CONSTRAINT "ck_assembleias_quorum_minimo_12" CHECK ("quorum_minimo" BETWEEN 0 AND 4294967295),
  "criado_por" BIGINT,
  CONSTRAINT "ck_assembleias_criado_por_14" CHECK ("criado_por" BETWEEN 0 AND 4294967295),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."avaliacoes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "pedido_id" INTEGER NOT NULL,
  "morador_id" INTEGER NOT NULL,
  "fornecedor_id" INTEGER NOT NULL,
  "produto_servico_id" INTEGER NOT NULL,
  "nota_morador" INTEGER,
  "comentario_morador" TEXT COLLATE ci,
  "nota_fornecedor" INTEGER,
  "comentario_fornecedor" TEXT COLLATE ci,
  "data_avaliacao_morador" TIMESTAMP(0) WITHOUT TIME ZONE,
  "data_avaliacao_fornecedor" TIMESTAMP(0) WITHOUT TIME ZONE,
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."avaliacoes_backup" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "pedido_id" INTEGER NOT NULL,
  "avaliador_tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_avaliacoes_backup_avaliador_tipo_4" CHECK ("avaliador_tipo" IN ('morador', 'fornecedor')),
  "avaliador_id" INTEGER NOT NULL,
  "avaliado_tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_avaliacoes_backup_avaliado_tipo_7" CHECK ("avaliado_tipo" IN ('morador', 'fornecedor')),
  "avaliado_id" INTEGER NOT NULL,
  "nota" INTEGER NOT NULL,
  "comentario" TEXT COLLATE ci,
  "data_avaliacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."bancos_brasileiros" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_bancos_brasileiros_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "codigo" CHARACTER VARYING(10) COLLATE ci NOT NULL,
  "ispb" CHARACTER VARYING(8) COLLATE ci,
  "nome" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "nome_curto" CHARACTER VARYING(60) COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_bancos_brasileiros_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."bridge_eventos_log" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "dispositivo_id" INTEGER NOT NULL,
  "log_id_externo" INTEGER NOT NULL,
  "user_id_controlid" INTEGER,
  "card_value" CHARACTER VARYING(64) COLLATE ci,
  "event_type" INTEGER,
  "event_time" TIMESTAMP(0) WITHOUT TIME ZONE,
  "door_id" INTEGER,
  "veiculo_id" INTEGER,
  "morador_id" INTEGER,
  "processado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_bridge_eventos_log_processado_12" CHECK ("processado" BETWEEN -128 AND 127),
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."bridge_fila_comandos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "dispositivo_id" INTEGER NOT NULL,
  "endpoint" CHARACTER VARYING(128) COLLATE ci NOT NULL,
  "verb" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'POST',
  CONSTRAINT "ck_bridge_fila_comandos_verb_5" CHECK ("verb" IN ('GET', 'POST', 'PUT', 'DELETE')),
  "body" TEXT COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'pendente',
  CONSTRAINT "ck_bridge_fila_comandos_status_8" CHECK ("status" IN ('pendente', 'executado', 'erro', 'cancelado')),
  "resultado" TEXT COLLATE ci,
  "executado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "created_by" INTEGER,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."bridge_status" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "bridge_id" CHARACTER VARYING(64) COLLATE ci,
  "versao" CHARACTER VARYING(20) COLLATE ci,
  "ip_local" CHARACTER VARYING(45) COLLATE ci,
  "ultimo_contato" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "online" SMALLINT DEFAULT 0,
  CONSTRAINT "ck_bridge_status_online_7" CHECK ("online" BETWEEN -128 AND 127),
  "dispositivos_total" INTEGER DEFAULT 0,
  "dispositivos_online" INTEGER DEFAULT 0,
  "dados_json" TEXT COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."categorias_estoque" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "cor" CHARACTER VARYING(20) COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_categorias_estoque_ativo_6" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."chatin_bloqueios" (
  "tenant_id" INTEGER NOT NULL,
  "bloqueador_tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_chatin_bloqueios_bloqueador_tipo_2" CHECK ("bloqueador_tipo" IN ('USUARIO', 'MORADOR')),
  "bloqueador_id" INTEGER NOT NULL,
  "bloqueado_tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_chatin_bloqueios_bloqueado_tipo_5" CHECK ("bloqueado_tipo" IN ('USUARIO', 'MORADOR')),
  "bloqueado_id" INTEGER NOT NULL,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("tenant_id", "bloqueador_tipo", "bloqueador_id", "bloqueado_tipo", "bloqueado_id")
);

CREATE TABLE "erp"."chatin_conversas" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('chatin_conversas.id') NOT NULL,
  CONSTRAINT "ck_chatin_conversas_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'DIRETA',
  CONSTRAINT "ck_chatin_conversas_tipo_4" CHECK ("tipo" IN ('DIRETA', 'AVISOS')),
  "chave_unica" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "ultima_mensagem_id" NUMERIC(20,0),
  CONSTRAINT "ck_chatin_conversas_ultima_mensagem_id_7" CHECK ("ultima_mensagem_id" BETWEEN 0 AND 18446744073709551615),
  "ultima_mensagem_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."chatin_mensagens" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('chatin_mensagens.id') NOT NULL,
  CONSTRAINT "ck_chatin_mensagens_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "conversa_id" NUMERIC(20,0) NOT NULL,
  CONSTRAINT "ck_chatin_mensagens_conversa_id_4" CHECK ("conversa_id" BETWEEN 0 AND 18446744073709551615),
  "remetente_tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_chatin_mensagens_remetente_tipo_6" CHECK ("remetente_tipo" IN ('USUARIO', 'MORADOR', 'SISTEMA')),
  "remetente_id" INTEGER NOT NULL DEFAULT 0,
  "remetente_nome" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'TEXTO',
  CONSTRAINT "ck_chatin_mensagens_tipo_10" CHECK ("tipo" IN ('TEXTO', 'AVISO')),
  "corpo" TEXT COLLATE ci NOT NULL,
  "titulo" CHARACTER VARYING(150) COLLATE ci,
  "referencia_tipo" CHARACTER VARYING(30) COLLATE ci,
  "referencia_id" INTEGER,
  "idempotency_key" CHARACTER VARYING(40) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."chatin_participantes" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('chatin_participantes.id') NOT NULL,
  CONSTRAINT "ck_chatin_participantes_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "conversa_id" NUMERIC(20,0) NOT NULL,
  CONSTRAINT "ck_chatin_participantes_conversa_id_4" CHECK ("conversa_id" BETWEEN 0 AND 18446744073709551615),
  "participante_tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_chatin_participantes_participante_tipo_6" CHECK ("participante_tipo" IN ('USUARIO', 'MORADOR', 'SISTEMA')),
  "participante_id" INTEGER NOT NULL,
  "ultima_leitura_id" NUMERIC(20,0) NOT NULL DEFAULT 0,
  CONSTRAINT "ck_chatin_participantes_ultima_leitura_id_9" CHECK ("ultima_leitura_id" BETWEEN 0 AND 18446744073709551615),
  "silenciado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_chatin_participantes_silenciado_11" CHECK ("silenciado" BETWEEN -128 AND 127),
  "arquivado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_chatin_participantes_arquivado_13" CHECK ("arquivado" BETWEEN -128 AND 127),
  "entrou_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."chatin_preferencias" (
  "tenant_id" INTEGER NOT NULL,
  "participante_tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_chatin_preferencias_participante_tipo_2" CHECK ("participante_tipo" IN ('USUARIO', 'MORADOR')),
  "participante_id" INTEGER NOT NULL,
  "visivel_busca" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_chatin_preferencias_visivel_busca_5" CHECK ("visivel_busca" BETWEEN -128 AND 127),
  "mostrar_online" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_chatin_preferencias_mostrar_online_7" CHECK ("mostrar_online" BETWEEN -128 AND 127),
  "notificar_push" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_chatin_preferencias_notificar_push_9" CHECK ("notificar_push" BETWEEN -128 AND 127),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("tenant_id", "participante_tipo", "participante_id")
);

CREATE TABLE "erp"."chatin_presenca" (
  "tenant_id" INTEGER NOT NULL,
  "participante_tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_chatin_presenca_participante_tipo_2" CHECK ("participante_tipo" IN ('USUARIO', 'MORADOR')),
  "participante_id" INTEGER NOT NULL,
  "ultimo_ping" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "plataforma" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "conversa_aberta_id" NUMERIC(20,0),
  CONSTRAINT "ck_chatin_presenca_conversa_aberta_id_7" CHECK ("conversa_aberta_id" BETWEEN 0 AND 18446744073709551615),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("tenant_id", "participante_tipo", "participante_id")
);

CREATE TABLE "erp"."checklist_alertas_config" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "categoria" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "nome_alerta" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "km_alerta" INTEGER NOT NULL,
  "descricao" TEXT COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_checklist_alertas_config_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."checklist_alertas_gerados" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "checklist_id" INTEGER NOT NULL,
  "alerta_config_id" INTEGER NOT NULL,
  "veiculo_id" INTEGER NOT NULL,
  "km_atual" INTEGER NOT NULL,
  "km_limite" INTEGER NOT NULL,
  "categoria" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci NOT NULL,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'pendente',
  CONSTRAINT "ck_checklist_alertas_gerados_status_10" CHECK ("status" IN ('pendente', 'resolvido', 'ignorado')),
  "data_geracao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "data_resolucao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "resolvido_por" INTEGER,
  "observacao_resolucao" TEXT COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."checklist_itens" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "checklist_id" INTEGER NOT NULL,
  "tipo_item" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_checklist_itens_tipo_item_4" CHECK ("tipo_item" IN ('nivel', 'funcional')),
  "nome_item" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "categoria" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "valor_abertura" CHARACTER VARYING(20) COLLATE ci,
  "valor_fechamento" CHARACTER VARYING(20) COLLATE ci,
  "data_registro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."checklist_km_acumulado" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "veiculo_id" INTEGER NOT NULL,
  "categoria" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "km_acumulado" INTEGER NOT NULL DEFAULT 0,
  "ultimo_checklist_id" INTEGER,
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."checklist_veicular" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "veiculo_id" INTEGER NOT NULL,
  "operador_id" INTEGER NOT NULL,
  "km_inicial" INTEGER NOT NULL,
  "data_hora_abertura" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'aberto',
  CONSTRAINT "ck_checklist_veicular_status_7" CHECK ("status" IN ('aberto', 'fechado')),
  "km_final" INTEGER,
  "data_hora_fechamento" TIMESTAMP(0) WITHOUT TIME ZONE,
  "observacao_abertura" TEXT COLLATE ci,
  "observacao_fechamento" TEXT COLLATE ci,
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."conciliacoes" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('conciliacoes.id') NOT NULL,
  CONSTRAINT "ck_conciliacoes_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "movimentacao_id" NUMERIC(20,0) NOT NULL,
  CONSTRAINT "ck_conciliacoes_movimentacao_id_4" CHECK ("movimentacao_id" BETWEEN 0 AND 18446744073709551615),
  "tipo_titulo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_conciliacoes_tipo_titulo_6" CHECK ("tipo_titulo" IN ('receber', 'pagar')),
  "titulo_id" BIGINT NOT NULL,
  CONSTRAINT "ck_conciliacoes_titulo_id_8" CHECK ("titulo_id" BETWEEN 0 AND 4294967295),
  "tipo_conciliacao" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'manual',
  CONSTRAINT "ck_conciliacoes_tipo_conciliacao_10" CHECK ("tipo_conciliacao" IN ('automatica', 'manual')),
  "score" NUMERIC(5,2),
  "criterios" TEXT COLLATE ci,
  "conciliado_por" CHARACTER VARYING(80) COLLATE ci,
  "conciliado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "desfeito_por" CHARACTER VARYING(80) COLLATE ci,
  "desfeito_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "ativa" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_conciliacoes_ativa_18" CHECK ("ativa" BETWEEN -128 AND 127),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."config_moradores_campos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "grupo" CHARACTER VARYING(30) COLLATE ci NOT NULL,
  "campo" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "obrigatorio" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_config_moradores_campos_obrigatorio_5" CHECK ("obrigatorio" BETWEEN -128 AND 127),
  "atualizado_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."config_moradores_opcoes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "opcao" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "habilitado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_config_moradores_opcoes_habilitado_4" CHECK ("habilitado" BETWEEN -128 AND 127),
  "atualizado_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."config_periodo_leitura" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "dia_inicio" INTEGER NOT NULL DEFAULT 1,
  "dia_fim" INTEGER NOT NULL DEFAULT 10,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_config_periodo_leitura_ativo_5" CHECK ("ativo" BETWEEN -128 AND 127),
  "morador_pode_lancar" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_config_periodo_leitura_morador_pode_lancar_7" CHECK ("morador_pode_lancar" BETWEEN -128 AND 127),
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."config_sessao" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_config_sessao_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "chave" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "valor" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "atualizado" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."config_visitantes_campos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "campo" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "obrigatorio" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_config_visitantes_campos_obrigatorio_4" CHECK ("obrigatorio" BETWEEN -128 AND 127),
  "atualizado_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."configuracoes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "chave" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "valor" TEXT COLLATE ci,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."contas_bancarias" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_contas_bancarias_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "banco_codigo" CHARACTER VARYING(10) COLLATE ci NOT NULL,
  "banco_nome" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "agencia" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "conta_numero" CHARACTER VARYING(30) COLLATE ci NOT NULL,
  "conta_tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'corrente',
  CONSTRAINT "ck_contas_bancarias_conta_tipo_9" CHECK ("conta_tipo" IN ('corrente', 'poupanca', 'investimento', 'caixa')),
  "moeda" CHARACTER(3) COLLATE ci NOT NULL,
  "saldo_inicial" NUMERIC(15,2) NOT NULL DEFAULT 0.00,
  "saldo_atual" NUMERIC(15,2) NOT NULL DEFAULT 0.00,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_contas_bancarias_ativo_14" CHECK ("ativo" BETWEEN -128 AND 127),
  "observacoes" TEXT COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."contas_pagar" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "numero_documento" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "fornecedor_id" INTEGER,
  "fornecedor_nome" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "plano_conta_id" INTEGER NOT NULL,
  "descricao" TEXT COLLATE ci NOT NULL,
  "valor_original" NUMERIC(12,2) NOT NULL,
  "valor_pago" NUMERIC(12,2) DEFAULT 0.00,
  "saldo_devedor" NUMERIC(12,2),
  "data_emissao" DATE NOT NULL,
  "data_vencimento" DATE NOT NULL,
  "data_pagamento" DATE,
  "status" CHARACTER VARYING COLLATE ci DEFAULT 'PENDENTE',
  CONSTRAINT "ck_contas_pagar_status_14" CHECK ("status" IN ('PENDENTE', 'PARCIAL', 'PAGO', 'CANCELADO', 'ATRASADO')),
  "forma_pagamento" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_contas_pagar_forma_pagamento_16" CHECK ("forma_pagamento" IN ('DINHEIRO', 'CHEQUE', 'TRANSFERENCIA', 'CARTAO', 'BOLETO', 'OUTRO')),
  "observacoes" TEXT COLLATE ci,
  "contrato_id" INTEGER,
  "usuario_criacao" CHARACTER VARYING(100) COLLATE ci,
  "usuario_pagamento" CHARACTER VARYING(100) COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_contas_pagar_ativo_22" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "classificacao_despesa" CHARACTER VARYING(255) COLLATE ci,
  "grupo" CHARACTER VARYING(255) COLLATE ci,
  "movimentacao_bancaria_id" NUMERIC(20,0),
  CONSTRAINT "ck_contas_pagar_movimentacao_bancaria_id_28" CHECK ("movimentacao_bancaria_id" BETWEEN 0 AND 18446744073709551615),
  "conta_bancaria_id" BIGINT,
  CONSTRAINT "ck_contas_pagar_conta_bancaria_id_30" CHECK ("conta_bancaria_id" BETWEEN 0 AND 4294967295),
  "conciliado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_contas_pagar_conciliado_32" CHECK ("conciliado" BETWEEN -128 AND 127),
  "data_conciliacao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "usuario_conciliacao" CHARACTER VARYING(80) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."contas_receber" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "numero_documento" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "morador_id" INTEGER,
  "morador_nome" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "unidade_id" INTEGER,
  "unidade_numero" CHARACTER VARYING(50) COLLATE ci,
  "plano_conta_id" INTEGER NOT NULL,
  "descricao" TEXT COLLATE ci NOT NULL,
  "valor_original" NUMERIC(12,2) NOT NULL,
  "valor_recebido" NUMERIC(12,2) DEFAULT 0.00,
  "saldo_devedor" NUMERIC(12,2),
  "data_emissao" DATE NOT NULL,
  "data_vencimento" DATE NOT NULL,
  "data_recebimento" DATE,
  "status" CHARACTER VARYING COLLATE ci DEFAULT 'PENDENTE',
  CONSTRAINT "ck_contas_receber_status_16" CHECK ("status" IN ('PENDENTE', 'PARCIAL', 'RECEBIDO', 'CANCELADO', 'ATRASADO')),
  "forma_pagamento" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_contas_receber_forma_pagamento_18" CHECK ("forma_pagamento" IN ('DINHEIRO', 'CHEQUE', 'TRANSFERENCIA', 'CARTAO', 'BOLETO', 'OUTRO')),
  "observacoes" TEXT COLLATE ci,
  "usuario_criacao" CHARACTER VARYING(100) COLLATE ci,
  "usuario_recebimento" CHARACTER VARYING(100) COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_contas_receber_ativo_23" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "movimentacao_bancaria_id" NUMERIC(20,0),
  CONSTRAINT "ck_contas_receber_movimentacao_bancaria_id_27" CHECK ("movimentacao_bancaria_id" BETWEEN 0 AND 18446744073709551615),
  "conta_bancaria_id" BIGINT,
  CONSTRAINT "ck_contas_receber_conta_bancaria_id_29" CHECK ("conta_bancaria_id" BETWEEN 0 AND 4294967295),
  "conciliado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_contas_receber_conciliado_31" CHECK ("conciliado" BETWEEN -128 AND 127),
  "data_conciliacao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "usuario_conciliacao" CHARACTER VARYING(80) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."contrato_aditivos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "contrato_id" INTEGER NOT NULL,
  "numero_aditivo" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "valor_adicional" NUMERIC(15,2) DEFAULT 0.00,
  "nova_data_fim" DATE,
  "data_assinatura" DATE NOT NULL,
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."contrato_documentos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "contrato_id" INTEGER NOT NULL,
  "nome_documento" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "tipo_documento" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'outros',
  CONSTRAINT "ck_contrato_documentos_tipo_documento_5" CHECK ("tipo_documento" IN ('contrato', 'aditivo', 'ata', 'outros')),
  "nome_arquivo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "url_arquivo" CHARACTER VARYING(500) COLLATE ci NOT NULL,
  "tamanho" BIGINT,
  "mime_type" CHARACTER VARYING(100) COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_contrato_documentos_ativo_11" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_upload" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."contrato_orcamento_documentos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "orcamento_id" INTEGER NOT NULL,
  "nome_documento" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "tipo_documento" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "nome_arquivo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "url_arquivo" CHARACTER VARYING(500) COLLATE ci NOT NULL,
  "tamanho" BIGINT,
  "mime_type" CHARACTER VARYING(100) COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_contrato_orcamento_documentos_ativo_10" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_upload" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."contrato_orcamentos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "contrato_id" INTEGER NOT NULL,
  "fornecedor" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci NOT NULL,
  "valor" NUMERIC(15,2) NOT NULL DEFAULT 0.00,
  "justificativa" TEXT COLLATE ci,
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."contratos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "numero_contrato" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "fornecedor_id" INTEGER,
  "fornecedor_nome" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "fornecedor_cnpj" CHARACTER VARYING(20) COLLATE ci,
  "tipo_servico" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_contratos_tipo_servico_7" CHECK ("tipo_servico" IN ('prestacao_servico', 'venda')),
  "nome_contrato" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "data_inicio" DATE NOT NULL,
  "data_fim" DATE NOT NULL,
  "recorrencia" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'unica',
  CONSTRAINT "ck_contratos_recorrencia_12" CHECK ("recorrencia" IN ('unica', 'mensal', 'anual', 'diaria')),
  "valor_total" NUMERIC(15,2) NOT NULL DEFAULT 0.00,
  "data_vencimento" DATE NOT NULL,
  "plano_conta_id" INTEGER NOT NULL,
  "observacoes" TEXT COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'ativo',
  CONSTRAINT "ck_contratos_status_18" CHECK ("status" IN ('ativo', 'aguardando', 'encerrado', 'cancelado')),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_contratos_ativo_20" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."controlid_eventos_acesso" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('controlid_eventos_acesso.id') NOT NULL,
  CONSTRAINT "ck_controlid_eventos_acesso_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "serial_number" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "user_id" INTEGER NOT NULL DEFAULT 0,
  "data_hora" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "tipo_evento" INTEGER NOT NULL DEFAULT 0,
  "raw_payload" JSON,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."controlid_fila_comandos" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_controlid_fila_comandos_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "serial_number" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "verbo" CHARACTER VARYING(10) COLLATE ci NOT NULL,
  "endpoint" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "corpo_json" JSON,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'pendente',
  CONSTRAINT "ck_controlid_fila_comandos_status_8" CHECK ("status" IN ('pendente', 'enviado', 'cancelado')),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "enviado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."controlid_push_queue" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "dispositivo_id" INTEGER NOT NULL,
  "device_id" BIGINT,
  "endpoint" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "verb" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'POST',
  CONSTRAINT "ck_controlid_push_queue_verb_6" CHECK ("verb" IN ('GET', 'POST')),
  "body" TEXT COLLATE ci NOT NULL,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'pendente',
  CONSTRAINT "ck_controlid_push_queue_status_9" CHECK ("status" IN ('pendente', 'enviado', 'executado', 'erro')),
  "resultado" TEXT COLLATE ci,
  "tentativas" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_controlid_push_queue_tentativas_12" CHECK ("tentativas" BETWEEN -128 AND 127),
  "criado_por" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "enviado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "executado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."convidados" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "unidade" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "morador_id" INTEGER NOT NULL,
  "anfitriao_tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'MORADOR',
  CONSTRAINT "ck_convidados_anfitriao_tipo_5" CHECK ("anfitriao_tipo" IN ('MORADOR', 'DEPENDENTE')),
  "dependente_id" INTEGER,
  "visitante_id" INTEGER,
  "tipo_documento" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_convidados_tipo_documento_9" CHECK ("tipo_documento" IN ('CPF', 'RG')),
  "documento" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "documento_normalizado" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(200) COLLATE ci NOT NULL,
  "tipo_convidado" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_convidados_tipo_convidado_14" CHECK ("tipo_convidado" IN ('VISITANTE', 'PRESTADOR', 'ENTREGADOR_AUTORIZADO', 'FAMILIAR')),
  "data_prevista_chegada" DATE NOT NULL,
  "dias_permanencia" INTEGER NOT NULL DEFAULT 1,
  CONSTRAINT "ck_convidados_dias_permanencia_17" CHECK ("dias_permanencia" BETWEEN 0 AND 65535),
  "validade_fim" DATE NOT NULL,
  "modalidade_entradas" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'UNICO',
  CONSTRAINT "ck_convidados_modalidade_entradas_20" CHECK ("modalidade_entradas" IN ('UNICO', 'MULTIPLO', 'INDETERMINADO')),
  "limite_entradas" INTEGER,
  CONSTRAINT "ck_convidados_limite_entradas_22" CHECK ("limite_entradas" BETWEEN 0 AND 65535),
  "entradas_utilizadas" INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT "ck_convidados_entradas_utilizadas_24" CHECK ("entradas_utilizadas" BETWEEN 0 AND 65535),
  "origem_cadastro" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_convidados_origem_cadastro_26" CHECK ("origem_cadastro" IN ('EMAIL', 'WHATSAPP', 'PRESENCIAL', 'ADMINISTRATIVO', 'PORTAL_MORADOR', 'APP_MORADOR')),
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'PREVISTO',
  CONSTRAINT "ck_convidados_status_28" CHECK ("status" IN ('PREVISTO', 'PRESENTE', 'FINALIZADO', 'CANCELADO')),
  "observacao" CHARACTER VARYING(500) COLLATE ci,
  "cadastrado_por_tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_convidados_cadastrado_por_tipo_31" CHECK ("cadastrado_por_tipo" IN ('USUARIO', 'MORADOR', 'DEPENDENTE')),
  "cadastrado_por_usuario_id" INTEGER,
  "cadastrado_por_morador_id" INTEGER,
  "cadastrado_por_dependente_id" INTEGER,
  "cadastrado_por_nome" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "atualizado_por_tipo" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_convidados_atualizado_por_tipo_37" CHECK ("atualizado_por_tipo" IN ('USUARIO', 'MORADOR', 'DEPENDENTE')),
  "atualizado_por_id" INTEGER,
  "atualizado_por_nome" CHARACTER VARYING(150) COLLATE ci,
  "cancelado_por_nome" CHARACTER VARYING(150) COLLATE ci,
  "cancelado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "motivo_cancelamento" CHARACTER VARYING(255) COLLATE ci,
  "primeira_entrada_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "ultima_movimentacao_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "idempotency_key" CHARACTER VARYING(36) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."crm_interacoes" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_crm_interacoes_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "relacionamento_id" BIGINT NOT NULL,
  CONSTRAINT "ck_crm_interacoes_relacionamento_id_4" CHECK ("relacionamento_id" BETWEEN 0 AND 4294967295),
  "usuario_id" BIGINT NOT NULL,
  CONSTRAINT "ck_crm_interacoes_usuario_id_6" CHECK ("usuario_id" BETWEEN 0 AND 4294967295),
  "usuario_nome" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "mensagem" TEXT COLLATE ci NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'comentario',
  CONSTRAINT "ck_crm_interacoes_tipo_10" CHECK ("tipo" IN ('comentario', 'mudanca_status', 'mudanca_prioridade', 'anexo', 'sistema')),
  "status_anterior" CHARACTER VARYING(50) COLLATE ci,
  "status_novo" CHARACTER VARYING(50) COLLATE ci,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."crm_relacionamentos" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_crm_relacionamentos_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "numero" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "remetente_id" BIGINT NOT NULL,
  CONSTRAINT "ck_crm_relacionamentos_remetente_id_5" CHECK ("remetente_id" BETWEEN 0 AND 4294967295),
  "remetente_nome" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "destinatario_tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'usuario',
  CONSTRAINT "ck_crm_relacionamentos_destinatario_tipo_8" CHECK ("destinatario_tipo" IN ('usuario', 'morador')),
  "destinatario_id" BIGINT NOT NULL,
  CONSTRAINT "ck_crm_relacionamentos_destinatario_id_10" CHECK ("destinatario_id" BETWEEN 0 AND 4294967295),
  "destinatario_nome" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "departamento" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_crm_relacionamentos_departamento_13" CHECK ("departamento" IN ('ADMINISTRATIVO', 'MANUTENCAO', 'FINANCEIRO', 'JURIDICO', 'CONTABIL')),
  "assunto" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'aberto',
  CONSTRAINT "ck_crm_relacionamentos_status_17" CHECK ("status" IN ('aberto', 'em_andamento', 'aguardando_retorno', 'finalizado', 'cancelado')),
  "prioridade" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'media',
  CONSTRAINT "ck_crm_relacionamentos_prioridade_19" CHECK ("prioridade" IN ('baixa', 'media', 'alta', 'urgente')),
  "data_limite" TIMESTAMP(0) WITHOUT TIME ZONE,
  "data_finalizacao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "sla_alertado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_crm_relacionamentos_sla_alertado_23" CHECK ("sla_alertado" BETWEEN -128 AND 127),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_crm_relacionamentos_ativo_25" CHECK ("ativo" BETWEEN -128 AND 127),
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "updated_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."crm_sequencia" (
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "ano" INTEGER NOT NULL,
  CONSTRAINT "ck_crm_sequencia_ano_2" CHECK ("ano" BETWEEN 0 AND 65535),
  "ultimo" BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_crm_sequencia_ultimo_4" CHECK ("ultimo" BETWEEN 0 AND 4294967295)
);

CREATE TABLE "erp"."dashboard_empresa_widgets" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('dashboard_empresa_widgets.id') NOT NULL,
  CONSTRAINT "ck_dashboard_empresa_widgets_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "widget_key" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "habilitado" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_dashboard_empresa_widgets_habilitado_5" CHECK ("habilitado" BETWEEN -128 AND 127),
  "atualizado_por" INTEGER,
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."dashboard_usuario_widgets" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('dashboard_usuario_widgets.id') NOT NULL,
  CONSTRAINT "ck_dashboard_usuario_widgets_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "usuario_id" INTEGER NOT NULL,
  "widget_key" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "habilitado" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_dashboard_usuario_widgets_habilitado_6" CHECK ("habilitado" BETWEEN -128 AND 127),
  "posicao" INTEGER NOT NULL DEFAULT 0,
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."dashboard_widgets_catalogo" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_dashboard_widgets_catalogo_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "modulo_key" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "modulo_nome" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "modulo_icone" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "widget_key" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "widget_nome" CHARACTER VARYING(160) COLLATE ci NOT NULL,
  "widget_tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'kpi',
  CONSTRAINT "ck_dashboard_widgets_catalogo_widget_tipo_8" CHECK ("widget_tipo" IN ('kpi', 'chart', 'list', 'alert', 'shortcut')),
  "descricao" CHARACTER VARYING(255) COLLATE ci NOT NULL DEFAULT '',
  "tamanho_padrao" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "ordem" INTEGER NOT NULL DEFAULT 0,
  "disponivel" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_dashboard_widgets_catalogo_disponivel_13" CHECK ("disponivel" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."departamentos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_departamentos_ativo_5" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."dependentes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "morador_id" INTEGER NOT NULL,
  "nome_completo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "cpf" CHARACTER VARYING(14) COLLATE ci NOT NULL,
  "email" CHARACTER VARYING(255) COLLATE ci,
  "telefone" CHARACTER VARYING(20) COLLATE ci,
  "celular" CHARACTER VARYING(20) COLLATE ci,
  "data_nascimento" DATE,
  "parentesco" CHARACTER VARYING COLLATE ci DEFAULT 'Outro',
  CONSTRAINT "ck_dependentes_parentesco_10" CHECK ("parentesco" IN ('Cônjuge', 'Filho(a)', 'Enteado(a)', 'Pai/Mãe', 'Irmão/Irmã', 'Avô/Avó', 'Outro')),
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_dependentes_ativo_12" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "observacao" TEXT COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."dispositivos_controlid_leituras" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "dispositivo_id" INTEGER NOT NULL,
  "controlid_log_id" BIGINT,
  "data_hora" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "tipo_evento" SMALLINT,
  CONSTRAINT "ck_dispositivos_controlid_leituras_tipo_evento_6" CHECK ("tipo_evento" BETWEEN -128 AND 127),
  "tag_value" CHARACTER VARYING(100) COLLATE ci,
  "card_value" BIGINT,
  "controlid_user_id" BIGINT,
  "veiculo_id" INTEGER,
  "morador_id" INTEGER,
  "acesso_liberado" SMALLINT DEFAULT 0,
  CONSTRAINT "ck_dispositivos_controlid_leituras_acesso_liberado_13" CHECK ("acesso_liberado" BETWEEN -128 AND 127),
  "processado" SMALLINT DEFAULT 0,
  CONSTRAINT "ck_dispositivos_controlid_leituras_processado_15" CHECK ("processado" BETWEEN -128 AND 127),
  "data_importacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."dispositivos_controlid_sync_log" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "dispositivo_id" INTEGER NOT NULL,
  "acao" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_dispositivos_controlid_sync_log_acao_4" CHECK ("acao" IN ('sincronizar_tags', 'testar_conexao', 'criar_usuario', 'remover_usuario', 'criar_tag', 'remover_tag', 'coletar_logs', 'configurar_push', 'configurar_online', 'enviar_comando', 'status_push')),
  "status" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_dispositivos_controlid_sync_log_status_6" CHECK ("status" IN ('sucesso', 'erro', 'parcial')),
  "detalhes" TEXT COLLATE ci,
  "total_enviados" INTEGER DEFAULT 0,
  "total_erros" INTEGER DEFAULT 0,
  "data_hora" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."documentos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(300) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "departamento_id" INTEGER,
  "pasta_id" INTEGER,
  "grupo_id" INTEGER,
  "tipo_id" INTEGER,
  "unidades_acesso" TEXT COLLATE ci,
  "visibilidade" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'todos',
  CONSTRAINT "ck_documentos_visibilidade_10" CHECK ("visibilidade" IN ('todos', 'moradores', 'usuarios', 'unidades_especificas')),
  "tags" TEXT COLLATE ci,
  "arquivo" CHARACTER VARYING(600) COLLATE ci,
  "arquivo_tipo" CHARACTER VARYING(100) COLLATE ci,
  "arquivo_tamanho" BIGINT DEFAULT 0,
  "arquivo_nome_original" CHARACTER VARYING(500) COLLATE ci,
  "link_externo" CHARACTER VARYING(1000) COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'ativo',
  CONSTRAINT "ck_documentos_status_18" CHECK ("status" IN ('ativo', 'inativo', 'expirado', 'rascunho')),
  "data_publicacao" DATE,
  "data_expiracao" DATE,
  "total_downloads" BIGINT DEFAULT 0,
  CONSTRAINT "ck_documentos_total_downloads_22" CHECK ("total_downloads" BETWEEN 0 AND 4294967295),
  "total_visualizacoes" BIGINT DEFAULT 0,
  CONSTRAINT "ck_documentos_total_visualizacoes_24" CHECK ("total_visualizacoes" BETWEEN 0 AND 4294967295),
  "criado_por" INTEGER,
  "atualizado_por" INTEGER,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "updated_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "os_id" INTEGER,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."documentos_departamentos_migrado_bkp" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "icone" CHARACTER VARYING(60) COLLATE ci,
  "cor" CHARACTER VARYING(7) COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_documentos_departamentos_migrado_bkp_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_por" INTEGER,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "updated_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."documentos_grupos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "acesso_tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'todos',
  CONSTRAINT "ck_documentos_grupos_acesso_tipo_5" CHECK ("acesso_tipo" IN ('todos', 'moradores', 'administradores', 'conselho', 'diretoria', 'financeiro', 'juridico', 'portaria', 'manutencao', 'prestadores', 'visitantes', 'personalizado')),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_documentos_grupos_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_por" INTEGER,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "updated_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."documentos_grupos_moradores" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "grupo_id" INTEGER NOT NULL,
  "morador_id" INTEGER NOT NULL,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."documentos_grupos_usuarios" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "grupo_id" INTEGER NOT NULL,
  "usuario_id" INTEGER NOT NULL,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."documentos_logs" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "documento_id" INTEGER,
  "usuario_id" INTEGER,
  "acao" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_documentos_logs_acao_5" CHECK ("acao" IN ('criacao', 'edicao', 'exclusao', 'download', 'visualizacao', 'compartilhamento', 'expiracao', 'restauracao', 'upload', 'acesso_negado')),
  "descricao" TEXT COLLATE ci,
  "ip" CHARACTER VARYING(45) COLLATE ci,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."documentos_pastas" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(200) COLLATE ci NOT NULL,
  "departamento_id" INTEGER,
  "pasta_pai_id" INTEGER,
  "descricao" TEXT COLLATE ci,
  "ordem" INTEGER DEFAULT 0,
  CONSTRAINT "ck_documentos_pastas_ordem_7" CHECK ("ordem" BETWEEN 0 AND 65535),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_documentos_pastas_ativo_9" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_por" INTEGER,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "updated_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."documentos_tipos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "icone" CHARACTER VARYING(60) COLLATE ci,
  "cor" CHARACTER VARYING(7) COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_documentos_tipos_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_por" INTEGER,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "updated_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."documentos_usuarios_acesso" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "documento_id" INTEGER NOT NULL,
  "usuario_id" INTEGER NOT NULL,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."email_alertas" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "codigo" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "modulo" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "evento" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_email_alertas_ativo_8" CHECK ("ativo" BETWEEN -128 AND 127),
  "assunto" CHARACTER VARYING(255) COLLATE ci NOT NULL DEFAULT '',
  "corpo_html" TEXT COLLATE ci,
  "variaveis" TEXT COLLATE ci,
  "destinatario_tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'admin',
  CONSTRAINT "ck_email_alertas_destinatario_tipo_13" CHECK ("destinatario_tipo" IN ('morador', 'admin', 'email_fixo', 'todos_admins')),
  "destinatario_email" CHARACTER VARYING(255) COLLATE ci,
  "cc_emails" TEXT COLLATE ci,
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."email_delivery_logs" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "provider_usado" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "fallback_chain" TEXT COLLATE ci,
  "destinatario" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "assunto" CHARACTER VARYING(500) COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'enviado',
  CONSTRAINT "ck_email_delivery_logs_status_7" CHECK ("status" IN ('enviado', 'erro', 'fallback', 'pendente')),
  "erro" TEXT COLLATE ci,
  "tempo_execucao" NUMERIC(8,3),
  "message_id" CHARACTER VARYING(255) COLLATE ci,
  "response_code" SMALLINT,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."email_log" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "alerta_codigo" CHARACTER VARYING(80) COLLATE ci,
  "morador_id" INTEGER,
  "destinatario" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "assunto" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_email_log_tipo_7" CHECK ("tipo" IN ('recuperacao_senha', 'boas_vindas', 'notificacao', 'outro')),
  "status" CHARACTER VARYING COLLATE ci DEFAULT 'pendente',
  CONSTRAINT "ck_email_log_status_9" CHECK ("status" IN ('enviado', 'erro', 'pendente')),
  "provider" CHARACTER VARYING(50) COLLATE ci,
  "message_id" CHARACTER VARYING(255) COLLATE ci,
  "response_code" INTEGER,
  "erro_detalhado" TEXT COLLATE ci,
  "erro_mensagem" TEXT COLLATE ci,
  "dados_contexto" TEXT COLLATE ci,
  "mensagem_erro" TEXT COLLATE ci,
  "data_envio" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "ip_origem" CHARACTER VARYING(45) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."email_templates" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_email_templates_tipo_3" CHECK ("tipo" IN ('recuperacao_senha', 'boas_vindas', 'notificacao')),
  "assunto" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "corpo" TEXT COLLATE ci NOT NULL,
  "variaveis" TEXT COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_email_templates_ativo_8" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."empresa" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "cnpj" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "razao_social" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "nome_fantasia" CHARACTER VARYING(255) COLLATE ci,
  "endereco_rua" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "endereco_numero" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "endereco_complemento" CHARACTER VARYING(255) COLLATE ci,
  "endereco_bairro" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "endereco_cidade" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "endereco_estado" CHARACTER VARYING(2) COLLATE ci NOT NULL,
  "endereco_cep" CHARACTER VARYING(10) COLLATE ci NOT NULL,
  "email_principal" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "email_cobranca" CHARACTER VARYING(255) COLLATE ci,
  "telefone" CHARACTER VARYING(20) COLLATE ci,
  "logo_url" CHARACTER VARYING(500) COLLATE ci,
  "logo_nome_arquivo" CHARACTER VARYING(255) COLLATE ci,
  "situacao" CHARACTER VARYING COLLATE ci DEFAULT 'ativo',
  CONSTRAINT "ck_empresa_situacao_18" CHECK ("situacao" IN ('ativo', 'inativo')),
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "usuario_criacao_id" INTEGER,
  "usuario_atualizacao_id" INTEGER,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."empresa_administradora" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "administradora_id" INTEGER NOT NULL,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_empresa_administradora_ativo_4" CHECK ("ativo" BETWEEN -128 AND 127),
  "usuario_atualizacao_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."empresa_administradora_layout" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "administradora_layout_id" INTEGER NOT NULL,
  "modulo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_empresa_administradora_layout_modulo_4" CHECK ("modulo" IN ('CONTAS_RECEBER', 'INADIMPLENCIA', 'CONTAS_PAGAR', 'EXTRATO_BANCARIO')),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_empresa_administradora_layout_ativo_6" CHECK ("ativo" BETWEEN -128 AND 127),
  "usuario_atualizacao_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."empresa_log" (
  "id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "empresa_id" INTEGER NOT NULL,
  "acao" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "dados_anteriores" JSON,
  "dados_novos" JSON,
  "usuario_id" INTEGER,
  "data_acao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "ip_usuario" CHARACTER VARYING(45) COLLATE ci
);

CREATE TABLE "erp"."eventos_condominio" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "titulo" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "local" CHARACTER VARYING(150) COLLATE ci,
  "data_inicio" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "data_fim" TIMESTAMP(0) WITHOUT TIME ZONE,
  "publico" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'TODOS',
  CONSTRAINT "ck_eventos_condominio_publico_8" CHECK ("publico" IN ('TODOS', 'UNIDADES')),
  "unidades_json" TEXT COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'AGENDADO',
  CONSTRAINT "ck_eventos_condominio_status_11" CHECK ("status" IN ('AGENDADO', 'CANCELADO', 'REALIZADO')),
  "notificado_chatin_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "notificados_total" INTEGER NOT NULL DEFAULT 0,
  "criado_por" INTEGER,
  "criado_por_nome" CHARACTER VARYING(150) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."grupos_inventario" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_grupos_inventario_ativo_5" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."hidrometro" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "unidade_id" INTEGER NOT NULL,
  "numero_hidrometro" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "localizacao" CHARACTER VARYING(255) COLLATE ci,
  "data_instalacao" DATE,
  "status" CHARACTER VARYING COLLATE ci DEFAULT 'ativo',
  CONSTRAINT "ck_hidrometro_status_7" CHECK ("status" IN ('ativo', 'inativo', 'manutencao')),
  "observacoes" TEXT COLLATE ci,
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."hidrometro_config" (
  "tenant_id" INTEGER NOT NULL,
  "limite_consumo_m3" NUMERIC(10,2),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("tenant_id")
);

CREATE TABLE "erp"."hidrometros" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "morador_id" INTEGER NOT NULL,
  "unidade" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "numero_hidrometro" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "numero_lacre" CHARACTER VARYING(50) COLLATE ci,
  "inventario_id" INTEGER,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_hidrometros_ativo_8" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_instalacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."hidrometros_historico" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "hidrometro_id" INTEGER NOT NULL,
  "campo_alterado" CHARACTER VARYING(100) COLLATE ci,
  "valor_anterior" TEXT COLLATE ci,
  "valor_novo" TEXT COLLATE ci,
  "observacao" TEXT COLLATE ci NOT NULL,
  "usuario" CHARACTER VARYING(100) COLLATE ci,
  "data_alteracao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."historico_importacoes_ofx" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_historico_importacoes_ofx_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "conta_id" BIGINT NOT NULL,
  CONSTRAINT "ck_historico_importacoes_ofx_conta_id_4" CHECK ("conta_id" BETWEEN 0 AND 4294967295),
  "nome_arquivo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "banco_id_ofx" CHARACTER VARYING(20) COLLATE ci,
  "acct_id_ofx" CHARACTER VARYING(40) COLLATE ci,
  "dt_inicio_ofx" DATE,
  "dt_fim_ofx" DATE,
  "ultimo_fitid" CHARACTER VARYING(80) COLLATE ci,
  "ultima_data" DATE,
  "total_transacoes" BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_historico_importacoes_ofx_total_transacoes_13" CHECK ("total_transacoes" BETWEEN 0 AND 4294967295),
  "importadas" BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_historico_importacoes_ofx_importadas_15" CHECK ("importadas" BETWEEN 0 AND 4294967295),
  "duplicadas" BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_historico_importacoes_ofx_duplicadas_17" CHECK ("duplicadas" BETWEEN 0 AND 4294967295),
  "saldo_final_ofx" NUMERIC(15,2),
  "importado_por" CHARACTER VARYING(80) COLLATE ci,
  "importado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "conciliadas_auto" BIGINT DEFAULT 0,
  CONSTRAINT "ck_historico_importacoes_ofx_conciliadas_auto_22" CHECK ("conciliadas_auto" BETWEEN 0 AND 4294967295),
  "pendentes" BIGINT DEFAULT 0,
  CONSTRAINT "ck_historico_importacoes_ofx_pendentes_24" CHECK ("pendentes" BETWEEN 0 AND 4294967295),
  "formato_ofx" CHARACTER VARYING COLLATE ci DEFAULT 'desconhecido',
  CONSTRAINT "ck_historico_importacoes_ofx_formato_ofx_26" CHECK ("formato_ofx" IN ('sgml', 'xml', 'desconhecido')),
  "tempo_ms" BIGINT,
  CONSTRAINT "ck_historico_importacoes_ofx_tempo_ms_28" CHECK ("tempo_ms" BETWEEN 0 AND 4294967295),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."historico_pagamentos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_historico_pagamentos_tipo_3" CHECK ("tipo" IN ('PAGAR', 'RECEBER')),
  "conta_id" INTEGER NOT NULL,
  "valor" NUMERIC(12,2) NOT NULL,
  "data_pagamento" DATE NOT NULL,
  "forma_pagamento" CHARACTER VARYING(50) COLLATE ci,
  "numero_comprovante" CHARACTER VARYING(100) COLLATE ci,
  "observacoes" TEXT COLLATE ci,
  "usuario" CHARACTER VARYING(100) COLLATE ci,
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."historico_status_pedido" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "pedido_id" INTEGER NOT NULL,
  "status_anterior" CHARACTER VARYING(50) COLLATE ci,
  "status_novo" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "usuario_tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_historico_status_pedido_usuario_tipo_6" CHECK ("usuario_tipo" IN ('morador', 'fornecedor', 'admin')),
  "usuario_id" INTEGER NOT NULL,
  "observacao" TEXT COLLATE ci,
  "data_mudanca" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."importacoes_financeiras" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome_arquivo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "tipo_arquivo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'CSV',
  CONSTRAINT "ck_importacoes_financeiras_tipo_arquivo_4" CHECK ("tipo_arquivo" IN ('CSV', 'PDF')),
  "tipo_conta" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'PAGAR',
  CONSTRAINT "ck_importacoes_financeiras_tipo_conta_6" CHECK ("tipo_conta" IN ('PAGAR', 'RECEBER')),
  "data_inicio" DATE,
  "data_fim" DATE,
  "total_registros" INTEGER DEFAULT 0,
  "total_importados" INTEGER DEFAULT 0,
  "total_duplicatas" INTEGER DEFAULT 0,
  "total_erros" INTEGER DEFAULT 0,
  "status" CHARACTER VARYING COLLATE ci DEFAULT 'PROCESSANDO',
  CONSTRAINT "ck_importacoes_financeiras_status_14" CHECK ("status" IN ('PROCESSANDO', 'CONCLUIDO', 'ERRO')),
  "usuario" CHARACTER VARYING(100) COLLATE ci,
  "data_importacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."importacoes_financeiras_itens" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "importacao_id" INTEGER NOT NULL,
  "linha_original" INTEGER,
  "numero_documento" CHARACTER VARYING(100) COLLATE ci,
  "fornecedor_nome" CHARACTER VARYING(255) COLLATE ci,
  "classificacao_despesa" CHARACTER VARYING(255) COLLATE ci,
  "centro_custo" CHARACTER VARYING(100) COLLATE ci,
  "observacao" TEXT COLLATE ci,
  "valor" NUMERIC(12,2),
  "data_vencimento" DATE,
  "data_pagamento" DATE,
  "status_original" CHARACTER VARYING(50) COLLATE ci,
  "aprovada" SMALLINT DEFAULT 0,
  CONSTRAINT "ck_importacoes_financeiras_itens_aprovada_14" CHECK ("aprovada" BETWEEN -128 AND 127),
  "status_importacao" CHARACTER VARYING COLLATE ci DEFAULT 'PENDENTE',
  CONSTRAINT "ck_importacoes_financeiras_itens_status_importacao_16" CHECK ("status_importacao" IN ('PENDENTE', 'IMPORTADO', 'DUPLICATA', 'ERRO', 'CONCILIADO', 'IGNORADO')),
  "conta_id" INTEGER,
  "duplicata_conta_id" INTEGER,
  "mensagem_erro" TEXT COLLATE ci,
  "conciliado_por" CHARACTER VARYING(100) COLLATE ci,
  "data_conciliacao" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."inadimplencia_comparacoes" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "importacao_atual_id" INTEGER NOT NULL,
  "importacao_anterior_id" INTEGER,
  "status_comparacao" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'PRIMEIRO_SNAPSHOT',
  CONSTRAINT "ck_inadimplencia_comparacoes_status_comparacao_5" CHECK ("status_comparacao" IN ('PRIMEIRO_SNAPSHOT', 'SEM_MUDANCA', 'ATUALIZADO')),
  "delta_total_projetado" NUMERIC(14,2) NOT NULL DEFAULT 0.00,
  "variacao_pct" NUMERIC(9,4),
  "total_novas_glebas" INTEGER NOT NULL DEFAULT 0,
  "total_evoluindo" INTEGER NOT NULL DEFAULT 0,
  "total_corrigidas" INTEGER NOT NULL DEFAULT 0,
  "total_quitadas" INTEGER NOT NULL DEFAULT 0,
  "total_risco_alto" INTEGER NOT NULL DEFAULT 0,
  "resumo_json" TEXT COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."inadimplencia_importacoes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "arquivo_id" INTEGER,
  "nome_arquivo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "fonte_sistema" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "layout_id" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "layout_versao" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "associacao_nome" CHARACTER VARYING(255) COLLATE ci,
  "data_base" DATE,
  "data_geracao_relatorio" TIMESTAMP(0) WITHOUT TIME ZONE,
  "indicador_correcao" CHARACTER VARYING(100) COLLATE ci,
  "indicador_juros_pct" NUMERIC(7,2),
  "indicador_multa_pct" NUMERIC(7,2),
  "quantidade_unidades" INTEGER NOT NULL DEFAULT 0,
  "total_lancado" NUMERIC(14,2) NOT NULL DEFAULT 0.00,
  "total_projetado" NUMERIC(14,2) NOT NULL DEFAULT 0.00,
  "total_lancado_relatorio" NUMERIC(14,2),
  "total_projetado_relatorio" NUMERIC(14,2),
  "totais_reconciliam" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_inadimplencia_importacoes_totais_reconciliam_19" CHECK ("totais_reconciliam" BETWEEN -128 AND 127),
  "alerta_reconciliacao" CHARACTER VARYING(500) COLLATE ci,
  "total_lancamentos" INTEGER NOT NULL DEFAULT 0,
  "total_sem_vinculo" INTEGER NOT NULL DEFAULT 0,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'PROCESSANDO',
  CONSTRAINT "ck_inadimplencia_importacoes_status_24" CHECK ("status" IN ('PROCESSANDO', 'CONCLUIDO', 'ERRO')),
  "mensagem_erro" TEXT COLLATE ci,
  "usuario" CHARACTER VARYING(100) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."inadimplencia_lancamentos" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "importacao_id" INTEGER NOT NULL,
  "gleba_numero" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "carteira_status" CHARACTER VARYING(160) COLLATE ci,
  "permite_receber" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_inadimplencia_lancamentos_permite_receber_6" CHECK ("permite_receber" BETWEEN -128 AND 127),
  "participa_cobranca" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_inadimplencia_lancamentos_participa_cobranca_8" CHECK ("participa_cobranca" BETWEEN -128 AND 127),
  "proprietario_nome" CHARACTER VARYING(255) COLLATE ci,
  "proprietario_cpf" CHARACTER VARYING(20) COLLATE ci,
  "proprietario_cpf_digitos" CHARACTER VARYING(14) COLLATE ci,
  "identificador_lancamento" CHARACTER VARYING(50) COLLATE ci,
  "chave_alternativa" CHARACTER VARYING(255) COLLATE ci,
  "chave_comparacao" CHARACTER VARYING(320) COLLATE ci NOT NULL,
  "tipo_cobranca" CHARACTER VARYING(80) COLLATE ci,
  "descricao_original" CHARACTER VARYING(500) COLLATE ci,
  "mes_referencia" DATE,
  "vencimento" DATE,
  "valor" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
  "juros" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
  "multa" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
  "correcao" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
  "projecao_recebimento" NUMERIC(12,2) NOT NULL DEFAULT 0.00,
  "morador_id" INTEGER,
  "unidade_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."inventario" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "numero_patrimonio" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "nome_item" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "fabricante" CHARACTER VARYING(100) COLLATE ci,
  "modelo" CHARACTER VARYING(100) COLLATE ci,
  "numero_serie" CHARACTER VARYING(100) COLLATE ci,
  "nf" CHARACTER VARYING(50) COLLATE ci,
  "data_compra" DATE,
  "situacao" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'imobilizado',
  CONSTRAINT "ck_inventario_situacao_10" CHECK ("situacao" IN ('imobilizado', 'circulante')),
  "valor" NUMERIC(10,2),
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'ativo',
  CONSTRAINT "ck_inventario_status_13" CHECK ("status" IN ('ativo', 'inativo')),
  "motivo_baixa" TEXT COLLATE ci,
  "data_baixa" DATE,
  "tutela_usuario_id" INTEGER,
  "grupo_id" INTEGER,
  "observacoes" TEXT COLLATE ci,
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."leituras" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "hidrometro_id" INTEGER NOT NULL,
  "morador_id" INTEGER NOT NULL,
  "lancado_por_tipo" CHARACTER VARYING COLLATE ci DEFAULT 'usuario',
  CONSTRAINT "ck_leituras_lancado_por_tipo_5" CHECK ("lancado_por_tipo" IN ('usuario', 'morador')),
  "lancado_por_id" INTEGER,
  "lancado_por_nome" CHARACTER VARYING(255) COLLATE ci,
  "unidade" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "leitura_anterior" NUMERIC(10,2) DEFAULT 0.00,
  "leitura_atual" NUMERIC(10,2) NOT NULL,
  "consumo" NUMERIC(10,2) NOT NULL,
  "valor_metro_cubico" NUMERIC(10,2) DEFAULT 6.16,
  "valor_minimo" NUMERIC(10,2) DEFAULT 61.60,
  "valor_total" NUMERIC(10,2) NOT NULL,
  "data_leitura" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "client_uuid" CHARACTER VARYING(64) COLLATE ci,
  "observacao" TEXT COLLATE ci,
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "lancamento_manual" SMALLINT DEFAULT 0,
  CONSTRAINT "ck_leituras_lancamento_manual_20" CHECK ("lancamento_manual" BETWEEN -128 AND 127),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."local_acessos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "observacao" TEXT COLLATE ci,
  "situacao" CHARACTER VARYING COLLATE ci DEFAULT 'ativo',
  CONSTRAINT "ck_local_acessos_situacao_6" CHECK ("situacao" IN ('ativo', 'inativo')),
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "usuario_criacao_id" INTEGER,
  "usuario_atualizacao_id" INTEGER,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."local_acessos_log" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "local_acesso_id" INTEGER NOT NULL,
  "acao" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "dados_anteriores" JSON,
  "dados_novos" JSON,
  "usuario_id" INTEGER,
  "data_acao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "ip_usuario" CHARACTER VARYING(45) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."local_acessos_tipos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "local_acesso_id" INTEGER NOT NULL,
  "tipo_usuario" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_local_acessos_tipos_tipo_usuario_4" CHECK ("tipo_usuario" IN ('morador', 'visitante', 'dependente')),
  "permissao_acesso" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_local_acessos_tipos_permissao_acesso_6" CHECK ("permissao_acesso" BETWEEN -128 AND 127),
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."logs_erro" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'outro',
  CONSTRAINT "ck_logs_erro_tipo_3" CHECK ("tipo" IN ('javascript', 'php', 'api', 'banco', 'outro')),
  "nivel" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'error',
  CONSTRAINT "ck_logs_erro_nivel_5" CHECK ("nivel" IN ('debug', 'info', 'warning', 'error', 'critical')),
  "arquivo" CHARACTER VARYING(255) COLLATE ci,
  "funcao" CHARACTER VARYING(255) COLLATE ci,
  "linha" INTEGER,
  "mensagem" TEXT COLLATE ci NOT NULL,
  "stack_trace" TEXT COLLATE ci,
  "contexto" JSON,
  "url" CHARACTER VARYING(500) COLLATE ci,
  "user_agent" TEXT COLLATE ci,
  "usuario_id" INTEGER,
  "ip_address" CHARACTER VARYING(45) COLLATE ci,
  "data_hora" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."logs_financeiro" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "modulo" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "nivel" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'INFO',
  CONSTRAINT "ck_logs_financeiro_nivel_4" CHECK ("nivel" IN ('INFO', 'AVISO', 'ERRO', 'DEBUG', 'CRITICO')),
  "acao" CHARACTER VARYING(100) COLLATE ci,
  "mensagem" TEXT COLLATE ci NOT NULL,
  "detalhe" TEXT COLLATE ci,
  "usuario" CHARACTER VARYING(100) COLLATE ci,
  "ip" CHARACTER VARYING(45) COLLATE ci,
  "user_agent" CHARACTER VARYING(255) COLLATE ci,
  "request_method" CHARACTER VARYING(10) COLLATE ci,
  "request_uri" CHARACTER VARYING(500) COLLATE ci,
  "post_data" TEXT COLLATE ci,
  "referencia_id" INTEGER,
  "duracao_ms" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."logs_sistema" (
  "id" INTEGER NOT NULL,
  "tipo" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci NOT NULL,
  "usuario" CHARACTER VARYING(100) COLLATE ci,
  "ip" CHARACTER VARYING(50) COLLATE ci,
  "data_hora" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp())
);

CREATE TABLE "erp"."manual_artigos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "modulo_id" INTEGER NOT NULL,
  "categoria_id" INTEGER,
  "titulo" CHARACTER VARYING(200) COLLATE ci NOT NULL,
  "resumo" TEXT COLLATE ci,
  "conteudo" TEXT COLLATE ci,
  "tags" CHARACTER VARYING(255) COLLATE ci,
  "video_url" CHARACTER VARYING(255) COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci DEFAULT 'publicado',
  CONSTRAINT "ck_manual_artigos_status_10" CHECK ("status" IN ('rascunho', 'publicado', 'desatualizado')),
  "versao" CHARACTER VARYING(20) COLLATE ci,
  "visualizacoes" INTEGER DEFAULT 0,
  "criado_por" INTEGER NOT NULL,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0),
  "atualizado_por" INTEGER,
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."manual_avaliacoes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "artigo_id" INTEGER NOT NULL,
  "usuario_id" INTEGER NOT NULL,
  "util" SMALLINT NOT NULL,
  CONSTRAINT "ck_manual_avaliacoes_util_5" CHECK ("util" BETWEEN -128 AND 127),
  "comentario" TEXT COLLATE ci,
  "data_avaliacao" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."manual_buscas" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "termo" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "usuario_id" INTEGER,
  "resultados" INTEGER DEFAULT 0,
  "data_busca" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."manual_categorias" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "modulo_id" INTEGER NOT NULL,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "ordem" INTEGER DEFAULT 0,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."manual_favoritos" (
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "usuario_id" INTEGER NOT NULL,
  "artigo_id" INTEGER NOT NULL,
  "data_favorito" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0)
);

CREATE TABLE "erp"."manual_historico" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "artigo_id" INTEGER NOT NULL,
  "versao" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "titulo" CHARACTER VARYING(200) COLLATE ci NOT NULL,
  "conteudo" TEXT COLLATE ci,
  "modificado_por" INTEGER NOT NULL,
  "modificado_em" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."manual_modulos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "icone" CHARACTER VARYING(50) COLLATE ci,
  "page_id" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "ordem" INTEGER DEFAULT 0,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."marcas_dispositivo" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "logo" CHARACTER VARYING(255) COLLATE ci,
  "site" CHARACTER VARYING(255) COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_marcas_dispositivo_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."media_avaliacoes_fornecedor" (
  "fornecedor_id" INTEGER,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome_estabelecimento" CHARACTER VARYING(200) COLLATE ci,
  "total_avaliacoes" BIGINT,
  "media_nota_morador" NUMERIC(13,2),
  "media_nota_fornecedor" NUMERIC(13,2),
  "media_geral" NUMERIC(14,2)
);

CREATE TABLE "erp"."media_avaliacoes_produto" (
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "produto_servico_id" INTEGER,
  "nome" CHARACTER VARYING(200) COLLATE ci,
  "tipo" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_media_avaliacoes_produto_tipo_4" CHECK ("tipo" IN ('produto', 'servico')),
  "fornecedor_id" INTEGER,
  "nome_estabelecimento" CHARACTER VARYING(200) COLLATE ci,
  "total_avaliacoes" BIGINT,
  "media_nota" NUMERIC(13,2)
);

CREATE TABLE "erp"."modelos_dispositivo" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "marca_id" INTEGER NOT NULL,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "tipo_id" INTEGER,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_modelos_dispositivo_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."modulos_sistema" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_modulos_sistema_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "chave" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "grupo" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "icone" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "permissao_minima" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'operador',
  CONSTRAINT "ck_modulos_sistema_permissao_minima_8" CHECK ("permissao_minima" IN ('visualizador', 'operador', 'gerente', 'admin')),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_modulos_sistema_ativo_10" CHECK ("ativo" BETWEEN -128 AND 127),
  "ordem" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."monitoramento_cameras" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "agente_id" INTEGER NOT NULL,
  "external_key" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "fabricante" CHARACTER VARYING(60) COLLATE ci,
  "modelo" CHARACTER VARYING(120) COLLATE ci,
  "origem_stream" CHARACTER VARYING(30) COLLATE ci NOT NULL,
  "canal" CHARACTER VARYING(30) COLLATE ci,
  "sentido" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_monitoramento_cameras_ativo_11" CHECK ("ativo" BETWEEN -128 AND 127),
  "config_version" INTEGER NOT NULL DEFAULT 1,
  "ultimo_status" CHARACTER VARYING(20) COLLATE ci,
  "ultimo_frame_at" TIMESTAMP(0) WITHOUT TIME ZONE,
  "frames_perdidos" INTEGER NOT NULL DEFAULT 0,
  "ultimo_erro_code" CHARACTER VARYING(60) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."monitoramento_configuracoes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "modulo_ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_monitoramento_configuracoes_modulo_ativo_3" CHECK ("modulo_ativo" BETWEEN -128 AND 127),
  "retencao_dias" INTEGER NOT NULL DEFAULT 30,
  "lpr_engine" CHARACTER VARYING(30) COLLATE ci NOT NULL,
  "onnx_backend" CHARACTER VARYING(30) COLLATE ci NOT NULL,
  "confidence_min" NUMERIC(5,4) NOT NULL DEFAULT 0.8000,
  "dedup_seconds" INTEGER NOT NULL DEFAULT 20,
  "versao_minima_agente" CHARACTER VARYING(30) COLLATE ci,
  "config_version" INTEGER NOT NULL DEFAULT 1,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."monitoramento_dispositivos_local" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "agente_id" INTEGER NOT NULL,
  "external_key" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "tipo_dispositivo" CHARACTER VARYING(40) COLLATE ci NOT NULL,
  "protocolo" CHARACTER VARYING(40) COLLATE ci NOT NULL,
  "fabricante" CHARACTER VARYING(80) COLLATE ci,
  "modelo" CHARACTER VARYING(120) COLLATE ci,
  "host_configurado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_monitoramento_dispositivos_local_host_configurado_10" CHECK ("host_configurado" BETWEEN -128 AND 127),
  "porta" INTEGER,
  "sentido" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_monitoramento_dispositivos_local_ativo_14" CHECK ("ativo" BETWEEN -128 AND 127),
  "ultimo_status" CHARACTER VARYING(30) COLLATE ci NOT NULL,
  "ultimo_heartbeat_at" TIMESTAMP(0) WITHOUT TIME ZONE,
  "ultimo_evento_at" TIMESTAMP(0) WITHOUT TIME ZONE,
  "ultimo_erro_code" CHARACTER VARYING(80) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."monitoramento_evento_acesso" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "evento_id" BIGINT NOT NULL,
  "registro_acesso_id" INTEGER NOT NULL,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."monitoramento_evento_revisoes" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "evento_id" BIGINT NOT NULL,
  "usuario_id" INTEGER,
  "campo" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "valor_anterior" TEXT COLLATE ci,
  "valor_novo" TEXT COLLATE ci,
  "motivo" CHARACTER VARYING(255) COLLATE ci,
  "ip_address" CHARACTER VARYING(45) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."monitoramento_eventos" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "agente_id" INTEGER NOT NULL,
  "camera_id" INTEGER,
  "camera_external_key" CHARACTER VARYING(80) COLLATE ci,
  "event_uuid" CHARACTER(36) COLLATE ci NOT NULL,
  "capturado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "placa_raw" CHARACTER VARYING(32) COLLATE ci NOT NULL,
  "placa_normalizada" CHARACTER VARYING(16) COLLATE ci NOT NULL,
  "placa_corrigida" CHARACTER VARYING(16) COLLATE ci,
  "detection_confidence" NUMERIC(5,4),
  "ocr_confidence" NUMERIC(5,4),
  "direcao" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "tipo_evento" CHARACTER VARYING(30) COLLATE ci NOT NULL,
  "motor" CHARACTER VARYING(40) COLLATE ci NOT NULL,
  "motor_versao" CHARACTER VARYING(40) COLLATE ci,
  "match_status" CHARACTER VARYING(40) COLLATE ci NOT NULL,
  "veiculo_id" INTEGER,
  "morador_id" INTEGER,
  "snapshot_ref" CHARACTER VARYING(500) COLLATE ci,
  "snapshot_sha256" CHARACTER(64) COLLATE ci,
  "status_evento" CHARACTER VARYING(30) COLLATE ci NOT NULL,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."monitoring_releases" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "version_name" CHARACTER VARYING(40) COLLATE ci NOT NULL,
  "version_code" INTEGER NOT NULL,
  "channel" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'interno',
  CONSTRAINT "ck_monitoring_releases_channel_4" CHECK ("channel" IN ('interno', 'teste', 'producao')),
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'rascunho',
  CONSTRAINT "ck_monitoring_releases_status_6" CHECK ("status" IN ('rascunho', 'publicado', 'arquivado')),
  "download_url" CHARACTER VARYING(700) COLLATE ci NOT NULL,
  "sha256" CHARACTER(64) COLLATE ci NOT NULL,
  "size_bytes" NUMERIC(20,0) NOT NULL DEFAULT 0,
  CONSTRAINT "ck_monitoring_releases_size_bytes_10" CHECK ("size_bytes" BETWEEN 0 AND 18446744073709551615),
  "mandatory" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_monitoring_releases_mandatory_12" CHECK ("mandatory" BETWEEN -128 AND 127),
  "minimum_version_code" INTEGER NOT NULL DEFAULT 0,
  "release_notes" TEXT COLLATE ci,
  "published_at" TIMESTAMP(0) WITHOUT TIME ZONE,
  "created_by_user_id" INTEGER,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "updated_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."monitoring_update_log" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "release_id" INTEGER,
  "agent_id" INTEGER,
  "current_version_code" INTEGER,
  "action" CHARACTER VARYING(40) COLLATE ci NOT NULL,
  "detail" CHARACTER VARYING(500) COLLATE ci,
  "ip_address" CHARACTER VARYING(64) COLLATE ci,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."movimentacoes_bancarias" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('movimentacoes_bancarias.id') NOT NULL,
  CONSTRAINT "ck_movimentacoes_bancarias_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "conta_id" BIGINT NOT NULL,
  CONSTRAINT "ck_movimentacoes_bancarias_conta_id_4" CHECK ("conta_id" BETWEEN 0 AND 4294967295),
  "fitid" CHARACTER VARYING(80) COLLATE ci,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_movimentacoes_bancarias_tipo_7" CHECK ("tipo" IN ('credito', 'debito')),
  "valor" NUMERIC(15,2) NOT NULL,
  "data_lancamento" DATE NOT NULL,
  "descricao" CHARACTER VARYING(500) COLLATE ci NOT NULL,
  "favorecido" CHARACTER VARYING(255) COLLATE ci,
  "memo" TEXT COLLATE ci,
  "payee" CHARACTER VARYING(255) COLLATE ci,
  "checknum" CHARACTER VARYING(30) COLLATE ci,
  "numero_documento" CHARACTER VARYING(60) COLLATE ci,
  "categoria" CHARACTER VARYING(80) COLLATE ci,
  "centro_custo" CHARACTER VARYING(80) COLLATE ci,
  "conciliado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_movimentacoes_bancarias_conciliado_19" CHECK ("conciliado" BETWEEN -128 AND 127),
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'pendente',
  CONSTRAINT "ck_movimentacoes_bancarias_status_21" CHECK ("status" IN ('pendente', 'conciliado', 'ignorado', 'divergente')),
  "origem" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'ofx',
  CONSTRAINT "ck_movimentacoes_bancarias_origem_23" CHECK ("origem" IN ('ofx', 'manual', 'importacao')),
  "importacao_id" BIGINT,
  CONSTRAINT "ck_movimentacoes_bancarias_importacao_id_25" CHECK ("importacao_id" BETWEEN 0 AND 4294967295),
  "conta_receber_id" BIGINT,
  CONSTRAINT "ck_movimentacoes_bancarias_conta_receber_id_27" CHECK ("conta_receber_id" BETWEEN 0 AND 4294967295),
  "conta_pagar_id" BIGINT,
  CONSTRAINT "ck_movimentacoes_bancarias_conta_pagar_id_29" CHECK ("conta_pagar_id" BETWEEN 0 AND 4294967295),
  "observacoes" TEXT COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "data_importacao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "usuario_importacao" CHARACTER VARYING(80) COLLATE ci,
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "banco_origem" CHARACTER VARYING(20) COLLATE ci,
  "conciliacao_id" BIGINT,
  CONSTRAINT "ck_movimentacoes_bancarias_conciliacao_id_37" CHECK ("conciliacao_id" BETWEEN 0 AND 4294967295),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."movimentacoes_estoque" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "produto_id" INTEGER NOT NULL,
  "tipo_movimentacao" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_movimentacoes_estoque_tipo_movimentacao_4" CHECK ("tipo_movimentacao" IN ('Entrada', 'Saida', 'Ajuste', 'Devolucao')),
  "quantidade" NUMERIC(10,2) NOT NULL,
  "quantidade_anterior" NUMERIC(10,2) NOT NULL,
  "quantidade_posterior" NUMERIC(10,2) NOT NULL,
  "tipo_destino" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_movimentacoes_estoque_tipo_destino_9" CHECK ("tipo_destino" IN ('Morador', 'Administracao', 'Manutencao', 'Limpeza', 'Outro')),
  "morador_id" INTEGER,
  "usuario_responsavel" CHARACTER VARYING(200) COLLATE ci,
  "motivo" CHARACTER VARYING(500) COLLATE ci,
  "nota_fiscal" CHARACTER VARYING(100) COLLATE ci,
  "valor_unitario" NUMERIC(10,2) DEFAULT 0.00,
  "valor_total" NUMERIC(10,2) DEFAULT 0.00,
  "fornecedor" CHARACTER VARYING(200) COLLATE ci,
  "data_movimentacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "observacoes" TEXT COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."mt_consolidacao_exclusoes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tabela" CHARACTER VARYING(128) COLLATE ci NOT NULL,
  "motivo" CHARACTER VARYING(500) COLLATE ci NOT NULL,
  "registrado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."mt_consolidacao_tabelas" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tabela" CHARACTER VARYING(128) COLLATE ci NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "registros_origem" BIGINT NOT NULL DEFAULT 0,
  "registros_destino_antes" BIGINT NOT NULL DEFAULT 0,
  "registros_destino_depois" BIGINT NOT NULL DEFAULT 0,
  "status" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_mt_consolidacao_tabelas_status_7" CHECK ("status" IN ('concluida', 'ignorada', 'erro')),
  "mensagem" TEXT COLLATE ci,
  "executado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."mt_integridade_ids_log" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tabela" CHARACTER VARYING(128) COLLATE ci NOT NULL,
  "primary_key_id" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_mt_integridade_ids_log_primary_key_id_3" CHECK ("primary_key_id" BETWEEN -128 AND 127),
  "auto_increment_id" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_mt_integridade_ids_log_auto_increment_id_5" CHECK ("auto_increment_id" BETWEEN -128 AND 127),
  "ids_invalidos" BIGINT NOT NULL DEFAULT 0,
  "ids_duplicados" BIGINT NOT NULL DEFAULT 0,
  "acao" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_mt_integridade_ids_log_acao_9" CHECK ("acao" IN ('corrigida', 'pendente', 'ignorada')),
  "mensagem" CHARACTER VARYING(500) COLLATE ci NOT NULL,
  "executado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."mt_os_chamados_id_zero_backup" (
  "id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "numero" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "titulo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "assunto_id" INTEGER,
  "departamento" CHARACTER VARYING(100) COLLATE ci,
  "prioridade" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'media',
  CONSTRAINT "ck_mt_os_chamados_id_zero_backup_prioridade_7" CHECK ("prioridade" IN ('baixa', 'media', 'alta', 'urgente')),
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'aberto',
  CONSTRAINT "ck_mt_os_chamados_id_zero_backup_status_9" CHECK ("status" IN ('aberto', 'andamento', 'finalizado', 'cancelado')),
  "projeto_publico" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_mt_os_chamados_id_zero_backup_projeto_publico_11" CHECK ("projeto_publico" BETWEEN -128 AND 127),
  "morador_id" INTEGER,
  "morador_nome" CHARACTER VARYING(255) COLLATE ci,
  "morador_unidade" CHARACTER VARYING(50) COLLATE ci,
  "atendente_id" INTEGER,
  "atendente_nome" CHARACTER VARYING(255) COLLATE ci,
  "descricao" TEXT COLLATE ci,
  "horas_estimadas" NUMERIC(8,2),
  "horas_totais" NUMERIC(8,2),
  "os_pai_id" INTEGER,
  "data_abertura" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "data_inicio" TIMESTAMP(0) WITHOUT TIME ZONE,
  "data_finalizacao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "data_previsao" DATE,
  "criado_por_id" INTEGER,
  "criado_por_nome" CHARACTER VARYING(255) COLLATE ci,
  "origem_portal" CHARACTER VARYING(30) COLLATE ci,
  "assumido_por_id" INTEGER,
  "assumido_por_nome" CHARACTER VARYING(150) COLLATE ci,
  "data_assumido" TIMESTAMP(0) WITHOUT TIME ZONE,
  "observacao_finalizacao" TEXT COLLATE ci,
  "projeto_nome" CHARACTER VARYING(255) COLLATE ci,
  "projeto_descricao" TEXT COLLATE ci,
  "projeto_objetivo" TEXT COLLATE ci,
  "projeto_beneficios" TEXT COLLATE ci,
  "projeto_localizacao" CHARACTER VARYING(255) COLLATE ci,
  "projeto_responsavel" CHARACTER VARYING(255) COLLATE ci,
  "projeto_departamento_id" INTEGER,
  "projeto_data_inicio_prevista" DATE,
  "projeto_data_fim_prevista" DATE,
  "projeto_imagem_capa" CHARACTER VARYING(255) COLLATE ci,
  "projeto_percentual" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_mt_os_chamados_id_zero_backup_projeto_percentual_43" CHECK ("projeto_percentual" BETWEEN 0 AND 255),
  "projeto_etapa_id" INTEGER,
  "projeto_status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'planejamento',
  CONSTRAINT "ck_mt_os_chamados_id_zero_backup_projeto_status_46" CHECK ("projeto_status" IN ('planejamento', 'execucao', 'paralisado', 'finalizado'))
);

CREATE TABLE "erp"."mt_os_integridade_log" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "executado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "status" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_mt_os_integridade_log_status_3" CHECK ("status" IN ('corrigido', 'pendente', 'ignorado')),
  "os_com_id_zero" INTEGER NOT NULL DEFAULT 0,
  "ids_duplicados_nao_zero" INTEGER NOT NULL DEFAULT 0,
  "interacoes_orfas_id_zero" INTEGER NOT NULL DEFAULT 0,
  "materiais_orfaos_id_zero" INTEGER NOT NULL DEFAULT 0,
  "recursos_orfaos_id_zero" INTEGER NOT NULL DEFAULT 0,
  "fotos_orfas_id_zero" INTEGER NOT NULL DEFAULT 0,
  "mensagem" CHARACTER VARYING(1000) COLLATE ci NOT NULL,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."mt_protocolos_id_zero_backup" (
  "id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "unidade_id" INTEGER NOT NULL,
  "morador_id" INTEGER NOT NULL,
  "descricao_mercadoria" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "codigo_nf" CHARACTER VARYING(100) COLLATE ci,
  "pagina" INTEGER,
  "data_hora_recebimento" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "recebedor_portaria" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "observacao" TEXT COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci DEFAULT 'pendente',
  CONSTRAINT "ck_mt_protocolos_id_zero_backup_status_11" CHECK ("status" IN ('pendente', 'entregue')),
  "nome_recebedor_morador" CHARACTER VARYING(100) COLLATE ci,
  "data_hora_entrega" TIMESTAMP(0) WITHOUT TIME ZONE,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp())
);

CREATE TABLE "erp"."mt_reconstrucao_exclusoes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tabela" CHARACTER VARYING(128) COLLATE ci NOT NULL,
  "motivo" CHARACTER VARYING(500) COLLATE ci NOT NULL,
  "registrado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."mt_reconstrucao_tabelas" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tabela" CHARACTER VARYING(128) COLLATE ci NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "registros_origem" BIGINT NOT NULL DEFAULT 0,
  "registros_removidos" BIGINT NOT NULL DEFAULT 0,
  "registros_importados" BIGINT NOT NULL DEFAULT 0,
  "status" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_mt_reconstrucao_tabelas_status_7" CHECK ("status" IN ('concluida', 'ignorada', 'erro')),
  "mensagem" TEXT COLLATE ci,
  "executado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."notif_alertas" (
  "id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "regra_id" INTEGER,
  "modulo" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "evento" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "titulo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "corpo" TEXT COLLATE ci,
  "icone" CHARACTER VARYING(50) COLLATE ci,
  "cor" CHARACTER VARYING(20) COLLATE ci,
  "link_pagina" CHARACTER VARYING(100) COLLATE ci,
  "link_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0)
);

CREATE TABLE "erp"."notif_destinatarios" (
  "id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "alerta_id" INTEGER NOT NULL,
  "usuario_id" INTEGER NOT NULL,
  "lido" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_notif_destinatarios_lido_5" CHECK ("lido" BETWEEN -128 AND 127),
  "lido_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "dispensado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_notif_destinatarios_dispensado_8" CHECK ("dispensado" BETWEEN -128 AND 127)
);

CREATE TABLE "erp"."notif_regras" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "modulo" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "evento" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_notif_regras_ativo_5" CHECK ("ativo" BETWEEN -128 AND 127),
  "canais" CHARACTER VARYING(200) COLLATE ci NOT NULL,
  "emails" TEXT COLLATE ci,
  "usuarios_ids" TEXT COLLATE ci,
  "horas_limite" NUMERIC(6,2),
  "prioridade" CHARACTER VARYING(20) COLLATE ci,
  "titulo_tpl" CHARACTER VARYING(255) COLLATE ci NOT NULL DEFAULT '',
  "corpo_tpl" TEXT COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."notificacao_canais_config" (
  "tenant_id" INTEGER NOT NULL,
  "evento" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "canal" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "ativo" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_notificacao_canais_config_ativo_4" CHECK ("ativo" BETWEEN -128 AND 127),
  "config_json" TEXT COLLATE ci,
  "atualizado_por" INTEGER,
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("tenant_id", "evento", "canal")
);

CREATE TABLE "erp"."notificacoes_downloads" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "notificacao_id" INTEGER NOT NULL,
  "morador_id" INTEGER NOT NULL,
  "data_download" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "ip_address" CHARACTER VARYING(50) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."notificacoes_morador" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_notificacoes_morador_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL,
  "morador_id" INTEGER NOT NULL,
  "protocolo_id" INTEGER NOT NULL,
  "alerta_unidade_id" NUMERIC(20,0),
  CONSTRAINT "ck_notificacoes_morador_alerta_unidade_id_6" CHECK ("alerta_unidade_id" BETWEEN 0 AND 18446744073709551615),
  "veiculo_id" INTEGER,
  "registro_acesso_id" INTEGER,
  "tipo" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "titulo" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "mensagem" CHARACTER VARYING(500) COLLATE ci NOT NULL,
  "descricao_mercadoria" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "nome_recebedor" CHARACTER VARYING(150) COLLATE ci,
  "lida" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_notificacoes_morador_lida_15" CHECK ("lida" BETWEEN -128 AND 127),
  "lida_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "push_status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'pendente',
  CONSTRAINT "ck_notificacoes_morador_push_status_18" CHECK ("push_status" IN ('pendente', 'enviado', 'falhou', 'sem_token', 'desativado', 'nao_configurado')),
  "push_detalhe" CHARACTER VARYING(255) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."notificacoes_visualizacoes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "notificacao_id" INTEGER NOT NULL,
  "morador_id" INTEGER NOT NULL,
  "data_visualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "ip_address" CHARACTER VARYING(50) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."os_assuntos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(150) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "departamento" CHARACTER VARYING(100) COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_os_assuntos_ativo_6" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."os_chamados" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "numero" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "titulo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "assunto_id" INTEGER,
  "departamento" CHARACTER VARYING(100) COLLATE ci,
  "prioridade" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'media',
  CONSTRAINT "ck_os_chamados_prioridade_7" CHECK ("prioridade" IN ('baixa', 'media', 'alta', 'urgente')),
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'aberto',
  CONSTRAINT "ck_os_chamados_status_9" CHECK ("status" IN ('aberto', 'andamento', 'finalizado', 'cancelado')),
  "projeto_publico" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_os_chamados_projeto_publico_11" CHECK ("projeto_publico" BETWEEN -128 AND 127),
  "morador_id" INTEGER,
  "morador_nome" CHARACTER VARYING(255) COLLATE ci,
  "morador_unidade" CHARACTER VARYING(50) COLLATE ci,
  "atendente_id" INTEGER,
  "atendente_nome" CHARACTER VARYING(255) COLLATE ci,
  "descricao" TEXT COLLATE ci,
  "horas_estimadas" NUMERIC(8,2),
  "horas_totais" NUMERIC(8,2),
  "os_pai_id" INTEGER,
  "data_abertura" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "data_inicio" TIMESTAMP(0) WITHOUT TIME ZONE,
  "data_finalizacao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "data_previsao" DATE,
  "criado_por_id" INTEGER,
  "criado_por_nome" CHARACTER VARYING(255) COLLATE ci,
  "origem_portal" CHARACTER VARYING(30) COLLATE ci,
  "assumido_por_id" INTEGER,
  "assumido_por_nome" CHARACTER VARYING(150) COLLATE ci,
  "data_assumido" TIMESTAMP(0) WITHOUT TIME ZONE,
  "observacao_finalizacao" TEXT COLLATE ci,
  "projeto_nome" CHARACTER VARYING(255) COLLATE ci,
  "projeto_descricao" TEXT COLLATE ci,
  "projeto_objetivo" TEXT COLLATE ci,
  "projeto_beneficios" TEXT COLLATE ci,
  "projeto_localizacao" CHARACTER VARYING(255) COLLATE ci,
  "projeto_responsavel" CHARACTER VARYING(255) COLLATE ci,
  "projeto_departamento_id" INTEGER,
  "projeto_data_inicio_prevista" DATE,
  "projeto_data_fim_prevista" DATE,
  "projeto_imagem_capa" CHARACTER VARYING(255) COLLATE ci,
  "projeto_percentual" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_os_chamados_projeto_percentual_43" CHECK ("projeto_percentual" BETWEEN 0 AND 255),
  "projeto_etapa_id" INTEGER,
  "projeto_status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'planejamento',
  CONSTRAINT "ck_os_chamados_projeto_status_46" CHECK ("projeto_status" IN ('planejamento', 'execucao', 'paralisado', 'finalizado')),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."os_config_homem_hora" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "assunto_id" INTEGER,
  "descricao" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "horas_estimadas" NUMERIC(8,2) NOT NULL DEFAULT 1.00,
  "custo_hora" NUMERIC(10,2) DEFAULT 0.00,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_os_config_homem_hora_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."os_etapas" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "ordem" INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT "ck_os_etapas_ordem_4" CHECK ("ordem" BETWEEN 0 AND 65535),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_os_etapas_ativo_6" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."os_integridade_interacoes_log" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('os_integridade_interacoes_log.id') NOT NULL,
  CONSTRAINT "ck_os_integridade_interacoes_log_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tabela" CHARACTER VARYING(64) COLLATE ci NOT NULL,
  "ids_zero_antes" BIGINT NOT NULL DEFAULT 0,
  "duplicados_nao_zero_antes" BIGINT NOT NULL DEFAULT 0,
  "acao" CHARACTER VARYING(64) COLLATE ci NOT NULL,
  "mensagem" CHARACTER VARYING(500) COLLATE ci NOT NULL,
  "executado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."os_interacao_fotos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "interacao_id" INTEGER NOT NULL,
  "arquivo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "arquivo_nome_original" CHARACTER VARYING(255) COLLATE ci,
  "arquivo_tamanho" BIGINT DEFAULT 0,
  CONSTRAINT "ck_os_interacao_fotos_arquivo_tamanho_6" CHECK ("arquivo_tamanho" BETWEEN 0 AND 4294967295),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."os_interacao_fotos_quarentena" (
  "backup_id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('os_interacao_fotos_quarentena.backup_id') NOT NULL,
  CONSTRAINT "ck_os_interacao_fotos_quarentena_backup_id_1" CHECK ("backup_id" BETWEEN 0 AND 18446744073709551615),
  "foto_id_origem" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "interacao_id_origem" INTEGER NOT NULL,
  "arquivo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "arquivo_nome_original" CHARACTER VARYING(255) COLLATE ci,
  "arquivo_tamanho" BIGINT DEFAULT 0,
  CONSTRAINT "ck_os_interacao_fotos_quarentena_arquivo_tamanho_8" CHECK ("arquivo_tamanho" BETWEEN 0 AND 4294967295),
  "criado_em_origem" TIMESTAMP(0) WITHOUT TIME ZONE,
  "motivo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "quarentenado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("backup_id")
);

CREATE TABLE "erp"."os_interacoes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "os_id" INTEGER NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'comentario',
  CONSTRAINT "ck_os_interacoes_tipo_4" CHECK ("tipo" IN ('comentario', 'andamento', 'solucao', 'nota_interna')),
  "mensagem" TEXT COLLATE ci NOT NULL,
  "anexos" TEXT COLLATE ci,
  "usuario_id" INTEGER,
  "usuario_nome" CHARACTER VARYING(255) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "etapa_id" INTEGER,
  "percentual" SMALLINT,
  CONSTRAINT "ck_os_interacoes_percentual_12" CHECK ("percentual" BETWEEN 0 AND 255),
  "publica" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_os_interacoes_publica_14" CHECK ("publica" BETWEEN -128 AND 127),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."os_materiais_usados" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "os_id" INTEGER NOT NULL,
  "produto_id" INTEGER NOT NULL,
  "produto_nome" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "quantidade" NUMERIC(10,3) NOT NULL DEFAULT 1.000,
  "preco_unitario" NUMERIC(10,2) DEFAULT 0.00,
  "estoque_baixado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_os_materiais_usados_estoque_baixado_8" CHECK ("estoque_baixado" BETWEEN -128 AND 127),
  "adicionado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."os_permissoes_departamento" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "usuario_id" INTEGER NOT NULL,
  "departamento" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "pode_visualizar" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_os_permissoes_departamento_pode_visualizar_5" CHECK ("pode_visualizar" BETWEEN -128 AND 127),
  "pode_criar" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_os_permissoes_departamento_pode_criar_7" CHECK ("pode_criar" BETWEEN -128 AND 127),
  "pode_editar" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_os_permissoes_departamento_pode_editar_9" CHECK ("pode_editar" BETWEEN -128 AND 127),
  "pode_excluir" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_os_permissoes_departamento_pode_excluir_11" CHECK ("pode_excluir" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."os_recursos_humanos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "os_id" INTEGER NOT NULL,
  "colaborador_id" INTEGER NOT NULL,
  "colaborador_nome" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "cargo" CHARACTER VARYING(150) COLLATE ci,
  "departamento" CHARACTER VARYING(100) COLLATE ci,
  "vinculado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."os_usuarios_config" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "usuario_id" INTEGER NOT NULL,
  "restritivo_os" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_os_usuarios_config_restritivo_os_4" CHECK ("restritivo_os" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."pedidos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "morador_id" INTEGER NOT NULL,
  "fornecedor_id" INTEGER NOT NULL,
  "produto_servico_id" INTEGER,
  "descricao_pedido" TEXT COLLATE ci NOT NULL,
  "valor_proposto" NUMERIC(10,2),
  "status" CHARACTER VARYING COLLATE ci DEFAULT 'enviado',
  CONSTRAINT "ck_pedidos_status_8" CHECK ("status" IN ('enviado', 'em_analise', 'aceito', 'recusado', 'em_execucao', 'finalizado_morador', 'finalizado_fornecedor', 'concluido', 'cancelado')),
  "motivo_recusa" TEXT COLLATE ci,
  "data_pedido" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_aceite" TIMESTAMP(0) WITHOUT TIME ZONE,
  "data_inicio_execucao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "data_finalizacao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "data_conclusao" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."planos_contas" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "codigo" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_planos_contas_tipo_6" CHECK ("tipo" IN ('ATIVO', 'PASSIVO', 'PATRIMONIO', 'RECEITA', 'DESPESA')),
  "natureza" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'DEVEDORA',
  CONSTRAINT "ck_planos_contas_natureza_8" CHECK ("natureza" IN ('DEVEDORA', 'CREDORA')),
  "categoria" CHARACTER VARYING(100) COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_planos_contas_ativo_11" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."produtos_estoque" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "codigo" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(200) COLLATE ci NOT NULL,
  "categoria_id" INTEGER,
  "unidade_medida" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'Unidade',
  CONSTRAINT "ck_produtos_estoque_unidade_medida_6" CHECK ("unidade_medida" IN ('Unidade', 'Metro', 'Kg', 'Litro', 'Caixa', 'Pacote', 'Rolo', 'Saco', 'Outro')),
  "descricao" TEXT COLLATE ci,
  "preco_unitario" NUMERIC(10,2) DEFAULT 0.00,
  "quantidade_estoque" NUMERIC(10,2) DEFAULT 0.00,
  "estoque_minimo" NUMERIC(10,2) DEFAULT 0.00,
  "estoque_maximo" NUMERIC(10,2) DEFAULT 0.00,
  "localizacao" CHARACTER VARYING(100) COLLATE ci,
  "codigo_barras" CHARACTER VARYING(50) COLLATE ci,
  "foto" CHARACTER VARYING(255) COLLATE ci,
  "fornecedor" CHARACTER VARYING(200) COLLATE ci,
  "observacoes" TEXT COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_produtos_estoque_ativo_18" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."produtos_servicos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "fornecedor_id" INTEGER NOT NULL,
  "nome" CHARACTER VARYING(200) COLLATE ci NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_produtos_servicos_tipo_5" CHECK ("tipo" IN ('produto', 'servico')),
  "codigo_sequencial" CHARACTER VARYING(50) COLLATE ci,
  "descricao" TEXT COLLATE ci,
  "preco_venda" NUMERIC(10,2) NOT NULL,
  "quantidade_disponivel" INTEGER NOT NULL DEFAULT 0,
  "valor_negociavel" SMALLINT DEFAULT 0,
  CONSTRAINT "ck_produtos_servicos_valor_negociavel_11" CHECK ("valor_negociavel" BETWEEN -128 AND 127),
  "imagem" CHARACTER VARYING(255) COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_produtos_servicos_ativo_14" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "observacao" TEXT COLLATE ci,
  "link_compartilhamento" CHARACTER VARYING(255) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."protocolos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "unidade_id" INTEGER NOT NULL,
  "morador_id" INTEGER NOT NULL,
  "descricao_mercadoria" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "codigo_nf" CHARACTER VARYING(100) COLLATE ci,
  "pagina" INTEGER,
  "data_hora_recebimento" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "recebedor_portaria" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "observacao" TEXT COLLATE ci,
  "status" CHARACTER VARYING COLLATE ci DEFAULT 'pendente',
  CONSTRAINT "ck_protocolos_status_11" CHECK ("status" IN ('pendente', 'entregue')),
  "nome_recebedor_morador" CHARACTER VARYING(100) COLLATE ci,
  "data_hora_entrega" TIMESTAMP(0) WITHOUT TIME ZONE,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."publico_rate_limit" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "ip" CHARACTER VARYING(45) COLLATE ci NOT NULL,
  "rota" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."pwa_configuracoes" (
  "id" BIGINT NOT NULL,
  CONSTRAINT "ck_pwa_configuracoes_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "chave" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "valor" TEXT COLLATE ci,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0)
);

CREATE TABLE "erp"."pwa_logs" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_pwa_logs_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "tipo" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "nivel" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'info',
  CONSTRAINT "ck_pwa_logs_nivel_5" CHECK ("nivel" IN ('info', 'aviso', 'erro')),
  "morador_id" INTEGER,
  "token_id" BIGINT,
  CONSTRAINT "ck_pwa_logs_token_id_8" CHECK ("token_id" BETWEEN 0 AND 4294967295),
  "descricao" TEXT COLLATE ci NOT NULL,
  "extras" JSON,
  "ip" CHARACTER VARYING(45) COLLATE ci,
  "user_agent" CHARACTER VARYING(255) COLLATE ci,
  "plataforma" CHARACTER VARYING(20) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."pwa_notificacoes_push" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_pwa_notificacoes_push_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "titulo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "corpo" TEXT COLLATE ci NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'geral',
  CONSTRAINT "ck_pwa_notificacoes_push_tipo_6" CHECK ("tipo" IN ('visitante', 'inadimplencia', 'comunicado', 'aviso', 'os', 'urgente', 'geral')),
  "url_destino" CHARACTER VARYING(512) COLLATE ci,
  "icone" CHARACTER VARYING(512) COLLATE ci,
  "tag" CHARACTER VARYING(100) COLLATE ci,
  "dados_extras" JSON,
  "destinatario" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'todos',
  CONSTRAINT "ck_pwa_notificacoes_push_destinatario_12" CHECK ("destinatario" IN ('todos', 'unidade', 'morador')),
  "morador_id" INTEGER,
  "unidade_id" INTEGER,
  "enviado_por" INTEGER,
  "enviado_por_nome" CHARACTER VARYING(150) COLLATE ci,
  "total_tokens" INTEGER DEFAULT 0,
  "total_sucesso" INTEGER DEFAULT 0,
  "total_falha" INTEGER DEFAULT 0,
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'pendente',
  CONSTRAINT "ck_pwa_notificacoes_push_status_21" CHECK ("status" IN ('pendente', 'enviando', 'concluido', 'erro')),
  "erro_detalhe" TEXT COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "enviado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."pwa_notificacoes_recebidas" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_pwa_notificacoes_recebidas_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "notificacao_id" BIGINT NOT NULL,
  CONSTRAINT "ck_pwa_notificacoes_recebidas_notificacao_id_4" CHECK ("notificacao_id" BETWEEN 0 AND 4294967295),
  "morador_id" INTEGER NOT NULL,
  "fcm_token_id" BIGINT,
  CONSTRAINT "ck_pwa_notificacoes_recebidas_fcm_token_id_7" CHECK ("fcm_token_id" BETWEEN 0 AND 4294967295),
  "status_envio" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'sucesso',
  CONSTRAINT "ck_pwa_notificacoes_recebidas_status_envio_9" CHECK ("status_envio" IN ('sucesso', 'falha', 'invalido')),
  "lida" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_pwa_notificacoes_recebidas_lida_11" CHECK ("lida" BETWEEN -128 AND 127),
  "lida_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "erro_fcm" CHARACTER VARYING(255) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."pwa_versao" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_pwa_versao_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "versao" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "cache_version" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "changelog" TEXT COLLATE ci,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'patch',
  CONSTRAINT "ck_pwa_versao_tipo_7" CHECK ("tipo" IN ('major', 'minor', 'patch', 'build')),
  "publicado_por" INTEGER,
  "publicado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_pwa_versao_ativo_11" CHECK ("ativo" BETWEEN -128 AND 127),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."ramos_atividade" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "icone" CHARACTER VARYING(50) COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_ramos_atividade_ativo_6" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."rbac_configuracoes" (
  "tenant_id" INTEGER NOT NULL,
  "revisao_permissoes" BIGINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_rbac_configuracoes_revisao_permissoes_2" CHECK ("revisao_permissoes" BETWEEN 0 AND 4294967295),
  "timeout_padrao_minutos" BIGINT NOT NULL DEFAULT 480,
  CONSTRAINT "ck_rbac_configuracoes_timeout_padrao_minutos_4" CHECK ("timeout_padrao_minutos" BETWEEN 0 AND 4294967295),
  "atualizado_por_usuario_id" INTEGER,
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("tenant_id")
);

CREATE TABLE "erp"."rbac_grupo_permissoes" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_rbac_grupo_permissoes_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "grupo_id" BIGINT NOT NULL,
  CONSTRAINT "ck_rbac_grupo_permissoes_grupo_id_3" CHECK ("grupo_id" BETWEEN 0 AND 4294967295),
  "permissao_id" BIGINT NOT NULL,
  CONSTRAINT "ck_rbac_grupo_permissoes_permissao_id_5" CHECK ("permissao_id" BETWEEN 0 AND 4294967295),
  "efeito" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'PERMITIR',
  CONSTRAINT "ck_rbac_grupo_permissoes_efeito_7" CHECK ("efeito" IN ('PERMITIR', 'NEGAR')),
  "escopo_dados" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'GLOBAL',
  CONSTRAINT "ck_rbac_grupo_permissoes_escopo_dados_9" CHECK ("escopo_dados" IN ('GLOBAL', 'DEPARTAMENTO', 'UNIDADE', 'PROPRIO', 'ATRIBUIDO')),
  "criado_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."rbac_grupos" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_rbac_grupos_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER,
  "slug" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_rbac_grupos_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  "protegido" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_rbac_grupos_protegido_9" CHECK ("protegido" BETWEEN -128 AND 127),
  "criado_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "excluido_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."rbac_modulos" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_rbac_modulos_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "chave" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "modulo_pai_id" BIGINT,
  CONSTRAINT "ck_rbac_modulos_modulo_pai_id_5" CHECK ("modulo_pai_id" BETWEEN 0 AND 4294967295),
  "pagina" CHARACTER VARYING(80) COLLATE ci,
  "grupo" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'MODULO',
  CONSTRAINT "ck_rbac_modulos_tipo_9" CHECK ("tipo" IN ('MODULO', 'SUBMODULO', 'PAGINA', 'SISTEMA')),
  "icone" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "perfil_compatibilidade" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'operador',
  CONSTRAINT "ck_rbac_modulos_perfil_compatibilidade_13" CHECK ("perfil_compatibilidade" IN ('visualizador', 'operador', 'gerente', 'admin')),
  "ordem" SMALLINT NOT NULL DEFAULT 0,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_rbac_modulos_ativo_16" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."rbac_permissoes" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_rbac_permissoes_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "modulo_id" BIGINT NOT NULL,
  CONSTRAINT "ck_rbac_permissoes_modulo_id_3" CHECK ("modulo_id" BETWEEN 0 AND 4294967295),
  "acao" CHARACTER VARYING(32) COLLATE ci NOT NULL,
  "nome" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_rbac_permissoes_ativo_8" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."rbac_politicas_api" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_rbac_politicas_api_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "endpoint" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "acao_requisicao" CHARACTER VARYING(80) COLLATE ci NOT NULL DEFAULT '',
  "modulo_chave" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "acao_permissao" CHARACTER VARYING(32) COLLATE ci NOT NULL,
  "metodo_http" CHARACTER VARYING(10) COLLATE ci,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_rbac_politicas_api_ativo_8" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."rbac_usuario_grupos" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_rbac_usuario_grupos_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "usuario_id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "grupo_id" BIGINT NOT NULL,
  CONSTRAINT "ck_rbac_usuario_grupos_grupo_id_5" CHECK ("grupo_id" BETWEEN 0 AND 4294967295),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_rbac_usuario_grupos_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  "atribuido_por_usuario_id" INTEGER,
  "atribuido_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "removido_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."rbac_usuario_grupos_bkp_ids_a_partir_15_20261027" (
  "id" BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_rbac_usuario_grupos_bkp_ids_a_partir_15_20261027_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "usuario_id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "grupo_id" BIGINT NOT NULL,
  CONSTRAINT "ck_rbac_usuario_grupos_bkp_ids_a_partir_15_20261027_grupo_id_5" CHECK ("grupo_id" BETWEEN 0 AND 4294967295),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_rbac_usuario_grupos_bkp_ids_a_partir_15_20261027_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  "atribuido_por_usuario_id" INTEGER,
  "atribuido_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "removido_em" TIMESTAMP(0) WITHOUT TIME ZONE
);

CREATE TABLE "erp"."rbac_usuario_permissoes" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_rbac_usuario_permissoes_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "usuario_id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "permissao_id" BIGINT NOT NULL,
  CONSTRAINT "ck_rbac_usuario_permissoes_permissao_id_5" CHECK ("permissao_id" BETWEEN 0 AND 4294967295),
  "efeito" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'PERMITIR',
  CONSTRAINT "ck_rbac_usuario_permissoes_efeito_7" CHECK ("efeito" IN ('PERMITIR', 'NEGAR')),
  "escopo_dados" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'GLOBAL',
  CONSTRAINT "ck_rbac_usuario_permissoes_escopo_dados_9" CHECK ("escopo_dados" IN ('GLOBAL', 'DEPARTAMENTO', 'UNIDADE', 'PROPRIO', 'ATRIBUIDO')),
  "motivo" CHARACTER VARYING(255) COLLATE ci,
  "atribuido_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "revogado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."rbac_usuario_permissoes_bkp_ids_a_partir_15_20261027" (
  "id" BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_rbac_usuario_permissoes_bkp_ids_a_partir_15_20261027_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "usuario_id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "permissao_id" BIGINT NOT NULL,
  CONSTRAINT "ck_rbac_usuario_permissoes_bkp_ids_a_partir_15_202_9f8ae6732e2b" CHECK ("permissao_id" BETWEEN 0 AND 4294967295),
  "efeito" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'PERMITIR',
  CONSTRAINT "ck_rbac_usuario_permissoes_bkp_ids_a_partir_15_202_bbcade193b24" CHECK ("efeito" IN ('PERMITIR', 'NEGAR')),
  "escopo_dados" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'GLOBAL',
  CONSTRAINT "ck_rbac_usuario_permissoes_bkp_ids_a_partir_15_202_8c3cca0f3c55" CHECK ("escopo_dados" IN ('GLOBAL', 'DEPARTAMENTO', 'UNIDADE', 'PROPRIO', 'ATRIBUIDO')),
  "motivo" CHARACTER VARYING(255) COLLATE ci,
  "atribuido_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "revogado_em" TIMESTAMP(0) WITHOUT TIME ZONE
);

CREATE TABLE "erp"."recebedores" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "cargo" CHARACTER VARYING(50) COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_recebedores_ativo_5" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."registros_acesso" (
  "id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "data_hora" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "placa" CHARACTER VARYING(10) COLLATE ci NOT NULL,
  "modelo" CHARACTER VARYING(100) COLLATE ci,
  "cor" CHARACTER VARYING(50) COLLATE ci,
  "tag" CHARACTER VARYING(50) COLLATE ci,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_registros_acesso_tipo_8" CHECK ("tipo" IN ('Morador', 'Visitante', 'Prestador', 'Entregador Autorizado', 'Familiar')),
  "morador_id" INTEGER,
  "visitante_id" INTEGER,
  "nome_visitante" CHARACTER VARYING(200) COLLATE ci,
  "documento_visitante" CHARACTER VARYING(30) COLLATE ci,
  "unidade_destino" CHARACTER VARYING(50) COLLATE ci,
  "dias_permanencia" INTEGER,
  "status" CHARACTER VARYING(100) COLLATE ci,
  "liberado" SMALLINT DEFAULT 0,
  CONSTRAINT "ck_registros_acesso_liberado_17" CHECK ("liberado" BETWEEN -128 AND 127),
  "observacao" TEXT COLLATE ci,
  "origem" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'manual',
  CONSTRAINT "ck_registros_acesso_origem_20" CHECK ("origem" IN ('manual', 'controlid', 'portal', 'sistema')),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "tipo_acesso" CHARACTER VARYING COLLATE ci DEFAULT 'Entrada',
  CONSTRAINT "ck_registros_acesso_tipo_acesso_23" CHECK ("tipo_acesso" IN ('Entrada', 'Saída')),
  "dependente_id" INTEGER,
  "papel_veiculo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'TITULAR',
  CONSTRAINT "ck_registros_acesso_papel_veiculo_26" CHECK ("papel_veiculo" IN ('TITULAR', 'OCUPANTE')),
  "registro_titular_id" INTEGER,
  "modo_registro" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'VEICULO',
  CONSTRAINT "ck_registros_acesso_modo_registro_29" CHECK ("modo_registro" IN ('VEICULO', 'PEDESTRE')),
  "vestimenta" CHARACTER VARYING(120) COLLATE ci,
  "usuario_liberou" CHARACTER VARYING(150) COLLATE ci,
  "idempotency_key" CHARACTER VARYING(36) COLLATE ci,
  "convidado_id" INTEGER
);

CREATE TABLE "erp"."rh_banco_horas" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_rh_banco_horas_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL,
  "colaborador_id" INTEGER NOT NULL,
  "lancamento_id" INTEGER,
  "data" DATE NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_rh_banco_horas_tipo_7" CHECK ("tipo" IN ('credito', 'debito', 'abatimento', 'pagamento', 'excedente')),
  "minutos" INTEGER NOT NULL DEFAULT 0,
  "descricao" CHARACTER VARYING(255) COLLATE ci NOT NULL DEFAULT '',
  "usuario" CHARACTER VARYING(100) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "saldo_anterior_min" INTEGER,
  "saldo_posterior_min" INTEGER,
  "saldo_negativo_confirmado" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_rh_banco_horas_saldo_negativo_confirmado_15" CHECK ("saldo_negativo_confirmado" BETWEEN -128 AND 127),
  "justificativa_saldo_negativo" CHARACTER VARYING(500) COLLATE ci,
  "usuario_id" INTEGER,
  "ip" CHARACTER VARYING(45) COLLATE ci,
  "idempotency_key" CHARACTER VARYING(64) COLLATE ci,
  "minutos_apurados" INTEGER,
  "limite_aplicado_min" INTEGER,
  "regra_aplicada" CHARACTER VARYING(40) COLLATE ci,
  "versao_calculo" CHARACTER VARYING(20) COLLATE ci,
  "reprocessamento_id" BIGINT,
  CONSTRAINT "ck_rh_banco_horas_reprocessamento_id_25" CHECK ("reprocessamento_id" BETWEEN 0 AND 4294967295),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."rh_escala" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_rh_escala_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "colaborador_id" BIGINT NOT NULL,
  CONSTRAINT "ck_rh_escala_colaborador_id_4" CHECK ("colaborador_id" BETWEEN 0 AND 4294967295),
  "nome_escala" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'livre',
  CONSTRAINT "ck_rh_escala_tipo_7" CHECK ("tipo" IN ('livre', 'controle_jornada', 'alternada', 'jornada_44h', 'jornada_40h', 'jornada_36h', 'escala_manual')),
  "carga_horaria_diaria_min" BIGINT DEFAULT 480,
  CONSTRAINT "ck_rh_escala_carga_horaria_diaria_min_9" CHECK ("carga_horaria_diaria_min" BETWEEN 0 AND 4294967295),
  "dias_trabalho" JSON,
  "hora_entrada" TIME(0) WITHOUT TIME ZONE,
  "hora_almoco_saida" TIME(0) WITHOUT TIME ZONE,
  "hora_almoco_retorno" TIME(0) WITHOUT TIME ZONE,
  "hora_saida" TIME(0) WITHOUT TIME ZONE,
  "tolerancia_minutos" BIGINT DEFAULT 10,
  CONSTRAINT "ck_rh_escala_tolerancia_minutos_16" CHECK ("tolerancia_minutos" BETWEEN 0 AND 4294967295),
  "intervalo_almoco_min" BIGINT DEFAULT 60,
  CONSTRAINT "ck_rh_escala_intervalo_almoco_min_18" CHECK ("intervalo_almoco_min" BETWEEN 0 AND 4294967295),
  "alternada_ativa" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_rh_escala_alternada_ativa_20" CHECK ("alternada_ativa" BETWEEN -128 AND 127),
  "alternada_dia_inicio" DATE,
  "alternada_semana_a" TEXT COLLATE ci,
  "alternada_semana_b" TEXT COLLATE ci,
  "alternada_tipo_folga" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'folga',
  CONSTRAINT "ck_rh_escala_alternada_tipo_folga_25" CHECK ("alternada_tipo_folga" IN ('folga', 'falta', 'feriado')),
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_rh_escala_ativo_27" CHECK ("ativo" BETWEEN -128 AND 127),
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "updated_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "carga_horaria_mensal_min" BIGINT DEFAULT 0,
  CONSTRAINT "ck_rh_escala_carga_horaria_mensal_min_31" CHECK ("carga_horaria_mensal_min" BETWEEN 0 AND 4294967295),
  "descanso_interjornada_min" BIGINT DEFAULT 0,
  CONSTRAINT "ck_rh_escala_descanso_interjornada_min_33" CHECK ("descanso_interjornada_min" BETWEEN 0 AND 4294967295),
  "regime_12x36" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_rh_escala_regime_12x36_35" CHECK ("regime_12x36" BETWEEN -128 AND 127),
  "banco_horas_ativo" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_rh_escala_banco_horas_ativo_37" CHECK ("banco_horas_ativo" BETWEEN -128 AND 127),
  "escala_manual_semana" TEXT COLLATE ci,
  "limite_saldo_negativo_min" BIGINT,
  CONSTRAINT "ck_rh_escala_limite_saldo_negativo_min_40" CHECK ("limite_saldo_negativo_min" BETWEEN 0 AND 4294967295),
  "politica_extra_excedente" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'CREDITAR_TUDO',
  CONSTRAINT "ck_rh_escala_politica_extra_excedente_42" CHECK ("politica_extra_excedente" IN ('CREDITAR_TUDO', 'LIMITAR_E_SEPARAR')),
  "limite_extra_diario_min" BIGINT NOT NULL DEFAULT 120,
  CONSTRAINT "ck_rh_escala_limite_extra_diario_min_44" CHECK ("limite_extra_diario_min" BETWEEN 0 AND 4294967295),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."rh_ponto_lancamento" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_rh_ponto_lancamento_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "periodo_id" BIGINT NOT NULL,
  CONSTRAINT "ck_rh_ponto_lancamento_periodo_id_4" CHECK ("periodo_id" BETWEEN 0 AND 4294967295),
  "colaborador_id" BIGINT NOT NULL,
  CONSTRAINT "ck_rh_ponto_lancamento_colaborador_id_6" CHECK ("colaborador_id" BETWEEN 0 AND 4294967295),
  "data" DATE NOT NULL,
  "hora_entrada" TIME(0) WITHOUT TIME ZONE,
  "hora_almoco_saida" TIME(0) WITHOUT TIME ZONE,
  "hora_almoco_retorno" TIME(0) WITHOUT TIME ZONE,
  "hora_saida" TIME(0) WITHOUT TIME ZONE,
  "tipo_dia" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'normal',
  CONSTRAINT "ck_rh_ponto_lancamento_tipo_dia_13" CHECK ("tipo_dia" IN ('normal', 'folga', 'falta', 'feriado', 'meio_periodo', 'afastamento', 'horas_extras')),
  "horas_trabalhadas_min" INTEGER DEFAULT 0,
  "horas_extras_min" INTEGER DEFAULT 0,
  "atraso_min" INTEGER DEFAULT 0,
  "observacoes" CHARACTER VARYING(300) COLLATE ci,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "updated_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "abono_extras" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'nenhum',
  CONSTRAINT "ck_rh_ponto_lancamento_abono_extras_21" CHECK ("abono_extras" IN ('nenhum', 'total', 'parcial')),
  "abono_extras_min" INTEGER NOT NULL DEFAULT 0,
  "abono_falta" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_rh_ponto_lancamento_abono_falta_24" CHECK ("abono_falta" BETWEEN -128 AND 127),
  "abono_atraso" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'nenhum',
  CONSTRAINT "ck_rh_ponto_lancamento_abono_atraso_26" CHECK ("abono_atraso" IN ('nenhum', 'total', 'parcial')),
  "abono_atraso_min" INTEGER NOT NULL DEFAULT 0,
  "abono_justificativa" TEXT COLLATE ci,
  "abono_usuario" CHARACTER VARYING(100) COLLATE ci,
  "abono_data" TIMESTAMP(0) WITHOUT TIME ZONE,
  "saida_antecipada_min" INTEGER NOT NULL DEFAULT 0,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."rh_ponto_periodo" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_rh_ponto_periodo_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "colaborador_id" BIGINT NOT NULL,
  CONSTRAINT "ck_rh_ponto_periodo_colaborador_id_4" CHECK ("colaborador_id" BETWEEN 0 AND 4294967295),
  "mes" SMALLINT NOT NULL,
  CONSTRAINT "ck_rh_ponto_periodo_mes_6" CHECK ("mes" BETWEEN 0 AND 255),
  "ano" INTEGER NOT NULL,
  CONSTRAINT "ck_rh_ponto_periodo_ano_8" CHECK ("ano" BETWEEN 0 AND 65535),
  "status" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'aberto',
  CONSTRAINT "ck_rh_ponto_periodo_status_10" CHECK ("status" IN ('aberto', 'fechado')),
  "total_horas_trabalhadas_min" INTEGER DEFAULT 0,
  "total_horas_extras_min" INTEGER DEFAULT 0,
  "total_atraso_min" INTEGER DEFAULT 0,
  "total_faltas" INTEGER DEFAULT 0,
  "total_folgas" INTEGER DEFAULT 0,
  "observacoes" TEXT COLLATE ci,
  "created_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "updated_at" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "total_saida_antecipada_min" INTEGER DEFAULT 0,
  "total_carga_prevista_min" INTEGER DEFAULT 0,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."ronda_auditoria" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('ronda_auditoria.id') NOT NULL,
  CONSTRAINT "ck_ronda_auditoria_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "rota_id" BIGINT,
  CONSTRAINT "ck_ronda_auditoria_rota_id_4" CHECK ("rota_id" BETWEEN 0 AND 4294967295),
  "usuario_id" INTEGER,
  "acao" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(500) COLLATE ci NOT NULL,
  "dados_json" TEXT COLLATE ci,
  "ip" CHARACTER VARYING(45) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."ronda_registros" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('ronda_registros.id') NOT NULL,
  CONSTRAINT "ck_ronda_registros_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "rota_id" BIGINT NOT NULL,
  CONSTRAINT "ck_ronda_registros_rota_id_4" CHECK ("rota_id" BETWEEN 0 AND 4294967295),
  "ponto_id" BIGINT NOT NULL,
  CONSTRAINT "ck_ronda_registros_ponto_id_6" CHECK ("ponto_id" BETWEEN 0 AND 4294967295),
  "colaborador_id" INTEGER NOT NULL,
  "ciclo_chave" CHARACTER(64) COLLATE ci NOT NULL,
  "previsto_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "registrado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "status_sla" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'no_prazo',
  CONSTRAINT "ck_ronda_registros_status_sla_12" CHECK ("status_sla" IN ('no_prazo', 'atrasado', 'manual')),
  "atraso_minutos" BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_ronda_registros_atraso_minutos_14" CHECK ("atraso_minutos" BETWEEN 0 AND 4294967295),
  "latitude" NUMERIC(10,7),
  "longitude" NUMERIC(10,7),
  "precisao_metros" NUMERIC(10,2),
  "ip" CHARACTER VARYING(45) COLLATE ci,
  "user_agent" CHARACTER VARYING(255) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."ronda_rotas" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_ronda_rotas_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL,
  "nome" CHARACTER VARYING(120) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(500) COLLATE ci,
  "hora_inicio" TIME(0) WITHOUT TIME ZONE NOT NULL,
  "hora_fim" TIME(0) WITHOUT TIME ZONE,
  "intervalo_minutos" INTEGER NOT NULL DEFAULT 60,
  CONSTRAINT "ck_ronda_rotas_intervalo_minutos_8" CHECK ("intervalo_minutos" BETWEEN 0 AND 65535),
  "repeticoes_por_dia" INTEGER NOT NULL DEFAULT 1,
  CONSTRAINT "ck_ronda_rotas_repeticoes_por_dia_10" CHECK ("repeticoes_por_dia" BETWEEN 0 AND 65535),
  "tolerancia_minutos" INTEGER NOT NULL DEFAULT 10,
  CONSTRAINT "ck_ronda_rotas_tolerancia_minutos_12" CHECK ("tolerancia_minutos" BETWEEN 0 AND 65535),
  "dias_semana" CHARACTER VARYING(32) COLLATE ci NOT NULL,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_ronda_rotas_ativo_15" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."ronda_vigilantes" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_ronda_vigilantes_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL,
  "rota_id" BIGINT NOT NULL,
  CONSTRAINT "ck_ronda_vigilantes_rota_id_4" CHECK ("rota_id" BETWEEN 0 AND 4294967295),
  "colaborador_id" INTEGER NOT NULL,
  "ativo" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_ronda_vigilantes_ativo_7" CHECK ("ativo" BETWEEN -128 AND 127),
  "vinculado_por_usuario_id" INTEGER,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."sessoes_usuarios" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "usuario_id" INTEGER NOT NULL,
  "session_id" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "ip_address" CHARACTER VARYING(45) COLLATE ci,
  "user_agent" TEXT COLLATE ci,
  "data_login" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_expiracao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "ultima_atividade" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_sessoes_usuarios_ativo_10" CHECK ("ativo" BETWEEN -128 AND 127),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."sessoes_usuarios_bkp_ids_a_partir_15_20261027" (
  "id" INTEGER NOT NULL DEFAULT 0,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "usuario_id" INTEGER NOT NULL,
  "session_id" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "ip_address" CHARACTER VARYING(45) COLLATE ci,
  "user_agent" TEXT COLLATE ci,
  "data_login" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_expiracao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "ultima_atividade" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_sessoes_usuarios_bkp_ids_a_partir_15_20261027_ativo_10" CHECK ("ativo" BETWEEN -128 AND 127)
);

CREATE TABLE "erp"."tenant_arquivo_referencias" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('tenant_arquivo_referencias.id') NOT NULL,
  CONSTRAINT "ck_tenant_arquivo_referencias_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "tenant_id" INTEGER NOT NULL,
  "arquivo_id" NUMERIC(20,0) NOT NULL,
  CONSTRAINT "ck_tenant_arquivo_referencias_arquivo_id_4" CHECK ("arquivo_id" BETWEEN 0 AND 18446744073709551615),
  "modulo" CHARACTER VARYING(80) COLLATE ci NOT NULL,
  "registro_id" NUMERIC(20,0),
  CONSTRAINT "ck_tenant_arquivo_referencias_registro_id_7" CHECK ("registro_id" BETWEEN 0 AND 18446744073709551615),
  "campo_origem" CHARACTER VARYING(100) COLLATE ci,
  "origem_legado" CHARACTER VARYING(1024) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."tenants" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "slug" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "projetos_slug" CHARACTER VARYING(60) COLLATE ci,
  "razao_social" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "nome_fantasia" CHARACTER VARYING(255) COLLATE ci,
  "cnpj" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "plano" CHARACTER VARYING COLLATE ci DEFAULT 'basico',
  CONSTRAINT "ck_tenants_plano_7" CHECK ("plano" IN ('basico', 'profissional', 'enterprise')),
  "status" CHARACTER VARYING COLLATE ci DEFAULT 'ativo',
  CONSTRAINT "ck_tenants_status_9" CHECK ("status" IN ('ativo', 'inativo', 'suspenso')),
  "modulos_habilitados" JSON,
  "logo_url" CHARACTER VARYING(500) COLLATE ci,
  "email_principal" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "telefone" CHARACTER VARYING(30) COLLATE ci,
  "endereco" CHARACTER VARYING(500) COLLATE ci,
  "cidade" CHARACTER VARYING(100) COLLATE ci,
  "estado" CHARACTER VARYING(2) COLLATE ci,
  "data_criacao" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0),
  "locale" CHARACTER VARYING(10) COLLATE ci NOT NULL,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."tipos_dispositivo" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "descricao" TEXT COLLATE ci,
  "icone" CHARACTER VARYING(50) COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_tipos_dispositivo_ativo_6" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."unidades" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "nome" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "descricao" CHARACTER VARYING(255) COLLATE ci,
  "bloco" CHARACTER VARYING(20) COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_unidades_ativo_6" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."usuario_modulos" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_usuario_modulos_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "usuario_id" BIGINT NOT NULL,
  CONSTRAINT "ck_usuario_modulos_usuario_id_4" CHECK ("usuario_id" BETWEEN 0 AND 4294967295),
  "modulo_chave" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "pode_acessar" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_usuario_modulos_pode_acessar_7" CHECK ("pode_acessar" BETWEEN -128 AND 127),
  "pode_criar" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_usuario_modulos_pode_criar_9" CHECK ("pode_criar" BETWEEN -128 AND 127),
  "pode_editar" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_usuario_modulos_pode_editar_11" CHECK ("pode_editar" BETWEEN -128 AND 127),
  "pode_excluir" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_usuario_modulos_pode_excluir_13" CHECK ("pode_excluir" BETWEEN -128 AND 127),
  "pode_exportar" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_usuario_modulos_pode_exportar_15" CHECK ("pode_exportar" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."usuario_modulos_bkp_ids_a_partir_15_20261027" (
  "id" BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_usuario_modulos_bkp_ids_a_partir_15_20261027_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "usuario_id" BIGINT NOT NULL,
  CONSTRAINT "ck_usuario_modulos_bkp_ids_a_partir_15_20261027_usuario_id_4" CHECK ("usuario_id" BETWEEN 0 AND 4294967295),
  "modulo_chave" CHARACTER VARYING(60) COLLATE ci NOT NULL,
  "pode_acessar" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "ck_usuario_modulos_bkp_ids_a_partir_15_20261027_pode_acessar_7" CHECK ("pode_acessar" BETWEEN -128 AND 127),
  "pode_criar" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_usuario_modulos_bkp_ids_a_partir_15_20261027_pode_criar_9" CHECK ("pode_criar" BETWEEN -128 AND 127),
  "pode_editar" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_usuario_modulos_bkp_ids_a_partir_15_20261027_pode_editar_11" CHECK ("pode_editar" BETWEEN -128 AND 127),
  "pode_excluir" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_usuario_modulos_bkp_ids_a_partir_15_20261027_pode_excluir_13" CHECK ("pode_excluir" BETWEEN -128 AND 127),
  "pode_exportar" SMALLINT NOT NULL DEFAULT 0,
  CONSTRAINT "ck_usuario_modulos_bkp_ids_a_partir_15_20261027_po_d7a5ebf72ba3" CHECK ("pode_exportar" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0),
  "atualizado_em" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT LOCALTIMESTAMP(0)
);

CREATE TABLE "erp"."usuario_tenant" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "usuario_id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "permissao" CHARACTER VARYING COLLATE ci DEFAULT 'operador',
  CONSTRAINT "ck_usuario_tenant_permissao_4" CHECK ("permissao" IN ('admin', 'gerente', 'operador', 'visualizador')),
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_usuario_tenant_ativo_6" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."usuario_tenant_bkp_ids_a_partir_15_20261027" (
  "id" INTEGER NOT NULL DEFAULT 0,
  "usuario_id" INTEGER NOT NULL,
  "tenant_id" INTEGER NOT NULL,
  "permissao" CHARACTER VARYING COLLATE ci DEFAULT 'operador',
  CONSTRAINT "ck_usuario_tenant_bkp_ids_a_partir_15_20261027_permissao_4" CHECK ("permissao" IN ('admin', 'gerente', 'operador', 'visualizador')),
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_usuario_tenant_bkp_ids_a_partir_15_20261027_ativo_6" CHECK ("ativo" BETWEEN -128 AND 127),
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0)
);

CREATE TABLE "erp"."v_emails_recentes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "destinatario" CHARACTER VARYING(255) COLLATE ci,
  "assunto" CHARACTER VARYING(255) COLLATE ci,
  "tipo" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_v_emails_recentes_tipo_4" CHECK ("tipo" IN ('recuperacao_senha', 'boas_vindas', 'notificacao', 'outro')),
  "status" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_v_emails_recentes_status_6" CHECK ("status" IN ('enviado', 'erro', 'pendente')),
  "data_envio" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "morador_nome" CHARACTER VARYING(200) COLLATE ci,
  "morador_unidade" CHARACTER VARYING(50) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."validacoes_face_id" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "face_descriptor_id" INTEGER NOT NULL,
  "visitante_id" INTEGER NOT NULL,
  "acesso_id" INTEGER,
  "dispositivo_id" INTEGER,
  "similaridade" NUMERIC(5,4) NOT NULL,
  "threshold_usado" NUMERIC(5,4) DEFAULT 0.6000,
  "resultado" CHARACTER VARYING COLLATE ci NOT NULL,
  CONSTRAINT "ck_validacoes_face_id_resultado_9" CHECK ("resultado" IN ('sucesso', 'falha')),
  "motivo_falha" CHARACTER VARYING(255) COLLATE ci,
  "data_validacao" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT LOCALTIMESTAMP(0),
  "ip_validacao" CHARACTER VARYING(45) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."veiculos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "placa" CHARACTER VARYING(10) COLLATE ci NOT NULL,
  "modelo" CHARACTER VARYING(100) COLLATE ci NOT NULL,
  "cor" CHARACTER VARYING(50) COLLATE ci,
  "tipo" CHARACTER VARYING(50) COLLATE ci,
  "tag" CHARACTER VARYING(50) COLLATE ci NOT NULL,
  "morador_id" INTEGER NOT NULL,
  "dependente_id" INTEGER,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_veiculos_ativo_10" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "controlid_user_id" BIGINT,
  "controlid_tag_id" BIGINT,
  "controlid_sincronizado" SMALLINT DEFAULT 0,
  CONSTRAINT "ck_veiculos_controlid_sincronizado_16" CHECK ("controlid_sincronizado" BETWEEN -128 AND 127),
  "controlid_sync_data" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."view_estatisticas_dispositivos" (
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "total_dispositivos" BIGINT,
  "dispositivos_ativos" NUMERIC(23,0),
  "dispositivos_inativos" NUMERIC(23,0),
  "total_validacoes_geral" NUMERIC(32,0),
  "validacoes_hoje" BIGINT,
  "validacoes_sucesso_hoje" BIGINT,
  "validacoes_falha_hoje" BIGINT
);

CREATE TABLE "erp"."view_estatisticas_tokens" (
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "total_tokens" BIGINT,
  "tokens_ativos" NUMERIC(23,0),
  "tokens_usados" NUMERIC(23,0),
  "tokens_expirados" NUMERIC(23,0),
  "tokens_usados_hoje" NUMERIC(23,0),
  "tokens_usados_semana" NUMERIC(23,0),
  "tokens_usados_mes" NUMERIC(23,0)
);

CREATE TABLE "erp"."visitantes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tenant_id" INTEGER NOT NULL DEFAULT 1,
  "morador_id" INTEGER,
  "cadastrado_por_tipo" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'LEGADO',
  CONSTRAINT "ck_visitantes_cadastrado_por_tipo_4" CHECK ("cadastrado_por_tipo" IN ('FUNCIONARIO', 'MORADOR', 'LEGADO')),
  "cadastrado_por_usuario_id" INTEGER,
  "cadastrado_por_morador_id" INTEGER,
  "cadastrado_por_nome" CHARACTER VARYING(255) COLLATE ci,
  "dependente_id" INTEGER,
  "nome_completo" CHARACTER VARYING(255) COLLATE ci NOT NULL,
  "documento" CHARACTER VARYING(20) COLLATE ci NOT NULL,
  "tipo_documento" CHARACTER VARYING COLLATE ci NOT NULL DEFAULT 'CPF',
  CONSTRAINT "ck_visitantes_tipo_documento_12" CHECK ("tipo_documento" IN ('RG', 'CPF')),
  "cep" CHARACTER VARYING(10) COLLATE ci,
  "endereco" CHARACTER VARYING(255) COLLATE ci,
  "numero" CHARACTER VARYING(20) COLLATE ci,
  "complemento" CHARACTER VARYING(100) COLLATE ci,
  "bairro" CHARACTER VARYING(100) COLLATE ci,
  "cidade" CHARACTER VARYING(100) COLLATE ci,
  "estado" CHARACTER VARYING(2) COLLATE ci,
  "telefone" CHARACTER VARYING(20) COLLATE ci,
  "celular" CHARACTER VARYING(20) COLLATE ci,
  "telefone_contato" CHARACTER VARYING(20) COLLATE ci,
  "placa_veiculo" CHARACTER VARYING(10) COLLATE ci,
  "email" CHARACTER VARYING(255) COLLATE ci,
  "observacao" TEXT COLLATE ci,
  "foto" CHARACTER VARYING(500) COLLATE ci,
  "documento_arquivo" CHARACTER VARYING(500) COLLATE ci,
  "ativo" SMALLINT DEFAULT 1,
  CONSTRAINT "ck_visitantes_ativo_29" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_alertas_pendentes" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "veiculo_id" INTEGER,
  "placa" CHARACTER VARYING(8) COLLATE ci,
  "veiculo_modelo" CHARACTER VARYING(100) COLLATE ci,
  "categoria" CHARACTER VARYING(50) COLLATE ci,
  "descricao" TEXT COLLATE ci,
  "km_atual" INTEGER,
  "km_limite" INTEGER,
  "km_excedido" BIGINT,
  "data_geracao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "operador_id" INTEGER,
  "operador_nome" CHARACTER VARYING(255) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_checklist_completo" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "veiculo_id" INTEGER,
  "placa" CHARACTER VARYING(8) COLLATE ci,
  "veiculo_modelo" CHARACTER VARYING(100) COLLATE ci,
  "operador_id" INTEGER,
  "operador_nome" CHARACTER VARYING(255) COLLATE ci,
  "km_inicial" INTEGER,
  "km_final" INTEGER,
  "km_percorrido" BIGINT,
  "data_hora_abertura" TIMESTAMP(0) WITHOUT TIME ZONE,
  "data_hora_fechamento" TIMESTAMP(0) WITHOUT TIME ZONE,
  "status" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_checklist_completo_status_12" CHECK ("status" IN ('aberto', 'fechado')),
  "observacao_abertura" TEXT COLLATE ci,
  "observacao_fechamento" TEXT COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_consumo_por_morador" (
  "morador_id" INTEGER,
  "morador_nome" CHARACTER VARYING(200) COLLATE ci,
  "morador_unidade" CHARACTER VARYING(50) COLLATE ci,
  "total_retiradas" BIGINT,
  "quantidade_total" NUMERIC(32,2),
  "valor_total_consumido" NUMERIC(32,2),
  "ultima_retirada" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp())
);

CREATE TABLE "erp"."vw_consumo_veiculos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "placa" CHARACTER VARYING(8) COLLATE ci,
  "modelo" CHARACTER VARYING(100) COLLATE ci,
  "ano" INTEGER,
  "total_abastecimentos" BIGINT,
  "total_litros" NUMERIC(32,2),
  "total_gasto" NUMERIC(32,2),
  "preco_medio_litro" NUMERIC(20,10),
  "km_atual" INTEGER,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_dashboard_financeiro" (
  "mes_referencia" CHARACTER VARYING(7) COLLATE ci,
  "saldo_bancario_total" NUMERIC(37,2),
  "total_contas_ativas" BIGINT,
  "entradas_mes" NUMERIC(37,2),
  "saidas_mes" NUMERIC(37,2),
  "pendentes_conciliacao" BIGINT,
  "conciliados_total" BIGINT,
  "a_receber_mes" NUMERIC(34,2),
  "a_pagar_mes" NUMERIC(34,2),
  "inadimplencia_total" NUMERIC(34,2),
  "vencidas_a_pagar" NUMERIC(34,2)
);

CREATE TABLE "erp"."vw_dependentes_ativos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "morador_id" INTEGER,
  "nome_completo" CHARACTER VARYING(255) COLLATE ci,
  "cpf" CHARACTER VARYING(14) COLLATE ci,
  "parentesco" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_dependentes_ativos_parentesco_5" CHECK ("parentesco" IN ('Cônjuge', 'Filho(a)', 'Enteado(a)', 'Pai/Mãe', 'Irmão/Irmã', 'Avô/Avó', 'Outro')),
  "data_nascimento" DATE,
  "total_veiculos" BIGINT,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_dependentes_completo" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "morador_id" INTEGER,
  "morador_nome" CHARACTER VARYING(200) COLLATE ci,
  "unidade" CHARACTER VARYING(50) COLLATE ci,
  "nome_completo" CHARACTER VARYING(255) COLLATE ci,
  "cpf" CHARACTER VARYING(14) COLLATE ci,
  "email" CHARACTER VARYING(255) COLLATE ci,
  "celular" CHARACTER VARYING(20) COLLATE ci,
  "data_nascimento" DATE,
  "parentesco" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_dependentes_completo_parentesco_10" CHECK ("parentesco" IN ('Cônjuge', 'Filho(a)', 'Enteado(a)', 'Pai/Mãe', 'Irmão/Irmã', 'Avô/Avó', 'Outro')),
  "ativo" SMALLINT,
  CONSTRAINT "ck_vw_dependentes_completo_ativo_12" CHECK ("ativo" BETWEEN -128 AND 127),
  "data_cadastro" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "data_atualizacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  "total_veiculos" BIGINT,
  "observacao" TEXT COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_estatisticas_veiculo" (
  "veiculo_id" INTEGER,
  "placa" CHARACTER VARYING(8) COLLATE ci,
  "modelo" CHARACTER VARYING(100) COLLATE ci,
  "total_checklists" BIGINT,
  "checklists_abertos" NUMERIC(23,0),
  "checklists_fechados" NUMERIC(23,0),
  "ultimo_km_registrado" INTEGER,
  "total_alertas" BIGINT,
  "alertas_pendentes" NUMERIC(23,0)
);

CREATE TABLE "erp"."vw_extrato_bancario" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('vw_extrato_bancario.id') NOT NULL,
  CONSTRAINT "ck_vw_extrato_bancario_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "conta_id" BIGINT,
  CONSTRAINT "ck_vw_extrato_bancario_conta_id_3" CHECK ("conta_id" BETWEEN 0 AND 4294967295),
  "conta_nome" CHARACTER VARYING(120) COLLATE ci,
  "banco_nome" CHARACTER VARYING(80) COLLATE ci,
  "banco_codigo" CHARACTER VARYING(10) COLLATE ci,
  "data_lancamento" DATE,
  "tipo" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_extrato_bancario_tipo_9" CHECK ("tipo" IN ('credito', 'debito')),
  "valor" NUMERIC(15,2),
  "valor_sinal" NUMERIC(15,2),
  "descricao" CHARACTER VARYING(500) COLLATE ci,
  "favorecido" CHARACTER VARYING(255) COLLATE ci,
  "checknum" CHARACTER VARYING(30) COLLATE ci,
  "numero_documento" CHARACTER VARYING(60) COLLATE ci,
  "categoria" CHARACTER VARYING(80) COLLATE ci,
  "centro_custo" CHARACTER VARYING(80) COLLATE ci,
  "status_conciliacao" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_extrato_bancario_status_conciliacao_19" CHECK ("status_conciliacao" IN ('pendente', 'conciliado', 'ignorado', 'divergente')),
  "origem" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_extrato_bancario_origem_21" CHECK ("origem" IN ('ofx', 'manual', 'importacao')),
  "importacao_id" BIGINT,
  CONSTRAINT "ck_vw_extrato_bancario_importacao_id_23" CHECK ("importacao_id" BETWEEN 0 AND 4294967295),
  "conta_receber_id" BIGINT,
  CONSTRAINT "ck_vw_extrato_bancario_conta_receber_id_25" CHECK ("conta_receber_id" BETWEEN 0 AND 4294967295),
  "doc_receber" CHARACTER VARYING(50) COLLATE ci,
  "nome_receber" CHARACTER VARYING(255) COLLATE ci,
  "venc_receber" DATE,
  "status_receber" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_extrato_bancario_status_receber_30" CHECK ("status_receber" IN ('PENDENTE', 'PARCIAL', 'RECEBIDO', 'CANCELADO', 'ATRASADO')),
  "conta_pagar_id" BIGINT,
  CONSTRAINT "ck_vw_extrato_bancario_conta_pagar_id_32" CHECK ("conta_pagar_id" BETWEEN 0 AND 4294967295),
  "doc_pagar" CHARACTER VARYING(50) COLLATE ci,
  "nome_pagar" CHARACTER VARYING(255) COLLATE ci,
  "venc_pagar" DATE,
  "status_pagar" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_extrato_bancario_status_pagar_37" CHECK ("status_pagar" IN ('PENDENTE', 'PARCIAL', 'PAGO', 'CANCELADO', 'ATRASADO')),
  "data_importacao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "usuario_importacao" CHARACTER VARYING(80) COLLATE ci,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_fluxo_caixa" (
  "mes" CHARACTER VARYING(7) COLLATE ci,
  "mes_label" CHARACTER VARYING(37) COLLATE ci,
  "entradas_realizadas" NUMERIC(37,2),
  "saidas_realizadas" NUMERIC(37,2),
  "a_receber_previsto" NUMERIC(35,2),
  "a_pagar_previsto" NUMERIC(35,2)
);

CREATE TABLE "erp"."vw_movimentacoes_detalhadas" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "tipo_movimentacao" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_movimentacoes_detalhadas_tipo_movimentacao_2" CHECK ("tipo_movimentacao" IN ('Entrada', 'Saida', 'Ajuste', 'Devolucao')),
  "produto_codigo" CHARACTER VARYING(50) COLLATE ci,
  "produto_nome" CHARACTER VARYING(200) COLLATE ci,
  "categoria" CHARACTER VARYING(100) COLLATE ci,
  "quantidade" NUMERIC(10,2),
  "unidade_medida" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_movimentacoes_detalhadas_unidade_medida_8" CHECK ("unidade_medida" IN ('Unidade', 'Metro', 'Kg', 'Litro', 'Caixa', 'Pacote', 'Rolo', 'Saco', 'Outro')),
  "quantidade_anterior" NUMERIC(10,2),
  "quantidade_posterior" NUMERIC(10,2),
  "tipo_destino" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_movimentacoes_detalhadas_tipo_destino_12" CHECK ("tipo_destino" IN ('Morador', 'Administracao', 'Manutencao', 'Limpeza', 'Outro')),
  "morador_nome" CHARACTER VARYING(200) COLLATE ci,
  "morador_unidade" CHARACTER VARYING(50) COLLATE ci,
  "usuario_responsavel" CHARACTER VARYING(200) COLLATE ci,
  "motivo" CHARACTER VARYING(500) COLLATE ci,
  "nota_fiscal" CHARACTER VARYING(100) COLLATE ci,
  "valor_unitario" NUMERIC(10,2),
  "valor_total" NUMERIC(10,2),
  "fornecedor" CHARACTER VARYING(200) COLLATE ci,
  "data_movimentacao" TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT timezone('America/Sao_Paulo', statement_timestamp()),
  "observacoes" TEXT COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_pendencias_conciliacao" (
  "id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"('vw_pendencias_conciliacao.id') NOT NULL,
  CONSTRAINT "ck_vw_pendencias_conciliacao_id_1" CHECK ("id" BETWEEN 0 AND 18446744073709551615),
  "conta_id" BIGINT,
  CONSTRAINT "ck_vw_pendencias_conciliacao_conta_id_3" CHECK ("conta_id" BETWEEN 0 AND 4294967295),
  "conta_nome" CHARACTER VARYING(120) COLLATE ci,
  "banco_nome" CHARACTER VARYING(80) COLLATE ci,
  "data_lancamento" DATE,
  "tipo" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_pendencias_conciliacao_tipo_8" CHECK ("tipo" IN ('credito', 'debito')),
  "valor" NUMERIC(15,2),
  "descricao" CHARACTER VARYING(500) COLLATE ci,
  "favorecido" CHARACTER VARYING(255) COLLATE ci,
  "checknum" CHARACTER VARYING(30) COLLATE ci,
  "numero_documento" CHARACTER VARYING(60) COLLATE ci,
  "memo" TEXT COLLATE ci,
  "origem" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_pendencias_conciliacao_origem_16" CHECK ("origem" IN ('ofx', 'manual', 'importacao')),
  "data_importacao" TIMESTAMP(0) WITHOUT TIME ZONE,
  "criado_em" TIMESTAMP(0) WITHOUT TIME ZONE,
  "candidatos_receber" BIGINT,
  "candidatos_pagar" BIGINT,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_produtos_estoque_baixo" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "codigo" CHARACTER VARYING(50) COLLATE ci,
  "nome" CHARACTER VARYING(200) COLLATE ci,
  "categoria" CHARACTER VARYING(100) COLLATE ci,
  "quantidade_estoque" NUMERIC(10,2),
  "estoque_minimo" NUMERIC(10,2),
  "unidade_medida" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_produtos_estoque_baixo_unidade_medida_7" CHECK ("unidade_medida" IN ('Unidade', 'Metro', 'Kg', 'Litro', 'Caixa', 'Pacote', 'Rolo', 'Saco', 'Outro')),
  "deficit" NUMERIC(11,2),
  "preco_unitario" NUMERIC(10,2),
  "valor_reposicao" NUMERIC(21,4),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_saldo_contas" (
  "id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  CONSTRAINT "ck_vw_saldo_contas_id_1" CHECK ("id" BETWEEN 0 AND 4294967295),
  "nome" CHARACTER VARYING(120) COLLATE ci,
  "banco_nome" CHARACTER VARYING(80) COLLATE ci,
  "banco_codigo" CHARACTER VARYING(10) COLLATE ci,
  "agencia" CHARACTER VARYING(20) COLLATE ci,
  "conta_numero" CHARACTER VARYING(30) COLLATE ci,
  "conta_tipo" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_saldo_contas_conta_tipo_8" CHECK ("conta_tipo" IN ('corrente', 'poupanca', 'investimento', 'caixa')),
  "moeda" CHARACTER(3) COLLATE ci,
  "saldo_inicial" NUMERIC(15,2),
  "total_creditos" NUMERIC(37,2),
  "total_debitos" NUMERIC(37,2),
  "saldo_calculado" NUMERIC(39,2),
  "saldo_atual" NUMERIC(15,2),
  "ativo" SMALLINT,
  CONSTRAINT "ck_vw_saldo_contas_ativo_16" CHECK ("ativo" BETWEEN -128 AND 127),
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_ultimos_abastecimentos" (
  "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  "data_abastecimento" TIMESTAMP(0) WITHOUT TIME ZONE,
  "placa" CHARACTER VARYING(8) COLLATE ci,
  "modelo" CHARACTER VARYING(100) COLLATE ci,
  "km_abastecimento" INTEGER,
  "litros" NUMERIC(10,2),
  "valor" NUMERIC(10,2),
  "tipo_combustivel" CHARACTER VARYING COLLATE ci,
  CONSTRAINT "ck_vw_ultimos_abastecimentos_tipo_combustivel_8" CHECK ("tipo_combustivel" IN ('Gasolina', 'Álcool', 'Diesel')),
  "operador" CHARACTER VARYING(255) COLLATE ci,
  "usuario_logado" CHARACTER VARYING(100) COLLATE ci,
  CONSTRAINT "PRIMARY" PRIMARY KEY ("id")
);

CREATE TABLE "erp"."vw_validacao_dependente_visitante" (
  "dependente_id" INTEGER,
  "morador_id" INTEGER,
  "nome_completo" CHARACTER VARYING(255) COLLATE ci,
  "cpf" CHARACTER VARYING(14) COLLATE ci,
  "visitante_id" INTEGER,
  "visitante_nome" CHARACTER VARYING(255) COLLATE ci,
  "status" CHARACTER VARYING(8) COLLATE ci
);

CREATE TABLE "erp"."vw_valor_total_estoque" (
  "valor_total" NUMERIC(42,4),
  "total_produtos" BIGINT,
  "produtos_baixo_estoque" NUMERIC(23,0),
  "produtos_zerados" NUMERIC(23,0)
);
