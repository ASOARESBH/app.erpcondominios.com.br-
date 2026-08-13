# Hotfix — 401 Conflitante Após Login

## Causa confirmada

O console mostrou duas conclusões aparentemente contraditórias: o `AuthGuard` autorizava o layout, mas o `SessionManager` recebia HTTP `401` da mesma API e redirecionava ao login.

A causa era um `portal_token` residual no `localStorage` ou cookie do Portal do Morador. O `SessionManager` enviava esse token como `Authorization: Bearer`, e a API central priorizava a sessão de portal sobre a sessão PHP do ERP. Quando o token estava expirado, a API retornava `401`, mesmo com `PHPSESSID` ERP válido. O `AuthGuard` não envia Bearer e, por isso, retornava autorizado.

## Correções incluídas

| Arquivo | Ajuste |
|---|---|
| `frontend/js/session-manager-core.js` | Envia Bearer somente nas páginas de Portal; no ERP usa exclusivamente o cookie `PHPSESSID`. Um único 401/403 é confirmado após 1,2 s antes de permitir logout. |
| `api/verificar_sessao_completa.php` | Prioriza a sessão ERP válida antes de analisar um token de Portal residual. |
| `frontend/login.html` | Ao efetuar login ERP, limpa tokens e dados locais do Portal do Morador. |

## Implantação

Envie o pacote para `public_html`, preservando a estrutura de pastas. Não há SQL nesta entrega.

Depois do upload, use `Ctrl+F5` na página de login. Recomenda-se limpar o armazenamento antigo uma única vez: abra o Console do navegador e execute `localStorage.removeItem('portal_token')`, então recarregue e faça login no ERP. O próprio hotfix fará essa limpeza automaticamente nos próximos logins ERP.

## Resultado esperado

O console deve registrar `Sessão ativa` e não pode mais apresentar o par contraditório **`AuthGuard: Acesso autorizado`** seguido de **`SessionManager: 401`**. Um logout só ocorrerá após dois retornos consecutivos `401` ou `403` da API central.
