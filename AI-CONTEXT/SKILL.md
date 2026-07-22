# AI SKILL: ERP Condomínio

**ATENÇÃO IA:** Leia este arquivo antes de qualquer interação com o código. Ele contém as regras absolutas e o comportamento esperado.

## 1. Regras Absolutas de Arquitetura
- **NUNCA** sugira ou utilize frameworks frontend (React, Vue, Angular, jQuery). O projeto é 100% Vanilla JS.
- **NUNCA** sugira Node.js, Python ou Ruby para o backend. O projeto é 100% PHP 8.x.
- **NUNCA** altere o `app-router.js` a menos que seja explicitamente solicitado. Ele é o coração do SPA.
- **NUNCA** crie arquivos CSS globais para páginas específicas. Use `assets/css/pages/nome_da_pagina.css`.

## 2. Padrão de Desenvolvimento Frontend
- As páginas HTML ficam em `frontend/pages/` e NÃO possuem `<html>`, `<head>` ou `<body>`. Elas são injetadas no `#appContent`.
- Todo arquivo JS de página deve exportar `init()` e `destroy()`.
- Use `fetch` com `credentials: 'include'` para todas as chamadas de API.

## 3. Padrão de Desenvolvimento Backend
- Todas as APIs devem validar a sessão incluindo `auth_helper.php` e chamando `verificarAutenticacao()`.
- Prevenção de SQL Injection é obrigatória: use `$stmt->prepare()` e `$stmt->bind_param()`.
- Retorno padronizado em JSON: `{"sucesso": bool, "mensagem": string, "dados": object}`.
- Use `ob_start()` e `ob_end_clean()` para prevenir warnings que quebram o JSON.

## 4. Fluxo de Trabalho Obrigatório
1. **Entendimento**: Leia o [INDEX.md](INDEX.md) e localize os arquivos relevantes.
2. **Pesquisa**: Busque por código duplicado antes de criar novo.
3. **Desenvolvimento**: Aplique a alteração seguindo os guias de estilo.
4. **Log de Debug**: Adicione `console.log` no JS e `error_log` no PHP para facilitar o rastreio.
5. **Atualização**: Se criar um novo módulo, atualize o `MODULES.md` e `API.md`.
