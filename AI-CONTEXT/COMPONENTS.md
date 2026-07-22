# Componentes Reutilizáveis

## 1. Web Components
- `<app-user-menu>`: Dropdown de perfil do usuário no header. Lógica em `user-menu.js`.

## 2. Componentes Globais (AppRouter/UI)
- **Sidebar**: Gerenciada pelo `sidebar-controller.js` e `menu-controller.js`.
- **Modais de Confirmação**: Padronizados via CSS (`.modal-overlay`).

## 3. Componentes Funcionais
- **Scanner de QR Code**: Módulo nativo (Html5Qrcode) integrado em `console_acesso.html`.
- **Sessão Manager**: O `session-manager-core.js` gerencia renovação de token, inatividade e logout seguro.
