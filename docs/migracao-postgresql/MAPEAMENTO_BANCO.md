# Mapeamento do banco MySQL/MariaDB → PostgreSQL

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial de código:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.
>
> **Destino:** PostgreSQL está definido; os mapeamentos abaixo são hipóteses de conversão a aprovar, não DDL executável.

## Evidência schema-only

O artefato externo `hostgator_schema_only.sql` foi usado somente como referência DDL, junto de `schema_extraction_summary.json` e `review_database.md`; nome e checksum estão em [baseline-manifest.json](baseline-manifest.json). Esses artefatos não são copiados para esta branch. O relatório identifica 270 definições `CREATE TABLE`, 489 `ALTER TABLE` e 7 `CREATE TRIGGER`; não há `CREATE VIEW`, `CREATE PROCEDURE`, `CREATE FUNCTION` ou `CREATE EVENT` nesse snapshot. Isso não prova o estado instalado nem a ausência desses objetos na instância.

A recontagem independente do DDL identifica 228 de 270 tabelas com coluna declarada `tenant_id` e 42 sem ela. Há 22 nomes view-like (`view_`, `vw_`, `v_`) no conjunto total; 18 deles estão entre as 42 sem `tenant_id`, e 4 possuem essa coluna. As outras 24 tabelas sem `tenant_id` não têm nome view-like. A regra lexical de nome não prova que qualquer uma seja view. Foram encontradas 11 FKs explícitas e nenhuma referencia `tenants.id`; a classificação funcional das 42 permanece pendente.

Os sete triggers observados são `trg_atualizar_km_acumulado`, `tr_dependente_ativar_veiculos`, `tr_dependente_deletar_veiculos`, `tr_dependente_inativar_veiculos`, `after_validacao_dispositivo_insert`, `trg_alerta_estoque_baixo` e `after_qrcode_token_insert`. Seus efeitos cruzam tabelas, mas não foram executados nem validados quanto a concorrência, permissões ou ordem.

## Crosswalk preliminar

| Padrão observado | Direção de planejamento PostgreSQL | Validação obrigatória |
|---|---|---|
| `ENGINE=InnoDB`, charset/collation, crases | Remover sintaxe MySQL; definir UTF-8, collation e comentários | Versão, comparação/case-folding e índices |
| `AUTO_INCREMENT` | `GENERATED ... AS IDENTITY` ou sequence | Maior ID, colisões, ownership e carga |
| `UNSIGNED`, `INT(n)` | Tipo assinado adequado + `CHECK` quando necessário; largura de exibição não é semântica | Limites reais e overflow |
| `TINYINT(1)` | `boolean`, `smallint` ou domínio | Valores existentes, NULL e defaults |
| `ENUM` | Tipo PostgreSQL, `CHECK` ou domínio | Lista real e valores fora da lista |
| `DATETIME`/`TIMESTAMP` | `timestamp` com política explícita de timezone/precisão | Uso por rota, timezone e defaults |
| Datas zero | NULL, data sentinela ou correção de dados aprovada | Perfil agregado de linhas |
| `TEXT`/`MEDIUMTEXT`/`LONGTEXT` | `text` com limites contratuais | Tamanho, encoding e retenção |
| `BLOB`/`LONGBLOB` | `bytea` ou storage externo | Manifesto de anexos e tamanho |
| `JSON` | `json` ou `jsonb` | Consultas, índices e validação |
| `DECIMAL(p,s)` | `numeric(p,s)` após confirmar precisão | Arredondamento e overflow |
| `ON UPDATE CURRENT_TIMESTAMP` | Trigger ou aplicação | Semântica de atualização concorrente |
| `INSERT IGNORE` | DML explícito com política de conflito | Constraint e tratamento de erro |
| `ON DUPLICATE KEY UPDATE` | `INSERT ... ON CONFLICT` com alvo explícito | Chave/constraint efetiva |
| `DATE_FORMAT`, `INTERVAL`, `IFNULL` e similares | Reescrita semântica PostgreSQL | Testes de equivalência por consulta |
| `SET FOREIGN_KEY_CHECKS=0` | Transação/ordem/validação desenhadas | Integridade e rollback |

Nenhuma linha da tabela é uma instrução pronta para execução. Views, procedures e funções não devem ser inventadas a partir de nomes ou de documentação.

## Inventário lexical indexado

O arquivo [inventario_sintaxe_mysql.csv](inventario_sintaxe_mysql.csv) contém cinco colunas: `arquivo`, `linha`, `sinal`, `categoria` e `classificacao_arquivo`. A triagem examinou 436 caminhos rastreados com extensões PHP/SQL/PHTML/INC (sem `docs/`, `md/`, `api/md/`, dependências vendorizadas e diretórios auxiliares); registrou 17.865 matches em 394 arquivos. Não contém trechos SQL, segredos, tokens ou dados pessoais.

Categorias: `driver_surface`, `mysql_ddl`, `mysql_dml`, `mysql_function`, `mysql_metadata`, `mysql_routine` e `mysql_data_compatibility`. A classificação do caminho diferencia candidatos canônicos de variantes/auxiliares e ferramentas/testes, mas não prova que um arquivo seja consumido. O catálogo é busca textual/heurística para revisão semântica: pode incluir falsos positivos, comentários, strings e sinais sem execução; não alega exaustividade, parsing completo ou entendimento semântico. Não substitui parser, replay ordenado de `CREATE` + `ALTER`, export da instância ou testes.

## Método de fechamento

1. Preservar checksum e origem do schema-only e do CSV.
2. Obter exports read-only da instância: `SHOW CREATE TABLE`, triggers, views, procedures, functions, events, versão e metadados de `information_schema`.
3. Reconstituir modelo final aplicando os 489 `ALTER` na ordem, marcando conflitos, drops e renomeações.
4. Classificar cada tabela sem `tenant_id` como global, catálogo, log, controle ou tenant-scoped.
5. Obter agregados de qualidade: linhas, NULLs, duplicidades, órfãos, tenant coverage, zero-date, enum, limites numéricos e tamanhos.
6. Converter em PostgreSQL descartável e testar constraints, triggers, consultas, isolamento e reconciliação.

## Fontes verificáveis

`AI-CONTEXT/DATABASE.md`; evidências externas `review_database.md`, `schema_extraction_summary.json` e `hostgator_schema_only.sql` conforme [baseline-manifest.json](baseline-manifest.json). O código de acesso e tenant está em `api/auth_helper.php`, `api/tenant_helper.php`, `api/rbac_helper.php` e endpoints sob `api/`.
