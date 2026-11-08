# Fase 2.4 — Catálogo MySQL e reconciliação

**Atualização:** 2026-11-08. **Schema:** `inlaud99_erpcondor`. **Status:** catálogo do snapshot completo de 2026-11-07 aprovado por André para fins de conversão. **Branch:** `pg/02-4-live-catalog-reconciliation`.

A tentativa de coleta via phpMyAdmin em 2026-11-06 não retornou resultados; nenhum dos dez blocos do pacote assistido foi executado. Em 2026-11-08, André determinou que o catálogo metadata-only obtido da cópia restaurada do dump completo de 07/11 substitui a coleta via phpMyAdmin para fins de conversão. O dump e a cópia de ensaio são privados, não estão neste repositório e não são fixtures.

A aprovação cobre o catálogo do snapshot, não mudanças de produção posteriores ao momento do dump, cutover, deploy ou conexão do ERP. Na janela de virada será necessário backup final, checksum/proveniência, reconciliação atualizada, revisão e rollback.

## Resumo do catálogo aprovado

| Métrica | Resultado do snapshot | Uso/limite |
|---|---:|---|
| `BASE TABLE` | 270 | Entrada do gerador de DDL. |
| Colunas | 3.339 | Catálogo MySQL restaurado, não projeção do `CREATE TABLE` inicial. |
| Engine | InnoDB em 270/270 | Confirmado no snapshot restaurado. |
| Índices | 770 | 400 BTREE não únicos e 370 únicos/PK. |
| FKs | 11 | Assinaturas e ações verificadas; destino passa por validação de dados. |
| `AUTO_INCREMENT` | 232 | Geração/alinhamento de identities PostgreSQL com `max+1`. |
| Tabelas sem PK | 30 | Reconciliação por multiconjunto/hash e contagem. |
| Triggers | 7 | Fingerprints metadata-only, tradução manual e harness sintético. |
| Procedures | 2 | Fingerprints/assinaturas metadata-only e tradução manual. |
| Functions / views / events | 0 / 0 / 0 | Ausências confirmadas no catálogo restaurado do dump. |
| Tenant coverage | 228 com `tenant_id`; 42 sem | Não adicionar tenant automaticamente; revisão semântica segue necessária. |
| `ON UPDATE CURRENT_TIMESTAMP` | 111 colunas | Lista explícita aprovada e versionada para gerar um trigger por coluna. Diverge de uma contagem estática anterior, resolvida pelo snapshot. |
| Candidatas estáticas anteriores a views | 22 tabelas | Os 22 nomes existem como tabelas no snapshot, não como views. |

## Proveniência por artefato

| Artefato | Fonte/uso | Estado |
|---|---|---|
| [LIVE_TABLES_AND_VIEWS.csv](LIVE_TABLES_AND_VIEWS.csv) | Matrizes iniciais baseadas no export schema-only | Histórico; complementar ao catálogo de snapshot. |
| [LIVE_COLUMNS.csv](LIVE_COLUMNS.csv) | Colunas do CREATE inicial do export | Não representa sozinho o estado pós-ALTER; o gerador usa o catálogo da cópia restaurada. |
| [LIVE_KEYS_AND_INDEXES.csv](LIVE_KEYS_AND_INDEXES.csv) | Chaves/índices extraídos estaticamente | Histórico; comparar com as 770 entradas confirmadas no snapshot. |
| [LIVE_FOREIGN_KEYS.csv](LIVE_FOREIGN_KEYS.csv) | Relações do export | 11 relações confirmadas no snapshot, com validação posterior. |
| [LIVE_TRIGGERS.csv](LIVE_TRIGGERS.csv) | Metadados resumidos do export | 7 triggers confirmados no snapshot; fingerprints não incluem corpos publicados. |
| [LIVE_VIEWS_DEFINITIONS.md](LIVE_VIEWS_DEFINITIONS.md) | 22 nomes view-like da análise antiga | Resolvidos como tabelas no snapshot; não são views. |
| [MYSQL_EXPORT_VS_LIVE_DIFF.md](MYSQL_EXPORT_VS_LIVE_DIFF.md) | Comparativo inicial incompleto | Atualizado para reconhecer a fonte do snapshot e seus limites temporais. |
| [GATE_2_READINESS_REPORT.md](GATE_2_READINESS_REPORT.md) | Decisão do Gate 2 | Aprovado por André em 08/11 para fins de conversão; não para produção/cutover. |
| [MYSQL_LIVE_CATALOG_COLLECTION.sql](MYSQL_LIVE_CATALOG_COLLECTION.sql) | Dez consultas read-only independentes | Mantido como referência/recovery; não é evidência de execução. |
| [INSTRUCOES_COLETA_MYSQL_LIVE.md](INSTRUCOES_COLETA_MYSQL_LIVE.md) | Manual assistido phpMyAdmin | Mantido; não exigido para aprovação do snapshot. |

## Interpretação e segurança

- O snapshot é proveniente de uma cópia privada restaurada do dump completo de ensaio do banco `inlaud99_erpcondor`. A coleta estrutural não consulta linhas diretamente no MySQL de produção.
- O dump completo foi autorizado para conversão experimental isolada. Valores de moradores, documentos, financeiro, hashes e outras linhas não são exibidos, publicados ou versionados. Permanecem privados no armazenamento de ensaio.
- O catálogo substitui o estado estático pós-ALTER para o pipeline deste snapshot. Não prova que produção permaneceu idêntica depois de 2026-11-07.
- O banco MySQL de produção não foi alterado por esta etapa. O ensaio usa instâncias e clusters isolados; não conectar a API/ERP ao destino como serviço.
- A aprovação do Gate 2 não é evidência de conversão completa: tabelas, linhas, hashes, numeric sums, binários, tenancy, sequences, FKs e timestamps precisam passar na reconciliação v9.

## Próximos passos

1. Gerar DDL PostgreSQL versionado a partir do catálogo exato do snapshot e revisar nomes de schema `erp`/`legado`, collations, tipos, defaults, índices, identities, triggers e routines.
2. Executar a carga v9 somente em database novo e socket-only, com MySQL fonte read-only e logs agregados.
3. Fazer reconciliação 270/270; bloquear em divergência, órfãos não autorizados, NUL textual, TIME incompatível, precisão perdida ou mudança de catálogo.
4. Manter validação de tenancy e das 42 tabelas sem `tenant_id` como requisito semântico.
5. Repetir backup e reconciliação em qualquer janela de virada; preservar o dump original.
