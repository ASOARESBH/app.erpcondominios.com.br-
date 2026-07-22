# Documentação: Sistema de Logs e Auditoria

## 📋 Visão Geral

O sistema de logs foi implementado para fornecer **auditoria completa** de todas as ações, eventos e erros que ocorrem no sistema ERP Serra da Liberdade. Esta funcionalidade permite rastreamento detalhado para análise de problemas, segurança e conformidade.

## 🎯 Funcionalidades Implementadas

### 1. **Visualização de Logs**
- Interface completa para visualização de logs do sistema
- Tabela paginada com todos os registros
- Informações detalhadas: ID, Data/Hora, Tipo, Descrição, Usuário e IP

### 2. **Filtros Avançados**
- **Por Tipo:** Filtre por tipo específico de log (ACESSO_RFID, LOGIN_SUCESSO, etc.)
- **Por Usuário:** Busque logs de um usuário específico
- **Por Período:** Defina data de início e fim
- **Busca Geral:** Pesquise em descrição, tipo ou usuário
- **Limite de Registros:** 50, 100, 200 ou 500 registros por página

### 3. **Estatísticas em Tempo Real**
- **Total de Logs:** Quantidade total de registros
- **Logs Hoje:** Registros do dia atual
- **Tipos Diferentes:** Quantidade de tipos únicos de logs
- **Usuários Ativos:** Quantidade de usuários que geraram logs

### 4. **Exportação de Dados**
- Exportação para **CSV** com todos os filtros aplicados
- Nome do arquivo: `logs_sistema_YYYY-MM-DD.csv`
- Compatível com Excel e Google Sheets

### 5. **Limpeza de Logs Antigos**
- Remove logs com mais de X dias (mínimo 30 dias)
- Confirmação dupla para evitar exclusões acidentais
- Registra a própria ação de limpeza no log

### 6. **Paginação Inteligente**
- Navegação por páginas
- Botões Anterior/Próximo
- Indicador de página atual e total
- Máximo de 5 páginas visíveis por vez

## 📊 Estrutura da Tabela `logs_sistema`

```sql
CREATE TABLE `logs_sistema` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(50) NOT NULL,
  `descricao` text NOT NULL,
  `usuario` varchar(100) DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `data_hora` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tipo` (`tipo`),
  KEY `idx_usuario` (`usuario`),
  KEY `idx_data_hora` (`data_hora`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 🏷️ Tipos de Logs Registrados

### **Controle de Acesso**
- `ACESSO_RFID` - Acesso autorizado via RFID
- `ACESSO_NEGADO_RFID` - Acesso negado via RFID (TAG não cadastrada ou inativa)
- `REGISTRO_CRIADO` - Registro manual de entrada/saída

### **Autenticação**
- `LOGIN_SUCESSO` - Login bem-sucedido
- `LOGIN_FALHA` - Tentativa de login com credenciais incorretas
- `LOGIN_MORADOR_SUCESSO` - Login de morador no portal
- `LOGIN_MORADOR_FALHA` - Tentativa de login de morador falhou
- `SENHA_ATUALIZADA` - Senha foi atualizada (migração ou alteração)

### **Gestão de Moradores**
- `MORADOR_CRIADO` - Novo morador cadastrado
- `MORADOR_ATUALIZADO` - Dados de morador atualizados
- `MORADOR_EXCLUIDO` - Morador removido do sistema

### **Gestão de Veículos**
- `VEICULO_CRIADO` - Novo veículo cadastrado
- `VEICULO_ATUALIZADO` - Dados de veículo atualizados
- `VEICULO_EXCLUIDO` - Veículo removido do sistema

### **Gestão de Usuários**
- `USUARIO_CRIADO` - Novo usuário do sistema criado
- `USUARIO_ATUALIZADO` - Dados de usuário atualizados
- `USUARIO_EXCLUIDO` - Usuário removido do sistema

### **Sistema**
- `LIMPEZA_LOGS` - Logs antigos foram removidos
- `BACKUP_REALIZADO` - Backup do banco de dados realizado
- `ERRO_SISTEMA` - Erro crítico do sistema

## 🔧 API de Logs (`api_logs_sistema.php`)

### **Endpoints Disponíveis**

#### 1. GET - Listar Logs com Filtros
```
GET /api_logs_sistema.php?tipo=ACESSO_RFID&data_inicio=2024-01-01&data_fim=2024-12-31&pagina=1&limite=100
```

**Parâmetros:**
- `tipo` (opcional) - Filtrar por tipo de log
- `usuario` (opcional) - Filtrar por nome de usuário
- `data_inicio` (opcional) - Data inicial (YYYY-MM-DD)
- `data_fim` (opcional) - Data final (YYYY-MM-DD)
- `busca` (opcional) - Busca geral em descrição, tipo ou usuário
- `limite` (opcional) - Registros por página (padrão: 100)
- `pagina` (opcional) - Número da página (padrão: 1)

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Logs listados com sucesso",
  "dados": {
    "logs": [
      {
        "id": 1,
        "tipo": "ACESSO_RFID",
        "descricao": "Acesso via RFID: GBI7C55 (TAG: MAJJHAG0022) - ANDRE SOARES E SILVA",
        "usuario": null,
        "ip": "200.229.247.18",
        "data_hora_formatada": "12/10/2025 13:21:06",
        "data_hora": "2025-10-12 13:21:06"
      }
    ],
    "paginacao": {
      "pagina_atual": 1,
      "total_paginas": 5,
      "total_registros": 500,
      "registros_por_pagina": 100
    }
  }
}
```

#### 2. POST - Obter Estatísticas
```
POST /api_logs_sistema.php?action=estatisticas
Content-Type: application/x-www-form-urlencoded

data_inicio=2024-01-01&data_fim=2024-12-31
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Estatísticas geradas com sucesso",
  "dados": {
    "total_geral": 1500,
    "logs_por_tipo": [
      { "tipo": "ACESSO_RFID", "total": 850, "ultimo_registro": "18/12/2024 15:30:00" },
      { "tipo": "LOGIN_SUCESSO", "total": 320, "ultimo_registro": "18/12/2024 14:20:00" }
    ],
    "logs_por_usuario": [
      { "usuario": "ANDRE SOARES", "total": 150, "ultimo_acesso": "18/12/2024 15:00:00" }
    ],
    "timeline": [
      { "dia": "01/12", "total": 45 },
      { "dia": "02/12", "total": 52 }
    ],
    "periodo": {
      "inicio": "01/01/2024",
      "fim": "31/12/2024"
    }
  }
}
```

#### 3. GET - Listar Tipos de Logs
```
GET /api_logs_sistema.php?action=tipos
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Tipos de logs listados com sucesso",
  "dados": [
    { "tipo": "ACESSO_RFID", "total": 850 },
    { "tipo": "LOGIN_SUCESSO", "total": 320 }
  ]
}
```

#### 4. DELETE - Limpar Logs Antigos
```
DELETE /api_logs_sistema.php?action=limpar
Content-Type: application/json

{
  "dias": 90
}
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Logs antigos limpos com sucesso",
  "dados": {
    "registros_excluidos": 1250
  }
}
```

#### 5. GET - Exportar Logs
```
GET /api_logs_sistema.php?action=exportar&tipo=ACESSO_RFID&data_inicio=2024-01-01&data_fim=2024-12-31
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Logs exportados com sucesso",
  "dados": [
    {
      "id": 1,
      "tipo": "ACESSO_RFID",
      "descricao": "Acesso via RFID...",
      "usuario": null,
      "ip": "200.229.247.18",
      "data_hora": "12/10/2025 13:21:06"
    }
  ]
}
```

## 🎨 Interface do Usuário

### **Componentes Principais**

#### 1. Cards de Estatísticas
- Total de Logs
- Logs Hoje
- Tipos Diferentes
- Usuários Ativos

#### 2. Seção de Filtros
- Select de Tipo de Log (carregado dinamicamente)
- Input de Usuário
- Inputs de Data (Início e Fim)
- Input de Busca Geral
- Select de Limite de Registros

#### 3. Botões de Ação
- **Buscar** - Aplica filtros
- **Limpar Filtros** - Remove todos os filtros
- **Exportar CSV** - Exporta logs filtrados
- **Atualizar Estatísticas** - Recarrega estatísticas
- **Limpar Logs Antigos** - Remove logs antigos (com confirmação)

#### 4. Tabela de Logs
- Colunas: ID, Data/Hora, Tipo, Descrição, Usuário, IP
- Badge colorido por tipo de log
- Hover para destacar linha
- Responsiva com scroll horizontal em mobile

#### 5. Paginação
- Botões Anterior/Próximo
- Números de páginas clicáveis
- Indicador de página atual
- Máximo de 5 páginas visíveis

## 🎨 Badges por Tipo de Log

| Tipo | Badge | Cor |
|------|-------|-----|
| ACESSO_RFID | success | Verde |
| ACESSO_NEGADO_RFID | danger | Vermelho |
| LOGIN_SUCESSO | success | Verde |
| LOGIN_FALHA | danger | Vermelho |
| MORADOR_CRIADO | info | Azul |
| MORADOR_ATUALIZADO | warning | Amarelo |
| MORADOR_EXCLUIDO | danger | Vermelho |
| VEICULO_CRIADO | info | Azul |
| VEICULO_ATUALIZADO | warning | Amarelo |
| VEICULO_EXCLUIDO | danger | Vermelho |
| USUARIO_CRIADO | info | Azul |
| USUARIO_ATUALIZADO | warning | Amarelo |
| USUARIO_EXCLUIDO | danger | Vermelho |
| REGISTRO_CRIADO | primary | Roxo |
| SENHA_ATUALIZADA | warning | Amarelo |
| LIMPEZA_LOGS | secondary | Cinza |

## 📱 Responsividade

### **Desktop (> 768px)**
- Sidebar fixa à esquerda
- Tabela com todas as colunas visíveis
- Filtros em grid 3x2
- Cards de estatísticas em linha

### **Tablet (768px)**
- Sidebar recolhível
- Menu toggle visível
- Filtros em coluna única
- Tabela com scroll horizontal

### **Mobile (< 480px)**
- Sidebar em overlay
- Botões em largura total
- Cards de estatísticas empilhados
- Tabela compacta com scroll

## 🔒 Segurança

### **Proteção de Acesso**
- Requer autenticação via `auth-guard.js`
- Apenas usuários logados podem acessar
- Logs registram IP de origem

### **Validações**
- Limpeza de logs: mínimo 30 dias
- Confirmação dupla para exclusões
- Sanitização de inputs na API

### **SQL Injection**
- Prepared statements em todas as queries
- Validação de tipos de dados
- Escape de caracteres especiais

## 📈 Casos de Uso

### **1. Investigar Acesso Negado**
1. Selecionar tipo: `ACESSO_NEGADO_RFID`
2. Definir período (últimos 7 dias)
3. Clicar em "Buscar"
4. Analisar descrições para identificar TAGs não cadastradas

### **2. Auditar Ações de Usuário**
1. Digitar nome do usuário no filtro
2. Definir período
3. Clicar em "Buscar"
4. Exportar para CSV para análise externa

### **3. Monitorar Logins Falhados**
1. Selecionar tipo: `LOGIN_FALHA`
2. Buscar por período
3. Identificar IPs com múltiplas tentativas
4. Tomar ações de segurança se necessário

### **4. Manutenção do Sistema**
1. Acessar "Limpar Logs Antigos"
2. Definir período (ex: 90 dias)
3. Confirmar exclusão
4. Verificar quantidade de registros removidos

## 🚀 Melhorias Futuras

### **Curto Prazo**
- [ ] Gráfico de timeline de logs (Chart.js)
- [ ] Filtro por IP
- [ ] Detalhes expandidos ao clicar em log
- [ ] Exportação para PDF

### **Médio Prazo**
- [ ] Dashboard de logs em tempo real
- [ ] Alertas automáticos para eventos críticos
- [ ] Integração com sistema de notificações
- [ ] Logs de API externa (webhook)

### **Longo Prazo**
- [ ] Machine Learning para detecção de anomalias
- [ ] Análise preditiva de problemas
- [ ] Integração com SIEM (Security Information and Event Management)
- [ ] Logs distribuídos (múltiplos servidores)

## 📝 Exemplo de Uso da Função `registrar_log()`

```php
// Em qualquer arquivo PHP do sistema
require_once 'config.php';

// Registrar log de criação
registrar_log(
    'MORADOR_CRIADO',
    "Morador criado: João Silva (ID: 123)",
    'ANDRE SOARES'
);

// Registrar log de erro
registrar_log(
    'ERRO_SISTEMA',
    "Erro ao enviar e-mail: Connection timeout",
    'Sistema'
);

// Registrar log de acesso
registrar_log(
    'ACESSO_RFID',
    "Acesso via RFID: ABC1234 (TAG: TAG001) - Maria Santos",
    null,  // Usuário automático
    $_SERVER['REMOTE_ADDR']  // IP do cliente
);
```

## 🔍 Consultas SQL Úteis

### **Logs por Tipo (últimos 30 dias)**
```sql
SELECT tipo, COUNT(*) as total
FROM logs_sistema
WHERE data_hora >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY tipo
ORDER BY total DESC;
```

### **Usuários Mais Ativos**
```sql
SELECT usuario, COUNT(*) as total
FROM logs_sistema
WHERE usuario IS NOT NULL
  AND data_hora >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY usuario
ORDER BY total DESC
LIMIT 10;
```

### **Acessos Negados por TAG**
```sql
SELECT 
    SUBSTRING_INDEX(SUBSTRING_INDEX(descricao, 'TAG ', -1), ' não', 1) as tag,
    COUNT(*) as tentativas
FROM logs_sistema
WHERE tipo = 'ACESSO_NEGADO_RFID'
  AND data_hora >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY tag
ORDER BY tentativas DESC;
```

### **Timeline de Logs (últimos 7 dias)**
```sql
SELECT 
    DATE_FORMAT(data_hora, '%d/%m') as dia,
    COUNT(*) as total
FROM logs_sistema
WHERE data_hora >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(data_hora)
ORDER BY data_hora ASC;
```

## 📞 Suporte

Para dúvidas ou problemas relacionados ao sistema de logs:
- Verifique a documentação completa
- Consulte os logs de erro do PHP
- Entre em contato com o administrador do sistema

---

**Versão:** 1.0  
**Data:** 18 de Dezembro de 2024  
**Desenvolvedor:** Manus AI  
**Sistema:** ERP Serra da Liberdade
