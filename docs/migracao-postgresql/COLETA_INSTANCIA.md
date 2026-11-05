# Coleta da instância — Fase 2.1

> **Estado: parcial. Gate 2 permanece fechado.** Este documento registra somente o status técnico sanitizado da coleta. DDL, inventários detalhados e artefatos privados não são versionados nesta pasta.

## Evidência obtida

Foi executada, pelo administrador no phpMyAdmin, uma consulta `SELECT` somente de variáveis do servidor; nenhum dado de aplicação foi lido por essa consulta. O resultado informado foi guardado em `server_metadata.txt` no pacote privado de coleta, com origem, horário e checksum. Detalhes operacionais não foram copiados ao Git.

A listagem estrutural do schema exibiu 250 objetos na primeira página e 20 na segunda. Isso confirma 270 objetos listados na interface, mas ainda não é uma reconciliação entre tabelas-base e views, nem substitui `SHOW CREATE`.

## O que permanece pendente

A exportação personalizada do phpMyAdmin tinha SQL selecionado, estrutura selecionada e dados marcados por padrão. O checkbox global de dados foi desmarcado e a tela mostrou os dados vazios para as linhas visíveis; porém, o estado dinâmico de todos os 270 checkboxes não pôde ser validado independentemente na sessão do navegador. Por precaução, nenhum download/export foi iniciado.

Ainda não foram obtidos `tables_ddl.sql`, `tables_inventory.csv` reconciliado, `constraints_indexes.csv`, `objects_inventory.csv`, `objects_ddl.sql`, `migration_ledger.csv`, `data_quality_aggregates.csv`, `tenant_profile.csv`, `attachment_manifest.csv` ou `MYSQL_POSTGRESQL_CROSSWALK.csv` baseado na instância.

## Próximo passo operacional

O administrador deverá confirmar no formulário de exportação, antes de gerar arquivo, que todas as opções `table_data[]` estão desmarcadas e todas as `table_structure[]` estão marcadas; incluir definições de views, triggers e routines/events quando disponíveis; escolher SQL e salvar localmente em armazenamento privado. Antes de transferir o artefato, verificar ausência de `INSERT`/linhas, truncamento, dados incidentais e segredos; redigir valores operacionais e calcular SHA-256.

Para `SHOW CREATE` e objetos que não forem incluídos no export, consultar os nomes permitidos do catálogo e executar somente leitura, sem executar procedures, functions, eventos ou triggers. Consultas de qualidade devem ser planejadas como agregados limitados, em etapas e após avaliação de impacto.

## Critério de conclusão

A contagem de 270 objetos não basta para aprovar Gate 2. O gate só poderá avançar após reconciliação do DDL final, constraints/índices, objetos programáveis, migration ledger, qualidade agregada, anexos e classificação das 42 tabelas sem `tenant_id`, com evidência, hashes e decisões registradas. Nenhuma migration executável foi criada nesta fase.

## Validação da atualização

A suíte offline do catalogador, do checklist SQL e do gerador agregado passou com 25 testes; `py_compile`, verificação de links internos e `git diff --check` passaram. Esses testes validam exclusivamente o tooling local e não executam nenhuma consulta SQL na origem. A consulta 8 do checklist identifica candidatos estruturais de anexos por nome/tipo de coluna em `INFORMATION_SCHEMA`; não lê nem produz caminho, hash ou conteúdo de arquivo.
