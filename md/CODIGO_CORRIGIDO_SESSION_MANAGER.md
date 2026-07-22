# 🔧 CORREÇÕES ESPECÍFICAS - Código Pronto para Implementar

Este documento mostra EXATAMENTE o que mudar no `session-manager-core.js`.

---

## CORREÇÃO 1: Constructor - Lançar erro em vez de retornar

**Localização:** Linhas 33-40

**ANTES (❌ ERRADO):**
```javascript
constructor() {
    // Garantir singleton (rejeitar tentativas de criar novo)
    if (SessionManagerCore.instance && !SessionManagerCore.locked) {
        console.warn(
            '[SessionManager] ⚠️ Tentativa de criar 2ª instância! Retornando instância existente.'
        );
        return SessionManagerCore.instance;  // ❌ NÃO FAZER ISSO!
    }
```

**DEPOIS (✅ CORRETO):**
```javascript
constructor() {
    // Garantir singleton (rejeitar tentativas de criar novo)
    if (SessionManagerCore.instance) {
        throw new Error(
            '[SessionManager] ❌ SessionManagerCore já foi instanciado!'
        );
    }
```

---

## CORREÇÃO 2: Adicionar propriedades de estado

**Localização:** Linhas 52-62

**ANTES (❌ FALTAM):**
```javascript
// ═══ ESTADO ═══
this.isAuthenticated = false;
this.currentUser = null;
this.sessionExpireTime = null;
this.isFetching = false;          // Guard contra requests simultâneos
this.isInitialized = false;        // Se ja fez primeira verificação
```

**DEPOIS (✅ COMPLETO):**
```javascript
// ═══ ESTADO ═══
this.isAuthenticated = false;
this.currentUser = null;
this.sessionExpireTime = null;
this.isFetching = false;          // Guard contra requests simultâneos
this.isInitialized = false;        // Se ja fez primeira verificação

// ═══ ADICIONAIS ═══
this.lastError = null;            // Último erro que ocorreu
this.lastSuccessfulCheck = null;  // Timestamp (ms) do último check bem-sucedido
this.isOnline = navigator.onLine; // Flag de conectividade de rede
```

---

## CORREÇÃO 3: Adicionar evento sessionRenewed

**Localização:** Linhas 63-67

**ANTES (❌ FALTANDO sessionRenewed):**
```javascript
// ═══ EVENT SYSTEM ═══
this.listeners = new Map();
this.listeners.set('userDataChanged', []);
this.listeners.set('sessionExpired', []);
this.listeners.set('error', []);
```

**DEPOIS (✅ COM TODOS OS EVENTOS):**
```javascript
// ═══ EVENT SYSTEM ═══
this.listeners = new Map();
this.listeners.set('userDataChanged', []);
this.listeners.set('sessionExpired', []);
this.listeners.set('error', []);
this.listeners.set('sessionRenewed', []);  // ← ADICIONAR
```

---

## CORREÇÃO 4: initialize() - Adicionar listeners de rede

**Localização:** Linhas 91-127

**ANTES (❌ SEM LISTENERS DE REDE):**
```javascript
async initialize() {
    if (this.isInitialized) {
        console.log('[SessionManager] ℹ️ Já inicializado, pulando...');
        return;
    }

    console.log('[SessionManager] Inicializando...');

    // Tentar recuperar estado anterior (localStorage)
    const persisted = this.loadPersistedState();
```

**DEPOIS (✅ COM LISTENERS):**
```javascript
async initialize() {
    if (this.isInitialized) {
        console.log('[SessionManager] ℹ️ Já inicializado, pulando...');
        return;
    }

    console.log('[SessionManager] Inicializando...');

    // ═══ ADICIONAR: Monitorar conectividade de rede
    window.addEventListener('online', () => {
        console.log('[SessionManager] 📡 Connexão de rede restaurada');
        this.isOnline = true;
        this.checkSession(); // Tentar reconectar
    });

    window.addEventListener('offline', () => {
        console.log('[SessionManager] 🔌 Conexão de rede perdida');
        this.isOnline = false;
    });
    // ═══ FIM ADIÇÃO

    // Tentar recuperar estado anterior (localStorage)
    const persisted = this.loadPersistedState();
```

---

## CORREÇÃO 5: checkSession() - Melhorar tratamento de erros

**Localização:** Linhas 170-183

**ANTES (❌ SEM DIFERENCIAÇÃO):**
```javascript
        } catch (error) {
            console.error('[SessionManager] ❌ Erro ao verificar sessão:', error.message);
            this.emit('error', { message: error.message });

            // Se timeout, rejeitar
            if (error.name === 'AbortError') {
                console.error('[SessionManager] ❌ Timeout na verificação');
                this.isFetching = false;
                return this.isAuthenticated; // Retornar estado anterior
            }

            this.isFetching = false;
            return false;
        }
```

**DEPOIS (✅ COM DIFERENCIAÇÃO):**
```javascript
        } catch (error) {
            console.error('[SessionManager] ❌ Erro ao verificar sessão:', error.message);
            
            // Registrar último erro
            this.lastError = {
                message: error.message,
                type: error.name || 'unknown',
                timestamp: Date.now()
            };

            // TIMEOUT: Servidor não respondeu a tempo
            if (error.name === 'AbortError') {
                console.warn('[SessionManager] ⚠️ Timeout na verificação (10s)');
                this.emit('error', { 
                    type: 'timeout',
                    message: 'Servidor não respondeu em 10s'
                });
                this.isFetching = false;
                return this.isAuthenticated; // Manter sessão
            }

            // ERRO DE REDE: Sem conexão com server
            if (error instanceof TypeError) {
                console.warn('[SessionManager] ⚠️ Erro de rede durante verificação');
                this.isOnline = false;
                this.emit('error', { 
                    type: 'network',
                    message: error.message
                });
                this.isFetching = false;
                return this.isAuthenticated; // Manter sessão
            }

            // ERRO DESCONHECIDO: Fazer logout seguro
            console.error('[SessionManager] ❌ Erro desconhecido na verificação:', error);
            this.handleSessionExpired('unknown_error');
            this.isFetching = false;
            return false;
        }
```

---

## CORREÇÃO 6: renewSession() - Adicionar credentials e re-fetch de dados

**Localização:** Linhas 188-225

**ANTES (❌ FALTAM CREDENTIALS):**
```javascript
    async renewSession() {
        if (!this.isAuthenticated) {
            console.log('[SessionManager] ℹ️ Não autenticado, pulando renovação');
            return false;
        }

        if (this.isFetching) {
            console.log('[SessionManager] ℹ️ Renovação já em progresso');
            return false;
        }

        try {
            console.log('[SessionManager] 🔄 Renovando sessão...');

            this.isFetching = true;

            const formData = new FormData();
            formData.append('acao', 'renovar');

            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), this.TIMEOUT);

            const response = await fetch(
                `${this.API_BASE}verificar_sessao_completa.php`,
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'include',
                    signal: controller.signal
                }
            );

            clearTimeout(timeout);

            if (response.ok) {
                console.log('[SessionManager] ✅ Sessão renovada');
                this.isFetching = false;
                return true;
            } else {
                console.warn('[SessionManager] ⚠️ Renovação falhou:', response.status);
                this.isFetching = false;
                return false;
            }
        } catch (error) {
            console.error('[SessionManager] ❌ Erro ao renovar:', error.message);
            this.isFetching = false;
            return false;
        }
    }
```

**DEPOIS (✅ COMPLETO COM VALIDAÇÃO):**
```javascript
    async renewSession() {
        if (!this.isAuthenticated) {
            console.log('[SessionManager] ℹ️ Não autenticado, pulando renovação');
            return false;
        }

        if (this.isFetching) {
            console.log('[SessionManager] ℹ️ Renovação já em progresso');
            return false;
        }

        try {
            console.log('[SessionManager] 🔄 Renovando sessão...');

            this.isFetching = true;

            const formData = new FormData();
            formData.append('acao', 'renovar');

            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), this.TIMEOUT);

            const response = await fetch(
                `${this.API_BASE}verificar_sessao_completa.php`,
                {
                    method: 'POST',
                    body: formData,
                    credentials: 'include',  // ← IMPORTANTE: Enviar cookies
                    signal: controller.signal
                }
            );

            clearTimeout(timeout);

            // ═══ ADICIONAR: Validação e re-fetch de dados
            if (response.ok) {
                const data = await response.json();

                // Validar resposta
                if (!data.sucesso) {
                    console.warn('[SessionManager] ⚠️ Resposta inválida na renovação');
                    this.isFetching = false;
                    return false;
                }

                // Atualizar tempo de expiração
                if (data.sessao?.tempo_restante) {
                    this.sessionExpireTime = data.sessao.tempo_restante;
                }

                // Atualizar dados do usuário se veio na resposta
                if (data.usuario) {
                    this.currentUser = data.usuario;
                }

                // Registrar sucesso
                this.lastSuccessfulCheck = Date.now();

                // Emitir evento de renovação
                this.emit('sessionRenewed', { 
                    expireTime: this.sessionExpireTime,
                    user: this.currentUser
                });

                console.log('[SessionManager] ✅ Sessão renovada com sucesso');
                this.isFetching = false;
                return true;
            } else {
                console.warn('[SessionManager] ⚠️ Renovação falhou:', response.status);
                this.isFetching = false;
                return false;
            }
            // ═══ FIM ADIÇÃO
        } catch (error) {
            console.error('[SessionManager] ❌ Erro ao renovar:', error.message);
            this.isFetching = false;
            return false;
        }
    }
```

---

## CORREÇÃO 7: logout() - Adicionar credentials

**Localização:** Linhas 230-260

**ANTES (❌ SEM CREDENTIALS):**
```javascript
    async logout() {
        console.log('[SessionManager] 🚪 Fazendo logout...');

        try {
            // 1. Chamar API
            await fetch(`${this.API_BASE}logout.php`, {
                method: 'POST',
                credentials: 'include'  // ← JÁ TEM!
            }).catch(() => {
```

**STATUS:** ✅ ESTE ESTÁ CORRETO

---

## CORREÇÃO 8: persistState() - Remover dados sensíveis

**Localização:** Linhas 385-425

**ANTES (❌ INSEGURO):**
```javascript
    persistState() {
        try {
            localStorage.setItem(
                this.storageKey,
                JSON.stringify({
                    isAuthenticated: this.isAuthenticated,
                    currentUser: this.currentUser,  // ❌ DADOS SENSÍVEIS!
                    sessionExpireTime: this.sessionExpireTime,
                    timestamp: Date.now()
                })
            );
        } catch (e) {
            console.warn('[SessionManager] ⚠️ Erro ao persistir estado:', e.message);
        }
    }

    loadPersistedState() {
        try {
            const data = localStorage.getItem(this.storageKey);
            if (!data) return null;
            return JSON.parse(data);  // ❌ Sem validação
        } catch (e) {
            console.warn('[SessionManager] ⚠️ Erro ao carregar estado persisted:', e);
            return null;
        }
    }
```

**DEPOIS (✅ SEGURO):**
```javascript
    persistState() {
        try {
            // SEGURANÇA: Só guardar flag de autenticação, NUNCA dados sensíveis
            localStorage.setItem(
                this.storageKey,
                JSON.stringify({
                    isAuthenticated: this.isAuthenticated,
                    // ❌ NÃO INCLUIR: currentUser (dados sensíveis)
                    // ❌ NÃO INCLUIR: sessionExpireTime (informação sensível)
                    timestamp: Date.now()
                })
            );
        } catch (e) {
            console.warn('[SessionManager] ⚠️ Erro ao persistir estado:', e.message);
        }
    }

    loadPersistedState() {
        try {
            const data = localStorage.getItem(this.storageKey);
            if (!data) return null;

            const parsed = JSON.parse(data);
            const age = Date.now() - (parsed.timestamp || 0);

            // ═══ ADICIONAR: Validação de validade
            // Descartar se mais velho que 24h
            if (age > 86400000) {
                console.log('[SessionManager] ℹ️ Estado persistido expirou (24h+)');
                this.clearPersistedState();
                return null;
            }
            // ═══ FIM ADIÇÃO

            return parsed;
        } catch (e) {
            console.warn('[SessionManager] ⚠️ Erro ao carregar persisted:', e);
            this.clearPersistedState();
            return null;
        }
    }
```

---

## CORREÇÃO 9: isPublicPage() - Expandir lista

**Localização:** Linhas 408-417

**ANTES (❌ PÁGINAS FALTANDO):**
```javascript
    isPublicPage() {
        const publicPages = [
            'login.html',
            'esqueci_senha.html',
            'redefinir_senha.html',
            'index.html',
            'register.html'
        ];
        const pathname = window.location.pathname;
        const page = pathname.split('/').pop();
        return publicPages.includes(page) || page === '' || page === 'frontend/';
    }
```

**DEPOIS (✅ LISTA COMPLETA):**
```javascript
    isPublicPage() {
        const publicPages = [
            'login.html',
            'login_morador.html',          // ← ADICIONAR
            'login_fornecedor.html',       // ← ADICIONAR
            'esqueci_senha.html',
            'redefinir_senha.html',
            'index.html',
            'register.html',
            'registro.html'                // ← ADICIONAR (variação)
        ];
        const pathname = window.location.pathname;
        const page = pathname.split('/').pop();
        return publicPages.includes(page) || page === '' || page === 'frontend/';
    }
```

---

## CORREÇÃO 10: Aumentar TIMEOUT

**Localização:** Linhas 44-50

**ANTES (❌ CURTO DEMAIS):**
```javascript
this.TIMEOUT = 10000;             // 10s timeout
```

**DEPOIS (✅ MAIS REALISTA):**
```javascript
this.TIMEOUT = 15000;             // 15s timeout (mais robusto)
```

**JUSTIFICATIVA:**
- 10s é curto demais se servidor está preso
- 15s permite que servidor lento mas funcional responda
- Ainda é tempo razoável para user perceber

---

## RESUMO DE MUDANÇAS

| # | Tipo | Linhas | Severidade | Status |
|---|------|--------|-----------|--------|
| 1 | Constructor | 35-40 | 🔴 CRÍTICO | ✅ Pronto |
| 2 | Propriedades | 52-62 | 🟠 ALTO | ✅ Pronto |
| 3 | Eventos | 63-67 | 🟡 MÉDIO | ✅ Pronto |
| 4 | initialize() | 91-127 | 🟠 ALTO | ✅ Pronto |
| 5 | checkSession() | 170-183 | 🔴 CRÍTICO | ✅ Pronto |
| 6 | renewSession() | 188-225 | 🔴 CRÍTICO | ✅ Pronto |
| 7 | logout() | 230-260 | ✅ OK | - |
| 8 | persistState() | 385-425 | 🔴 CRÍTICO | ✅ Pronto |
| 9 | isPublicPage() | 408-417 | 🟡 MÉDIO | ✅ Pronto |
| 10 | TIMEOUT | 48 | 🟡 MÉDIO | ✅ Pronto |

---

## ORDEM DE APLICAÇÃO

1. **PRIMEIRO:** Corrigir P1 (localStorage)
2. **SEGUNDO:** Corrigir P2 (constructor)
3. **TERCEIRO:** P5, P6 (checkSession, renewSession)
4. **QUARTO:** Outras correções
5. **QUINTO:** Integrar em todas as páginas

---

## TESTE APÓS CADA CORREÇÃO

```javascript
// Console do navegador - Testar:

// 1. Verificar singleton
const manager1 = SessionManagerCore.getInstance();
const manager2 = SessionManagerCore.getInstance();
console.log(manager1 === manager2); // Deve ser true

// 2. Verificar listeners
manager1.on('userDataChanged', (data) => {
    console.log('User changed:', data);
});

// 3. Testar renovação
manager1.renewSession();

// 4. Verificar localStorage (SEM dados sensíveis!)
console.log(localStorage.getItem('sessionManagerState_v2'));
// Deve ser: {"isAuthenticated":true,"timestamp":1234567890}
// NÃO deve ter: currentUser, sessionExpireTime, email, senha, etc.
```

---

## ✅ CHECKLIST FINAL

Antes de considerar completo:

- [ ] Todos os 10 pontos corrigidos no session-manager-core.js
- [ ] Nenhum erro no console do navegador ao carregar página
- [ ] localStorage SÓ contém isAuthenticated e timestamp
- [ ] Login/Logout funcionam sem erros
- [ ] Renovação automática funciona (cada 5min)
- [ ] Teste offline/online muda flag isOnline
- [ ] Timeout de 10s funciona sem deslogar user
- [ ] Integrado em TODAS as ~80 páginas
- [ ] session-manager-singleton.js removido de todas as páginas
- [ ] Testes no navegador real (não apenas DevTools)
