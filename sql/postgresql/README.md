# DDL PostgreSQL versionado — P2

Este diretório é destinado ao DDL gerado e revisado da origem isolada `erp_pg_source`. **Atualmente não contém DDL executável:** a origem não existe, não houve leitura de catálogo e os mapeamentos de negócio/objetos ainda estão pendentes.

Não inferir o schema final a partir de dumps antigos, arquivos SQL históricos ou migrations MySQL. O gerador só poderá escrever aqui depois de consumir um snapshot sanitizado versionado/identificado e aprovado. Cada artefato futuro deverá registrar fonte, hash do snapshot de metadados, versão do gerador e classificações manuais, sem conter linhas de negócio, valores pessoais ou segredos.

O primeiro componente P2, `tools/migracao_pg/type_mapping.py`, é uma biblioteca sintética: produz declarações/fragmentos em memória e não conecta nem executa SQL. Consulte `AI-CONTEXT/POSTGRESQL_P2.md` para sequência, gates e critérios de aceite.
