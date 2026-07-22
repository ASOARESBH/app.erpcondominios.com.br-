# Guia de CSS

O ERP utiliza um sistema de design próprio (Design System) sem frameworks como Bootstrap ou Tailwind.

## 1. Arquivos Core
- **`app.css`**: Estilos globais, reset, tipografia, grid, utilitários, componentes base (botões, inputs, modais).
- **`style.css`**: Legado/complementar ao app.css.
- **`themes/theme-blue.css`**: Variáveis CSS (Custom Properties) que definem a identidade visual.

## 2. Variáveis (Theme)
Toda cor deve usar as variáveis definidas no tema:
```css
color: var(--color-primary-600);
background-color: var(--color-background-soft);
border: 1px solid var(--color-border);
```

## 3. CSS por Página
Cada página tem seu próprio CSS (`assets/css/pages/nome_da_pagina.css`), carregado dinamicamente pelo `app-router.js`.
Evite criar estilos globais dentro de CSS de página.

## 4. Padrões de UI
- **Botões**: `.btn-primary-modern`, `.btn-secondary-modern`, `.btn-danger-modern`
- **Cards**: `.page-card`
- **Inputs**: Estrutura com `.form-group`, `label.form-label`, `.input-wrapper` e `input.form-control`
