# H3 — Reconciliação live do HML Hetzner

**Data:** 12/11/2026
**Repositório:** `ASOARESBH/app.erpcondominios.com.br-`
**Branch:** `infra/hetzner-go-live`
**Base de código:** `origin/main` em `f60739713e6de3f2473b79ecd14a70fd700e5462`
**Estado:** `H2_ACCEPTED / H3_PARTIAL / H4_IP_NOT_STARTED`

## H2 — evidência aceita

A restauração do dump HML de 07/11 foi repetida depois da correção permanente autorizada de `log_bin_trust_function_creators=ON`. H2 foi aceita com **270 tabelas, 202.609 registros reconciliados em 270/270 tabelas, 7 triggers, 2 procedures, zero erros de importação e zero definers incorretos**. Evidências e logs completos são root-only em `/var/log/erpcondominios/h2-restore-accepted-20261112/`; nenhum dado de negócio ou segredo foi incluído neste relatório. A cópia HML continua com integrações externas neutralizadas.

Antes do DDL H3, foi criado um backup lógico root-only da cópia HML em `/var/backups/erpcondominios/h3-pre-ddl-20261112T200850Z/`. O dump original e o backup permanecem preservados.

## H3 — execução estrutural aprovada

Foi versionado e aplicado `sql/deploy/hetzner_cutover_migrations.sql`, compatível com Oracle MySQL 8.4 por guards `INFORMATION_SCHEMA` e SQL dinâmico preparado. O script contém somente DDL estrutural, sem INSERT/UPDATE/DELETE, DROP, TRUNCATE ou valores de integração.

| Conjunto aplicado | Estado anterior | Resultado pós-DDL |
|---|---|---|
| `alertas_acesso` e quatro tabelas relacionadas | 5 tabelas ausentes | 5/5 tabelas presentes, novas e vazias |
| `abastecimento_lancamentos.observacao` | Coluna ausente; âncora estrutural existente | `TEXT NULL` presente |
| `leituras` e `leituras_fotos` | Dois `client_uuid` e dois índices únicos ausentes | 2/2 colunas `VARCHAR(64) NULL` e 2/2 índices únicos `(tenant_id, client_uuid)` presentes |
| `os_chamados` | Dois índices ausentes; colunas-alvo existentes | `idx_origem_portal` e `idx_morador_id` presentes |

A validação estática do artefato confirmou cinco `CREATE TABLE IF NOT EXISTS`, sete blocos de DDL preparado, nenhum DML/DROP/TRUNCATE/procedure temporária e nenhuma sintaxe MariaDB `ALTER ... ADD ... IF NOT EXISTS`.

### Reconciliação após DDL

O comparador leu apenas `COUNT(*)` e nomes de tabelas. Resultado: **270/270 tabelas originais preservadas, 202.609/202.609 linhas preservadas, zero tabelas-base removidas, zero diferenças de contagem e cinco tabelas novas com zero linhas**. Catálogo estrutural privado pós-DDL: 275 tabelas, 3.385 colunas, 808 entradas de índices, 331 constraints e zero views. Catálogos privados permanecem em `/home/ubuntu/reports/private/` e não devem ser publicados.

## Matriz de estado por arquivo

`JÁ_APLICADA` significa que os objetos estruturais verificados já existem no HML; não afirma que um seed ou backfill de uma migration `MIXED` foi executado. `PARCIAL` significa que faltam objetos estruturais ou que o arquivo contém partes ainda pendentes. `AUSENTE` significa que a estrutura-alvo não existe. `BLOQUEADA` significa que a migration está desativada/protegida. Nenhuma migration de dados foi executada.

| Migration | Tipo estático | Estado live estrutural | Observação count-only / gate |
|---|---|---|---|
| `migration_alertas_acesso_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | 5 tabelas e seus objetos aprovados presentes; zero linhas novas. |
| `migration_alertas_unidade_mysql57.sql` | Mista | JÁ_APLICADA | Seeds: 2/2 permissões, 11/11 políticas, 2/2 grants de compatibilidade, 1/1 configuração push; 0 sessões portal a preencher. A migration-base não foi chamada; suas estruturas/seeds verificados já estão presentes. |
| `migration_aplicativos_versionamento_mysql57.sql` | Mista | JÁ_APLICADA | Chave natural do catálogo: 1/1 presente. |
| `migration_arquivos_tenant_mysql57.sql` | Mista | JÁ_APLICADA | Estruturas verificadas presentes; não foi executado DML. |
| `migration_assembleia_tenant_mysql57.sql` | Mista | JÁ_APLICADA | Estrutura `tenant_id`/índice presente; 0 assembleias nulas/zero e 0 linhas candidatas nos três UPDATEs. Backfill não executado. |
| `migration_assembleia_v1.sql` | Mista | PARCIAL | Catálogo de módulo: 0/2 chaves naturais presentes; seed requer autorização. |
| `migration_autoria_visitantes_mysql57.sql` | Mista | JÁ_APLICADA | Estrutura presente; o UPDATE de autoria teria efeito sobre 33 visitantes; não executado. |
| `migration_chatin_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Objetos estruturais verificados presentes. |
| `migration_config_cadastro_moradores_mysql57.sql` | Mista | JÁ_APLICADA | Seeds por chave natural: 16/16 campos e 1/1 opção presentes. |
| `migration_config_cadastro_visitantes_mysql57.sql` | Mista | JÁ_APLICADA | Seeds por chave natural: 10/10 campos presentes. |
| `migration_consolidar_erpserra_para_erpcondor_mysql57.sql` | Revisão/bloqueada | BLOQUEADA | Desativada; nunca executar. |
| `migration_contas_bancarias_v1.sql` | Mista | PARCIAL | View candidata `vw_saldo_contas` ausente. Seed do módulo bancário 1/1 presente. |
| `migration_contas_bancarias_v2.sql` | Mista | PARCIAL | Quatro views financeiras candidatas ausentes (`vw_dashboard_financeiro`, `vw_pendencias_conciliacao`, `vw_fluxo_caixa`, `vw_extrato_bancario`); seeds de módulos 4/4 presentes. View de saldo também ausente; total de views distintas esperadas por v1/v2: 5, presentes: 0. |
| `migration_controlid_v2.sql` | Idempotente estrutural com DROP | PARCIAL / NÃO APLICADA | Bloqueada por quatro `DROP TABLE`; contagens exatas nos quatro alvos: `dispositivos_controlid_leituras` 0, `dispositivos_controlid_sync_log` 18, `dispositivos_controlid` 1, `dispositivos_seguranca` 0. Não remover. |
| `migration_convidados_pre_cadastro_morador_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Objetos estruturais verificados presentes. |
| `migration_dashboard_personalizavel_mysql57.sql` | Mista | JÁ_APLICADA | Seed do catálogo: 16/16 chaves naturais presentes. |
| `migration_dependentes_add_campos.sql` | Idempotente estrutural | JÁ_APLICADA | Colunas estruturais presentes. |
| `migration_hidrometro_inventario.sql` | Não idempotente | PARCIAL | Um objeto estrutural ausente; não executada. |
| `migration_i18n_pt_en_es_mysql57.sql` | Não idempotente | JÁ_APLICADA | Objetos estruturais verificados presentes; não executar novamente. |
| `migration_inadimplencia_comparacoes_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Objetos estruturais presentes. |
| `migration_inadimplencia_mysql57.sql` | Mista | JÁ_APLICADA | Seed do módulo: 1/1 presente. |
| `migration_layout_administradora_mysql57.sql` | Mista | JÁ_APLICADA | 4/4 administradoras e 10/10 layouts presentes pelas chaves naturais. |
| `migration_leiturista_hidrometros_mobile.sql` | Idempotente estrutural | JÁ_APLICADA | Aplicada no delta H3; 2 colunas e 2 índices únicos confirmados. |
| `migration_lista_convidados_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Objetos estruturais verificados presentes. |
| `migration_monitoramento_hub_multimodal_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Objetos estruturais verificados presentes. |
| `migration_monitoramento_lpr_mysql57.sql` | Mista | JÁ_APLICADA | Configuração por tenant sem lacunas; contagem de tenants=1 e nenhuma configuração ausente. |
| `migration_monitoring_preautorizacao_mysql57.sql` | Mista | AUSENTE | Dois objetos estruturais ausentes; não executada. Seed de configuração não teria linhas novas no estado atual (0 tenants ativos sem config). |
| `migration_monitoring_versionamento_mysql57.sql` | Mista | JÁ_APLICADA | A chave natural da release inicial está presente: 1/1. |
| `migration_moradores_anexos_multitenant_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Objetos estruturais verificados presentes. |
| `migration_multitenant_fase1.sql` | Mista | PARCIAL | 134 alvos `tenant_id` do arquivo já têm a coluna; HML contém 1 empresa e 1 tenant, e não faltam vínculos `usuario_tenant` para os usuários atuais (0). Nenhum DML executado. |
| `migration_multitenant_fase1_v2.sql` | Mista | PARCIAL | Objetos-alvo estruturais presentes; 1/1 chaves selecionadas de usuário/vínculo encontradas. Não executada; possível atualização de tenant exige revisão. |
| `migration_multitenant_fase1_v3.sql` | Mista | PARCIAL | Objetos-alvo estruturais presentes; DELETE condicionado da tabela `usuarios` atingiria 0 linhas. Nenhum DML executado. |
| `migration_notificacoes_encomendas_mobile.sql` | Mista | PARCIAL | Chave natural da configuração push de encomenda: 1/1 presente; estrutura ainda parcial. Nenhum upsert executado. |
| `migration_notificacoes_registros_acesso.sql` | Idempotente estrutural | JÁ_APLICADA | Objetos estruturais de notificações/registros já presentes. |
| `migration_notificacoes_veiculos_acessos_v2.sql` | Idempotente estrutural | PARCIAL | `protocolo_id` continua `NOT NULL`; há 643 notificações e 0 duplicidades na chave candidata. Não executar a alteração de nulabilidade/índice sem decisão adicional. |
| `migration_notificacoes_veiculos_controle_acesso.sql` | Mista | PARCIAL | Chave push para veículos: 0/1 presente para 1 tenant. Sem seed ou DDL adicional aplicado. |
| `migration_observacao_abastecimento_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Aplicada no delta H3; `observacao TEXT NULL` confirmado. |
| `migration_os_indice_unidade_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Índice estrutural já presente. |
| `migration_os_portal_morador.sql` | Idempotente estrutural | JÁ_APLICADA | Aplicados os dois índices ausentes; colunas-alvo já existiam. |
| `migration_portal_colaborador_mobile.sql` | Mista | JÁ_APLICADA | 1 sessão expirada ainda ativa seria desativada pelo UPDATE; não executado. |
| `migration_projetos_slug_tenant_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Coluna/índice estrutural presentes. |
| `migration_pwa_fcm.sql` | Mista | PARCIAL | As 12/12 chaves de configuração FCM verificadas estão presentes; 11 objetos estruturais ainda faltam. |
| `migration_pwa_v2.sql` | Mista | PARCIAL | Seed pwa_versao 0/1; configurações principais 9/10; módulo PWA 0/1; uma chave configuracional adicional 1/1. O UPDATE de descrição teria efeito em 1 linha. Nada foi alterado. |
| `migration_rbac_multitenant_mysql57.sql` | Mista | JÁ_APLICADA | Catálogos de módulos (59 verificações/59) e permissões (12 ações, nenhuma lacuna) presentes; grupos compatíveis 4/4. Count-only identificou até 3 grants de grupo e 1 associação de usuário ausentes; permissões individuais legadas candidatas ausentes: 0. Nenhum seed/grant aplicado. |
| `migration_recuperacao_senha_segura_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Estrutura presente. |
| `migration_rh_banco_horas_mapeamento_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | 53/53 objetos catalogados presentes; procedures/alterações não foram reexecutadas. |
| `migration_rh_banco_horas_saldo_negativo_mysql57.sql` | Mista | JÁ_APLICADA | O UPDATE de alinhamento de tenant atingiria 0 registros; não executado. |
| `migration_rh_escala_v2.sql` | Mista | JÁ_APLICADA | Os dois UPDATEs de backfill atingiriam 10 e 3 registros, respectivamente; não executados. |
| `migration_rh_mysql57.sql` | Não idempotente | JÁ_APLICADA | Objetos estruturais verificados presentes; não executar novamente. |
| `migration_rondas_vigilante_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Objetos estruturais verificados presentes. |
| `migration_sessao_inativa_v3.1.sql` | Mista | JÁ_APLICADA | Três chaves globais verificadas: 1/1 em cada chave natural. |
| `migration_sessao_personalizada.sql` | Mista | PARCIAL | 3/4 chaves de configuração presentes; backfill de atividade atingiria 20 sessões. Não executado. |
| `migration_veiculos_paginacao_mysql57.sql` | Idempotente estrutural | JÁ_APLICADA | Coluna estrutural presente. |
| `migration_visitantes_v2.sql` | Não idempotente | PARCIAL | Dois objetos estruturais ausentes; não executada. |

## Efeitos de dados ainda pendentes de autorização

As contagens abaixo são avaliações read-only de predicados/keys naturais das migrations, não alterações realizadas. Valores das linhas não foram selecionados nem exibidos.

| Migration / alvo | Efeito numérico possível | Estado |
|---|---:|---|
| `migration_autoria_visitantes_mysql57.sql` — `visitantes` | 33 updates | Não aplicado; requer autorização |
| `migration_portal_colaborador_mobile.sql` — `sessoes_colaborador_mobile` | 1 update | Não aplicado; requer autorização |
| `migration_pwa_v2.sql` — `pwa_configuracoes` | 1 update | Não aplicado; requer autorização |
| `migration_rh_escala_v2.sql` — `rh_escala` | 10 + 3 updates | Não aplicado; requer autorização |
| `migration_sessao_personalizada.sql` — `sessoes_portal` | 20 updates | Não aplicado; requer autorização |
| `migration_rh_banco_horas_saldo_negativo_mysql57.sql` — `rh_banco_horas` | 0 updates | Sem efeito atual, mas migration mista; não aplicada |
| `migration_assembleia_tenant_mysql57.sql` — `assembleias` | 0 updates nos três predicados; nulos/zero=0 | Backfill omitido; sem alteração necessária |
| `migration_alertas_unidade_mysql57.sql` — `sessoes_portal` | 0 updates | Sem efeito atual; não executada |
| `migration_multitenant_fase1_v3.sql` — `usuarios` | 0 deletes | DELETE protegido não executado |
| `migration_multitenant_fase1_v2/v3.sql` — `tenants` | 1 empresa e 1 tenant existentes; upsert poderia regravar o slug | Não executado; revisão de impacto pendente |
| `migration_rbac_multitenant_mysql57.sql` — grants | Até 3 grants de grupo e 1 vínculo usuário-grupo ausentes; 0 permissões individuais faltantes | Não aplicados; requer autorização |
| `migration_assembleia_v1.sql` — `modulos_sistema` | 0/2 seeds de módulos presentes | Seed DML pendente de autorização |
| `migration_pwa_v2.sql` — `pwa_versao`, configurações e `modulos_sistema` | Seeds ausentes: 1 + 1 + 1 chaves; uma das 10 configurações principais também está ausente | Seeds DML pendentes de autorização |
| `migration_notificacoes_veiculos_controle_acesso.sql` — `pwa_configuracoes` | 0/1 chave push para o tenant atual | Seed DML pendente de autorização |
| `migration_notificacoes_encomendas_mobile.sql` — `pwa_configuracoes` | 1/1 chave já presente; upsert ainda regravaria valor/descrição | Não executado; requer autorização |
| `migration_layout_administradora_mysql57.sql` — catálogo | 4/4 administradoras e 10/10 layouts presentes | Sem seed faltante |
| `migration_config_cadastro_moradores_mysql57.sql` — configurações | 16/16 campos e 1/1 opção presentes | Sem seed faltante |
| `migration_config_cadastro_visitantes_mysql57.sql` — configurações | 10/10 campos presentes | Sem seed faltante |
| `migration_dashboard_personalizavel_mysql57.sql` — catálogo | 16/16 widgets presentes | Sem seed faltante |
| `migration_pwa_fcm.sql` — configurações | 12/12 chaves presentes | Sem seed faltante |
| `migration_monitoring_versionamento_mysql57.sql` — releases | 1/1 chave de release inicial presente | Sem seed faltante |
| `migration_controlid_v2.sql` — quatro tabelas DROP | 0 + 18 + 1 + 0 = 19 linhas; uma tabela ainda contém 18 registros | Destrutivo, bloqueado até decisão expressa |

Migrations mistas/não idempotentes e seeds ausentes continuam pendentes. Não foi executada qualquer DML; contagens 0 não são autorização para executar o arquivo completo.

## Gates e próximo passo

- H3: **parcial** — DDL autorizado aplicado e reconciliado; decisões de DML, dados/seed e objetos de migrations mistas seguem pendentes.
- Assembleias: `tenant_id` existe, há 0 registros sem tenant e o índice estrutural já existia; migration de backfill não executada.
- Notificações: v2 não aplicada; `protocolo_id` permanece `NOT NULL`, 643 linhas existentes, duplicidade por chave candidata=0.
- ControlID: não executar a migration inteira por causa dos quatro DROP; 19 linhas potencialmente removidas.
- PostgreSQL segue pausado e seus branches, bancos de ensaio e dumps históricos permanecem preservados.
- H4 deve partir de `origin/main` e funcionar primeiro no IP `2.29.161.106`. Não alterar DNS/HostGator; aguardar os ZIPs de `uploads/` e `config/firebase/` antes de importar esses arquivos. Nenhum merge em `main` está autorizado.

As evidências detalhadas, backup pré-DDL, logs completos e metadados live permanecem privados/root-only. Este documento não contém linhas de negócio, credenciais, hashes de dumps nem valores de configuração.
