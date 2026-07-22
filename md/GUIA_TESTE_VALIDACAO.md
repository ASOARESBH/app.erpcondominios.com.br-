# 🧪 Guia de Teste: Validação das Correcções

**Objetivo:** Validar que as correcções resolvem o problema de URL duplicate e MIME type

**Tempo estimado:** 5-10 minutos

---

## ✅ Pré-Requisitos

- [ ] Browser moderno (Chrome, Firefox, Safari, Edge)
- [ ] Como acessar DevTools (F12)
- [ ] Credenciais válidas para login
- [ ] Cache do navegador limpo

---

## 🧹 PASSO 1: Limpar Cache do Navegador

### Chrome / Brave / Edge
1. Pressione `Ctrl+Shift+Delete` (ou `Cmd+Shift+Delete` no Mac)
2. Selecione:
   - [x] Cookies e outros dados de site
   - [x] Imagens em cache
   - [x] Arquivos em cache
3. Clique "Limpar Dados"
4. Feche todas as abas e reabra o navegador

### Firefox
1. Pressione `Ctrl+Shift+Delete`
2. Selecione "Tudo"
3. Clique "Limpar agora"

---

## 📍 PASSO 2: Acessar a Aplicação

1. Abra uma nova aba
2. Acesse: `https://app.erpcondominios.com.br/`
3. **Resultado esperado:**
   - [ ] Página **login.html** carrega
   - [ ] Sem mensagem de erro
   - [ ] Formulário visível e funcional

**Se falhar:** Verifique se o domínio está acessível. Verifique a URL no navegador.

---

## 🔍 PASSO 3: Verificar URLs no Histórico

1. Pressione `Ctrl+H` (ou `Cmd+Y` no Mac)
2. Procure pela URL atual
3. **Verificar:**
   - [ ] URL é: `https://app.erpcondominios.com.br/` (SEM duplicação)
   - [ ] URL NÃO é: `.../app.erpcondominios.com.br/...`

**Se falhar:** Feche todas as abas. Limpe cache novamente.

---

## 👤 PASSO 4: Executar Login

1. Preencha o formulário com credenciais válidas
2. Clique em "Entrar"
3. **Resultado esperado:**
   - [ ] Mensagem "Login realizado com sucesso" aparece
   - [ ] Aguarde 500ms (loading)
   - [ ] Redirecione para Dashboard
   - [ ] URL muda para: `layout-base.html?page=dashboard`

**Se falhar:** Verifique credenciais. Abra F12 para ver erros no console.

---

## 🔨 PASSO 5: Abrir DevTools - Network Tab

1. Pressione `F12` (DevTools abre)
2. Clique na aba **"Network"** (ou "Rede")
3. **Ative logging antes de fazer login:**
   - Marque a opção "Preserve log" (Preservar log)
4. Recarregue a página (`F5`)
5. Faça login novamente

---

## ✅ PASSO 6: Validar Network Tab

Na aba Network, procure pelos seguintes recursos:

### 6.1 - CSS Files (Deve carregar com status 200)

```
Procure por:          Esperado:
─────────────────────────────────────────
app.css              Status: 200 ✅
                     Type: css ✅
                     Size: >0 bytes ✅

theme-blue.css       Status: 200 ✅
                     Type: css ✅
                     Size: >0 bytes ✅
```

**Verificar MIME Type:**
1. Clique no arquivo CSS
2. Aba "Headers"
3. Procure por: `Content-Type: text/css` ✅

**Se estiver errado:**
```
❌ Content-Type: text/html (ERRO!)
Significa: CSS foi servido como HTML (404)
Solução: Verifique .htaccess e caminhos relativos
```

### 6.2 - JavaScript Files (Deve carregar com status 200)

```
Procure por:          Esperado:
─────────────────────────────────────────
visual-identity.js   Status: 200 ✅
                     Type: javascript ✅
                     Size: >0 bytes ✅

app-router.js        Status: 200 ✅
                     Type: javascript ✅
                     Size: >0 bytes ✅

dashboard.js         Status: 200 ✅
                     Type: javascript ✅
```

**Verificar MIME Type:**
1. Clique no arquivo JS
2. Aba "Headers"
3. Procure por: `Content-Type: application/javascript` ✅

### 6.3 - API Calls (Deve carregar com status 200-201)

```
Procure por:          Esperado:
─────────────────────────────────────────
verificar_sessao.php Status: 200 ✅
                     Type: xhr (fetch) ✅
                     Response: JSON com
                              "sucesso": true ✅

validar_login.php    Status: 200 ✅
                     Type: xhr (fetch) ✅
                     Response: JSON com
                              "sucesso": true ✅
```

**Verificar Response:**
1. Clique no arquivo API
2. Aba "Response"
3. Verifique JSON:
```json
{
  "sucesso": true,
  "mensagem": "Login realizado com sucesso",
  "dados": {
    "nome": "...",
    "permissao": "..."
  }
}
```

### 6.4 - Nenhum erro 404, 403, 500

Em vermelho, não deveria haver:
```
❌ 404 (Arquivo não encontrado)
❌ 403 (Acesso negado)
❌ 500 (Erro do servidor)
```

**Se houver:**
```
Verificar o arquivo não encontrado
Procurar em qual caminho está sendo requisitado
Validar .htaccess e caminhos relativos
```

---

## 🎨 PASSO 7: Validar Console Tab

1. Clique na aba **"Console"** (DevTools)
2. Procure por mensagens com `[App]`, `[Router]`, `[Dashboard]`

**Esperado (sem erros):**
```javascript
[App] Inicializando aplicação...
[Router] Inicializando...
[Router] Carregando página: dashboard
[Dashboard] Inicializado
✅ Nenhuma mensagem de erro
✅ Nenhuma mensagem vermelha
```

**Se houver erros:**
```
❌ Uncaught SyntaxError: Unexpected token
❌ Uncaught TypeError: Cannot read property
❌ Failed to fetch: 404
↓
Anote a mensagem exacta
Procure no código o arquivo/função mencionada
Verifique paths relativos
```

---

## 🎯 PASSO 8: Validar Interface Visual

1. Verifique se a página carregou correctamente:
   - [ ] **Sidebar** visível à esquerda
   - [ ] **Header** com nome do usuário à direita
   - [ ] **Conteúdo do Dashboard** no centro
   - [ ] **Sem área em branco** ou "Carregando..."

2. Clique num item da sidebar (ex: "Visitantes")
   - [ ] Página carrega sem erro
   - [ ] URL muda para: `layout-base.html?page=visitantes`
   - [ ] Sidebar item fica destacado
   - [ ] SEM recarregar página inteira

3. Clique no botão "Voltar" do navegador
   - [ ] Volta para dashboard
   - [ ] URL muda para: `layout-base.html?page=dashboard`
   - [ ] SEM recarregar página

---

## 📊 PASSO 9: Validar localStorage

1. Abra DevTools (F12)
2. Clique em **"Application"** (ou "Armazenamento")
3. À esquerda, selecione **"Local Storage"**
4. Clique em sua URL

**Esperado:**
```
Key                    Value
──────────────────────────────
usuario_nome           João Silva (ou seu nome)
usuario_permissao      admin (ou sua permissão)
```

**Se estiver vazio:**
- [ ] Login pode não ter salvado dados
- [ ] Verifique se `validar_login.php` retorna `dados`

---

## ✨ PASSO 10: Teste de Responsive (Mobile)

1. DevTools aberto (F12)
2. Clique em **"Toggle device toolbar"** (ou `Ctrl+Shift+M`)
3. Selecione dispositivo mobile (ex: iPhone 12)

**Esperado:**
- [ ] Sidebar collapsa
- [ ] Menu toggle (≡) aparece
- [ ] Clique em ≡ para abrir/fechar sidebar
- [ ] Dashboard responsivo
- [ ] Sem erros de MIME type

---

## 📋 Checklist de Validação FINAL

### Sucesso = Todos os ✅

```
NETWORK TAB:
  [✅] CSS carrega status 200
  [✅] JS carrega status 200
  [✅] API responde 200-201
  [✅] Nenhum 404, 403, 500
  [✅] MIME types corretos (text/css, application/javascript, application/json)

CONSOLE:
  [✅] Nenhuma mensagem de erro vermelha
  [✅] Messages [App], [Router], [Dashboard]
  [✅] URL sem duplicação

INTERFACE:
  [✅] Sidebar visível
  [✅] Header com usuário
  [✅] Dashboard carregado
  [✅] Navegação funciona
  [✅] Back/Forward funciona
  [✅] Mobile responsivo

DADOS:
  [✅] localStorage com dados do usuário
  [✅] URL correta: layout-base.html?page=X
  [✅] Nenhuma URL duplicada
```

---

## ❌ Troubleshooting: Se Algo Estiver Errado

### "CSS retorna MIME type: text/html"

```
Causa: Caminho absoluto /assets/ causando 404
Solução:
  1. Verificar se é /assets/ ou ../assets/
  2. Chamar F5 para recarregar
  3. Limpar cache novamente (Ctrl+Shift+Delete)
```

### "404 em /api/verificar_sessao.php"

```
Causa: Endpoint não existe ou path está errado
Solução:
  1. Verificar /frontend/index.html linha 55
  2. Deve ser: ../api/verificar_sessao.php
  3. Não deve ser: /api/api_verificar_sessao.php
```

### "Sidebar não aparece"

```
Causa: JavaScript não carregou
Solução:
  1. Abrir F12 Console
  2. Procurar por erros vermelhos
  3. Procurar arquivo que causa erro
  4. Verificar caminho relativo desse arquivo
```

### "API retorna 403 Forbidden"

```
Causa: .htaccess protege /api/
Solução:
  1. Verifique .htaccess na raiz
  2. Verifique se <Directory "/api"> Allow from all
  3. Se não houver, adicione as linhas
```

---

## 📞 Suporte

Se algum teste falhar:
1. Anote exactamente qual passo falhou
2. Screnshot do erro (F12 Console)
3. URL exacta que estava sendo acessada
4. Mensagem de erro exacta

---

**Tempo Estimado:** 5-10 minutos  
**Complexidade:** Baixa (só observar console)  
**Resultado:** Validação completa das correcções  

**Status:** ✅ Pronto para testar!