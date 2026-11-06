# Fase 2.3 — Análise de compatibilidade e riscos

Repositório exclusivo: `ASOARESBH/app.erpcondominios.com.br-`. Baseline de código: `f60739713e6de3f2473b79ecd14a70fd700e5462`. Branch local: `pg/02-3-compatibilidade-riscos`. Origem MySQL/Percona 5.7; destino PostgreSQL definitivo, sem banco criado nesta fase.

**Status:** entregáveis de análise produzidos; **reconciliação live não confirmada e Gate 2 fechado**. Não foram alterados dados, schema, APIs, aplicação ou VM. Nenhum SQL de qualidade foi executado. Não houve migration, importação, deploy, commit/push desta fase ou alteração direta de main.

## Nove entregáveis solicitados

| Arquivo | Cobertura |
|---|---|
| [MYSQL_POSTGRES_TYPE_MAPPING.csv](MYSQL_POSTGRES_TYPE_MAPPING.csv) | 3.339 colunas do CREATE inicial, tipos/transformação/PDO/validação advisory. |
| [MYSQL_POSTGRES_COMPATIBILITY_REPORT.md](MYSQL_POSTGRES_COMPATIBILITY_REPORT.md) | Relatório executivo, métricas, riscos e próximo gate. |
| [PRIMARY_KEYS_AND_INDEXES_AUDIT.csv](PRIMARY_KEYS_AND_INDEXES_AUDIT.csv) | 270 objetos; PK/UK/índices declarados, prefixos/direções e candidatos de redundância. |
| [FOREIGN_KEYS_AND_RELATIONSHIPS.csv](FOREIGN_KEYS_AND_RELATIONSHIPS.csv) | 11 FKs explícitas e 25 relações de JOIN estático, sem inferência de novas constraints. |
| [VIEWS_DEPENDENCY_MAP.md](VIEWS_DEPENDENCY_MAP.md) | 22 candidatas: contratos de colunas, fontes, consumidores, divergências e riscos. |
| [TRIGGERS_BUSINESS_RULES.md](TRIGGERS_BUSINESS_RULES.md) | Sete regras, eventos/condições, efeitos, tenant e proposta narrativa PL/pgSQL. |
| [TENANT_CLASSIFICATION_DETAILED.csv](TENANT_CLASSIFICATION_DETAILED.csv) | 42 estruturas sem tenant_id, classificação A–F proposta e evidência individual. |
| [DATA_INTEGRITY_RISKS.md](DATA_INTEGRITY_RISKS.md) | Qualidade agregada planejada, nulos/órfãos/duplicidade/temporais/custo. |
| [POSTGRES_MIGRATION_DECISIONS.md](POSTGRES_MIGRATION_DECISIONS.md) | Destino confirmado, parâmetros pendentes, gates, cutover e rollback. |

## Suplementos

- [QUALITY_QUERIES_REVIEW_ONLY.sql](QUALITY_QUERIES_REVIEW_ONLY.sql): 28 templates SELECT agregados **MySQL/Percona 5.7**. Contêm placeholders: **não executar como lote**. Não são SQL PostgreSQL nem migrations.
- [SECURITY_COLUMNS_METADATA.csv](SECURITY_COLUMNS_METADATA.csv): 128 colunas de interesse de segurança; apenas metadados e proteção recomendada.
- [VALIDATION_REPORT.md](VALIDATION_REPORT.md): testes offline e limitações; não prova integridade de registros live.
- `artifact_checksums.sha256`: integridade dos demais arquivos do pacote, não assinatura/autorização de execução.

## Convenções importantes

`not_replayed` refere-se ao parser parcial que não aplicou ALTERs sobre o CREATE inicial; não prova que a migration esteja ausente no HostGator. PK/UNIQUE/índices e tipos instalados permanecem não confirmados. Não existem catálogos PostgreSQL live nesta etapa.

A classe D significa hipótese/definição estática de view, não TABLE_TYPE confirmado. Nenhuma candidata pode ser recriada como tabela física por nome. Ausência de aresta view→view no mapa revisado não prova inexistência de ciclos live.

Tenant_id não será acrescentado automaticamente. Das 42 propostas: A Global=11, B Administrativo=3, C Tenant-specific=2, D View=12, E Auditoria/infra=8, F Indeterminado=6. A classificação estática exige aprovação e reconciliação; estruturas derivadas também podem carregar dados tenant-specific.

Nenhum arquivo deste pacote contém registros de produção, credenciais, dumps, corpos DDL, DEFINER ou defaults literais. As revisões e fontes privadas não são necessárias para abrir o pacote, mas devem ser preservadas separadamente para auditoria autorizada.
