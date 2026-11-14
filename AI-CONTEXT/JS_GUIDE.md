# Guia de JavaScript

O frontend utiliza Vanilla JS (ECMAScript 6+) sem frameworks.

## 1. Módulos de Página (ES Modules)
Todo arquivo em `frontend/js/pages/*.js` deve exportar funções de ciclo de vida:
```javascript
export function init() {
    // Carregar dados, adicionar listeners
}

export function destroy() {
    // Remover listeners globais (se houver)
}
```

## 2. Padrão de Fetch (API Calls)
Utilizar `fetch` nativo com async/await e `credentials: 'include'`:
```javascript
async function carregarDados() {
    try {
        const response = await fetch('/api/api_exemplo.php?action=listar', {
            credentials: 'include'
        });
        const data = await response.json();
        if (data.sucesso) {
            // Renderizar
        }
    } catch (error) {
        console.error(error);
    }
}
```

## 3. Componentização
Para componentes reutilizáveis, usamos Web Components nativos (ex: `class UserMenu extends HTMLElement`).

## 4. Rotas limpas da SPA
- Use `/<slug>` para a rota; mantenha `/frontend/layout-base.html?page=<slug>` como compatibilidade. O `AppRouter.getPageFromURL()` é a fonte única para descobrir a página atual, inclusive nos indicadores de menu e guards de permissão.
- O router canonicaliza o path por `history.replaceState`, preserva parâmetros de filtro/aba/hash permitidos, usa `pushState` para navegação SPA e carrega a rota da URL em `popstate`.
- Não escreva `page=<slug>` de novo nos módulos de filtro/aba. Use `AppRouter.cleanURLForPage(slug, params, hash)` quando precisar sincronizar estado da página. Não passe parâmetros de uma página anterior para outra sem necessidade.
- O listener global intercepta somente navegação interna normal; cliques com Ctrl/Cmd/Shift/Alt, links externos, download e targets diferentes de `_self` devem permanecer comportamentos nativos.
- URLs de scripts/CSS/componentes/catálogos requisitados pelo shell por fetch devem ser absolutas sob `/frontend/`; recursos de API usam `/api/` ou origem explícita. Imports entre módulos continuam relativos ao módulo que importa.
