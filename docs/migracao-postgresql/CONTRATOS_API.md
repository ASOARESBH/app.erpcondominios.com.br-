# Contratos API e compatibilidade

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.
>
> **Regra de destino:** PostgreSQL é definido pelo usuário; esta documentação preserva contratos enquanto a camada de persistência é convertida.

## Padrão de roteamento observado

O padrão dominante é path PHP direto, como `/api/api_nome_modulo.php?acao=nome_da_acao`, com variantes `action`, GET/POST/PUT/DELETE, JSON em `php://input` e multipart para upload. Um path pode expor várias ações e uma ação pode aceitar métodos diferentes. Há também endpoints diretos de login, sessão, PDF, imagem, download, logs e hardware.

A contagem heurística de assinaturas path+ação é apenas limite inferior amplo: inclui aliases, comentários, ações por método e falsos positivos. Não existe, neste baseline, um catálogo definitivo de rotas únicas; isso requer confirmação por consumidores, rewrite, métodos e execução controlada.

## Envelopes e status

| Família | Resposta observada | Observação de compatibilidade |
|---|---|---|
| APIs modernas | `{sucesso, mensagem, dados}` e, às vezes, `codigo` | Base para novas ações; erro continua JSON em 4xx/5xx. |
| `ApiBase` | `{success, message, data, error}` | Adaptador legado em inglês. |
| Assembleia | `{ok, erro}` ou `{ok, dados}` | Deve ser preservado temporariamente ou adaptado explicitamente. |
| ControlID | Payload físico ou `{}` | Contrato de dispositivo; não forçar envelope ERP. |
| Migrations ausentes | 409/503 em módulos | Deve falhar fechado, sem liberar operação sensível. |

Os status observados ou planejados incluem 401, 403, 405, 409, 422 e 503. Clientes devem tratar `sucesso:false` mesmo quando o HTTP é 4xx. Mensagens não devem expor SQL, stack trace, exceção, caminho local, token ou dado pessoal; há pontos identificados em Assembleia/GED que precisam ser corrigidos antes do aceite.

## Famílias funcionais

- **Painel administrativo:** sessão PHP, RBAC por ação, CRUDs e relatórios.
- **Portal/App/Mobile:** Bearer opaco ou sessão específica; identidade e tenant derivados do servidor.
- **Console/QR:** token de dispositivo; toda ação deve passar pelo gate de dispositivo e tenant.
- **ControlID:** superfície sem sessão PHP, com resposta própria do equipamento e vínculo dispositivo → tenant → pessoa/veículo.
- **Uploads/downloads:** storage tenant-scoped, autorização no backend, MIME/tamanho/nome e stream protegido.
- **Notificações:** persistência primária separada de FCM/PWA, e-mail, Chat-In e provedores externos.

## Pontos críticos do baseline

1. **Assembleia:** pai recebe tenant em algumas consultas, mas pautas, votos, participantes e anexos precisam revalidar o pai no mesmo tenant. A migration inline e as queries de `assembleia_anexos` estão desalinhadas; a ação de migration precisa de autorização administrativa explícita.
2. **Portal Documentos:** o arquivo canônico não demonstra tenant-scoping explícito em pastas, documentos, acessos e logs; token em query string é fallback inseguro. Fechar antes de considerar o GED isolado.
3. **SQL heterogêneo:** coexistem prepared statements, `query()`, `real_escape_string` e interpolação. A triagem de 131 candidatos canônicos e do CSV não substitui auditoria por query.
4. **CORS:** APIs modernas usam allowlist em parte da árvore; Assembleia, GED, Portal Documentos e hardware têm wildcard/fallback permissivo. Definir política por superfície, com `Vary: Origin` quando aplicável.
5. **Git-only:** Chat-In, Eventos e Canais exigem alinhamento simultâneo de API, helpers, frontend, schema/migration e configuração por tenant.

## Matriz de contrato a completar

Para cada `path + método + ação`, registrar: request e Content-Type; envelope; status de sucesso/erro; autenticação; tenant derivado; RBAC; relação pai/filho; idempotência; upload/download; efeitos secundários; migration necessária; consumidor frontend; e teste negativo cross-tenant.

O registro deverá separar rotas confirmadas de aliases ou sinais textuais. A preservação funcional exige adaptar clientes legados, não remover silenciosamente ações ou alterar nomes sem versão/compatibilidade.

## Fontes

`AI-CONTEXT/API.md`, `AI-CONTEXT/BACKEND.md`, `AI-CONTEXT/HELPERS.md`; `api/api_base.php`, `api/api_assembleia.php`, `api/api_documentos.php`, `api/api_portal_documentos.php`, `api/api_chatin.php`, `api/api_eventos.php`, `api/api_notificacao_canais.php`, `api/controlid/_helper.php`, `api/helpers/tenant_file_storage_helper.php`; evidências externas `review_api.md` e `review_frontend.md` listadas em [baseline-manifest.json](baseline-manifest.json), não incluídas nesta branch.
