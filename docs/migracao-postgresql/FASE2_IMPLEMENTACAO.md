# Fase 2 — arquitetura, tooling offline e reconciliação do banco

> **Status: preparação/coleta parcial; Gate 2 não passou.** O novo anexo schema-only foi validado por parser fail-closed. Uma consulta agregada à instância foi submetida uma vez, mas o navegador expirou sem resultado confirmado e a consulta não foi reenviada. Não há DDL PostgreSQL executável, conversor de linhas, escrita em banco, leitura de linhas de aplicação, acesso à VM/Hetzner, instalação, migration, importação, deploy ou mudança em código da aplicação.

## 1. O que foi implementado

| Artefato | Função | Limite de segurança |
|---|---|---|
| `tools/phase2/catalog_schema_only.py` | Lexer e catalogador do snapshot schema-only: contagem/ordem/hash de statements, tabelas/colunas declaradas, candidatos de tipo, FKs e suboperações de `ALTER`. | Não executa SQL. Exige fingerprint `766/270/489/7/11`, cobertura tenant `228/42` e partição view-like `22/4/18/24`; divergência bloqueia bundle. Rejeita comentários executáveis, cláusulas desconhecidas/incompletas e caudas SQL não consumidas; tokens de literais não viram atributos. Registra `ALTER` como não reproduzido e só produz projeção parcial. Não grava DDL bruto nem cria `catalog_final.json`. |
| `tools/phase2/test_catalog_schema_only.py` | Testes com DDL sintético para delimitadores, tipos, constraints, triggers, entrada unsafe, falhas fechadas e publicação do bundle. | Fixtures não vêm do dump nem da produção. |
| `tools/phase2/mysql_metadata_inventory.sql` | Oito consultas `SELECT` à `INFORMATION_SCHEMA`/variáveis para obter metadados, incluindo candidatos de anexos por nomes/tipos de coluna. | O lote continua sem execução. Além do SELECT anterior de variáveis, foi submetido manualmente um SELECT agregado de `TABLE_TYPE`; a ação expirou sem resultado e não foi repetida. O checklist não lê `ROUTINE_DEFINITION`, `VIEW_DEFINITION`, dados de negócio nem corpos de objetos. |
| `tools/generate_read_only_quality_reports.py` | Gera CSVs agregados a partir de snapshot JSON metadata-only previamente autorizado. | Sem driver, SQL, rede ou acesso ao banco; exige hash aprovado, schema fechado, decomposição exata 270/228/42 e view-like 22/4/18/24, e publicação privada no-replace. Falha de `fsync` após rename informa que o bundle já existe e deve ser verificado antes de nova tentativa. |
| `tools/test_generate_read_only_quality_reports.py` | Testes sintéticos do contrato dos agregados. | Não contém snapshot real. |
| `docs/migracao-postgresql/RELATORIOS_QUALIDADE_READ_ONLY.md` | Contrato de entrada, política de dados e operação do gerador. | Agregados reais seguem pendentes. |

## 2. Recontagem independente do schema-only

O catalogador foi executado localmente sobre o arquivo schema-only já autorizado, após conferir o SHA-256 da entrada. Os contadores resultantes são:

- 766 statements: 270 `CREATE TABLE`, 489 `ALTER TABLE` e 7 `CREATE TRIGGER`;
- 3.339 declarações de coluna reconhecidas;
- 891 suboperações lexicais de `ALTER`: 240 adições de PK, 193 de índices, 70 de unique, 11 de FK, 232 modificações de coluna e 145 ajustes de `AUTO_INCREMENT`;
- 11 FKs explícitas no material; sete triggers declaram DML interno;
- 0 `CREATE VIEW`, `PROCEDURE`, `FUNCTION` ou `EVENT` presentes no artefato schema-only;
- 228/270 tabelas declaram `tenant_id`; 42 não declaram. Existem 22 nomes view-like no total: quatro entre as tabelas com tenant e 18 entre as 42 sem; as demais 24 sem tenant não têm nome view-like.

As contagens descrevem **somente o DDL de entrada**, não o banco instalado, os dados, nem a semântica dos objetos. “DML interno em trigger” é uma assinatura lexical, não revisão da lógica ou prova de instalação. Os 145 `AUTO_INCREMENT` encontrados representam ocorrências no DDL, não uma decisão de identity/sequence.

## 3. Por que ainda não existe schema final nem migration PostgreSQL

A ordem de estado é `CREATE TABLE` seguido de `ALTER TABLE`; muitos PKs, unique, índices, FKs, alterações de coluna e valores de `AUTO_INCREMENT` aparecem nos `ALTER`. Converter apenas os `CREATE TABLE` perderia estrutura. O catalogador inventaria e classifica os eventos, mas não os reproduz: não trata ainda toda a semântica de ordem, dependências, defaults, prefix indexes, tipos, collations, constraints e efeito de triggers. Por isso:

- não produzir um suposto DDL final seria uma conclusão falsa;
- não há script que importe linhas ou aplique schema;
- o mapping em `type_mapping_advisory.json` é consultivo, com `executable=false` e blockers por coluna;
- a decisão entre `identity`/sequences, enum/`CHECK`/domínio, `json`/`jsonb`, tempo com/sem timezone e tratamento de zero-dates segue pendente;
- objetos instalados não podem ser inferidos pela ausência do schema-only;
- regras de tenant não podem ser derivadas apenas da presença/ausência da coluna.

Uma próxima entrega de parser pode gerar um modelo final apenas após replay determinístico de todas as 489 alterações, com cada cláusula consumida por gramática explícita, erro em cláusula desconhecida, invariantes de tabela/coluna/constraints e comparação contra metadados da instância. Até lá o status é `partial_source_catalog`, não “conversão completa”.

## 4. Protocolo de coleta por artefato

O checklist SQL está em `tools/phase2/mysql_metadata_inventory.sql`. É apenas consulta estática: executar exige aprovação operacional específica, selecionar manualmente o schema certo, conta read-only e armazenamento privado das saídas. `VERSION()`/`INFORMATION_SCHEMA` não substituem `SHOW CREATE` nem garantem permissão para ver todos os objetos.

| Artefato pedido | Conteúdo aceitável | Origem/observação |
|---|---|---|
| `server_metadata.txt` | Versão/produto, schema selecionado, charset/collation/timezone/sql_mode e engine default. | Saída da consulta 1; redigir qualquer segredo/configuração operacional incidental. |
| `tables_ddl.sql` | `SHOW CREATE TABLE` por cada tabela aprovada, ordem registrada e checksum; só DDL de tabelas. | `INFORMATION_SCHEMA` não substitui `SHOW CREATE`. Rejeitar DML top-level/dados de linhas e comentários executáveis não revisados; separar `objects_ddl.sql`, cujos corpos de triggers/rotinas podem conter DML legítimo. Não remover DML automaticamente. Redigir `AUTO_INCREMENT` numérico e literais incidentais antes de transferir. |
| `constraints_indexes.csv` | PK/UK/FK, colunas/ordem, regras FK, índices e prefix length. | Consultas 4–5; confirmar cobertura e privilégios. |
| `objects_inventory.csv` | Schema, tipo, nome, status/timing/evento quando aplicável. | Consulta 6; status/timing/evento não aplicáveis ficam nulos; nomes são inventário, não prova de execução completa. |
| `objects_ddl.sql` | `SHOW CREATE` de views, triggers, procedures, functions e events existentes, somente se autorizado; checksum e redação de DEFINER/segredos antes da transferência. | Artefato ausente na extração atual. Nunca aceitar body truncado/incompleto. |
| `migration_ledger.csv` | Identificador técnico, migration, checksum, estado, data/ambiente e versão; sem logs livres nem dados pessoais. | Primeiro identificar tabela/colunas pelo nome e `INFORMATION_SCHEMA`; `SHOW CREATE` e export de registros são pedidos separados, sujeitos a revisão/escopo aprovados. |
| `data_quality_aggregates.csv` | Contagens, nulls/duplicidades, candidatos de órfãos, estado de enum/zero-date/overflow/JSON e anexos, sem valores/amostras. | Snapshot metadata-only strict; sem registros pessoais ou valores de min/max. Ferramenta atual apenas valida/formata agregados fornecidos. |
| `tenant_profile.csv` | Uma linha por tabela; presença, null/default counts, classificação pendente. | Gerado pelo mesmo contrato; a ausência de tenant não deve ser corrigida automaticamente. |
| `attachment_manifest.csv` | Referência técnica mínima, hash, tamanho, tenant e estado de localização; sem conteúdo. | A consulta 8 encontra somente candidatos estruturais pelo nome/tipo da coluna; não lê caminhos/valores, nem produz o manifesto. Minimizar caminhos/nome de arquivo e acordar tenant, acesso, retenção e legal basis antes da coleta. |

Além dos itens acima, falta o manifesto de runtime: PHP/SAPI/extensões, versão PDO/pgsql, Nginx/PHP-FPM, Composer e lockfile, Node/Chrome de QA, cron/scheduler, limites de upload, timezone, locale, storage, observabilidade e owners. Também faltam decisões medidas/aprovadas de RPO/RTO/SLO, janela de cutover, tolerância de perda e autoridade de rollback.

**Não enviar:** senha, chave, token, cookie, `.env`, dump com linhas, upload completo, log livre, export de conteúdo de views/rotinas sem autorização, nem valor individual de coluna ou expressão/default literal. O SQL não exporta `COLUMN_DEFAULT`, expressão generated nem valor numérico corrente de `AUTO_INCREMENT`; `COLUMN_TYPE` pode conter labels de ENUM/SET e deve ser revisado/redigido antes da transferência. Marcadores DML fora de contexto são ambíguos: classificar se são statements top-level de dados ou parte de corpos autorizados de objetos programáveis. Se não puderem ser classificados sem ler valores, manter o arquivo pendente e solicitar artefatos separados; nunca remover statements automaticamente. Os arquivos devem ser transmitidos por canal aprovado, com hash externo, dono, ambiente, data, retenção e registro no migration ledger.

## 4.1 Matriz de gates para avançar de fase

| Gate | Responsável pela evidência | Evidência mínima | Aprovadores | Passa somente se | Abortar / rollback |
|---|---|---|---|---|---|
| **2 — schema, objetos, tenant e perfil** | DBA/engenheiro de dados e tech lead | Nove artefatos da seção 4, com hashes, origem, ambiente, data e relatório de cobertura; replay completo dos 489 ALTERs em modelo descartável; inventário de objetos comparado com fonte/instância. | Dono técnico do ERP + segurança/privacidade + responsável de negócio multi-tenant. | Todos objetos/schema são explicados; tabelas e constraints reconciliam; 42 tabelas sem tenant têm owner/classificação; nenhum body faltante/truncado; blockers de tipo/tenant enumerados e aceitos. | Não produzir migration executável; manter catalogação parcial. Se artefato diverge, descartar bundle e solicitar export autorizado corrigido. |
| **3 — migrations PostgreSQL e adaptador** | DBA + líder PHP/API | Migrations versionadas/checksummed em branch isolada, matriz de crosswalk aprovada, testes de parse/replay em PostgreSQL descartável e smoke da API em ambiente QA. | Tech lead + DBA + security reviewer. | Schema/índices/rotinas necessários aplicados em QA; migrations repetíveis conforme contrato; isolamento/falha fechada e migrations reversíveis ou forward-fix documentado. | Descartar QA; restaurar snapshot descartável; nunca aplicar em origem/produção. Reverter branch se falha antes de merge; schema com dados tem rollback por restore/fix forward, não `DROP` presumido. |
| **4 — reconciliação e homologação** | QA lead + data owner + responsáveis dos módulos | Relatórios agregados por tabela/tenant, diferenças explicadas, T01–T22, restore drill, performance e evidência de rollback testado. | Dono de negócio + tech lead + segurança + QA. | Contagens/relacionamentos/outputs críticos passam limites aprovados; sem vazamento cross-tenant; backup restaurado dentro do RPO/RTO; testes de módulos preservados aprovados. | Não liberar cutover; corrigir e reexecutar QA em ambiente descartável. Dados inconsistentes exigem nova carga/restore controlada, não edição manual de produção. |
| **5 — VM Hetzner e cutover** | Operações/SRE + DBA | Fingerprint SSH validada por canal independente, admin não-root/sudo, firewall revisado, TLS, monitoramento, backup externo/PITR, plano e janela de mudança assinados. | Owner do serviço + DBA + segurança + aprovador explícito do usuário. | Acesso seguro e restore operacional foram comprovados; congelamento/escrita/cutover e observabilidade prontos; critério de abort e responsáveis de plantão definidos. | Antes de abrir escrita, abortar e manter origem sem alteração. Após nova escrita no destino, só rollback por procedimento aprovado com escrita congelada e reconciliação/replicação; se não for seguro reverter, forward-fix/PITR com aprovação. Nunca reabrir origem sem contabilizar transações. |

Os gates são sequenciais e independentes de publicação. SSH ainda bloqueado/pendente e a matriz acima não autoriza tentativa de conexão, alteração de firewall, importação, migration ou deploy.

## 5. Execução local permitida do catalogador

A entrada deve ser o DDL schema-only autorizado, somente leitura, nunca `mysqldump` com linhas. O hash deve ser conferido separadamente. O diretório pai precisa ser privado (`0700`), pertencer ao usuário executor e ficar fora do checkout; o destino deve ser novo. Exemplo (substituir caminhos e hash fora de terminal compartilhado):

```bash
python3 tools/phase2/catalog_schema_only.py \
  --input /caminho-privado/tables_ddl.sql \
  --expected-input-sha256 '<sha256 conferido por canal confiável>' \
  --output-dir /caminho-privado-novo/catalog
```

O bundle contém `statements.csv`, `objects_partial.json`, `catalog_partial.json`, `type_mapping_advisory.json`, `source_coverage.json`, `manifest.json` e checksum. Diretório `0700`, arquivos `0600`. Não há conteúdo bruto de DDL no bundle. Relatórios reais permanecem fora do Git.

## 6. Migração completa futura — sequência, sem execução nesta fase

1. Reconstruir e comparar o schema final MySQL/MariaDB e o inventário real instalado; revisar todos os 489 ALTERs e os sete bodies de trigger.
2. Aprovar crosswalk de tipos e resolução de zero dates, `UNSIGNED`, ENUM/SET, prefix indexes, collations, defaults, `AUTO_INCREMENT`, rotinas, timezone e JSON.
3. Escrever migrations PostgreSQL pequenas, ordenadas, idempotentes onde seguro, com checksums e registro em ledger. Uma migration não deve ignorar ou “consertar” ambiguidades com fallback tenant.
4. Converter dados em carga descartável, streaming/batches, com snapshot consistente, encoding declarado, mapa de identity, tratamento determinístico de relações, staged validation, ordem de FK e revalidação final de constraints. Conteúdos de anexos só via manifesto/contrato autorizado.
5. Rodar reconciliação de contagens e agregados por tabela/tenant, validação referencial, relatórios e consultas de negócio; executar teste de restauração e rollback antes/depois da escrita.
6. Adaptar e testar API PHP separadamente (PDO_PGSQL/dialect explícito) e executar QA funcional/security; nenhum resultado do catálogo autoriza trocar driver em produção.
7. Migrar/cutover somente após Gate 2–5, aprovação, acesso seguro validado e janela explícita.

## 7. Validação realizada nesta preparação

A suíte offline do catalogador, do checklist SQL e do gerador agregado passou anteriormente com 25 testes; isso não valida a consulta live nem a reconciliação desta fase. O anexo `(7)` passou pelo parser fail-closed: 270 `CREATE TABLE`, 489 `ALTER TABLE`, 7 triggers, 11 FKs explícitas e zero DML top-level. Seu catálogo semântico coincide com o export schema-only anterior. O SELECT agregado de `INFORMATION_SCHEMA.TABLES` foi submetido uma vez, mas expirou no navegador; nenhum resultado foi recebido e não houve retry. Os artefatos da Fase 2.2 são projeções parciais do export, com estado live marcado desconhecido. Não se comprovou o tipo dos 270 objetos listados pelo phpMyAdmin, nem se obteve `SHOW CREATE` live. O Gate 2 permanece parcial. Nenhuma migration PostgreSQL, integração com API ou produção foi testada.
