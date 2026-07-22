# Frontend Guide

## 1. Regra de Ouro
**Proibido uso de Frameworks.** Todo desenvolvimento deve ser feito em HTML5, CSS3 e Vanilla JS.

## 2. Roteamento (AppRouter)
O arquivo `frontend/js/app-router.js` gerencia a navegação.
- As páginas ficam em `frontend/pages/*.html` (apenas o miolo, sem `<html>` ou `<body>`).
- Ao navegar para `?page=clientes`, o Router:
  1. Carrega `frontend/pages/clientes.html` no `#appContent`.
  2. Carrega `assets/css/pages/clientes.css` dinamicamente.
  3. Executa `import { init } from './pages/clientes.js'` e chama `init()`.

## 3. Padrão de Arquivos JS de Página
Todo arquivo JS de página DEVE ser um ES Module:
```javascript
export function init() {
    // Inicialização de eventos, chamadas de API iniciais
}

export function destroy() {
    // Limpeza de timers, listeners globais ao sair da página
}
```

## 4. UI Components
- **Modais**: Usar as classes padronizadas `.modal-overlay`, `.modal-content`.
- **Botões**: `.btn-primary-modern`, `.btn-secondary-modern`.
- **Tabelas**: `.table-responsive`, `.data-table`.
