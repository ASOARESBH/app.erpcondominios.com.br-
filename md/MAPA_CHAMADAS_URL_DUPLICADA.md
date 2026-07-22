# 🎯 Mapa de Chamadas: Onde a URL Duplicada é Usada

**Data:** 13/02/2026

---

## 📊 DIAGRAMA DE PROPAGAÇÃO

```
┌─────────────────────────────────────────────────────────────┐
│ User acessa: https://app.erpcondominios.com.br/.../frontend/ │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ Browser carrega: frontend/login.html                        │
│                                                              │
│ <script src="js/config.js"></script>  <!-- Executa!-->      │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
        ┌──────────────────────────────┐
        │ frontend/js/config.js         │
        │ ========================      │
        │                              │
        │ Linha 9: basePath = '/'      │
        │                              │
        │ Linha 28: ❌ ERRO AQUI!      │
        │ basePath = window.location   │
        │            .origin +         │
        │            path.split(       │
        │              '/frontend/'    │
        │            )[0] + '/'        │
        │                              │
        │ Resultado:                   │
        │ basePath =                   │
        │ "https://asl.erpcond .../   │
        │  home2/inlaud99/asl  .../   │
        │  erpcondominios...br/"       │
        │                              │
        │ Linha 32:                    │
        │ window.APP_BASE_PATH =       │
        │   basePath ❌ VALOR ERRADO   │
        │                              │
        │ Linha 33:                    │
        │ console.log('APP_BASE_PATH   │
        │   detected:', ...)           │
        │ 📍 AQUI VÊ A URL DUPLICADA! │
        └──────────────────────────────┘
                       │
                       │ Propaga para:
                       ▼
        ┌──────────────────────────────┐
        │ frontend/login.html           │
        │ ========================      │
        │                              │
        │ Linha 379:                   │
        │ const basePath =             │
        │   window.APP_BASE_PATH       │
        │   || '../'                   │
        │ (Usa valor ERRADO de JS!)    │
        │                              │
        │ Linha 381:                   │
        │ const logoPath =             │
        │   basePath +                 │
        │   "uploads/logo/...jpeg"     │
        │                              │
        │ Linha 383-389:               │
        │ logoImg.src = logoPath       │
        │ ❌ Resultado:                │
        │ "https://asl.erpcond.../    │
        │  home2/inlaud99/asl.../     │
        │  uploads/logo/logo....jpeg"  │
        └──────────────────────────────┘
                       │
                       ▼
        ┌──────────────────────────────┐
        │ Browser Network Request       │
        │ ========================      │
        │ GET /home2/inlaud99/asl.../ │
        │     uploads/logo/...jpeg    │
        │                              │
        │ Status: 404 NOT FOUND ❌     │
        │ (ou retorna HTML error)      │
        └──────────────────────────────┘
```

---

## 🔴 PONTO 1: Geração do `basePath` Errado

**Arquivo:** [frontend/js/config.js](frontend/js/config.js)

```javascript
(function () {
    'use strict';

    let basePath = '/';
    const script = document.currentScript;
    
    if (script && script.src) {
        if (script.src.includes('/frontend/js/')) {
            basePath = script.src.split('/frontend/js/')[0] + '/';
        } else if (script.src.includes('/js/')) {
            basePath = script.src.split('/js/')[0] + '/';
        }
    } else {
        const path = window.location.pathname;
        if (path.includes('/frontend/')) {
            // 🔴 LINHA 28 - AQUI OCORRE O ERRO:
            basePath = window.location.origin + path.split('/frontend/')[0] + '/';
            
            // Quando pathname = "/home2/inlaud99/app.erpcondominios.com.br/frontend/login.html"
            // Resultado = origin + "/home2/inlaud99/app.erpcondominios.com.br" + "/"
            // basePath = "https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/"
        }
    }

    window.APP_BASE_PATH = basePath;  // Armazena o valor ERRADO
    console.log('APP_BASE_PATH detected:', window.APP_BASE_PATH);
    // 📍 Console mostrará a URL duplicada aqui!
})();
```

**O que você verá no console do navegador:**
```
APP_BASE_PATH detected: https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/
```

---

## 🔴 PONTO 2: Uso do `basePath` Errado

**Arquivo:** [frontend/login.html](frontend/login.html)

```html
<!DOCTYPE html>
<html>
<head>
    <!-- ... -->
</head>
<body>
    <!-- ... -->
    
    <img id="loginLogo" src="" alt="Logo">
    
    <!-- ... -->
    
    <script src="js/config.js"></script>
    <!-- Após config.js carregar, window.APP_BASE_PATH está definido (ERRADO) -->
    
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            // 🔴 LINHA 379 - USA O VALOR ERRADO:
            const basePath = window.APP_BASE_PATH || '../';
            // basePath agora é: "https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/"
            
            // 🔴 LINHA 381:
            const logoPath = basePath + "uploads/logo/logo_1769740112.jpeg";
            // logoPath = "https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/uploads/logo/logo_1769740112.jpeg"
            
            const logoImg = document.getElementById("loginLogo");

            if (logoImg) {
                // 🔴 LINHA 383 - REQUISIÇÃO COM URL DUPLICADA:
                logoImg.src = logoPath;
                // Browser tentriar fazer: GET https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/uploads/logo/...
                // ❌ ERRO 404 - Arquivo não encontrado porque caminho está duplicado!
                
                logoImg.onerror = function () {
                    console.warn("Logo não encontrada, usando fallback.");
                    // 🔴 LINHA 389 - MESMO ERRO NO FALLBACK:
                    this.src = basePath + "uploads/logo/logo_padrao.png";
                    // Tenta: https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/uploads/logo/logo_padrao.png"
                    // ❌ ERRO 404 NOVAMENTE!
                };
            }
        });
    </script>
</body>
</html>
```

**Network Tab do Navegador mostrará:**
```
GET https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/uploads/logo/logo_1769740112.jpeg
    Status: 404 Not Found
    
GET https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/uploads/logo/logo_padrao.png
    Status: 404 Not Found
```

---

## 🌐 Network Tab - O que o Browser tenta fazer

Quando você abre o `frontend/login.html`, a aba Network mostra:

| Recurso | URL Esperada | URL Duplicada (Atual) | Status |
|---------|---------|---------|--------|
| Logo (primária) | `https://app.erpcondominios.com.br/uploads/logo/logo_1769740112.jpeg` | `https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/uploads/logo/logo_1769740112.jpeg` | ❌ 404 |
| Logo (fallback) | `https://app.erpcondominios.com.br/uploads/logo/logo_padrao.png` | `https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/uploads/logo/logo_padrao.png` | ❌ 404 |
| CSS | `https://app.erpcondominios.com.br/assets/css/app.css` | `https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/assets/css/app.css` | ❌ 404 |
| JS Scripts | `https://app.erpcondominios.com.br/frontend/js/...` | `https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/frontend/js/...` | ❌ 404 |

---

## 🔍 Console do Navegador

**Você verá esses logs:**

```javascript
// Do config.js:
APP_BASE_PATH detected: https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/

// Do login.html quando tenta carregar recursos:
Logo não encontrada, usando fallback.
```

**E erros de MIME type que aparecem na aba Resources:**

```
Refused to apply style from 'https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/assets/css/app.css' because its MIME type ('text/html') is not a valid CSS MIME type

Refused to execute script from 'https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/frontend/js/visual-identity.js' because its MIME type ('text/html') is not a valid JavaScript MIME type
```

---

## 📌 RESUMO: Cadeia de Error

| Passo | O que acontece | Arquivo | Linha |
|-------|----------------|---------|-------|
| 1️⃣ | Browser acessa `/frontend/login.html` | - | - |
| 2️⃣ | HTML carrega `config.js` | login.html | 373 |
| 3️⃣ | config.js calcula `basePath` ERRADO | config.js | 28 |
| 4️⃣ | Valor errado armazenado em `window.APP_BASE_PATH` | config.js | 32 |
| 5️⃣ | login.html recupera esse valor errado | login.html | 379 |
| 6️⃣ | login.html constrói URLs de recursos usando o valor errado | login.html | 381 |
| 7️⃣ | Browser tenta fazer GET para URLs DUPLICADAS | Network | - |
| 8️⃣ | Servidor retorna 404 ou HTML error (MIME type errado) | Server | - |

---

## 🎯 A URL Duplicada

**Construída em:** `frontend/js/config.js` linha 28  
**Usada em:** `frontend/login.html` linhas 379-389  
**Resultado Final:**  
```
https://app.erpcondominios.com.br/home2/inlaud99/app.erpcondominios.com.br/
```

Esta URL está sendo concatenada com paths de recursos como:
- `/uploads/logo/logo_1769740112.jpeg`
- `/frontend/js/visual-identity.js`
- `/assets/css/app.css`
- Etc.

Resultando em requisições para URLs **completamente inválidas**.

