# 📤 Guia de Upload para o Servidor

## ⚠️ PROBLEMA IDENTIFICADO

Os arquivos foram atualizados no **GitHub**, mas **NÃO no servidor de hospedagem**.

**Resultado:**
- ❌ Layout continua quebrado
- ❌ teste_dispositivo.html não existe (404)
- ❌ Correções não aplicadas

**Solução:** Fazer upload manual dos arquivos via FTP ou cPanel.

---

## 📦 Pacote Criado

**Arquivo:** `correcao_layout_completa.zip`  
**Tamanho:** 132 KB  
**Arquivos:** 32 arquivos HTML e documentação  

---

## 🚀 MÉTODO 1: Upload via cPanel (RECOMENDADO)

### **Passo 1: Fazer Login no cPanel**

```
URL: https://asserradaliberdade.ong.br:2083
Usuário: [seu_usuario_cpanel]
Senha: [sua_senha_cpanel]
```

### **Passo 2: Acessar Gerenciador de Arquivos**

1. No cPanel, procure por **"Gerenciador de Arquivos"** ou **"File Manager"**
2. Clique para abrir
3. Navegue até a pasta do site:
   - Geralmente: `public_html/` ou `public_html/erp/`
   - Ou onde está o arquivo `index.html` do ERP

### **Passo 3: Fazer Backup (IMPORTANTE)**

Antes de substituir, faça backup:

1. Selecione todos os arquivos `.html` atuais
2. Clique em **"Compress"** ou **"Compactar"**
3. Escolha formato **ZIP**
4. Nome: `backup_antes_correcao_YYYYMMDD.zip`
5. Clique em **"Compress Files"**
6. Baixe o backup para seu computador

### **Passo 4: Fazer Upload do Pacote**

1. Clique em **"Upload"** no topo
2. Clique em **"Select File"** ou arraste o arquivo
3. Selecione: `correcao_layout_completa.zip`
4. Aguarde o upload (barra de progresso)
5. Feche a janela de upload

### **Passo 5: Extrair Arquivos**

1. Volte ao Gerenciador de Arquivos
2. Localize `correcao_layout_completa.zip`
3. Clique com botão direito → **"Extract"** ou **"Extrair"**
4. Confirme a extração
5. Os arquivos serão extraídos na mesma pasta

### **Passo 6: Excluir o ZIP**

1. Selecione `correcao_layout_completa.zip`
2. Clique em **"Delete"** ou **"Excluir"**
3. Confirme

### **Passo 7: Verificar Permissões**

1. Selecione todos os arquivos `.html` extraídos
2. Clique em **"Change Permissions"** ou **"Alterar Permissões"**
3. Defina para **644** (rw-r--r--)
4. Aplique

---

## 🚀 MÉTODO 2: Upload via FTP (FileZilla)

### **Passo 1: Baixar FileZilla**

Se não tiver instalado:
```
https://filezilla-project.org/download.php?type=client
```

### **Passo 2: Conectar ao Servidor**

```
Host: ftp.asserradaliberdade.ong.br
Usuário: [seu_usuario_ftp]
Senha: [sua_senha_ftp]
Porta: 21
```

Clique em **"Quickconnect"** ou **"Conexão Rápida"**

### **Passo 3: Navegar até a Pasta**

No painel direito (servidor):
1. Navegue até `public_html/` ou pasta do ERP
2. Localize onde estão os arquivos `.html` atuais

### **Passo 4: Fazer Backup**

1. Selecione todos os arquivos `.html`
2. Arraste para uma pasta local no seu computador
3. Crie uma pasta: `backup_antes_correcao_YYYYMMDD`

### **Passo 5: Fazer Upload**

No painel esquerdo (local):
1. Navegue até onde está `correcao_layout_completa.zip`
2. Extraia o ZIP localmente primeiro
3. Selecione todos os 32 arquivos extraídos
4. Arraste para o painel direito (servidor)
5. Confirme substituição quando perguntado

### **Passo 6: Verificar Upload**

1. Verifique se todos os 32 arquivos foram enviados
2. Compare tamanhos e datas
3. Feche o FileZilla

---

## 🚀 MÉTODO 3: Upload via SSH (Avançado)

Se você tem acesso SSH:

```bash
# 1. Conectar ao servidor
ssh usuario@asserradaliberdade.ong.br

# 2. Navegar até a pasta do site
cd public_html/

# 3. Fazer backup
tar -czf backup_antes_correcao_$(date +%Y%m%d).tar.gz *.html

# 4. Baixar o ZIP do GitHub
wget https://github.com/andreprogramadorbh-ai/erpserra/raw/main/correcao_layout_completa.zip

# 5. Extrair
unzip -o correcao_layout_completa.zip

# 6. Ajustar permissões
chmod 644 *.html

# 7. Limpar
rm correcao_layout_completa.zip

# 8. Verificar
ls -lh *.html | head -10
```

---

## ✅ Verificar se Funcionou

### **1. Limpar Cache do Navegador**

```
Ctrl + Shift + Delete
→ Marcar "Imagens e arquivos em cache"
→ Limpar dados
→ Recarregar com Ctrl + F5
```

### **2. Testar Layout**

```
Acesse: https://erp.asserradaliberdade.ong.br/dispositivos_console.html

✅ Sidebar deve estar à esquerda
✅ Logo deve estar em tamanho normal
✅ Conteúdo não deve estar sobreposto
✅ Fundo roxo não deve cobrir tudo
```

### **3. Testar Página de Debug**

```
Acesse: https://erp.asserradaliberdade.ong.br/teste_dispositivo.html

✅ Deve carregar (não 404)
✅ Deve mostrar formulário
✅ Deve funcionar o teste
```

### **4. Testar Cadastro de Dispositivo**

```
1. Acesse: dispositivos_console.html
2. Clique em "Novo Dispositivo"
3. Preencha os campos
4. Clique em "Salvar"
5. Deve aparecer alert com TOKEN
```

---

## 📋 Checklist Pós-Upload

- [ ] Backup dos arquivos antigos feito
- [ ] Upload do pacote realizado
- [ ] Arquivos extraídos
- [ ] Permissões ajustadas (644)
- [ ] Cache do navegador limpo
- [ ] Layout testado e funcionando
- [ ] teste_dispositivo.html acessível
- [ ] Token sendo gerado corretamente

---

## 🐛 Problemas Comuns

### **Problema 1: "Permission Denied" ao extrair**

**Causa:** Sem permissão de escrita

**Solução:**
1. Verifique permissões da pasta
2. Deve ser 755 (rwxr-xr-x)
3. Ou extraia localmente e faça upload via FTP

### **Problema 2: Arquivos não aparecem**

**Causa:** Extraído em pasta errada

**Solução:**
1. Verifique se está na pasta correta
2. Deve ser onde está o `index.html` do ERP
3. Geralmente `public_html/` ou `public_html/erp/`

### **Problema 3: Layout ainda quebrado**

**Causa:** Cache do navegador ou CDN

**Solução:**
1. Limpar cache do navegador (Ctrl + Shift + Delete)
2. Testar em navegador privado (Ctrl + Shift + N)
3. Testar em dispositivo diferente
4. Aguardar 5-10 minutos (cache do servidor)

### **Problema 4: 404 em teste_dispositivo.html**

**Causa:** Arquivo não foi extraído

**Solução:**
1. Verificar se arquivo está na pasta
2. Verificar permissões (deve ser 644)
3. Verificar nome do arquivo (case-sensitive)

---

## 📞 Suporte

Se precisar de ajuda:

1. **Tire screenshots:**
   - Tela do cPanel/FTP
   - Lista de arquivos no servidor
   - Mensagens de erro

2. **Envie informações:**
   - Método usado (cPanel/FTP/SSH)
   - Mensagens de erro exatas
   - Resultado dos testes

3. **Verifique:**
   - Credenciais de acesso
   - Permissões da pasta
   - Espaço em disco disponível

---

## 📊 Resumo

| Item | Status |
|------|--------|
| **Pacote criado** | ✅ correcao_layout_completa.zip (132 KB) |
| **Arquivos incluídos** | ✅ 32 arquivos |
| **Documentação** | ✅ Guias completos |
| **Backup recomendado** | ⚠️ Fazer antes de upload |
| **Upload necessário** | ❌ PENDENTE |
| **Testes necessários** | ❌ PENDENTE |

---

## 🎯 Próximo Passo

**FAÇA O UPLOAD AGORA:**

1. ✅ Escolha um método (cPanel recomendado)
2. ✅ Faça backup dos arquivos atuais
3. ✅ Faça upload do pacote
4. ✅ Extraia os arquivos
5. ✅ Teste o resultado
6. ✅ Reporte o sucesso ou problemas

---

**Última atualização:** 26 de Dezembro de 2024  
**Versão:** 1.0
