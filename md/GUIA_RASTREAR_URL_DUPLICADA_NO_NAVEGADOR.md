# 🔬 Guia: Como Rastrear a URL Duplicada no Navegador

**Data:** 13/02/2026  
**Objetivo:** Mostrar como você pode ver a URL duplicada sendo requisitada em tempo real

---

## 🧪 Teste 1: Console do Navegador (F12)

### Passo 1: Abrir DevTools
```
1. Abrir site: https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/frontend/login.html
2. Pressionar: F12 (ou Ctrl+Shift+I no Windows)
3. Ir para aba: Console
```

### Passo 2: Procurar o Log do Config
```
No console você verá:

┌─────────────────────────────────────────────────────────────┐
│ APP_BASE_PATH detected: https://asl.erpcondominios.com.br/  │
│                         home2/inlaud99/asl.erpcondominios... │
│                         .com.br/                              │
└─────────────────────────────────────────────────────────────┘

Este valor está ERRADO!
```

### Passo 3: Verificar Manualmente
```javascript
// No console, digite:
window.APP_BASE_PATH

// Pressione Enter

// Resultado esperado (ERRADO):
"https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/"

// Resultado correto deveria ser:
"https://asl.erpcondominios.com.br/"
```

---

## 📡 Teste 2: Network Tab (Rastrear Requisições Falhadas)

### Passo 1: Abrir DevTools → Network
```
1. Pressionar: F12
2. Ir para aba: Network
3. Recarregar página: Ctrl+R ou F5
```

### Passo 2: Procurar por 404s
```
A aba Network mostrará todas as requisições feitas pelo navegador.

Procure por linhas em VERMELHO (status 404):
```

| Recurso | URL Tentada | Status |
|---------|------|--------|
| logo_1769740112.jpeg | `https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/uploads/logo/logo_1769740112.jpeg` | 404 ❌ |
| logo_padrao.png | `https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/uploads/logo/logo_padrao.png` | 404 ❌ |
| app.css | `https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/assets/css/app.css` | 404 ❌ |
| visual-identity.js | `https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/frontend/js/visual-identity.js` | 404 ❌ |

```

### Passo 3: Clicar em um dos 404s
```
Clique em qualquer linha com status 404.

No painel direito, você verá:

Headers:
├─ Request URL: https://asl.erpcondominios.com.br/home2/inlaud99/...
├─ Request Method: GET
├─ Status Code: 404 Not Found
└─ Remote Address: xxx.xxx.xxx.xxx

Response:
├─ <html>
├─   <head>
├─     <title>404 Not Found</title>
├─   </head>
└─ </html>

Preview:
└─ Error 404: Arquivo não encontrado
```

---

## 🔗 Teste 3: Sources Tab (Ver o Código Problemático)

### Passo 1: Abrir DevTools → Sources
```
1. F12
2. Aba: Sources
3. No painel esquerdo, expandir: frontend > js
```

### Passo 2: Encontrar config.js
```
Clicar em: config.js

O arquivo abre no editor.
```

### Passo 3: Ir para Linha 28
```
Ctrl+G (Go to Line)
Digitar: 28
Pressionar: Enter

Você verá:
┌────────────────────────────────────────────┐
│ 28 │ basePath = window.location.origin +   │
│    │            path.split('/frontend/')[0]│
│    │            + '/';                     │
│    │                                        │
│    │ 🔴 ESTA É A LINHA PROBLEMÁTICA!      │
└────────────────────────────────────────────┘
```

### Passo 4: Colocar Breakpoint
```
Clique no número da linha (28) à esquerda.

Um ponto azul aparece.

Recarregue a página (F5).

O código parará aqui durante execução.

Você poderá:
├─ Inspecionar window.location.origin
├─ Inspecionar path
├─ Ver o resultado da concatenação
└─ Confirmar a URL duplicada
```

---

## 🎯 Teste 4: Expandir o Breakpoint e Inspecionar Variáveis

Quando o código parar no breakpoint (linha 28):

### Inspecionar `window.location`
```
No console ou aba Scope, você verá:

window.location.origin
┌──────────────────────────────────────────┐
│ "https://asl.erpcondominios.com.br"      │  ✓ Correto
└──────────────────────────────────────────┘

window.location.pathname
┌──────────────────────────────────────────┐
│ "/home2/inlaud99/asl.erpcondominios.../ │  ✓ Contém o caminho do servidor
│  frontend/login.html"                    │
└──────────────────────────────────────────┘
```

### Inspecionar resultado do split
```
Digite no console:

path.split('/frontend/')[0]

Resultado:
"/home2/inlaud99/asl.erpcondominios.com.br"

🔴 ESTE É O PROBLEMA!
   Contém mais do que deveria!
```

### Inspecionar basePath final
```
Digite no console:

basePath

Resultado:
"https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/"

🔴 URL DUPLICADA!
```

---

## 🎬 Teste 5: Watch Expression (Monitorar Variável)

Você pode adicionar uma "Watch Expression" para monitorar a variável:

```
1. Na aba Sources, procure "Watch Expressions" (lado direito)
2. Clique em "+" para adicionar
3. Digite: window.APP_BASE_PATH
4. Pressione Enter
5. A expressão aparecerá e será monitorada durante execução
6. Quando o código rodar, você verá o valor ser preenchido
7. Resultado será a URL duplicada!
```

---

## 📊 Visualização Completa do Fluxo

```
┌─ Navegador ────────────────────────────┐
│ F12 → Console                          │
│ ──────────────────────────────────────│
│ APP_BASE_PATH detected:                │
│ "https://asl.erpcondominios.com.br/   │
│  home2/inlaud99/asl.erpcondominios... │
│  .com.br/"                             │
│                                        │
│ ❌ ESTA É A URL DUPLICADA!            │
└────────────────────────────────────────┘

                    ↓

┌─ Navegador ────────────────────────────┐
│ F12 → Network                          │
│ ──────────────────────────────────────│
│ ✗ 404 GET /home2/inlaud99/asl... |
│           /uploads/logo/logo.jpeg │
│                                        │
│ ✗ 404 GET /home2/inlaud99/asl... │
│           /assets/css/app.css      │
│                                        │
│ ✗ 404 GET /home2/inlaud99/asl... │
│           /frontend/js/visual...   │
│                                        │
│ ❌ REQUISIÇÕES COM URL DUPLICADA!     │
└────────────────────────────────────────┘

                    ↓

┌─ Navegador ────────────────────────────┐
│ F12 → Sources → config.js              │
│ ──────────────────────────────────────│
│ Linha 28 (com Breakpoint)              │
│                                        │
│ basePath = window.location.origin +   │
│            path.split('/frontend/')[0]│
│ + '/'                                  │
│                                        │
│ Resultado:                             │
│ "https://asl.erpcondominios.com.br/  │
│  home2/inlaud99/asl.erpcondominios...│
│  .com.br/"                             │
│                                        │
│ ❌ ORIGEM DO ERRO IDENTIFICADA!       │
└────────────────────────────────────────┘
```

---

## 🧪 Teste Manual: Verificar no Console

Abra o console e execute:

```javascript
// 1. Ver o valor atual (ERRADO):
console.log('APP_BASE_PATH atual:', window.APP_BASE_PATH);

// Saída: "https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/"

// 2. Ver o pathname:
console.log('pathname:', window.location.pathname);

// Saída: "/home2/inlaud99/asl.erpcondominios.com.br/frontend/login.html"

// 3. Simular o split (que causa o problema):
const path = window.location.pathname;
console.log('resultado do split:', path.split('/frontend/')[0]);

// Saída: "/home2/inlaud99/asl.erpcondominios.com.br"

// 4. Simular a concatenação final:
const wrongBasePath = window.location.origin + path.split('/frontend/')[0] + '/';
console.log('basePath resultante (ERRADA):', wrongBasePath);

// Saída: "https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/"

// 5. Comparar com o correto:
const correctBasePath = window.location.origin + '/';
console.log('basePath que deveria ser (CORRETA):', correctBasePath);

// Saída: "https://asl.erpcondominios.com.br/"
```

**Saída esperada:**
```
APP_BASE_PATH atual: https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/
pathname: /home2/inlaud99/asl.erpcondominios.com.br/frontend/login.html
resultado do split: /home2/inlaud99/asl.erpcondominios.com.br
basePath resultante (ERRADA): https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/
basePath que deveria ser (CORRETA): https://asl.erpcondominios.com.br/
```

---

## 📌 Checklist de Rastreamento

✅ **Console:** Ver `APP_BASE_PATH detected: ...` com URL duplicada
✅ **Network:** Ver requisições 404 com `/home2/inlaud99/...` no caminho
✅ **Sources:** Pausar código em `config.js` linha 28
✅ **Watch:** Monitorar `window.APP_BASE_PATH` durante execução
✅ **Manual:** Executar console.logs que confirmem o valor errado

---

## 🎯 Conclusão

Quando você executar esses testes, você conseguirá **ver em tempo real**:

1. **Exatamente onde** a URL duplicada é criada: `frontend/js/config.js` linha 28
2. **Como** ela é criada: através da concatenação errada de `origin + split + '/`
3. **Onde** ela é usada: `frontend/login.html` linha 379
4. **O que acontece** com ela: é concatenada com paths, criando URLs inválidas de 404

A URL `https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/...` aparecerá em **TODOS** os testes acima.

