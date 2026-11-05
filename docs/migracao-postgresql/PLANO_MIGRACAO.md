# Plano de migração para PostgreSQL

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.
>
> **Destino:** PostgreSQL já definido. Não há autorização para iniciar conversão ou operação neste documento.

## Objetivo de aceite

Preservar funcionalidades, identidade, tenant, RBAC, contratos HTTP/JSON, uploads/downloads, relatórios, jobs, notificações e integrações relevantes, demonstrando equivalência em ambiente isolado. A migração não será considerada troca de driver: DDL, dados, triggers, consultas, transações, runtime e operação exigem adaptação e evidência.

## Fases e gates

### Fase 0 — congelamento e rastreabilidade

Fixar commit oficial, branch/worktree, escopo, exclusões, checksums de evidências e regra de que ZIP/HostGator é somente evidência. Registrar o baseline-manifest e manter o worktree sem alterações de código. **Preparação da Fase 1:** branch isolada e documentação versionada no histórico Git; nenhuma alteração de aplicação.

### Fase 1 — contratos e parâmetros

Aprovar versão/extensões PostgreSQL, encoding, timezone, collation, TLS, driver, identity, enum/check, JSON, temporal, `UNSIGNED`, zero-date, índices, scheduler, backup e observabilidade. Fechar matriz API/auth/tenant/RBAC e runtime proposto. **Gate 1:** aprovação escrita, sem pendências P0 abertas.

### Fase 2 — evidência instalada e qualidade

O catálogo offline atual lê somente schema-only fornecido e classifica statements, tabelas/colunas, tipos candidatos e suboperações; os 489 `ALTER TABLE` ficam explicitamente não reproduzidos, logo não há modelo final. A coleta read-only real obteve somente metadados de servidor e contou 270 objetos na listagem do phpMyAdmin, ainda sem classificação por tipo/DDL; seu status e os bloqueios da exportação estão em [COLETA_INSTANCIA.md](COLETA_INSTANCIA.md). Obter os exports da instância em [FASE2_IMPLEMENTACAO.md](FASE2_IMPLEMENTACAO.md), reconciliar `SHOW CREATE`, views/rotinas/triggers e ledger, classificar tabelas sem tenant, agregar qualidade sem valores pessoais e obter manifesto de anexos. **Gate 2 continua fechado** até cruzamento contra a instância e decisão de todos os blockers, sem inferir instalação a partir do Git/schema-only. A evidência, owners, aprovadores e critérios de abort/rollback dos Gates 2–5 estão na [matriz de gates](FASE2_IMPLEMENTACAO.md#41-matriz-de-gates-para-avancar-de-fase).

### Fase 3 — conversão descartável

Construir schema PostgreSQL em ambiente isolado, adapter/dialect, migrations executáveis em branch própria e conversores de dados. Converter triggers/rotinas apenas com definições reais. Testar constraints, transações, consultas, idempotência, performance e isolamento. **Gate 3:** QA técnico e segurança aprovados.

### Fase 4 — ensaios de carga e operação

Carga anonimizada ou autorizada, reconciliação por contagens/agregados, restore drill, PITR/WAL, rollback rehearsal antes e depois de reabrir escrita, validação de jobs, storage, referências e integrações. **Gate 4:** T01–T22 aplicáveis, RPO/RTO/SLO medidos, defeitos críticos encerrados.

### Fase 5 — cutover autorizado

Somente após janela aprovada, critérios de abortar, acesso operacional validado e owner responsável: congelar writes/jobs/filas; executar backup; migrar; validar smoke e reconciliação; abrir tráfego progressivamente; monitorar; registrar decisão. **Gate 5:** autorização formal. Não atingido.

## Preservação funcional mínima

Cobrir painel, RH, financeiro, manutenção, segurança/acesso, moradores/dependentes/veículos, visitantes/QR/ControlID, água, assembleia, GED/documentos, Chat-In/Eventos/Canais, ordens de serviço, portal, PWA/FCM, relatórios, uploads/PDF, notificações e jobs. Módulos Git-only devem ser alinhados em API, helper, frontend, schema e configuração.

## Artefatos exatos necessários antes de execução

- baseline-manifest com commit, escopo, checksums e exclusões;
- decisão assinada dos parâmetros PostgreSQL;
- catálogo `path + método + ação` e matriz auth/tenant/RBAC;
- manifesto de runtime e dependências reproduzíveis;
- `server_metadata.txt`, `tables_ddl.sql`, `constraints_indexes.csv`, `objects_ddl.sql`, `objects_inventory.csv`, `migration_ledger.csv`;
- modelo final de tabelas/índices/constraints e crosswalk;
- `data_quality_aggregates.csv`, `tenant_profile.csv` e `attachment_manifest.csv` metadata-only;
- adapter/dialect, migrations PostgreSQL e conversores versionados;
- evidências T01–T22, reconciliação, backup/restore/PITR/WAL e rollback rehearsal;
- plano operacional, RPO/RTO/SLO, janela, abort criteria e validação de acesso ao host.

## Restrições explícitas

Não tentar SSH Hetzner enquanto fingerprint, usuário, chave, sudo e recuperação não forem validados por canal confiável. Não alterar VM, firewall, DNS, runtime, banco ou produção. Não promover ZIP, uploads, logs, dump ou configuração externa. Não incluir dados pessoais, credenciais ou tokens nos artefatos.

## Referências

`AI-CONTEXT/TESTING.md`, `AI-CONTEXT/DEPLOY.md`, `AI-CONTEXT/DATABASE.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SECURITY.md`; evidências externas `review_operations.md`, `review_database.md`, `plano_migracao_postgresql_erp.md` e `relatorio_divergencias_zip_hostgator_vs_git_main.md`, identificadas no [baseline-manifest.json](baseline-manifest.json) e não incluídas nesta branch.
