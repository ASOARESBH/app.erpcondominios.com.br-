# FASE 6 QA — Plano de Execução (Exato)

**Status atual:** Cookie bloqueando FASE 6  
**Objetivo:** Obter cookie válido → Reexecutar QA → Aprovar FASE 6

---

## 🔑 PASSO 1 — Validar o cookie no backend

### O que fazer (Equipe backend)

Alguém com acesso direto ao servidor PHP precisa executar um teste rápido:

#### Opção A: Usar script de validação automática (recomendado)

```
URL: https://asl.erpcondominios.com.br/validate_cookie_qa.php?cookie=SEU_PHPSESSID_AQUI
```

Substitua `SEU_PHPSESSID_AQUI` com o cookie real. Exemplo:
```
https://asl.erpcondominios.com.br/validate_cookie_qa.php?cookie=abc123def456xyz789
```

Esperado (VALID):
```json
{
  "verdict": "VALID ✅",
  "checks": {
    "session_id_matches": true,
    "session_has_data": true,
    "user_id_present": true,
    "user_role_present": true,
    "session_not_expired": true
  }
}
```

#### Opção B: Teste manual no PHP (se script não for acessível)

```php
<?php
session_id('SEU_COOKIE_AQUI');
session_start();

var_dump($_SESSION);
// Esperado: array com user_id, user_role, e mais dados
?>
```

### Checklist objetivo (SIM/NÃO)

- [ ] session_id() bate com o cookie fornecido?
- [ ] $_SESSION contém dados do usuário (não é vazio)?  
- [ ] Sessão não está vazia?
- [ ] Sessão não expirou (age < gc_maxlifetime)?
- [ ] Handler de sessão correto (files/redis/etc)?
- [ ] Domínio correto (asl.erpcondominios.com.br)?

⚠️ **Se $_SESSION vier vazio → cookie é inválido. PONTO FINAL.**

---

## 🔑 PASSO 2 — Gerar um cookie QA válido (forma correta)

### Forma recomendada (ÚNICA que funciona)

1. **Abrir browser NORMAL** (Chrome/Firefox)
   - Link: https://asl.erpcondominios.com.br/login.html
   
2. **Fazer login manual**
   - Usar credenciais reais de um usuário teste
   - Confirmar que login funcionou (dashboard carrega)

3. **Copiar PHPSESSID ativo**
   - F12 → Application → Cookies → asl.erpcondominios.com.br
   - Procurar por `PHPSESSID`
   - **Copiar o VALOR completo** (do lado direito)
   
4. **Usar IMEDIATAMENTE**
   - Usar o cookie em menos de 10 minutos (antes de expirar)
   - Usar em ambiente QA, não em produção
   - Marcar: "esse cookie foi gerado em [data/hora]"

### ❌ Formas que NÃO funcionam

- ❌ Cookie antigo (exportado semanas atrás)
- ❌ Cookie copiado de logs
- ❌ Cookie teórico/imaginário
- ❌ Cookie de outra conta/domínio
- ❌ Cookie com atributos alterados

---

## 🔁 PASSO 3 — Reexecutar o QA Auth Header Injection

### Quando: assim que tiver cookie válido do PASSO 2

### Como executar

1. **Terminal PowerShell** no workspace:

```powershell
# Defina o cookie (aqui no exemplo, use o real)
$env:QA_PHPSESSID = "abc123def456xyz789..."

# Execute o script QA
node "c:\xampp\htdocs\dashboard\asl.erpcondominios.com.br\tools\qa-puppeteer-auth-header.js"
```

2. **Aguarde ~60 segundos** (testa 4 páginas)

3. **Verifique os resultados:**
   ```
   tools/qa-results-auth-header.json
   ```

### Esperado (FASE 6 APROVADA) ✅

```json
{
  "dashboard.html": {
    "checks": {
      "basic": {
        "hasSessionManager": true,             // ✅ Core inicializou
        "isAuthenticated": true,               // ✅ Autenticado
        "userDataLoaded": true
      },
      "renew": {
        "triggered": true,                     // ✅ Renewal funcionou
        "hasCredentials": true
      }
    },
    "events": {
      "userDataChanged": true,                 // ✅ Evento disparou
      "sessionRenewed": true                   // ✅ Renewal disparat
    },
    "metrics": {
      "reduction": 67,                         // ✅ >= 67% redução
      "perMinute": 120
    }
  }
}
```

### No console esperado

```javascript
window.sessionManager.isLoggedIn()       // true
window.sessionManager.getUsername()      // "nome do usuário"
window.sessionManager.renewSession()     // função disponível
window.sessionManager.logout()           // função disponível
```

### Após PASSO 3 com sucesso

- ✅ SessionManagerCore funcional com auth
- ✅ Eventos disparando corretamente
- ✅ HTTP request reduction >= 67%
- ✅ **FASE 6 APROVADA** → Avançar para FASE 7

---

## 🚦 Status Real do Projeto (sem maquiagem)

| Fase | Status | Resultado |
|------|--------|-----------|
| Arquitetura SessionManager | ✅ Aprovada | 10 fixes P1-P10 aplicadas, codigo revisado |
| Performance (HTTP reduction) | ✅ Aprovada | 67-80% confirmado (sem auth) |
| Segurança localStorage | ✅ Aprovada | Nenhum dado sensível, apenas isAuthenticated |
| Integração (63 páginas) | ✅ Aprovada | Todas 63 páginas com session-manager-core.js |
| Transporte de cookie | ✅ Aprovada | HTTP header injection funciona 100% |
| **Sessão backend válida** | ❌ **BLOQUEADOR** | Cookie fornecido inválido/expirado |
| SessionManager com auth | ⏳ Pendente | Aguardando cookie válido |
| Eventos (renew/expire) | ⏳ Pendente | Aguardando init com auth |
| Logout funcional | ⏳ Pendente | Aguardando init com auth |

---

## 📋 Checklist de Desbloqueio

- [ ] PASSO 1: Backend validou cookie (SIM a todos os 6 itens)
- [ ] PASSO 2: Cookie fresco copiado do browser (menos de 10 min)
- [ ] PASSO 3: QA reexecutado com cookie válido
- [ ] Resultado: VALID ✅ em todos os checks
- [ ] **FASE 6 → APROVADA**
- [ ] **FASE 7: Geração do relatório final**

---

## Tempo estimado

| Passo | Tempo | Executor |
|------|-------|----------|
| PASSO 1 (Validação backend) | 5 min | Backend |
| PASSO 2 (Copiar cookie) | 2 min | Qualquer pessoa |
| PASSO 3 (Reexecutar QA) | 3 min | Automático (script) |
| **Total** | **~10 min** | - |

---

## Contato / Próximos passos

1. Enviar este documento para equipe backend
2. Executar PASSO 1 (validação)
3. Copiar cookie PASSO 2
4. Informar cookie para QA
5. Executar PASSO 3 automaticamente
6. Análise de resultado

**Se TODOS os checks passarem → FASE 6 APROVADA → FASE 7 segue normalmente**
