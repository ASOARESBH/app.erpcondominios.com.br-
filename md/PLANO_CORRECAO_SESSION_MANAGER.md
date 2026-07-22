# 🛠️ PLANO DE CORREÇÃO - Session Manager Core

## ⚠️ STATUS ATUAL

**Situação:**
- ✅ Arquivo `session-manager-core.js` criado
- ❌ **ZERO páginas usando o arquivo** (todas usam `session-manager-singleton.js`)
- ❌ **10 problemas críticos/altos encontrados**

---

## 🔴 PROBLEMAS CRÍTICOS A CORRIGIR

### P1: Dados Sensíveis em localStorage

**LOCAL:** [session-manager-core.js](session-manager-core.js#L388-L397)

**PROBLEMA:**
```javascript
// ❌ INSEGURO - Senha/email do usuário em localStorage em TEXTO PLANO
localStorage.setItem(this.storageKey, JSON.stringify({
    isAuthenticated: this.isAuthenticated,
    currentUser: this.currentUser,  // ← DADOS SENSÍVEIS!
    sessionExpireTime: this.sessionExpireTime,
    timestamp: Date.now()
}));
```

**RISCO:** XSS attack pode roubar dados do usuário

**SOLUÇÃO:**
```javascript
// ✅ SEGURO - Só guardar flag de autenticação
persistState() {
    try {
        localStorage.setItem(this.storageKey, JSON.stringify({
            isAuthenticated: this.isAuthenticated,
            // ❌ NUNCA: currentUser (dados sensíveis)
            // ❌ NUNCA: sessionExpireTime (info sensível)
            timestamp: Date.now()
        }));
    } catch (e) {
        console.warn('[SessionManager] ⚠️ Erro ao persistir:', e.message);
    }
}

loadPersistedState() {
    try {
        const data = localStorage.getItem(this.storageKey);
        if (!data) return null;
        
        const parsed = JSON.parse(data);
        const age = Date.now() - (parsed.timestamp || 0);
        
        // Descartar se mais velho que 24h
        if (age > 86400000) {
            this.clearPersistedState();
            return null;
        }
        
        return parsed;
    } catch (e) {
        this.clearPersistedState();
        return null;
    }
}
```

**IMPACTO:** ALTER SESSION-MANAGER-CORE.JS

---

### P2: Constructor retorna (Anti-pattern)

**LOCAL:** [session-manager-core.js](session-manager-core.js#L35-L40)

**PROBLEMA:**
```javascript
constructor() {
    if (SessionManagerCore.instance && !SessionManagerCore.locked) {
        console.warn('[SessionManager] ⚠️ Tentativa de criar 2ª instância!');
        return SessionManagerCore.instance;  // ❌ Constructor NÃO deve retornar!
    }
    // ... resto do constructor
}
```

**QUANDO CHAMA `new SessionManagerCore()`:**
1. Se já existe instância, retorna ela
2. Caller pensa que criou nova, mas recebeu antiga
3. Comportamento confuso e anti-pattern

**SOLUÇÃO:**
```javascript
constructor() {
    if (SessionManagerCore.instance) {
        throw new Error('[SessionManager] ❌ SessionManagerCore já foi instanciado! Use getInstance() em vez de new.');
    }
    
    // ... resto do constructor ...
    
    SessionManagerCore.instance = this;
    SessionManagerCore.locked = true;
}
```

**IMPACTO:** ALTER SESSION-MANAGER-CORE.JS

---

### P3: Endpoint não verificado

**LOCAL:** [session-manager-core.js](session-manager-core.js#L140), [session-manager-core.js](session-manager-core.js#L207)

**PROBLEMA:**
```javascript
const response = await fetch(
    `${this.API_BASE}verificar_sessao_completa.php`,  // ❌ Arquivo existe?
    // ...
);
```

**VERIFICAÇÃO NECESSÁRIA:**
```bash
# Validar se arquivo existe:
ls -la /xampp/htdocs/dashboard/app.erpcondominios.com.br/api/verificar_sessao_completa.php

# Se não existir, usar:
# - api/api_validar_token.php
# - api/api_usuario_logado.php
# - Ou outro que valida sessão
```

**STATUS:** ⚠️ **AGUARDANDO CONFIRMAÇÃO**

**IMPACTO:** 
- Se arquivo não existe → fetch falha → todos logados são deslogados
- CRÍTICO DE ALTA PRIORIDADE

---

### P4: Missing credentials em POST

**LOCAL:** [session-manager-core.js](session-manager-core.js#L207-L213)

**PROBLEMA:**
```javascript
const response = await fetch(
    `${this.API_BASE}verificar_sessao_completa.php`,
    {
        method: 'POST',
        body: formData,
        // ❌ FALTA: credentials: 'include'
        signal: controller.signal
    }
);
```

**IMPACTO:** Cookies de sessão não são enviados, servidor rejeita como não autenticado

**SOLUÇÃO:**
Adicionar `credentials: 'include'` em TODOS os fetch POST/PUT/DELETE:

```javascript
const response = await fetch(
    `${this.API_BASE}verificar_sessao_completa.php`,
    {
        method: 'POST',
        body: formData,
        credentials: 'include',  // ← ADICIONAR
        signal: controller.signal
    }
);
```

**IMPACTO:** ALTER SESSION-MANAGER-CORE.JS

---

### P5: Logout sem credentials

**LOCAL:** [session-manager-core.js](session-manager-core.js#L240-L245)

**PROBLEMA:**
```javascript
await fetch(`${this.API_BASE}logout.php`, {
    method: 'POST',
    // ❌ FALTA: credentials: 'include'
}).catch(() => {
    // ...
});
```

**SOLUÇÃO:**
```javascript
await fetch(`${this.API_BASE}logout.php`, {
    method: 'POST',
    credentials: 'include'  // ← ADICIONAR
}).catch(() => {
    // ...
});
```

**IMPACTO:** ALTER SESSION-MANAGER-CORE.JS

---

### P6: Sem diferenciação de erros

**LOCAL:** [session-manager-core.js](session-manager-core.js#L170-L183)

**PROBLEMA:**
```javascript
catch (error) {
    console.error('[SessionManager] ❌ Erro:', error.message);
    
    if (error.name === 'AbortError') {
        return this.isAuthenticated;  // Mantém estado (timeout)
    }
    
    this.isFetching = false;
    return false;  // ❌ Tudo que não é timeout vira false/logout
}
```

**CENÁRIOS:**
1. **Timeout** (AbortError) → Manter sessão ✅
2. **Erro de rede** (TypeError) → Manter sessão ✅
3. **Erro desconhecido** → Fazer logout ❌ ERRADO

**SOLUÇÃO COMPLETA:**
```javascript
catch (error) {
    console.error('[SessionManager] ❌ Erro ao verificar sessão:', error.message);
    
    this.lastError = {
        message: error.message,
        type: error.name || 'unknown',
        timestamp: Date.now()
    };
    
    // TIMEOUT: AbortError (controller.abort())
    if (error.name === 'AbortError') {
        console.warn('[SessionManager] ⚠️ Timeout na verificação (10s)');
        this.emit('error', { type: 'timeout', message: 'Servidor não respondeu em 10s' });
        this.isFetching = false;
        return this.isAuthenticated; // Manter estado anterior
    }
    
    // ERRO DE REDE: TypeError (fetch não consegue sair)
    if (error instanceof TypeError) {
        console.warn('[SessionManager] ⚠️ Erro de rede');
        this.isOnline = false;
        this.emit('error', { type: 'network', message: error.message });
        this.isFetching = false;
        return this.isAuthenticated; // Manter estado anterior
    }
    
    // ERRO DESCONHECIDO: logout seguro
    console.error('[SessionManager] ❌ Erro desconhecido', error);
    this.handleSessionExpired('unknown_error');
    this.isFetching = false;
    return false;
}
```

**IMPACTO:** ALTER SESSION-MANAGER-CORE.JS

---

### P7: renewSession incompleto

**LOCAL:** [session-manager-core.js](session-manager-core.js#L210-L216)

**PROBLEMA:**
```javascript
if (response.ok) {
    console.log('[SessionManager] ✅ Sessão renovada');
    this.isFetching = false;
    return true;
    // ❌ FALTA: Fazer refetch dos dados do usuário!
}
```

**IMPACTO:** Sessão renovada mas dados do usuário desatualizados, UI fica com dados velhos

**SOLUÇÃO:**
```javascript
if (response.ok) {
    const data = await response.json();
    
    // Validar resposta
    if (!data.sucesso) {
        console.warn('[SessionManager] ⚠️ Resposta inválida na renovação');
        this.isFetching = false;
        return false;
    }
    
    // Atualizar tempo de expirá
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
```

**IMPACTO:** ALTER SESSION-MANAGER-CORE.JS

---

## 🟠 PROBLEMAS ALTOS

### P8: isPublicPage() lista incompleta

**LOCAL:** [session-manager-core.js](session-manager-core.js#L408-L417)

**PÁGINAS PÚBLICAS FALTANDO:**
- `login_morador.html`
- `login_fornecedor.html`
- `registro.html` (variação de register)
- Possível: `portal.html`

**SOLUÇÃO:**
```javascript
isPublicPage() {
    const publicPages = [
        'login.html',
        'login_morador.html',
        'login_fornecedor.html',
        'esqueci_senha.html',
        'redefinir_senha.html',
        'index.html',
        'register.html',
        'registro.html'
    ];
    
    const pathname = window.location.pathname;
    const page = pathname.split('/').pop();
    
    return publicPages.includes(page) || 
           page === '' || 
           page === 'frontend/';
}
```

**IMPACTO:** ALTER SESSION-MANAGER-CORE.JS

---

### P9: Faltam propriedades de estado

**LOCAL:** [session-manager-core.js](session-manager-core.js#L52-L62)

**ADICIONAR NO CONSTRUCTOR:**
```javascript
// ═══ ESTADO ───
this.isAuthenticated = false;
this.currentUser = null;
this.sessionExpireTime = null;
this.isFetching = false;
this.isInitialized = false;

// ❌ FALTA ABAIXO:
this.lastError = null;           // Rastrear último erro
this.lastSuccessfulCheck = null; // Timestamp do último check bem-sucedido
this.isOnline = navigator.onLine || true; // Flag de conectividade
```

**IMPACTO:** ALTER SESSION-MANAGER-CORE.JS

---

### P10: Falta listener de rede

**LOCAL:** [session-manager-core.js](session-manager-core.js#L91-L127)

**ADICIONAR EM initialize():**
```javascript
async initialize() {
    if (this.isInitialized) {
        console.log('[SessionManager] ℹ️ Já inicializado, pulando...');
        return;
    }

    console.log('[SessionManager] Inicializando...');
    
    // ← ADICIONAR AQUI
    // Escutar mudanças de conectividade
    window.addEventListener('online', () => {
        console.log('[SessionManager] 📡 Online detectado');
        this.isOnline = true;
        this.checkSession(); // Tentar reconectar
    });

    window.addEventListener('offline', () => {
        console.log('[SessionManager] 🔌 Offline detectado');
        this.isOnline = false;
    });
    // ← FIM DA ADIÇÃO

    // Resto do código...
}
```

**IMPACTO:** ALTER SESSION-MANAGER-CORE.JS

---

## 🔵 INTEGRAÇÃO NAS PÁGINAS

### CHECKLIST: Substituir em todas as páginas

**Páginas encontradas usando `session-manager-singleton.js`:**
1. ✅ [frontend/dashboard.html](frontend/dashboard.html#L486)
2. ✅ [frontend/estoque.html](frontend/estoque.html#L86)
3. ✅ [frontend/marketplace_admin.html](frontend/marketplace_admin.html#L114)
4. ✅ [frontend/protocolo.html](frontend/protocolo.html#L68)

**TODAS OUTRAS PÁGINAS:**
Procurar por `<script src="js/session-manager` e verificar se têm o import

**TOTAL DE PÁGINAS HTML:** ~80+ arquivos

**AÇÃO:** 
```html
<!-- ❌ REMOVER -->
<script src="js/session-manager-singleton.js"></script>

<!-- ✅ ADICIONAR -->
<script src="js/session-manager-core.js"></script>
```

**ORDEM CORRETA DE SCRIPTS:**
```html
<!-- 1. Core session manager (primeiro!) -->
<script src="js/session-manager-core.js"></script>

<!-- 2. Componentes que dependem de sessionManager -->
<script src="js/auth-guard.js"></script>
<script src="js/user-display.js"></script>
<script src="js/user-profile-sidebar.js"></script>

<!-- 3. Lógica da página -->
<script src="js/dashboard-logic.js"></script>
```

---

## ✅ CHECKLIST DE CORREÇÃO

### Pré-requisitos (Fazer ANTES de integrar)
- [ ] Confirmar que `verificar_sessao_completa.php` existe no `/api/`
- [ ] Ou atualizar para endpoint correto
- [ ] Revisar estrutura de resposta do API

### Corrigir session-manager-core.js
- [ ] P1: Remover dados sensíveis do localStorage
- [ ] P2: Lançar erro em constructor ao invés de retornar
- [ ] P4: Adicionar `credentials: 'include'` em POST renewSession
- [ ] P5: Adicionar `credentials: 'include'` em logout
- [ ] P6: Implementar diferenciação de erros (timeout vs rede vs desconhecido)
- [ ] P7: Fazer re-fetch de dados em renewSession
- [ ] P8: Expandir lista de isPublicPage
- [ ] P9: Adicionar propriedades: lastError, lastSuccessfulCheck, isOnline
- [ ] P10: Adicionar listeners de online/offline em initialize
- [ ] P3: Usar endpoint correto (confirmar qual é)

### Adicionar eventos
- [ ] Adicionar evento 'sessionRenewed' aos listeners

### Testes
- [ ] [ ] Simular timeout (servidor demora 20s)
- [ ] [ ] Simular offline (desligar rede)
- [ ] [ ] Simular sessão expirada
- [ ] [ ] Verificar localStorage após logout
- [ ] [ ] Verificar renovação automática a cada 5min

### Integração
- [ ] Integrar em todas as ~80 páginas HTML
- [ ] Remover `session-manager-singleton.js`
- [ ] Testar em navegador real
- [ ] Testar com DevTools Network throttled

---

## 📊 ESTIMATIVA DE ESFORÇO

| Tarefa | Duração |
|--------|---------|
| Corrigir problemas em core.js | 2-3h |
| Integrar em todas as páginas | 1-2h (automático se usar find/replace) |
| Testes funcionais | 2h |
| Testes de edge cases | 1-2h |
| **TOTAL** | **6-9 horas** |

---

## 🚨 RISCOS SE NÃO CORRIGIR

| Risco | Impacto |
|--------|---------|
| Dados sensíveis em localStorage | XSS attack roba credenciais |
| credentials faltando | Todas as requisições falham |
| Erro desconhecido = logout | Users deslogados aleatoriamente |
| renewSession incompleto | UI com dados velhos |
| Endpoint errado | Todos deslogados |

**RECOMENDAÇÃO:** Não colocar em produção até corrigir P1, P2, P3, P4, P5, P6
