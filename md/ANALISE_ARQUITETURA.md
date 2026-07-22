# 📐 ANÁLISE ARQUITETURAL: Reestruturação Funcional do Sistema

**Data:** 2026-02-06  
**Versão:** 1.0 (Análise Completa)  

---

## 📋 ÍNDICE

1. [Análise da Arquitetura ATUAL](#análise-da-arquitetura-atual)
2. [Problemas Identificados](#problemas-identificados)
3. [Diagramas ASCII](#diagramas-ascii)
4. [Arquitetura CORRIGIDA](#arquitetura-corrigida)
5. [Implementação Proposta](#implementação-proposta)
6. [Fluxos End-to-End](#fluxos-end-to-end)
7. [Checklist de Migração](#checklist-de-migração)

---

## 🔍 ANÁLISE DA ARQUITETURA ATUAL

### 📊 Estados Identificados

```
HOJE (❌ Problemático):

┌─────────────────────────────────────────────────────────┐
│  Frontend (Vanilla JS)                                  │
│                                                          │
│  ┌──────────────────────────────────────────────────┐  │
│  │ Página A (dashboard.html)                        │  │
│  │ ├─ auth-guard.js (verifica sessão)   ⚠️ DUPLO   │  │
│  │ ├─ session-manager-singleton.js      ⚠️ DUPLO   │  │
│  │ ├─ user-display.js (renova sessão?)  ⚠️ DUPLO   │  │
│  │ ├─ header-user-profile.js            ⚠️ DUPLO   │  │
│  │ ├─ unified-header-sync.js            ⚠️ DUPLO   │  │
│  │ ├─ Logout local (fetch logout.php)   ⚠️ DUPLO   │  │
│  └──────────────────────────────────────────────────┘  │
│                                                          │
│  ┌──────────────────────────────────────────────────┐  │
│  │ Página B (protocolo.html)                        │  │
│  │ ├─ auth-guard.js (verifica sessão)   ⚠️ DUPLO   │  │
│  │ ├─ session-manager-singleton.js      ⚠️ DUPLO   │  │
│  │ ├─ user-display.js (renova sessão?)  ⚠️ DUPLO   │  │
│  │ ├─ Logout local (fetch logout.php)   ⚠️ DUPLO   │  │
│  └──────────────────────────────────────────────────┘  │
│                                                          │
│  ┌──────────────────────────────────────────────────┐  │
│  └─ ... mais 30+ páginas com mesmo padrão ...      │  │
│                                                          │
│  ⚠️ MÚLTIPLAS INSTÂNCIAS DE:                           │
│    • session-manager.js (OLD)                          │
│    • session-manager-melhorado.js (OLD)                │
│    • session-manager-singleton.js (NOVO) X 32 páginas  │
│                                                          │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  Backend PHP                                            │
│                                                          │
│  ├─ verificar_sessao_completa.php (checkado 32x/reload)│
│  ├─ api_usuario_logado.php                             │
│  ├─ logout.php (chamado 24 vezes no frontend)          │
│  └─ ...                                                 │
└─────────────────────────────────────────────────────────┘
```

### 🔴 Mapeamento de Responsabilidades (HOJE)

| Componente | Responsabilidade Intended | Responsabilidade Real | Status |
|---|---|---|---|
| **auth-guard.js** | Proteção de acesso | Verifica sessão a cada página | ⚠️ Duplicado |
| **SessionManagerSingleton** | Gerenciar sessão | Gerencia + UI dispara eventos | ⚠️ Acoplado |
| **dashboard.html** | Exibir dados | Exibir + renovar sessão + logout | ❌ Sobre-carregado |
| **protocolo.html** | Exibir protocolo | Exibir + renovar sessão + logout | ❌ Sobre-carregado |
| **header-user-profile.js** | Exibir usuário | Exibir + atualizar estado | ⚠️ Acoplado |
| **user-display.js** | Exibir nome/avatar | Exibir + atualizar em tempo real | ⚠️ Acoplado |
| **logout local** (24 locais) | Fazer logout | Fazer logout | ❌ Espalhado |

### 🔴 Fluxos ATUAIS (Problemáticos)

#### 1️⃣ **Carregar Página (ex: dashboard.html)**
```
┌─────────────┐
│ Usuário clica em "Dashboard" (link/botão)
└──────┬──────┘
       ↓
┌─────────────┐
│ Navegador carrega dashboard.html
└──────┬──────┘
       ↓
┌─────────────┐  
│ auth-guard.js inicia
│ → fetch verificar_sessao_completa.php (API Call #1)
└──────┬──────┘
       ↓ (OK)
┌─────────────┐
│ SessionManagerSingleton inicia
│ → fetch verificar_sessao_completa.php (API Call #2) ⚠️ DUPLICADO
│ → setInterval verificação a cada 60s (API Call #3+)
└──────┬──────┘
       ↓
┌─────────────┐
│ dashboard.html - atualizarExibicao() callback rodando
│ → sessionMgr.onUserDataChanged() escuta evento
└──────┬──────┘
       ↓
┌─────────────┐
│ user-display.js inicia
│ → sessionMgr.onUserDataChanged() escuta evento
└──────┬──────┘
       ↓
┌─────────────┐
│ header-user-profile.js inicia
│ → sessionMgr.onUserDataChanged() escuta evento
└──────┬──────┘
       ↓
┌─────────────┐
│ unified-header-sync.js inicia
│ → sessionMgr.onUserDataChanged() escuta evento
└──────┬──────┘
       ↓
✅ Dashboard renderizado

⚠️ CUSTO: 2+ requisições desnecessárias + 4 listeners simultâneos
```

#### 2️⃣ **Fazer Logout**
```
Usuário clica logout
       ↓
┌─────────────────────────────────────────────┐
│ Qual função logout é executada?             │
│                                              │
│ dashboard.html linha 898: fetch logout.php │
│ protocolo.html linha 553: fetch logout.php │
│ estoque.html linha 545: fetch logout.php   │
│ ... 24 versões diferentes ...               │
│                                              │
│ Cada uma com:                               │
│ - Diferentes params                        │
│ - Diferentes error handlers                │
│ - Diferentes redirects                     │
│ - Diferentes cleanup                       │
└─────────────────────────────────────────────┘
⚠️ RISCO: Inconsistência de comportamento
```

#### 3️⃣ **Navegar entre Páginas**
```
Dashboard.html → Protocolo.html
       ↓
Protocolo.html carrega (mesmo ciclo do #1)
       ↓
⚠️ SessionManagerSingleton DA PÁGINA ANTERIOR é eliminado!
⚠️ Novo SessionManagerSingleton é criado para Protocolo
⚠️ Estado anterior perdido
⚠️ Múltiplas requisições de ambas as páginas
⚠️ Race conditions possíveis
```

---

## 🚨 PROBLEMAS IDENTIFICADOS

### **PROBLEMA #1: Múltiplas Instâncias de Gerenciador**

**Hoje:**
- `session-manager.js` (OBSOLETO - ainda carregado?)
- `session-manager-melhorado.js` (OBSOLETO - ainda carregado?)
- `session-manager-singleton.js` (NOVO)

**Código:**
```javascript
// auth-guard.js (linha 1)
fetch(API_URL, ...) // Verificação #1

// session-manager-singleton.js (linha 70 de cada página)
this.verificarSessao(); // Verificação #2
setInterval(() => this.verificarSessao(), 60000); // Verificação #3-N
```

**Impacto:**
- ❌ 2+ requisições HTTP por página carregada
- ❌ Código duplicado em 32+ páginas
- ❌ Race conditions entre gerenciadores

**Causa Raiz:**
- `SessionManagerSingleton.getInstance()` cria **UMA** instância **POR PÁGINA**, não por **SESSÃO/TAB**
- localStorage não compartilha estado entre abas

---

### **PROBLEMA #2: Auth-Guard Duplica Verificação**

**Hoje:**
```javascript
// auth-guard.js carrega PRIMEIRO
fetch(verificar_sessao_completa.php) // ✅ Verificação

// Depois SessionManagerSingleton carrega
fetch(verificar_sessao_completa.php) // ⚠️ Duplicação

// Resultado: 2 requisições desnecessárias
```

**Impacto:**
- ❌ Gasto de largura de banda
- ❌ Latência aumentada
- ❌ Servidor sobrecarregado

---

### **PROBLEMA #3: Logout Espalhado (24 Locais)**

**Hoje:**
```
dashboard.html        → fetch logout.php (ln 898)
protocolo.html        → fetch logout.php (ln 553)
estoque.html          → fetch logout.php (ln 545)
marketplace_admin.html → fetch logout.php (ln 734)
...22 outros ...
```

**Cada um com código diferente!**

**Impacto:**
- ❌ Inconsistência de comportamento
- ❌ Manutenção impossível
- ❌ Bug fix requer 24 edições
- ❌ Risco de logout incompleto

---

### **PROBLEMA #4: Sidebar Carregado em Cada Página**

**Hoje:**
- Cada página HTML inclui seu próprio sidebar
- Cada sidebar inicializa componentes
- Componentes fazem suas próprias verificações

**Impacto:**
- ❌ Sidebar recarrega a cada navegação
- ❌ Estado anterior perdido
- ❌ Múltiplas inicializações

---

### **PROBLEMA #5: UI Controla Sessão**

**Hoje (dashboard.html):**
```javascript
sessionMgr.onUserDataChanged((dados) => {
    atualizarExibicao(dados);  // ✅ OK - UI
    verificarAvisos(dados.tempo_restante);  // ⚠️ Lógica?
});
```

**Esperado:**
```javascript
sessionMgr.onUserDataChanged((dados) => {
    atualizarExibicao(dados);  // ✅ Apenas renderizar
    // Nada de lógica aqui!
});
```

**Impacto:**
- ❌ Violação de SoC (Separation of Concerns)
- ❌ Difícil de testar
- ❌ Difícil de manter

---

### **RESUMO: Acoplamentos Indevidos**

```
┌────────────────────────────────────────────┐
│ UI (Sidebar/Dashboard/Protocolo)           │
│                                             │
│ ├─ Verifica sessão ⚠️ (dever do Session)  │
│ ├─ Renova sessão ⚠️ (dever do Session)    │
│ ├─ Controla login ⚠️ (dever do Session)   │
│ ├─ Faz logout ⚠️ (dever do Session)       │
│ ├─ Exibe dados ✅ (dever da UI)            │
│ └─ Escuta eventos ✅ (dever da UI)        │
│                                             │
└────────────────────────────────────────────┘

┌────────────────────────────────────────────┐
│ SessionManagerSingleton (Singleton)        │
│                                             │
│ ├─ Verifica sessão ✅ (OK)                 │
│ ├─ Renova sessão ✅ (OK)                   │
│ ├─ Emite eventos ✅ (OK)                   │
│ └─ Expõe estado ✅ (OK)                    │
│                                             │
│ ⚠️ PROBLEMA: Múltiplas instâncias!        │
│             Una por página, não por aba   │
│             Singleton quebrado!            │
│                                             │
└────────────────────────────────────────────┘
```

---

## 📊 DIAGRAMAS ASCII

### 🔴 ARQUITETURA ATUAL (Problemática)

```
┌──────────────────────────────────────────────────────────────┐
│                         NAVEGADOR                            │
│                                                               │
│  ABA 1: Dashboard.html          │  ABA 2: Protocolo.html     │
│  ┌─────────────────────────┐    │  ┌─────────────────────┐   │
│  │ SessionManagerSingleton │    │  │ SessionManagerSingleton
│  │ (Instância #1)          │    │  │ (Instância #2)      │   │
│  │ ├─ sessão: ativo        │    │  │ ├─ sessão: ativo    │   │
│  │ ├─ usuario: João        │    │  │ ├─ usuario: João    │   │
│  │ └─ listeners: [...]     │    │  │ └─ listeners: [...] │   │
│  │                          │    │  │                      │   │
│  │ fetch verificar (60s)   │◄──┼─►│ fetch verificar (60s)│   │
│  │ fetch renovar (5min)    │    │  │ fetch renovar (5min)│   │
│  └─────────────────────────┘    │  └─────────────────────┘   │
│  ⚠️ Instância Perdida            │  ⚠️ Instância Nova      │   │
│     ao sair!                      │     ao entrar!           │   │
│                                                               │
│  ❌ PROBLEMA:                                                │
│     - Sem com compartilhament entre abas                    │
│     - Estado duplicado                                       │
│     - Requisições duplicadas                                 │
│     - Sem sincronização                                      │
│                                                               │
└──────────────────────────────────────────────────────────────┘
                           ↓
                  ┌─────────────────┐
                  │  Backend PHP    │
                  │                 │
                  │ verificar_sessão│ ← Chamadas múltiplas
                  │ renovar_sessão  │   (caóticas)
                  │ logout          │
                  └─────────────────┘
```

### 🟢 ARQUITETURA CORRIGIDA (Proposta)

```
┌──────────────────────────────────────────────────────────────┐
│                    NAVEGADOR (TODO)                          │
│                                                               │
│  ┌──────────────────────────────────────────────────────┐   │
│  │        SessionManagerSingleton (ÚNICO)              │   │
│  │                                                      │   │
│  │  ├─ Instância por TAB (usando Service Worker)      │   │
│  │  ├─ Estado centralizado em IndexedDB               │   │
│  │  ├─ Sincronização cross-tab via BroadcastChannel   │   │
│  │  │                                                  │   │
│  │  ├─ verificarSessao() → 1x a cada 60s             │   │
│  │  ├─ renovarSessao() → 1x a cada 5min              │   │
│  │  ├─ emitir eventos → listeners                    │   │
│  │  └─ logout() → centralizado                       │   │
│  └──────────────────────────────────────────────────────┘   │
│       ▲                        ▲                              │
│       │ eventos de estado     │ eventos de estado            │
│       │ onUserDataChanged     │ onSessionExpired             │
│       │                        │                              │
│  ┌────┴───────┐          ┌────┴──────┐                      │
│  │ Dashboard  │          │ Protocolo │                      │
│  │ (CONSUMIDOR)          │ (CONSUMIDOR)                      │
│  │            │          │           │                      │
│  │ Listeners: │          │ Listeners:│                      │
│  │ • Sidebar  │          │ • Sidebar │                      │
│  │ • Header   │          │ • Header  │                      │
│  │ • Content  │          │ • Content │                      │
│  └────────────┘          └───────────┘                      │
│                                                               │
│  ✅ VANTAGENS:                                               │
│     • Uma instância por aba                                 │
│     • Estado compartilhado entre abas                       │
│     • Sincronização automática                              │
│     • Sem requisições duplicadas                            │
│     • UI é consumidora passiva                              │
│                                                               │
└──────────────────────────────────────────────────────────────┘
                           ↓
                  ┌─────────────────┐
                  │  Backend PHP    │
                  │                 │
                  │ verificar_sessão│ ← Chamadas previsíveis
                  │ renovar_sessão  │   (uma por sessão)
                  │ logout          │
                  └─────────────────┘
```

---

## ✨ ARQUITETURA CORRIGIDA

### **Princípios Fundamentais**

```
1. SESSION ≠ UI
   A UI NUNCA valida, renova ou controla sessão.
   A UI APENAS renderiza dados que recebe.

2. MENU ≠ AUTENTICAÇÃO
   O menu APENAS exibe dados do usuário.
   O menu NÃO autentica ou renova sessão.

3. PÁGINA ≠ GERENCIADOR DE ESTADO
   Páginas NÃO fazem fetch de sessão.
   Páginas NÃO controlam login.

4. LISTENERS = CONSUMIDORES PASSIVOS
   Listeners escutam eventos.
   Listeners NÃO disparam requisições.

5. ÚNICO GERENCIADOR
   Uma instância por aba/sessão.
   Estado centralizado e compartilhado.
```

### **Camadas da Arquitetura Corrigida**

```
┌─────────────────────────────────────────────────────────┐
│  CAMADA 1: CONTROLE (SessionManagerSingleton)           │
│                                                          │
│  Responsabilidades:                                      │
│  ✅ Verificar sessão (60s)                             │
│  ✅ Renovar sessão (5min ou por atividade)             │
│  ✅ Manter estado em memória                           │
│  ✅ Emitir eventos (onUserDataChanged, etc)            │
│  ✅ Redirecionar para login se expirar                 │
│  ✅ Fazer logout (centralizado)                        │
│  ✅ Sincronizar entre abas                             │
│                                                          │
│  ❌ NÃO faz:                                             │
│  • Renderizar HTML                                       │
│  • Validar entrada do usuário                            │
│  • Controlar navegação da página                        │
│                                                          │
└─────────────────────────────────────────────────────────┘
                           ▲
                    emite eventos
                           │
┌─────────────────────────────────────────────────────────┐
│  CAMADA 2: UI COMPONENTS (Consumidores Passivos)        │
│                                                          │
│  Dashboard, Protocolo, Estoque, etc.                    │
│                                                          │
│  Responsabilidades:                                      │
│  ✅ Escutar eventos do SessionManager                  │
│  ✅ Renderizar dados recebidos                         │
│  ✅ Exibir UI baseada em estado                        │
│                                                          │
│  ❌ NÃO faz:                                             │
│  • Fazer fetch de sessão                                │
│  • Renovar sessão                                        │
│  • Controlar login/logout                               │
│  • Fazer validação de estado                            │
│                                                          │
├─ Sidebar                                                 │
│  ├─ user-profile.js (exibe nome/avatar)                │
│  └─ menu-navigation.js (exibe links)                    │
│                                                          │
├─ Header                                                  │
│  ├─ user-badge.js (exibe usuário)                      │
│  ├─ session-timer.js (exibe tempo)                     │
│  └─ logout-button.js (clica = evento para SessionMgr)  │
│                                                          │
└─ Page Content                                           │
│  └─ Qualquer componente de negócio                     │
│                                                          │
└─────────────────────────────────────────────────────────┘
                           ▲
                   consumem estado
                           │
┌─────────────────────────────────────────────────────────┐
│  CAMADA 3: AUTH GUARD (Protetor de Rotas)              │
│                                                          │
│  Responsabilidades:                                      │
│  ✅ Verificar se página é pública ou protegida         │
│  ✅ Consultar estado do SessionManager (NÃO fetch!)    │
│  ✅ Redirecionar se não autenticado                    │
│                                                          │
│  ❌ NÃO faz:                                             │
│  • Fazer fetch de sessão (já feito pelo SessionMgr)    │
│  • Renovar sessão                                        │
│  • Emitir eventos                                        │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

---

## 💻 IMPLEMENTAÇÃO PROPOSTA

### **1️⃣ SessionManagerSingleton (CORRIGIDO)**

**Responsabilidades:**
- ✅ Gerenciar sessão
- ✅ Emitir eventos
- ✅ NÃO renderizar UI

**Arquivo:** `frontend/js/session-manager-singleton-v2.js`

```javascript
/**
 * =====================================================
 * SESSION MANAGER SINGLETON v2.0 (ARQUITETURA CORRIGIDA)
 * =====================================================
 * 
 * ÚNICO ponto de controle de sessão
 * 
 * Responsabilidades:
 * ✅ Verificar sessão (único lugar)
 * ✅ Renovar sessão (único lugar)
 * ✅ Emitir eventos (para UI consumir)
 * ✅ NÃO renderizar HTML
 * ✅ NÃO fazer validação de entrada
 * 
 * Uso:
 *   const mgr = SessionManager.getInstance();
 *   mgr.onUserDataChanged((userData) => {
 *     // Renderizar UI aqui, não controlar sessão
 *     renderUserProfile(userData);
 *   });
 */

class SessionManager {
    static instance = null;
    static lock = false; // Para garantir criação em thread-safe

    constructor() {
        if (SessionManager.instance && !SessionManager.lock) {
            return SessionManager.instance;
        }

        // Hardware session management
        this.apiBase = '../api/';
        
        // Timers (e SEGURADOS - nunca agressivos)
        this.CHECK_INTERVAL = 60 * 1000;      // 60s (não 1s!)
        this.RENEW_INTERVAL = 5 * 60 * 1000;  // 5min
        this.ACTIVITY_THRESHOLD = 30 * 60 * 1000; // 30min
        
        // Flags de controle
        this.isFetching = false;
        this.sessionActive = false;
        this.currentUser = null;
        this.lastActivity = Date.now();
        
        // Timers
        this.checkTimer = null;
        this.renewTimer = null;
        
        // Listeners (UI pode escutar)
        this.listeners = new Map([
            ['userDataChanged', []],
            ['sessionExpired', []],
            ['error', []]
        ]);

        // Estado persistente (IndexedDB ou localStorage)
        this.persistKey = 'session_state';

        SessionManager.instance = this;
        SessionManager.lock = true;
    }

    static getInstance() {
        if (!SessionManager.instance) {
            SessionManager.instance = new SessionManager();
        }
        return SessionManager.instance;
    }

    /**
     * Iniciar o gerenciador
     * Chamado UMA ÚNICA VEZ na primeira página carregada
     */
    async initialize() {
        // Verificar se já está inicializado (localStorage)
        const stored = this.getPersistedState();
        if (stored && stored.sessionActive) {
            this.sessionActive = stored.sessionActive;
            this.currentUser = stored.currentUser;
            this.emit('userDataChanged', { user: this.currentUser });
        } else {
            // Primeira verificação
            await this.checkSession();
        }

        // Iniciar verificações periódicas APENAS se em página protegida
        if (!this.isPublicPage()) {
            this.startPeriodicChecks();
        }

        console.log('[SessionManager] ✅ Inicializado');
    }

    /**
     * Verificar sessão (ÚNICO lugar onde faz fetch)
     */
    async checkSession() {
        // Evitar requisições simultâneas
        if (this.isFetching) return false;

        this.isFetching = true;

        try {
            const response = await fetch(
                `${this.apiBase}verificar_sessao_completa.php`,
                { 
                    method: 'GET',
                    credentials: 'include',
                    headers: { 'Content-Type': 'application/json' },
                    timeout: 10000
                }
            );

            if (!response.ok) {
                this.handleSessionExpired();
                return false;
            }

            const data = await response.json();

            if (data.sucesso && data.sessao_ativa) {
                this.sessionActive = true;
                this.currentUser = data.usuario || null;
                
                // Persistir estado
                this.persistState();
                
                // Emitir evento (UI vai escutar e renderizar)
                this.emit('userDataChanged', { 
                    user: this.currentUser,
                    sessionTime: data.sessao?.tempo_restante 
                });

                this.isFetching = false;
                return true;
            } else {
                this.handleSessionExpired();
                return false;
            }
        } catch (error) {
            console.error('[SessionManager] Erro ao verificar sessão:', error);
            this.emit('error', { message: error.message });
            this.isFetching = false;
            return false;
        }
    }

    /**
     * Renovar sessão (apenas por atividade real ou timer)
     */
    async renewSession() {
        if (this.isFetching || !this.sessionActive) return false;

        try {
            const formData = new FormData();
            formData.append('acao', 'renovar');
            
            const response = await fetch(
                `${this.apiBase}verificar_sessao_completa.php`,
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'include'
                }
            );

            return response.ok;
        } catch (error) {
            console.error('[SessionManager] Erro ao renovar:', error);
            return false;
        }
    }

    /**
     * Iniciar verificações periódicas
     */
    startPeriodicChecks() {
        if (this.checkTimer) return; // Já está rodando

        // Verificação a cada 60s
        this.checkTimer = setInterval(() => this.checkSession(), this.CHECK_INTERVAL);

        // Renovação a cada 5min
        this.renewTimer = setInterval(() => this.renewSession(), this.RENEW_INTERVAL);

        // Monitorar atividade do usuário
        this.trackUserActivity();
    }

    /**
     * Parar verificações
     */
    stopPeriodicChecks() {
        if (this.checkTimer) clearInterval(this.checkTimer);
        if (this.renewTimer) clearInterval(this.renewTimer);
    }

    /**
     * Rastrear atividade do usuário
     */
    trackUserActivity() {
        const events = ['mousedown', 'keydown', 'scroll', 'touchstart'];
        
        events.forEach(evt => {
            document.addEventListener(evt, () => {
                const now = Date.now();
                // Renovar apenas se passou 30min sem atividade
                if (now - this.lastActivity > this.ACTIVITY_THRESHOLD) {
                    this.renewSession();
                }
                this.lastActivity = now;
            }, { passive: true });
        });
    }

    /**
     * LOGOUT - Centralizado
     */
    async logout() {
        console.log('[SessionManager] Fazendo logout...');

        // 1. Chamar API
        try {
            await fetch(`${this.apiBase}logout.php`, {
                method: 'POST',
                credentials: 'include'
            }).catch(() => {});
        } catch (e) {}

        // 2. Limpar estado local
        this.sessionActive = false;
        this.currentUser = null;
        this.clearPersistedState();
        this.stopPeriodicChecks();

        // 3. Emitir evento (UI vai saber que expirouse)
        this.emit('sessionExpired', {});

        // 4. Redirecionar
        setTimeout(() => {
            window.location.href = 'login.html';
        }, 300);
    }

    /**
     * Lidar com expiração
     */
    handleSessionExpired() {
        this.sessionActive = false;
        this.currentUser = null;
        this.clearPersistedState();
        this.stopPeriodicChecks();
        
        if (!this.isPublicPage()) {
            this.emit('sessionExpired', {});
            setTimeout(() => {
                window.location.href = 'login.html';
            }, 300);
        }
    }

    /**
     * LISTENERS - UI Pode escutar (NÃO fazer lógica de sessão!)
     */
    on(event, callback) {
        if (typeof callback === 'function') {
            if (!this.listeners.has(event)) {
                this.listeners.set(event, []);
            }
            this.listeners.get(event).push(callback);
        }
    }

    /**
     * Emitir eventos para listeners
     * (Interno, não precisa de normalização complexa)
     */
    emit(event, data) {
        if (this.listeners.has(event)) {
            this.listeners.get(event).forEach(callback => {
                try {
                    callback(data);
                } catch (err) {
                    console.warn(`[SessionManager] Erro em listener ${event}:`, err);
                }
            });
        }
    }

    /**
     * Getters (UI pode consultar estado)
     */
    getUser() {
        return this.currentUser;
    }

    isAuthenticated() {
        return this.sessionActive && !!this.currentUser;
    }

    /**
     * Persistência de estado
     */
    persistState() {
        try {
            localStorage.setItem(this.persistKey, JSON.stringify({
                sessionActive: this.sessionActive,
                currentUser: this.currentUser,
                timestamp: Date.now()
            }));
        } catch (e) {
            console.warn('Erro ao persistir estado:', e);
        }
    }

    getPersistedState() {
        try {
            const data = localStorage.getItem(this.persistKey);
            return data ? JSON.parse(data) : null;
        } catch (e) {
            return null;
        }
    }

    clearPersistedState() {
        try {
            localStorage.removeItem(this.persistKey);
        } catch (e) {}
    }

    /**
     * Helpers
     */
    isPublicPage() {
        const publicPages = ['login.html', 'esqueci_senha.html', 'redefinir_senha.html', 'index.html'];
        const page = window.location.pathname.split('/').pop();
        return publicPages.includes(page) || page === '';
    }
}

// Auto-inicializar no DOMContentLoaded
document.addEventListener('DOMContentLoaded', async () => {
    const mgr = SessionManager.getInstance();
    await mgr.initialize();
    window.sessionManager = mgr;
});
```

---

### **2️⃣ Auth Guard (CORRIGIDO)**

**Responsabilidades:**
- ✅ Verificar se página é protegida
- ✅ Usar estado do SessionManager (NÃO fazer fetch!)
- ✅ Redirecionar se não autenticado

**Arquivo:** `frontend/js/auth-guard-v2.js`

```javascript
/**
 * AUTH GUARD v2.0
 * 
 * ✅ CORRIGIDO: Não faz fetch (SessionManager já fez)
 * ✅ CORRIGIDO: Apenas verifica estado existente
 * ✅ CORRIGIDO: Escuta eventos do SessionManager
 */

(function() {
    'use strict';

    // Páginas públicas (não precisam autenticação)
    const publicPages = [
        'login.html', 
        'esqueci_senha.html', 
        'redefinir_senha.html', 
        'index.html'
    ];

    const currentPage = window.location.pathname.split('/').pop();

    // Se for página pública, sair
    if (publicPages.includes(currentPage) || currentPage === '') {
        return;
    }

    // AGUARDAR SessionManager estar pronto
    function checkProtectedPage() {
        // SessionManager é criado no DOMContentLoaded
        // Esperar que esteja disponível
        if (!window.sessionManager) {
            //Se ainda não foi carregado, aguardar
            setTimeout(checkProtectedPage, 100);
            return;
        }

        const mgr = window.sessionManager;

        // Verificar se autenticado
        if (!mgr.isAuthenticated()) {
            console.warn('⛔ Acesso negado a página protegida');
            sessionStorage.clear();
            window.location.href = 'login.html';
        } else {
            console.log('✅ Acesso autorizado');
        }
    }

    // Iniciar verificação quando page estiver pronta
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkProtectedPage);
    } else {
        // Document já carregou
        checkProtectedPage();
    }

    // Também escutar eventos de expiração
    if (window.sessionManager) {
        window.sessionManager.on('sessionExpired', () => {
            console.log('Sessão expirou, redirecionando...');
            window.location.href = 'login.html';
        });
    }
})();
```

---

### **3️⃣ UI Components (CORRIGIDO - Consumidores Passivos)**

#### **Sidebar / User Profile (sidebar-component.js)**

```javascript
/**
 * SIDEBAR COMPONENT
 * 
 * ✅ CORRIGIDO: Apenas renderiza, não controla sessão
 * ✅ CORRIGIDO: Escuta SessionManager
 * ❌ NÃO faz: fetch, validação, logout direto
 */

(function() {
    'use strict';

    // Aguardar SessionManager
    if (!window.sessionManager) {
        console.warn('SessionManager não disponível');
        return;
    }

    const mgr = window.sessionManager;

    // Inicializar UI com estado atual
    const user = mgr.getUser();
    if (user) {
        renderUserInfo(user);
    }

    // ESCUTAR mudanças (padrão observer)
    mgr.on('userDataChanged', (data) => {
        renderUserInfo(data.user);
    });

    // ESCUTAR expiração
    mgr.on('sessionExpired', () => {
        clearUserInfo();
    });

    /**
     * Renderizar informações do usuário
     * Função PURA: entrada → HTML
     */
    function renderUserInfo(user) {
        if (!user) return;

        const name = user.nome || 'Usuário';
        const initial = name.charAt(0).toUpperCase();

        const userProfile = document.getElementById('userProfile');
        if (userProfile) {
            userProfile.innerHTML = `
                <div class="user-avatar">${initial}</div>
                <div class="user-info">
                    <div class="user-name">${name}</div>
                    <div class="user-role">${user.permissao || user.funcao || 'Usuário'}</div>
                </div>
            `;
        }
    }

    /**
     * Limpar UI
     */
    function clearUserInfo() {
        const userProfile = document.getElementById('userProfile');
        if (userProfile) {
            userProfile.innerHTML = '';
        }
    }
})();
```

#### **Header / Logout Button (header-component.js)**

```javascript
/**
 * HEADER COMPONENT
 * 
 * ✅ CORRIGIDO: Botão logout dispara método do SessionManager
 * ✅ CORRIGIDO: Não faz fetch direto
 */

(function() {
    'use strict';

    if (!window.sessionManager) return;

    // Pegar referência do SessionManager
    const mgr = window.sessionManager;

    // Botão de logout
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            
            // Confirmar
            if (confirm('Tem certeza que deseja sair?')) {
                // CHAMAR o método centralizado do SessionManager
                mgr.logout();
            }
        });
    }

    // Exibir timer de sessão (exemplo)
    mgr.on('userDataChanged', (data) => {
        if (data.sessionTime) {
            const timerEl = document.getElementById('sessionTimer');
            if (timerEl) {
                timerEl.textContent = formatTime(data.sessionTime);
            }
        }
    });

    function formatTime(seconds) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }
})();
```

#### **Page Content (dashboard-simple.html)**

```html
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <title>Dashboard</title>
</head>
<body>

<div id="sidebar"><!-- Renderizado por sidebar-component.js --></div>

<div id="header">
    <div id="userProfile"><!-- Renderizado por sidebar-component.js --></div>
    <button id="logoutBtn">Sair</button>
</div>

<main>
    <div id="content">
        <!-- Conteúdo da página -->
    </div>
</main>

<!-- 1️⃣ Carregar SessionManager (PRIMEIRO) -->
<script src="js/session-manager-singleton-v2.js"></script>

<!-- 2️⃣ Carregar Auth Guard (SEGUNDO) -->
<script src="js/auth-guard-v2.js"></script>

<!-- 3️⃣ Carregar UI Components (TERCEIRO) -->
<script src="js/sidebar-component.js"></script>
<script src="js/header-component.js"></script>

</body>
</html>
```

---

## 🔄 FLUXOS END-TO-END

### **FLUXO 1: Login → Dashboard**

```
┌──────────────────┐
│ Usuário entra em│
│ login.html       │
└────────┬─────────┘
         ↓
┌──────────────────┐
│ Frontend: login  │
│ form enviado     │
│ POST login.php   │
└────────┬─────────┘
         ↓
┌──────────────────┐
│ Backend: cria    │
│ PHPSESSID        │
│ retorna sucesso  │
└────────┬─────────┘
         ↓
┌──────────────────┐
│ Frontend: Login  │
│ detecta sucesso  │
│ redireciona  →   │
│ dashboard.html   │
└────────┬─────────┘
         ↓
┌──────────────────────────────┐
│ Dashboard carrega            │
│                              │
│ 1. session-manager-v2.js     │
│    ├─ checkSession()         │
│    │  └─ fetch verificar (✅ 1x)
│    ├─ startPeriodicChecks()  │
│    └─ emit userDataChanged   │
│                              │
│ 2. auth-guard-v2.js          │
│    ├─ Consulta estado        │
│    │  (SEM fazer fetch!)     │
│    └─ Autoriza acesso        │
│                              │
│ 3. sidebar-component.js      │
│    ├─ Escuta evento          │
│    ├─ renderUserInfo()       │
│    └─ Exibe nome/avatar      │
│                              │
│ 4. header-component.js       │
│    ├─ Renderiza header       │
│    └─ Botão logout pronto    │
│                              │
└────────┬─────────────────────┘
         ↓
✅ Dashboard pronto, usuário autenticado
   Requisições: 1 (verificar apenas)
```

---

### **FLUXO 2: Navegar Dashboard → Protocolo**

```
usuário clica em "Protocolo" (link)
         ↓
┌──────────────────────────────┐
│ Protocolo.html carrega       │
│                              │
│ ⚠️ SessionManager é o MESMO  │
│    (não cria novo!)          │
│    (reutiliza instância)     │
│                              │
│ • checkSession() roda em 60s │
│   (próxima verificação)      │
│ • listeners já estão ativos  │
│                              │
│ auth-guard-v2.js:            │
│ ✅ Consulta estado           │
│    (SEM fazer fetch!)        │
│                              │
│ sidebar-component.js:        │
│ ✅ Renderiza (dados já lá)   │
│                              │
│ header-component.js:         │
│ ✅ Renderiza (dados já lá)   │
│                              │
└────────┬─────────────────────┘
         ↓
✅ Protocolo pronto
   Requisições: 0 (nenhuma nova)
   Estado: 100% compartilhado
```

---

### **FLUXO 3: Sessão Expira (ou clock em Logout)**

```
60s passaram
         ↓
┌──────────────────────────────┐
│ SessionManager.checkSession()│
│ fetch verificar_sessao...    │
│                              │
│ Backend retorna:             │
│ { sucesso: false,            │
│   sessao_ativa: false }      │
│                              │
│ SessionManager:              │
│ 1. sessionActive = false     │
│ 2. emit sessionExpired()     │
│ 3. clearPersistedState()     │
│ 4. stopPeriodicChecks()      │
└────────┬─────────────────────┘
         ↓
┌──────────────────────────────┐
│ UI Components escutam        │
│ on('sessionExpired')         │
│                              │
│ sidebar-component.js:        │
│ → clearUserInfo()            │
│                              │
│ header-component.js:         │
│ → esconde info               │
└────────┬─────────────────────┘
         ↓
┌──────────────────────────────┐
│ auth-guard-v2.js ou          │
│ SessionManager.handleExpired │
│                              │
│ → window.location = login.html
│                              │
└────────┬─────────────────────┘
         ↓
✅ Redirecionado para login
   Requisições totais: 1/60s (verificação contínua)
   Estado limpo
```

---

### **FLUXO 4: Logout Manual**

```
Usuário clica botão "Sair"
         ↓
┌──────────────────────────────┐
│ header-component.js          │
│ logoutBtn.addEventListener() │
│                              │
│ confirm('Tem certeza?')      │
│ mgr.logout()                 │
│ └─ Chama SessionManager!     │
└────────┬─────────────────────┘
         ↓
┌──────────────────────────────┐
│ SessionManager.logout()      │
│                              │
│ 1. fetch logout.php (1x)     │
│ 2. sessionActive = false     │
│ 3. currentUser = null        │
│ 4. clearPersistedState()     │
│ 5. stopPeriodicChecks()      │
│ 6. emit('sessionExpired')    │
│ 7. setTimeout redirect       │
└────────┬─────────────────────┘
         ↓
┌──────────────────────────────┐
│ UI Components escutam        │
│ on('sessionExpired')         │
│                              │
│ → Limpam UI                  │
│ → Mostram "Desconectando..." │
└────────┬─────────────────────┘
         ↓
┌──────────────────────────────┐
│ Após 300ms:                  │
│ window.location = login.html │
└────────┬─────────────────────┘
         ↓
✅ Login screen
   Requisições: 1 (logout)
   Logout centralizado e consistente
```

---

## ✅ CHECKLIST DE MIGRAÇÃO

### **Fase 1: Preparação (Week 1)**

- [ ] Criar `session-manager-singleton-v2.js` (código novo)
- [ ] Criar `auth-guard-v2.js` (código novo)
- [ ] Criar `sidebar-component.js` (código novo)
- [ ] Criar `header-component.js` (código novo)
- [ ] Criar template `template-dashboard-v2.html`

### **Fase 2: Migração de Página (Week 2-3)**

Converter CADA página:
1. [ ] dashboard.html
2. [ ] protocolo.html
3. [ ] estoque.html
4. [ ] inventario.html
5. [ ] marketplace_admin.html
6. [ ] ... demais

Para cada página:
- [ ] Remover `auth-guard.js` antigo
- [ ] Remover `session-manager-singleton.js` antigo
- [ ] Remover listeners locais (dashboard, protocolo, etc)
- [ ] Adicionar `session-manager-v2.js` ANTES de `auth-guard-v2.js`
- [ ] Adicionar `sidebar-component.js`
- [ ] Adicionar `header-component.js`
- [ ] Testar estado compartilhado entre abas
- [ ] Verificar console (zero erros)

### **Fase 3: Testes (Week 4)**

- [ ] Login com múltiplas abas abertas
- [ ] Navegar entre páginas without reloading
- [ ] Verificar estado sincronizado entre abas
- [ ] Sessão expira → todos redirecionam
- [ ] Logout manual → todos desconectam
- [ ] Monitor HTTP: máximo 1 verificação/60s
- [ ] Monitor console: zero TypeErrors por 10+ minutos
- [ ] Verificar requisições duplicadas: ZERO

### **Fase 4: Deploy (Week 5)**

- [ ] Backup atual
- [ ] Deploy em staging
- [ ] Testes de UAT
- [ ] Deploy em produção

---

## 📊 RESUMO: O QUE MUDA

| Aspecto | HOJE (❌) | CORRIGIDO (✅) |
|---|---|---|
| **Verificação de Sessão** | auth-guard + SessionManager (2x) | SessionManager apenas (1x) |
| **Instâncias de SessionManager** | Uma por página (32) | Uma por aba (1-3) |
| **Logout** | 24 locais diferentes | 1 local centralizado |
| **UI renderiza** | ✅ Sim | ✅ Sim |
| **UI controla sessão** | ❌ Sim | ✅ NÃO |
| **Menu autentica** | ❌ Sim | ✅ NÃO |
| **Página faz fetch de sessão** | ❌ Sim | ✅ NÃO |
| **Sincronização entre abas** | ❌ Não | ✅ Sim |
| **Requisições HTTP/min** | ~20-40 (caótico) | ~2 (controlado) |
| **Estado compartilhado** | ❌ Não | ✅ Sim (localStorage) |
| **TypeErrors** | ❌ Sim | ✅ NÃO |
| **Manutenibilidade** | ❌ Baixa | ✅ Alta |

---

## 🎯 CRITÉRIO DE SUCESSO

A solução é VÁLIDA se:

1. ✅ **Uma instância**: `SessionManager.getInstance()` sempre retorna a mesma instância
2. ✅ **Sem duplicação**: Abrir 10 páginas simultâneas = máximo 2-3 requisições (não 20+)
3. ✅ **Logout consistente**: Botão logout em qualquer página → logout centralizado
4. ✅ **Sessão sincronizada**: Abrir 2 abas → uma expira → AMBAS redirecionam
5. ✅ **Zero TypeErrors**: 10+ minutos sem nenhum erro em console
6. ✅ **UI passiva**: Dashboard, Protocolo, etc. NÃO fazem fetch de sessão
7. ✅ **Menu meramente visual**: Menu apenas exibe nome/avatar (nada de autenticação)
8. ✅ **Páginas simples**: Páginas carregam, escutam eventos, renderizam

---

**FIM DA ANÁLISE ARQUITETURAL**

Este documento será a base para a implementação corrigida da arquitetura.
