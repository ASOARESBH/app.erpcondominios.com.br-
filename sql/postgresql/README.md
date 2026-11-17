# DDL PostgreSQL versionado — P2

Este diretório é destinado ao DDL gerado e revisado da cópia MySQL isolada `erp_pg_source`. **Atualmente não contém DDL executável:** o catálogo da cópia ainda não foi exportado/reconciliado e os mapeamentos de negócio/objetos permanecem pendentes.

Não inferir o schema final a partir de dumps antigos, arquivos SQL históricos ou migrations MySQL. O gerador só poderá escrever aqui depois de consumir um snapshot sanitizado versionado/identificado e aprovado. Cada artefato futuro deverá registrar fonte, hash do snapshot de metadados, versão do gerador e classificações manuais, sem conter linhas de negócio, valores pessoais ou segredos.

Identidades operacionais verificadas em 2026-11-16:

| Banco/schema | Papel | Uso e restrições |
| --- | --- | --- |
| MySQL `erpcondominios_stage_20261114` | Produção ativa após cutover de 14/11/2026 | O nome “stage” é histórico. Não usar como origem, não escrever durante desenvolvimento P2. |
| MySQL `erpcondominios` | Schema antigo de rollback | Não é produção ativa nem fonte do pipeline; preservar sem alteração. |
| MySQL `erp_pg_source` | Cópia de origem do dump de produção | Origem exclusiva futura; `pg_reader@localhost` tem `SELECT`/`SHOW VIEW`. 275 tabelas-base, 7 triggers, zero views/procedures/functions/events no dump verificado. |
| MySQL `erp_hml_mysql` | Cópia de homologação | Restaurada do mesmo dump de `erp_pg_source`; aplicação HML usa `erp_hml_app`, com grants somente neste schema. |
| PostgreSQL `erp_pg_dev` | Destino de desenvolvimento | Contém schemas `erp` e `legado`; no estado desta verificação, zero tabelas de aplicação carregadas. |

O dump verificado foi identificado pelo cabeçalho como originário de `erpcondominios_stage_20261114`. O contrato anterior esperava duas procedures, mas o dump contém zero; essa diferença é bloqueadora até recuperar/confirmar os artefatos de rotina. Nenhum rebuild deve conectar-se aos schemas ativo/rollback ou depender de `DEFINER`; o arquivo `tools/migracao_pg/rebuild.sh` ainda não foi implementado. O primeiro componente P2, `tools/migracao_pg/type_mapping.py`, é uma biblioteca sintética: produz declarações/fragmentos em memória e não conecta nem executa SQL. Consulte `AI-CONTEXT/POSTGRESQL_P2.md` para sequência, gates e critérios de aceite.
