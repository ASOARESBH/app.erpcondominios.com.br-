# Fase 2.3 — Validação dos artefatos

## Resultado

**PASS dos artefatos de análise. Gate 2 FECHADO/NÃO APROVADO.** O resultado não comprova integridade de dados, instalação de schema/objetos, desempenho, equivalência de resultados ou segurança da instância MySQL live. Não houve execução de consultas SQL ou criação de PostgreSQL.

## Verificações offline realizadas

| Verificação | Resultado |
|---|---|
| CSV de tipos: 3.339 pares tabela/coluna únicos | PASS |
| Auditoria: 270 objetos; nomes iguais ao catálogo parcial | PASS |
| Relações: 11 FKs explícitas e 25 JOINs estáticos | PASS |
| Classificação tenant: 42 objetos sem tenant_id, categorias A–F | PASS |
| Views: 22 seções individuais; colunas/tipos/argumentos/nulabilidade do CREATE parcial | PASS |
| Triggers: sete seções com evento, origem, condição, alvos e narrativa | PASS |
| Metadados suplementares de segurança: 128 colunas | PASS |
| SQL de revisão: 28 templates top-level SELECT, sem mutações/tokens PostgreSQL | PASS lexical |
| Ausência de truncamento, campos CSV vazios, caminhos absolutos e citações a dump histórico | PASS |
| Verificação de links internos e escopo exclusivo phase2_3 | PASS |
| Padrões de credenciais/DEFINER/corpos de definição não presentes | PASS nos artefatos analisados |
| Revisão independente de SQL MySQL 5.7 e relatório de integridade corrigidos | PASS estático |

A validação lexical SQL usa o lexer já disponível no projeto, sem instalar dependência. Não é compilação por MySQL nem execução de queries. Os placeholders tornam os templates inadequados para execução direta. Campos de negócio, tipos, índice/custo e grants efetivos continuam exigindo confirmação na origem.

Foram executados **25 testes sintéticos offline das ferramentas herdadas de catalogação/agregação/inventário**, todos aprovados. Não utilizam dados de produção e não validam a migração. Comando:

```bash
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest -v \
  tools/phase2/test_catalog_schema_only.py \
  tools/test_generate_read_only_quality_reports.py \
  tools/phase2/test_mysql_metadata_inventory.py
```

## Correções de QA desta entrega

- Reescrita da síntese de views/triggers para remover JSON truncado e recuperar campos das revisões individuais, sem pesquisar objetos novamente.
- Eliminação da falsa autoaresta de view formada por referência ao próprio arquivo; ciclos live permanecem desconhecidos.
- Restauração das narrativas completas na classificação tenant; referências a dump histórico não são evidência utilizada.
- Preservação de precisão/escala/comprimento e UNSIGNED nos tipos; TIME pode ser duração, e BIGINT fora da faixa PHP exige representação decimal em string.
- Candidatura de duplicidade de índices não equipara PK/UNIQUE/índice comum; prefixos/direções são preservados e opções/métodos live continuam desconhecidos. Nenhum índice é removível por esta análise.
- Índice por prefixo textual não é apresentado como suporte integral confirmado de FK/UK referenciada.
- Consultas de qualidade corrigidas para MySQL/Percona 5.7, com tratamento de NULL em UNIQUE, EXISTS/NOT EXISTS, JSON_VALID e datas zero, sem valores individuais.
- PostgreSQL registrado como destino já definido; `not_replayed` não significa migration não aplicada no HostGator.

## Controles preservados

Somente `docs/migracao-postgresql/phase2_3/` recebeu artefatos naquela entrega. O worktree da branch isolada não incorporou pendências herdadas de `main`. A documentação foi publicada em `pg/02-3-compatibilidade-riscos`, commit `4288185bb65456d682cbf2cce5b3ebab34d4b0e5`; `main` não foi alterada. Não foram alterados APIs, aplicação, banco, VM ou produção; nenhum SQL, EXPLAIN, migration, carga ou deploy foi executado.

Os checksums cobrem os arquivos da entrega, excluindo o próprio manifesto. Devem ser conferidos após extração com `sha256sum -c artifact_checksums.sha256`. Hashes não equivalem a aprovação de gate nem autorização de operação.
