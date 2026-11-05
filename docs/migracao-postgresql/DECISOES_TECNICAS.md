# Decisões técnicas e parâmetros

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.
>
> **Status das decisões:** as decisões abaixo distinguem o que foi definido pelo usuário, o que é regra de planejamento e o que ainda aguarda aprovação.

## Decisões já definidas

| ID | Decisão | Estado |
|---|---|---|
| D-001 | PostgreSQL é o destino da aplicação. | Definida pelo usuário; não reabrir MySQL operacional como alternativa. |
| D-002 | Fonte de código é `ASOARESBH/app.erpcondominios.com.br-` no commit `f60739713e6de3f2473b79ecd14a70fd700e5462`. | Definida e congelada para o baseline. |
| D-003 | ZIP/HostGator é somente evidência de comparação. | Definida; não promover ZIP, uploads, dumps ou runtime externo como código. |
| D-004 | Preservar funcionalidades e contratos observáveis. | Definida como objetivo de aceite. |
| D-005 | Esta fase é documentação de planejamento. | Definida; sem deploy, migration, SSH, produção ou alteração de código/app/banco/runtime. |
| D-006 | Não publicar dados pessoais, credenciais, tokens ou conteúdo de linha. | Definida como regra de segurança documental. |

## Decisões técnicas pendentes de aprovação

| Tema | Opções a decidir | Dependência/evidência |
|---|---|---|
| PostgreSQL | versão e extensões permitidas | owner da plataforma e requisito funcional |
| Conexão PHP | PDO_PGSQL, driver/adapter e pool | runtime reproduzível e testes SQLSTATE |
| Identidade | `identity` versus sequence | maior ID, carga e ownership |
| `UNSIGNED` | tipo maior, `CHECK` ou domínio | limites reais e overflow |
| `ENUM` | enum nativo, `CHECK` ou domínio | catálogo e valores legados |
| JSON | `json` ou `jsonb` | consultas, índices e semântica |
| Temporal | timestamp com/sem timezone, precisão | uso, timezone e zero-date |
| Collation | locale/collation e case-folding | equivalência de busca/índice |
| `ON UPDATE` | trigger PostgreSQL ou aplicação | concorrência e auditoria |
| Prefix index | índice completo, expressão ou normalização | seletividade e limites |
| Tenant | FKs/chaves compostas/índices tenant-first | classificação das 42 tabelas e qualidade |
| Triggers | PL/pgSQL ou aplicação | definições reais e testes |
| Jobs | scheduler, timezone, lock e retry | host alvo e SLO |
| Backup | retenção, imutabilidade, WAL/PITR | RPO/RTO e storage externo |
| Cutover | janela, go/no-go e abort owner | ensaios e autorização operacional |

## Regras de implementação propostas

- Não converter por substituição mecânica; usar crosswalk semântico e replay ordenado de DDL.
- Não adicionar FK, `CHECK` ou `UNIQUE` sem auditoria de órfãos, duplicidades e NULLs.
- Não confiar em `tenant_id` do cliente; derivar identidade/tenant no servidor.
- Não liberar operações sensíveis quando migration, política RBAC ou sessão não puderem ser validada.
- Não expor SQL, exceções, tokens ou PII em resposta, log ou documento.
- Manter adaptadores para contratos modernos, legados e ControlID durante a convergência.
- Usar ambiente descartável, fixtures anonimizadas e dois tenants para testes.

## Não decisões

Este registro não escolhe versão PostgreSQL, não afirma PHP 8.3/PDO_PGSQL/Nginx/PHP-FPM instalados, não aprova arquitetura de VM, não define RPO/RTO, não autoriza SSH, não aprova migrations executáveis, não aprova cutover e não confirma estado do HostGator. Também não classifica definitivamente as tabelas sem `tenant_id` nem inventa views/procedures ausentes do schema-only.

## Registro de mudança

Qualquer alteração deve indicar ID, data, aprovador, justificativa, evidência, impacto funcional, risco, rollback e documento afetado. A branch desta Fase 1 deriva do commit oficial; os SHA de documentação devem ser consultados no histórico Git, sem self-reference no próprio arquivo.

## Referências

`BASELINE.md`, `ARQUITETURA_POSTGRESQL.md`, `MAPEAMENTO_BANCO.md`, `AUTENTICACAO_TENANT_RBAC.md`, `PLANO_MIGRACAO.md`, `PLANO_BACKUP_ROLLBACK.md`; `review_database.md`, `review_operations.md` e `plano_migracao_postgresql_erp.md`.
