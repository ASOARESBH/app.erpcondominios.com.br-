# Migração PostgreSQL — documentação de planejamento

> **Estado:** planejamento e coleta read-only parcial. Não houve deploy, migration, acesso à VM, escrita no banco, leitura de linhas de aplicação, importação de dados, alteração de runtime ou alteração do código aplicativo. Foi consultado somente um conjunto de variáveis de servidor por `SELECT` no phpMyAdmin.
>
> **Fonte oficial de código:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.
>
> **Branch:** `pg/02-inventario-hostgator`, derivada da branch publicada `pg/01-schema-tenant-rbac` e mantida em worktree isolado. Esta fase acrescenta status sanitizado da coleta; não há alterações na aplicação, DDL PostgreSQL executável ou conversão de linhas.

## Objetivo e limites

Esta pasta registra o baseline técnico e o plano de trabalho para preservar as funcionalidades do ERP Condomínios durante a migração para PostgreSQL. PostgreSQL é o destino definido pelo usuário e não está sendo reaberto como alternativa operacional. Os parâmetros de conversão, o runtime alvo, a janela de mudança, RPO/RTO e demais decisões de implementação permanecem sujeitos a aprovação.

A documentação usa exclusivamente o commit oficial e evidências estruturadas identificadas por nome e checksum em [baseline-manifest.json](baseline-manifest.json). Esses relatórios/schema estão fora do repositório e não são copiados para esta branch. O ZIP/HostGator é evidência de comparação e contexto, não fonte de código a ser promovida. A coleta da instância limitou-se a metadados do servidor; não foram lidos dados de linha nem usados credenciais, tokens, uploads, logs operacionais ou SSH. Os detalhes privados e o status da coleta estão em [COLETA_INSTANCIA.md](COLETA_INSTANCIA.md), sem DDL bruto.

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
| [FASE2_IMPLEMENTACAO.md](FASE2_IMPLEMENTACAO.md) | Arquitetura e tooling offline da Fase 2, contagens reconciliadas e artefatos ainda bloqueadores. |
| [RELATORIOS_QUALIDADE_READ_ONLY.md](RELATORIOS_QUALIDADE_READ_ONLY.md) | Contrato de agregados sem dados de linha e operação do gerador. |
| [COLETA_INSTANCIA.md](COLETA_INSTANCIA.md) | Evidência sanitizada e bloqueios da coleta read-only real da Fase 2.1. |
| `tools/phase2/catalog_schema_only.py` | Catálogo parcial de schema-only; inventaria `ALTER`, não os aplica nem gera estado final. |
| `tools/phase2/mysql_metadata_inventory.sql` | Consultas read-only de metadados; o arquivo não foi executado como lote. Uma consulta separada de variáveis do servidor foi executada pelo operador. |
| `tools/generate_read_only_quality_reports.py` | Formatter/validator offline de snapshot agregado metadata-only. |

## Convenções

- **Fato:** diretamente observável no commit, em um relatório estruturado ou no artefato citado.
- **Inferência:** interpretação técnica útil para planejamento, que ainda requer validação.
- **Desconhecido:** informação não obtida e que não deve ser preenchida por suposição.
- Contagens de caminhos, ocorrências ou assinaturas são sinais de busca; não equivalem a rotas únicas, objetos instalados, linhas de dados ou comportamento em produção.
- Nenhum documento autoriza escrita no banco, migration executável, alteração de aplicação/API/runtime/VM ou exportação de linhas de aplicação; a única operação real autorizada e executada nesta fase foi um `SELECT` de metadados do servidor.

## Próximos passos imediatos

1. Aprovar os parâmetros PostgreSQL e a matriz de compatibilidade em [DECISOES_TECNICAS.md](DECISOES_TECNICAS.md).
2. Completar, por canal autorizado e read-only, os exports de metadados, objetos e qualidade agregada descritos em [PENDENCIAS.md](PENDENCIAS.md) e [COLETA_INSTANCIA.md](COLETA_INSTANCIA.md).
3. Fechar contratos API/auth/tenant/RBAC antes de conversão, conforme [CONTRATOS_API.md](CONTRATOS_API.md) e [AUTENTICACAO_TENANT_RBAC.md](AUTENTICACAO_TENANT_RBAC.md).
4. Concluir metadata e qualidade da instância conforme [FASE2_IMPLEMENTACAO.md](FASE2_IMPLEMENTACAO.md); os scripts atuais não certificam schema final.
5. Executar QA e ensaios de restore/rollback somente em ambiente isolado, após aprovação separada.

## Fonte de rastreabilidade

A base de estrutura e stack vem de `AI-CONTEXT/ARCHITECTURE.md`, `AI-CONTEXT/MODULES.md`, `AI-CONTEXT/DATABASE.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SECURITY.md`, `AI-CONTEXT/DEPENDENCIES.md`, `AI-CONTEXT/TESTING.md` e `AI-CONTEXT/DEPLOY.md`, complementados por evidências externas listadas por nome e checksum no manifesto. Os caminhos de código específicos estão listados nos documentos temáticos.
