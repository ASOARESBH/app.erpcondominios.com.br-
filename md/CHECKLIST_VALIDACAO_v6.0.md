# CHECKLIST DE VALIDAÇÃO PÓS-CORREÇÃO
## Loop Infinito de Requisições v6.0

---

## ✅ TESTE 1: Validação de Rede (10 minutos)

**Objetivo:** Confirmar que requisições caíram de 210+ para ~1-2 por minuto

**Passos:**

1. Abrir navegador em **modo anônimo** (ou limpar cache/cookies)
   
2. DevTools: Pressionar `F12` → Aba **Network**

3. Acessar: `https://asl.erpcondominios.com.br/frontend/dashboard.html`

4. Aguardar carregamento completo (~5s)

5. Fazer login (se necessário)

6. **Esperar 10 minutos** com a página aberta (não fechar abas)

7. **Verificações durante os 10 minutos:**
   - [ ] Network: Filtrar por `/api/`
   - [ ] Contar requisições para `api_usuario_logado.php`
   - [ ] Esperado: **máximo 20 requisições em 10min** (2 por minuto)
   - [ ] ❌ FALHA SE: > 50 requisições (5+ por minuto)
   - [ ] [ ] Não há requisições simultâneas (sobrepostas)
   - [ ] [ ] Sem timeouts ou erros 503/504
   - [ ] [ ] Status HTTP: 200-401 (não **403 bloqueado**)

8. **Teste de Múltiplas Abas:**
   - [ ] Abrir dashboard em 2-3 abas diferentes
   - [ ] Esperar 2-3 minutos
   - [ ] Contar requisições TOTAIS
   - [ ] Esperado: **linear** (2 abas = 2-4 req/min, não 10+)

**Resultado esperado:**
```
✅ Requisições ao longo de 10 min:
   Minuto 1: 1-2 requisições
   Minuto 2: 0-1 requisições
   Minuto 3: 1-2 requisições
   ...
   Minuto 10: 0-1 requisições
   ================================================================
   TOTAL: ~10-20 requisições
   MÉDIA: 1-2 por minuto ← ✅ SUCESSO
```

---

## ✅ TESTE 2: Validação de Console (5 minutos)

**Objetivo:** Confirmar que não há loops de log ou erros de parsing JSON

**Passos:**

1. DevTools: Aba **Console** (F12)

2. Limpar console (ícone de lixeira)

3. Com a página aberta, deixar por **2-3 minutos**

4. Verificações:
   - [ ] Sem mensagens repetidas tipo:
     - `SyntaxError: Unexpected token '<'`
     - `TypeError: Cannot read property 'sucesso' of null`
     - `fetch(...) returned 403`
   
   - [ ] Mensagens de log controladas:
     - OK: `[SessionManager] Iniciando...` (uma vez no início)
     - OK: Sem output contínuo a cada segundo
     - ❌ FALHA SE: Logs repetidos a cada segundo
   
   - [ ] Nenhum warning em vermelho ⚠️ relativo a requisições
   - [ ] Sem travamentos de CPU (tarefa não fica presa)

**Resultado esperado:**
```
Console limpo sem erros de parsing JSON ou requisições
```

---

## ✅ TESTE 3: Validação de Responsividade (5 minutos)

**Objetivo:** Confirmar que UI permanece responsiva

**Passos:**

1. Dashboard aberto

2. Testar **interações:**
   - [ ] Clicar em menus → resposta imediata (< 100ms)
   - [ ] Scroll da página → fluxo suave
   - [ ] Navegação entre páginas → carregamento normal
   - [ ] Informações do usuário (avatar, nome) aparecem corretamente

3. Monitorar **Performance:**
   - DevTools → Aba **Performance**
   - Registrar um intervalo de 10s
   - Esperado: **CPU não constantemente alta** (picos & vales, não plateau)
   - ❌ FALHA SE: CPU em 100% contínuo

**Resultado esperado:**
```
✅ Interface responsiva, sem "engasgos"
✅ Navegação fluidez, scroll sem stuttering
✅ CPU em picos ocasionais (quando requisição chega), não contínuo
```

---

## ✅ TESTE 4: Validação de SessionManager (Desenvolvimento)

**Objetivo:** Verificar que o Singleton está operacional

**Passos no Console (F12):**

```javascript
// 1. Verificar se Singleton foi criado
console.log(window.sessionManagerSingleton);
// Esperado: SingularInstance object com métodos

// 2. Verificar estado da sessão
console.log(window.sessionManagerSingleton.isSessionActive());
// Esperado: true (se logado)

// 3. Verificar dados do usuário
console.log(window.sessionManagerSingleton.getUserData());
// Esperado: { id, nome, email, funcao, ... }

// 4. Contadores (se habilitado debug):
window.sessionValidator.analyzeLog();
// Esperado:
// ✅ Requisições por minuto: ~1-2
// ✅ Nenhuma URL com frequência > 2 req/min
```

**Resultado esperado:**
```
✅ SessionManagerSingleton criado e operacional
✅ getUserData retorna dados do usuário
✅ isSessionActive retorna true
✅ Analyze log mostra < 2 req/min
```

---

## ✅ TESTE 5: Teste de Logout (Segurança)

**Objetivo:** Confirmar que finalizarSessao funciona sem loops

**Passos:**

1. Dashboard aberto

2. Clicar em **Sair/Logout**

3. Verificações:
   - [ ] Modal de confirmação aparece (sem delay)
   - [ ] Clique em "Sair Agora"
   - [ ] Redirecionamento para login.html (< 2s)
   - [ ] localStorage e sessionStorage zerados
   - [ ] PHPSESSID cookie deletado
   - [ ] Sem logs repetidos durante logout

**Resultado esperado:**
```
✅ Logout seguro em < 2 segundos
✅ Redirecionamento limpo sem erros
✅ Sessão totalmente limpa
```

---

## 🚨 CHECKLIST DE FALHA (Rollback necessário?)

Se QUALQUER item abaixo for marcado, a correção tem problema:

- [ ] ❌ Requisições > **5 por minuto** persistem
- [ ] ❌ `SyntaxError: Unexpected token '<'` aparece
- [ ] ❌ CPU em **100% contínuo**
- [ ] ❌ Dashboard **inresponsível** após 1 minuto
- [ ] ❌ SessionManager não carrega (window.sessionManagerSingleton === undefined)
- [ ] ❌ Logout **não funciona** ou causa erro
- [ ] ❌ Múltiplas abas causam **exponential growth** de requisições

**Se qualquer um marcar:** Executar rollback e investigar logs

---

## 📊 RESULTADOS ESPERADOS (Quadro Resumido)

```
╔════════════════════════════════════════════════════════════════╗
║                    MÉTRICAS ESPERADAS                          ║
╠════════════════════════════════════════════════════════════════╣
║ Requisições por minuto:      1-2 (antes: 50-210)              ║
║ Erro "Unexpected token '<'": Nenhum (antes: recorrente)       ║
║ CPU durante uso normal:      15-30% (antes: 80-100%)          ║
║ Latência UI (clicks):        < 100ms (antes: 200-500ms)       ║
║ Sessão ativa:               SIM (antes: pode expirar)         ║
║ Race conditions:            NÃO (antes: SIM)                  ║
║ Compatibilidade:           ✅ Backward-compatible             ║
╚════════════════════════════════════════════════════════════════╝
```

---

## 📝 COMO PREENCHER

1. Executar cada TESTEna ordem
2. Marcar ✅ ou ❌ em cada item
3. Se tudo ✅ → **VALIDAÇÃO APROVADA**
4. Se algum ❌ → Documentar e escalate para dev team

---

**Validação Por:** ___________________________  
**Data/Hora:** ___________________________  
**Status Final:** ✅ **APROVADO** / ❌ **PENDENTE DE CORREÇÃO**

---

**Notas Adicionais:**
________________________________________________________________________
________________________________________________________________________
________________________________________________________________________

