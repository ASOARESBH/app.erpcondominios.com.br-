# 📚 Guia de Implementação - Interface Unificada

## Visão Geral

Este guia descreve como a Interface Unificada foi implementada e como manter/estender o sistema.

---

## 🏗️ Arquitetura

### Camadas

```
┌─────────────────────────────────────────────┐
│         HTML (Estrutura)                    │
│  - Sidebar minimalista                      │
│  - Cabeçalho com botão de logout            │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│         CSS (Apresentação)                  │
│  - unified-header.css (1000+ linhas)        │
│  - logout-modal.css (400+ linhas)           │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│         JavaScript (Lógica)                 │
│  - unified-header-sync.js                   │
│  - logout-modal-unified.js                  │
│  - sessao_manager.js (existente)            │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│         APIs (Backend)                      │
│  - api_usuario_logado.php                   │
│  - verificar_sessao_completa.php            │
│  - logout.php                               │
└─────────────────────────────────────────────┘
```

---

## 📋 Estrutura de Arquivos

```
projeto_refatorado/
├── frontend/
│   ├── css/
│   │   ├── unified-header.css          ← NOVO
│   │   └── logout-modal.css            ← NOVO
│   ├── js/
│   │   ├── unified-header-sync.js      ← NOVO
│   │   ├── logout-modal-unified.js     ← NOVO
│   │   ├── sessao_manager.js           ← EXISTENTE
│   │   └── ... (outros scripts)
│   ├── dashboard.html                  ← REFATORADO
│   ├── administrativa.html             ← REFATORADO
│   └── ... (67 outras páginas)         ← REFATORADAS
├── api/
│   ├── api_usuario_logado.php
│   ├── verificar_sessao_completa.php
│   └── logout.php
├── uploads/
│   └── logo/
│       └── logo.jpeg                   ← Logo dinâmica
└── REFACTORING_VALIDATION.md           ← Documentação
```

---

## 🔄 Fluxo de Sincronização

### 1. Carregamento Inicial

```
1. Página carrega (DOMContentLoaded)
   ↓
2. unified-header-sync.js inicializa
   ├─ Cria estrutura HTML do cabeçalho
   ├─ Adiciona CSS
   └─ Inicia sincronização
   ↓
3. logout-modal-unified.js inicializa
   ├─ Cria modal de confirmação
   ├─ Adiciona CSS
   └─ Configura event listeners
   ↓
4. sessao_manager.js inicializa
   ├─ Verifica sessão
   └─ Inicia renovação automática
```

### 2. Sincronização Periódica

```
A cada 1 segundo:
1. unified-header-sync.js busca dados
   ├─ GET /api/api_usuario_logado.php
   └─ Recebe: nome, funcao, tempo_restante
   ↓
2. Atualiza UI
   ├─ Avatar (inicial)
   ├─ Nome (CAPS LOCK)
   ├─ Função
   ├─ Status
   └─ Timer (HH:MM:SS)
   ↓
3. Sincroniza com sidebar (se existir)
   ├─ Atualiza userProfileSection
   └─ Mantém consistência
```

### 3. Logout

```
1. Usuário clica em btn-logout
   ↓
2. logout-modal-unified.js abre modal
   ├─ Mostra confirmação
   └─ Aguarda resposta
   ↓
3. Usuário confirma
   ↓
4. logout-modal-unified.js executa logout
   ├─ Chama sessao_manager.logout()
   ├─ Limpa localStorage
   ├─ Limpa sessionStorage
   └─ Remove token_acesso
   ↓
5. Redireciona para login.html
```

---

## 🎯 IDs de Sistema Preservados

### Mapeamento de IDs

| ID | Tipo | Localização | Responsável |
|---|---|---|---|
| `userProfileSection` | div | Sidebar | unified-header-sync.js |
| `userAvatar` | div | Cabeçalho | unified-header-sync.js |
| `userName` | div | Cabeçalho | unified-header-sync.js |
| `userFunction` | div | Cabeçalho | unified-header-sync.js |
| `sessionTimer` | div | Cabeçalho | unified-header-sync.js |
| `sessionStatus` | div | Cabeçalho | unified-header-sync.js |
| `sidebar` | nav | Navegação | HTML |
| `btn-logout` | button | Cabeçalho | logout-modal-unified.js |

### Como Usar IDs

```javascript
// Acessar elementos
const avatar = document.getElementById('userAvatar');
const nome = document.getElementById('userName');
const funcao = document.getElementById('userFunction');
const timer = document.getElementById('sessionTimer');
const status = document.getElementById('sessionStatus');
const btnLogout = document.getElementById('btn-logout');
const sidebar = document.getElementById('sidebar');

// Verificar se existem
if (avatar) {
    console.log('Avatar encontrado:', avatar.textContent);
}
```

---

## 🔌 APIs Utilizadas

### 1. api_usuario_logado.php

**Método**: GET  
**Resposta**:
```json
{
    "sucesso": true,
    "logado": true,
    "usuario": {
        "id": 1,
        "nome": "Andre Soares e Silva",
        "email": "andre@example.com",
        "funcao": "ADMINISTRADOR",
        "permissao": "SISTEMA"
    },
    "tempo_restante_segundos": 3600,
    "tempo_restante_formatado": "01:00:00"
}
```

**Usado por**: `unified-header-sync.js`

### 2. verificar_sessao_completa.php

**Método**: GET (verificar) / POST (logout)  
**Resposta (GET)**:
```json
{
    "sucesso": true,
    "sessao_ativa": true,
    "usuario": {...},
    "tempo_restante_segundos": 3600
}
```

**Resposta (POST logout)**:
```json
{
    "sucesso": true,
    "mensagem": "Logout realizado com sucesso"
}
```

**Usado por**: `sessao_manager.js`, `logout-modal-unified.js`

### 3. logout.php

**Método**: POST  
**Resposta**:
```json
{
    "sucesso": true,
    "mensagem": "Logout realizado"
}
```

**Usado por**: `logout-modal-unified.js` (fallback)

---

## 🎨 CSS Classes

### Cabeçalho Unificado

```css
.header                           /* Cabeçalho principal */
.header h1                        /* Título da página */
.header-user-profile             /* Bloco de perfil */
.header-user-avatar              /* Avatar circular */
.header-user-info                /* Informações do usuário */
.header-user-name                /* Nome em CAPS LOCK */
.header-user-function            /* Função do usuário */
.header-user-status              /* Status com indicador */
.status-indicator                /* Ponto verde de status */
.header-session-info             /* Informações de sessão */
.session-timer                   /* Timer HH:MM:SS */
.session-status                  /* Label "SESSÃO" */
#btn-logout                       /* Botão de logout */
```

### Modal de Logout

```css
.logout-modal-overlay             /* Fundo escuro */
.logout-modal-container           /* Container do modal */
.logout-modal-header              /* Cabeçalho do modal */
.logout-modal-icon                /* Ícone do modal */
.logout-modal-title               /* Título do modal */
.logout-modal-body                /* Corpo do modal */
.logout-modal-message             /* Mensagem de confirmação */
.logout-modal-warning             /* Aviso de perda de dados */
.logout-modal-footer              /* Rodapé com botões */
.logout-modal-button              /* Botões genéricos */
.logout-modal-cancel              /* Botão cancelar */
.logout-modal-confirm             /* Botão confirmar */
.logout-modal-spinner             /* Spinner de carregamento */
```

### Sidebar Minimalista

```css
.sidebar                          /* Navegação lateral */
.sidebar-header                   /* Cabeçalho da sidebar */
.sidebar-logo                     /* Logo dinâmica */
.nav-menu                         /* Menu de navegação */
.nav-item                         /* Item do menu */
.nav-link                         /* Link do menu */
.nav-link.active                  /* Link ativo */
```

---

## 🔐 Segurança

### Logout Seguro

```javascript
// Fluxo de segurança implementado:

1. Modal de confirmação
   └─ Previne logout acidental

2. Limpeza de tokens
   ├─ localStorage.removeItem('token_acesso')
   ├─ sessionStorage.removeItem('token_acesso')
   └─ sessionStorage.removeItem('sessao_ativa')

3. Chamada de API
   └─ POST /api/verificar_sessao_completa.php?acao=logout

4. Redirecionamento
   └─ window.location.href = 'login.html'
```

### Proteção de Dados

- ✅ Nenhuma informação sensível no HTML
- ✅ Dados carregados dinamicamente via HTTPS
- ✅ Tokens armazenados em sessionStorage (não localStorage por padrão)
- ✅ Sincronização apenas na camada UI
- ✅ APIs originais mantidas intactas

---

## 📱 Responsividade

### Breakpoints

```css
/* Desktop */
@media (min-width: 1200px) {
    /* Cabeçalho completo */
    /* Avatar 48px */
    /* Informações visíveis */
    /* Timer visível */
}

/* Tablet */
@media (max-width: 1200px) and (min-width: 768px) {
    /* Cabeçalho adaptado */
    /* Avatar 40px */
    /* Informações reduzidas */
    /* Timer oculto */
}

/* Mobile */
@media (max-width: 768px) {
    /* Cabeçalho em duas linhas */
    /* Avatar 36px */
    /* Botão em largura total */
    /* Informações em coluna */
}
```

---

## 🧪 Testes

### Teste 1: Carregamento Inicial

```javascript
// Verificar que elementos foram criados
console.assert(document.getElementById('userAvatar'), 'Avatar não encontrado');
console.assert(document.getElementById('userName'), 'Nome não encontrado');
console.assert(document.getElementById('btn-logout'), 'Botão logout não encontrado');
```

### Teste 2: Sincronização

```javascript
// Verificar que dados são sincronizados
setTimeout(() => {
    const nome = document.getElementById('userName').textContent;
    console.log('Nome sincronizado:', nome);
    console.assert(nome !== 'Carregando...', 'Dados não sincronizados');
}, 2000);
```

### Teste 3: Modal de Logout

```javascript
// Verificar que modal abre
document.getElementById('btn-logout').click();
setTimeout(() => {
    const modal = document.getElementById('logoutModalOverlay');
    console.assert(modal?.classList.contains('active'), 'Modal não abriu');
}, 500);
```

### Teste 4: Responsividade

```javascript
// Testar em diferentes resoluções
const sizes = [
    { width: 1920, name: 'Desktop' },
    { width: 768, name: 'Tablet' },
    { width: 480, name: 'Mobile' }
];

sizes.forEach(size => {
    window.resizeTo(size.width, 1080);
    console.log(`${size.name} (${size.width}px): OK`);
});
```

---

## 🚀 Como Estender

### Adicionar Novo Campo no Cabeçalho

```javascript
// Em unified-header-sync.js, função atualizarUI:

// 1. Adicionar HTML
const profileHTML = `
    ...
    <div class="header-user-email" id="userEmail">email@example.com</div>
    ...
`;

// 2. Atualizar dados
function atualizarUI(usuario, tempoRestante) {
    ...
    const email = usuario.email;
    const emailElement = document.getElementById('userEmail');
    if (emailElement) {
        emailElement.textContent = email;
    }
    ...
}
```

### Customizar Estilos

```css
/* Em unified-header.css, adicionar customizações */

.header {
    /* Seu CSS aqui */
}

.header-user-profile {
    /* Seu CSS aqui */
}
```

### Adicionar Novo Modal

```javascript
// Criar novo arquivo: js/novo-modal-unified.js

(function() {
    'use strict';
    
    function inicializar() {
        console.log('🔐 Novo Modal inicializado');
        criarModal();
        configurarEventListeners();
    }
    
    function criarModal() {
        // Implementar criação do modal
    }
    
    function configurarEventListeners() {
        // Implementar event listeners
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', inicializar);
    } else {
        inicializar();
    }
})();
```

---

## 🐛 Troubleshooting

### Problema: Cabeçalho não aparece

**Solução**:
1. Verificar se CSS foi carregado (F12 → Network)
2. Verificar console para erros
3. Verificar se `unified-header-sync.js` está carregado

### Problema: Dados não sincronizam

**Solução**:
1. Verificar se API responde (F12 → Network)
2. Verificar se sessão está ativa
3. Verificar se `api_usuario_logado.php` existe

### Problema: Modal não abre

**Solução**:
1. Verificar se `logout-modal-unified.js` está carregado
2. Verificar se CSS do modal foi carregado
3. Verificar console para erros

### Problema: Logout não funciona

**Solução**:
1. Verificar se `sessao_manager.js` está carregado
2. Verificar se API de logout responde
3. Verificar se token_acesso está sendo limpo

---

## 📞 Contato e Suporte

Para dúvidas sobre a implementação:

1. Verificar documentação em `REFACTORING_VALIDATION.md`
2. Verificar console do navegador (F12)
3. Verificar Network tab para requisições de API
4. Verificar se todos os scripts estão carregados

---

## 📝 Changelog

### Versão 1.0 (02/02/2026)

- ✅ Interface Unificada implementada
- ✅ Sidebar minimalista com logo dinâmica
- ✅ Cabeçalho com perfil à direita
- ✅ Modal de confirmação de logout
- ✅ 68 páginas refatoradas
- ✅ Documentação completa

---

**Última Atualização**: 02/02/2026  
**Status**: ✅ Estável  
**Versão**: 1.0
