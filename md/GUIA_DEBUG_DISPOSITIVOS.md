# 🐛 Guia de Debug - Dispositivos Console

## 🎯 Objetivo

Este guia ajuda a identificar e resolver problemas no módulo de dispositivos do console.

---

## 🔍 Como Usar o Console de Debug

### **Passo 1: Abrir Console do Navegador**

```
Pressione F12 ou:
- Chrome/Edge: Ctrl + Shift + J (Windows) / Cmd + Option + J (Mac)
- Firefox: Ctrl + Shift + K (Windows) / Cmd + Option + K (Mac)
```

### **Passo 2: Ir para Aba "Console"**

Você verá mensagens de log em tempo real.

---

## 📊 Logs Implementados

Todos os logs começam com um emoji para facilitar identificação:

| Emoji | Tipo | Descrição |
|-------|------|-----------|
| 🔵 | INFO | Informação de fluxo normal |
| ✅ | SUCCESS | Operação bem-sucedida |
| ❌ | ERROR | Erro capturado |
| ⚠️ | WARNING | Aviso importante |

---

## 🧪 Teste de Salvamento

### **Fluxo Normal (Sucesso)**

Ao clicar em "Salvar", você deve ver:

```
🔵 [DEBUG] Iniciando salvamento de dispositivo
🔵 [DEBUG] ID do dispositivo: NOVO
🔵 [DEBUG] Dados coletados: {nome_dispositivo: "teste", tipo_dispositivo: "tablet", ...}
🔵 [DEBUG] URL: api_dispositivos_console.php
🔵 [DEBUG] Método: POST
🔵 [DEBUG] Enviando requisição...
🔵 [DEBUG] Body: {"nome_dispositivo":"teste",...}
🔵 [DEBUG] Resposta recebida: 200 OK
✅ [DEBUG] Dados parseados: {sucesso: true, mensagem: "...", dados: {...}}
```

### **Fluxo com Erro**

Se houver erro, você verá:

```
🔵 [DEBUG] Iniciando salvamento de dispositivo
...
❌ [DEBUG] Erro capturado: TypeError: Failed to fetch
❌ [DEBUG] Tipo de erro: TypeError
❌ [DEBUG] Mensagem: Failed to fetch
❌ [DEBUG] Stack trace: ...
```

---

## 🔧 Problemas Comuns e Soluções

### **Problema 1: "Carregando..." Infinito**

**Sintoma:** Modal fica com "Carregando..." e nunca fecha.

**Causa Provável:** API não está respondendo ou retornando erro.

**Como Verificar:**
1. Abra o console (F12)
2. Tente salvar novamente
3. Veja a última mensagem de log

**Soluções:**

#### **Se aparecer: "❌ [DEBUG] Erro capturado: TypeError: Failed to fetch"**

**Causa:** API não está acessível ou CORS bloqueado.

**Solução:**
```
1. Verifique se api_dispositivos_console.php existe
2. Teste diretamente: https://erp.asserradaliberdade.ong.br/api_dispositivos_console.php
3. Deve retornar JSON, não 404
```

#### **Se aparecer: "❌ [DEBUG] Resposta não OK: 500"**

**Causa:** Erro no PHP da API.

**Solução:**
```
1. Acesse diagnostico_api.php
2. Veja o erro exato
3. Verifique se tabela dispositivos_console existe
```

#### **Se aparecer: "❌ [DEBUG] Dados parseados: {sucesso: false, mensagem: '...'}"**

**Causa:** Validação falhou ou erro de banco.

**Solução:**
```
1. Leia a mensagem de erro
2. Corrija o dado inválido
3. Tente novamente
```

---

### **Problema 2: Layout Quebrado**

**Sintoma:** Logo gigante, sidebar e conteúdo sobrepostos.

**Causa:** Arquivo CSS não carregou ou estrutura HTML incorreta.

**Como Verificar:**
1. Abra o console (F12)
2. Vá na aba "Network"
3. Recarregue a página (Ctrl + R)
4. Procure por `style.css`
5. Veja se status é 200 (OK) ou 404 (Not Found)

**Soluções:**

#### **Se style.css retorna 404:**

```
1. Verifique se arquivo existe: assets/css/style.css
2. Faça upload do arquivo se não existir
3. Verifique permissões (644)
```

#### **Se style.css retorna 200 mas layout está quebrado:**

```
1. Limpe o cache do navegador:
   Ctrl + Shift + Delete
   
2. Marque "Imagens e arquivos em cache"

3. Limpe e recarregue:
   Ctrl + F5
```

#### **Se ainda não funcionar:**

```
1. Verifique se arquivo HTML tem:
   <div class="main-container">
   
2. Se não tiver, faça upload da versão corrigida

3. Arquivo correto está em:
   https://github.com/andreprogramadorbh-ai/erpserra
```

---

### **Problema 3: Token Não Aparece**

**Sintoma:** Dispositivo é salvo mas token não aparece em alert.

**Causa:** API não está retornando o token.

**Como Verificar:**
1. Abra o console (F12)
2. Salve um dispositivo
3. Procure por: `✅ [DEBUG] Dados parseados:`
4. Veja se tem `token_acesso` no objeto

**Solução:**

Se não tiver `token_acesso`:
```
1. Verifique se api_dispositivos_console.php tem função gerarTokenSimples()
2. Verifique se está gerando e retornando o token
3. Faça upload da versão corrigida da API
```

---

## 📋 Checklist de Verificação

Antes de reportar problema, verifique:

- [ ] Console do navegador aberto (F12)
- [ ] Aba "Console" selecionada
- [ ] Tentou salvar dispositivo
- [ ] Viu mensagens de log
- [ ] Copiou mensagens de erro
- [ ] Verificou aba "Network"
- [ ] Limpou cache do navegador
- [ ] Testou em navegador diferente

---

## 🎯 Testes Recomendados

### **Teste 1: Verificar Estrutura HTML**

```javascript
// Cole no console:
console.log('main-container existe?', document.querySelector('.main-container') !== null);
console.log('sidebar existe?', document.querySelector('.sidebar') !== null);
console.log('main-content existe?', document.querySelector('.main-content') !== null);
```

**Resultado esperado:**
```
main-container existe? true
sidebar existe? true
main-content existe? true
```

### **Teste 2: Verificar CSS Carregado**

```javascript
// Cole no console:
const styles = Array.from(document.styleSheets);
const styleCSS = styles.find(s => s.href && s.href.includes('style.css'));
console.log('style.css carregado?', styleCSS !== undefined);
if (styleCSS) {
    console.log('URL:', styleCSS.href);
    console.log('Regras:', styleCSS.cssRules.length);
}
```

**Resultado esperado:**
```
style.css carregado? true
URL: https://erp.asserradaliberdade.ong.br/assets/css/style.css?v=20241226
Regras: 300+ (número de regras CSS)
```

### **Teste 3: Testar API Diretamente**

```javascript
// Cole no console:
fetch('api_dispositivos_console.php')
    .then(r => r.json())
    .then(d => console.log('API responde:', d))
    .catch(e => console.error('API erro:', e));
```

**Resultado esperado:**
```
API responde: {sucesso: true, dados: [...]}
```

---

## 📞 Reportar Problema

Se o problema persistir, envie:

1. **Screenshot do console** (F12 → Console)
2. **Screenshot da aba Network** (F12 → Network)
3. **Descrição do que acontece**
4. **Passos para reproduzir**

---

## ✅ Arquivos Atualizados

Versão com logs de debug:

- ✅ dispositivos_console.html (v20241226)
- ✅ Logs em todas as operações
- ✅ Tratamento de erros melhorado
- ✅ Mensagens mais descritivas

---

**Data:** 26 de Dezembro de 2024  
**Versão:** 2.0  
**Status:** ✅ Logs Implementados
