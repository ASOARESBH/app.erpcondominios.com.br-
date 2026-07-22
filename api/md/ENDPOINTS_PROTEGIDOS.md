# Endpoints Protegidos com Autenticação

## Resumo das Alterações

Todos os endpoints da API foram atualizados para incluir verificação de autenticação de sessão usando a função `verificarAutenticacao()` do arquivo `auth_helper.php`.

## Endpoints Atualizados ✅

### 1. **api_acessos_visitantes.php**
- **Autenticação:** Requerida para todas as operações
- **Permissão mínima:** operador
- **Operações:**
  - GET: Listar acessos (operador)
  - POST: Criar acesso (operador)
  - PUT: Atualizar acesso (operador)
  - DELETE: Deletar acesso (admin)

### 2. **api_visitantes.php**
- **Autenticação:** Requerida para todas as operações
- **Permissão mínima:** operador
- **Operações:**
  - GET: Listar visitantes (operador)
  - POST: Criar visitante (operador)
  - PUT: Atualizar visitante (operador)
  - DELETE: Deletar visitante (admin)

### 3. **api_moradores.php**
- **Autenticação:** Requerida para todas as operações
- **Permissão mínima:** operador para leitura, admin para escrita
- **Operações:**
  - GET: Listar moradores (operador)
  - POST/PUT/DELETE: Apenas admin

### 4. **api_usuarios.php**
- **Autenticação:** Requerida
- **Permissão mínima:** admin (para todas as operações)
- **Operações:**
  - GET: Listar usuários (admin)
  - POST: Criar usuário (admin)
  - PUT: Atualizar usuário (admin)
  - DELETE: Deletar usuário (admin)

### 5. **api_notificacoes.php**
- **Autenticação:** Requerida para todas as operações
- **Permissão mínima:** operador para leitura, admin para escrita
- **Operações:**
  - GET: Listar notificações (operador)
  - POST/PUT/DELETE: Apenas admin

### 6. **api_checklist.php**
- **Autenticação:** Requerida para todas as operações
- **Permissão mínima:** operador
- **Operações:**
  - Leitura: operador
  - Escrita (criar, atualizar, fechar, deletar): operador

### 7. **api_estoque.php**
- **Autenticação:** Requerida para todas as operações
- **Permissão mínima:** operador
- **Operações:**
  - GET: Listar categorias/produtos (operador)
  - POST/PUT/DELETE: Operador

### 8. **api_pedidos.php**
- **Autenticação:** Requerida para todas as operações
- **Permissão mínima:** operador
- **Operações:**
  - GET: Listar pedidos (operador)
  - POST/PUT/DELETE: Operador

### 9. **api_contas_pagar.php**
- **Autenticação:** Requerida para todas as operações
- **Permissão mínima:** operador para leitura, gerente para escrita
- **Operações:**
  - GET: Listar contas (operador)
  - POST/PUT/DELETE: Gerente

### 10. **api_contas_receber.php**
- **Autenticação:** Requerida para todas as operações
- **Permissão mínima:** operador para leitura, gerente para escrita
- **Operações:**
  - GET: Listar contas (operador)
  - POST/PUT/DELETE: Gerente

### 11. **api_face_id.php**
- **Autenticação:** Requerida para todas as operações
- **Permissão mínima:** operador
- **Operações:**
  - GET: Listar descritores (operador)
  - POST/PUT/DELETE: Operador

### 12. **api_abastecimento.php**
- **Autenticação:** Requerida para todas as operações
- **Permissão mínima:** operador
- **Operações:**
  - GET: Listar veículos/abastecimentos (operador)
  - POST/PUT/DELETE: Operador

## Hierarquia de Permissões

1. **visualizador** - Apenas leitura
2. **operador** - Leitura e escrita básica
3. **gerente** - Leitura, escrita e aprovações
4. **admin** - Acesso total

## CORS Atualizado

Todos os endpoints agora usam:
- **Origin:** http://erp.asserradaliberdade.ong.br
- **Credentials:** true
- **Methods:** GET, POST, PUT, DELETE, OPTIONS
- **Headers:** Content-Type, Authorization

## Tratamento de Erros

### Erro 401 - Não Autenticado
```json
{
  "sucesso": false,
  "mensagem": "Autenticação necessária. Faça login novamente.",
  "codigo": "AUTH_REQUIRED"
}
```

### Erro 403 - Permissão Insuficiente
```json
{
  "sucesso": false,
  "mensagem": "Permissão insuficiente para realizar esta ação.",
  "codigo": "PERMISSION_DENIED",
  "permissao_necessaria": "admin",
  "permissao_usuario": "operador"
}
```

## Como Usar o auth_helper.php

### Verificação Básica
```php
require_once 'auth_helper.php';

// Verificar se usuário está autenticado
verificarAutenticacao(true, 'operador');
```

### Verificação com Permissão Específica
```php
// Requer permissão de admin
verificarAutenticacao(true, 'admin');
```

### Verificar Permissão Adicional
```php
// Após autenticação inicial
verificarPermissao('admin');
```

### Obter Dados do Usuário
```php
$usuario = obterUsuarioAutenticado();
if ($usuario) {
    echo "Bem-vindo, " . $usuario['nome'];
}
```

## Próximos Passos

1. Testar todos os endpoints com diferentes níveis de permissão
2. Atualizar os endpoints restantes com autenticação
3. Implementar rate limiting em endpoints críticos
4. Adicionar logging detalhado de acessos
5. Implementar renovação automática de tokens
