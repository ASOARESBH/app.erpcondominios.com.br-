# Migração PostgreSQL — documentação de planejamento

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial de código:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.
>
> **Branch:** `pg/00-baseline-contratos`, criada em worktree isolado diretamente do commit acima. Os arquivos desta pasta são documentação e inventário da Fase 1; não há alterações de aplicação.

## Objetivo e limites

Esta pasta registra o baseline técnico e o plano de trabalho para preservar as funcionalidades do ERP Condomínios durante a migração para PostgreSQL. PostgreSQL é o destino definido pelo usuário e não está sendo reaberto como alternativa operacional. Os parâmetros de conversão, o runtime alvo, a janela de mudança, RPO/RTO e demais decisões de implementação permanecem sujeitos a aprovação.

A documentação usa exclusivamente o commit oficial e evidências estruturadas identificadas por nome e checksum em [baseline-manifest.json](baseline-manifest.json). Esses relatórios/schema estão fora do repositório e não são copiados para esta branch. O ZIP/HostGator é evidência de comparação e contexto, não fonte de código a ser promovida. Não foram usados dados de linha, credenciais, tokens, uploads, logs operacionais, SSH, banco ou produção.

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
| [inventario_sintaxe_mysql.csv](inventario_sintaxe_mysql.csv) | Catálogo estático `arquivo,linha,categoria`, indexado por `MAPEAMENTO_BANCO.md`. |

## Convenções

- **Fato:** diretamente observável no commit, em um relatório estruturado ou no artefato citado.
- **Inferência:** interpretação técnica útil para planejamento, que ainda requer validação.
- **Desconhecido:** informação não obtida e que não deve ser preenchida por suposição.
- Contagens de caminhos, ocorrências ou assinaturas são sinais de busca; não equivalem a rotas únicas, objetos instalados, linhas de dados ou comportamento em produção.
- Nenhum documento autoriza alteração de código aplicativo, schema/migration executável, API, runtime, VM, banco ou dados.

## Próximos passos imediatos

1. Aprovar os parâmetros PostgreSQL e a matriz de compatibilidade em [DECISOES_TECNICAS.md](DECISOES_TECNICAS.md).
2. Obter, por canal autorizado e read-only, exports de metadados, objetos e qualidade agregada descritos em [PENDENCIAS.md](PENDENCIAS.md).
3. Fechar contratos API/auth/tenant/RBAC antes de conversão, conforme [CONTRATOS_API.md](CONTRATOS_API.md) e [AUTENTICACAO_TENANT_RBAC.md](AUTENTICACAO_TENANT_RBAC.md).
4. Executar QA e ensaios de restore/rollback somente em ambiente isolado, após aprovação separada.

## Fonte de rastreabilidade

A base de estrutura e stack vem de `AI-CONTEXT/ARCHITECTURE.md`, `AI-CONTEXT/MODULES.md`, `AI-CONTEXT/DATABASE.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SECURITY.md`, `AI-CONTEXT/DEPENDENCIES.md`, `AI-CONTEXT/TESTING.md` e `AI-CONTEXT/DEPLOY.md`, complementados por evidências externas listadas por nome e checksum no manifesto. Os caminhos de código específicos estão listados nos documentos temáticos.
