# 🔧 Resolver Erro da API de Dispositivos

## 🐛 Erro Identificado

```
Failed to execute 'json' on 'Response': Unexpected end of JSON input
```

**Causa:** A API `api_dispositivos_console.php` não está retornando JSON válido porque a tabela `dispositivos_console` **não existe** no banco de dados.

---

## ✅ Solução Rápida (3 Passos)

### **Passo 1: Acessar Diagnóstico**

```
https://erp.asserradaliberdade.ong.br/diagnostico_api.php
```

Este script irá:
- ✅ Verificar conexão com banco
- ✅ Verificar se tabela existe
- ✅ Mostrar script SQL para criar
- ✅ Testar API automaticamente

---

### **Passo 2: Criar Tabela no Banco**

#### **Método 1: Via phpMyAdmin (RECOMENDADO)**

1. Acesse o cPanel
2. Clique em **"phpMyAdmin"**
3. Selecione o banco: `inlaud99_erpserra`
4. Clique na aba **"SQL"**
5. Cole o script abaixo:

```sql
CREATE TABLE IF NOT EXISTS `dispositivos_console` (
  `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
  `nome_dispositivo` VARCHAR(200) NOT NULL COMMENT 'Nome identificador do dispositivo',
  `token_acesso` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Token simples de acesso (6-8 caracteres)',
  `tipo_dispositivo` ENUM('tablet', 'smartphone', 'outro') DEFAULT 'tablet',
  `localizacao` VARCHAR(200) NULL COMMENT 'Localização física do dispositivo',
  `responsavel` VARCHAR(200) NULL COMMENT 'Nome do responsável pelo dispositivo',
  `user_agent` TEXT NULL COMMENT 'User agent do navegador',
  `ip_cadastro` VARCHAR(45) NULL COMMENT 'IP no momento do cadastro',
  `ip_ultimo_acesso` VARCHAR(45) NULL COMMENT 'IP do último acesso',
  `data_ultimo_acesso` DATETIME NULL COMMENT 'Data e hora do último acesso',
  `total_acessos` INT(11) DEFAULT 0 COMMENT 'Total de acessos realizados',
  `ativo` TINYINT(1) DEFAULT 1 COMMENT '1=Ativo, 0=Inativo',
  `observacao` TEXT NULL,
  `data_cadastro` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  INDEX `idx_token_acesso` (`token_acesso`),
  INDEX `idx_ativo` (`ativo`),
  INDEX `idx_data_ultimo_acesso` (`data_ultimo_acesso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Dispositivos autorizados para acessar o console';

-- Inserir dispositivo padrão para testes
INSERT INTO `dispositivos_console` 
(nome_dispositivo, token_acesso, tipo_dispositivo, localizacao, responsavel, ativo)
VALUES 
('Tablet Portaria Principal', 'PORT001', 'tablet', 'Portaria Principal', 'Equipe de Segurança', 1);
```

6. Clique em **"Executar"**
7. Aguarde mensagem de sucesso

#### **Método 2: Via Upload de Arquivo SQL**

1. Acesse o cPanel → phpMyAdmin
2. Selecione o banco: `inlaud99_erpserra`
3. Clique na aba **"Importar"**
4. Clique em **"Escolher arquivo"**
5. Selecione: `create_dispositivos_console.sql`
6. Clique em **"Executar"**

---

### **Passo 3: Testar Novamente**

1. **Limpe o cache do navegador**
   ```
   Ctrl + Shift + Delete
   ```

2. **Acesse o teste novamente:**
   ```
   https://erp.asserradaliberdade.ong.br/teste_dispositivo.html
   ```

3. **Preencha o formulário:**
   - Nome: Tablet Teste
   - Tipo: Tablet
   - Localização: Portaria Principal
   - Responsável: Equipe de Segurança
   - Observação: Dispositivo de teste

4. **Clique em "Cadastrar e Testar"**

5. **Resultado esperado:**
   ```json
   {
     "sucesso": true,
     "mensagem": "Dispositivo cadastrado com sucesso!",
     "token": "ABC123",
     "dispositivo": {
       "id": 2,
       "nome": "Tablet Teste",
       "tipo": "tablet",
       "localizacao": "Portaria Principal"
     }
   }
   ```

---

## 📋 Checklist de Verificação

Após executar os passos acima, verifique:

- [ ] Tabela `dispositivos_console` criada no banco
- [ ] Registro padrão inserido (PORT001)
- [ ] API retorna JSON válido
- [ ] Token é gerado ao cadastrar dispositivo
- [ ] Token aparece em alert na tela
- [ ] Dispositivo aparece na lista

---

## 🔍 Diagnóstico Detalhado

Se o problema persistir, acesse:

```
https://erp.asserradaliberdade.ong.br/diagnostico_api.php
```

Este script mostrará:
- ✅ Status da conexão com banco
- ✅ Se a tabela existe
- ✅ Estrutura da tabela
- ✅ Quantidade de registros
- ✅ Teste da API em tempo real
- ✅ Mensagens de erro detalhadas

---

## 🎯 Arquivos Necessários

Certifique-se de que estes arquivos existem no servidor:

1. ✅ `config.php` - Configuração do banco
2. ✅ `api_dispositivos_console.php` - API de dispositivos
3. ✅ `dispositivos_console.html` - Página de gerenciamento
4. ✅ `teste_dispositivo.html` - Página de teste
5. ✅ `diagnostico_api.php` - Script de diagnóstico (NOVO)
6. ✅ `create_dispositivos_console.sql` - Script SQL

---

## 📊 Estrutura da Tabela

A tabela `dispositivos_console` tem os seguintes campos:

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | INT(11) | ID único (auto increment) |
| `nome_dispositivo` | VARCHAR(200) | Nome do dispositivo |
| `token_acesso` | VARCHAR(100) | Token de acesso (único) |
| `tipo_dispositivo` | ENUM | tablet, smartphone, outro |
| `localizacao` | VARCHAR(200) | Localização física |
| `responsavel` | VARCHAR(200) | Nome do responsável |
| `user_agent` | TEXT | User agent do navegador |
| `ip_cadastro` | VARCHAR(45) | IP no cadastro |
| `ip_ultimo_acesso` | VARCHAR(45) | IP do último acesso |
| `data_ultimo_acesso` | DATETIME | Data do último acesso |
| `total_acessos` | INT(11) | Total de acessos |
| `ativo` | TINYINT(1) | 1=Ativo, 0=Inativo |
| `observacao` | TEXT | Observações |
| `data_cadastro` | TIMESTAMP | Data de cadastro |
| `data_atualizacao` | TIMESTAMP | Data de atualização |

---

## ⚠️ Problemas Comuns

### **Problema 1: "Table doesn't exist"**

**Solução:** Execute o script SQL fornecido no Passo 2.

### **Problema 2: "Duplicate entry for key 'token_acesso'"**

**Solução:** O token já existe. Isso é normal se você já cadastrou um dispositivo com esse token.

### **Problema 3: "Access denied"**

**Solução:** Verifique as credenciais no `config.php`:
- DB_HOST
- DB_NAME
- DB_USER
- DB_PASS

### **Problema 4: "JSON parse error"**

**Solução:** 
1. Acesse `diagnostico_api.php`
2. Veja a resposta exata da API
3. Verifique se há erros de PHP

---

## 📞 Suporte

Se o problema persistir:

1. Acesse `diagnostico_api.php`
2. Tire screenshot da página completa
3. Envie para análise
4. Inclua mensagens de erro do console (F12)

---

## ✅ Resultado Final Esperado

Após seguir todos os passos:

✅ Tabela criada no banco de dados  
✅ Dispositivo padrão inserido (PORT001)  
✅ API retornando JSON válido  
✅ Token sendo gerado automaticamente  
✅ Dispositivos aparecendo na lista  
✅ Console de acesso funcionando  
✅ Autenticação de dispositivos ativa  

---

**Data:** 26 de Dezembro de 2024  
**Versão:** 1.0  
**Status:** ✅ Pronto para uso
