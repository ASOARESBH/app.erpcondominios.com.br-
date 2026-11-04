# Incidente: aba Visitantes do app não mostra todos os visitantes da Gleba (2026-11-04)

## Sintoma
No aplicativo Flutter (`aplicativoerpcondominios`), a aba **Visitantes** listava apenas parte dos visitantes da Gleba do morador. Bloqueador para publicação do APK na Google Play.

## Fluxo analisado
| Camada | Arquivo | Comportamento |
|---|---|---|
| App | `lib/presentation/screens/visitors/visitors_screen.dart` | `GET /api/api_portal_morador.php?action=visitantes`, sem paginação; exibe tudo que vier em `dados` |
| App | `lib/presentation/screens/access/access_screen.dart` | Reutiliza a mesma lista no seletor de visitante do QR |
| API | `api/api_portal_morador.php` | `WHERE tenant_id = ? AND morador_id = ?` |
| API | `api/api_acessos_visitantes.php` (POST) | Validava `morador_id = sessão OR cadastrado_por_morador_id = sessão` |
| Banco | `visitantes` | Não possui coluna de unidade; vínculo com a Gleba é derivado do morador |

## Causa raiz
O app não tinha limite nem filtro: o corte estava no backend, que restringia a lista ao **morador logado**. Ficavam de fora:
1. visitantes cadastrados por outro morador da mesma Gleba (ex.: cônjuge com login próprio);
2. visitantes da base geral cadastrados pela portaria/painel (`cadastrado_por_tipo = FUNCIONARIO`, `morador_id` NULL) já autorizados para a Gleba em `acessos_visitantes`.
A geração de QR tinha a mesma restrição, então esses visitantes também não podiam ser autorizados.

## Decisão
- Escopo = unidade do morador autenticado, resolvida no servidor por `moradores.unidade` com normalização `REPLACE(UPPER(TRIM()),'GLEBA ','')` (padrão já usado na ação `acessos`).
- Somente moradores **ativos** da unidade contam, para que um ex-proprietário não exponha seus visitantes ao novo morador.
- Visitantes da base geral só entram com evidência de vínculo (autorização em `acessos_visitantes` para morador da unidade); a base geral do tenant continua fechada ao portal (coerente com BUSINESS_RULES de Alertas).
- Exclusão permanece restrita ao autor; API devolve `pode_excluir` para a UI esconder o botão.
- Isolamento multi-tenant: todas as subconsultas filtram `tenant_id`.

## Correção
- Novo `api/helpers/portal_visitantes_unidade_helper.php`.
- `api_portal_morador.php` (GET visitantes) e `api_acessos_visitantes.php` (POST via portal) usam o helper.
- App: `visitors_screen.dart` (título "Visitantes da Unidade", contador, "Cadastrado por", excluir condicional) e `access_screen.dart` (apenas ativos no seletor, `isExpanded` + ellipsis).
- Portal web: `frontend/portal_morador.html` mostra autor e só renderiza excluir com `pode_excluir`.

## Testes
Ver `TESTING.md` §5. Pendentes: `flutter analyze`/build do APK e smoke test autenticado em produção.

## Deploy
Publicar os três PHP (incluindo o helper novo) **antes** de distribuir o APK. APKs antigos continuam funcionando: os campos novos são adicionais, e o botão excluir antigo para visitantes de terceiros recebe a mensagem "não pertence a você".
