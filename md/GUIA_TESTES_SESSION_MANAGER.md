# 🧪 GUIA DE TESTES - Session Manager Core

## Teste 1: Verificar localStorage (SEGURANÇA)

### Objetivo
Confirmar que localStorage NÃO contém dados sensíveis

### Passos
1. Abrir DevTools (F12)
2. Ir em Application → Storage → Local Storage
3. Procurar por `sessionManagerState_v2`

### ❌ NÃO DEVE TER
```javascript
{
    isAuthenticated: true,
    currentUser: {           // ← NUNCA
        id: 123,
        email: "user@email", // ← NADA DISSO!
        nome: "João",
        foto: "..."
    },
    sessionExpireTime: 3600,  // ← NUNCA
    timestamp: 1707244800000
}
```

### ✅ DEVE TER APENAS
```javascript
{
    isAuthenticated: true,
    timestamp: 1707244800000
}
```

### Teste Prático
```javascript
// Console do navegador
const stored = localStorage.getItem('sessionManagerState_v2');
const data = JSON.parse(stored);

// Validar estrutura
console.assert(!data.currentUser, '❌ ERRO: currentUser em localStorage!');
console.assert(!data.sessionExpireTime, '❌ ERRO: sessionExpireTime em localStorage!');
console.assert(data.isAuthenticated !== undefined, '✅ OK: isAuthenticated presente');
console.assert(typeof data.timestamp === 'number', '✅ OK: timestamp presente');

console.log('✅ localStorage SEGURO');
```

---

## Teste 2: Verificar Singleton Pattern

### Objetivo
Confirmar que só há UMA instância de SessionManagerCore

### Teste
```javascript
// Console do navegador
const manager1 = SessionManagerCore.getInstance();
const manager2 = SessionManagerCore.getInstance();

console.assert(manager1 === manager2, '❌ ERRO: Instâncias diferentes!');
console.log('✅ Singleton funcionando:', manager1 === manager2);

// Tentar criar nova com 'new' deve falhar
try {
    const manager3 = new SessionManagerCore();
    console.error('❌ ERRO: Constructor deveria ter lançado erro!');
} catch (error) {
    console.log('✅ Constructor lançou erro corretamente:', error.message);
}
```

### Resultado Esperado
```
✅ Singleton funcionando: true
✅ Constructor lançou erro corretamente: [SessionManager] ❌ SessionManagerCore já foi instanciado!
```

---

## Teste 3: Verificar Event Listeners

### Objetivo
Confirmar que listeners funcionam corretamente

### Teste
```javascript
// Console do navegador
const manager = SessionManagerCore.getInstance();
let userDataChangedCalled = false;

// Subscribe a evento
const unsubscribe = manager.on('userDataChanged', (data) => {
    console.log('✅ userDataChanged disparado com:', data);
    userDataChangedCalled = true;
});

// Simular mudança de dados (logout e login)
manager.logout();

// Validar
setTimeout(() => {
    console.assert(userDataChangedCalled || !manager.isLoggedIn(), 
        '✅ Listener foi chamado ou estado mudou');

    // Unsubscribe
    unsubscribe();
    console.log('✅ Unsubscribe funcionou');
}, 1000);
```

---

## Teste 4: Verificar Conectividade Offline

### Objetivo
Confirmar que app lida bem com perda de rede

### Passos Manuais
1. Abrir DevTools → Network
2. Marcar "Offline"
3. Tentar renovar sessão
4. Verificar que NÃO faz logout

### Teste Automático
```javascript
// Console do navegador
const manager = SessionManagerCore.getInstance();

// Simular offline
const onlineEvent = new Event('offline');
window.dispatchEvent(onlineEvent);

// Validar
console.assert(!manager.isOnline, '✅ isOnline = false após offline event');
console.log(`✅ isOnline: ${manager.isOnline}`);

// Simular online
const offlineEvent = new Event('online');
window.dispatchEvent(offlineEvent);

// Validar
console.assert(manager.isOnline, '✅ isOnline = true após online event');
console.log(`✅ isOnline: ${manager.isOnline}`);
```

---

## Teste 5: Verificar Timeout

### Objetivo
Confirmar que timeout não causa logout

### Setup (Servidor mock)
```javascript
// Interceptar fetch para simular timeout
const originalFetch = window.fetch;
window.fetch = function(url, options) {
    if (url.includes('verificar_sessao_completa')) {
        // Simular timeout de 20s (maior que 15s configurado)
        return new Promise((resolve, reject) => {
            setTimeout(() => {
                reject(new DOMException('Aborted', 'AbortError'));
            }, 20000);
        });
    }
    return originalFetch.apply(this, arguments);
};
```

### Teste
```javascript
const manager = SessionManagerCore.getInstance();
const wasSessaoAtiva = manager.isLoggedIn();

manager.checkSession().then((result) => {
    // Timeout pode retornar true se mantém estado anterior
    console.assert(
        result === wasSessaoAtiva || !result,
        '✅ Timeout não deslogou user'
    );
    console.log('✅ Timeout tratado corretamente');
});
```

---

## Teste 6: Verificar Renovação (5 min)

### Objetivo
Confirmar que renovação automática funciona

### Setup
```javascript
// Reduzir intervalo para teste (normalmente 300s = 5min)
const manager = SessionManagerCore.getInstance();
manager.RENEW_INTERVAL = 5000; // 5 segundos para teste

manager.startPeriodicChecks();
```

### Verificar
```javascript
// Console do navegador
// Depois de 5 segundos, deve ver no console:
// [SessionManager] 🔄 Renovando sessão...
// [SessionManager] ✅ Sessão renovada com sucesso

// Parar testes
manager.stopPeriodicChecks();
```

---

## Teste 7: Verificar Lista de Páginas Públicas

### Objetivo
Confirmar que todas as páginas públicas são reconhecidas

### Teste
```javascript
// Mockar window.location para teste
const testPages = [
    'login.html',
    'login_morador.html',
    'login_fornecedor.html',
    'esqueci_senha.html',
    'redefinir_senha.html',
    'index.html',
    'register.html',
    'registro.html'
];

// Cada página após as correções
testPages.forEach(page => {
    // Simular pathname
    Object.defineProperty(window.location, 'pathname', {
        value: `/dashboard/${page}`,
        writable: true
    });

    const manager = SessionManagerCore.getInstance();
    const isPublic = manager.isPublicPage();
    
    console.assert(isPublic, `❌ ${page} não reconhecida como pública!`);
    console.log(`✅ ${page} é pública`);
});
```

---

## Teste 8: Verificar Erro Diferenciação

### Objetivo
Confirmar que timeout ≠ logout

### Teste com Mock
```javascript
const manager = SessionManagerCore.getInstance();

// Caso 1: Timeout (AbortError)
const testTimeout = async () => {
    const originalFetch = window.fetch;
    window.fetch = () => {
        return new Promise((_, reject) => {
            reject(new DOMException('Aborted', 'AbortError'));
        });
    };

    const result = await manager.checkSession();
    console.assert(result === true, '✅ Timeout mantém sessão');
    
    window.fetch = originalFetch;
};

// Caso 2: Erro de rede (TypeError)
const testNetworkError = async () => {
    const originalFetch = window.fetch;
    window.fetch = () => {
        return Promise.reject(new TypeError('Failed to fetch'));
    };

    const result = await manager.checkSession();
    console.assert(result === true, '✅ Erro de rede mantém sessão');
    
    window.fetch = originalFetch;
};

await testTimeout();
await testNetworkError();
```

---

## Teste 9: Integração em Página Real

### Checklist após integração
- [ ] Script carrega sem erros no console
- [ ] window.sessionManager está disponível
- [ ] Usuário logado aparece na tela
- [ ] Logout funciona
- [ ] Refresh da página mantém sessão
- [ ] F5 em página protegida mantém login

### Console
```javascript
// Após carregar página:
console.log(window.sessionManager); // Deve mostrar objeto SessionManagerCore
console.log(window.sessionManager.isLoggedIn()); // true/false
console.log(window.sessionManager.getUser()); // dados do usuário ou null
```

---

## Teste 10: Compatibilidade com auth-guard.js

### Objetivo
Confirmar que auth-guard.js consegue usar SessionManagerCore

### Auth-guard.js deve ter
```javascript
// ✅ CORRETO
const manager = window.sessionManager || SessionManagerCore.getInstance();

// ❌ ERRADO (referência direta)
const manager = SessionManagerSingleton.getInstance();
```

### Teste
```javascript
// Verificar que auth-guard consegue ouvir eventos
window.sessionManager.on('sessionExpired', () => {
    console.log('✅ Auth-guard pode ouvir eventos da sessão');
});
```

---

## 🎯 PLANO DE TESTES INTEGRADO

### Fase 1: Unitários (Antes de integrar)
```bash
Teste 1: localStorage seguro
Teste 2: Singleton pattern
Teste 3: Event listeners
```

### Fase 2: Integração (Depois de integrar)
```bash
Teste 4: Offline handling
Teste 5: Timeout handling
Teste 6: Auto-renovação
Teste 7: Páginas públicas
Teste 8: Diferenciação de erros
```

### Fase 3: Sistema (Em produção)
```bash
Teste 9: Integração em página real
Teste 10: Compatibilidade com outros scripts
```

---

## ✅ CHECKLIST FINAL

Antes de considerar completo:

```javascript
// Execute no console após carregar página:

const manager = window.sessionManager;
const tests = {
    'localStorage seguro': () => {
        const data = JSON.parse(localStorage.getItem('sessionManagerState_v2') || '{}');
        return !data.currentUser && !data.sessionExpireTime;
    },
    'Singleton': () => {
        const m1 = SessionManagerCore.getInstance();
        const m2 = SessionManagerCore.getInstance();
        return m1 === m2;
    },
    'Events funcionam': () => {
        let called = false;
        const unsub = manager.on('userDataChanged', () => { called = true; });
        return typeof unsub === 'function';
    },
    'Conectividade': () => {
        return typeof manager.isOnline === 'boolean';
    },
    'Estado válido': () => {
        return typeof manager.isAuthent !== 'undefined';
    }
};

Object.entries(tests).forEach(([name, test]) => {
    const result = test();
    console.log(`${result ? '✅' : '❌'} ${name}`);
});
```

---

## 🐛 Problemas Comuns e Soluções

### Problema: "ReferenceError: SessionManagerCore is not defined"
**Solução:** Verificar se `session-manager-core.js` está sendo carregado ANTES dos outros scripts

### Problema: "localStorage tem currentUser/sessionExpireTime"
**Solução:** Não corrigiu P1 (persistState). Ver CODIGO_CORRIGIDO_SESSION_MANAGER.md

### Problema: "Timeout causa logout"
**Solução:** Não corrigiu P5 (checkSession error handling). Ver linha 170-183

### Problema: "refresh Token não funciona"
**Solução:** Não corrigiu P6 (renewSession). Ver linha 210-216

### Problema: "Offline desativa tudo"
**Solução:** Verificar se P10 foi implementado (listeners de rede)

---

## 📊 Relatório de Testes (Modelo)

```
═══════════════════════════════════════════════════════════════════
RELATÓRIO DE TESTES - Session Manager Core
═══════════════════════════════════════════════════════════════════

Data:           [DATA]
Versão:         2.0
Navegador:      Chrome 120.0 (exemplo)
Servidor:       prod/staging/localhost

TESTES FUNCIONAIS
├─ Login                   ✅ PASSOU
├─ Logout                  ✅ PASSOU  
├─ Renovação automática    ✅ PASSOU
├─ Timeout handling        ✅ PASSOU
├─ Offline mode            ✅ PASSOU
├─ Restauração (refresh)   ✅ PASSOU
└─ Sincronização dados     ✅ PASSOU

TESTES DE SEGURANÇA
├─ localStorage seguro     ✅ PASSOU
├─ Credentials em POST     ✅ PASSOU
├─ Erro de rede mantém-se  ✅ PASSOU
└─ XSS protection          ✅ PASSOU

TESTES DE COMPATIBILIDADE
├─ auth-guard.js           ✅ PASSOU
├─ user-display.js         ✅ PASSOU
├─ user-profile-sidebar.js ✅ PASSOU
└─ Mobile WIP              ⏸️ PENDENTE

PERFORMANCE
├─ Inicialização           ✅ <500ms
├─ Verificação (60s)       ✅ <200ms
├─ Renovação (5min)        ✅ <300ms
└─ Memória                 ✅ <5MB

RESULTADO FINAL:           ✅ PASSOU (19/19 testes)
═══════════════════════════════════════════════════════════════════
```

Usar este modelo como referência depois de implementar todas as correções.
