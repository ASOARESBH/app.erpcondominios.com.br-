# 📊 Documentação - Módulo Financeiro

## 📋 Visão Geral

O módulo Financeiro foi desenvolvido para gerenciar contas a pagar, contas a receber e planos de contas do ERP Condomínio — Gestão Inteligente ERP Condomínio.

**Versão:** 1.0  
**Data:** 05/01/2026  
**Status:** ✅ Pronto para Produção

---

## 📁 Arquivos Criados

### APIs Backend
1. **`api_planos_contas.php`** - Gerenciar planos de contas
2. **`api_contas_pagar.php`** - Gerenciar contas a pagar
3. **`api_contas_receber.php`** - Gerenciar contas a receber

### Páginas Frontend
1. **`planos_contas.html`** - Cadastro e listagem de planos de contas
2. **`contas_pagar.html`** - Cadastro e pagamento de contas a pagar
3. **`contas_receber.html`** - Cadastro e recebimento de contas a receber

### Banco de Dados
1. **`TABELAS_FINANCEIRO.sql`** - Script SQL com todas as tabelas

### Documentação
1. **`DOCUMENTACAO_MODULO_FINANCEIRO.md`** - Este arquivo

---

## 🗄️ Estrutura do Banco de Dados

### Tabela: `planos_contas`
```sql
CREATE TABLE planos_contas (
  id INT PRIMARY KEY AUTO_INCREMENT,
  codigo VARCHAR(50) UNIQUE NOT NULL,
  tipo ENUM('ATIVO', 'PASSIVO', 'PATRIMONIO', 'RECEITA', 'DESPESA'),
  nome VARCHAR(255) NOT NULL,
  natureza ENUM('DEVEDORA', 'CREDORA'),
  categoria VARCHAR(100),
  descricao TEXT,
  ativo BOOLEAN DEFAULT 1,
  data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Tabela: `contas_pagar`
```sql
CREATE TABLE contas_pagar (
  id INT PRIMARY KEY AUTO_INCREMENT,
  numero_documento VARCHAR(50) UNIQUE NOT NULL,
  fornecedor_nome VARCHAR(255) NOT NULL,
  plano_conta_id INT NOT NULL,
  descricao TEXT,
  valor_original DECIMAL(10, 2) NOT NULL,
  valor_pago DECIMAL(10, 2) DEFAULT 0,
  saldo_devedor DECIMAL(10, 2),
  data_emissao DATE,
  data_vencimento DATE NOT NULL,
  data_pagamento DATE,
  status ENUM('PENDENTE', 'PARCIAL', 'PAGO') DEFAULT 'PENDENTE',
  forma_pagamento VARCHAR(50),
  observacoes TEXT,
  ativo BOOLEAN DEFAULT 1,
  data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (plano_conta_id) REFERENCES planos_contas(id)
);
```

### Tabela: `contas_receber`
```sql
CREATE TABLE contas_receber (
  id INT PRIMARY KEY AUTO_INCREMENT,
  numero_documento VARCHAR(50) UNIQUE NOT NULL,
  morador_nome VARCHAR(255) NOT NULL,
  unidade_numero VARCHAR(50),
  plano_conta_id INT NOT NULL,
  descricao TEXT,
  valor_original DECIMAL(10, 2) NOT NULL,
  valor_recebido DECIMAL(10, 2) DEFAULT 0,
  saldo_devedor DECIMAL(10, 2),
  data_emissao DATE,
  data_vencimento DATE NOT NULL,
  data_recebimento DATE,
  status ENUM('PENDENTE', 'PARCIAL', 'RECEBIDO') DEFAULT 'PENDENTE',
  forma_pagamento VARCHAR(50),
  observacoes TEXT,
  ativo BOOLEAN DEFAULT 1,
  data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (plano_conta_id) REFERENCES planos_contas(id)
);
```

---

## 🔌 APIs

### 1. API de Planos de Contas (`api_planos_contas.php`)

#### Listar Planos
```
GET /api_planos_contas.php?acao=listar
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Planos de contas carregados",
  "dados": [
    {
      "id": 1,
      "codigo": "1.1.1.1",
      "tipo": "ATIVO",
      "nome": "Caixa e Bancos",
      "natureza": "DEVEDORA",
      "categoria": "Financeiro",
      "descricao": "Contas de caixa e bancos",
      "ativo": 1
    }
  ]
}
```

#### Buscar Plano
```
GET /api_planos_contas.php?acao=buscar&id=1
```

#### Cadastrar Plano
```
POST /api_planos_contas.php
Content-Type: application/x-www-form-urlencoded

acao=cadastrar&codigo=1.1.1.1&tipo=ATIVO&nome=Caixa&natureza=DEVEDORA&categoria=Financeiro
```

#### Atualizar Plano
```
POST /api_planos_contas.php
Content-Type: application/x-www-form-urlencoded

acao=atualizar&id=1&tipo=ATIVO&nome=Novo Nome&natureza=DEVEDORA
```

#### Deletar Plano
```
POST /api_planos_contas.php
Content-Type: application/x-www-form-urlencoded

acao=deletar&id=1
```

---

### 2. API de Contas a Pagar (`api_contas_pagar.php`)

#### Listar Contas
```
GET /api_contas_pagar.php?acao=listar&status=PENDENTE&limite=50&offset=0
```

#### Cadastrar Conta
```
POST /api_contas_pagar.php
Content-Type: application/x-www-form-urlencoded

acao=cadastrar&numero_documento=NF001&fornecedor_nome=Fornecedor XYZ&plano_conta_id=1&descricao=Descrição&valor_original=1000.00&data_vencimento=2026-02-05
```

#### Registrar Pagamento
```
POST /api_contas_pagar.php
Content-Type: application/x-www-form-urlencoded

acao=pagar&id=1&valor_pago=500.00&data_pagamento=2026-01-05&forma_pagamento=TRANSFERENCIA
```

---

### 3. API de Contas a Receber (`api_contas_receber.php`)

#### Listar Contas
```
GET /api_contas_receber.php?acao=listar&status=PENDENTE
```

#### Cadastrar Conta
```
POST /api_contas_receber.php
Content-Type: application/x-www-form-urlencoded

acao=cadastrar&numero_documento=FAT001&morador_nome=João Silva&unidade_numero=Gleba 5&plano_conta_id=1&descricao=Aluguel&valor_original=500.00&data_vencimento=2026-02-05
```

#### Registrar Recebimento
```
POST /api_contas_receber.php
Content-Type: application/x-www-form-urlencoded

acao=receber&id=1&valor_recebido=500.00&data_recebimento=2026-01-05&forma_pagamento=DINHEIRO
```

---

## 🎨 Interface do Usuário

### Página: Planos de Contas (`planos_contas.html`)

**Funcionalidades:**
- ✅ Cadastro de novos planos de contas
- ✅ Listagem com filtros
- ✅ Edição de planos existentes
- ✅ Exclusão (soft delete)
- ✅ Validação de campos obrigatórios

**Campos:**
- Código (único, obrigatório)
- Tipo (Ativo, Passivo, Patrimônio, Receita, Despesa)
- Nome (obrigatório)
- Natureza (Devedora, Credora)
- Categoria
- Descrição

---

### Página: Contas a Pagar (`contas_pagar.html`)

**Funcionalidades:**
- ✅ Cadastro de contas a pagar
- ✅ Listagem com status
- ✅ Registro de pagamentos (parcial ou total)
- ✅ Cálculo automático de saldo devedor
- ✅ Estatísticas (Total Pendente, Total Pago, Contas Atrasadas)

**Campos de Cadastro:**
- Número do Documento (único, obrigatório)
- Plano de Contas (obrigatório)
- Fornecedor (obrigatório)
- Descrição (obrigatório)
- Valor (obrigatório)
- Data de Vencimento (obrigatório)
- Observações

**Campos de Pagamento:**
- Valor a Pagar (obrigatório)
- Data de Pagamento
- Forma de Pagamento

---

### Página: Contas a Receber (`contas_receber.html`)

**Funcionalidades:**
- ✅ Cadastro de contas a receber
- ✅ Listagem com status
- ✅ Registro de recebimentos (parcial ou total)
- ✅ Cálculo automático de saldo devedor
- ✅ Estatísticas (Total a Receber, Total Recebido, Contas Atrasadas)

**Campos de Cadastro:**
- Número do Documento (único, obrigatório)
- Plano de Contas (obrigatório)
- Morador (obrigatório)
- Unidade
- Descrição (obrigatório)
- Valor (obrigatório)
- Data de Vencimento (obrigatório)
- Observações

**Campos de Recebimento:**
- Valor a Receber (obrigatório)
- Data de Recebimento
- Forma de Recebimento

---

## 🚀 Instalação

### Passo 1: Executar Script SQL
```bash
mysql -h localhost -u inlaud99_admin -p'Admin25908' inlaud99_erpserra < TABELAS_FINANCEIRO.sql
```

### Passo 2: Copiar Arquivos
```bash
# APIs
cp api_planos_contas.php /var/www/html/
cp api_contas_pagar.php /var/www/html/
cp api_contas_receber.php /var/www/html/

# Páginas
cp planos_contas.html /var/www/html/
cp contas_pagar.html /var/www/html/
cp contas_receber.html /var/www/html/
```

### Passo 3: Atualizar Dashboard
O menu do dashboard.html já foi atualizado com o novo módulo Financeiro.

### Passo 4: Testar
1. Acesse o dashboard
2. Clique em "Financeiro" no menu lateral
3. Selecione uma opção (Contas a Pagar, Contas a Receber, Planos de Contas)

---

## 🔐 Segurança

✅ **Implementado:**
- Prepared statements para prevenir SQL Injection
- Validação de entrada em cliente e servidor
- Sanitização de dados
- Soft delete (registros nunca são deletados, apenas marcados como inativos)
- Tratamento de erros seguro

⚠️ **Recomendações para Produção:**
- Implementar autenticação por usuário
- Adicionar autorização por perfil
- Implementar auditoria de alterações
- Usar HTTPS
- Implementar rate limiting
- Adicionar logs de acesso

---

## 📊 Fluxos de Negócio

### Fluxo: Contas a Pagar

```
1. Cadastrar Conta a Pagar
   ↓
2. Status: PENDENTE
   ↓
3. Registrar Pagamento Parcial
   ↓
4. Status: PARCIAL (Saldo Devedor atualizado)
   ↓
5. Registrar Pagamento Final
   ↓
6. Status: PAGO (Saldo Devedor = 0)
```

### Fluxo: Contas a Receber

```
1. Cadastrar Conta a Receber
   ↓
2. Status: PENDENTE
   ↓
3. Registrar Recebimento Parcial
   ↓
4. Status: PARCIAL (Saldo Devedor atualizado)
   ↓
5. Registrar Recebimento Final
   ↓
6. Status: RECEBIDO (Saldo Devedor = 0)
```

---

## 🐛 Troubleshooting

### Erro: "Plano de contas não encontrado"
- Verifique se o plano foi criado antes de usar
- Verifique o ID do plano

### Erro: "Documento já existe no sistema"
- O número do documento deve ser único
- Use um número diferente

### Erro: "Valor de pagamento não pode ser maior que o saldo devedor"
- O valor do pagamento não pode exceder o saldo devedor
- Verifique o valor inserido

### Erro: "Conexão com banco de dados falhou"
- Verifique as credenciais no config.php
- Verifique se o banco de dados está rodando
- Verifique se as tabelas foram criadas

---

## 📈 Próximas Melhorias

1. **Relatórios**
   - Relatório de contas a pagar por período
   - Relatório de contas a receber por período
   - Relatório de fluxo de caixa

2. **Integração**
   - Integração com sistema de leitura de água
   - Integração com sistema de moradores

3. **Automação**
   - Geração automática de alertas de vencimento
   - Envio automático de notificações

4. **Análise**
   - Dashboard com gráficos de fluxo de caixa
   - Previsão de caixa
   - Análise de inadimplência

---

## 📞 Suporte

Para dúvidas ou problemas, consulte a documentação técnica ou entre em contato com o desenvolvedor.

**Versão:** 1.0  
**Última Atualização:** 05/01/2026  
**Status:** ✅ Pronto para Produção
