# 🚀 Guia de Instalação Rápida

## Sistema de Controle de Acesso para Portaria

---

## 📋 Pré-requisitos

- Hospedagem HostGator com PHP 7.4+ e MySQL
- Acesso ao cPanel
- Banco de dados: `inlaud99_erpserra`
- Usuário: `inlaud99_admin`
- Senha: `Admin259087@`

---

## 🔧 Passo a Passo

### 1️⃣ Upload dos Arquivos

1. Acesse o **cPanel** da sua hospedagem HostGator
2. Abra o **File Manager** (Gerenciador de Arquivos)
3. Navegue até a pasta `public_html` (ou pasta do seu domínio)
4. Faça upload do arquivo `sistema_acesso_portaria.zip`
5. Clique com o botão direito no arquivo ZIP e selecione **Extract** (Extrair)
6. Os arquivos serão extraídos para a pasta `sistema_acesso_portaria/`

**OU** faça upload via FTP usando FileZilla ou similar.

---

### 2️⃣ Criar o Banco de Dados

#### Opção A: Banco já existe

Se o banco `inlaud99_erpserra` já existe:

1. Acesse **phpMyAdmin** no cPanel
2. Selecione o banco `inlaud99_erpserra` no menu lateral
3. Clique na aba **SQL** no topo
4. Abra o arquivo `database.sql` em um editor de texto
5. Copie todo o conteúdo
6. Cole na área de texto do phpMyAdmin
7. Clique em **Executar** (Go)
8. Aguarde a mensagem de sucesso

#### Opção B: Criar novo banco

Se precisar criar o banco:

1. No cPanel, acesse **MySQL Databases**
2. Crie o banco: `inlaud99_erpserra`
3. Crie o usuário: `inlaud99_admin` com senha: `Admin259087@`
4. Adicione o usuário ao banco com **ALL PRIVILEGES**
5. Siga os passos da Opção A para importar o SQL

---

### 3️⃣ Configurar Permissões

No File Manager, verifique as permissões dos arquivos:

- **Arquivos PHP**: 644 ou 755
- **Pasta assets**: 755
- **Arquivo .htaccess**: 644

Para alterar permissões:
1. Clique com botão direito no arquivo/pasta
2. Selecione **Change Permissions**
3. Configure conforme acima

---

### 4️⃣ Testar a Instalação

1. Acesse no navegador:
   ```
   https://seudominio.com.br/sistema_acesso_portaria/teste_api.php
   ```

2. Verifique se todos os testes passam:
   - ✅ Conexão com banco de dados
   - ✅ Tabelas criadas
   - ✅ Inserção de dados de teste

3. Se algum teste falhar, verifique:
   - Credenciais do banco em `config.php`
   - Permissões dos arquivos
   - Logs de erro do PHP no cPanel

---

### 5️⃣ Acessar o Sistema

Após os testes, acesse:

```
https://seudominio.com.br/sistema_acesso_portaria/dashboard.html
```

**Menu do Sistema:**
- 📊 **Dashboard** - Visão geral
- 👥 **Moradores** - Cadastro de moradores
- 🚗 **Veículos** - Cadastro de veículos e TAGs
- 📝 **Registro Manual** - Visitantes e prestadores
- 🚪 **Controle de Acesso** - Tela de portaria

---

### 6️⃣ Configurar RFID (Opcional)

Se você tem o equipamento **RFID Control iD iDUHF**:

1. Acesse a interface web do leitor RFID
2. Vá em **Configurações** → **Webhooks**
3. Configure a URL:
   ```
   https://seudominio.com.br/sistema_acesso_portaria/api_rfid.php?acao=webhook
   ```
4. Método: **POST**
5. Formato: **JSON**
6. Salve as configurações

---

## 🎯 Primeiro Uso

### Cadastrar Primeiro Morador

1. Acesse **Moradores**
2. Preencha o formulário:
   - Nome completo
   - CPF (será validado como único)
   - Unidade
   - Email
   - Senha (será criptografada)
   - Telefone e celular (opcional)
3. Clique em **Salvar Morador**

### Cadastrar Primeiro Veículo

1. Acesse **Veículos**
2. Preencha o formulário:
   - Placa (ABC1D23 ou ABC-1234)
   - Modelo
   - Cor (opcional)
   - **TAG RFID** (deve ser única!)
   - Selecione o morador
3. Clique em **Salvar Veículo**

### Testar Acesso

1. Acesse **Controle de Acesso**
2. Digite a TAG cadastrada
3. Clique em **Verificar Acesso**
4. Se tudo estiver correto: ✅ **ACESSO LIBERADO**

---

## 🔒 Segurança

### Proteger Arquivos Sensíveis

O arquivo `.htaccess` já protege:
- `config.php` - Não acessível via navegador
- `database.sql` - Não acessível via navegador

### Alterar Credenciais

Para maior segurança, altere as credenciais do banco:

1. No cPanel, altere a senha do usuário MySQL
2. Edite o arquivo `config.php`:
   ```php
   define('DB_PASS', 'SUA_NOVA_SENHA_AQUI');
   ```

---

## 📊 Estrutura de URLs

- **Dashboard**: `/sistema_acesso_portaria/dashboard.html`
- **Moradores**: `/sistema_acesso_portaria/moradores.html`
- **Veículos**: `/sistema_acesso_portaria/veiculos.html`
- **Registro**: `/sistema_acesso_portaria/registro.html`
- **Acesso**: `/sistema_acesso_portaria/acesso.html`
- **API Moradores**: `/sistema_acesso_portaria/api_moradores.php`
- **API Veículos**: `/sistema_acesso_portaria/api_veiculos.php`
- **API Registros**: `/sistema_acesso_portaria/api_registros.php`
- **API RFID**: `/sistema_acesso_portaria/api_rfid.php`

---

## 🆘 Solução de Problemas

### Erro 500 - Internal Server Error

- Verifique permissões dos arquivos PHP (644 ou 755)
- Verifique sintaxe do `.htaccess`
- Consulte logs de erro no cPanel

### Erro de Conexão com Banco

- Verifique credenciais em `config.php`
- Verifique se o banco existe no phpMyAdmin
- Verifique se o usuário tem permissões

### TAG não funciona

- Verifique se a TAG está cadastrada
- Verifique se o veículo está **ativo**
- Verifique se o morador está **ativo**
- Consulte a tabela `logs_sistema` para detalhes

### Tela de acesso não atualiza

- Verifique se JavaScript está habilitado
- Limpe cache do navegador
- Verifique console do navegador (F12)

---

## 📞 Suporte

Para problemas técnicos:

1. Acesse `teste_api.php` para diagnóstico
2. Verifique logs em `logs_sistema` no banco
3. Consulte o arquivo `README.md` completo
4. Verifique logs de erro do PHP no cPanel

---

## ✅ Checklist de Instalação

- [ ] Arquivos extraídos no servidor
- [ ] Banco de dados criado
- [ ] Script SQL executado com sucesso
- [ ] Permissões configuradas
- [ ] Teste de API executado com sucesso
- [ ] Dashboard acessível
- [ ] Primeiro morador cadastrado
- [ ] Primeiro veículo cadastrado
- [ ] Teste de acesso realizado
- [ ] RFID configurado (se aplicável)

---

## 🎉 Sistema Pronto!

Após seguir todos os passos, o sistema estará 100% funcional.

**Desenvolvido para Condomínio Serra da Liberdade**

---

## 📝 Observações Importantes

- Faça **backup regular** do banco de dados
- Mantenha o PHP atualizado
- Monitore os logs do sistema
- Teste regularmente a conexão com RFID
- Documente alterações personalizadas

---

**Versão:** 1.0  
**Data:** Outubro 2025  
**Compatível com:** HostGator, PHP 7.4+, MySQL 5.7+

