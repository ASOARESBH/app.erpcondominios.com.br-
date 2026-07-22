# 🧪 GUIA DE TESTE - Validar Todas as Correções

**Data:** 13/02/2026  
**Status:** 🟢 PRONTO PARA TESTAR

---

## ⚡ TESTE RÁPIDO (5 MINUTOS)

### Passo 1: Limpar Cache
```
Pressione: Ctrl+Shift+Delete (Windows) ou Cmd+Shift+Delete (Mac)
Selecione: "Cookies and cached images and files"
Clique: "Clear now"
```

### Passo 2: Acessar Login
```
Abrir: https://app.erpcondominios.com.br/frontend/login.html
Resultado esperado:
- ✅ Logo carrega (não fica em branco)
- ✅ Página não tem erros de CSS
- ✅ Campos de entrada aparecem normalmente
```

### Passo 3: Abrir DevTools
```
Pressione: F12
Abra aba: Console
Digite: window.APP_BASE_PATH
Resultado esperado: "https://app.erpcondominios.com.br/"
```

### Passo 4: Verificar Network
```
Aba: Network
Procure por: 404
Resultado esperado: Nenhum 404 com /home2/inlaud99/ no caminho!
```

---

## 📋 TESTES DETALHADOS

### ✅ TESTE 1: Verificar APP_BASE_PATH

**O que testar:**
```javascript
// Digite no Console (F12):
window.APP_BASE_PATH

// Resultado CORRETO:
"https://app.erpcondominios.com.br/"

// Resultado ERRADO (indicaria falta de correção):
"https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/"
```

**Status:** ✅ ESPERADO = CORRETO

---

### ✅ TESTE 2: Verificar se Logo Carrega

**Manual:**
1. Abrir `https://app.erpcondominios.com.br/frontend/login.html`
2. Procurar por imagem no topo (logo da empresa)
3. Se a imagem aparecer = ✅ CORRETO
4. Se a imagem NÃO aparecer ou box vazio = ❌ ERRADO

**Via DevTools:**
```
Aba: Network
Procure por: logo_1769740112.jpeg ou logo_padrao.png
Status esperado: 200 OK
URL esperada: https://app.erpcondominios.com.br/uploads/logo/logo_1769740112.jpeg
```

**Status:** ✅ ESPERADO = Logo visível + Status 200

---

### ✅ TESTE 3: Verificar se há 404s de URL Duplicada

**O que fazer:**
1. Abrir DevTools (F12)
2. Nova aba: Network
3. Recarregar página: F5
4. Procurar por linhas em VERMELHO (status 404)
5. Clicar em cada 404 e procurar por `/home2/inlaud99/` na URL

**Resultado CORRETO:**
```
Nenhuma URL contendo /home2/inlaud99/app.erpcondominios.com.br/
```

**Resultado ERRADO:**
```
Requests como:
GET /home2/inlaud99/app.erpcondominios.com.br/assets/css/app.css 404
GET /home2/inlaud99/app.erpcondominios.com.br/uploads/logo/... 404
```

**Status:** ✅ ESPERADO = Nenhuma URL duplicada

---

### ✅ TESTE 4: Verificar Manifest

**O que fazer:**
1. DevTools (F12)
2. Aba: Application
3. Lado esquerdo: Manifest
4. Procurar por "icons"

**Resultado CORRETO:**
```
Todas as ícones devem ter uma imagem pequena ao lado
Todas devem ter tamanhos válidos (72x72, 192x192, etc)
```

**Resultado ERRADO:**
```
Ícones com "?" ou vazio
URLs como /ico/icon-192x192.png (absoluto)
```

**Status:** ✅ ESPERADO = Todos os ícones carregados

---

### ✅ TESTE 5: Testar Login

**O que fazer:**
1. Preencher credenciais de teste
2. Clicar em "Login"
3. Observar redirecionamento

**Resultado CORRETO:**
```
✅ Redireciona para dashboard
✅ Não há erros de 404 no Network
✅ Página carrega normalmente
```

**Resultado ERRADO:**
```
❌ Fica na página de login
❌ Erro CORS (Access denied)
❌ Erro 404 na API
```

**Status:** ✅ ESPERADO = Login bem-sucedido

---

### ✅ TESTE 6: Verificar PWA (Mobile)

**O que fazer:**
1. Abrir em dispositivo mobile
2. Abrir `https://app.erpcondominios.com.br/frontend/console_acesso.html`
3. Browser deve sugerir "Add to Home Screen"

**Resultado CORRETO:**
```
✅ Instala PWA
✅ Ícones aparecem (não genéricos)
✅ App abre corretamente
```

**Resultado ERRADO:**
```
❌ PWA não instala
❌ Ícone genérico (sem logo)
❌ App não funciona
```

**Status:** ✅ ESPERADO = PWA funciona

---

## 🔍 TESTES AUTOMÁTICOS (No Console)

Cole esses comandos no Console (F12) para testar automaticamente:

### Teste 1: Verificar basePath
```javascript
if (window.APP_BASE_PATH === 'https://app.erpcondominios.com.br/') {
    console.log('✅ APP_BASE_PATH CORRETO');
} else {
    console.log('❌ APP_BASE_PATH ERRADO:', window.APP_BASE_PATH);
}
```

### Teste 2: Verificar se manifes carrega
```javascript
fetch('../manifest.json')
    .then(r => r.json())
    .then(d => console.log('✅ Manifest carregado:', d.name))
    .catch(e => console.log('❌ Erro ao carregar manifest:', e));
```

### Teste 3: Verificar se uploads folder existe
```javascript
fetch('../uploads/').then(r => {
    if (r.status === 404) {
        console.log('❌ Pasta uploads não encontrada');
    } else {
        console.log('✅ Pasta uploads existe');
    }
}).catch(e => console.log('❌ Erro:', e));
```

### Teste 4: Verificar se logo existe
```javascript
fetch('../uploads/logo/logo_1769740112.jpeg')
    .then(r => {
        if (r.ok) {
            console.log('✅ Logo principal encontrada');
        } else {
            console.log('❌ Logo principal não existe (status: ' + r.status + ')');
        }
    })
    .catch(e => console.log('❌ Erro ao buscar logo:', e));
```

### Teste 5: Verificar se logo_padrao existe
```javascript
fetch('../uploads/logo/logo_padrao.png')
    .then(r => {
        if (r.ok) {
            console.log('✅ Logo padrão encontrada');
        } else {
            console.log('❌ Logo padrão não existe (status: ' + r.status + ')');
        }
    })
    .catch(e => console.log('❌ Erro ao buscar logo padrão:', e));
```

---

## 📊 Resumo de Testes

| Teste | O que verifica | Esperado | Comando |
|-------|---|---|---|
| 1 | APP_BASE_PATH | `https://app.erpcondominios.com.br/` | `window.APP_BASE_PATH` |
| 2 | Logo | Visível + 200 OK | Visual + Network tab |
| 3 | URLs duplicadas | Nenhum 404 com `/home2/inlaud99/` | Network tab |
| 4 | Manifest | Ícones carregados | DevTools > Application |
| 5 | Login | Funciona normalmente | Testar credenciais |
| 6 | PWA | Instala corretamente | Mobile browser |

**Resultado Final:** Se todos os 6 testes passarem = ✅ **TUDO CORRETO!**

---

## ❌ Troubleshooting

Se algum teste falhar:

### Problema: APP_BASE_PATH ainda está duplicado

**Solução:**
1. Verificar se `frontend/js/config.js` foi atualizado
2. Limpar cache: Ctrl+Shift+Delete
3. Recarregar página: Ctrl+F5 (force refresh)

### Problema: Logo não carrega

**Verificar:**
1. Na Network tab, procurar por `logo_1769740112.jpeg`
2. Se status = 404, verificar se arquivo existe em `uploads/logo/`
3. Se URL contém `/home2/inlaud99/`, voltar ao Problema 1

### Problema: Manifest não carrega

**Verificar:**
1. DevTools > Application > Manifest
2. Se mostra erro, verificar `manifest.json` foi atualizado
3. URLs do manifest devem ser relativas, não absolutas

### Problema: PWA não instala

**Verificar:**
1. Limpar cache do mobile
2. Verificar que manifest.json está carregando (Status 200)
3. Testar em Chrome/Android (suporte melhor)

---

## 📞 Suporte

Se algum teste falhar persistentemente:

1. ✅ Confirmar que todos os 3 arquivos foram atualizados
   - [ ] `frontend/js/config.js` - Verificar conteúdo
   - [ ] `frontend/login.html` - Verificar linha 379-389
   - [ ] `manifest.json` - Verificar start_url e icons

2. ✅ Limpar cache completamente
   - Ctrl+Shift+Delete (todo o cache)
   - Reabrir navegador
   - Testar novamente

3. ✅ Verificar se servidor está servindo os arquivos corretos
   - Abrir Network tab
   - Ver Response headers
   - Confirmar Content-Type corretos

---

## 🎉 Final

Se todos os testes passarem, a aplicação está pronta para:
- ✅ Produção
- ✅ Hospedagem compartilhada
- ✅ PWA em mobile
- ✅ Qualquer ambiente

**Data de Teste:** 13/02/2026  
**Status Final:** 🟢 PRONTO

