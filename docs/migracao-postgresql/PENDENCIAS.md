# Pendências e artefatos necessários

> **Estado:** Gate 2 **aprovado por André em 2026-11-08 para fins de conversão**, com base no catálogo metadata-only da cópia privada restaurada do dump completo de 2026-11-07. Essa evidência substitui a coleta assistida pelo phpMyAdmin para a conversão, mas não afirma equivalência com o estado atual da produção após o snapshot nem aprova cutover/deploy. O pipeline v9 e a Fase D permanecem limitados ao ensaio privado.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit-base `f60739713e6de3f2473b79ecd14a70fd700e5462`.
>
> **Registro e retomada:** [Catálogo MySQL Fase 2.4](phase2_4/LIVE_MYSQL_CATALOG.md), [parecer do Gate 2](phase2_4/GATE_2_READINESS_REPORT.md), [pacote SQL incremental](phase2_4/MYSQL_LIVE_CATALOG_COLLECTION.sql) e [manual de coleta](phase2_4/INSTRUCOES_COLETA_MYSQL_LIVE.md).

## Bloqueadores P0

- Aprovar parâmetros PostgreSQL: versão, extensões, UTF-8, timezone, collation, TLS, scheduler e políticas de tipos.
- O catálogo do snapshot aprovado substitui a coleta incremental via phpMyAdmin para fins de conversão. Ainda faltam migration ledger do momento do dump, QA de semântica, agregados de reconciliação e evidência de alterações ocorridas após 2026-11-07; isso não reabre o Gate 2 para o ensaio, mas é obrigatório na janela de virada.
- O anexo schema-only `(7)` foi validado pelo parser fail-closed; o anexo `(6)` continua não catalogado. Em etapa posterior, André autorizou um dump completo de ensaio, restaurado somente na cópia isolada. Dados de negócio não são publicados em Git/relatórios; a cópia e os arquivos brutos permanecem privados e não são fixtures de teste.
- Os 270 objetos do snapshot do dump são `BASE TABLE`; 3.339 colunas, 11 FKs, 7 triggers, 2 procedures, 0 functions/views/events; 232 `AUTO_INCREMENT`, 30 tabelas sem PK. O catálogo aprovado é a base do ensaio, não evidência de que o banco atual permaneceu idêntico depois do dump.
- As 22 candidatas estáticas a views do export foram resolvidas como tabelas no snapshot restaurado. Ainda é necessária revisão semântica e de dependências no código para qualquer conversão de query/view.
- Revisar as 42 estruturas sem `tenant_id` semanticamente, sem adicionar a coluna automaticamente.
- A lista de 111 colunas `ON UPDATE CURRENT_TIMESTAMP` foi obtida do snapshot e versionada como allowlist exata. Ela difere de uma contagem estática anterior; preservar a lista e a proveniência do novo catálogo.
- Os sete triggers e duas procedures têm fingerprints metadata-only; completar revisão semântica, executar harness sintético e confirmar equivalência antes de aprovar comportamento no destino.
- Validar no pipeline o estado final das colunas, defaults, AUTO_INCREMENT, UNSIGNED, ENUM/SET, DECIMAL, DATETIME/TIMESTAMP/TIME, JSON, binários, índices e constraints contra o catálogo do snapshot.
- Fechar isolamento da Assembleia, incluindo pai/filho, anexos e autorização de migration.
- Fechar Console/ControlID: gate de dispositivo, vínculo explícito device → tenant → pessoa/veículo e rejeição de ambiguidades.
- Tornar RBAC e resolução de tenant fail-closed para operações sensíveis; retirar default operacional tenant 1.
- Definir RPO/RTO/SLO, janela, owner, critérios de abortar e autoridade de rollback.
- Acesso Hetzner e inventário read-only foram validados em etapa separada; não iniciar serviços públicos, ERP runtime ou cutover sem gates/documentação e autorização específicos.

## Pendências P1

- Matriz `path + método + ação` com request/response/status/auth/RBAC/tenant/idempotência e consumidor.
- Reconciliar Portal Documentos: tenant explícito, prepared statements, Bearer sem query string e autorização de stream/download.
- Modelo final do schema após replay controlado dos 489 `ALTER TABLE` e comparação com `SHOW CREATE` da instância.
- Classificação funcional das 42 tabelas sem `tenant_id` continua pendente. O export contém 22 nomes view-like (18 sem tenant_id e 4 com) e 24 outros nomes sem tenant_id; o nome do objeto não classifica semântica nem isolamento.
- Perfil agregado real: contagem, NULLs, duplicidades, órfãos, cobertura tenant, zero-date, enum, limites DECIMAL, IDs e tamanhos de TEXT/BLOB/JSON; executar por etapas após avaliar impacto.
- Manifesto de anexos: referência, tipo, contagem e tamanho agregados, tenant e política de retenção, sem baixar conteúdo ou publicar caminhos pessoais.
- Adapter/dialect PHP para PostgreSQL, prepared statements, SQLSTATE, transações, locks, JSON, identity, temporal e upsert (Fase D autorizada, branch própria, somente ensaio privado).
- Definições reais dos triggers e de qualquer view/procedure/function/event instalado; não inferir inexistência a partir do snapshot.
- Reconciliar resultados de `tools/phase2/catalog_schema_only.py` com a instância e revisar semanticamente/ordenadamente os 489 `ALTER`; a projeção atual é parcial e não executável.
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

Não coletar nem transferir dumps com linhas pessoais sem autorização explícita, escopo, isolamento, checksum e retenção definidos. O dump de ensaio autorizado é exceção privada de conversão, fica fora do Git e dos relatórios, não pode ser copiado para fixtures ou compartilhado; logs/relatórios contêm somente metadados, contagens e hashes agregados. Nunca publicar segredos, credenciais, cookies, chaves, arquivos `.env`, uploads completos ou dados não anonimizados.

## Critério de encerramento

Cada item deve ter owner, evidência, data, ambiente, checksum quando aplicável, decisão e referência no migration ledger. A aprovação de Gate 2 é limitada ao catálogo do snapshot para conversão; os critérios de dados, QA, cutover e mudanças pós-snapshot permanecem obrigatórios e não são resolvidos por ausência no Git.

## Referências

`AI-CONTEXT/DATABASE.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SECURITY.md`, `AI-CONTEXT/DEPENDENCIES.md`, `AI-CONTEXT/TESTING.md`, `AI-CONTEXT/DEPLOY.md`; `review_api.md`, `review_database.md`, `review_operations.md`, `plano_migracao_postgresql_erp.md` e `relatorio_divergencias_zip_hostgator_vs_git_main.md`.
