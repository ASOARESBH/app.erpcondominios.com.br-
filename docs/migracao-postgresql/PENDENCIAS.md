# Pendências e artefatos necessários

> **Estado:** Gate 2 fechado/não aprovado. A Fase 2.3 foi publicada no repositório autorizado. Na tentativa da Fase 2.4, o phpMyAdmin mostrou o schema e o alerta “Está faltando valores no formulário”; a interação posterior expirou sem retorno de resultados. Nenhum conjunto live foi confirmado. Não houve alteração de dados, importação, deploy, SSH, PostgreSQL ou runtime.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit-base `f60739713e6de3f2473b79ecd14a70fd700e5462`.
>
> **Registro da tentativa:** [Catálogo MySQL Fase 2.4](phase2_4/LIVE_MYSQL_CATALOG.md) e [parecer do Gate 2](phase2_4/GATE_2_READINESS_REPORT.md).

## Bloqueadores P0

- Aprovar parâmetros PostgreSQL: versão, extensões, UTF-8, timezone, collation, TLS, scheduler e políticas de tipos.
- Obter e reconciliar evidências live de `INFORMATION_SCHEMA` e `SHOW CREATE` para tables, columns, indexes, objects, migration ledger, qualidade agregada, tenant profile e manifesto de anexos. Cada artefato deve ter origem, data/hora, checksum, ambiente, responsável e retenção. `server_metadata.txt` continua parcial e inclui valores informados pelo operador. O checklist `tools/phase2/mysql_metadata_inventory.sql` não foi executado como lote.
- O anexo `(7)` foi validado pelo parser fail-closed: schema-only com 270 `CREATE TABLE`, 489 `ALTER TABLE`, 7 triggers, 11 FKs explícitas e zero DML top-level. O anexo `(6)` anterior permanece rejeitado. Nenhuma linha de negócio foi lida ou exibida.
- Reconciliar os 270 `CREATE TABLE` e os 489 `ALTER` do export com `TABLE_TYPE` e `SHOW CREATE` reais. Eventos `not_replayed` não demonstram estado instalado. A consulta agregada à origem não gerou resultado confirmado; na retomada da Fase 2.4, o formulário do phpMyAdmin apontou valores ausentes e a tentativa de interação expirou. Não declarar zero divergências nem repetir tentativas pesadas.
- Obter `SHOW CREATE` por tipo de objeto e comprovar visibilidade/privilégios. O export tem sete triggers e não contém `CREATE VIEW`/procedures/functions/events; a ausência no export não prova ausência live.
- Revisar os 22 candidatos a views, confirmar tipo e definições live, mapear dependências reais e testar consumo/escopo tenant em etapa posterior.
- Revisar as 42 estruturas sem `tenant_id` após confirmação live, preservando as propostas da Fase 2.3 como hipóteses; não adicionar `tenant_id` automaticamente.
- Confirmar os sete triggers, eventos/timing, ordem, definição e dependências; não converter para PL/pgSQL ainda.
- Confirmar estado final das colunas após 232 `MODIFY`, inclusive AUTO_INCREMENT, UNSIGNED, ENUM, DECIMAL, DATETIME/TIMESTAMP, `ON UPDATE CURRENT_TIMESTAMP`, JSON, TEXT/LONGTEXT/LONGBLOB, TINYINT(1), chaves compostas e defaults.
- Fechar isolamento da Assembleia, incluindo pai/filho, anexos e autorização de migration.
- Fechar Console/ControlID: gate de dispositivo, vínculo explícito device → tenant → pessoa/veículo e rejeição de ambiguidades.
- Tornar RBAC e resolução de tenant fail-closed para operações sensíveis; retirar default operacional tenant 1.
- Definir RPO/RTO/SLO, janela, owner, critérios de abortar e autoridade de rollback.
- Validar acesso operacional futuro somente após fingerprint, usuário não-root, chave, sudo e recuperação serem aprovados por canal confiável; não tentar SSH agora.

## Pendências P1

- Matriz `path + método + ação` com request/response/status/auth/RBAC/tenant/idempotência e consumidor.
- Reconciliar Portal Documentos: tenant explícito, prepared statements, Bearer sem query string e autorização de stream/download.
- Modelo final do schema após replay controlado dos 489 `ALTER TABLE` e comparação com `SHOW CREATE` da instância.
- Classificação funcional das 42 tabelas sem `tenant_id` continua pendente. O export contém 22 nomes view-like (18 sem tenant_id e 4 com) e 24 outros nomes sem tenant_id; o nome do objeto não classifica semântica nem isolamento.
- Perfil agregado real: contagem, NULLs, duplicidades, órfãos, cobertura tenant, zero-date, enum, limites DECIMAL, IDs e tamanhos de TEXT/BLOB/JSON; executar por etapas após avaliar impacto.
- Manifesto de anexos: referência, tipo, contagem e tamanho agregados, tenant e política de retenção, sem baixar conteúdo ou publicar caminhos pessoais.
- Adapter/dialect PHP para PostgreSQL, prepared statements, SQLSTATE, transações, locks, JSON, identity, temporal e upsert.
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

Não coletar dumps com linhas pessoais, uploads completos, logs com tokens, configuração com segredos, credenciais, cookies, chaves, arquivos `.env` ou dados não anonimizados. Solicitar metadata-only e agregados controlados, com canal autorizado, checksum e retenção definida. DDL bruto e detalhes operacionais da origem permanecem no pacote privado, nunca no GitHub.

## Critério de encerramento

Cada item deve ter owner, evidência, data, ambiente, checksum quando aplicável, decisão e referência no migration ledger. Pendência “não observada” não deve ser marcada como resolvida por ausência no Git ou schema-only. Gate 2 permanece fechado até a reconciliação live descrita no [relatório Fase 2.4](phase2_4/GATE_2_READINESS_REPORT.md).

## Referências

`AI-CONTEXT/DATABASE.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SECURITY.md`, `AI-CONTEXT/DEPENDENCIES.md`, `AI-CONTEXT/TESTING.md`, `AI-CONTEXT/DEPLOY.md`; `review_api.md`, `review_database.md`, `review_operations.md`, `plano_migracao_postgresql_erp.md` e `relatorio_divergencias_zip_hostgator_vs_git_main.md`.
