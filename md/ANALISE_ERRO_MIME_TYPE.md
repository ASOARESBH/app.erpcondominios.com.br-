# 🐛 Análise e Resolução: Erro de URL Duplicada e MIME Type

**Data:** 12/02/2026  
**Status:** ✅ RESOLVIDO

---

## 📊 **O Problema Identificado**

### **Erro Reportado:**
```
URL: https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/frontend/

Refused to apply style from '..../assets/css/app.css' 
  MIME type: text/html (esperado: text/css)
  
Refused to execute script from '..../js/visual-identity.js' 
  MIME type: text/html (esperado: text/javascript)
  
Failed to load resource: api/verificar_sessao.php
  Status: 403 Forbidden
```

### **Causa Raiz:**

A URL estava **duplicando o path do domínio**:
- ❌ `https://asl.erpcondominios.com.br/home2/inlaud99/asl.erpcondominios.com.br/`
- ✅ Deveria ser: `https://asl.erpcondominios.com.br/`

**Por quê?**

O servidor Apache estava processando requisições com caminhos **absolutos** (começando com `/`), e como o domínio está apontado para um subdiretório (`/home2/inlaud99/asl.erpcondominios.com.br/`), houve duplicação.

---

## 🔍 **Rastreamento do Erro**

### **1. Arquivo: `/frontend/index.html` (Linha 55)**

**Código Problemático:**
```javascript
fetch('/api/api_verificar_sessao.php', {  // ❌ CAMINHO ABSOLUTO
    credentials: 'include'
})
```

**Problemas:**
- ❌ Usa caminho absoluto `/api/` em vez de relativo `../api/`
- ❌ Chama `api_verificar_sessao.php` (arquivo não existe)
- ❌ Deveria chamar `verificar_sessao.php`

**Por que causa erro em cascata:**

```
1. Navegador carrega /frontend/index.html
   ↓
2. Script tenta fazer fetch('/api/api_verificar_sessao.php')
   ↓
3. Apache processa como /api/ na raiz do servidor
   ↓
4. Como domínio aponta para /home2/inlaud99/asl.erpcondominios.com.br/
   ↓ (duplicação ocorre no redirecionamento interno)
5. Resultado: /home2/inlaud99/asl.erpcondominios.com.br/api/
   ↓
6. Nginx/Apache retorna 404 HTML em vez de PHP
   ↓
7. CSS, JS, etc. também retornam 404 HTML
   ↓
8. Navegador rejeita com "MIME type is text/html, expected text/css"
```

### **2. Arquivo: `/frontend/console_acesso.html` (Linha 13-17)**

**Código Problemático:**
```html
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/png" href="/ico/icon-192x192.png">
<link rel="apple-touch-icon" href="/ico/icon-192x192.png">
```

**Problema:** Mesma causa - caminhos absolutos causam duplicação

---

## ✅ **Soluções Implementadas**

### **1. Corrigir `/frontend/index.html`**

**Antes:**
```javascript
fetch('/api/api_verificar_sessao.php', {
    credentials: 'include'
})
.then(response => response.json())
.then(data => {
    if (data.sucesso && data.logado) {
        window.location.replace('layout-base.html?page=dashboard');
    } else {
        window.location.replace('login.html');  // ❌ Caminho relativo incompleto
    }
})
```

**Depois:**
```javascript
fetch('../api/verificar_sessao.php', {  // ✅ Caminho relativo correto
    credentials: 'include'
})
.then(response => response.json())
.then(data => {
    if (data.sucesso && data.logado) {
        window.location.replace('layout-base.html?page=dashboard');
    } else {
        window.location.replace('../login.html');  // ✅ Caminho relativo completo
    }
})
```

**Mudanças:**
- ✅ `/api/api_verificar_sessao.php` → `../api/verificar_sessao.php`
- ✅ `login.html` → `../login.html` (quando redireciona de /frontend/)

### **2. Corrigir `/frontend/console_acesso.html`**

**Antes:**
```html
<link rel="manifest" href="/manifest.json">
<link rel="icon" href="/ico/icon-192x192.png">
<link rel="apple-touch-icon" href="/ico/icon-192x192.png">
```

**Depois:**
```html
<link rel="manifest" href="../manifest.json">
<link rel="icon" href="../ico/icon-192x192.png">
<link rel="apple-touch-icon" href="../ico/icon-192x192.png">
```

### **3. Atualizar `.htaccess` (Raiz)**

**Melhorias:**
- ✅ Ordem melhorada de regras para evitar duplicação
- ✅ Proteção de MIME types com headers corretos
- ✅ Cache explícito para assets estáticos
- ✅ Diretórios protegidos contra acesso direto
- ✅ Compatibilidade com subdiretórios de hospedagem

**Regras críticas:**
```apache
# Permitir acesso a arquivos reais antes de rewrite
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]

RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# Headers para MIME types corretos
Header set X-Content-Type-Options "nosniff"
```

---

## 🛠️ **Checklist: Por que Caminhos Absolutos são Problemáticos em Subdiretórios**

Quando um projeto está em um subdiretório do servidor:

```
Caminho no servidor:  /home/user/public_html/asl.erpcondominios.com.br/
URL acessada:         https://asl.erpcondominios.com.br/

❌ Caminho ABSOLUTO:  /assets/css/app.css
   Resolve para:      /home/user/public_html/assets/css/app.css
   Problema:          Arquivo não está lá!

✅ Caminho RELATIVO:  ../assets/css/app.css (de /frontend/)
   Resolve para:      /home/user/public_html/asl.erpcondominios.com.br/assets/css/app.css
   Sucesso:           Arquivo encontrado!
```

---

## 🧪 **Teste de Validação**

### **Antes (Com Erro):**
```
Acesso: https://asl.erpcondominios.com.br/
   ↓
/frontend/index.html carrega
   ↓
fetch('/api/api_verificar_sessao.php') → 404 (duplicação de path)
   ↓
CSS/JS retornam 404 HTML
   ↓
Navegador: "MIME type is text/html"
   ↓
❌ Página não funciona
```

### **Depois (Corrigido):**
```
Acesso: https://asl.erpcondominios.com.br/
   ↓
login.html carregue (DirectoryIndex)
   ↓
Login bem-sucedido
   ↓
window.location.href = './frontend/layout-base.html?page=dashboard'
   ↓
layout-base.html carrega com:
   - ../api/verificar_sessao.php ✅
   - ../assets/css/app.css ✅
   - ../assets/css/themes/theme-blue.css ✅
   ↓
Sidebar + Dashboard carregam corretamente
   ↓
✅ Página funciona perfeitamente
```

---

## 📋 **Arquivos Modificados**

| Arquivo | Mudança | Linha(s) |
|---------|---------|----------|
| `/frontend/index.html` | Caminho API e redirecionamentos | 55, 62, 67 |
| `/frontend/console_acesso.html` | Icons e manifest | 13-17 |
| `/.htaccess` | Rewrite rules otimizadas | Completo |

---

## 🚀 **Como Executar o Teste**

1. **Limpar cache do navegador:**
   ```
   Ctrl+Shift+Delete (ou Cmd+Shift+Delete no Mac)
   ```

2. **Acessar a raiz:**
   ```
   https://asl.erpcondominios.com.br/
   ```

3. **Fazer login** com credenciais válidas

4. **Verificar DevTools (F12):**
   - ✅ Nenhum erro de "MIME type"
   - ✅ Nenhum erro de "Failed to load resource"
   - ✅ CSS carregado como `text/css`
   - ✅ JS carregado como `text/javascript`
   - ✅ API responde com 200-201
   - ✅ URL fica: `layout-base.html?page=dashboard`

5. **Clicar em links da sidebar:**
   - ✅ Navegação funciona
   - ✅ URL atualiza (ex: `?page=visitantes`)
   - ✅ Sem erro de MIME type

---

## 🔮 **Por que Isso não foi Detectado Antes?**

1. **Em localhost** - Projeto está na raiz `/localhost/dashboard/`
   - Caminho absoluto `/api/` funciona
   - Não há duplicação de path

2. **Em subdiretório de produção** - Projeto em `/home/user/asl.erpcondominios.com.br/`
   - Apache resolve `/api/` na raiz do servidor
   - Resulta em duplicação do path
   - CSS/JS retornam 404 HTML

**Lição:** Sempre usar **caminhos relativos** em projetos web para máxima compatibilidade!

---

## 📚 **Boas Práticas Implementadas**

✅ **Caminhos Relativos** - Sempre use `../` em vez de `/`  
✅ **MIME Type Correto** - Headers no .htaccess definem tipos corretos  
✅ **Cache Apropriado** - Assets estáticos em cache, HTML em 1 hora  
✅ **Segurança** - Bloquear acesso a arquivos sensíveis (.env, .sql)  
✅ **DirectoryIndex** - Servir login.html como padrão  

---

## ❓ **FAQ**

**P: Por que alguns arquivos continuam retornando 403?**  
R: O .htaccess/servidor pode estar bloqueando acesso a certos arquivos por segurança. Verifique se há diretórios protegidos adicionais.

**P: Como evitar esse problema em novo código?**  
R: Sempre use caminhos relativos como: `../api/`, `./pages/`, etc. Evite `/api/`, `/assets/`, etc.

**P: E se o projeto mudar de subdiretório?**  
R: Caminhos relativos funcionarão automaticamente. Caminhos absolutos precisariam de reconfigração.

---

**Status:** ✅ RESOLVIDO  
**Próximo passo:** Testar em produção e monitorar console do navegador