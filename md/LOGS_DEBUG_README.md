# Sistema de Logs de Debug e Erro

## 📋 Visão Geral

Sistema completo de registro e visualização de erros técnicos para facilitar o debug e resolução de problemas no ERP Serra da Liberdade.

## 🎯 Objetivo

Separar **logs de auditoria** (ações de usuários) de **logs de erro** (problemas técnicos), facilitando a identificação e correção de bugs.

## 📦 Componentes Implementados

### 1. Banco de Dados

**Arquivo:** `create_logs_erro.sql`

**Tabela:** `logs_erro`

Campos principais:
- `tipo`: javascript, php, api, sql, sistema
- `nivel`: critical, error, warning, info, debug
- `arquivo`: Nome do arquivo onde ocorreu o erro
- `funcao`: Função ou método onde ocorreu
- `linha`: Linha do código
- `mensagem`: Mensagem de erro
- `stack_trace`: Stack trace completo
- `contexto`: Dados adicionais em JSON
- `url`: URL onde ocorreu
- `user_agent`: Navegador do usuário
- `ip_address`: IP de origem
- `data_hora`: Timestamp do erro

**Instalação:**
```sql
-- Execute no phpMyAdmin ou MySQL CLI
source create_logs_erro.sql;
```

### 2. API de Logs de Erro

**Arquivo:** `api_logs_erro.php`

**Endpoints:**

#### POST - Registrar novo erro
```javascript
fetch('api_logs_erro.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        tipo: 'javascript',
        nivel: 'error',
        arquivo: 'visitantes.html',
        funcao: 'gerarQRCode',
        linha: 245,
        mensagem: 'Erro ao gerar QR Code',
        stack_trace: error.stack,
        contexto: JSON.stringify({acesso_id: 123}),
        url: window.location.href,
        user_agent: navigator.userAgent
    })
});
```

#### GET - Listar erros
```
api_logs_erro.php?action=listar&tipo=javascript&nivel=error&limit=100&offset=0
```

Parâmetros:
- `tipo`: Filtrar por tipo
- `nivel`: Filtrar por nível
- `arquivo`: Filtrar por arquivo
- `data_inicial`: Data início (YYYY-MM-DD)
- `data_final`: Data fim (YYYY-MM-DD)
- `limit`: Registros por página (padrão: 100)
- `offset`: Deslocamento para paginação

#### GET - Estatísticas
```
api_logs_erro.php?action=estatisticas
```

Retorna:
- Total de erros
- Erros por tipo
- Erros por nível
- Erros nas últimas 24h
- Arquivos com mais erros

### 3. Interface de Visualização

**Arquivo:** `logs_sistema_v2.html`

**Funcionalidades:**

#### Aba "Logs de Auditoria"
- Visualização de ações de usuários (login, cadastro, edição, etc.)
- Filtros por tipo, usuário, data
- Exportação CSV
- Paginação

#### Aba "Logs de Erro/Debug"
- Visualização de erros técnicos
- Filtros por tipo, nível, arquivo, data
- Detalhes completos do erro (stack trace, contexto)
- Estatísticas em tempo real
- Identificação de arquivos problemáticos

**Estatísticas exibidas:**
- Total de erros
- Erros hoje
- Erros críticos
- Erros nas últimas 24h

**Filtros disponíveis:**
- Tipo de erro (JavaScript, PHP, API, SQL, Sistema)
- Nível (Critical, Error, Warning, Info, Debug)
- Arquivo específico
- Período (data início/fim)
- Registros por página

### 4. Captura Automática de Erros

#### JavaScript (visitantes.html)

**Função implementada:**
```javascript
function registrarErro(tipo, nivel, arquivo, funcao, mensagem, contexto = {}) {
    const dados = {
        tipo: tipo,
        nivel: nivel,
        arquivo: arquivo,
        funcao: funcao,
        mensagem: mensagem,
        contexto: JSON.stringify(contexto),
        url: window.location.href,
        user_agent: navigator.userAgent
    };
    
    fetch('api_logs_erro.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(dados)
    });
}
```

**Exemplo de uso:**
```javascript
try {
    // Código que pode gerar erro
    gerarQRCode(id);
} catch (error) {
    registrarErro('javascript', 'error', 'visitantes.html', 'gerarQRCode', 
        error.message, {acesso_id: id, stack: error.stack});
}
```

#### PHP (api_acessos_visitantes.php)

**Logs implementados na função gerar_qrcode:**
```php
error_log("[DEBUG QR] Iniciando geração de QR Code");
error_log("[DEBUG QR] ID do acesso: $id");
error_log("[DEBUG QR] Buscando dados do acesso no banco");
error_log("[DEBUG QR] Acesso encontrado: " . $acesso['nome_completo']);
error_log("[DEBUG QR] URL da API Google Charts: $qr_url");
error_log("[DEBUG QR] Fazendo requisição para Google Charts...");
error_log("[DEBUG QR] Imagem recebida com sucesso. Tamanho: " . strlen($qr_image) . " bytes");
error_log("[DEBUG QR] Salvando QR Code no banco de dados...");
error_log("[DEBUG QR] QR Code salvo no banco com sucesso");
error_log("[DEBUG QR] Retornando QR Code para o cliente");
```

**Captura de erros:**
```php
if ($qr_image === false) {
    $error = error_get_last();
    error_log("[DEBUG QR] ERRO ao buscar imagem do Google Charts: " . ($error['message'] ?? 'Desconhecido'));
    error_log("[DEBUG QR] Verifique: 1) Conexão com internet, 2) Firewall, 3) allow_url_fopen habilitado");
    retornar_json(false, "Erro ao gerar QR Code: Não foi possível conectar ao serviço de QR Code");
}
```

## 🔍 Como Usar para Debug

### Cenário 1: Erro ao gerar QR Code em visitantes.html

1. **Acesse logs_sistema_v2.html**
2. **Clique na aba "Logs de Erro/Debug"**
3. **Filtre por:**
   - Tipo: `javascript` ou `api`
   - Arquivo: `visitantes.html` ou `api_acessos_visitantes.php`
   - Período: últimas 24h
4. **Clique no botão 👁️ para ver detalhes completos**
5. **Analise:**
   - Mensagem de erro
   - Stack trace
   - Contexto (ID do acesso, parâmetros)
   - URL onde ocorreu

### Cenário 2: Verificar logs PHP no servidor

**Via SSH/cPanel:**
```bash
# Ver últimos logs
tail -f /var/log/apache2/error.log | grep "DEBUG QR"

# Ou no arquivo de log do PHP
tail -f /var/log/php_errors.log | grep "DEBUG QR"
```

**Buscar logs específicos:**
```bash
grep "DEBUG QR" /var/log/apache2/error.log | tail -50
```

### Cenário 3: Monitorar erros em tempo real

1. Abra o console do navegador (F12)
2. Acesse visitantes.html
3. Tente gerar QR Code
4. Observe logs com emojis:
   - 🔵 [DEBUG QR] - Informação
   - ✅ [DEBUG QR] - Sucesso
   - ❌ [DEBUG QR] - Erro
   - 📝 [LOG] - Registro no banco

## 📊 Níveis de Severidade

| Nível | Uso | Exemplo |
|-------|-----|---------|
| **critical** | Erros que impedem funcionamento do sistema | Banco de dados inacessível |
| **error** | Erros que impedem uma operação específica | Falha ao gerar QR Code |
| **warning** | Situações anormais mas não críticas | Timeout em API externa |
| **info** | Informações relevantes | Usuário tentou acessar recurso inexistente |
| **debug** | Informações detalhadas para desenvolvimento | Valores de variáveis, fluxo de execução |

## 🛠️ Manutenção

### Limpar logs antigos

**Via SQL:**
```sql
-- Limpar logs com mais de 90 dias
DELETE FROM logs_erro WHERE data_hora < DATE_SUB(NOW(), INTERVAL 90 DAY);

-- Ou usar a procedure
CALL limpar_logs_erro_antigos(90);
```

**Via interface:**
- Acesse logs_sistema_v2.html
- Aba "Logs de Erro/Debug"
- Clique em "Limpar Antigos"

### Estatísticas úteis

**Arquivos com mais erros:**
```sql
SELECT * FROM v_arquivos_com_erros;
```

**Erros recentes (24h):**
```sql
SELECT * FROM v_erros_recentes;
```

**Estatísticas por tipo:**
```sql
SELECT * FROM v_estatisticas_erros;
```

## 📝 Checklist de Implementação

### No Servidor de Produção

- [ ] 1. Fazer backup do banco de dados
- [ ] 2. Executar `create_logs_erro.sql` no banco
- [ ] 3. Upload de `api_logs_erro.php`
- [ ] 4. Upload de `api_acessos_visitantes.php` (atualizado)
- [ ] 5. Upload de `visitantes.html` (atualizado)
- [ ] 6. Upload de `logs_sistema_v2.html`
- [ ] 7. Testar acesso a `logs_sistema_v2.html`
- [ ] 8. Testar geração de QR Code em `visitantes.html`
- [ ] 9. Verificar se erros aparecem em logs_sistema_v2.html
- [ ] 10. Verificar logs PHP no servidor (se tiver acesso SSH)

### Verificações

- [ ] Tabela `logs_erro` criada no banco
- [ ] API `api_logs_erro.php` responde corretamente
- [ ] Interface `logs_sistema_v2.html` carrega sem erros
- [ ] Logs de JavaScript são registrados
- [ ] Logs de PHP aparecem no error_log do servidor
- [ ] Filtros funcionam corretamente
- [ ] Paginação funciona
- [ ] Detalhes do erro exibem stack trace e contexto

## 🐛 Resolução de Problemas Comuns

### Erro: "Tabela logs_erro não existe"
**Solução:** Execute o script `create_logs_erro.sql` no banco de dados

### Erro: "api_logs_erro.php não encontrado"
**Solução:** Verifique se o arquivo foi enviado para o servidor e está no diretório raiz

### Logs não aparecem na interface
**Solução:** 
1. Abra o console do navegador (F12)
2. Verifique se há erros JavaScript
3. Teste a API diretamente: `api_logs_erro.php?action=listar`
4. Verifique permissões do arquivo no servidor

### QR Code ainda não funciona
**Solução:**
1. Acesse logs_sistema_v2.html → Aba "Logs de Erro"
2. Filtre por arquivo: `api_acessos_visitantes.php`
3. Veja a mensagem de erro específica
4. Possíveis causas:
   - `allow_url_fopen` desabilitado no PHP
   - Firewall bloqueando acesso a chart.googleapis.com
   - Problema de conectividade com internet
   - Dados inválidos no banco (qr_code vazio)

## 📚 Referências

- **Tabela:** `logs_erro`
- **API:** `api_logs_erro.php`
- **Interface:** `logs_sistema_v2.html`
- **Exemplo de uso:** `visitantes.html` (função `registrarErro`)
- **Logs PHP:** `api_acessos_visitantes.php` (função `gerar_qrcode`)

## 🔄 Próximas Melhorias

- [ ] Exportação de logs em CSV/Excel
- [ ] Notificações por email para erros críticos
- [ ] Dashboard de métricas de erros
- [ ] Integração com ferramentas de monitoramento (Sentry, Rollbar)
- [ ] Agrupamento de erros similares
- [ ] Marcação de erros como "resolvidos"
- [ ] Atribuição de erros para desenvolvedores
- [ ] Gráficos de tendência de erros

## 👨‍💻 Desenvolvido por

André Programador BH
Data: 26/12/2024
Sistema: ERP Serra da Liberdade
