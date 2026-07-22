# 🐛 Debug - Dispositivos Console

## Problemas Relatados

1. ✅ **Layout quebrado** - dispositivos_console.html
2. ❌ **Token não sendo gerado** ao cadastrar dispositivo

---

## 🔍 Análise Realizada

### 1. Estrutura HTML ✅
```html
<!-- CORRETO -->
<div class="main-container">
    <div class="sidebar">...</div>
    <div class="main-content">...</div>
</div>
```

### 2. API de Dispositivos ✅
- ✅ Função `gerarTokenSimples()` existe
- ✅ Função `registrar_log()` existe no config.php
- ✅ Função `retornar_json()` existe no config.php
- ✅ Lógica de cadastro está correta

### 3. JavaScript ✅
- ✅ Envia dados corretamente via fetch
- ✅ Exibe token em alert após cadastro
- ✅ Tratamento de erros implementado

---

## 🧪 Como Testar

### **Opção 1: Página de Teste**

1. Acesse: `https://erp.asserradaliberdade.ong.br/teste_dispositivo.html`
2. Preencha o formulário
3. Clique em "Cadastrar e Testar"
4. Verifique:
   - ✅ Se retorna sucesso
   - ✅ Se o token aparece
   - ✅ Se há mensagens de erro

### **Opção 2: Console do Navegador**

1. Abra `dispositivos_console.html`
2. Pressione `F12` (DevTools)
3. Vá na aba **Console**
4. Tente cadastrar um dispositivo
5. Verifique mensagens de erro

### **Opção 3: Teste Direto da API**

```bash
curl -X POST https://erp.asserradaliberdade.ong.br/api_dispositivos_console.php \
  -H "Content-Type: application/json" \
  -d '{
    "nome_dispositivo": "Teste via CURL",
    "tipo_dispositivo": "tablet",
    "localizacao": "Portaria",
    "responsavel": "Admin"
  }'
```

**Resposta esperada:**
```json
{
  "sucesso": true,
  "mensagem": "Dispositivo cadastrado com sucesso",
  "dados": {
    "id": 1,
    "token_acesso": "ABC123XY",
    "nome_dispositivo": "Teste via CURL"
  }
}
```

---

## 🔧 Possíveis Causas

### **1. Cache do Navegador**

**Sintoma:** Layout ainda quebrado

**Solução:**
```
1. Pressione Ctrl + Shift + Delete
2. Marque "Imagens e arquivos em cache"
3. Clique em "Limpar dados"
4. Recarregue a página com Ctrl + F5
```

### **2. Erro no Banco de Dados**

**Sintoma:** Token não é gerado

**Verificar:**
```sql
-- Verificar se tabela existe
SHOW TABLES LIKE 'dispositivos_console';

-- Verificar estrutura
DESCRIBE dispositivos_console;

-- Verificar se há registros
SELECT * FROM dispositivos_console ORDER BY id DESC LIMIT 5;
```

### **3. Erro de Permissão**

**Sintoma:** Erro 500 ou "Erro ao cadastrar"

**Verificar:**
- Permissões do arquivo `api_dispositivos_console.php`
- Permissões de escrita no banco de dados
- Logs de erro do PHP

### **4. Arquivo não Atualizado no Servidor**

**Sintoma:** Código antigo ainda em execução

**Verificar:**
```bash
# Ver data de modificação
ls -lh dispositivos_console.html
ls -lh api_dispositivos_console.php

# Ver primeiras linhas
head -20 dispositivos_console.html | grep "main-container"
```

---

## 📋 Checklist de Verificação

### **Layout**
- [ ] Limpar cache do navegador
- [ ] Recarregar página com Ctrl + F5
- [ ] Verificar se arquivo foi atualizado no servidor
- [ ] Verificar console do navegador (F12)
- [ ] Testar em navegador diferente

### **Token**
- [ ] Acessar teste_dispositivo.html
- [ ] Verificar resposta da API
- [ ] Verificar console do navegador
- [ ] Verificar logs do PHP
- [ ] Verificar banco de dados
- [ ] Testar API via CURL

---

## 🚀 Soluções Rápidas

### **Forçar Atualização do Cache**

Adicione versão no link do CSS:

```html
<!-- ANTES -->
<link rel="stylesheet" href="assets/css/style.css">

<!-- DEPOIS -->
<link rel="stylesheet" href="assets/css/style.css?v=20241218">
```

### **Adicionar Logs de Debug na API**

Adicione no início do cadastro (linha 65):

```php
// DEBUG
error_log("=== CADASTRO DISPOSITIVO ===");
error_log("Dados recebidos: " . print_r($dados, true));
error_log("Token gerado: " . $token_acesso);
```

Verificar logs:
```bash
tail -f /var/log/php_errors.log
```

### **Testar Geração de Token**

Criar arquivo `teste_token.php`:

```php
<?php
function gerarTokenSimples() {
    $caracteres = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $tamanho = rand(6, 8);
    $token = '';
    
    for ($i = 0; $i < $tamanho; $i++) {
        $token .= $caracteres[rand(0, strlen($caracteres) - 1)];
    }
    
    return $token;
}

echo "Tokens gerados:\n";
for ($i = 1; $i <= 10; $i++) {
    echo "$i. " . gerarTokenSimples() . "\n";
}
?>
```

Executar:
```bash
php teste_token.php
```

---

## 📞 Suporte

Se os problemas persistirem:

1. **Enviar logs:**
   - Console do navegador (F12 → Console)
   - Resposta da API (teste_dispositivo.html)
   - Logs do PHP

2. **Enviar informações:**
   - Navegador e versão
   - Sistema operacional
   - Mensagens de erro exatas

3. **Enviar screenshots:**
   - Tela com erro
   - Console do navegador
   - Resposta da API

---

## ✅ Arquivos Criados para Debug

1. **teste_dispositivo.html** - Página de teste da API
2. **DEBUG_DISPOSITIVOS.md** - Este arquivo

---

**Última atualização:** 18 de Dezembro de 2024
