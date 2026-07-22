# Sistema de Controle de Leituras de Hidrômetro

## 📋 Resumo

Sistema completo para controlar lançamento de leituras de hidrômetro com **log de usuário**, **configuração de período** e **regra de 1 leitura por mês** (usuário OU morador).

---

## 🎯 Funcionalidades Implementadas

### 1. Log de Lançamento

**Objetivo**: Registrar quem lançou cada leitura (usuário operador ou morador)

**Campos adicionados na tabela `leituras`**:
- `lancado_por_tipo` - ENUM('usuario', 'morador')
- `lancado_por_id` - INT (ID do usuário ou morador)
- `lancado_por_nome` - VARCHAR(255) (Nome de quem lançou)

**Exibição**:
- 👤 Nome (Operador) - quando lançado por usuário
- 🏠 Nome (Morador) - quando lançado por morador

### 2. Configuração de Período

**Objetivo**: Definir período em que moradores podem lançar próprias leituras

**Tabela**: `config_periodo_leitura`

**Campos**:
- `dia_inicio` - INT (1-31) - Dia inicial do período
- `dia_fim` - INT (1-31) - Dia final do período
- `morador_pode_lancar` - TINYINT (1 = Sim, 0 = Não)
- `ativo` - TINYINT (1 = Ativo, 0 = Inativo)

**Exemplo**:
```
Período: Dia 1 a 10
Morador pode lançar: Sim
Status: Ativo
```

**Resultado**: Moradores só podem lançar leituras entre os dias 1 e 10 de cada mês.

### 3. Regra de 1 Leitura por Mês

**Objetivo**: Garantir que apenas 1 leitura seja lançada por mês (usuário OU morador)

**Validação**:
1. Verifica se já existe leitura no mês/ano
2. Se existir, bloqueia novo lançamento
3. Exibe mensagem: "Já existe leitura para este mês lançada por [nome] ([tipo]) em [data]"

**Exemplo de Bloqueio**:
```
❌ Tentativa de lançamento bloqueada
Mensagem: "Já existe leitura para este mês lançada por João Silva (operador) em 05/01/2025 10:30"
```

---

## 📁 Arquivos Criados/Atualizados

### 1. SQL

**Arquivo**: `alter_leituras_add_log.sql`

**Conteúdo**:
- Adiciona campos de log na tabela `leituras`
- Cria tabela `config_periodo_leitura`
- Cria VIEW `view_leituras_completas`
- Cria PROCEDURE `sp_verificar_pode_lancar_leitura`
- Cria FUNCTION `fn_esta_no_periodo_leitura`
- Queries úteis para consultas

### 2. Backend (PHP)

**Arquivo 1**: `api_leituras.php` (ATUALIZADO)

**Alterações**:
- Adiciona validação de leitura duplicada no mês
- Adiciona campos de log no INSERT
- Adiciona `lancado_por_descricao` na listagem
- Valida antes de inserir

**Arquivo 2**: `api_config_periodo_leitura.php` (NOVO)

**Endpoints**:
- `GET /api_config_periodo_leitura.php` - Buscar configuração atual
- `POST /api_config_periodo_leitura.php` - Atualizar configuração

**Resposta GET**:
```json
{
  "sucesso": true,
  "mensagem": "Configuração encontrada",
  "dados": {
    "dia_inicio": 1,
    "dia_fim": 10,
    "morador_pode_lancar": 1,
    "esta_no_periodo": true,
    "dia_atual": 5
  }
}
```

### 3. Frontend (HTML)

**Arquivo**: `leitura.html` (ATUALIZADO)

**Alterações**:
- Nova aba "Configurações"
- Formulário para definir período
- Checkbox "Permitir que moradores lançem"
- Exibição de status do período
- Funções JavaScript para salvar/carregar

---

## 🔧 Como Usar

### Para Operadores (leitura.html)

#### 1. Configurar Período

1. Acesse leitura.html
2. Clique na aba "Configurações"
3. Defina:
   - Dia Inicial: 1
   - Dia Final: 10
   - ✅ Permitir que moradores lançem suas próprias leituras
4. Clique em "Salvar Configuração"

#### 2. Lançar Leitura

1. Acesse aba "Leitura Individual"
2. Selecione unidade, morador e hidrômetro
3. Informe leitura atual
4. Clique em "Registrar Leitura"

**Validações**:
- ✅ Se já houver leitura no mês, exibe erro
- ✅ Registra como lançado por "usuário"
- ✅ Salva nome do operador logado

### Para Moradores (portal.html)

#### 1. Verificar Período

- Morador só pode lançar dentro do período configurado
- Se fora do período, botão fica desabilitado
- Exibe mensagem: "Lançamento disponível de [dia_inicio] a [dia_fim]"

#### 2. Lançar Leitura

1. Acesse portal.html
2. Clique na aba "Hidrômetro"
3. Clique em "Lançar Leitura"
4. Informe leitura atual
5. Clique em "Salvar"

**Validações**:
- ✅ Verifica se está no período
- ✅ Verifica se já há leitura no mês
- ✅ Se operador já lançou, bloqueia morador
- ✅ Registra como lançado por "morador"

#### 3. Ver Histórico

- Histórico exibe quem lançou cada leitura
- Exemplo:
  - 👤 João Silva (Operador) - 05/01/2025
  - 🏠 Maria Santos (Morador) - 05/02/2025

---

## 📊 Exemplos de Uso

### Cenário 1: Operador lança no dia 5

```
Data: 05/01/2025
Operador: João Silva
Ação: Lançar leitura

Resultado:
✅ Leitura registrada com sucesso
✅ Lançado por: João Silva (Operador)
✅ Morador NÃO pode mais lançar neste mês
```

### Cenário 2: Morador tenta lançar após operador

```
Data: 10/01/2025
Morador: Maria Santos
Ação: Lançar leitura

Resultado:
❌ Lançamento bloqueado
Mensagem: "Já existe leitura para este mês lançada por João Silva (operador) em 05/01/2025 10:30"
```

### Cenário 3: Morador lança dentro do período

```
Data: 05/02/2025 (dia 5, dentro do período 1-10)
Morador: Maria Santos
Ação: Lançar leitura

Resultado:
✅ Leitura registrada com sucesso
✅ Lançado por: Maria Santos (Morador)
✅ Operador NÃO pode mais lançar neste mês
```

### Cenário 4: Morador tenta lançar fora do período

```
Data: 15/03/2025 (dia 15, fora do período 1-10)
Morador: Maria Santos
Ação: Tentar lançar leitura

Resultado:
❌ Botão desabilitado
Mensagem: "Lançamento disponível de 01 a 10 de cada mês"
```

---

## 🗄️ Estrutura do Banco de Dados

### Tabela: leituras (ATUALIZADA)

```sql
ALTER TABLE leituras 
ADD COLUMN lancado_por_tipo ENUM('usuario', 'morador') DEFAULT 'usuario',
ADD COLUMN lancado_por_id INT NULL,
ADD COLUMN lancado_por_nome VARCHAR(255) NULL;
```

### Tabela: config_periodo_leitura (NOVA)

```sql
CREATE TABLE config_periodo_leitura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dia_inicio INT NOT NULL DEFAULT 1,
    dia_fim INT NOT NULL DEFAULT 10,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    morador_pode_lancar TINYINT(1) NOT NULL DEFAULT 1,
    data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### VIEW: view_leituras_completas (NOVA)

```sql
CREATE OR REPLACE VIEW view_leituras_completas AS
SELECT 
    l.*,
    h.numero_hidrometro,
    m.nome as morador_nome,
    CASE 
        WHEN l.lancado_por_tipo = 'usuario' THEN CONCAT('👤 ', l.lancado_por_nome, ' (Operador)')
        WHEN l.lancado_por_tipo = 'morador' THEN CONCAT('🏠 ', l.lancado_por_nome, ' (Morador)')
        ELSE 'Sistema'
    END as lancado_por_descricao
FROM leituras l
INNER JOIN hidrometros h ON l.hidrometro_id = h.id
INNER JOIN moradores m ON l.morador_id = m.id;
```

---

## 🔍 Queries Úteis

### Listar leituras com quem lançou

```sql
SELECT * FROM view_leituras_completas 
ORDER BY data_leitura DESC 
LIMIT 50;
```

### Verificar configuração de período

```sql
SELECT * FROM config_periodo_leitura WHERE ativo = 1;
```

### Verificar se está no período

```sql
SELECT fn_esta_no_periodo_leitura() as esta_no_periodo;
```

### Verificar leituras duplicadas no mesmo mês

```sql
SELECT 
    hidrometro_id,
    DATE_FORMAT(data_leitura, '%m/%Y') as mes_ano,
    COUNT(*) as total_leituras,
    GROUP_CONCAT(CONCAT(lancado_por_nome, ' (', lancado_por_tipo, ')') SEPARATOR ', ') as lancado_por
FROM leituras
GROUP BY hidrometro_id, DATE_FORMAT(data_leitura, '%m/%Y')
HAVING total_leituras > 1;
```

### Verificar se morador pode lançar hoje

```sql
CALL sp_verificar_pode_lancar_leitura(
    1,  -- hidrometro_id
    MONTH(CURDATE()),  -- mês atual
    YEAR(CURDATE()),   -- ano atual
    @pode_lancar,
    @mensagem,
    @lancado_por_tipo,
    @lancado_por_nome,
    @data_leitura
);

SELECT @pode_lancar, @mensagem, @lancado_por_tipo, @lancado_por_nome, @data_leitura;
```

---

## 📝 Instruções de Instalação

### 1. Executar SQL

1. Acesse phpMyAdmin
2. Selecione banco "inlaud99_erpserra"
3. Clique em "SQL"
4. Copie e cole o conteúdo de `alter_leituras_add_log.sql`
5. Clique em "Executar"

### 2. Fazer Upload dos Arquivos

**Arquivos para upload**:
- `api_leituras.php` (atualizado)
- `api_config_periodo_leitura.php` (novo)
- `leitura.html` (atualizado)

### 3. Configurar Período

1. Acesse leitura.html
2. Clique em "Configurações"
3. Defina período (ex: dia 1 a 10)
4. Marque "Permitir que moradores lançem"
5. Salve

### 4. Testar

**Teste 1: Lançamento por operador**
1. Acesse leitura.html
2. Lance uma leitura
3. Verifique se foi registrado como "usuário"

**Teste 2: Bloqueio de duplicação**
1. Tente lançar novamente no mesmo mês
2. Deve exibir erro com nome de quem já lançou

**Teste 3: Lançamento por morador (dentro do período)**
1. Acesse portal.html como morador
2. Vá em "Hidrômetro"
3. Lance leitura (se dentro do período)
4. Verifique se foi registrado como "morador"

**Teste 4: Bloqueio fora do período**
1. Mude data do servidor para fora do período
2. Acesse portal.html como morador
3. Botão deve estar desabilitado

---

## ⚠️ Importante

### Regras de Negócio

1. **1 leitura por mês**: Apenas UMA leitura por hidrômetro por mês
2. **Usuário OU morador**: Se um lançar, o outro não pode
3. **Período obrigatório**: Morador só lança dentro do período
4. **Operador sem restrição**: Operador pode lançar qualquer dia

### Mensagens de Erro

- "Já existe leitura para este mês lançada por [nome] ([tipo]) em [data]"
- "Lançamento disponível de [dia_inicio] a [dia_fim] de cada mês"
- "Dia inicial não pode ser maior que dia final"

---

## 🎉 Benefícios

✅ **Transparência**: Saber quem lançou cada leitura  
✅ **Controle**: Apenas 1 leitura por mês  
✅ **Flexibilidade**: Configurar período conforme necessidade  
✅ **Autonomia**: Morador pode lançar própria leitura  
✅ **Auditoria**: Histórico completo de lançamentos  

---

## 📞 Suporte

Em caso de dúvidas ou problemas:
1. Verificar se SQL foi executado corretamente
2. Verificar se arquivos foram enviados
3. Verificar se configuração de período está ativa
4. Consultar logs de erro no sistema

---

**Versão**: 1.0  
**Data**: 26/12/2024  
**Autor**: Manus AI
