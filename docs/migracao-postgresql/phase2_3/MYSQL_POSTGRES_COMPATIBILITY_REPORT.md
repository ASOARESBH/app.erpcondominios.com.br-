# Fase 2.3 — Compatibilidade MySQL → PostgreSQL e riscos

**Resultado:** os nove entregáveis de análise foram produzidos. **Gate 2 fechado/não aprovado:** o catálogo instalado do MySQL de origem não foi reconciliado. PostgreSQL é o destino definitivo já definido pelo usuário; não foi criado banco ou realizado deploy nesta fase. Os documentos foram publicados em `pg/02-3-compatibilidade-riscos`, commit `4288185bb65456d682cbf2cce5b3ebab34d4b0e5`.

## 1. Resumo executivo

A análise combinou o export estrutural autorizado do anexo (7) e revisões estáticas do único repositório autorizado, `ASOARESBH/app.erpcondominios.com.br-`, com baseline de código `f60739713e6de3f2473b79ecd14a70fd700e5462`. A branch publicada `pg/02-3-compatibilidade-riscos` deriva da fase anterior sem alteração direta de `main`.

Foram realizadas 53 revisões individuais: 46 objetos únicos da união das estruturas sem tenant e candidatas a views, mais sete triggers. Há sobreposição de 18 objetos entre os grupos de 42 sem tenant e 22 view-like; os grupos não devem ser somados como objetos distintos. Quatro frentes técnicas complementaram tipos, chaves/relações, integridade e segurança, seguidas de síntese e QA.

Os arquivos distinguem export estrutural, análise estática do código, hipótese/recomendação e catálogo live **não confirmado**. Não foram executados SQL, importação, conversão de triggers, migrations ou testes de dados nesta fase. APIs, aplicação, MySQL e VM não foram alterados. A publicação documental está registrada no commit informado acima; `main` não foi alterada.

## 2. Cobertura e métricas

| Item | Quantidade | Interpretação |
|---|---:|---|
| CREATE TABLE / ALTER TABLE | 270 / 489 | Declarações no export, não catálogo final instalado. |
| Colunas CREATE mapeadas | 3.339 | Recomendações advisory; 232 MODIFY não foram reproduzidos no estado inicial pelo parser. |
| Estruturas com/sem tenant_id | 228 / 42 | Classificação exige contrato de domínio, não preenchimento automático. |
| Candidatos view-like | 22 | Todos aparecem como CREATE TABLE; natureza instalada desconhecida. |
| Triggers documentados | 7 | Regra exportada, instalação live desconhecida. |
| ADD PRIMARY KEY / ADD UNIQUE / ADD INDEX | 240 / 70 / 193 | Eventos estruturais; não prova presença nem ausência instalada. |
| FKs explícitas / relações lógicas adicionais | 11 / 25 | JOIN estático não equivale a FK nem cobertura integral de relações. |
| Auditoria de PK/índices | 270 linhas | Uma por objeto exportado, inclusive candidatos a views. |
| Classificação tenant | 42 linhas | Proposta estática com justificativa e risco. |
| Inventário sensível suplementar | 128 colunas | Somente nomes/tipos/localização e proteção recomendada. |

### AUTO_INCREMENT e estado parcial

As **466 referências** citadas no mandato, as **516 ocorrências lexicais** encontradas em um conjunto de fontes do repositório (SQL/PHP/Python) e os **232 MODIFY com atributo AUTO_INCREMENT** do export medem corpora/unidades diferentes. Não há reconciliação numérica entre elas e nenhuma deve ser tratada como quantidade definitiva de identities PostgreSQL.

`not_replayed` significa que o catalogador **não aplicou os eventos ALTER sobre as declarações CREATE iniciais**. Não significa que o HostGator deixou de aplicar uma migration. Nenhum `SHOW CREATE` individual ou resultado confirmado de catálogo live foi obtido nesta fase. A situação instalada continua desconhecida.

## 3. Bloqueadores P0 antes da Fase 3

1. **Reconciliação da origem MySQL.** Confirmar TABLE_TYPE, colunas finais, tipos, nullabilidade, PK/UK/índices/FKs, views, triggers e demais rotinas com visibilidade suficiente. A ausência de objetos em um export não prova inexistência instalada.
2. **Contrato multi-tenant.** Decidir os 42 escopos e revisar dependências transitivas — principalmente `documentos_usuarios_acesso`, `monitoramento_sessoes` e `rbac_grupo_permissoes`. No CREATE inicial há sete tenant_id nullable e 161 defaults legados de tenant 1. Isso é risco estrutural, não prova de vazamento.
3. **Natureza das 22 candidatas a views.** Resolver conflito entre fontes estáticas e export CREATE TABLE. Sem TABLE_TYPE/SHOW CREATE, não criar tabela física substituta ou view PostgreSQL. Algumas candidatas não têm definição estática recuperada.
4. **Regras dos sete triggers.** Preservar transições, alvos e efeitos; confirmar instalação/ordem. Tenant-aware, deduplicação e controle de concorrência são ajustes de segurança/regra deliberados, não equivalência automática de sintaxe.
5. **Tipos e serialização.** Aprovar UNSIGNED/BIGINT, ENUM, DECIMAL, collation, temporais/zero-date, JSON/JSONB, binários e ON UPDATE. MySQL TIME pode ser duração negativa/superior a 24h. Inteiros fora da faixa PHP exigem strings decimais no PDO/JSON, não float/binding inteiro automático.

## 4. Achados estáticos que exigem revisão

| Frente | Achado de planejamento | Ação necessária |
|---|---|---|
| GED | Relação de acesso documento/usuário não tem tenant_id direto no export; isolamento depende dos pais e caminhos de autorização. | Comprovar escopo de leitura/escrita e vínculo dos dois pais, antes de impor tenant/constraints. |
| RBAC | Catálogos globais coexistem com relações de grupo e usuário tenant-scoped/transitivas; helper possui caminhos condicionais de indisponibilidade. | Preservar semântica global e garantir autorização fail-closed no adapter futuro. |
| Logs | Escritores estáticos usam contratos de colunas diferentes; existem consultas globais e nomenclatura tenant cuja filtragem precisa ser revisada. | Reconciliar schema e política de visibilidade/retenção; não concluir incidente live. |
| Checklist | Fechamento aberto→fechado incrementa KM por categoria; a API consome/resetará o acumulado. | Não duplicar incremento na API e trigger; validar escopo tenant e concorrência. |
| Dependentes/veículos | Há cascatas de ativação, inativação e exclusão distintas. | Analisar o conjunto: a inativação sozinha não reativa, mas o trigger de ativação faz a transição inversa. |
| Estoque | Trigger gera alerta a cada UPDATE qualificado, não recalcula estoque e não cobre INSERT inicial. | Não introduzir deduplicação/transição-only como se fosse mera portabilidade. |
| QR/dispositivos | Espelhamento de token corrente e contador por INSERT de log têm efeitos implícitos e podem não ser idempotentes. | Revisar tenant, produtor PHP, repetição e ordenamento concorrente. |
| Views estatísticas | Agregações e joins podem alterar cardinalidade; contrato estático nem sempre coincide com colunas exportadas. | Comparar resultado/shape em QA sintético, sem depender de ordenação interna de view. |
| Segurança | Credenciais/tokens/sessões/auditoria têm proteção e escopo não uniformemente demonstrados nos trechos estáticos. | Least privilege, minimização, criptografia com chaves separadas e rotação/versionamento; nenhum segredo foi coletado. |

Ausência de predicado em um trecho estático é indicação de revisão, não prova de que a aplicação em produção expôs dados. Consumidores legados encontrados no código não comprovam rota atualmente carregada.

## 5. P1 e decisões operacionais

Validar qualidade somente por contagens agregadas, uma tabela/relação por vez, após conferência de metadados, índices, privilégios e custo. As consultas suplementares estão em **dialeto MySQL/Percona 5.7**, com placeholders e proibição de execução como lote. Nenhuma contagem de registros foi obtida. Os testes pós-carga PostgreSQL serão separados, após autorização de QA.

RPO/RTO, janela, freeze/CDC se aplicável, validação e rollback pós-escrita continuam pendentes de aprovação operacional. A arquitetura anterior já possui plano de backup/rollback; esta fase não testou nem implantou essas rotinas. RLS não substitui filtros tenant/RBAC e requer papel runtime sem bypass e testes dedicados se adotada.

## 6. Entregáveis

1. [Matriz de tipos](MYSQL_POSTGRES_TYPE_MAPPING.csv).
2. Este relatório executivo.
3. [Auditoria de PK/índices](PRIMARY_KEYS_AND_INDEXES_AUDIT.csv).
4. [FKs e relações](FOREIGN_KEYS_AND_RELATIONSHIPS.csv).
5. [Mapa das 22 candidatas a views](VIEWS_DEPENDENCY_MAP.md).
6. [Regras dos sete triggers](TRIGGERS_BUSINESS_RULES.md).
7. [Classificação das 42 estruturas sem tenant](TENANT_CLASSIFICATION_DETAILED.csv).
8. [Integridade e custo](DATA_INTEGRITY_RISKS.md).
9. [Decisões e gates](POSTGRES_MIGRATION_DECISIONS.md).

Suplementos: `QUALITY_QUERIES_REVIEW_ONLY.sql`, `SECURITY_COLUMNS_METADATA.csv`, README, validação final e checksums. Não contêm dados de registros, corpos DDL, defaults literais, DEFINER ou credenciais.

## 7. Próxima etapa segura

Obter, por canal autorizado, o catálogo MySQL real, definições de views/rotinas/triggers e ledger de migrations. Reconciliar com o export, aprovar classificação/semântica e qualidade agregada. Só então detalhar DDL PostgreSQL e adapter PHP em branches e QA separados. **Os entregáveis de análise estão completos; a reconciliação live e o Gate 2 não estão concluídos.**
