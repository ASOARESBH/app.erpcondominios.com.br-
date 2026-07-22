# 🛠️ GUIA DE IMPLEMENTAÇÃO: Fase 1 (Preparação)

**Objetivo:** Criar os 4 arquivos base para a nova arquitetura

---

## 📁 Arquivos a Criar

```
frontend/js/
├── session-manager-core.js       (NOVO - Nucleo)
├── auth-guard-core.js            (NOVO - Protetor)
├── ui-component-base.js          (NOVO - Base para componentes)
└── app-bootstrap.js              (NOVO - Inicializador)
```

---

## 1️⃣ SessionManagerCore (Núcleo)

**Arquivo:** `frontend/js/session-manager-core.js`

```javascript
/**
 * ============================================================
 * SESSION MANAGER CORE
 * ============================================================
 * 
 * NÚCLEO de gerenciamento de sessão
 * 
 * ✅ RESPONSABILIDADES:
 *    - Verificar sessão (1x por página)
 *    - Renovar sessão (5min ou atividade)
 *    - Manter estado centralizado
 *    - Emitir eventos para UI
 *    - Fazer logout centralizado
 * 
 * ❌ NÃO FARÁ:
 *    - Renderizar HTML
 *    - Validar entrada
 *    - Verificar rotas
 */

class SessionManagerCore {
    static instance = null;

    constructor() {
        // Evitar múltiplas instâncias
        if (SessionManagerCore.instance) {
            return SessionManagerCore.instance;
        }

        // Configuração
        this.apiBase = '../api/';
        this.CHECK_INTERVAL = 60 * 1000;       // 60s
        this.RENEW_INTERVAL = 5 * 60 * 1000;   // 5min
        this.ACTIVITY_TIMEOUT = 30 * 60 * 1000; // 30min

        // Estado
        this.sessionActive = false;
        this.currentUser = null;
        this.isFetching = false;
        this.lastActivityTime = Date.now();

        // Timers
        this.checkTimer = null;
        this.renewTimer = null;

        // Event listeners
        this.listeners = new Map();
        this.registerEventType('userDataChanged');
        this.registerEventType('sessionExpired');
        this.registerEventType('error');

        // Persist
        this.persistKey = '__session_state_v2__';

        SessionManagerCore.instance = this;
        return this;
    }

    /**
     * Singleton getter
     */
    static getInstance() {
        if (!SessionManagerCore.instance) {
            SessionManagerCore.instance = new SessionManagerCore();
        }
        return SessionManagerCore.instance;
    }

    /**
     * Inicializar (chamado ÚNICA VEZ)
     */
    async init() {
        console.log('[SessionCore] Inicializando...');

        // 1. Carregar estado persistido
        const stored = this.loadPersistedState();
        if (stored && stored.sessionActive) {
            this.sessionActive = stored.sessionActive;
            this.currentUser = stored.currentUser;
            console.log('[SessionCore] Estado recuperado do localStorage');
            this.emit('userDataChanged', this.currentUser);
        }

        // 2. Verificar sessão inicial
        await this.checkSession();

        // 3. Iniciar verificações periódicas (se não em página pública)
        if (!this.isPublicPage()) {
            this.startPeriodicChecks();
            console.log('[SessionCore] Verificações periódicas iniciadas');
        }

        console.log('[SessionCore] ✅ Inicializado');
        return this.sessionActive;
    }

    /**
     * Verificar sessão (ÚNICO lugar com fetch)
     */
    async checkSession() {
        // Lock against concurrent requests
        if (this.isFetching) {
            console.log('[SessionCore] Requisição anterior ainda ativa, pulando...');
            return false;
        }

        this.isFetching = true;

        try {
            console.log('[SessionCore] Verificando sessão...');

            const response = await fetch(
                `${this.apiBase}verificar_sessao_completa.php`,
                {
                    method: 'GET',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    timeout: 10000
                }
            );

            if (!response.ok) {
                console.warn('[SessionCore] Response não OK:', response.status);
                return this.handleSessionExpired();
            }

            const data = await response.json();

            if (data.sucesso && data.sessao_ativa) {
                // Sessão ativa
                this.sessionActive = true;
                this.currentUser = data.usuario || null;
                this.persistState();

                console.log('[SessionCore] ✅ Sessão ativa:', this.currentUser?.nome);
                this.emit('userDataChanged', this.currentUser);

                this.isFetching = false;
                return true;
            } else {
                // Sessão inativa
                console.warn('[SessionCore] Sessão inativa no servidor');
                return this.handleSessionExpired();
            }
        } catch (error) {
            console.error('[SessionCore] Erro ao verificar:', error);
            this.emit('error', { message: error.message });
            this.isFetching = false;
            return false;
        }
    }

    /**
     * Renovar sessão
     */
    async renewSession() {
        if (!this.sessionActive || this.isFetching) {
            return false;
        }

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

            const success = response.ok;
            if (success) {
                console.log('[SessionCore] Sessão renovada');
            }
            return success;
        } catch (error) {
            console.error('[SessionCore] Erro ao renovar:', error);
            return false;
        }
    }

    /**
     * Iniciar verificações periódicas
     */
    startPeriodicChecks() {
        if (this.checkTimer) return; // Já rodando

        console.log('[SessionCore] Iniciando verificações periódicas');

        // Verificar a cada 60s
        this.checkTimer = setInterval(
            () => this.checkSession(),
            this.CHECK_INTERVAL
        );

        // Renovar a cada 5min
        this.renewTimer = setInterval(
            () => this.renewSession(),
            this.RENEW_INTERVAL
        );

        // Rastrear atividade do usuário
        this.trackActivity();
    }

    /**
     * Parar verificações
     */
    stopPeriodicChecks() {
        if (this.checkTimer) {
            clearInterval(this.checkTimer);
            this.checkTimer = null;
        }
        if (this.renewTimer) {
            clearInterval(this.renewTimer);
            this.renewTimer = null;
        }
        console.log('[SessionCore] Verificações periódicas paradas');
    }

    /**
     * Rastrear atividade
     */
    trackActivity() {
        const events = ['mousedown', 'keydown', 'scroll', 'touchstart'];

        events.forEach(evt => {
            document.addEventListener(evt, () => {
                const now = Date.now();
                // Renovar se passaram 30min sem atividade
                if (now - this.lastActivityTime > this.ACTIVITY_TIMEOUT) {
                    console.log('[SessionCore] Renovando por atividade');
                    this.renewSession();
                }
                this.lastActivityTime = now;
            }, { passive: true });
        });
    }

    /**
     * LOGOUT (CENTRALIZADO)
     */
    async logout() {
        console.log('[SessionCore] Fazendo logout...');

        // 1. Chamar API
        try {
            await fetch(`${this.apiBase}logout.php`, {
                method: 'POST',
                credentials: 'include'
            }).catch(() => {});
        } catch (e) {
            console.error('[SessionCore] Erro ao chamar logout.php:', e);
        }

        // 2. Limpar estado local
        this.sessionActive = false;
        this.currentUser = null;
        this.clearPersistedState();
        this.stopPeriodicChecks();

        // 3. Emitir evento
        this.emit('sessionExpired', {});

        // 4. Redirecionar
        console.log('[SessionCore] Redirecionando para login...');
        setTimeout(() => {
            window.location.href = 'login.html';
        }, 300);
    }

    /**
     * Lidar com sessão expirada
     */
    handleSessionExpired() {
        console.log('[SessionCore] Sessão expirada!');

        this.sessionActive = false;
        this.currentUser = null;
        this.clearPersistedState();
        this.stopPeriodicChecks();

        // Emitir evento
        this.emit('sessionExpired', {});

        // Se não em página pública, redirecionar
        if (!this.isPublicPage()) {
            setTimeout(() => {
                window.location.href = 'login.html';
            }, 300);
        }

        return false;
    }

    /**
     * EVENT SYSTEM
     */
    registerEventType(eventName) {
        if (!this.listeners.has(eventName)) {
            this.listeners.set(eventName, []);
        }
    }

    on(eventName, callback) {
        if (typeof callback !== 'function') {
            console.warn('[SessionCore] Callback não é função');
            return;
        }

        this.registerEventType(eventName);
        this.listeners.get(eventName).push(callback);

        // Retornar unsubscribe function
        return () => {
            const callbacks = this.listeners.get(eventName);
            const index = callbacks.indexOf(callback);
            if (index > -1) {
                callbacks.splice(index, 1);
            }
        };
    }

    emit(eventName, data) {
        if (!this.listeners.has(eventName)) {
            return;
        }

        const callbacks = this.listeners.get(eventName);
        console.log(`[SessionCore] Emitindo evento: ${eventName} (${callbacks.length} listeners)`);

        callbacks.forEach(callback => {
            try {
                callback(data);
            } catch (err) {
                console.error(`[SessionCore] Erro em listener ${eventName}:`, err);
            }
        });
    }

    /**
     * GETTERS
     */
    getUser() {
        return this.currentUser;
    }

    isAuthenticated() {
        return this.sessionActive && !!this.currentUser;
    }

    isSessionActive() {
        return this.sessionActive;
    }

    /**
     * PERSISTENCE
     */
    persistState() {
        try {
            localStorage.setItem(this.persistKey, JSON.stringify({
                sessionActive: this.sessionActive,
                currentUser: this.currentUser,
                timestamp: Date.now()
            }));
        } catch (e) {
            console.warn('[SessionCore] Erro ao persistir estado:', e);
        }
    }

    loadPersistedState() {
        try {
            const data = localStorage.getItem(this.persistKey);
            return data ? JSON.parse(data) : null;
        } catch (e) {
            console.warn('[SessionCore] Erro ao carregar estado:', e);
            return null;
        }
    }

    clearPersistedState() {
        try {
            localStorage.removeItem(this.persistKey);
        } catch (e) {}
    }

    /**
     * HELPERS
     */
    isPublicPage() {
        const publicPages = [
            'login.html',
            'esqueci_senha.html',
            'redefinir_senha.html',
            'index.html'
        ];
        const currentPage = window.location.pathname.split('/').pop();
        return publicPages.includes(currentPage) || currentPage === '';
    }
}

// Exportar globalmente
window.SessionManagerCore = SessionManagerCore;
```

---

## 2️⃣ AuthGuardCore (Protetor)

**Arquivo:** `frontend/js/auth-guard-core.js`

```javascript
/**
 * ============================================================
 * AUTH GUARD CORE
 * ============================================================
 * 
 * Protetor de rotas
 * 
 * ✅ RESPONSABILIDADES:
 *    - Verificar se página é protegida
 *    - Usar estado do SessionManager (SEM fetch!)
 *    - Redirecionar se não autenticado
 * 
 * ❌ NÃO FARÁ:
 *    - Fazer fetch de sessão
 *    - Renovar sessão
 *    - Emitir eventos
 */

(function() {
    'use strict';

    console.log('[AuthGuard] Iniciando proteção de rota...');

    // Páginas públicas
    const publicPages = [
        'login.html',
        'esqueci_senha.html',
        'redefinir_senha.html',
        'index.html'
    ];

    const currentPage = window.location.pathname.split('/').pop();

    // Se é página pública, sair
    if (publicPages.includes(currentPage) || currentPage === '') {
        console.log('[AuthGuard] Página pública, sem proteção necessária');
        return;
    }

    console.log(`[AuthGuard] Página protegida: ${currentPage}`);

    // Aguardar SessionManagerCore estar pronto
    function checkProtection() {
        if (!window.SessionManagerCore) {
            console.log('[AuthGuard] Aguardando SessionManagerCore...');
            setTimeout(checkProtection, 100);
            return;
        }

        const manager = SessionManagerCore.getInstance();

        // ⚠️ NÃO FAZER FETCH AQUI
        // Apenas verificar estado já carregado

        if (!manager.isAuthenticated()) {
            console.warn('[AuthGuard] ❌ Acesso negado, redirecionando para login...');
            sessionStorage.clear();
            window.location.href = 'login.html';
            return;
        }

        console.log('[AuthGuard] ✅ Acesso autorizado');

        // Também escutar expiração
        manager.on('sessionExpired', () => {
            console.log('[AuthGuard] Sessão expirou, redirecionando...');
            window.location.href = 'login.html';
        });
    }

    // Iniciar verificação quando document estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkProtection);
    } else {
        checkProtection();
    }
})();
```

---

## 3️⃣ UIComponentBase (Base para componentes)

**Arquivo:** `frontend/js/ui-component-base.js`

```javascript
/**
 * ============================================================
 * UI COMPONENT BASE
 * ============================================================
 * 
 * Classe base para componentes UI
 * 
 * ✅ Responsabilidades:
 *    - Escutar eventos do SessionManager
 *    - Renderizar dados
 *    - Gerenciar ciclo de vida
 * 
 * ❌ NÃO faz:
 *    - Fazer fetch
 *    - Controlar sessão
 *    - Validar estado
 * 
 * USO:
 *   class UserBadgeComponent extends UIComponentBase {
 *       init() { ... }
 *       render(userData) { ... }
 *   }
 */

class UIComponentBase {
    constructor(name) {
        this.name = name;
        this.manager = null;
        this.subscriptions = [];

        console.log(`[UI:${this.name}] Criado`);
    }

    /**
     * Inicializar componente
     */
    init() {
        console.log(`[UI:${this.name}] Inicializando...`);

        // Aguardar SessionManagerCore
        if (!window.SessionManagerCore) {
            setTimeout(() => this.init(), 100);
            return;
        }

        this.manager = SessionManagerCore.getInstance();

        // Renderizar com estado atual
        const user = this.manager.getUser();
        if (user) {
            this.onDataChanged(user);
        }

        // Escutar eventos
        this.subscribe('userDataChanged', (userData) => {
            this.onDataChanged(userData);
        });

        this.subscribe('sessionExpired', () => {
            this.onSessionExpired();
        });

        console.log(`[UI:${this.name}] ✅ Pronto`);
    }

    /**
     * Subscrever a evento
     */
    subscribe(eventName, callback) {
        const unsubscribe = this.manager.on(eventName, callback);
        this.subscriptions.push(unsubscribe);
        return unsubscribe;
    }

    /**
     * Destruir componente
     */
    destroy() {
        this.subscriptions.forEach(unsub => unsub());
        console.log(`[UI:${this.name}] Destruído`);
    }

    /**
     * Métodos a serem sobrescritos
     */
    onDataChanged(userData) {
        console.log(`[UI:${this.name}] Dados mudaram:`, userData);
    }

    onSessionExpired() {
        console.log(`[UI:${this.name}] Sessão expirou`);
    }
}

// Exportar globalmente
window.UIComponentBase = UIComponentBase;
```

---

## 4️⃣ AppBootstrap (Inicializador)

**Arquivo:** `frontend/js/app-bootstrap.js`

```javascript
/**
 * ============================================================
 * APP BOOTSTRAP
 * ============================================================
 * 
 * Inicializa a aplicação na ordem correta
 * 
 * Ordem de carregamento:
 * 1. SessionManagerCore (cria estado)
 * 2. AuthGuardCore (protege rotas)
 * 3. UIComponents (consomem estado)
 */

(function() {
    'use strict';

    console.log('='.repeat(60));
    console.log('[APP] Inicializando aplicação...');
    console.log('='.repeat(60));

    /**
     * Iniciar aplicação quando DOM estiver pronto
     */
    async function bootstrap() {
        console.log('[APP] DOM pronto, iniciando bootstrap...');

        // 1. Inicializar SessionManagerCore
        const manager = SessionManagerCore.getInstance();
        const authenticated = await manager.init();

        if (authenticated) {
            console.log('[APP] ✅ Usuário autenticado');
        } else {
            console.log('[APP] ⚠️ Usuário não autenticado');
        }

        // 2. AuthGuardCore vai rodar automaticamente (global scope)

        // 3. UIComponents vão inicializar automaticamente (DOMContentLoaded ou load)

        console.log('[APP] ✅ Bootstrap completo');
        console.log('='.repeat(60));
    }

    // Esperar DOM pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }

    // Também exportar para acesso externo
    window.appBootstrap = {
        getManager() {
            return SessionManagerCore.getInstance();
        }
    };
})();
```

---

## 📝 Template HTML Básico

**Arquivo:** `frontend/template-page-v2.html`

```html
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página Protegida</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>

<!-- SIDEBAR -->
<aside id="sidebar">
    <div id="userProfile">
        <!-- Renderizado por sidebar-component -->
    </div>
    <nav id="mainMenu">
        <!-- Menu aqui -->
    </nav>
</aside>

<!-- HEADER -->
<header id="header">
    <div id="userBadge">
        <!-- Renderizado por header-component -->
    </div>
    <button id="logoutBtn" class="btn-logout">Sair</button>
</header>

<!-- MAIN CONTENT -->
<main id="content">
    <!-- Seu conteúdo aqui -->
</main>

<!-- ============================================================ -->
<!-- SCRIPTS (ORDEM IMPORTANTE!)                                 -->
<!-- ============================================================ -->

<!-- 1️⃣ CORE (Session Management) -->
<script src="js/session-manager-core.js"></script>
<script src="js/auth-guard-core.js"></script>

<!-- 2️⃣ UI BASE -->
<script src="js/ui-component-base.js"></script>

<!-- 3️⃣ BOOTSTRAP -->
<script src="js/app-bootstrap.js"></script>

<!-- 4️⃣ UI COMPONENTS (exemplos básicos inline para teste) -->
<script>
// Componente: User Profile (Sidebar)
class UserProfileComponent extends UIComponentBase {
    onDataChanged(userData) {
        const el = document.getElementById('userProfile');
        if (!el || !userData) return;

        const name = userData.nome || 'Usuário';
        const initial = name.charAt(0).toUpperCase();
        const role = userData.permissao || userData.funcao || 'Usuário';

        el.innerHTML = `
            <div class="profile">
                <div class="avatar">${initial}</div>
                <div class="info">
                    <div class="name">${name}</div>
                    <div class="role">${role}</div>
                </div>
            </div>
        `;
    }
}

// Inicializar componentes
document.addEventListener('DOMContentLoaded', () => {
    const profile = new UserProfileComponent('UserProfile');
    profile.init();

    // Botão logout
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            if (confirm('Deseja realmente sair?')) {
                SessionManagerCore.getInstance().logout();
            }
        });
    }
});
</script>

<!-- 5️⃣ PAGE-SPECIFIC SCRIPTS -->
<!-- Seu código da página aqui -->

</body>
</html>
```

---

## ✅ Checklist de Implementação Fase 1

- [ ] Criar `session-manager-core.js`
- [ ] Criar `auth-guard-core.js`
- [ ] Criar `ui-component-base.js`
- [ ] Criar `app-bootstrap.js`
- [ ] Criar `template-page-v2.html`
- [ ] Testar em navegador (console limpo)
- [ ] Verificar estado em 2 abas
- [ ] Validar logout centralizado

---

## 🧪 Testes Básicos (Console)

```javascript
// Test 1: Singleton
SessionManagerCore.getInstance() === SessionManagerCore.getInstance()
// Esperado: true

// Test 2: Estado
SessionManagerCore.getInstance().getUser()
// Esperado: { nome: "...", permissao: "...", ... }

// Test 3: Listeners
SessionManagerCore.getInstance().on('userDataChanged', data => {
    console.log('Evento capturado:', data);
});
// Esperado: listener registrado

// Test 4: Logout
SessionManagerCore.getInstance().logout()
// Esperado: redirect para login.html
```

---

**Próxima Etapa:** Adaptar páginas existentes para usar novo template
