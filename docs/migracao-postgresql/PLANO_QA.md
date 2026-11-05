# Plano de QA e aceite

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.

## Escopo e evidência atual

O baseline registra checks sintáticos e harnesses locais/mocados descritos em `AI-CONTEXT/TESTING.md`, incluindo PHP, Node, fixtures MariaDB descartáveis, Playwright com APIs interceptadas e testes headless. Isso não equivale a integração autenticada real, PostgreSQL, hardware, FCM, SMTP, cron, restore, rollback ou produção. Nenhum teste operacional foi executado como parte desta documentação.

Toda evidência futura deve conter ambiente/versão, commit, tenant/papel sintético, request/response sanitizados, resultado, defeito, severidade, reconciliação e indicação de ausência de cross-tenant. Não registrar cookies, tokens, dados pessoais, SQL sensível ou segredos.

## Matriz T01–T22

| ID | Área | Cobertura mínima |
|---|---|---|
| T01 | Autenticação/HTTP | 401/403, logout, expiração, JSON estrito, CORS e Content-Type |
| T02 | RBAC | papéis, negar, grants, URL direta, stale session e fail closed |
| T03 | Tenant/integridade | tenants A/B, IDs semelhantes, CRUD, download, export, notificação, FKs/órfãos |
| T04 | Moradores | dependentes, anexos, autorização e storage |
| T05 | Veículos | vínculo, acesso, edição, exclusão e tenant |
| T06 | Visitantes/acesso | QR, temporário, ControlID e falha de canal secundário |
| T07 | Financeiro | precisão, arredondamento, relatórios e limites |
| T08 | Água/hidrômetros | leitura, agregação, datas e tenant |
| T09 | Assembleia | pai/filho, pauta, voto, participante, anexo e migration |
| T10 | GED/documentos | pastas, visibilidade, upload, stream, download e logs |
| T11 | Chat-In/Eventos/Canais | auth, idempotência, 409/503, destinatários e canais |
| T12 | Ordens de Serviço | ciclo, anexos, notificações e concorrência |
| T13 | Portal | Bearer, autoria, unidade, sessão e cross-tenant |
| T14 | PWA/FCM | cache, token, push, revogação e fallback |
| T15 | Relatórios | filtros, PDF, timezone, permissões e não vazamento |
| T16 | Upload/PDF/storage | MIME real, limite, path, autorização, restore e referência |
| T17 | Schema/dados | replay, constraints, índices, tipos, agregados e reconciliação |
| T18 | Transação/idempotência | retry, rollback de operação e efeitos secundários |
| T19 | Concorrência | locks, atualização simultânea, duplicidade e deadlock controlado |
| T20 | Performance | SLO aprovado, plano de consulta, paginação e carga representativa |
| T21 | Backup/restore/rollback | dump, globals, base backup, WAL/PITR, storage e ensaio |
| T22 | Frontend/operação | CDN, browser, cron, timezone, locks, health check, cache e logs |

## Casos negativos obrigatórios

Para todas as famílias: tenant errado, pai de outro tenant, ID filho forjado, sessão revogada, Bearer expirado, permissão negada, migration ausente, payload inválido, método não permitido, retry duplicado, upload não autorizado e provedor secundário indisponível. Esperar status e envelope documentados, sem HTML, warnings, SQL, stack trace, tokens ou PII.

## Critérios de aceite

- Contratos compatíveis ou adaptados explicitamente, sem remoção funcional silenciosa.
- Nenhum caso cross-tenant reproduzido em ambiente de dois tenants.
- Dados e objetos reconciliados por contagens/agregados aprovados; divergências explicadas.
- RPO/RTO/SLO medidos e dentro dos valores aprovados.
- Backup restaurado em ambiente separado, incluindo roles, banco, storage, referências e configuração não secreta necessária.
- Jobs idempotentes, com lock, timezone, retry e observabilidade validados.
- Defeitos P0/P1 encerrados ou aceitos formalmente por owner; Gate 4 só então.

## Limitações conhecidas

Não há evidência de browser com sessão real, Flutter/APK, hardware/Console físico, FCM/SMTP/Resend real, deploy, migration, cron efetivo, PostgreSQL, restore ou rollback. O plano não transforma os testes locais citados em homologação.

## Referências

`AI-CONTEXT/TESTING.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SECURITY.md`, `AI-CONTEXT/DEPLOY.md`; `review_operations.md`, `review_api.md`, `review_database.md` e `plano_migracao_postgresql_erp.md`.
