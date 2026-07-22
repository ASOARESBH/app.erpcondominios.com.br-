# 🏢 ERP Associação Serra da Liberdade

**Sistema de Gestão Completo para Condomínios e Associações**

[![Status](https://img.shields.io/badge/status-ativo-success)](https://github.com/andreprogramadorbh-ai/erpcondominiosASL)
[![Versão](https://img.shields.io/badge/vers%C3%A3o-2.0-blue)](https://github.com/andreprogramadorbh-ai/erpcondominiosASL)
[![Licença](https://img.shields.io/badge/licen%C3%A7a-MIT-green)](LICENSE)

---

## 📋 Sobre o Projeto

O **ERP Associação Serra da Liberdade** é um sistema completo de gestão desenvolvido especificamente para condomínios e associações, oferecendo controle total sobre:

- 🚪 **Portaria e Controle de Acesso**
- 👥 **Gestão de Moradores e Dependentes**
- 🚗 **Controle de Veículos**
- 👤 **Gestão de Visitantes**
- 📦 **Controle de Estoque**
- 💰 **Contas a Pagar e Receber**
- 📊 **Dashboards e Relatórios**
- 🔐 **Sistema de Segurança Robusto**

---

## 🚀 Tecnologias Utilizadas

### Backend
- **PHP 7.4+** - Linguagem principal
- **MariaDB/MySQL** - Banco de dados
- **PDO** - Camada de abstração de banco de dados
- **PHPMailer** - Envio de emails

### Frontend
- **HTML5** - Estrutura
- **CSS3** - Estilização
- **JavaScript (ES6+)** - Interatividade
- **Font Awesome** - Ícones

### Arquitetura
- **MVC (Model-View-Controller)** - Padrão arquitetural
- **RESTful API** - Comunicação cliente-servidor
- **JWT (JSON Web Tokens)** - Autenticação
- **Rate Limiting** - Proteção contra ataques

---

## 📁 Estrutura do Projeto

```
erpcondominiosASL/
├── api/                          # APIs RESTful
│   ├── controllers/              # Controllers MVC
│   ├── models/                   # Models MVC
│   ├── services/                 # Serviços auxiliares
│   ├── logs/                     # Logs de erro e auditoria
│   ├── api_dependentes.php       # API de dependentes
│   ├── api_moradores.php         # API de moradores
│   ├── api_visitantes.php        # API de visitantes
│   ├── api_veiculos.php          # API de veículos
│   ├── error_logger.php          # Sistema de logging
│   ├── rate_limiter.php          # Rate limiting
│   └── jwt_handler.php           # Autenticação JWT
│
├── frontend/                     # Interface do usuário
│   ├── js/                       # JavaScript
│   │   ├── dependentes_melhorado.js
│   │   └── integracao_veiculos_dependentes.js
│   ├── html_snippets/            # Componentes HTML
│   ├── moradores.html            # Gestão de moradores
│   ├── veiculos.html             # Gestão de veículos
│   └── visitantes.html           # Gestão de visitantes
│
├── assets/                       # Recursos estáticos
│   ├── css/                      # Folhas de estilo
│   ├── images/                   # Imagens
│   └── js/                       # JavaScript global
│
├── includes/                     # Arquivos de inclusão
├── sql/                          # Scripts SQL
├── uploads/                      # Arquivos enviados
└── README.md                     # Este arquivo
```

---

## 🔧 Instalação

### Pré-requisitos

- PHP 7.4 ou superior
- MariaDB 10.3+ ou MySQL 5.7+
- Servidor web (Apache/Nginx)
- Composer (opcional)

### Passo 1: Clonar Repositório

```bash
git clone https://github.com/andreprogramadorbh-ai/erpcondominiosASL.git
cd erpcondominiosASL
```

### Passo 2: Configurar Banco de Dados

```bash
# Criar banco de dados
mysql -u root -p -e "CREATE DATABASE erpserra CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Importar estrutura
mysql -u root -p erpserra < sql/estrutura_completa.sql
```

### Passo 3: Configurar Conexão

Edite `api/config.php`:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'erpserra');
define('DB_USER', 'seu_usuario');
define('DB_PASS', 'sua_senha');
define('DB_CHARSET', 'utf8mb4');
?>
```

### Passo 4: Criar Diretórios de Logs

```bash
mkdir -p api/logs
chmod 755 api/logs
```

### Passo 5: Configurar Permissões

```bash
chmod 755 api/
chmod 755 frontend/
chmod 755 uploads/
chmod 644 api/*.php
chmod 644 frontend/*.html
```

### Passo 6: Acessar Sistema

Abra no navegador:
```
http://seu-dominio.com/login.html
```

**Credenciais padrão:**
- Usuário: `admin`
- Senha: `admin123`

⚠️ **Altere a senha após primeiro login!**

---

## 📚 Funcionalidades Principais

### 1. Gestão de Moradores e Dependentes

- ✅ Cadastro completo de moradores
- ✅ Gestão de dependentes vinculados
- ✅ Validação de CPF
- ✅ Controle de status (ativo/inativo)
- ✅ Histórico de alterações

### 2. Controle de Veículos

- ✅ Cadastro de veículos
- ✅ Vinculação a moradores ou dependentes
- ✅ Controle de entrada/saída
- ✅ Histórico de acessos

### 3. Gestão de Visitantes

- ✅ Cadastro de visitantes
- ✅ Validação de conflito com dependentes
- ✅ Controle de acesso temporário
- ✅ Notificações aos moradores

### 4. Sistema de Segurança

- ✅ Autenticação JWT
- ✅ Rate limiting
- ✅ Logging completo
- ✅ Proteção contra SQL Injection
- ✅ Validação de entrada
- ✅ CORS configurável

### 5. Dashboards e Relatórios

- ✅ Dashboard de acessos
- ✅ Dashboard de consumo de água
- ✅ Relatórios personalizados
- ✅ Exportação em PDF/Excel

---

## 🔐 Segurança

O sistema implementa múltiplas camadas de segurança:

### Autenticação
- JWT (JSON Web Tokens)
- Sessões PHP seguras
- Bcrypt para hash de senhas

### Proteção de API
- Rate limiting (3 tentativas por minuto)
- Verificação de autenticação em todos os endpoints
- Validação de permissões por nível de usuário

### Validações
- Validação de CPF (algoritmo módulo 11)
- Validação de email
- Sanitização de entrada
- Prepared statements (PDO)

### Logging
- Registro de todas as operações
- Logs de erro detalhados
- Auditoria de acessos
- Arquivo: `api/logs/erros.log`

---

## 📖 Documentação da API

### Endpoints Principais

#### Dependentes

```http
GET    /api/api_dependentes.php?acao=listar&morador_id={id}
POST   /api/api_dependentes.php?acao=criar
PUT    /api/api_dependentes.php?acao=atualizar&id={id}
DELETE /api/api_dependentes.php?acao=deletar&id={id}
POST   /api/api_dependentes.php?acao=ativar&id={id}
POST   /api/api_dependentes.php?acao=inativar&id={id}
```

#### Moradores

```http
GET    /api/api_moradores.php?acao=listar
POST   /api/api_moradores.php?acao=criar
PUT    /api/api_moradores.php?acao=atualizar&id={id}
DELETE /api/api_moradores.php?acao=deletar&id={id}
```

#### Veículos

```http
GET    /api/api_veiculos.php?acao=listar
POST   /api/api_veiculos.php?acao=criar
PUT    /api/api_veiculos.php?acao=atualizar&id={id}
DELETE /api/api_veiculos.php?acao=deletar&id={id}
```

### Autenticação

Todas as requisições devem incluir:

```http
Cookie: PHPSESSID=seu_session_id
```

Ou para JWT:

```http
Authorization: Bearer seu_token_jwt
```

---

## 🧪 Testes

### Teste de Funcionalidade

```bash
# Teste de criação de dependente
curl -X POST "http://seu-dominio.com/api/api_dependentes.php?acao=criar" \
  -H "Content-Type: application/json" \
  -b "PHPSESSID=seu_session_id" \
  -d '{
    "morador_id": 1,
    "nome_completo": "João Silva",
    "cpf": "123.456.789-10",
    "parentesco": "Filho(a)"
  }'
```

### Teste de Segurança

```bash
# Executar suite de testes
php api/test_seguranca.php
```

**Resultado esperado:** 21/21 testes passando (100%)

---

## 📊 Monitoramento

### Logs de Erro

Localização: `api/logs/erros.log`

```bash
# Ver últimas 50 linhas
tail -50 api/logs/erros.log

# Monitorar em tempo real
tail -f api/logs/erros.log
```

### Estrutura do Log

```
[2026-01-25 19:30:45] [API_ERROR] [IP: 192.168.1.100] [USER: 5] [POST /api/api_dependentes.php?acao=criar]
Mensagem: Dependente criado com sucesso
Contexto: {
  "acao": "criar",
  "dados_enviados": {...}
}
```

---

## 🤝 Contribuindo

Contribuições são bem-vindas! Para contribuir:

1. Faça um fork do projeto
2. Crie uma branch para sua feature (`git checkout -b feature/MinhaFeature`)
3. Commit suas mudanças (`git commit -m 'Adiciona MinhaFeature'`)
4. Push para a branch (`git push origin feature/MinhaFeature`)
5. Abra um Pull Request

---

## 📝 Changelog

### Versão 2.0 (25/01/2026)

#### Novas Funcionalidades
- ✅ Sistema de Dependentes completo
- ✅ Integração de dependentes com veículos
- ✅ Validação de conflito visitante/dependente
- ✅ Popups visuais de feedback
- ✅ Sistema de logging robusto
- ✅ Rate limiting em autenticação
- ✅ Autenticação JWT

#### Melhorias de Segurança
- ✅ Proteção de 59 endpoints com autenticação
- ✅ Validações completas de entrada
- ✅ Tratamento robusto de erros
- ✅ Logging de erros do cliente
- ✅ Retry automático em falhas

#### Correções
- ✅ Falta de feedback ao usuário
- ✅ Erros silenciosos
- ✅ Timeouts indefinidos
- ✅ Validações inadequadas

### Versão 1.0 (01/01/2026)
- 🎉 Lançamento inicial

---

## 📄 Licença

Este projeto está sob a licença MIT. Veja o arquivo [LICENSE](LICENSE) para mais detalhes.

---

## 👥 Autores

- **Manus AI** - *Desenvolvimento e Implementação* - [Manus](https://manus.im)
- **Associação Serra da Liberdade** - *Requisitos e Testes*

---

## 📞 Suporte

Para suporte técnico:

- 📧 Email: suporte@asserradaliberdade.ong.br
- 🌐 Website: https://asserradaliberdade.ong.br
- 📱 Telefone: (31) XXXX-XXXX

---

## 🙏 Agradecimentos

- Comunidade PHP
- Contribuidores do projeto
- Associação Serra da Liberdade
- Manus AI Platform

---

**Desenvolvido com ❤️ pela Associação Serra da Liberdade**
