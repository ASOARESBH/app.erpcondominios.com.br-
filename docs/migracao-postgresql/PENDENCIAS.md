# Pendências e artefatos necessários

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.

## Bloqueadores P0

- Aprovar parâmetros PostgreSQL: versão, extensões, UTF-8, timezone, collation, TLS, scheduler e políticas de tipos.
- Obter por canal autorizado os arquivos exatos `server_metadata.txt`, `tables_ddl.sql`, `constraints_indexes.csv`, `objects_ddl.sql`, `objects_inventory.csv`, `migration_ledger.csv`, `data_quality_aggregates.csv`, `tenant_profile.csv` e `attachment_manifest.csv`, com checksum, ambiente, data, responsável e retenção. O checklist `tools/phase2/mysql_metadata_inventory.sql` é somente SELECT e ainda não foi executado; DDL de cada objeto exige extração `SHOW CREATE` separada.
- Fechar isolamento da Assembleia, incluindo pai/filho, anexos e autorização da ação migration.
- Fechar Console/ControlID: gate de dispositivo, vínculo explícito device → tenant → pessoa/veículo e rejeição de ambiguidades.
- Tornar RBAC e resolução de tenant fail closed para operações sensíveis; retirar default operacional tenant 1.
- Definir RPO/RTO/SLO, janela, owner, critérios de abortar e autoridade de rollback.
- Validar acesso operacional futuro somente após fingerprint, usuário não-root, chave, sudo e recuperação serem aprovados por canal confiável; não tentar SSH agora.

## Pendências P1

- Matriz `path + método + ação` com request/response/status/auth/RBAC/tenant/idempotência e consumidor.
- Reconciliar Portal Documentos: tenant explícito, prepared statements, Bearer sem query string e autorização de stream/download.
- Modelo final do schema após replay dos 489 `ALTER TABLE`.
- Classificação funcional das 42 tabelas sem `tenant_id` continua pendente: o DDL tem 22 nomes view-like no total (18 sem `tenant_id` e 4 com), além de 24 outros nomes sem `tenant_id`. O nome não classifica semântica nem isolamento.
- Perfil agregado de dados: contagem, NULLs, duplicidades, órfãos, tenant coverage, zero-date, enum, limites DECIMAL, IDs e tamanhos de TEXT/BLOB/JSON.
- Manifesto de anexos: arquivo, hash, tamanho, referência, tenant e política de retenção, sem publicar conteúdo pessoal.
- Adapter/dialect PHP para PostgreSQL, prepared statements, SQLSTATE, transações, locks, JSON, identity, temporal e upsert.
- Definições reais dos sete triggers e de qualquer view/procedure/function/event instalado.
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

Não coletar dumps com linhas pessoais, uploads completos, logs com tokens, configuração com segredos, credenciais, cookies, chaves, arquivos `.env` ou dados não anonimizados. Solicitar metadata-only e agregados controlados, com canal autorizado, checksum e retenção definida.

## Critério de encerramento

Cada item deve ter owner, evidência, data, ambiente, checksum quando aplicável, decisão e referência no migration ledger. Pendência “não observada” não deve ser marcada como resolvida por ausência no Git ou schema-only.

## Referências

`AI-CONTEXT/DATABASE.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SECURITY.md`, `AI-CONTEXT/DEPENDENCIES.md`, `AI-CONTEXT/TESTING.md`, `AI-CONTEXT/DEPLOY.md`; `review_api.md`, `review_database.md`, `review_operations.md`, `plano_migracao_postgresql_erp.md` e `relatorio_divergencias_zip_hostgator_vs_git_main.md`.
