# Análise Comparativa: Usuários vs Moradores

## 1. Estrutura do Banco de Dados

### Tabela `usuarios`
```sql
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nome` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `senha` varchar(255) NOT NULL,  -- password_hash()
  `funcao` varchar(100) NOT NULL,
  `departamento` varchar(100) DEFAULT NULL,
  `permissao` enum('admin','gerente','operador','visualizador') NOT NULL DEFAULT 'operador',
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `data_criacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)
```

### Tabela `moradores`
```sql
CREATE TABLE `moradores` (
  `id` int(11) NOT NULL,
  `nome` varchar(200) NOT NULL,
  `cpf` varchar(14) NOT NULL,
  `unidade` varchar(50) NOT NULL,
  `email` varchar(200) NOT NULL,
  `senha` varchar(255) NOT NULL,  -- SHA1 (PROBLEMA!)
  `telefone` varchar(20) DEFAULT NULL,
  `celular` varchar(20) DEFAULT NULL,
  `ativo` tinyint(1) DEFAULT '1',
  `data_cadastro` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ultimo_acesso` datetime DEFAULT NULL
)
```

## 2. Diferenças Críticas Identificadas

### 2.1 Criptografia de Senha

**❌ PROBLEMA CRÍTICO: Moradores usa SHA1**

Dados do banco mostram:
```sql
INSERT INTO `moradores` (..., `senha`, ...) VALUES
(..., '7c4a8d09ca3762af61e59520943dc26494f8941b', ...);  -- SHA1 (40 caracteres)
```

**✅ Usuários usa password_hash() corretamente:**
```sql
INSERT INTO `usuarios` (..., `senha`, ...) VALUES
(..., '$2y$10$jIoMsParHs08bj4JzzhVA.GiqemG9czp7n5cft3/75ZzoMQEswSyK', ...);  -- bcrypt
```

**Impacto:**
- SHA1 é considerado **inseguro** para senhas
- Não possui salt automático
- Vulnerável a rainbow tables
- Não recomendado desde 2005

### 2.2 API de Criação

**api_usuarios.php (CORRETO):**
```php
$senha_hash = password_hash($senha, PASSWORD_DEFAULT);
$stmt = $conexao->prepare("INSERT INTO usuarios (..., senha, ...) VALUES (..., ?, ...)");
```

**api_moradores.php (CORRETO):**
```php
$senha_hash = password_hash($senha, PASSWORD_DEFAULT);
$stmt = $conexao->prepare("INSERT INTO moradores (..., senha, ...) VALUES (..., ?, ...)");
```

✅ **Ambas as APIs estão corretas!** O problema está nos dados antigos do banco.

### 2.3 Validação de Login

**Usuários (validar_login.php):**
```php
// Busca usuário por email
$stmt = $conexao->prepare("SELECT id, nome, senha, permissao FROM usuarios WHERE email = ? AND ativo = 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$resultado = $stmt->get_result();

if ($usuario = $resultado->fetch_assoc()) {
    // Verifica senha com password_verify()
    if (password_verify($senha, $usuario['senha'])) {
        // Login bem-sucedido
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_permissao'] = $usuario['permissao'];
    }
}
```

**Moradores (validar_login_morador.php):**
```php
// Busca morador por email
$stmt = $conexao->prepare("SELECT id, nome, cpf, unidade, email, senha FROM moradores WHERE email = ? AND ativo = 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$resultado = $stmt->get_result();

if ($morador = $resultado->fetch_assoc()) {
    // PROBLEMA: Usa SHA1 para comparação
    if (sha1($senha) === $morador['senha']) {
        // Login bem-sucedido
        $_SESSION['morador_id'] = $morador['id'];
        $_SESSION['morador_nome'] = $morador['nome'];
        $_SESSION['morador_cpf'] = $morador['cpf'];
        $_SESSION['morador_unidade'] = $morador['unidade'];
    }
}
```

## 3. Funcionalidades Presentes em Usuários mas Ausentes em Moradores

### 3.1 Campo de Status Ativo/Inativo

**✅ Usuários:**
- Possui badge visual (verde/vermelho)
- Permite ativar/desativar sem excluir
- Filtro de usuários ativos/inativos

**⚠️ Moradores:**
- Campo `ativo` existe no banco
- Não é exibido na interface
- Não há controle visual de status

### 3.2 Campo de Permissão/Nível de Acesso

**✅ Usuários:**
- Campo `permissao` enum('admin','gerente','operador','visualizador')
- Badge colorido por nível
- Controle de acesso por permissão

**❌ Moradores:**
- Não possui campo de permissão
- Todos os moradores têm o mesmo nível de acesso

### 3.3 Proteção contra Exclusão

**✅ Usuários:**
```javascript
${u.id !== 1 ? `<button class="btn-delete" onclick="excluirUsuario(${u.id}, '${u.nome}')">
    <i class="fas fa-trash"></i> Excluir</button>` : ''}
```
- Protege o usuário ID 1 (admin principal) contra exclusão

**❌ Moradores:**
- Não possui proteção similar
- Qualquer morador pode ser excluído

### 3.4 Máscara de Senha ao Editar

**✅ Usuários:**
```javascript
document.getElementById('senha').value = '********';
if (dados.senha === '********') {
    delete dados.senha;  // Não atualiza senha se não foi alterada
}
```

**❌ Moradores:**
- Não possui máscara de senha ao editar
- Exige sempre informar nova senha

## 4. Estrutura de Interface

### 4.1 Formulário

**Usuários:**
- Nome
- Email
- Senha
- Função
- Departamento
- Permissão (select)
- Status Ativo (implícito)

**Moradores:**
- Nome
- CPF (único em moradores)
- Unidade (select com API)
- Email
- Senha
- Confirma Senha
- Telefone
- Celular

### 4.2 Tabela de Listagem

**Usuários:**
| ID | Nome | Email | Função | Departamento | Permissão | Status | Ações |

**Moradores:**
| ID | Nome | CPF | Unidade | Email | Telefone | Celular | Ações |

### 4.3 Sistema de Busca

**✅ Usuários:**
- Busca simples (não implementada visualmente, mas API suporta)

**✅ Moradores:**
- Busca avançada com múltiplos filtros:
  - Unidade
  - Nome
  - Email
  - CPF
- Botões "Buscar" e "Limpar"

## 5. Recomendações de Padronização

### 5.1 CRÍTICO: Migrar Senhas de Moradores

**Problema:** Senhas em SHA1 no banco de dados

**Solução:**
1. Criar script de migração para forçar reset de senhas
2. Atualizar validar_login_morador.php para usar password_verify()
3. Adicionar lógica de migração automática no primeiro login

### 5.2 Adicionar Campo de Status em Moradores

**Implementar:**
- Badge visual de status (Ativo/Inativo)
- Botão para ativar/desativar
- Filtro por status

### 5.3 Adicionar Máscara de Senha ao Editar Morador

**Implementar:**
- Preencher com '********' ao editar
- Não atualizar senha se não foi alterada
- Validar apenas se senha foi modificada

### 5.4 Adicionar Campo de Último Acesso

**Implementar:**
- Exibir data do último acesso na tabela
- Atualizar automaticamente no login

### 5.5 Padronizar Estrutura de Código

**Ambos devem ter:**
- Mesma estrutura de CSS
- Mesmos padrões de função JavaScript
- Mesma estrutura de alertas
- Mesmo padrão de loading

## 6. Plano de Ação

### Fase 1: Correção Crítica de Segurança
1. ✅ Atualizar validar_login_morador.php para usar password_verify()
2. ✅ Adicionar lógica de migração automática de senha
3. ✅ Criar script SQL para resetar senhas antigas

### Fase 2: Padronização de Interface
1. ✅ Adicionar campo de status (Ativo/Inativo) na tabela
2. ✅ Adicionar badge de status
3. ✅ Adicionar máscara de senha ao editar

### Fase 3: Melhorias Adicionais
1. ✅ Exibir último acesso
2. ✅ Adicionar proteção contra exclusão (se necessário)
3. ✅ Padronizar mensagens de erro/sucesso

## 7. Arquivos a Serem Modificados

1. **validar_login_morador.php** - Migrar de SHA1 para password_verify()
2. **moradores.html** - Adicionar campo de status e máscara de senha
3. **api_moradores.php** - Adicionar suporte a atualização de status
4. **Script SQL** - Migração de senhas antigas

## 8. Conclusão

A principal diferença crítica é o uso de **SHA1** nas senhas dos moradores (dados antigos do banco), enquanto as APIs já estão corretas usando **password_hash()**. 

A padronização deve focar em:
1. **Segurança:** Migrar senhas antigas para bcrypt
2. **Consistência:** Adicionar campos de status e último acesso
3. **Usabilidade:** Máscara de senha ao editar
4. **Interface:** Padronizar badges e alertas
