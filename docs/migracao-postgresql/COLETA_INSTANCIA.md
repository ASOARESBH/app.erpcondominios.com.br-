# Coleta da instância — Fase 2.1

> **Estado: parcial. Gate 2 permanece fechado.** Este documento registra somente o status técnico sanitizado da coleta. DDL, hashes brutos e artefatos operacionais detalhados ficam fora desta pasta.

## Evidência obtida

Foi executada, pelo administrador no phpMyAdmin, uma consulta `SELECT` somente de variáveis do servidor; nenhum dado de aplicação foi lido por essa consulta. O resultado informado foi guardado em `server_metadata.txt` no pacote privado de coleta, com origem, horário e checksum. Detalhes operacionais não foram copiados ao Git.

A listagem estrutural do schema exibiu 250 objetos na primeira página e 20 na segunda. Isso confirma 270 objetos listados na interface, mas ainda não é reconciliação entre tabelas-base e views, nem substitui `SHOW CREATE`.

O SQL anexado depois foi submetido apenas a um preflight em memória, com tamanho, hash e contagens de marcadores por início de linha. Como o preflight encontrou marcadores de operações DML (`INSERT`/`UPDATE`/`DELETE`) e comentários condicionais, o arquivo **não foi aceito como schema-only**. O catalogador fail-closed parou antes de gerar bundle. Não foram exibidos, extraídos, transformados ou persistidos valores de registros ou nomes/trechos SQL; o anexo original não foi copiado para o Git ou para o pacote de resultados.

## O que permanece pendente

Ainda não foram obtidos `tables_ddl.sql`, `tables_inventory.csv` reconciliado, `constraints_indexes.csv`, `objects_inventory.csv`, `objects_ddl.sql`, `migration_ledger.csv`, `data_quality_aggregates.csv`, `tenant_profile.csv`, `attachment_manifest.csv` ou `MYSQL_POSTGRESQL_CROSSWALK.csv` baseado na instância. Os counts anteriores de 270 `CREATE TABLE`, 489 `ALTER TABLE`, 7 triggers e 228/42 tenant vêm do schema-only previamente analisado e não provam o estado instalado.

## Próximo passo operacional

Gerar um novo export personalizado `.sql` em armazenamento privado, com todas as opções `table_data[]` desmarcadas e todas as `table_structure[]` marcadas; incluir definições de views, triggers e routines/events quando disponíveis. Antes de transferir, confirmar que o arquivo contém apenas DDL: sem `INSERT`, DML, linhas, truncamento ou segredos. Se a ferramenta incluir comandos de sessão necessários ao DDL, revisar e aprovar separadamente sem executar o arquivo. Para `SHOW CREATE` e objetos não incluídos no export, consultar nomes permitidos do catálogo em modo somente leitura, sem executar procedures, functions, eventos ou triggers.

As consultas para qualidade devem ser planejadas como agregados limitados, em etapas e após avaliação de impacto. A consulta 8 do checklist identifica somente candidatos estruturais de anexos por nome/tipo de coluna em `INFORMATION_SCHEMA`; não foi executada na origem e não produz manifesto.

## Critério de conclusão

A contagem de 270 objetos não basta para aprovar Gate 2. O gate só poderá avançar após reconciliação do DDL final, constraints/índices, objetos programáveis, migration ledger, qualidade agregada, anexos e classificação das 42 tabelas sem `tenant_id`, com evidência, hashes e decisões registradas. Nenhuma migration executável foi criada nesta fase.

## Validação da atualização

A suíte offline do catalogador, do checklist SQL e do gerador agregado passou com 25 testes; `py_compile`, verificação de links internos e `git diff --check` passaram. Esses testes validam exclusivamente o tooling local e não executam consultas SQL na origem. O preflight do arquivo anexado foi apenas uma checagem de marcadores para rejeição segura, não um parse ou inventário completo.
