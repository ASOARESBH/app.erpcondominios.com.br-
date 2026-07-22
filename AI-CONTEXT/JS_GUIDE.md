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
