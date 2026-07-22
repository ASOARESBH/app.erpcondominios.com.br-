# 🔴 Análise: Localização da URL Duplicada

**Data:** 13/02/2026  
**URL Duplicada:** `https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/frontend/`  
**Status:** ✅ LOCALIZADA E MAPEADA

---

## 📍 LOCAL DE ORIGEM: 2 ARQUIVOS

### **1. 🎯 PRINCIPAL: [frontend/js/config.js](frontend/js/config.js) - Linha 28**

**Código Problemático:**
```javascript
/**
 * Global Configuration
 * Detects the base path of the application to ensure assets are loaded correctly
 * regardless of the deployment folder (root or subdirectory).
 */
(function () {
    'use strict';

    let basePath = '/';

    // Try to detect based on this script's location
    const script = document.currentScript;
    if (script && script.src) {
        // We assume this script is located at .../frontend/js/config.js
        // We want the root of the project (parent of frontend)
        if (script.src.includes('/frontend/js/')) {
            basePath = script.src.split('/frontend/js/')[0] + '/';
        } else if (script.src.includes('/js/')) {
            // Fallback if structure is different
            basePath = script.src.split('/js/')[0] + '/';
            // If /js/ is in root, this gives root. If in frontend/js/, handled above.
        }
    } else {
        // Fallback: try to guess from window.location
        // If we are in /dashboard/backup ASL/frontend/login.html
        const path = window.location.pathname;
        if (path.includes('/frontend/')) {
            // 🔴 ESTA É A LINHA PROBLEMÁTICA (28):
            basePath = window.location.origin + path.split('/frontend/')[0] + '/';
        }
    }

    window.APP_BASE_PATH = basePath;
    console.log('APP_BASE_PATH detected:', window.APP_BASE_PATH);

})();
```

---

## 🔍 POR QUE ISSO GERA A URL DUPLICADA?

### **Cenário Real:**

Quando o usuário acessa:
```
https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/frontend/login.html
```

A propriedade `window.location.pathname` contém:
```
/home2/inlaud99/asl.erpcondominios.com.br/frontend/login.html
```

### **Passo a Passo do Erro:**

| Passo | Valor | Descrição |
|-------|-------|-----------|
| 1 | `window.location.origin` = `https://asl.erpcondominios.com.br` | Origem (correto) |
| 2 | `path` = `/home2/inlaud99/asl.erpcondominios.com.br/frontend/login.html` | Pathname completo |
| 3 | `path.split('/frontend/')[0]` = `/home2/inlaud99/asl.erpcondominios.com.br` | ❌ PROBLEMA! |
| 4 | Concatenação = `origin + split + '/'` | |
| 5 | **Resultado** = `https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/` | ❌ **DUPLICADO!** |

---

### **Exemplo Concreto:**

```javascript
// Entrada:
window.location.origin    = "https://asl.erpcondominios.com.br"
window.location.pathname  = "/home2/inlaud99/asl.erpcondominios.com.br/frontend/login.html"

// Processamento:
path.split('/frontend/')[0] // Retorna: "/home2/inlaud99/asl.erpcondominios.com.br"

// Saída (ERRADA):
basePath = "https://asl.erpcondominios.com.br" + "/home2/inlaud99/asl.erpcondominios.com.br" + "/"
basePath = "https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/"
```

---

## 🔗 SEGUNDO LOCAL: [frontend/login.html](frontend/login.html) - Linha 379-389

Este arquivo **usa o `basePath` errado** definido em config.js:

```html
<script>
    document.addEventListener("DOMContentLoaded", function () {
        // Usar o basePath gerado pelo config.js (que está ERRADO!)
        const basePath = window.APP_BASE_PATH || '../';
        
        // Logo path - vai ficar ERRADO se APP_BASE_PATH estiver errado
        const logoPath = basePath + "uploads/logo/logo_1769740112.jpeg";
        const logoImg = document.getElementById("loginLogo");

        if (logoImg) {
            logoImg.src = logoPath;
            logoImg.onerror = function () {
                console.warn("Logo não encontrada, usando fallback.");
                // Fallback com o basePath ERRADO também
                this.src = basePath + "uploads/logo/logo_padrao.png";
            };
        }
    });
</script>
```

**Resultado da concatanação:**
```
basePath            = "https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/"
logoPath            = basePath + "uploads/logo/logo_1769740112.jpeg"
logoPath (resultado) = "https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/uploads/logo/logo_1769740112.jpeg"
                       ❌ URL DUPLICADA!
```

---

## 🌳 FLUXO VISUAL DO ERRO

```
User Browser
    ↓
Acessa: https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/frontend/login.html
    ↓
HTML carrega e executa: <script src="js/config.js"></script>
    ↓
config.js (linha 28) executado:
    window.location.origin    = "https://asl.erpcondominios.com.br" ✓
    window.location.pathname  = "/home2/inlaud99/asl.erpcondominios.com.br/frontend/login.html"
    ↓
    basePath = origin + pathname.split('/frontend/')[0] + '/'
           = "https://asl.erpcondominios.com.br" + "/home2/inlaud99/asl.erpcondominios.com.br" + "/"
           = "https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/" ❌
    ↓
window.APP_BASE_PATH = basePath (VALOR ERRADO ARMAZENADO)
    ↓
login.html (linha 379) executado:
    const basePath = window.APP_BASE_PATH  // Recupera o valor ERRADO!
    const logoPath = basePath + "uploads/logo/..."
    imgElement.src = logoPath  // Resultado: URL com duplicação ❌
    ↓
Browser tenta carregar:
    GET https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/uploads/logo/...
             ❌ DUPLICAÇÃO DO CAMINHO COMPLETO DO DOMÍNIO!
```

---

## 📝 RESUMO DOS PROBLEMAS

| # | Arquivo | Linha | Tipo | Problema |
|---|---------|-------|------|----------|
| 1 | `frontend/js/config.js` | 28 | 🔴 **CRÍTICO** | Cálculo errado de `basePath` quando pathname contém a estrutura do servidor |
| 2 | `frontend/login.html` | 379 | 🟡 Consequência | Usa o `basePath` errado para construir URLs de recursos |

---

## 🎯 O QUE ESTÁ ACONTECENDO?

### **O Erro Raiz:**

O código assume que quando um caminho contém `/frontend/`, tudo **antes** disso é irrelevante. Mas em um environment de hospedagem compartilhada (como cPanel/WHM), o pathname **inclui todo o caminho do servidor**, não apenas a raiz do projeto web.

**Estrutura esperada (desenvolvimento local):**
```
http://localhost/frontend/login.html
pathname = /frontend/login.html
split antes de /frontend = / (raiz)
✓ Funciona!
```

**Estrutura real (hospedagem compartilhada):**
```
https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/frontend/login.html
pathname = /home2/inlaud99/asl.erpcondominios.com.br/frontend/login.html
split antes de /frontend = /home2/inlaud99/asl.erpcondominios.com.br
❌ Duplicação!
```

---

## 🔧 SOLUÇÃO NECESSÁRIA

O `config.js` precisa ser corrigido para detectar apenas o **contexto relativo** do projeto, não absolutamente o pathname do servidor.

Esperado após correção:
```javascript
basePath = window.location.origin + '/'  // Apenas origin!
// Não concatenar path.split('/frontend/')[0] quando houver subdiretórios
```

Ou usar apenas caminhos relativos em vez de absolutos:
```javascript
// Em vez de:
logoPath = basePath + "uploads/logo/..."

// Usar:
logoPath = "../uploads/logo/..."  // Sempre relativo!
```

---

## 📌 CONCLUSÃO

**A URL duplicada `https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/frontend/` é gerada por:**

1. ✅ **Identificado em:** `frontend/js/config.js` linha 28
2. ✅ **Efeito cascata em:** `frontend/login.html` linhas 379-389
3. ✅ **Causa raiz:** Lógica de detecção de `basePath` que não considera caminhos de servidor compartilhado
4. ✅ **Afeta:** Carregamento de todos os recursos (CSS, JS, imagens) quando feito via `APP_BASE_PATH`

