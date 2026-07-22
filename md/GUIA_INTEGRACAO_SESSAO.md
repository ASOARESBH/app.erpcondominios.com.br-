# Guia de Integração - Sistema de Sessão

## 🔧 Passos de Integração

### Passo 1: Criar Tabela no Banco de Dados

Execute o script SQL no phpMyAdmin ou via CLI:

```bash
mysql -h localhost -u inlaud99_admin -p inlaud99_erpserra < sql/criar_tabela_sessoes_usuarios.sql
```

Ou copie e execute em phpMyAdmin:
```sql
-- Arquivo: sql/criar_tabela_sessoes_usuarios.sql
```

### Passo 2: Modificar api/validar_login.php

Após a linha onde a sessão é criada (linha ~123), adicione:

```php
// Registrar sessão no banco de dados
try {
    require_once 'controllers/SessionController.php';
    $controller = new SessionController($conexao);
    $controller->registrarNovaSessionao($usuario['id']);
} catch (Exception $e) {
    error_log('Erro ao registrar sessão: ' . $e->getMessage());
    // Continuar mesmo se falhar
}
```

### Passo 3: Incluir Script JavaScript em Todas as Páginas

Adicione em TODAS as páginas HTML (dashboard, moradores, etc):

**Opção A: No `<head>`**
```html
<head>
    <!-- ... outros scripts ... -->
    <script src="js/session-display.js"></script>
</head>
```

**Opção B: Antes de `</body>`**
```html
<body>
    <!-- ... conteúdo ... -->
    <script src="js/session-display.js"></script>
</body>
```

### Passo 4: Atualizar api/logout.php

Modifique para usar o controller:

```php
<?php
// Headers para API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://erp.asserradaliberdade.ong.br');
header('Access-Control-Allow-Credentials: true');

// Iniciar sessão
session_start();

// Incluir configurações
require_once 'config.php';
require_once 'controllers/SessionController.php';

try {
    $conexao = conectar_banco();
    $controller = new SessionController($conexao);
    $resultado = $controller->encerrarSessao();
    
    http_response_code($resultado['sucesso'] ? 200 : 400);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
    
    fechar_conexao($conexao);
} catch (Exception $e) {
    error_log('Erro em logout.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao fazer logout'
    ], JSON_UNESCAPED_UNICODE);
}
?>
```

### Passo 5: Testar a Implementação

#### 5.1 Verificar Tabela Criada

```sql
SELECT * FROM sessoes_usuarios LIMIT 1;
```

#### 5.2 Testar API

```bash
# Em um navegador ou com curl
curl -X GET http://erp.asserradaliberdade.ong.br/api/api_usuario_logado.php \
  -H "Cookie: PHPSESSID=seu_session_id" \
  -H "Content-Type: application/json"
```

#### 5.3 Verificar Console do Navegador

1. Abra a página do dashboard
2. Pressione F12 (Developer Tools)
3. Vá para a aba "Console"
4. Procure por mensagens como:
   - "🔧 Session Display inicializado"
   - "✅ Session Display pronto"

#### 5.4 Verificar Exibição do Usuário

1. Faça login
2. Procure no menu lateral por:
   - Avatar com inicial do nome
   - Nome do usuário
   - Função/cargo
   - Tempo de sessão em tempo real

## 📋 Checklist de Integração

### Banco de Dados
- [ ] Tabela `sessoes_usuarios` criada
- [ ] Views `v_sessoes_ativas` e `v_historico_sessoes` criadas
- [ ] Procedure `limpar_sessoes_expiradas` criada
- [ ] Triggers criados

### Backend
- [ ] Arquivo `api/models/SessionModel.php` copiado
- [ ] Arquivo `api/controllers/SessionController.php` copiado
- [ ] Arquivo `api/api_usuario_logado.php` copiado
- [ ] `api/validar_login.php` modificado
- [ ] `api/logout.php` atualizado

### Frontend
- [ ] Arquivo `js/session-display.js` copiado
- [ ] Script incluído em todas as páginas HTML
- [ ] Testado em diferentes navegadores

### Testes
- [ ] Login funciona e registra sessão
- [ ] Usuário aparece no menu
- [ ] Tempo de sessão atualiza em tempo real
- [ ] Renovação de sessão funciona
- [ ] Logout funciona corretamente
- [ ] Avisos aparecem quando tempo está acabando
- [ ] Auto-renovação funciona

## 🔍 Verificação de Funcionamento

### 1. Verificar Logs

```sql
-- Ver logs de nova sessão
SELECT * FROM logs_sistema WHERE tipo = 'nova_sessao' ORDER BY data DESC LIMIT 10;

-- Ver logs de logout
SELECT * FROM logs_sistema WHERE tipo = 'logout' ORDER BY data DESC LIMIT 10;

-- Ver logs de limpeza
SELECT * FROM logs_sistema WHERE tipo = 'limpeza_sessoes' ORDER BY data DESC LIMIT 10;
```

### 2. Verificar Sessões Ativas

```sql
-- Ver todas as sessões ativas
SELECT * FROM v_sessoes_ativas;

-- Ver sessões de um usuário específico
SELECT * FROM v_sessoes_ativas WHERE usuario_id = 1;

-- Ver tempo restante formatado
SELECT usuario_nome, tempo_restante_formatado, ultima_atividade 
FROM v_sessoes_ativas;
```

### 3. Verificar no Navegador

**Console (F12 > Console):**
```javascript
// Verificar se script foi carregado
typeof SessionDisplay // deve retornar 'object' ou 'function'

// Testar API manualmente
fetch('../api/api_usuario_logado.php', {
    method: 'GET',
    credentials: 'include'
})
.then(r => r.json())
.then(d => console.log(d));
```

**Network (F12 > Network):**
- Procure por requisições para `api_usuario_logado.php`
- Verifique se retorna status 200
- Verifique se resposta é JSON válido

## ⚠️ Possíveis Problemas e Soluções

### Problema: Script não aparece no menu

**Solução:**
1. Verificar se `session-display.js` está incluído
2. Verificar console (F12) para erros
3. Verificar se sidebar tem classe `.sidebar` ou atributo `[data-sidebar]`
4. Adicionar classe manualmente se necessário

### Problema: Tempo não atualiza

**Solução:**
1. Verificar se JavaScript está habilitado
2. Verificar se há erros CORS (F12 > Console)
3. Verificar se API está respondendo
4. Verificar se sessão PHP está ativa

### Problema: Sessão expira muito rápido

**Solução:**
1. Verificar `session.gc_maxlifetime` em `config.php`
2. Verificar `duracao_segundos` em `SessionModel.php`
3. Verificar se há múltiplas renovações conflitantes
4. Aumentar valor se necessário

### Problema: Erro "Tabela não existe"

**Solução:**
1. Executar script SQL novamente
2. Verificar se banco de dados está correto
3. Verificar se usuário tem permissão
4. Verificar se não há erro de sintaxe SQL

### Problema: Erro de permissão negada

**Solução:**
1. Verificar permissões de arquivo (755 ou 777)
2. Verificar permissões do banco de dados
3. Verificar se usuário MySQL tem permissão
4. Executar: `chmod -R 755 api/`

## 🔐 Considerações de Segurança

1. **Sempre use HTTPS** em produção
2. **Não exponha dados sensíveis** em logs públicos
3. **Limpe sessões expiradas** regularmente
4. **Monitore tentativas de acesso** não autorizado
5. **Atualize permissões** conforme necessário

## 📞 Suporte

Se encontrar problemas:

1. Verifique os logs:
   - `/api/logs/` (logs da aplicação)
   - `logs_sistema` (banco de dados)
   - Console do navegador (F12)

2. Verifique a documentação:
   - `md/IMPLEMENTACAO_USUARIO_LOGADO.md`
   - `md/GUIA_INTEGRACAO_SESSAO.md`

3. Teste a API manualmente:
   ```bash
   curl -X GET http://seu-dominio/api/api_usuario_logado.php \
     -H "Cookie: PHPSESSID=seu_session_id"
   ```

## ✅ Conclusão

Após seguir todos os passos, você terá:

✅ Sistema completo de gerenciamento de sessão  
✅ Exibição em tempo real do usuário logado  
✅ Contador de tempo de sessão  
✅ Avisos automáticos de expiração  
✅ Auto-renovação de sessão  
✅ Auditoria completa de sessões  
✅ Logout seguro  

---

**Versão:** 1.0.0  
**Data:** 18 de Janeiro de 2026  
**Compatibilidade:** PHP 7.4+, MySQL 5.7+
