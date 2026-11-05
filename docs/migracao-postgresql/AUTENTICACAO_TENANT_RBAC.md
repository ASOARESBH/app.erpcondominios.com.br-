# Autenticação, tenant e RBAC

> **Estado:** documentação de planejamento. Não houve deploy, migration, tentativa de SSH, acesso à VM/ao banco/ao ambiente de produção, importação de dados, alteração de runtime ou alteração do código aplicativo.
>
> **Fonte oficial:** `ASOARESBH/app.erpcondominios.com.br-`, commit `f60739713e6de3f2473b79ecd14a70fd700e5462`.

## Superfícies de identidade

| Superfície | Evidência observada | Regra de preservação |
|---|---|---|
| Painel web | Sessão PHP/PHPSESSID, `credentials: include`, `auth_helper.php` | Sessão, expiração e tenant derivados no servidor. |
| Portal do morador | Bearer em `sessoes_portal` | Cruzar token hashado, morador ativo e tenant; não confiar no payload. |
| Portal/mobile colaborador | Bearer opaco em `sessoes_colaborador_mobile` | Sessão carrega usuário/tenant e exige `usuario_tenant` ativo. |
| Console/QR | Token de dispositivo ativo | Toda ação exige gate do dispositivo e tenant. |
| ControlID | Chamada de hardware sem sessão PHP | Resolver dispositivo, pessoa/veículo e tenant por vínculos persistidos. |

JWT, quando presente, é uma superfície própria; não deve ser misturado ao token de portal. Nenhum tenant enviado pelo cliente deve ser autoridade de escopo.

## Invariantes de tenant

- Derivar tenant da identidade autenticada ou do dispositivo autorizado.
- Resolver o pai com `tenant_id + id` antes de consultar ou alterar filho.
- Aplicar o mesmo tenant a listagem, detalhe, criação, atualização, exclusão, upload, download, relatório, voto, participante e notificação.
- Revalidar `morador_id`, `unidade`, `colaborador_id`, autoria e departamento recebidos no payload.
- Rejeitar contexto ausente, ambíguo ou revogado; não usar tenant 1 como default operacional.
- Não assumir que uma menção a `tenant_id` no arquivo prova isolamento de todas as queries.

## RBAC

`rbac_helper.php` documenta permissões por módulo/ação, precedência de negar sobre permitir, sessão RBAC e auditoria. A autorização deve ocorrer no servidor; frontend somente oculta ou navega. A ausência de permissão deve retornar 403 estruturado.

Há uma fragilidade de compatibilidade: quando tabelas/políticas não estão disponíveis ou a preparação falha, o modo compatibilidade pode retornar permitido. Para a migração, isso deve ser substituído por fail closed em escrita, exclusão, configuração, upload, exportação e integrações, mantendo eventuais exceções em allowlist explícita, auditada e temporária.

## Riscos prioritários

- **P0 Assembleia:** sub-recursos não revalidam consistentemente o pai no tenant; migration inline de anexos conflita com as consultas; ação de migration aparece antes de autorização administrativa específica.
- **P0 Console/ControlID:** existem ações que não demonstram gate uniforme de dispositivo/tenant; identificadores globais de dispositivo/IP/veículo não foram comprovados como únicos.
- **P1 Portal Documentos:** tenant não é propagado explicitamente em várias queries; pastas, documentos, acessos e logs precisam de escopo explícito e Bearer sem query string.
- **P1 sessão/RBAC:** falha de validação ou banco pode ser tratada como não bloqueante. Definir comportamento de segurança durante indisponibilidade.
- **P1 schema:** 42 tabelas do snapshot não têm `tenant_id`; 22 são nomes view-like e o restante requer classificação por contrato. Há defaults `tenant_id=1` e poucas FKs explícitas para tenants.

## Suíte negativa mínima

Dois tenants com IDs e dados semelhantes; papéis visualizador/operador/gerente/admin; sessão expirada/revogada; Bearer de outro tenant; token de dispositivo inválido; pai de outro tenant; IDs filhos forjados; upload/download cruzado; exportação/relatório; notificação e retry; migration ausente; falha de RBAC; 401/403/404/409/503; e ausência de vazamento em headers, logs e mensagens.

## Referências

`AI-CONTEXT/SECURITY.md`, `AI-CONTEXT/API.md`, `AI-CONTEXT/SERVICES.md`, `AI-CONTEXT/CHATIN.md`; `api/auth_helper.php`, `api/tenant_helper.php`, `api/rbac_helper.php`, `api/jwt_handler.php`, `api/session_helper.php`, `api/api_portal_alertas.php`, `api/api_portal_convidados.php`, `api/api_portal_documentos.php`, `api/api_colaborador_mobile.php`, `api/api_console_acesso.php`, `api/api_assembleia.php`, `api/controlid/_helper.php`; `review_api.md` e `review_database.md`.
