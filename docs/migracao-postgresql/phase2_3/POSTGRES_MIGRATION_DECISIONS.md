# Fase 2.3 — Decisões de migração PostgreSQL

> **Estado:** decisões de planejamento e gates; nenhum item abaixo autoriza DDL, replay, importação ou deploy.

## 1. Decisões aceitas nesta fase

| Tema | Decisão aceita | Base/limite |
|---|---|---|
| Banco de destino | PostgreSQL definitivo; MySQL/Percona do HostGator é a origem dos dados. | Decisão expressa do usuário; nenhum banco PostgreSQL foi criado nesta fase. |
| Escopo | Manter a atividade como inventário, análise estática e planejamento; não alterar aplicação, API, banco, VM, `main` ou ambiente live. | Mandato da fase; nenhuma mutação foi executada. |
| Fonte de verdade | Tratar o catálogo live individual como autoridade futura; o export estrutural e as revisões são advisory. | Consultas anteriores expiraram; nenhum `SHOW CREATE` individual foi obtido. |
| Tenant | Não adicionar `tenant_id` automaticamente. Classificar cada estrutura por evidência e preservar escopos transitivos somente após validação. | 42 estruturas sem `tenant_id`; classificação em CSV é proposta estática. |
| Views | Não substituir views por tabelas físicas por nome, export ou hipótese. Recriar como view somente se `TABLE_TYPE`/definição live confirmarem essa natureza e o contrato. | 22 candidatos view-like; natureza live desconhecida. |
| Triggers | Não converter nem instalar nesta fase. Preservar regra apenas como narrativa PL/pgSQL condicionada a esquema, ordem e tenant confirmados. | Sete triggers com DML interno; instalação live não confirmada. |
| DDL | Não gerar migration definitiva, `CREATE TABLE`, `ALTER`, FK, UNIQUE, `NOT NULL`, sequence/identity ou view PostgreSQL executável. | Export contém 489 alterações catalogadas como não replayadas. |
| Qualidade | Consultas futuras devem ser somente SELECT agregado, uma tabela/relação por vez, com metadata-only e revisão offline de custo. | Templates existentes não foram executados. |
| Segurança | Não expor segredos, tokens, `DEFINER`, corpos DDL, literais ou dados pessoais; usar least privilege em eventual etapa live autorizada. | Restrição explícita da tarefa. |

## 2. PostgreSQL definido — parâmetros técnicos pendentes

A versão major/minor do PostgreSQL, extensões permitidas, encoding, locale/collation, timezone de sessão/servidor, schema alvo, roles, privilégios, estratégia de identidade/sequence, política de RLS e limites de recursos **NÃO ESTÃO DEFINIDOS**. Não é permitido inferir esses parâmetros do export MySQL ou aprovar conversões antes de uma decisão de arquitetura/infraestrutura.

## 3. Políticas de tipos — pendentes de aprovação

| Família | Política provisória | Pendência bloqueante |
|---|---|---|
| INT/BIGINT e UNSIGNED | Não converter cegamente; avaliar `integer`, `bigint`, `numeric(20,0)` e CHECK somente após limites/contrato. | Faixas live e uso PHP/PDO não confirmados. |
| TINYINT(1) | Manter como semântica não decidida; não converter automaticamente para boolean. | Uso pode ser flag, tri-state ou contador. |
| AUTO_INCREMENT | Mapear somente após reconciliar PK, eventos MODIFY e sequência/identity live. | 466 referências lexicais do mandato, 516 ocorrências lexicais privadas e 232 MODIFY não são a mesma métrica. |
| ENUM | Não criar enum/CHECK sem labels completos e política de evolução. | Labels não foram aprovados como contrato. |
| DECIMAL | Preservar precisão/escala quando comprovadas; sem arredondamento implícito. | Domínios e colunas live precisam de confirmação. |
| DATETIME/TIMESTAMP | Decidir `timestamp without time zone` versus `with time zone`, precisão e timezone. | Contrato temporal e zero-date não confirmados. |
| TIME | Usar `interval` para duração ou `time` para horário somente após confirmar o domínio. | MySQL TIME pode ser negativo ou exceder 24h; conversão automática perderia semântica. |
| ON UPDATE | Não transportar automaticamente; modelar em aplicação, trigger ou default somente após decisão. | Eventos/uso real não reconciliados. |
| JSON/TEXT/BLOB | Escolher json/jsonb/text/bytea conforme operadores, igualdade, índice, tamanho e streaming. | Operadores e payloads não foram testados. |
| CHAR/VARCHAR/collation | Validar trailing spaces, acentuação, case-insensitive e collation alvo. | Locale/collation PostgreSQL não definidos. |

## 4. Ordem provisória de migração (não executável)

1. Aprovar ambiente PostgreSQL, schema, roles, privilégios, encoding/collation, timezone, observabilidade e política de rollback.
2. Obter metadados live individualmente e reconciliar os 270 objetos, 489 eventos, 240 PKs, 70 uniques, 193 índices e 11 FKs catalogados.
3. Classificar as 42 estruturas sem `tenant_id` e validar escopos globais/administrativos/tenant/transitivos/auditoria; revisar as 22 views candidatas e sete triggers.
4. Validar tipos, defaults, temporal/JSON/ENUM, chaves e índices; executar somente consultas agregadas aprovadas após plano offline.
5. Definir e validar relações-base, PK/UK/índices; carregar/transformar dados em plano aprovado, sem impor integridade que os dados não suportem.
6. Validar órfãos, duplicidades, NULL, mismatch tenant e cardinalidade; somente então considerar FKs, constraints e regras derivadas.
7. Recriar views e triggers confirmados, em ordem topológica; revisar concorrência e dupla responsabilidade com a aplicação.
8. Executar testes de contrato PHP/PDO, autorização, tenant, auditoria, performance e rollback em ambiente aprovado; somente depois decidir cutover.

A ordem é uma proposta de dependência. Não existe ordem final/live aprovada enquanto `TABLE_TYPE`, `SHOW CREATE`, chaves, dados, permissões e dependências não forem confirmados.

## 5. RPO/RTO, cutover e rollback — não definidos

| Item | Estado | Gate necessário |
|---|---|---|
| RPO | **NÃO DEFINIDO** | Dono do produto/operação deve aprovar perda máxima de dados e mecanismo de captura/validação. |
| RTO | **NÃO DEFINIDO** | Dono do produto/operação deve aprovar tempo máximo de indisponibilidade e restauração. |
| Cutover | **PENDENTE** | Definir janela, freeze, CDC/replay se aplicável, validação e autorização de retorno. |
| Rollback | **PENDENTE** | Definir ponto de retorno, retenção, testes e critério objetivo de abortar. |
| Observabilidade | **PENDENTE** | Métricas agregadas, logs sem segredos, alertas e responsáveis. |

Nenhum RPO/RTO foi inventado a partir dos artefatos privados. Sem essas metas, não há plano operacional aprovado.

## 5.1 Distinção de origem, parser e destino

`not_replayed` descreve o catálogo parcial: o parser não aplicou ALTERs sobre as declarações CREATE iniciais. Não comprova migration ausente no HostGator. Reconciliação live nesta fase refere-se ao MySQL de origem; catálogos PostgreSQL só existirão após implantação autorizada de QA.

Os templates de qualidade são MySQL/Percona 5.7, somente para revisão. Valores fora da faixa de inteiro PHP exigem strings decimais no PDO/JSON, não float ou binding inteiro automático. Mudanças de escopo tenant, deduplicação de alertas e adoção de RLS são decisões deliberadas, não mera tradução de sintaxe.

## 6. Gate 2

**FECHADO / NÃO APROVADO.** Condições ainda abertas: (a) catálogo live individual; (b) TABLE_TYPE/SHOW CREATE das 22 candidatas; (c) instalação/ordem dos sete triggers; (d) classificação e isolamento das 42 estruturas; (e) reconciliação de PK/UK/índices/FKs; (f) política de tipos e collation/timezone; (g) consultas agregadas de qualidade; (h) segurança/roles; (i) RPO/RTO, cutover e rollback. A Fase 3 está bloqueada para DDL, replay, importação, migração e deploy.

Contagens de referência do export: 270 tabelas, 42 sem `tenant_id`, 22 view-like, 7 triggers, 489 ALTER TABLE e 11 FKs explícitas. Esses números não são confirmação live.
