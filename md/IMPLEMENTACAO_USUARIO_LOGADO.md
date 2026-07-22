# Implementação - Usuário Logado e Tempo de Sessão

## 📋 Resumo Executivo

Este documento descreve a implementação de uma solução completa para exibir o usuário logado e o tempo de sessão em tempo real, seguindo o padrão arquitetural **MVC (Model-View-Controller)** com boas práticas de programação.

## 🎯 Objetivos Alcançados

✅ **Model (SessionModel.php)** - Camada de dados para gerenciar sessões  
✅ **Controller (SessionController.php)** - Lógica de negócio centralizada  
✅ **API (api_usuario_logado.php)** - Endpoint unificado para requisições  
✅ **Frontend (session-display.js)** - Interface em tempo real  
✅ **Banco de Dados** - Tabela e views para auditoria de sessões  

## 📁 Arquivos Implementados

### Backend (PHP)

#### 1. **api/models/SessionModel.php**
Classe responsável pela interação com o banco de dados.

**Métodos principais:**
- `criarTabelaSessoes()` - Criar tabela de sessões se não existir
- `registrarSessao()` - Registrar nova sessão no banco
- `obterSessaoAtiva()` - Buscar sessão ativa com dados do usuário
- `atualizarUltimaAtividade()` - Atualizar timestamp de atividade
- `calcularTempoRestante()` - Calcular tempo restante da sessão
- `encerrarSessao()` - Marcar sessão como inativa
- `limparSessoesExpiradas()` - Remover sessões expiradas
- `obterSessoesAtivasUsuario()` - Listar todas as sessões ativas do usuário

#### 2. **api/controllers/SessionController.php**
Classe controladora que processa requisições e coordena a lógica.

**Métodos principais:**
- `obterDadosUsuarioLogado()` - Retorna dados do usuário + tempo de sessão
- `registrarNovaSessionao()` - Registra nova sessão no banco
- `renovarSessao()` - Estende o tempo de sessão
- `encerrarSessao()` - Faz logout e limpa dados
- `obterSessoesAtivasUsuario()` - Lista sessões ativas
- `limparSessoesExpiradas()` - Limpeza de manutenção

#### 3. **api/api_usuario_logado.php**
API REST unificada para gerenciar sessões.

**Endpoints:**
- GET `/api/api_usuario_logado.php` - Obter dados do usuário logado
- POST `/api/api_usuario_logado.php?acao=renovar` - Renovar sessão
- POST `/api/api_usuario_logado.php?acao=logout` - Fazer logout
- POST `/api/api_usuario_logado.php?acao=sessoes` - Listar sessões ativas
- POST `/api/api_usuario_logado.php?acao=limpar` - Limpar sessões expiradas (admin only)

### Frontend (JavaScript)

#### 4. **js/session-display.js**
Script para exibir informações do usuário e tempo de sessão em tempo real.

**Funcionalidades:**
- ✅ Exibir nome, função e cargo do usuário
- ✅ Mostrar tempo de sessão em tempo real (HH:MM:SS)
- ✅ Alertas visuais quando tempo está acabando
- ✅ Auto-renovação de sessão
- ✅ Renovação manual com um clique
- ✅ Avisos com cores indicativas

### Banco de Dados (SQL)

#### 5. **sql/criar_tabela_sessoes_usuarios.sql**

**Tabela `sessoes_usuarios`:**
- Rastreia todas as sessões ativas
- Registra IP e User Agent
- Controla expiração automática
- Auditoria completa

**Views Criadas:**
1. `v_sessoes_ativas` - Sessões ativas com tempo restante
2. `v_historico_sessoes` - Histórico completo de sessões

## 🚀 Como Usar

### 1. Executar Script SQL

```bash
mysql -u usuario -p banco < sql/criar_tabela_sessoes_usuarios.sql
```

### 2. Incluir Script JavaScript

Adicione ao `<head>` ou antes do `</body>` em todas as páginas:

```html
<script src="js/session-display.js"></script>
```

### 3. Registrar Sessão no Login

Modifique `api/validar_login.php`:

```php
require_once 'controllers/SessionController.php';
$controller = new SessionController($conexao);
$controller->registrarNovaSessionao($usuario['id']);
```

## 🏗️ Arquitetura MVC

```
Frontend (session-display.js)
           ↕
API REST (api_usuario_logado.php)
           ↕
Controller (SessionController.php)
           ↕
Model (SessionModel.php)
           ↕
Database (sessoes_usuarios)
```

## 🔒 Segurança

- ✅ Prepared Statements - Proteção contra SQL Injection
- ✅ Session Regeneration - Regenera ID após login
- ✅ HTTPOnly Cookies - Protege contra XSS
- ✅ CORS Validado - Apenas origem autorizada
- ✅ Timeout de Sessão - 2 horas por padrão
- ✅ Auditoria Completa - Todos os eventos registrados

## 📊 Monitoramento

### Verificar Sessões Ativas

```sql
SELECT * FROM v_sessoes_ativas;
```

### Histórico de Sessões

```sql
SELECT * FROM v_historico_sessoes ORDER BY data_login DESC;
```

### Limpar Sessões Expiradas

```sql
CALL limpar_sessoes_expiradas();
```

## ✅ Checklist de Implementação

- [ ] Executar script SQL para criar tabelas
- [ ] Incluir `session-display.js` em todas as páginas
- [ ] Modificar `validar_login.php` para registrar sessão
- [ ] Testar obtenção de dados do usuário
- [ ] Testar renovação de sessão
- [ ] Testar logout
- [ ] Verificar avisos de tempo
- [ ] Verificar auto-renovação
- [ ] Testar em diferentes navegadores
- [ ] Verificar logs de auditoria

---

**Versão:** 1.0.0  
**Data:** 18 de Janeiro de 2026  
**Autor:** Sistema de Gestão ERP
