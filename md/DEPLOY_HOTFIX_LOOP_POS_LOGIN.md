# Hotfix — Estabilização do Login e da Sessão

## Causa corrigida

O loop pós-login era possível quando a API de sessão retornava uma falha interna com HTTP `200` e um JSON negativo ou inválido. O `auth-guard.js` e o `session-manager-core.js` interpretavam essa resposta como logout, redirecionavam para o login e criavam o ciclo `login → layout → login` mesmo quando o cookie da sessão ainda era válido.

A tela de login também consultava um endpoint legado (`verificar_sessao.php`) em paralelo ao fluxo principal. Essa consulta poderia competir com o redirecionamento gerado pelo login recém-concluído.

## Correções incluídas

| Arquivo | Ajuste |
|---|---|
| `frontend/js/auth-guard.js` | Redireciona somente quando a API confirma HTTP `401` ou `403`. Respostas negativas com HTTP `200`, falhas de rede e timeouts mantêm a página atual. |
| `frontend/js/session-manager-core.js` | Trata JSON inválido, falhas internas e indisponibilidade como condição transitória; somente `401`/`403` podem encerrar a sessão e redirecionar. |
| `frontend/login.html` | Substitui a checagem legada pela API central `verificar_sessao_completa.php`, evita corridas de redirecionamento e preserva o destino Super-Admin quando aplicável. |

## Implantação

Envie o conteúdo do pacote para `public_html`, preservando a estrutura `frontend/`. Não há migration de banco para este hotfix.

Após o upload, abra uma janela anônima ou pressione `Ctrl+F5` na tela de login. Entre normalmente e valide que a navegação permanece no dashboard ou no Painel Super-Admin, sem retornar automaticamente ao login.

## Diagnóstico adicional

No console, ocorrências de timeout ou resposta interna de sessão passam a aparecer como avisos identificáveis, sem causar logout automático. Uma resposta real de sessão expirada continuará retornando HTTP `401` ou `403` e levará ao login de modo controlado.
