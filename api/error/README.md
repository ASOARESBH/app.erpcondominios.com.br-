# 🐛 Diretório de Debug - API

Este diretório contém ferramentas de debug e diagnóstico para a API do sistema ERP.

## ⚠️ ATENÇÃO

**Este diretório é apenas para desenvolvimento e testes. NÃO deve estar acessível em produção!**

## 📁 Arquivos

### debug_dependentes.php

Ferramenta de debug para testar e diagnosticar problemas com o módulo de dependentes.

#### Ações Disponíveis

1. **testar** - Testa criação de um dependente
   ```
   GET /api/error/debug_dependentes.php?acao=testar
   ```

2. **verificar_tabela** - Verifica se a tabela existe e sua estrutura
   ```
   GET /api/error/debug_dependentes.php?acao=verificar_tabela
   ```

3. **listar_todos** - Lista todos os dependentes (últimos 10)
   ```
   GET /api/error/debug_dependentes.php?acao=listar_todos
   ```

4. **verificar_conexao** - Verifica conexão com banco de dados
   ```
   GET /api/error/debug_dependentes.php?acao=verificar_conexao
   ```

5. **testar_insert_direto** - Testa INSERT direto no banco
   ```
   GET /api/error/debug_dependentes.php?acao=testar_insert_direto
   ```

6. **limpar_testes** - Remove registros de teste
   ```
   GET /api/error/debug_dependentes.php?acao=limpar_testes
   ```

#### Arquivo de Log

Todos os testes são registrados em:
```
api/error/debug_dependentes.log
```

## 🔒 Segurança

Para proteger este diretório em produção, adicione ao `.htaccess`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^/api/error/
    RewriteRule ^(.*)$ - [F,L]
</IfModule>
```

Ou crie um arquivo `.htaccess` neste diretório:

```apache
Order Deny,Allow
Deny from all
Allow from 127.0.0.1
Allow from ::1
