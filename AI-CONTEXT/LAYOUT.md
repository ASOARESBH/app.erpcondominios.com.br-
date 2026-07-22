# Layout e Estrutura de Telas

## 1. Layout Base
O `frontend/layout-base.html` é o esqueleto de toda a aplicação autenticada.
Ele contém:
- Header (Logo, Título dinâmico, `<app-user-menu>`)
- Sidebar (Navegação lateral)
- Main Content (`#appContent` onde o AppRouter injeta as páginas)

## 2. Responsividade
O layout é mobile-first e responsivo.
- Sidebar em mobile fica oculta (canvas/offcanvas) ativada por botão hambúrguer.
- Em desktop, a sidebar pode ser expandida/recolhida (mini-sidebar).

## 3. Páginas Standalone
Páginas como Login (`login.html`) e Console de Acesso (`console_acesso.html`) não usam o `layout-base.html`, elas carregam seus próprios assets e rodam independentes.
