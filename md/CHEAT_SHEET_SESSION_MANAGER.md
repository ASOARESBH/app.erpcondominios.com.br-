# ⚡ CHEAT SHEET - Session Manager Core

## 🎯 10 PROBLEMAS EM 30 SEGUNDOS

| # | Problema | Linha | Severidade | Ação |
|---|----------|-------|-----------|------|
| 1 | localStorage inseguro | 388-397 | 🔴 | Remove dados sensíveis |
| 2 | Constructor retorna | 35-40 | 🔴 | Lança erro |
| 3 | Endpoint errado | 140,207 | 🔴 | Confirmar qual usar |
| 4 | POST sem credentials | 207 | 🔴 | Adiciona `credentials: 'include'` |
| 5 | Sem erro diferenciação | 170-183 | 🔴 | Trata timeout/rede diferente |
| 6 | renewSession incompleto | 210-216 | 🔴 | Faz re-fetch de dados |
| 7 | logout sem credentials | 240 | 🟠 | Adiciona `credentials: 'include'` |
| 8 | isPublicPage incompleto | 408-417 | 🟡 | Adiciona mobile logins |
| 9 | Faltam propriedades | 52-62 | 🟡 | Adiciona lastError, isOnline |
| 10 | Sem listeners rede | 91 | 🟡 | Adiciona online/offline events |

---

## 📍 LOCALIZAÇÃO RÁPIDA

```
session-manager-core.js:

Linhas 35-40:        Constructor (P2) ← PERIGO: retorna
Linhas 48:           TIMEOUT = 10000 ← Aumentar para 15000
Linhas 52-62:        Estado (P9) ← Faltam lastError, isOnline
Linhas 63-67:        Listeners (P3) ← Adicionar 'sessionRenewed'
Linhas 91-127:       initialize() (P10) ← Adicionar listeners de rede
Linhas 140,207:      API_BASE endpoint (P3) ← Qual é correto?
Linhas 170-183:      checkSession erro (P5) ← Diferencial timeout/rede
Linhas 207:          POST renewSession (P4) ← Falta credentials
Linhas 210-216:      renewSession validação (P6) ← Incompleto
Linhas 240:          logout API (P7) ← Falta credentials (⚠️ já tem!)
Linhas 388-397:      persistState (P1) ← CRÍTICO: dados sensíveis!
Linhas 408-417:      isPublicPage (P8) ← Lista incompleta
```

---

## 🔧 FIXES ULTRA-RÁPIDOS

### FIX 1: localStorage seguro (P1)
```javascript
// REMOVER: currentUser, sessionExpireTime
// MANTER: isAuthenticated, timestamp

localStorage.setItem(this.storageKey, JSON.stringify({
    isAuthenticated: this.isAuthenticated,
    timestamp: Date.now()
}));
```

### FIX 2: Constructor (P2)
```javascript
// REMOVER: return SessionManagerCore.instance;
// ADICIONAR:
throw new Error('[SessionManager] ❌ Já foi instanciado!');
```

### FIX 3: POST credentials (P4, P7)
```javascript
// ADICIONAR em TODOS os fetch POST:
credentials: 'include',
```

### FIX 4: Erro diferenciação (P5)
```javascript
if (error.name === 'AbortError') {
    // Timeout: manter sessão ✅
    return this.isAuthenticated;
} else if (error instanceof TypeError) {
    // Rede: manter sessão ✅
    return this.isAuthenticated;
} else {
    // Outro: logout seguro
    this.handleSessionExpired('unknown_error');
}
```

### FIX 5: renewSession validação (P6)
```javascript
const data = await response.json();
if (data.sucesso && data.usuario) {
    this.currentUser = data.usuario;
    this.emit('sessionRenewed', { ... });
}
```

### FIX 6: Listeners rede (P10)
```javascript
window.addEventListener('online', () => {
    this.isOnline = true;
    this.checkSession();
});

window.addEventListener('offline', () => {
    this.isOnline = false;
});
```

### FIX 7: Propriedades estado (P9)
```javascript
this.lastError = null;
this.lastSuccessfulCheck = null;
this.isOnline = navigator.onLine;
```

### FIX 8: Páginas públicas (P8)
```javascript
const publicPages = [
    'login.html',
    'login_morador.html',          // ← NOVO
    'login_fornecedor.html',       // ← NOVO
    'esqueci_senha.html',
    'redefinir_senha.html',
    'index.html',
    'register.html',
    'registro.html'                // ← NOVO
];
```

### FIX 9: TIMEOUT (não é crítico)
```javascript
this.TIMEOUT = 15000;  // 15s ao invés de 10s
```

### FIX 10: evento sessionRenewed (P3)
```javascript
this.listeners.set('sessionRenewed', []);
```

---

## 📋 PARA INTEGRAR EM PÁGINAS

### Encontrar
```bash
grep -r "session-manager-singleton.js" frontend/
```

### Substituir (Find Replace)
```
FIND:    <script src="js/session-manager-singleton.js"></script>
REPLACE: <script src="js/session-manager-core.js"></script>
```

### Ordem de scripts
```html
<!-- 1. Core (primeiro!) -->
<script src="js/session-manager-core.js"></script>

<!-- 2. Dependentes -->
<script src="js/auth-guard.js"></script>
<script src="js/user-display.js"></script>

<!-- 3. Lógica -->
<script src="js/page-logic.js"></script>
```

---

## 🧪 TESTES MÍNIMOS

### localStorage OK?
```javascript
const d = JSON.parse(localStorage.getItem('sessionManagerState_v2'));
console.assert(!d.currentUser && !d.sessionExpireTime);  // ✅ DEVE FALHAR
```

### Singleton OK?
```javascript
const m1 = SessionManagerCore.getInstance();
const m2 = SessionManagerCore.getInstance();
console.assert(m1 === m2);  // ✅ DEVE PASSAR
```

### Events OK?
```javascript
let called = false;
sessionManager.on('userDataChanged', () => { called = true; });
// ... fazer algo que dispare evento ...
console.assert(called);  // ✅ DEVE PASSAR
```

### Offline OK?
```javascript
window.dispatchEvent(new Event('offline'));
console.assert(!sessionManager.isOnline);  // ✅ DEVE PASSAR
```

---

## ⏱️ TEMPO DE TRABALHO

| Tarefa | Tempo |
|--------|-------|
| Ler documentação | 30 min |
| Corrigir código | 1-2h |
| Integrar 80 páginas | 1h (automático) |
| Testar | 1-2h |
| **TOTAL** | **4-6h** |

---

## 🚨 ANTES DE COLOCAR EM PRODUÇÃO

- [ ] P1 (localStorage) corrigido
- [ ] P3 (endpoint) verificado
- [ ] P4 (credentials) adicionado
- [ ] P5 (erro tipo) diferenciado
- [ ] P6 (renewSession) completo
- [ ] Testado em offline
- [ ] Testado timeout (20s)
- [ ] Integrado em TODAS as páginas
- [ ] Removido session-manager-singleton.js
- [ ] Zero erros no console

---

## 💡 DICAS RÁPIDAS

✅ Sempre testar DOIS caminhos:
- URL raiz: `/dashboard/`
- Página aninhada: `/dashboard/frontend/dashboard.html`

✅ Verificar no DevTools Network se:
- POST tem credentials=include? ✅
- localStorage só tem isAuthenticated+timestamp? ✅

✅ Se der erro "fetchSameOriginNotAllowed":
- Faltaram credentials em algum fetch

✅ Se deslogar aleatoriamente:
- Problema: P5 não estava diferenciando erro de timeout

✅ Se localStorage tem dados sensíveis:
- Problema: P1 não foi corrigido

---

## 📞 PROBLEMAS COMUNS

**Erro:** "SessionManagerCore is not defined"
**Fix:** Carregar script ANTES de usar

**Erro:** "fetch credentials warning"
**Fix:** Adicionar `credentials: 'include'` em POST

**Erro:** "Deslogar sem motivo"
**Fix:** P5 - timeout está virando logout

**Erro:** "UI com dados velhos"
**Fix:** P6 - renewSession não tem re-fetch

**Erro:** "localStorage com email/senha"
**Fix:** P1 - remover dados sensíveis

---

## ✅ AFTER CHECKLIST

```javascript
// Console do navegador:

const m = window.sessionManager;
console.log('✅', m !== undefined);
console.log('✅', m.isLoggedIn() !== undefined);
console.log('✅', !localStorage.getItem('sessionManagerState_v2').includes('currentUser'));
console.log('✅', m.TIMEOUT === 15000);
console.log('✅', m.isOnline === true || m.isOnline === false);

// Se tudo ✅, você está pronto!
```

---

**Gerado:** 2025-02-06  
**Propósito:** Referência rápida durante implementação  
**Tempo de leitura:** 5-10 minutos
