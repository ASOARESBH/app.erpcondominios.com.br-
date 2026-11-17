-- REVIEW ARTIFACT: DO NOT APPLY until report.json has executable_complete=true and required reviews are approved.
-- Generator code SHA-256: 6681924b7a259c8b048c739ef2e30ea124cbda787061d09cf62ad5f41b865a58
-- Collation overrides SHA-256: 44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a

-- Generator version: 1.3.0

-- Catalog SHA-256: 9112484a2fd7ed128a51f4b39e167f6e025d6560ab78db24d341fc52e666c7f4

SET search_path TO "erp", pg_catalog;

CREATE INDEX "ix_administradoras_importacao_idx_administradora_i_c8c98eeea511" ON "erp"."administradoras_importacao" ("ativo" ASC, "ordem" ASC);

CREATE UNIQUE INDEX "ix_administradoras_importacao_uq_administradora_importacao_slug" ON "erp"."administradoras_importacao" ("slug" ASC);

CREATE INDEX "ix_administradoras_layouts_idx_administradora_layout_modulo" ON "erp"."administradoras_layouts" ("administradora_id" ASC, "modulo" ASC, "ativo" ASC);

CREATE UNIQUE INDEX "ix_administradoras_layouts_uq_administradora_layout_codigo" ON "erp"."administradoras_layouts" ("administradora_id" ASC, "codigo" ASC);

CREATE INDEX "ix_alertas_acesso_idx_alertas_tenant_ativo" ON "erp"."alertas_acesso" ("tenant_id" ASC, "ativo" ASC);

CREATE INDEX "ix_alertas_acesso_idx_alertas_tenant_nome" ON "erp"."alertas_acesso" ("tenant_id" ASC, "nome" ASC);

CREATE INDEX "ix_alertas_acesso_criterios_idx_criterios_alerta" ON "erp"."alertas_acesso_criterios" ("tenant_id" ASC, "alerta_id" ASC);

CREATE INDEX "ix_alertas_acesso_criterios_idx_criterios_busca" ON "erp"."alertas_acesso_criterios" ("tenant_id" ASC, "tipo" ASC, "campo" ASC, "valor" ASC);

CREATE INDEX "ix_alertas_acesso_entregas_idx_entregas_tenant_status" ON "erp"."alertas_acesso_entregas" ("tenant_id" ASC, "status" ASC);

CREATE UNIQUE INDEX "ix_alertas_acesso_entregas_uk_evento_canal_destinatario" ON "erp"."alertas_acesso_entregas" ("evento_id" ASC, "canal" ASC, "destinatario" ASC);

CREATE INDEX "ix_alertas_acesso_eventos_idx_eventos_status" ON "erp"."alertas_acesso_eventos" ("tenant_id" ASC, "status" ASC);

CREATE INDEX "ix_alertas_acesso_eventos_idx_eventos_tenant_data" ON "erp"."alertas_acesso_eventos" ("tenant_id" ASC, "detectado_em" ASC);

CREATE UNIQUE INDEX "ix_alertas_acesso_eventos_uk_alerta_evento" ON "erp"."alertas_acesso_eventos" ("tenant_id" ASC, "alerta_id" ASC, "evento_uuid" ASC);

CREATE INDEX "ix_alertas_acesso_leituras_idx_leituras_usuario" ON "erp"."alertas_acesso_leituras" ("tenant_id" ASC, "usuario_id" ASC, "lido" ASC);

CREATE UNIQUE INDEX "ix_alertas_acesso_leituras_uk_evento_usuario" ON "erp"."alertas_acesso_leituras" ("evento_id" ASC, "usuario_id" ASC);

CREATE INDEX "ix_alertas_unidade_acionamentos_idx_auac_tenant_alerta_data" ON "erp"."alertas_unidade_acionamentos" ("tenant_id" ASC, "alerta_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_acionamentos_idx_auac_tenant_data" ON "erp"."alertas_unidade_acionamentos" ("tenant_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_acionamentos_idx_auac_tenant_registro" ON "erp"."alertas_unidade_acionamentos" ("tenant_id" ASC, "registro_acesso_id" ASC);

CREATE INDEX "ix_alertas_unidade_acionamentos_idx_auac_tenant_usuario_data" ON "erp"."alertas_unidade_acionamentos" ("tenant_id" ASC, "usuario_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_alvos_idx_aualvo_tenant_alerta" ON "erp"."alertas_unidade_alvos" ("tenant_id" ASC, "alerta_id" ASC);

CREATE INDEX "ix_alertas_unidade_alvos_idx_aualvo_tenant_documento" ON "erp"."alertas_unidade_alvos" ("tenant_id" ASC, "documento_normalizado" ASC);

CREATE INDEX "ix_alertas_unidade_alvos_idx_aualvo_tenant_placa" ON "erp"."alertas_unidade_alvos" ("tenant_id" ASC, "placa_normalizada" ASC);

CREATE INDEX "ix_alertas_unidade_alvos_idx_aualvo_tenant_visitante" ON "erp"."alertas_unidade_alvos" ("tenant_id" ASC, "visitante_id" ASC);

CREATE INDEX "ix_alertas_unidade_cache_ids_idx_auci_atualizado" ON "erp"."alertas_unidade_cache_ids" ("tenant_id" ASC, "atualizado_em" ASC);

CREATE INDEX "ix_alertas_unidade_ciencias_idx_auc_tenant_alerta_data" ON "erp"."alertas_unidade_ciencias" ("tenant_id" ASC, "alerta_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_ciencias_idx_auc_tenant_data" ON "erp"."alertas_unidade_ciencias" ("tenant_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_ciencias_idx_auc_tenant_registro" ON "erp"."alertas_unidade_ciencias" ("tenant_id" ASC, "registro_acesso_id" ASC);

CREATE INDEX "ix_alertas_unidade_ciencias_idx_auc_tenant_usuario_data" ON "erp"."alertas_unidade_ciencias" ("tenant_id" ASC, "usuario_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_historico_idx_auh_tenant_alerta_data" ON "erp"."alertas_unidade_historico" ("tenant_id" ASC, "alerta_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_historico_idx_auh_tenant_data" ON "erp"."alertas_unidade_historico" ("tenant_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_historico_idx_auh_tenant_usuario_data" ON "erp"."alertas_unidade_historico" ("tenant_id" ASC, "usuario_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_lembretes_idx_aul_tenant_morador_data" ON "erp"."alertas_unidade_lembretes" ("tenant_id" ASC, "morador_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_lembretes_idx_aul_tenant_status_fim" ON "erp"."alertas_unidade_lembretes" ("tenant_id" ASC, "status" ASC, "vigencia_fim" ASC);

CREATE UNIQUE INDEX "ix_alertas_unidade_lembretes_uk_aul_expiracao" ON "erp"."alertas_unidade_lembretes" ("tenant_id" ASC, "alerta_id" ASC, "tipo" ASC, "vigencia_fim" ASC);

CREATE INDEX "ix_alertas_unidade_notificacoes_idx_aun_tenant_alerta_data" ON "erp"."alertas_unidade_notificacoes" ("tenant_id" ASC, "alerta_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_notificacoes_idx_aun_tenant_morador_data" ON "erp"."alertas_unidade_notificacoes" ("tenant_id" ASC, "morador_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_alertas_unidade_notificacoes_idx_aun_tenant_notificacao" ON "erp"."alertas_unidade_notificacoes" ("tenant_id" ASC, "notificacao_morador_id" ASC);

CREATE UNIQUE INDEX "ix_alertas_unidade_notificacoes_uk_aun_dedupe" ON "erp"."alertas_unidade_notificacoes" ("tenant_id" ASC, "alerta_id" ASC, "morador_id" ASC, "janela_inicio" ASC);

CREATE INDEX "ix_alertas_unidade_preferencias_idx_aup_tenant_push" ON "erp"."alertas_unidade_preferencias" ("tenant_id" ASC, "push_ativo" ASC);

CREATE INDEX "ix_alertas_unidade_rate_limits_idx_aurl_tenant_unidade_bucket" ON "erp"."alertas_unidade_rate_limits" ("tenant_id" ASC, "unidade" ASC, "bucket_inicio" ASC);

CREATE UNIQUE INDEX "ix_alertas_unidade_rate_limits_uk_aurl_bucket" ON "erp"."alertas_unidade_rate_limits" ("tenant_id" ASC, "usuario_id" ASC, "unidade" ASC, "bucket_inicio" ASC);

CREATE INDEX "ix_aplicativos_catalogo_idx_aplicativos_catalogo_plataforma" ON "erp"."aplicativos_catalogo" ("plataforma" ASC);

CREATE INDEX "ix_aplicativos_catalogo_idx_aplicativos_catalogo_status" ON "erp"."aplicativos_catalogo" ("status" ASC);

CREATE UNIQUE INDEX "ix_aplicativos_catalogo_uk_aplicativos_catalogo_chave" ON "erp"."aplicativos_catalogo" ("chave" ASC);

CREATE INDEX "ix_aplicativos_versionamento_log_idx_aplicativos_log_aplicativo" ON "erp"."aplicativos_versionamento_log" ("aplicativo_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_aplicativos_versionamento_log_idx_aplicativos_log_versao" ON "erp"."aplicativos_versionamento_log" ("versao_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_aplicativos_versoes_idx_aplicativos_versoes_lista" ON "erp"."aplicativos_versoes" ("aplicativo_id" ASC, "status" ASC, "canal" ASC, "criado_em" ASC);

CREATE UNIQUE INDEX "ix_aplicativos_versoes_uk_aplicativo_version_code" ON "erp"."aplicativos_versoes" ("aplicativo_id" ASC, "version_code" ASC);

CREATE INDEX "ix_assembleias_idx_assembleias_tenant_data" ON "erp"."assembleias" ("tenant_id" ASC, "data_assembleia" ASC);

CREATE INDEX "ix_assembleias_idx_data" ON "erp"."assembleias" ("data_assembleia" ASC);

CREATE INDEX "ix_assembleias_idx_status" ON "erp"."assembleias" ("status" ASC);

CREATE INDEX "ix_chatin_bloqueios_idx_chatin_bloqueado" ON "erp"."chatin_bloqueios" ("tenant_id" ASC, "bloqueado_tipo" ASC, "bloqueado_id" ASC);

CREATE INDEX "ix_chatin_conversas_idx_chatin_conversa_tenant_ult" ON "erp"."chatin_conversas" ("tenant_id" ASC, "ultima_mensagem_em" ASC);

CREATE UNIQUE INDEX "ix_chatin_conversas_uk_chatin_conversa_chave" ON "erp"."chatin_conversas" ("tenant_id" ASC, "chave_unica" ASC);

CREATE INDEX "ix_chatin_mensagens_idx_chatin_msg_conversa" ON "erp"."chatin_mensagens" ("tenant_id" ASC, "conversa_id" ASC, "id" ASC);

CREATE INDEX "ix_chatin_mensagens_idx_chatin_msg_remetente" ON "erp"."chatin_mensagens" ("tenant_id" ASC, "remetente_tipo" ASC, "remetente_id" ASC, "criado_em" ASC);

CREATE UNIQUE INDEX "ix_chatin_mensagens_uk_chatin_msg_idem" ON "erp"."chatin_mensagens" ("tenant_id" ASC, "remetente_tipo" ASC, "remetente_id" ASC, "idempotency_key" ASC);

CREATE INDEX "ix_chatin_participantes_idx_chatin_part_pessoa" ON "erp"."chatin_participantes" ("tenant_id" ASC, "participante_tipo" ASC, "participante_id" ASC);

CREATE UNIQUE INDEX "ix_chatin_participantes_uk_chatin_part" ON "erp"."chatin_participantes" ("conversa_id" ASC, "participante_tipo" ASC, "participante_id" ASC);

CREATE INDEX "ix_chatin_presenca_idx_chatin_presenca_ping" ON "erp"."chatin_presenca" ("tenant_id" ASC, "ultimo_ping" ASC);

CREATE INDEX "ix_config_moradores_campos_idx_config_moradores_tenant" ON "erp"."config_moradores_campos" ("tenant_id" ASC);

CREATE INDEX "ix_config_moradores_campos_idx_config_moradores_usuario" ON "erp"."config_moradores_campos" ("atualizado_por_usuario_id" ASC);

CREATE UNIQUE INDEX "ix_config_moradores_campos_uk_config_moradores_ten_4d39943d94f8" ON "erp"."config_moradores_campos" ("tenant_id" ASC, "grupo" ASC, "campo" ASC);

CREATE INDEX "ix_config_moradores_opcoes_idx_config_moradores_opcoes_tenant" ON "erp"."config_moradores_opcoes" ("tenant_id" ASC);

CREATE INDEX "ix_config_moradores_opcoes_idx_config_moradores_opcoes_usuario" ON "erp"."config_moradores_opcoes" ("atualizado_por_usuario_id" ASC);

CREATE UNIQUE INDEX "ix_config_moradores_opcoes_uk_config_moradores_tenant_opcao" ON "erp"."config_moradores_opcoes" ("tenant_id" ASC, "opcao" ASC);

CREATE INDEX "ix_config_visitantes_campos_idx_config_visitantes_tenant" ON "erp"."config_visitantes_campos" ("tenant_id" ASC);

CREATE INDEX "ix_config_visitantes_campos_idx_config_visitantes_usuario" ON "erp"."config_visitantes_campos" ("atualizado_por_usuario_id" ASC);

CREATE UNIQUE INDEX "ix_config_visitantes_campos_uk_config_visitantes_tenant_campo" ON "erp"."config_visitantes_campos" ("tenant_id" ASC, "campo" ASC);

CREATE INDEX "ix_configuracoes_idx_configuracoes_tenant" ON "erp"."configuracoes" ("tenant_id" ASC);

CREATE INDEX "ix_contas_bancarias_idx_contas_bancarias_tenant" ON "erp"."contas_bancarias" ("tenant_id" ASC);

CREATE INDEX "ix_contas_pagar_idx_contas_pagar_tenant" ON "erp"."contas_pagar" ("tenant_id" ASC);

CREATE INDEX "ix_contas_receber_idx_contas_receber_tenant" ON "erp"."contas_receber" ("tenant_id" ASC);

CREATE INDEX "ix_contrato_orcamento_documentos_idx_tenant_orcamento" ON "erp"."contrato_orcamento_documentos" ("tenant_id" ASC, "orcamento_id" ASC);

CREATE INDEX "ix_contratos_idx_contratos_tenant" ON "erp"."contratos" ("tenant_id" ASC);

CREATE INDEX "ix_convidados_idx_conv_tenant_autor_morador" ON "erp"."convidados" ("tenant_id" ASC, "cadastrado_por_morador_id" ASC, "status" ASC);

CREATE INDEX "ix_convidados_idx_conv_tenant_doc" ON "erp"."convidados" ("tenant_id" ASC, "documento_normalizado" ASC);

CREATE INDEX "ix_convidados_idx_conv_tenant_morador" ON "erp"."convidados" ("tenant_id" ASC, "morador_id" ASC);

CREATE INDEX "ix_convidados_idx_conv_tenant_periodo" ON "erp"."convidados" ("tenant_id" ASC, "data_prevista_chegada" ASC, "validade_fim" ASC);

CREATE INDEX "ix_convidados_idx_conv_tenant_status" ON "erp"."convidados" ("tenant_id" ASC, "status" ASC);

CREATE UNIQUE INDEX "ix_convidados_uk_conv_tenant_idempotency" ON "erp"."convidados" ("tenant_id" ASC, "idempotency_key" ASC);

CREATE INDEX "ix_dashboard_empresa_widgets_fk_dashboard_empresa__bc1f651f2d9e" ON "erp"."dashboard_empresa_widgets" ("widget_key" ASC);

CREATE INDEX "ix_dashboard_empresa_widgets_idx_dashboard_empresa_tenant" ON "erp"."dashboard_empresa_widgets" ("tenant_id" ASC);

CREATE UNIQUE INDEX "ix_dashboard_empresa_widgets_uk_dashboard_empresa_widget" ON "erp"."dashboard_empresa_widgets" ("tenant_id" ASC, "widget_key" ASC);

CREATE INDEX "ix_dashboard_usuario_widgets_fk_dashboard_usuario__cb4b7412e43a" ON "erp"."dashboard_usuario_widgets" ("widget_key" ASC);

CREATE INDEX "ix_dashboard_usuario_widgets_idx_dashboard_usuario" ON "erp"."dashboard_usuario_widgets" ("tenant_id" ASC, "usuario_id" ASC);

CREATE UNIQUE INDEX "ix_dashboard_usuario_widgets_uk_dashboard_usuario_widget" ON "erp"."dashboard_usuario_widgets" ("tenant_id" ASC, "usuario_id" ASC, "widget_key" ASC);

CREATE INDEX "ix_dashboard_widgets_catalogo_idx_dashboard_modulo_ordem" ON "erp"."dashboard_widgets_catalogo" ("modulo_key" ASC, "ordem" ASC);

CREATE UNIQUE INDEX "ix_dashboard_widgets_catalogo_uk_dashboard_widget_key" ON "erp"."dashboard_widgets_catalogo" ("widget_key" ASC);

CREATE UNIQUE INDEX "ix_departamentos_uk_tenant_nome" ON "erp"."departamentos" ("tenant_id" ASC, "nome" ASC);

CREATE INDEX "ix_dependentes_idx_dependentes_tenant" ON "erp"."dependentes" ("tenant_id" ASC);

CREATE INDEX "ix_documentos_idx_documentos_tenant" ON "erp"."documentos" ("tenant_id" ASC);

CREATE INDEX "ix_documentos_usuarios_acesso_idx_documento_id" ON "erp"."documentos_usuarios_acesso" ("documento_id" ASC);

CREATE INDEX "ix_documentos_usuarios_acesso_idx_usuario_id" ON "erp"."documentos_usuarios_acesso" ("usuario_id" ASC);

CREATE UNIQUE INDEX "ix_documentos_usuarios_acesso_uk_doc_usr" ON "erp"."documentos_usuarios_acesso" ("documento_id" ASC, "usuario_id" ASC);

CREATE INDEX "ix_empresa_idx_empresa_tenant" ON "erp"."empresa" ("tenant_id" ASC);

CREATE INDEX "ix_empresa_administradora_idx_empresa_administradora_adm" ON "erp"."empresa_administradora" ("administradora_id" ASC, "ativo" ASC);

CREATE UNIQUE INDEX "ix_empresa_administradora_uq_empresa_administradora_tenant" ON "erp"."empresa_administradora" ("tenant_id" ASC);

CREATE INDEX "ix_empresa_administradora_layout_idx_empresa_adm_layout_ativo" ON "erp"."empresa_administradora_layout" ("tenant_id" ASC, "ativo" ASC);

CREATE INDEX "ix_empresa_administradora_layout_idx_empresa_adm_layout_layout" ON "erp"."empresa_administradora_layout" ("administradora_layout_id" ASC);

CREATE UNIQUE INDEX "ix_empresa_administradora_layout_uq_empresa_adm_layout_modulo" ON "erp"."empresa_administradora_layout" ("tenant_id" ASC, "modulo" ASC);

CREATE INDEX "ix_eventos_condominio_idx_eventos_tenant_data" ON "erp"."eventos_condominio" ("tenant_id" ASC, "data_inicio" ASC);

CREATE INDEX "ix_eventos_condominio_idx_eventos_tenant_status" ON "erp"."eventos_condominio" ("tenant_id" ASC, "status" ASC);

CREATE INDEX "ix_hidrometros_idx_hidrometros_tenant" ON "erp"."hidrometros" ("tenant_id" ASC);

CREATE INDEX "ix_inadimplencia_comparacoes_idx_inad_comparacao_anterior" ON "erp"."inadimplencia_comparacoes" ("tenant_id" ASC, "importacao_anterior_id" ASC);

CREATE INDEX "ix_inadimplencia_comparacoes_idx_inad_comparacao_status" ON "erp"."inadimplencia_comparacoes" ("tenant_id" ASC, "status_comparacao" ASC);

CREATE UNIQUE INDEX "ix_inadimplencia_comparacoes_uq_inad_comparacao_atual" ON "erp"."inadimplencia_comparacoes" ("tenant_id" ASC, "importacao_atual_id" ASC);

CREATE INDEX "ix_inadimplencia_importacoes_idx_inad_tenant_data" ON "erp"."inadimplencia_importacoes" ("tenant_id" ASC, "data_base" ASC);

CREATE INDEX "ix_inadimplencia_importacoes_idx_inad_tenant_status" ON "erp"."inadimplencia_importacoes" ("tenant_id" ASC, "status" ASC);

CREATE INDEX "ix_inadimplencia_lancamentos_idx_inad_importacao" ON "erp"."inadimplencia_lancamentos" ("importacao_id" ASC);

CREATE INDEX "ix_inadimplencia_lancamentos_idx_inad_morador" ON "erp"."inadimplencia_lancamentos" ("tenant_id" ASC, "morador_id" ASC);

CREATE INDEX "ix_inadimplencia_lancamentos_idx_inad_tenant_chave" ON "erp"."inadimplencia_lancamentos" ("tenant_id" ASC, "chave_comparacao" ASC);

CREATE INDEX "ix_inadimplencia_lancamentos_idx_inad_tenant_gleba" ON "erp"."inadimplencia_lancamentos" ("tenant_id" ASC, "gleba_numero" ASC);

CREATE INDEX "ix_inadimplencia_lancamentos_idx_inad_unidade" ON "erp"."inadimplencia_lancamentos" ("tenant_id" ASC, "unidade_id" ASC);

CREATE INDEX "ix_leituras_idx_hidrometro_data" ON "erp"."leituras" ("hidrometro_id" ASC, "data_leitura" ASC);

CREATE INDEX "ix_leituras_idx_leituras_tenant" ON "erp"."leituras" ("tenant_id" ASC);

CREATE UNIQUE INDEX "ix_leituras_uq_leituras_tenant_client_uuid" ON "erp"."leituras" ("tenant_id" ASC, "client_uuid" ASC);

CREATE INDEX "ix_monitoramento_cameras_idx_monitoramento_camera_agent" ON "erp"."monitoramento_cameras" ("agente_id" ASC);

CREATE INDEX "ix_monitoramento_cameras_idx_monitoramento_camera_tenant" ON "erp"."monitoramento_cameras" ("tenant_id" ASC, "ativo" ASC);

CREATE UNIQUE INDEX "ix_monitoramento_cameras_uk_monitoramento_camera_agent_key" ON "erp"."monitoramento_cameras" ("agente_id" ASC, "external_key" ASC);

CREATE INDEX "ix_monitoramento_configuracoes_idx_monitoramento_config_tenant" ON "erp"."monitoramento_configuracoes" ("tenant_id" ASC);

CREATE UNIQUE INDEX "ix_monitoramento_configuracoes_uk_monitoramento_config_tenant" ON "erp"."monitoramento_configuracoes" ("tenant_id" ASC);

CREATE INDEX "ix_monitoramento_dispositivos_local_idx_monitorame_6477b7d2276c" ON "erp"."monitoramento_dispositivos_local" ("tenant_id" ASC, "ultimo_status" ASC);

CREATE INDEX "ix_monitoramento_dispositivos_local_idx_monitorame_b20fc34f61e2" ON "erp"."monitoramento_dispositivos_local" ("tenant_id" ASC, "ativo" ASC);

CREATE UNIQUE INDEX "ix_monitoramento_dispositivos_local_uk_monitoramen_56c19c05b5ee" ON "erp"."monitoramento_dispositivos_local" ("tenant_id" ASC, "agente_id" ASC, "external_key" ASC);

CREATE INDEX "ix_monitoramento_evento_acesso_idx_monitoramento_access_tenant" ON "erp"."monitoramento_evento_acesso" ("tenant_id" ASC, "criado_em" ASC);

CREATE UNIQUE INDEX "ix_monitoramento_evento_acesso_uk_monitoramento_access_event" ON "erp"."monitoramento_evento_acesso" ("tenant_id" ASC, "evento_id" ASC);

CREATE UNIQUE INDEX "ix_monitoramento_evento_acesso_uk_monitoramento_access_record" ON "erp"."monitoramento_evento_acesso" ("tenant_id" ASC, "registro_acesso_id" ASC);

CREATE INDEX "ix_monitoramento_evento_revisoes_idx_monitoramento_6992f384a5d6" ON "erp"."monitoramento_evento_revisoes" ("tenant_id" ASC, "evento_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_monitoramento_eventos_idx_monitoramento_event_camera_time" ON "erp"."monitoramento_eventos" ("tenant_id" ASC, "camera_id" ASC, "capturado_em" ASC);

CREATE INDEX "ix_monitoramento_eventos_idx_monitoramento_event_plate_time" ON "erp"."monitoramento_eventos" ("tenant_id" ASC, "placa_normalizada" ASC, "capturado_em" ASC);

CREATE INDEX "ix_monitoramento_eventos_idx_monitoramento_event_status" ON "erp"."monitoramento_eventos" ("tenant_id" ASC, "status_evento" ASC);

CREATE INDEX "ix_monitoramento_eventos_idx_monitoramento_event_tenant_time" ON "erp"."monitoramento_eventos" ("tenant_id" ASC, "capturado_em" ASC);

CREATE UNIQUE INDEX "ix_monitoramento_eventos_uk_monitoramento_event_tenant_uuid" ON "erp"."monitoramento_eventos" ("tenant_id" ASC, "agente_id" ASC, "event_uuid" ASC);

CREATE INDEX "ix_monitoring_releases_idx_monitoring_release_status_channel" ON "erp"."monitoring_releases" ("status" ASC, "channel" ASC, "version_code" ASC);

CREATE UNIQUE INDEX "ix_monitoring_releases_uk_monitoring_release_code_channel" ON "erp"."monitoring_releases" ("version_code" ASC, "channel" ASC);

CREATE INDEX "ix_monitoring_update_log_idx_monitoring_update_log_agent" ON "erp"."monitoring_update_log" ("agent_id" ASC, "created_at" ASC);

CREATE INDEX "ix_monitoring_update_log_idx_monitoring_update_log_release" ON "erp"."monitoring_update_log" ("release_id" ASC, "created_at" ASC);

CREATE INDEX "ix_movimentacoes_bancarias_idx_movbancarias_tenant" ON "erp"."movimentacoes_bancarias" ("tenant_id" ASC);

CREATE UNIQUE INDEX "ix_mt_consolidacao_exclusoes_uk_mt_exclusao_tabela" ON "erp"."mt_consolidacao_exclusoes" ("tabela" ASC);

CREATE UNIQUE INDEX "ix_mt_consolidacao_tabelas_uk_mt_consolidacao_tabela" ON "erp"."mt_consolidacao_tabelas" ("tabela" ASC, "tenant_id" ASC);

CREATE UNIQUE INDEX "ix_mt_integridade_ids_log_uk_mt_integridade_tabela" ON "erp"."mt_integridade_ids_log" ("tabela" ASC);

CREATE INDEX "ix_mt_os_chamados_id_zero_backup_idx_os_chamados_tenant" ON "erp"."mt_os_chamados_id_zero_backup" ("tenant_id" ASC);

CREATE INDEX "ix_mt_protocolos_id_zero_backup_idx_protocolos_tenant" ON "erp"."mt_protocolos_id_zero_backup" ("tenant_id" ASC);

CREATE UNIQUE INDEX "ix_mt_reconstrucao_exclusoes_uk_mt_reconstrucao_exclusao" ON "erp"."mt_reconstrucao_exclusoes" ("tabela" ASC);

CREATE UNIQUE INDEX "ix_mt_reconstrucao_tabelas_uk_mt_reconstrucao" ON "erp"."mt_reconstrucao_tabelas" ("tabela" ASC, "tenant_id" ASC);

CREATE INDEX "ix_notificacoes_morador_idx_notif_morador_listagem" ON "erp"."notificacoes_morador" ("tenant_id" ASC, "morador_id" ASC, "lida" ASC, "criado_em" ASC);

CREATE INDEX "ix_notificacoes_morador_idx_notif_protocolo" ON "erp"."notificacoes_morador" ("tenant_id" ASC, "protocolo_id" ASC);

CREATE INDEX "ix_notificacoes_morador_idx_notif_registro_acesso" ON "erp"."notificacoes_morador" ("tenant_id" ASC, "registro_acesso_id" ASC);

CREATE UNIQUE INDEX "ix_notificacoes_morador_uq_notif_morador_protocolo_evento" ON "erp"."notificacoes_morador" ("tenant_id" ASC, "morador_id" ASC, "protocolo_id" ASC, "tipo" ASC);

CREATE UNIQUE INDEX "ix_notificacoes_morador_uq_notif_morador_registro_evento" ON "erp"."notificacoes_morador" ("tenant_id" ASC, "morador_id" ASC, "registro_acesso_id" ASC, "tipo" ASC);

CREATE INDEX "ix_os_chamados_idx_morador_id" ON "erp"."os_chamados" ("morador_id" ASC);

CREATE INDEX "ix_os_chamados_idx_origem_portal" ON "erp"."os_chamados" ("origem_portal" ASC);

CREATE INDEX "ix_os_chamados_idx_os_chamados_tenant" ON "erp"."os_chamados" ("tenant_id" ASC);

CREATE INDEX "ix_os_chamados_idx_os_tenant_unidade" ON "erp"."os_chamados" ("tenant_id" ASC, "morador_unidade" ASC);

CREATE INDEX "ix_os_integridade_interacoes_log_idx_tabela_data" ON "erp"."os_integridade_interacoes_log" ("tabela" ASC, "executado_em" ASC);

CREATE INDEX "ix_os_interacao_fotos_quarentena_idx_interacao_origem" ON "erp"."os_interacao_fotos_quarentena" ("interacao_id_origem" ASC);

CREATE INDEX "ix_os_interacao_fotos_quarentena_idx_tenant_foto_origem" ON "erp"."os_interacao_fotos_quarentena" ("tenant_id" ASC, "foto_id_origem" ASC);

CREATE INDEX "ix_os_permissoes_departamento_idx_os_permissoes_departamento" ON "erp"."os_permissoes_departamento" ("tenant_id" ASC, "departamento" ASC);

CREATE INDEX "ix_os_permissoes_departamento_idx_os_permissoes_usuario" ON "erp"."os_permissoes_departamento" ("tenant_id" ASC, "usuario_id" ASC);

CREATE UNIQUE INDEX "ix_os_permissoes_departamento_uk_os_usuario_departamento" ON "erp"."os_permissoes_departamento" ("tenant_id" ASC, "usuario_id" ASC, "departamento" ASC);

CREATE INDEX "ix_os_usuarios_config_idx_os_usuario_config_tenant" ON "erp"."os_usuarios_config" ("tenant_id" ASC);

CREATE UNIQUE INDEX "ix_os_usuarios_config_uk_os_usuario_config" ON "erp"."os_usuarios_config" ("tenant_id" ASC, "usuario_id" ASC);

CREATE INDEX "ix_planos_contas_idx_planos_contas_tenant" ON "erp"."planos_contas" ("tenant_id" ASC);

CREATE INDEX "ix_produtos_estoque_idx_produtos_estoque_tenant" ON "erp"."produtos_estoque" ("tenant_id" ASC);

CREATE INDEX "ix_protocolos_idx_protocolos_tenant" ON "erp"."protocolos" ("tenant_id" ASC);

CREATE UNIQUE INDEX "ix_pwa_configuracoes_uq_pwa_config_tenant_chave" ON "erp"."pwa_configuracoes" ("tenant_id" ASC, "chave" ASC);

CREATE INDEX "ix_rbac_grupo_permissoes_idx_rbac_gp_permissao" ON "erp"."rbac_grupo_permissoes" ("permissao_id" ASC);

CREATE UNIQUE INDEX "ix_rbac_grupo_permissoes_uk_rbac_grupo_permissao" ON "erp"."rbac_grupo_permissoes" ("grupo_id" ASC, "permissao_id" ASC);

CREATE INDEX "ix_rbac_grupos_idx_rbac_grupo_tenant_ativo" ON "erp"."rbac_grupos" ("tenant_id" ASC, "ativo" ASC);

CREATE UNIQUE INDEX "ix_rbac_grupos_uk_rbac_grupo_tenant_slug" ON "erp"."rbac_grupos" ("tenant_id" ASC, "slug" ASC);

CREATE INDEX "ix_rbac_modulos_idx_rbac_modulo_pagina" ON "erp"."rbac_modulos" ("pagina" ASC);

CREATE INDEX "ix_rbac_modulos_idx_rbac_modulo_pai" ON "erp"."rbac_modulos" ("modulo_pai_id" ASC);

CREATE UNIQUE INDEX "ix_rbac_modulos_uk_rbac_modulo_chave" ON "erp"."rbac_modulos" ("chave" ASC);

CREATE INDEX "ix_rbac_permissoes_idx_rbac_permissao_modulo" ON "erp"."rbac_permissoes" ("modulo_id" ASC);

CREATE UNIQUE INDEX "ix_rbac_permissoes_uk_rbac_modulo_acao" ON "erp"."rbac_permissoes" ("modulo_id" ASC, "acao" ASC);

CREATE INDEX "ix_rbac_politicas_api_idx_rbac_politica_modulo" ON "erp"."rbac_politicas_api" ("modulo_chave" ASC, "acao_permissao" ASC);

CREATE UNIQUE INDEX "ix_rbac_politicas_api_uk_rbac_politica" ON "erp"."rbac_politicas_api" ("endpoint" ASC, "acao_requisicao" ASC, "metodo_http" ASC);

CREATE INDEX "ix_rbac_usuario_grupos_idx_rbac_ug_grupo" ON "erp"."rbac_usuario_grupos" ("grupo_id" ASC);

CREATE INDEX "ix_rbac_usuario_grupos_idx_rbac_ug_tenant_usuario" ON "erp"."rbac_usuario_grupos" ("tenant_id" ASC, "usuario_id" ASC);

CREATE UNIQUE INDEX "ix_rbac_usuario_grupos_uk_rbac_usuario_grupo_tenant" ON "erp"."rbac_usuario_grupos" ("usuario_id" ASC, "tenant_id" ASC, "grupo_id" ASC);

CREATE INDEX "ix_rbac_usuario_permissoes_idx_rbac_up_permissao" ON "erp"."rbac_usuario_permissoes" ("permissao_id" ASC);

CREATE INDEX "ix_rbac_usuario_permissoes_idx_rbac_up_tenant_usuario" ON "erp"."rbac_usuario_permissoes" ("tenant_id" ASC, "usuario_id" ASC);

CREATE UNIQUE INDEX "ix_rbac_usuario_permissoes_uk_rbac_usuario_permissao_tenant" ON "erp"."rbac_usuario_permissoes" ("usuario_id" ASC, "tenant_id" ASC, "permissao_id" ASC);

CREATE INDEX "ix_registros_acesso_idx_registros_acesso_tenant" ON "erp"."registros_acesso" ("tenant_id" ASC);

CREATE INDEX "ix_registros_acesso_idx_registros_acesso_tenant_convidado" ON "erp"."registros_acesso" ("tenant_id" ASC, "convidado_id" ASC);

CREATE UNIQUE INDEX "ix_registros_acesso_uk_registros_acesso_tenant_idempotency" ON "erp"."registros_acesso" ("tenant_id" ASC, "idempotency_key" ASC);

CREATE INDEX "ix_rh_banco_horas_idx_rh_bh_lancamento" ON "erp"."rh_banco_horas" ("lancamento_id" ASC);

CREATE INDEX "ix_rh_banco_horas_idx_rh_bh_reprocessamento" ON "erp"."rh_banco_horas" ("tenant_id" ASC, "reprocessamento_id" ASC);

CREATE INDEX "ix_rh_banco_horas_idx_rh_bh_tenant_colab_data" ON "erp"."rh_banco_horas" ("tenant_id" ASC, "colaborador_id" ASC, "data" ASC);

CREATE UNIQUE INDEX "ix_rh_banco_horas_uq_rh_bh_tenant_idempotency" ON "erp"."rh_banco_horas" ("tenant_id" ASC, "idempotency_key" ASC);

CREATE INDEX "ix_rh_ponto_lancamento_idx_rh_ponto_tenant" ON "erp"."rh_ponto_lancamento" ("tenant_id" ASC);

CREATE INDEX "ix_ronda_auditoria_idx_ronda_auditoria_rota" ON "erp"."ronda_auditoria" ("tenant_id" ASC, "rota_id" ASC);

CREATE INDEX "ix_ronda_auditoria_idx_ronda_auditoria_tenant_data" ON "erp"."ronda_auditoria" ("tenant_id" ASC, "criado_em" ASC);

CREATE INDEX "ix_ronda_registros_idx_ronda_registro_rota_data" ON "erp"."ronda_registros" ("tenant_id" ASC, "rota_id" ASC, "registrado_em" ASC);

CREATE INDEX "ix_ronda_registros_idx_ronda_registro_sla" ON "erp"."ronda_registros" ("tenant_id" ASC, "status_sla" ASC, "registrado_em" ASC);

CREATE INDEX "ix_ronda_registros_idx_ronda_registro_vigilante_data" ON "erp"."ronda_registros" ("tenant_id" ASC, "colaborador_id" ASC, "registrado_em" ASC);

CREATE UNIQUE INDEX "ix_ronda_registros_uk_ronda_registro_ponto_ciclo" ON "erp"."ronda_registros" ("tenant_id" ASC, "ponto_id" ASC, "colaborador_id" ASC, "ciclo_chave" ASC);

CREATE INDEX "ix_ronda_rotas_idx_ronda_tenant_ativo" ON "erp"."ronda_rotas" ("tenant_id" ASC, "ativo" ASC);

CREATE INDEX "ix_ronda_rotas_idx_ronda_tenant_dias" ON "erp"."ronda_rotas" ("tenant_id" ASC, "dias_semana" ASC);

CREATE INDEX "ix_ronda_vigilantes_idx_ronda_vigilante_colaborador" ON "erp"."ronda_vigilantes" ("tenant_id" ASC, "colaborador_id" ASC, "ativo" ASC);

CREATE UNIQUE INDEX "ix_ronda_vigilantes_uk_ronda_vigilante" ON "erp"."ronda_vigilantes" ("tenant_id" ASC, "rota_id" ASC, "colaborador_id" ASC);

CREATE INDEX "ix_tenant_arquivo_referencias_idx_referencia_arquivo" ON "erp"."tenant_arquivo_referencias" ("arquivo_id" ASC);

CREATE INDEX "ix_tenant_arquivo_referencias_idx_referencia_registro" ON "erp"."tenant_arquivo_referencias" ("tenant_id" ASC, "modulo" ASC, "registro_id" ASC);

CREATE UNIQUE INDEX "ix_tenant_arquivo_referencias_uk_referencia_arquivo" ON "erp"."tenant_arquivo_referencias" ("tenant_id" ASC, "arquivo_id" ASC, "modulo" ASC, "registro_id" ASC, "campo_origem" ASC);

CREATE INDEX "ix_tenants_idx_tenant_status" ON "erp"."tenants" ("status" ASC);

CREATE UNIQUE INDEX "ix_tenants_uk_tenant_slug" ON "erp"."tenants" ("slug" ASC);

CREATE UNIQUE INDEX "ix_tenants_uk_tenants_projetos_slug" ON "erp"."tenants" ("projetos_slug" ASC);

CREATE INDEX "ix_unidades_idx_unidades_tenant" ON "erp"."unidades" ("tenant_id" ASC);

CREATE INDEX "ix_usuario_tenant_idx_ut_tenant" ON "erp"."usuario_tenant" ("tenant_id" ASC);

CREATE INDEX "ix_usuario_tenant_idx_ut_usuario" ON "erp"."usuario_tenant" ("usuario_id" ASC);

CREATE UNIQUE INDEX "ix_usuario_tenant_uk_usuario_tenant" ON "erp"."usuario_tenant" ("usuario_id" ASC, "tenant_id" ASC);

CREATE INDEX "ix_veiculos_idx_veiculos_tenant" ON "erp"."veiculos" ("tenant_id" ASC);

CREATE INDEX "ix_visitantes_idx_visitantes_tenant" ON "erp"."visitantes" ("tenant_id" ASC);

CREATE INDEX "ix_visitantes_idx_visitantes_tenant_autoria" ON "erp"."visitantes" ("tenant_id" ASC, "cadastrado_por_tipo" ASC);
