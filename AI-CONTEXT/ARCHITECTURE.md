# Arquitetura do Sistema

O ERP Condomínio utiliza uma arquitetura **Monolítica Modular** baseada em SPA (Single Page Application) no frontend e APIs RESTful em PHP no backend.

## 1. Visão Geral
- **Frontend**: HTML5, CSS3, Vanilla JS (Sem frameworks como React/Vue)
- **Backend**: PHP 8.x (Procedural em transição para OOP/MVC)
- **Banco de Dados**: MySQL (HostGator)
- **Infraestrutura**: Servidor Apache/Litespeed com `.htaccess`

## 2. Padrão Arquitetural Frontend
O frontend opera como uma SPA gerenciada pelo `app-router.js`.
- **`layout-base.html`**: Container principal (Header, Sidebar).
- **`app-router.js`**: Intercepta rotas, carrega HTML parcial (`frontend/pages/`), carrega CSS específico dinamicamente e executa o JS correspondente via ES Modules.
- **Componentes**: Uso de Web Components nativos (ex: `<app-user-menu>`).

## 3. Padrão Arquitetural Backend
O backend é composto por dezenas de arquivos `api_*.php`.
- **Legacy**: Scripts procedurais com `switch($_GET['action'])`.
- **Moderno**: Classes estendendo `ApiBase` (ex: `ExemploApi extends ApiBase`), implementando rotas e tratamento de erros padronizado.
- **Autenticação**: Gerenciada centralmente pelo `auth_helper.php` usando Sessões PHP (`PHPSESSID`) e validação de permissões por hierarquia.

## 4. Separação de Responsabilidades
- **Frontend**: Apenas UI e chamadas Fetch API. Proibido regras de negócio no JS.
- **Backend**: Validação, persistência, integração externa e regras de negócio.
