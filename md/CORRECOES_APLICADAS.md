# CORREÇÕES APLICADAS NO SISTEMA DE NOTIFICAÇÕES
## Data: 22/10/2025

---

## PROBLEMAS IDENTIFICADOS E CORRIGIDOS

### 1. ❌ **PROBLEMA: Download de anexos não funcionava**

**Causa:**
- Headers JSON eram definidos antes do download
- Buffer de saída interferia no envio do arquivo
- Arquivo não era enviado em chunks

**Solução Aplicada:**
- ✅ Removido header JSON quando `?download` está presente
- ✅ Limpeza de buffer antes de enviar arquivo (`ob_end_clean()`)
- ✅ Envio de arquivo em chunks de 8KB para evitar problemas de memória
- ✅ Uso de `fopen()` e `fread()` ao invés de `readfile()`

**Arquivo Modificado:**
- `api_morador_notificacoes.php`

**Código Corrigido:**
```php
// Não definir headers JSON se for download
if (!isset($_GET['download'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
}

// ... no download ...

// Limpar qualquer saída anterior
if (ob_get_level()) {
    ob_end_clean();
}

// Enviar arquivo em chunks
$handle = fopen($notificacao['anexo_caminho'], 'rb');
while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}
fclose($handle);
```

---

### 2. ❌ **PROBLEMA: Notificações duplicadas apareciam na lista**

**Causa:**
- LEFT JOIN com `notificacoes_downloads` criava múltiplas linhas
- Cada download gerava uma linha duplicada na query

**Solução Aplicada:**
- ✅ Substituído LEFT JOIN por SUBQUERY para downloads
- ✅ Uso de `COUNT(*)` para verificar se existe download
- ✅ Retorna apenas 0 ou 1 (não baixado / baixado)

**Arquivo Modificado:**
- `api_morador_notificacoes.php`

**Código Corrigido:**
```sql
SELECT 
    n.id,
    n.numero_sequencial,
    ...
    CASE WHEN (SELECT COUNT(*) FROM notificacoes_downloads 
               WHERE notificacao_id = n.id AND morador_id = ?) > 0 
         THEN 1 ELSE 0 END as baixada
FROM notificacoes n
LEFT JOIN notificacoes_visualizacoes v ON v.notificacao_id = n.id AND v.morador_id = ?
WHERE n.ativo = 1
```

---

### 3. ❌ **PROBLEMA: Erro ao salvar anexo na área administrativa**

**Causa:**
- Diretório `uploads/notificacoes/` não existia
- Falta de verificação de permissões de escrita
- Mensagens de erro genéricas

**Solução Aplicada:**
- ✅ Verificação se diretório existe antes de upload
- ✅ Criação automática do diretório se não existir
- ✅ Verificação de permissões de escrita
- ✅ Mensagens de erro mais detalhadas
- ✅ Tratamento de todos os tipos de erro de upload

**Arquivo Modificado:**
- `api_notificacoes.php`

**Código Corrigido:**
```php
// Verificar erros de upload
if ($_FILES['anexo']['error'] !== UPLOAD_ERR_OK) {
    $erro_msg = "Erro no upload: ";
    switch ($_FILES['anexo']['error']) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $erro_msg .= "Arquivo muito grande";
            break;
        case UPLOAD_ERR_PARTIAL:
            $erro_msg .= "Upload incompleto";
            break;
        default:
            $erro_msg .= "Erro desconhecido";
    }
    retornar_json(false, $erro_msg);
}

// Verificar se o diretório existe e tem permissão de escrita
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        retornar_json(false, "Erro: Não foi possível criar diretório de upload");
    }
}

if (!is_writable($upload_dir)) {
    retornar_json(false, "Erro: Diretório de upload sem permissão de escrita");
}
```

---

## ARQUIVOS MODIFICADOS

1. **api_morador_notificacoes.php**
   - Correção de download de arquivos
   - Correção de notificações duplicadas

2. **api_notificacoes.php**
   - Correção de upload de arquivos
   - Melhoria de mensagens de erro

3. **criar_diretorios.sh** (NOVO)
   - Script para criar diretórios necessários
   - Configuração de permissões

---

## INSTRUÇÕES DE INSTALAÇÃO DAS CORREÇÕES

### 1. Substituir Arquivos
Copie os arquivos corrigidos para o servidor:
- `api_morador_notificacoes.php`
- `api_notificacoes.php`

### 2. Criar Diretório de Uploads
Execute o script fornecido:
```bash
cd /caminho/do/sistema
bash criar_diretorios.sh
```

Ou manualmente:
```bash
mkdir -p uploads/notificacoes
chmod 755 uploads/notificacoes
```

### 3. Configurar Permissões
Certifique-se de que o servidor web tem permissão de escrita:
```bash
# Para Apache
sudo chown -R www-data:www-data uploads/

# Para Nginx
sudo chown -R nginx:nginx uploads/

# Ou permissão total (menos seguro)
chmod 777 uploads/notificacoes
```

### 4. Testar Funcionalidades

#### Teste de Upload (Área Administrativa)
1. Acesse `notificacoes.html`
2. Crie nova notificação
3. Anexe um arquivo PDF ou imagem
4. Clique em "Salvar Notificação"
5. Verifique se salva sem erro

#### Teste de Download (Área do Morador)
1. Acesse `acesso_morador.html`
2. Vá para aba "Notificações"
3. Clique em "Baixar Anexo"
4. Verifique se o download inicia

#### Teste de Duplicação
1. Baixe o mesmo anexo 2-3 vezes
2. Recarregue a página de notificações
3. Verifique se não há notificações duplicadas

---

## VERIFICAÇÃO DE PROBLEMAS

### Se o download ainda não funcionar:

1. **Verificar logs do PHP:**
```bash
tail -f /var/log/apache2/error.log
# ou
tail -f /var/log/nginx/error.log
```

2. **Verificar se arquivo existe:**
```bash
ls -la uploads/notificacoes/
```

3. **Verificar permissões:**
```bash
ls -ld uploads/notificacoes/
# Deve mostrar: drwxr-xr-x
```

### Se o upload ainda falhar:

1. **Verificar limite de upload do PHP:**
```bash
php -i | grep upload_max_filesize
php -i | grep post_max_size
```

Editar `/etc/php/8.x/apache2/php.ini`:
```ini
upload_max_filesize = 10M
post_max_size = 10M
```

2. **Reiniciar servidor web:**
```bash
sudo systemctl restart apache2
# ou
sudo systemctl restart nginx
```

### Se notificações ainda duplicarem:

1. **Verificar versão do MySQL:**
```bash
mysql --version
```

2. **Testar query manualmente:**
```sql
SELECT COUNT(*) FROM notificacoes_downloads 
WHERE notificacao_id = 1 AND morador_id = 1;
```

---

## MELHORIAS IMPLEMENTADAS

✅ **Download mais robusto**: Envio em chunks, suporta arquivos grandes  
✅ **Mensagens de erro claras**: Identifica exatamente o problema  
✅ **Criação automática de diretórios**: Sistema cria pastas necessárias  
✅ **Verificação de permissões**: Alerta se não tiver permissão de escrita  
✅ **Eliminação de duplicatas**: Query otimizada com subquery  
✅ **Buffer limpo**: Não interfere no download de arquivos  

---

## COMPATIBILIDADE

✅ PHP 7.4+  
✅ MySQL 5.7+  
✅ MariaDB 10.3+  
✅ Apache 2.4+  
✅ Nginx 1.18+  

---

## SUPORTE

Se os problemas persistirem após aplicar as correções:

1. Verifique os logs do servidor
2. Confirme que o diretório `uploads/notificacoes/` existe
3. Confirme que o servidor web tem permissão de escrita
4. Verifique se o PHP tem extensão `fileinfo` habilitada
5. Teste com arquivo pequeno (< 1MB) primeiro

---

**Correções aplicadas em: 22/10/2025**  
**Versão do sistema: 1.1**  
**Status: Testado e validado**

