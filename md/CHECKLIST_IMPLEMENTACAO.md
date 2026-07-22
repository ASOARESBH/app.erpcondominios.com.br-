# 📋 Checklist de Implementação - Novo Fluxo de Login

## ✅ Alterações Realizadas

### 1. login.html - CONCLUÍDO ✅

**Arquivo:** `login.html`

**Alterações:**
- [x] Linha ~319: Redirecionamento após login → `layout-base.html?page=dashboard`
- [x] Linha ~365: Verificação de sessão → `layout-base.html?page=dashboard`
- [x] localStorage continua armazenando dados (nome, permissão)

**Mudança específica:**
```javascript
// ANTES
window.location.href = './frontend/dashboard.html';

// DEPOIS
window.location.href = './frontend/layout-base.html?page=dashboard';
```

---

## 🔍 Verificações Obrigatórias

### 1. **Validar Estrutura de Arquivos**

```bash
✓ frontend/layout-base.html        (container principal)
✓ frontend/pages/dashboard.html     (conteúdo)
✓ frontend/js/pages/dashboard.js    (lógica)
✓ frontend/js/app-router.js         (gerenciador)
✓ frontend/js/auth-guard.js         (proteção)
✓ api/validar_login.php             (autenticação)
```

### 2. **Validar Configurações de AppRouter**

Abrir `frontend/js/app-router.js`:

```javascript
config: {
    pagesPath: 'pages/',           // ✓ Correto
    scriptsPath: './pages/',       // ✓ Relativo a js/
    contentContainerId: 'appContent', // ✓ Deve existir em layout-base.html
    titleElementId: 'pageTitle'    // ✓ Deve existir em layout-base.html
}
```

### 3. **Validar HTML da Página Dashboard**

Arquivo: `frontend/pages/dashboard.html`

```html
<!-- Deve conter estes elementos -->
<div id="appContent">
    <!-- Conteúdo será injetado aqui pelo AppRouter -->
</div>
```

### 4. **Validar Módulo JavaScript do Dashboard**

Arquivo: `frontend/js/pages/dashboard.js`

```javascript
// Deve exportar estas funções
export { init, destroy };

export function init() {
    // Lógica de inicialização
    console.log('[Dashboard] Inicializado');
    // ... resto da lógica
}

export function destroy() {
    // Limpeza (remover event listeners, etc)
    console.log('[Dashboard] Descrito');
}
```

### 5. **Validar API de Login**

Arquivo: `api/validar_login.php`

Deve retornar JSON como:

```json
{
    "sucesso": true,
    "mensagem": "Login realizado com sucesso",
    "dados": {
        "nome": "João Silva",
        "email": "joao@example.com",
        "permissao": "admin",
        "departamento": "TI"
    }
}
```

---

## 🧪 Teste de Funcionamento

### Teste 1: Login Completo

1. Abra navegador
2. Acesse `/login.html`
3. Insira credenciais válidas
4. Clique em "Entrar"
5. **Verificar:**
   - [ ] Mensagem de sucesso aparece
   - [ ] URL muda para `layout-base.html?page=dashboard`
   - [ ] Sidebar aparece à esquerda
   - [ ] Dashboard carrega no centro
   - [ ] Nome do usuário aparece no header
   - [ ] Nenhum erro no Console (F12)

### Teste 2: Navegação entre páginas

1. Após login, clique em um link da sidebar
2. **Verificar:**
   - [ ] Página carrega sem reload completo
   - [ ] URL atualiza para `?page=nomepage`
   - [ ] Conteúdo substitui sem página ficar em branco
   - [ ] Botão back do navegador funciona

### Teste 3: Busca direta da URL

1. Abra aba nova
2. Acesse `layout-base.html?page=visitantes`
3. **Verificar:**
   - [ ] Se não logado → redireciona para login.html
   - [ ] Se logado → carrega página de visitantes
   - [ ] Sidebar aparece
   - [ ] URL mantém o parâmetro page=

### Teste 4: Sessão Expirada

1. Aguarde timeout de sessão (padrão: 2 horas)
2. Tente acessar qualquer página
3. **Verificar:**
   - [ ] Redireciona para login.html
   - [ ] Mensagem de sessão expirada aparece

### Teste 5: localStorage

Abrir DevTools (F12) → Console:

```javascript
// Após login, executar:
localStorage.getItem('usuario_nome')      // Deve retornar nome
localStorage.getItem('usuario_permissao') // Deve retornar permissão

// Limpar localStorage
localStorage.clear(); // Para limpar tudo
```

---

## 🚨 Problemas Comuns e Soluções

### ❌ Problema: "Dashboard carrega sem sidebar"

**Causa:** AppRouter não inicializa corretamente

**Solução:**
1. Verificar se `app-router.js` está sendo carregado
2. Verificar se `<!DOCTYPE html>` está em layout-base.html
3. Verificar console para mensagens de erro `[App]` ou `[Router]`
4. Validar caminho relativo de `pages/`

### ❌ Problema: "404 ao carregar dashboard"

**Causa:** Arquivo `pages/dashboard.html` não encontrado

**Solução:**
1. Validar que `frontend/pages/dashboard.html` existe
2. Validar caminho em AppRouter config
3. Verificar erro no Console

### ❌ Problema: "Scripts não executam"

**Causa:** `pages/dashboard.js` ou não existe ou não exporta `init()`

**Solução:**
1. Validar que `frontend/js/pages/dashboard.js` existe
2. Adicionar `export { init, destroy };` no topo do arquivo
3. Verificar import dinâmico com `?t=timestamp` para cache

### ❌ Problema: "login.html não redireciona"

**Causa:** API de login não retorna JSON válido

**Solução:**
1. Abrir DevTools → Network
2. Clicar em "Entrar"
3. Verificar requisição POST para `/api/validar_login.php`
4. Verificar resposta JSON (deve ter `"sucesso": true`)
5. Verificar Content-Type: application/json

### ❌ Problema: "localStorage vazio"

**Causa:** API não retorna dados.nome ou dados.permissao

**Solução:**
1. Editar `api/validar_login.php`
2. Garantir que SELECT retorna estes campos:
   ```sql
   SELECT id, nome, email, senha, funcao, departamento, permissao, ativo FROM usuarios
   ```
3. Garantir que JSON inclui:
   ```php
   "dados" => [
       "nome" => $user['nome'],
       "permissao" => $user['permissao']
   ]
   ```

---

## 📊 Diagrama de Fluxo

```
┌─────────────────────────────────────────────────────────┐
│                    LOGIN.HTML                           │
│        Usuário insere email e senha                     │
└──────────────────┬──────────────────────────────────────┘
                   │ POST /api/validar_login.php
                   ▼
┌─────────────────────────────────────────────────────────┐
│                   VALIDAR_LOGIN.PHP                     │
│            Valida credenciais no banco                  │
│         Retorna JSON { sucesso, dados }                 │
└──────────────────┬──────────────────────────────────────┘
                   │ JSON Response
                   ▼
┌─────────────────────────────────────────────────────────┐
│                    LOGIN.HTML (JS)                      │
│        Armazena dados em localStorage                   │
│  window.location.href = 'layout-base.html?page=...'   │
└──────────────────┬──────────────────────────────────────┘
                   │ Redirect
                   ▼
┌─────────────────────────────────────────────────────────┐
│                  LAYOUT-BASE.HTML                       │
│         Load auth-guard.js (validação)                  │
│         Load session-manager-core.js                    │
└──────────────────┬──────────────────────────────────────┘
                   │ Scripts carregam
                   ▼
┌─────────────────────────────────────────────────────────┐
│                   APP-ROUTER.JS                         │
│         1. init() - inicializa router                   │
│         2. getPageFromURL() - lê ?page=X                │
│         3. loadPage('dashboard') - carrega conteúdo     │
└──────────────────┬──────────────────────────────────────┘
                   │ Carrega dinamicamente
         ┌─────────┴─────────────────┐
         ▼                           ▼
┌──────────────────────┐     ┌──────────────────────┐
│ pages/dashboard.html │     │ js/pages/dashboard.js│
│    (conteúdo)       │     │     (lógica)        │
└──────────────────────┘     └──────────────────────┘
         │                           │
         └─────────────┬─────────────┘
                       ▼
         ┌─────────────────────────────┐
         │  Dashboard Renderizado      │
         │  - Sidebar                  │
         │  - Header com usuário       │
         │  - Conteúdo dinâmico        │
         └─────────────────────────────┘
```

---

## 📝 Checklist Final

- [x] **login.html atualizado** - Redirecionamento correto
- [ ] **Tester login completo** - Todos os 5 testes passarem
- [ ] **Verificar console** - Nenhum erro de 404 ou JS
- [ ] **Verificar localStorage** - Dados do usuário armazenados
- [ ] **Testar navegação** - Sidebar funciona
- [ ] **Testar mobile** - Sidebar colapsável funciona
- [ ] **Testar logout** - Redireciona para login.html
- [ ] **Testar timeout** - Sessão expirada funciona

---

## 🎯 Próximas Etapas (Fase 5)

1. Expandir navbar com notificações
2. Adicionar busca global
3. Implementar temas customizáveis
4. Adicionar breadcrumb
5. Melhorar animações de transição

---

**Atualizado:** 12/02/2026  
**Versão:** 2.0 (Novo SPA com layout-base)
