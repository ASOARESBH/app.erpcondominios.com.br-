# FAQ Técnico

## 1. Por que não usar React ou Vue?
Por restrição arquitetural definida pelo usuário. O sistema deve ser puramente HTML/CSS/Vanilla JS para garantir que possa ser hospedado em qualquer servidor web simples sem necessidade de Node.js, build steps (Webpack/Vite) ou pipelines complexos.

## 2. Como as páginas são carregadas?
O `layout-base.html` carrega o esqueleto. O `app-router.js` intercepta cliques em links, faz um `fetch` do HTML da página (`frontend/pages/*.html`), injeta no `#appContent`, carrega o CSS específico e executa o JS da página via ES Module `import()`.

## 3. O que acontece se a sessão expirar?
O `session-manager-core.js` detecta a expiração via polling ou erro 401/403 na API, e redireciona o usuário para `login.html`, limpando o localStorage.
