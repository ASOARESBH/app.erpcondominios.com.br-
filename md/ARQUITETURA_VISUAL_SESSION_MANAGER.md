# 🏗️ ARQUITETURA - Session Manager Core

## ESTADO ATUAL vs ESPERADO

### ❌ ESTADO ATUAL (ERRADO)

```
┌─────────────────────────────────────────────────────────────┐
│                     APLICAÇÃO                               │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  dashboard.html       estoque.html      protocolo.html  │
│        │                   │                  │        │
│        └───────────────────┴──────────────────┘        │
│                      │                              │
│        ┌─────────────▼─────────────┐                 │
│        │ session-manager-singleton │ ❌ VERSÃO ANTIGA │
│        │ (324 linhas, v6.0)        │                 │
│        └─────────────▬─────────────┘                 │
│                      │                              │
│        ┌─────────────▼─────────────┐                │
│        │ API verificar_sessao      │               │
│        │ (backend)                 │               │
│        └───────────────────────────┘               │
│                                                   │
│  ⚠️ Arquivo novo nunca integrado:                │
│     session-manager-core.js (510 linhas, v2.0)   │
│                                                   │
└─────────────────────────────────────────────────────────────┘
```

### ✅ ESTADO ESPERADO (CORRETO)

```
┌───────────────────────────────────────────────────────────────┐
│                     APLICAÇÃO (~80 páginas)                  │
├───────────────────────────────────────────────────────────────┤
│                                                              │
│  [dashboard.html] [estoque.html] [protocolo.html] [...]    │
│        │               │              │        │            │
│        └───────────────┴──────────────┴────────┘            │
│                      │                                   │
│        ┌─────────────▼─────────────────┐                 │
│        │ session-manager-core.js ✅    │ NOVO            │
│        │ (510 linhas, v2.0)            │                 │
│        │                               │                 │
│        │ • Singleton pattern           │                 │
│        │ • Event system                │                 │
│        │ • Gerencia estado             │                 │
│        │ • localStorage seguro         │                 │
│        │ • Offline detection           │                 │
│        └──────────┬──────────────────┘                 │
│                   │                               │
│        ┌──────────┴──────────┐                     │
│        │                     │                    │
│   ┌────▼────┐         ┌─────▼──────┐            │
│   │auth-    │         │user-       │            │
│   │guard.js │         │display.js  │            │
│   └────┬────┘         └─────┬──────┘            │
│        │                     │                  │
│        └────┬────────────────┘                  │
│             │                                 │
│        ┌────▼────────────────┐                │
│        │ API Backend         │               │
│        │ verificar_sessao    │               │
│        │ renovar_sessao      │               │
│        │ logout              │               │
│        └─────────────────────┘               │
│                                             │
│  ✅ Versão antiga removida:                │
│     session-manager-singleton.js           │
│                                            │
└───────────────────────────────────────────────────────────────┘
```

---

## FLUXO DE DADOS - ANTES (❌ CONFUSO)

```
┌──────────────┐
│ Página HTML  │
└────────┬─────┘
         │
         ├─→ Load session-manager-singleton.js
         │        │
         │        ├─→ Faz verificação sessão
         │        │
         │        ├─→ localStorage com dados sensíveis! ⚠️
         │        │
         │        │
         ├─→ Load auth-guard.js
         │        │
         │        ├─→ Verifica sessionManager
         │        │
         │        └─→ Se falhar → logout
         │
         ├─→ Load user-display.js
         │        │
         │        ├─→ Mostra usuário na tela
         │        │
         │        └─→ Pode estar com dados inconsistentes
         │
         └─→ Load page-logic.js

❌ PROBLEMAS:
   • Múltiplos pontos de controle
   • localStorage inseguro (P1)
   • Sem diferenciação de erro (P5)
   • Dados podem ficar desincronizados
```

---

## FLUXO DE DADOS - DEPOIS (✅ CORRETO)

```
┌──────────────────────────────────────────────────────────────┐
│ 1. Page Load                                                 │
└─────────────┬──────────────────────────────────────────────┘
              │
              ▼
    ┌─────────────────────────────────────────┐
    │ Load: session-manager-core.js            │
    │ (SINGLETON - Uma única instância!)       │
    └──────────────┬──────────────────────────┘
                   │
                   ├─→ Constructor()
                   │    ├─ Cria estado centralizado
                   │    ├─ Initiliza listeners
                   │    └─ Configura timers
                   │
                   ├─→ initialize()
                   │    ├─ Recupera estado (localStorage seguro)
                   │    ├─ Faz checkSession() (1ª vez)
                   │    ├─ Configura listeners de rede
                   │    └─ Inicia timers (60s check, 5min renew)
                   │
                   └─→ Super! Sessão verificada ✅
                       │
                       ├─ emit('userDataChanged')  ← Dispara evento
                       │
                       ▼
    ┌──────────────────────────────────────────┐
    │ 2. Load: auth-guard.js                   │
    │    (Ouve eventos, não faz lógica)        │
    └──────────────┬───────────────────────────┘
                   │
                   ├─→ sessionManager.on('sessionExpired', ...)
                   │
                   └─→ Se evento → Redireciona logout
                       │
                       ▼
    ┌──────────────────────────────────────────┐
    │ 3. Load: user-display.js                 │
    │    (Mostra dados que viraram de eventos) │
    └──────────────┬───────────────────────────┘
                   │
                   ├─→ sessionManager.on('userDataChanged', ...)
                   │
                   └─→ Sempre em sync com estado central
                       │
                       ▼
    ┌──────────────────────────────────────────┐
    │ 4. Page Logic                            │
    │    (Usa window.sessionManager)           │
    └──────────────┬───────────────────────────┘
                   │
                   ├─→ if (!sessionManager.isLoggedIn())
                   │       → Redirecionar
                   │
                   ├─→ user = sessionManager.getUser()
                   │       → Sempre dados atuais
                   │
                   └─→ Tudo funciona corretamente ✅

✅ CORRETO:
   • Único ponto de controle (singleton)
   • localStorage seguro (apenas isAuthenticated + timestamp)
   • Componentes são ouvintes passivos
   • Dados sempre sincronizados
   • Sem race conditions
```

---

## PROBLEMAS E SOLUÇÕES VISUAIS

### P1: localStorage inseguro

```
❌ ANTES:
   localStorage = {
       "sessionManagerState_v2": {
           "isAuthenticated": true,
           "currentUser": {
               "id": 123,
               "email": "user@example.com",     ← EXPOSTOS! XSS risco
               "nome": "João da Silva",        ← EXPOSTOS!
               "role": "admin",                ← EXPOSTOS!
               "foto": "...base64..."
           },
           "sessionExpireTime": 3600,          ← EXPOSTOS!
           "timestamp": 1707244800000
       }
   }

✅ DEPOIS:
   localStorage = {
       "sessionManagerState_v2": {
           "isAuthenticated": true,
           "timestamp": 1707244800000
       }
   }
   
   Dados do usuário? SEMPRE obtidos via API quando necessário!
```

### P5: Erro diferenciação

```
❌ ANTES:
   checkSession()
     ├─ Timeout (10s passou)? → AbortError → Manter sessão ✅
     ├─ Erro de rede?         → TypeError  → Logout ❌ ERRADO!
     └─ Outro erro?           → Any        → Logout ✅

❌ PROBLEMA:
   User está offline? → TypeError → LOGOUT IMEDIATO! 😱

✅ DEPOIS:
   checkSession()
     ├─ Timeout (15s passou)? 
     │   └─ AbortError → Manter sessão ✅
     │      (servidor tá lento, não deslogar)
     │
     ├─ Erro de rede?  
     │   └─ TypeError → Manter sessão ✅
     │      (usuário offline, manter dados local)
     │      flag: isOnline = false
     │
     └─ Outro erro?
         └─ LogoutSeguro ✅
            (erro desconhecido, session pode estar inválida)

✅ CORRETO:
   User offline? → Mantém sessão, flag isOnline fica false
   User reconecta? → window 'online' event → checkSession()
```

### P6: renewSession incompleto

```
❌ ANTES:
   renewSession()
     ├─ POST /api/verificar_sessao_completa.php
     ├─ response.ok? → Pronto, voltamos!
     └─ ❌ NÃO faz refetch de dados do usuário
        → User data fica desatualizado!

❌ CENÁRIO:
   20:00 → renewSession
   20:15 → Admin muda permissões do user no BD
   20:20 → renewSession novamente
   20:25 → UI ainda mostra permissões antigas ❌

✅ DEPOIS:
   renewSession()
     ├─ POST /api/verificar_sessao_completa.php
     ├─ response.ok?
     │   ├─ data = response.json()
     │   ├─ data.usuario? → Atualiza currentUser ✅
     │   ├─ data.sessao?.tempo_restante? → Atualiza expirá ✅
     │   └─ emit('sessionRenewed', ...) → UI se atualiza ✅
     └─ Sempre dados atualizados!

✅ CENÁRIO:
   20:00 → renewSession
   20:15 → Admin muda permissões no BD
   20:20 → renewSession novamente
   20:20.100 → UI já mostra permissões novas ✅
```

---

## INTEGRAÇÃO NAS PÁGINAS

### Antes (Atual)

```
frontend/
├─ dashboard.html
│  └─ <script src="js/session-manager-singleton.js"></script>
├─ estoque.html  
│  └─ <script src="js/session-manager-singleton.js"></script>
├─ protocolo.html
│  └─ <script src="js/session-manager-singleton.js"></script>
├─ ... (restante das ~80 páginas SEM nada)
│
└─ js/
   ├─ session-manager-singleton.js    ← Versão ANTIGA
   ├─ session-manager-core.js         ← Versão NOVA (não usada!)
   ├─ auth-guard.js
   └─ user-display.js

❌ PROBLEMA:
   • 76 páginas sem session manager
   • 4 páginas usando singleton
   • Core.js nunca é carregado!
```

### Depois (Esperado)

```
frontend/
├─ dashboard.html
│  ├─ <script src="js/session-manager-core.js"></script>      ✅
│  ├─ <script src="js/auth-guard.js"></script>
│  └─ <script src="js/user-display.js"></script>
├─ estoque.html  
│  ├─ <script src="js/session-manager-core.js"></script>      ✅
│  ├─ <script src="js/auth-guard.js"></script>
│  └─ <script src="js/user-display.js"></script>
├─ protocolo.html
│  ├─ <script src="js/session-manager-core.js"></script>      ✅
│  ├─ <script src="js/auth-guard.js"></script>
│  └─ <script src="js/user-display.js"></script>
├─ acesso.html
│  ├─ <script src="js/session-manager-core.js"></script>      ✅
│  ├─ <script src="js/auth-guard.js"></script>
│  └─ <script src="js/user-display.js"></script>
├─ ... (TODAS as ~80 páginas com session-manager-core.js)    ✅
│
└─ js/
   ├─ session-manager-core.js         ← Versão NOVA ✅
   ├─ auth-guard.js
   ├─ user-display.js
   └─ user-profile-sidebar.js

✅ CORRETO:
   • Todas as ~80 páginas com session-manager-core.js
   • session-manager-singleton.js removido
   • Um único gerenciador robusto
```

---

## FLUXO DE CICLO DE VIDA

```
                          USER ABRE PÁGINA
                                 │
                                 ▼
                    ┌────────────────────────┐
                    │ DOMContentLoaded      │
                    │ sessionManager        │
                    │ .initialize()         │
                    └────────┬───────────────┘
                             │
        ┌────────────────────┼────────────────────┐
        │                    │                    │
        ▼                    ▼                    ▼
   ┌────────┐        ┌───────────────┐    ┌──────────────┐
   │PUBLIC  │        │PROTECTED PAGE │    │sessionExpired│
   │PAGE?   │        │               │    │(logout)?     │
   └────┬───┘        └──────┬────────┘    └──────┬───────┘
        │ SIM               │ NÃO               │ SIM
        │      NÃO          │                   │
        ▼                   ▼                   ▼
   PULA TIMERS      Inicia timers       redirectToLogin()
   ✅ OK             ✅ OK                 PAGE = login.html
                                           ✅ OK
        │                                    │
        │◄──────────────────┐────────────────┘
        │                   │
        ▼                   ▼
   ┌─────────────────────────────────┐
   │ Timers Rodando                  │
   │ (se página protegida)           │
   │                                 │
   ├─ 60s: checkSession()            │
   │        Verifica se sessão ainda  │
   │        válida                    │
   │                                 │
   ├─ 5min: renewSession()           │
   │        Renova sessão no servidor │
   │        Re-fetch dados            │
   │                                 │
   └─────────────────────────────────┘
           │         │         │
           ▼         ▼         ▼
      [60s passa] [5min] [24h session expira]
           │         │         │
           ▼         ▼         ▼
      Verifica  Renova    Expired!
           │         │         │
           └───┬─────┴────┬────┘
               │          │
               ▼          ▼
          emit eventos   Logout
          userData       Redirect
          Changed        

┌──────────────┐      ┌──────────────┐
│ USER LOGOFF  │      │ REDE OFFLINE │
└────┬─────────┘      └────┬─────────┘
     │                     │
     ▼                     ▼
 logout()            isOnline=false
     │               Mantém dados
     ├─ stopTimers   Escuta 'online'
     ├─ clearState       │
     ├─ emit logout      ▼
     └─ redirect     checkSession()
        login.html      │
                        ▼
                     isOnline=true
                     Sincroniza
     
```

---

## RESUMO VISUAL

### ANTES (❌)
```
🔴 Versão errada em uso
🔴 Dados sensíveis em localStorage  
🔴 Componentes desincronizados
🔴 Sem resposta para offline
🔴 ~80 páginas sem session manager
```

### DEPOIS (✅)
```
✅ Versão corrigida em todas as páginas
✅ localStorage completamente seguro
✅ Todos os componentes sincronizados
✅ Responde corretamente a offline
✅ Único ponto de controle centralizado
```

---

**Visão Geral da Arquitetura**  
**Data:** 2025-02-06  
**Propósito:** Entender diferenças estruturais
