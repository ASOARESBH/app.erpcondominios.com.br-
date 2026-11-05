# Pendências e artefatos necessários

> **Estado:** Gate 2 parcial. Foi executado somente um `SELECT` de metadados do servidor por operador no phpMyAdmin; não houve escrita, leitura de linhas de aplicação, importação, deploy, tentativa de SSH ou alteração de runtime/código.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.

## Bloqueadores P0

- Aprovar parâmetros PostgreSQL: versão, extensões, UTF-8, timezone, collation, TLS, scheduler e políticas de tipos.
- Completar os artefatos reais `tables_ddl.sql`, `tables_inventory.csv`, `constraints_indexes.csv`, `objects_ddl.sql`, `objects_inventory.csv`, `migration_ledger.csv`, `data_quality_aggregates.csv`, `tenant_profile.csv`, `attachment_manifest.csv` e `MYSQL_POSTGRESQL_CROSSWALK.csv`; cada um deve ter origem, data/hora, checksum, ambiente, responsável e retenção. `server_metadata.txt` foi produzido parcialmente, com resultado informado pelo usuário. O arquivo `tools/phase2/mysql_metadata_inventory.sql` continua sem execução como lote; DDL completo exige `SHOW CREATE`.
- Validar a exportação personalizada do phpMyAdmin antes de baixar: todos os checkboxes `table_data[]` devem estar desmarcados e `table_structure[]` marcados; incluir views, triggers, procedures/functions/events conforme disponibilidade. A exportação não foi iniciada porque o estado dinâmico completo dos checkboxes não pôde ser confirmado com segurança.
- Reconciliar os 270 objetos listados no phpMyAdmin com `TABLE_TYPE` e DDL real; as contagens 270 CREATE/489 ALTER/7 triggers/228 tenant/42 sem tenant pertencem ao schema-only anterior, não à instância atual.
- Fechar isolamento da Assembleia, incluindo pai/filho, anexos e autorização da ação migration.
- Fechar Console/ControlID: gate de dispositivo, vínculo explícito device → tenant → pessoa/veículo e rejeição de ambiguidades.
- Tornar RBAC e resolução de tenant fail closed para operações sensíveis; retirar default operacional tenant 1.
- Definir RPO/RTO/SLO, janela, owner, critérios de abortar e autoridade de rollback.
- Validar acesso operacional futuro somente após fingerprint, usuário não-root, chave, sudo e recuperação serem aprovados por canal confiável; não tentar SSH agora.

## Pendências P1

- Matriz `path + método + ação` com request/response/status/auth/RBAC/tenant/idempotência e consumidor.
- Reconciliar Portal Documentos: tenant explícito, prepared statements, Bearer sem query string e autorização de stream/download.
- Modelo final do schema após replay dos 489 `ALTER TABLE` e comparação com `SHOW CREATE` da instância.
- Classificação funcional das 42 tabelas sem `tenant_id` continua pendente: o DDL anterior tem 22 nomes view-like no total (18 sem `tenant_id` e 4 com), além de 24 outros nomes sem `tenant_id`. Nome de objeto não classifica semântica nem isolamento.
- Perfil agregado real: contagem, NULLs, duplicidades, órfãos, tenant coverage, zero-date, enum, limites DECIMAL, IDs e tamanhos de TEXT/BLOB/JSON; executar em etapas após avaliar impacto.
- Manifesto de anexos: referência, tipo, contagem e tamanho agregados, tenant e política de retenção, sem baixar conteúdo ou publicar caminhos pessoais.
- Adapter/dialect PHP para PostgreSQL, prepared statements, SQLSTATE, transações, locks, JSON, identity, temporal e upsert.
- Definições reais dos triggers e de qualquer view/procedure/function/event instalado; não inferir inexistência a partir do snapshot antigo.
- Reconciliar os resultados do catalogador `tools/phase2/catalog_schema_only.py` com a instância e revisar os 489 `ALTER` semântica/ordenadamente; projeção atual é parcial, não executável.
- Manifesto de runtime: PHP/SAPI/extensões, driver, web server/PHP-FPM, Node/Chrome para QA, scheduler, timezone, limites e observabilidade.
- Correção planejada do QA Node: Node mínimo, comando de execução e arquivo `qa-puppeteer-auth-header.js` duplicado.
- Catálogo CDN, versões de FontAwesome/Chart.js/html5-qrcode e política de fallback.
- Backup custom/globals, base backup, WAL/PITR, checksum, retenção, imutabilidade e restore drill.

## Pendências P2

- Padronização gradual dos envelopes legados.
- Centralização de CORS e remoção de wildcard em APIs web com credenciais.
- Auditoria dos candidatos SQL com `query()`/interpolação, sem usar quantidade de `prepare` como proxy de segurança.
- Testes de performance/EXPLAIN, concorrência, jobs e health checks.
- Revalidação de bibliotecas PDF/e-mail e licenças.

## O que não deve ser solicitado ou publicado

Não coletar dumps com linhas pessoais, uploads completos, logs com tokens, configuração com segredos, credenciais, cookies, chaves, arquivos `.env` ou dados não anonimizados. Solicitar metadata-only e agregados controlados, com canal autorizado, checksum e retenção definida. DDL bruto e detalhes operacionais da origem permanecem no pacote privado, nunca no GitHub.

## Critério de encerramento

Cada item deve ter owner, evidência, data, ambiente, checksum quando aplicável, decisão e referência no migration ledger. Pendência “não observada” não deve ser marcada como resolvida por ausência no Git ou schema-only. Gate 2 permanece fechado até a reconciliação dos objetos e dependências descritos em `COLETA_INSTANCIA.md`.

## Referências

`AI-CONTEXT/DATABASE.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SECURITY.md`, `AI-CONTEXT/DEPENDENCIES.md`, `AI-CONTEXT/TESTING.md`, `AI-CONTEXT/DEPLOY.md`; `review_api.md`, `review_database.md`, `review_operations.md`, `plano_migracao_postgresql_erp.md` e `relatorio_divergencias_zip_hostgator_vs_git_main.md`.
