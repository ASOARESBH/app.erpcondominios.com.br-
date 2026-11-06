# Migração PostgreSQL — documentação de planejamento

> **Estado:** coleta read-only parcial. A Fase 2.3 foi publicada na branch `pg/02-3-compatibilidade-riscos`, commit `4288185bb65456d682cbf2cce5b3ebab34d4b0e5`. Na Fase 2.4, a sessão do phpMyAdmin alcançou o schema, mas o formulário informou que faltavam valores e a tentativa de interação expirou; nenhum resultado do catálogo live foi confirmado. **Gate 2 permanece fechado.**
>
> **Fonte oficial de código:** `ASOARESBH/app.erpcondominios.com.br-`, commit-base `f60739713e6de3f2473b79ecd14a70fd700e5462`.
>
> **Branch corrente:** `pg/02-4-live-catalog-reconciliation`, derivada do commit publicado da Fase 2.3. Os novos arquivos são matrizes de comparação com campos live explicitamente `NÃO CONFIRMADO`; não contêm catálogo inventado, dados de linhas, DDL PostgreSQL executável ou conversão de dados.

## Objetivo e limites

Esta pasta registra o baseline técnico e o plano de trabalho para preservar as funcionalidades do ERP Condomínios durante a migração para PostgreSQL. PostgreSQL é o destino definido pelo usuário e não está sendo reaberto como alternativa operacional. Os parâmetros de conversão, o runtime alvo, a janela de mudança, RPO/RTO e demais decisões de implementação permanecem sujeitos a aprovação.

A documentação usa exclusivamente o commit oficial e evidências estruturadas identificadas por nome e checksum no [baseline-manifest.json](baseline-manifest.json). Relatórios/schema privados não são copiados para esta branch. O ZIP/HostGator é evidência de comparação e contexto, não fonte de código a ser promovida. Nenhum dado de linha foi lido, e credenciais, tokens e DDL bruto não são publicados. A coleta inicial está em [COLETA_INSTANCIA.md](COLETA_INSTANCIA.md), a análise estática em [phase2_3/](phase2_3/README.md) e a tentativa de reconciliação live em [phase2_4/](phase2_4/LIVE_MYSQL_CATALOG.md).

## Mapa dos documentos

| Documento | Finalidade |
|---|---|
| [baseline-manifest.json](baseline-manifest.json) | Identidade do baseline, escopo, checksums das evidências e limitações. |
| [BASELINE.md](BASELINE.md) | Escopo, evidências, contagens e limitações do snapshot. |
| [ARQUITETURA_POSTGRESQL.md](ARQUITETURA_POSTGRESQL.md) | Arquitetura alvo proposta e fronteiras de compatibilidade. |
| [MAPEAMENTO_BANCO.md](MAPEAMENTO_BANCO.md) | Inventário schema-only, crosswalk MySQL/MariaDB → PostgreSQL e CSV estático. |
| [CONTRATOS_API.md](CONTRATOS_API.md) | Rotas, envelopes, status, uploads e integrações observadas. |
| [AUTENTICACAO_TENANT_RBAC.md](AUTENTICACAO_TENANT_RBAC.md) | Sessão, Bearer, dispositivos, tenant e autorização server-side. |
| [DEPENDENCIAS_RUNTIME.md](DEPENDENCIAS_RUNTIME.md) | Dependências observadas, requisitos propostos e lacunas de runtime. |
| [PLANO_MIGRACAO.md](PLANO_MIGRACAO.md) | Fases, gates, preservação funcional e artefatos necessários. |
| [PLANO_QA.md](PLANO_QA.md) | Matriz de testes, evidências e critérios de aceite. |
| [PLANO_BACKUP_ROLLBACK.md](PLANO_BACKUP_ROLLBACK.md) | Backup, restore, rollback, RPO/RTO e abort criteria. |
| [PENDENCIAS.md](PENDENCIAS.md) | Pendências bloqueadoras e evidências que precisam ser obtidas. |
| [DECISOES_TECNICAS.md](DECISOES_TECNICAS.md) | Decisões registradas, decisões pendentes e não decisões. |
| [inventario_sintaxe_mysql.csv](inventario_sintaxe_mysql.csv) | Triagem estática `arquivo,linha,sinal,categoria,classificacao_arquivo`, indexada por `MAPEAMENTO_BANCO.md`. |
| [FASE2_IMPLEMENTACAO.md](FASE2_IMPLEMENTACAO.md) | Arquitetura e tooling offline da Fase 2, contagens e artefatos bloqueadores. |
| [RELATORIOS_QUALIDADE_READ_ONLY.md](RELATORIOS_QUALIDADE_READ_ONLY.md) | Contrato de agregados sem dados de linha e operação do gerador. |
| [COLETA_INSTANCIA.md](COLETA_INSTANCIA.md) | Evidência sanitizada e bloqueios da coleta read-only. |
| [phase2_2/MYSQL_INSTALLED_CATALOG_REPORT.md](phase2_2/MYSQL_INSTALLED_CATALOG_REPORT.md) | Reconciliação parcial e limites do inventário estrutural da Fase 2.2. |
| [phase2_3/README.md](phase2_3/README.md) | Compatibilidade MySQL/PostgreSQL, riscos de integridade, tenant, índices, views e triggers, com evidência estática. |
| [phase2_4/LIVE_MYSQL_CATALOG.md](phase2_4/LIVE_MYSQL_CATALOG.md) | Estado da tentativa de catálogo live, dados exportados separados de campos não confirmados. |
| [phase2_4/MYSQL_EXPORT_VS_LIVE_DIFF.md](phase2_4/MYSQL_EXPORT_VS_LIVE_DIFF.md) | Comparação export × live e lacunas não comparáveis. |
| [phase2_4/GATE_2_READINESS_REPORT.md](phase2_4/GATE_2_READINESS_REPORT.md) | Parecer do Gate 2 e bloqueadores P0; recomendação atual: manter fechado. |
| `tools/phase2/catalog_schema_only.py` | Catálogo parcial de schema-only; inventaria `ALTER`, não os aplica nem gera estado final. |
| `tools/phase2/mysql_metadata_inventory.sql` | Consultas read-only de metadados; o arquivo não foi executado como lote. |
| `tools/generate_read_only_quality_reports.py` | Formatter/validator offline de snapshot agregado metadata-only. |

## Convenções

- **Fato:** diretamente observável no commit, em um relatório estruturado ou no artefato citado.
- **Inferência:** interpretação técnica útil para planejamento, que ainda requer validação.
- **Desconhecido:** informação não obtida e que não deve ser preenchida por suposição.
- Contagens de caminhos, ocorrências ou assinaturas são sinais de busca; não equivalem a rotas únicas, objetos instalados, linhas de dados ou comportamento em produção.
- `not_replayed` significa que o parser não aplicou os ALTERs ao CREATE inicial; não indica se a migration foi aplicada no HostGator.
- Na Fase 2.4, nenhum resultado de `INFORMATION_SCHEMA`/`SHOW CREATE` foi recebido; os artefatos live refletem isso como `NÃO CONFIRMADO`, não como ausência.

## Próximos passos imediatos

1. Obter um export metadata-only ou resultados read-only paginados que permitam reconciliar `TABLES`, `COLUMNS`, índices/FKs, views/rotinas/triggers/events e ledger.
2. Fechar a revisão live das 22 candidatas a views, 42 estruturas sem `tenant_id`, sete triggers, estado pós-ALTER e artefatos ausentes do export.
3. Registrar source, data, ambiente, checksum e limitações de cada artefato, sem coletar linhas pessoais ou credenciais.
4. Aprovar parâmetros PostgreSQL e contratos API/auth/tenant/RBAC antes de qualquer conversão; consultar [PENDENCIAS.md](PENDENCIAS.md) e [GATE_2_READINESS_REPORT.md](phase2_4/GATE_2_READINESS_REPORT.md).
5. Executar QA e ensaios de restore/rollback somente em ambiente isolado, após aprovação separada. Não iniciar Fase 3 enquanto Gate 2 estiver fechado.

## Fonte de rastreabilidade

A base de estrutura e stack vem de `AI-CONTEXT/ARCHITECTURE.md`, `AI-CONTEXT/MODULES.md`, `AI-CONTEXT/DATABASE.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SECURITY.md`, `AI-CONTEXT/DEPENDENCIES.md`, `AI-CONTEXT/TESTING.md` e `AI-CONTEXT/DEPLOY.md`, complementados por evidências externas listadas por nome e checksum no manifesto. Os caminhos de código específicos estão listados nos documentos temáticos.
