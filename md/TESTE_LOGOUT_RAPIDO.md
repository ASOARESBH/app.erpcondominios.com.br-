# 🧪 TESTE RÁPIDO - FUNÇÃO LOGOUT

**Data:** 13/02/2026  
**Tempo:** 2 minutos

---

## ⚡ TESTE IMEDIATO

### Passo 1: Abrir Dashboard
```
URL: https://app.erpcondominios.com.br/frontend/dashboard.html
     ou
     https://app.erpcondominios.com.br/frontend/layout-base.html?page=dashboard
```

### Passo 2: Localizar Botão "Sair"
```
Procure no MENU LATERAL ESQUERDO (sidebar)
Na PARTE INFERIOR, separado por uma linha
Cor: VERMELHO (#fca5a5)
Ícone: ↪ (Sign Out)
Texto: "Sair"
```

### Passo 3: Clicar no Botão
```
Aparecerá um diálogo:
"Deseja realmente sair do sistema? Sua sessão será encerrada."

🔘 OK       🔘 Cancelar
```

### Passo 4: Confirmar
```
Clique em "OK"
```

### Passo 5: Validar Redirecionamento
```
Resultado esperado:
✅ Página muda para login.html
✅ URL agora é: https://app.erpcondominios.com.br/login.html
✅ Botão "Sair" desaparece
```

---

## 🔍 VALIDAÇÃO TÉCNICA (DevTools)

### Console (F12)
```javascript
// Cole no console:
// Não há comandos específicos, mas você deve ver:
// ✅ Logout bem-sucedido (mensagem de log)
```

### Network (F12)
```
Abra a aba Network
Clique em "Sair"
Procure por: logout.php

Deve haver uma linha:
POST  ../api/logout.php  200 OK
```

### Storage (F12)
```
Aba: Application → Storage
Antes do logout:
- localStorage tem dados
- sessionStorage tem dados  
- Cookies tem session ID

Depois do logout:
- localStorage VAZIO
- sessionStorage VAZIO
- Cookies LIMPOS
```

---

## ✅ CHECKLIST RÁPIDO

| Teste | Esperado | Resultado | ✅/❌ |
|-------|----------|-----------|------|
| Botão visível | Sim | ? | |
| Confirmar aparece | Sim | ? | |
| Redireciona para login | Sim | ? | |
| Storage limpo | Sim | ? | |
| Não pode voltar | Correto | ? | |

---

## 🎯 Resumo do Fluxo

```
┌─────────────────────────────────────────────────┐
│ USUÁRIO NO DASHBOARD                            │
│                                                 │
│  Menu Lateral (esquerda)                       │
│  ├── Dashboard                                  │
│  ├── Moradores                                 │
│  ├── ...                                        │
│  └── [Sair] ← CLICK AQUI                       │
└────────┬───────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────┐
│ CONFIRMAÇÃO APARECE                             │
│                                                 │
│ "Deseja realmente sair do sistema?"            │
│ "Sua sessão será encerrada."                   │
│                                                 │
│ [OK] [Cancelar]  ← Clique OK                   │
└────────┬───────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────┐
│ PROCESSAMENTO (500ms)                          │
│                                                 │
│ 1. Botão fica desabilitado                    │
│ 2. POST /api/logout.php                       │
│ 3. Limpa localStorage                          │
│ 4. Limpa sessionStorage                        │
│ 5. Limpa cookies                               │
│ 6. Aguarda 500ms                              │
│ 7. Redireciona                                │
└────────┬───────────────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────┐
│ PÁGINA DE LOGIN                                 │
│                                                 │
│ URL: https://asl.erp../login.html             │
│                                                 │
│ Campos: Email, Senha                          │
│ Status: LOGOUT COMPLETO ✅                    │
└─────────────────────────────────────────────────┘
```

---

## 📞 Se Não Funcionar

### Cenário 1: Botão não aparece
```
1. Abrir DevTools (F12)
2. Console
3. Procurar por erros em vermelho
4. Verificar se dashboard.html carregou completamente
```

### Cenário 2: Confirmação não aparece
```
1. Verificar se JavaScript está habilitado
2. Verificar console (F12) por erros
3. Tentar com outro navegador
```

### Cenário 3: Logout não redireciona
```
1. DevTools (F12) → Network
2. Procurar por logout.php
3. Verificar status (deve ser 200)
4. Manual: Acessar /login.html diretamente
```

### Cenário 4: Storage não limpa
```
1. Isso é normal de alguns navegadores (Dev mode)
2. Mas logout.php executa no servidor (PHP)
3. Session foi destruída no backend
4. Tentar fazer login novamente (deve pedir credenciais)
```

---

## 🎉 Se Tudo Funcionar

```
✅ LOGOUT ESTÁ 100% FUNCIONANDO!

Próximos passos:
1. Testar com outro usuário
2. Testar em mobile browser
3. Testar logout acidental (cancelar)
4. Verificar logs de logout
```

---

**Tempo de teste:** 2-3 minutos  
**Dificuldade:** Muito fácil  
**Risco:** Nenhum (trata logout limpo)

