# ✅ PRÓXIMAS AÇÕES - GUIA PRÁTICO

**Data:** 13/02/2026  
**Objetivo:** Instruções práticas para validar e deploy  
**Tempo Estimado:** 15 minutos

---

## 🎬 PASSO 1: VALIDAR LOCAL (3 minutos)

### 1.1 Abrir o Navegador
```javascript
1. Abra: https://asl.erpcondominios.com.br/dashboard.html
2. Login com suas credenciais
3. Você deve ver o dashboard normal
```

### 1.2 Verificar Config Correta
```javascript
// No console do navegador (F12 → Console):

// Verificação 1: APP_BASE_PATH
window.APP_BASE_PATH
// ✅ Esperado: "https://asl.erpcondominios.com.br/"
// ❌ Não deve ter: "/home2/inlaud99/" ou duplicações

// Verificação 2: Nenhum 404
console.log(document.querySelectorAll('img, link, script').length, 'recursos carregados')
// Olhe na aba Network (F12 → Network)
// ✅ Todos devem estar em verde (200 OK)
// ❌ Nenhum deve estar em vermelho (404)

// Verificação 3: Teste a função logout
typeof fazerLogout
// ✅ Esperado: "function"
// ❌ Não deve ser "undefined"
```

---

## 🔴 PASSO 2: TESTAR BOTÃO SAIR (3 minutos)

### 2.1 Localizar o Botão
```
No dashboard, procure pela barra lateral (lado esquerdo):
- Dashboard
- Moradores
- Veículos
- ...
- [🔴 Sair]  ← Este é o botão!

Características:
✅ Cor vermelha
✅ No final do menu (embaixo)
✅ Separado por uma linha
✅ Texto dizendo "Sair" ou "Sign Out"
```

### 2.2 Clicar e Confirmar
```
Passo 1: Clique no botão "Sair"

Passo 2: Uma caixa de diálogo aparecerá:
    "Deseja realmente sair do sistema?"
    "Sua sessão será encerrada."

Passo 3: Clique em "OK"
    ✅ Botão ficará mais fraco (opacidade reduzida)
    ✅ Você verá "Aguarde..." ou similar

Passo 4: Aguarde (máximo 2 segundos)
    ✅ Será redirecionado para login.html automáticamente
    ✅ A página de login deve aparecer

Passo 5: Tente voltar (Click botão voltar)
    ✅ Pode voltar e ver login, mas não consegue acessar dashboard
    ✅ Precisa fazer login novamente
```

### 2.3 Verificar Console
```javascript
// Durante o logout, veja a aba Console (F12):

// Você deve ver:
✅ "✅ Logout bem-sucedido"

// Você também pode ver:
ℹ️ "POST /api/logout.php" na aba Network

// Se houver erro:
❌ "❌ Erro ao fazer logout: ..."
   → Teste novamente
   → Verifique se api/logout.php está acessível
```

---

## 🌐 PASSO 3: TESTAR RESPONSIVIDADE (2 minutos)

### 3.1 Desktop
```
1. F12 para abrir DevTools
2. Dashboard abre normal
3. Botão "Sair" visível e funcional
4. Logout funciona como esperado
```

### 3.2 Tablet
```
1. F12 → Toggle Device Toolbar (Ctrl+Shift+M)
2. Selecione "iPad" ou tablet genérico
3. Botão "Sair" deve estar visível no menu mobile
4. Clique e teste logout
```

### 3.3 Mobile
```
1. F12 → Toggle Device Toolbar (Ctrl+Shift+M)
2. Selecione "iPhone" ou mobile genérico
3. Abra menu (se necessário)
4. Botão "Sair" deve estar acessível
5. Clique e teste logout
```

---

## 🔍 PASSO 4: VERIFICAR LIMPEZA (2 minutos)

### 4.1 Verificar localStorage
```javascript
// Antes do logout:
localStorage.length
// Exemplo: 5

// Faça logout

// Depois do logout:
localStorage.length
// ✅ Esperado: 0
// Todos os dados foram apagados
```

### 4.2 Verificar sessionStorage
```javascript
// Antes do logout:
sessionStorage.length
// Exemplo: 3

// Faça logout

// Depois do logout:
sessionStorage.length
// ✅ Esperado: 0
// Todos os dados foram apagados
```

### 4.3 Verificar Cookies
```javascript
// Antes do logout:
document.cookie
// Exemplo: "sessionid=abc123; user=xyz;"

// Faça logout

// Depois do logout:
document.cookie
// ✅ Esperado: "" (vazio)
// Todos os cookies foram removidos
```

---

## 🚀 PASSO 5: DEPLOY PARA PRODUÇÃO (3 minutos)

### 5.1 Fazer Backup
```bash
# Windows (PowerShell):
mkdir "C:\backups\asl.erpcondominios.com.br-backup-13_02_2026"

# Copie os áquivos:
copy "frontend/js/config.js" "C:\backups\..."
copy "frontend/login.html" "C:\backups\..."
copy "manifest.json" "C:\backups\..."
copy "frontend/dashboard.html" "C:\backups\..."
```

### 5.2 Upload dos Arquivos
```
Via FTP (FileZilla) ou cPanel:

1. Conecte ao servidor FTP
2. Vá para: /home2/inlaud99/asl.erpcondominios.com.br/

3. Envie estes arquivos (sobrescrevendo):
   ✅ frontend/js/config.js
   ✅ frontend/login.html
   ✅ manifest.json
   ✅ frontend/dashboard.html

4. Verifique integridade:
   - Tamanho dos arquivos deve conferir
   - Permissões: 644 for files, 755 for directories
```

### 5.3 Testar em Produção
```
1. Acesse: https://asl.erpcondominios.com.br/dashboard.html
2. Faça login
3. Repita os testes (Passo 2 e 3)
4. Tudo deve funcionar igual ao teste local
```

### 5.4 Notificar Usuários
```
Envie e-mail para suporte/admin:

---
Assunto: Atualização do Sistema - Botão Sair Implementado

Body:
Foi implementada uma nova funcionalidade de logout seguro.

O botão "Sair" agora está disponível no menu do dashboard.

Novidades:
✅ Confirmação antes de sair
✅ Limpeza completa de dados
✅ Segurança reforçada
✅ Suporte para mobile

Nenhuma ação necessária do usuário.
---
```

---

## ⚠️ PASSO 6: VERIFICAR ERROS (2 minutos)

### 6.1 Erro 404 na Logo
```
Sintoma: Logo não carrega, vejo erro 404

Solução:
1. Verifique: window.APP_BASE_PATH correto?
   window.APP_BASE_PATH
   // Deve estar sem /home2/inlaud99/

2. Verifique: Upload de config.js foi correto?
   grep "window.location.origin" frontend/js/config.js
   // Deve estar lá

3. Se ainda não funcionar:
   - Delete cache: Ctrl+Shift+Delete
   - Recarregue: Ctrl+F5
   - Tente outro navegador
```

### 6.2 Erro ao Clicar "Sair"
```
Sintoma: Clico em sair e nada acontece ou vejo erro

Verificações (F12 Console):
1. Função existe?
   typeof fazerLogout
   ✅ Deve retornar "function"

2. API acessível?
   fetch('../api/logout.php', {method: 'POST'})
   .then(r => console.log(r.status))
   ✅ Deve retornar 200

3. Botão há elemento correto?
   document.getElementById('btn-logout')
   ✅ Deve retornar o elemento

Solução:
- Verifique se dashboard.html foi uploadado corretamente
- Verifique se api/logout.php existe em frontend/../../api/logout.php
```

### 6.3 Erro de Sessão Após Logout
```
Sintoma: Depois que fiz logout, a sessão ainda continua ativa

Verificação:
1. localStorage limpo?
   localStorage.length === 0
   ✅ Deve ser 0

2. Servidor destruiu sessão?
   Acesse: ../api/verificar_sessao.php
   ✅ Deve retornar error/unauthorized

Solução:
- Verifique api/logout.php está completo
- Verifique se $_SESSION = array() está no backend
- Teste fazer logout de novo
```

---

## 📊 PASSO 7: MONITORAR (Contínuo)

### 7.1 Verificar Logs
```bash
# SSH ou Telnet para servidor:

# Ver erro_log:
tail -f error_log

# Procurar por logout:
grep -i "logout" error_log

# Verificar api/logout.php:
grep -A5 "POST /api/logout.php" logs/api_calls.log
```

### 7.2 Testar Regularmente
```
Todos os dias (ou semanalmente):
- [ ] Fazer login
- [ ] Clicar "Sair"
- [ ] Confirmar logout
- [ ] Verificar redirecionamento
- [ ] Testar login novamente

Objetivo: Garantir funcionamento contínuo
```

---

## ✅ CHECKLIST DE VALIDAÇÃO

```
[ ] Passo 1 - Validação Local
    [ ] APP_BASE_PATH correto
    [ ] Logo carrega sem 404
    [ ] Nenhum erro de 404 nos recursos
    [ ] Função fazerLogout() existe

[ ] Passo 2 - Teste do Botão
    [ ] Botão "Sair" visível no dashboard
    [ ] Confirmação aparece ao clicar
    [ ] Logout é executado
    [ ] Redirecionado para login.html
    [ ] Novo login necessário

[ ] Passo 3 - Responsividade
    [ ] Desktop funciona (1920px)
    [ ] Tablet funciona (768px)
    [ ] Mobile funciona (375px)

[ ] Passo 4 - Limpeza
    [ ] localStorage zerado
    [ ] sessionStorage zerado
    [ ] Cookies removidos

[ ] Passo 5 - Deploy
    [ ] Backup realizado
    [ ] Arquivos uploadados
    [ ] Testado em produção
    [ ] Usuários notificados

[ ] Passo 6 - Erros
    [ ] Nenhum 404 encontrado
    [ ] Botão funciona sem erros
    [ ] Sessão realmente encerrada

[ ] Passo 7 - Monitorar
    [ ] Logs verificados
    [ ] Teste diário agendado
```

---

## 📞 SE TIVER DÚVIDAS

### Referências Rápidas
```
Problema URL Duplicada:
  → Ver: ANALISE_LOCALIZACAO_URL_DUPLICADA.md

Como testar:
  → Ver: TESTE_LOGOUT_RAPIDO.md

Detalhes técnicos:
  → Ver: LOGOUT_IMPLEMENTADO.md

Visual do sistema:
  → Ver: LOGOUT_GUIA_VISUAL.md

Resumo geral:
  → Ver: RESUMO_EXECUTIVO_FINAL.md
```

### Contatos
```
Suporte técnico:
  - Verifique os logs em: error_log
  - Verifique console do navegador: F12
  - Procure por: "GET /api/logout.php" ou "POST /api/logout.php"
```

---

## 🎉 RESULTADO ESPERADO

Após completar todos os passos:

```
✅ URL sem duplication
✅ Logo carrega normalmente
✅ PWA funciona em cualquier contexto
✅ Botão "Sair" visível e funcional
✅ Logout executa com segurança
✅ Dados completamente limpos
✅ Sessão encerrada no servidor
✅ Redirecionamento automático
✅ Usuário precisa fazer novo login
✅ Sistema 100% funcionando

🚀 PRONTO PARA PRODUÇÃO!
```

---

**Versão:** 1.0  
**Data:** 13/02/2026  
**Status:** ✅ PRONTO PARA USO

