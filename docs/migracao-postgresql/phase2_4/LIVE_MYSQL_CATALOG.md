# Fase 2.4 — Catálogo MySQL e reconciliação (parcial)

**Data:** 2026-11-06 (tentativa de reconciliação live; resultados não obtidos). **Schema selecionado:** `inlaud99_erpcondor`. **Status:** tentativa read-only sem resultado do catálogo live; Gate 2 não aprovado. **Branch:** `pg/02-4-live-catalog-reconciliation`.

## Resumo

A Fase 2.4 preparou a reconciliação entre o MySQL/Percona de origem, o export estrutural validado, o código do repositório oficial e as análises da Fase 2.3. A coleta live não foi concluída: My Browser exibiu o schema solicitado no phpMyAdmin 5.2.3, com SELECT somente de metadados visível no editor, mas houve alerta de formulário incompleto; uma tentativa de interação posterior expirou. Nenhuma contagem ou definição do catálogo foi recebida, e não houve reenvio da consulta.

Consequentemente, os campos de catálogo live abaixo são `NÃO CONFIRMADO`/`NÃO OBTIDO`. Isso não quer dizer zero nem ausência. Os CSVs são matrizes com metadados do export em colunas separadas, para evitar transformar inferência em fato.

## Proveniência por artefato

| Arquivo | Conteúdo e fonte | Estado live |
|---|---|---|
| [LIVE_TABLES_AND_VIEWS.csv](LIVE_TABLES_AND_VIEWS.csv) | 270 objetos do `CREATE TABLE` do export; candidatos de view ficam identificados como proposta estática | TABLE_TYPE, engine, collation e presença não confirmados |
| [LIVE_COLUMNS.csv](LIVE_COLUMNS.csv) | 3.339 colunas do CREATE inicial, sem valores de default; eventos posteriores não foram replayados | tipo final, nullabilidade, default e `EXTRA` não confirmados |
| [LIVE_KEYS_AND_INDEXES.csv](LIVE_KEYS_AND_INDEXES.csv) | 514 declarações de chave/índice em eventos ALTER | presença/definição live não confirmada |
| [LIVE_FOREIGN_KEYS.csv](LIVE_FOREIGN_KEYS.csv) | 11 FKs declaradas em ALTER no export | presença/definição live não confirmada |
| [LIVE_TRIGGERS.csv](LIVE_TRIGGERS.csv) | 7 metadados resumidos do export, sem corpos | existência/ordem/definição live não confirmadas |
| [LIVE_VIEWS_DEFINITIONS.md](LIVE_VIEWS_DEFINITIONS.md) | 22 candidatas do mapa estático da Fase 2.3 | TABLE_TYPE/SHOW CREATE e dependências live não obtidos |
| [MYSQL_EXPORT_VS_LIVE_DIFF.md](MYSQL_EXPORT_VS_LIVE_DIFF.md) | Comparação explícita export × live | diferenças indeterminadas; não declarar “sem diferença” |
| [TENANT_CLASSIFICATION_FINAL_REVIEW.csv](TENANT_CLASSIFICATION_FINAL_REVIEW.csv) | 42 propostas existentes com colunas live/final bloqueadas | classificação final pendente |
| [GATE_2_READINESS_REPORT.md](GATE_2_READINESS_REPORT.md) | Decisão e critérios do Gate 2 | recomendar manter fechado |

## O que se sabe sobre a origem

O operador informou em 2026-11-05 um resultado de consulta SELECT-only: Percona Server 5.7.44-48; charset/collation `utf8`/`utf8_unicode_ci`; timezone de sessão `SYSTEM` e sistema `-03`; SQL mode `NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`; engine padrão InnoDB; `lower_case_table_names=0`; Event Scheduler `OFF`. A fonte completa e seu grau de confiança estão em `server_metadata.txt` privado, referenciado pelo relatório interno da coleta anterior; isso não confirma engines ou collations por objeto.

No export estrutural validado pela Fase 2.3 há 270 `CREATE TABLE`, 489 `ALTER TABLE`, 3.339 colunas iniciais, 514 declarações catalogadas em ALTER (240 PK, 70 UNIQUE, 193 INDEX, 11 FK), 232 modificações de coluna e 7 triggers. Não foram extraídos `CREATE VIEW`, `PROCEDURE`, `FUNCTION` ou `EVENT`. Nomes e `not_replayed` não provam tipo nem estado instalado.

## Tentativa live e limite

Em 2026-11-06, a tela capturada pelo usuário mostrava “Está faltando valores no formulário”. A inspeção read-only confirmou que o phpMyAdmin 5.2.3 estava no schema `inlaud99_erpcondor` e que a query agregada `INFORMATION_SCHEMA.TABLES` continuava no editor. Uma tentativa de interação com o alerta expirou no navegador; nenhum resultado foi retornado. Por orientação do usuário, a coleta não foi perseguida além dessa tentativa.

## Segurança, decisões e próximos passos

- Não foram consultadas linhas de negócio nem extraídos dados pessoais, financeiros, hashes de senha ou tokens.
- Não foram executados DDL, migrations, transações de escrita, `EXPLAIN`, importação ou queries de qualidade sobre tabelas de domínio.
- MySQL de produção, PostgreSQL, API, Hetzner e deploy permanecem inalterados.
- O Gate 2 permanece fechado até obter artefatos read-only com resultados suficientes e reconciliar os 22 candidatos, as 42 estruturas, os sete triggers, o estado pós-ALTER e rotinas ausentes do export.
- A Fase 3 não deve iniciar conversão executável ou adaptação de driver antes de aprovação do Gate 2.

Para detalhes, consulte o [relatório de prontidão](GATE_2_READINESS_REPORT.md), o [comparativo](MYSQL_EXPORT_VS_LIVE_DIFF.md) e os documentos estáticos da [Fase 2.3](../phase2_3/README.md).
