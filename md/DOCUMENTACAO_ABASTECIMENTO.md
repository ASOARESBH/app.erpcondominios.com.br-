# Módulo de Controle de Abastecimento

## Descrição Geral

O módulo de Controle de Abastecimento foi desenvolvido para gerenciar o abastecimento dos veículos do condomínio Serra da Liberdade. O sistema oferece controle completo sobre cadastro de veículos, lançamento de abastecimentos, sistema de crédito/débito (recargas) e relatórios detalhados de consumo.

---

## Funcionalidades Principais

### 1. Cadastro de Veículos

**Objetivo:** Registrar os veículos que serão controlados pelo sistema.

**Campos do Cadastro:**
- **Placa do Veículo** (obrigatório): Aceita formato antigo (ABC-1234) ou Mercosul (ABC1D23)
- **Modelo do Veículo** (obrigatório): Ex: Fiat Uno, Volkswagen Gol
- **Ano** (obrigatório): Ano de fabricação do veículo
- **Cor** (obrigatório): Cor do veículo
- **KM Atual** (obrigatório): Quilometragem inicial do veículo no momento do cadastro

**Regras Importantes:**
- ✅ A placa é validada automaticamente (formato antigo ou Mercosul)
- ✅ Após cadastrar, **NÃO é possível editar** os dados do veículo
- ✅ O KM inicial registrado não pode ser alterado posteriormente
- ✅ A placa é única no sistema (não permite duplicatas)

**Ações Disponíveis:**
- Cadastrar novo veículo
- Visualizar detalhes do veículo (estatísticas de abastecimento)

---

### 2. Lançamento de Abastecimento

**Objetivo:** Registrar cada abastecimento realizado nos veículos.

**Campos do Lançamento:**
- **Veículo** (obrigatório): Selecionar o veículo abastecido
- **Data e Hora** (obrigatório): Momento do abastecimento
- **KM Atual** (obrigatório): Quilometragem no momento do abastecimento
- **Litros** (obrigatório): Quantidade de combustível abastecida
- **Valor (R$)** (obrigatório): Valor total pago no abastecimento
- **Tipo de Combustível** (obrigatório): Gasolina, Álcool ou Diesel
- **Operador** (obrigatório): Usuário responsável pelo abastecimento

**Regras Importantes:**
- ✅ O KM informado deve ser **maior ou igual** ao último KM registrado para o veículo
- ✅ O sistema exibe o último KM registrado como referência
- ✅ Para lançar abastecimento, é necessário ter **saldo disponível** no sistema
- ✅ Se o saldo for insuficiente, o sistema alerta e permite continuar (saldo negativo)
- ✅ O sistema registra automaticamente o **usuário logado** no momento do lançamento (log de auditoria)
- ✅ O valor do abastecimento é **debitado automaticamente** do saldo

**Histórico:**
- Todos os abastecimentos são listados em ordem cronológica decrescente
- Exibe: Data/Hora, Veículo, KM, Litros, Valor, Combustível, Operador e Usuário Logado

---

### 3. Sistema de Recarga (Crédito/Débito)

**Objetivo:** Controlar o saldo disponível para lançamento de abastecimentos.

**Funcionamento:**
- O sistema funciona como uma **conta de crédito/débito**
- Para lançar abastecimentos, é necessário ter saldo
- Cada recarga adiciona crédito ao sistema
- Cada abastecimento debita do saldo

**Campos da Recarga:**
- **Data e Hora** (obrigatório): Momento da recarga
- **Valor da Recarga (R$)** (obrigatório): Valor a ser creditado
- **Valor Mínimo para Alerta (R$)** (obrigatório): Quando o saldo atingir este valor, o sistema alertará
- **Número da NF** (opcional): Número da nota fiscal da recarga

**Regras Importantes:**
- ✅ O saldo é atualizado automaticamente após cada recarga
- ✅ O sistema exibe o **saldo após a recarga**
- ✅ O valor mínimo define quando o sistema deve alertar sobre saldo baixo
- ✅ É possível lançar abastecimentos mesmo com saldo negativo (até a próxima recarga)
- ✅ O sistema registra o usuário que realizou a recarga

**Display de Saldo:**
- **Verde:** Saldo positivo acima do valor mínimo
- **Amarelo:** Saldo abaixo do valor mínimo (alerta)
- **Vermelho:** Saldo negativo

---

### 4. Relatórios

**Objetivo:** Gerar relatórios detalhados de consumo, gastos e desempenho dos veículos.

**Filtros Disponíveis:**
- **Veículo:** Filtrar por veículo específico ou todos
- **Data Início:** Data inicial do período
- **Data Fim:** Data final do período
- **Combustível:** Filtrar por tipo de combustível

**Informações do Relatório:**

**Resumo Geral:**
- Total de Abastecimentos
- Total em Litros
- Total Gasto (R$)
- Média de Consumo (km/L)
- Preço Médio por Litro (R$/L)

**Tabela Detalhada:**
- Data/Hora do abastecimento
- Veículo e Placa
- KM registrado
- KM Rodado (desde o último abastecimento)
- Litros abastecidos
- Valor pago
- Preço por litro (R$/L)
- Consumo calculado (km/L)
- Tipo de combustível
- Operador responsável

**Cálculos Automáticos:**
- **KM Rodado:** Diferença entre o KM atual e o KM do abastecimento anterior
- **Consumo (km/L):** KM rodado dividido pelos litros do abastecimento anterior
- **Preço por Litro:** Valor total dividido pelos litros

**Exportação:**
- Função de exportação para Excel (a ser implementada no backend)

---

## Estrutura de Arquivos

### Arquivos HTML
- **abastecimento.html**: Interface principal do módulo com todas as funcionalidades

### Arquivos PHP
- **api_abastecimento.php**: API REST para gerenciar todas as operações do módulo

### Arquivos SQL
- **sql_abastecimento.sql**: Script de criação das tabelas do banco de dados

### Arquivos de Configuração
- **config.php**: Configuração de conexão com banco de dados (já existente no sistema)

---

## Estrutura do Banco de Dados

### Tabela: `abastecimento_veiculos`
Armazena os veículos cadastrados.

**Campos:**
- `id` (INT, AUTO_INCREMENT, PRIMARY KEY)
- `placa` (VARCHAR(8), UNIQUE, NOT NULL)
- `modelo` (VARCHAR(100), NOT NULL)
- `ano` (INT(4), NOT NULL)
- `cor` (VARCHAR(50), NOT NULL)
- `km_inicial` (INT(11), NOT NULL)
- `data_cadastro` (DATETIME, NOT NULL)

### Tabela: `abastecimento_lancamentos`
Registra cada abastecimento realizado.

**Campos:**
- `id` (INT, AUTO_INCREMENT, PRIMARY KEY)
- `veiculo_id` (INT, NOT NULL, FOREIGN KEY)
- `data_abastecimento` (DATETIME, NOT NULL)
- `km_abastecimento` (INT(11), NOT NULL)
- `litros` (DECIMAL(10,2), NOT NULL)
- `valor` (DECIMAL(10,2), NOT NULL)
- `tipo_combustivel` (ENUM: 'Gasolina', 'Álcool', 'Diesel', NOT NULL)
- `operador_id` (INT, NOT NULL, FOREIGN KEY)
- `usuario_logado` (VARCHAR(100), NOT NULL)
- `data_registro` (DATETIME, NOT NULL)

### Tabela: `abastecimento_recargas`
Registra as recargas de saldo.

**Campos:**
- `id` (INT, AUTO_INCREMENT, PRIMARY KEY)
- `data_recarga` (DATETIME, NOT NULL)
- `valor_recarga` (DECIMAL(10,2), NOT NULL)
- `valor_minimo` (DECIMAL(10,2), NOT NULL)
- `nf` (VARCHAR(50), NULL)
- `saldo_apos` (DECIMAL(10,2), NOT NULL)
- `usuario_id` (INT, NOT NULL, FOREIGN KEY)
- `data_registro` (DATETIME, NOT NULL)

### Tabela: `abastecimento_saldo`
Mantém o saldo atual do sistema (registro único).

**Campos:**
- `id` (INT, PRIMARY KEY, DEFAULT 1)
- `valor` (DECIMAL(10,2), NOT NULL, DEFAULT 0.00)
- `valor_minimo` (DECIMAL(10,2), NOT NULL, DEFAULT 0.00)
- `data_atualizacao` (DATETIME, NOT NULL)

---

## Instalação

### Passo 1: Criar as Tabelas
Execute o arquivo `sql_abastecimento.sql` no banco de dados:

```sql
-- Via phpMyAdmin ou linha de comando
mysql -u usuario -p nome_banco < sql_abastecimento.sql
```

### Passo 2: Verificar Permissões
Certifique-se de que o usuário do banco de dados tem permissões para:
- CREATE TABLE
- INSERT, UPDATE, DELETE, SELECT
- CREATE VIEW (para as views opcionais)

### Passo 3: Configurar Conexão
O módulo utiliza o arquivo `config.php` existente no sistema. Verifique se as configurações estão corretas:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'inlaud99_erpserra');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### Passo 4: Adicionar ao Menu
O arquivo `manutencao.html` já foi atualizado com o link para o módulo de abastecimento.

### Passo 5: Testar
1. Acesse `manutencao.html`
2. Clique em "Abastecimento"
3. Cadastre um veículo de teste
4. Faça uma recarga inicial
5. Registre um abastecimento
6. Gere um relatório

---

## Segurança

### Autenticação
- ✅ Todas as páginas são protegidas por `auth-guard.js`
- ✅ Apenas usuários logados podem acessar o módulo
- ✅ A API verifica a sessão do usuário antes de processar requisições

### Auditoria
- ✅ Todos os lançamentos registram o **usuário logado** no momento
- ✅ Todas as recargas registram o **usuário responsável**
- ✅ Histórico completo de todas as operações

### Validações
- ✅ Validação de placa (formato antigo e Mercosul)
- ✅ Validação de KM (não pode ser menor que o último registrado)
- ✅ Validação de valores numéricos
- ✅ Proteção contra SQL Injection (prepared statements)
- ✅ Proteção contra duplicatas (placa única)

---

## Fluxo de Uso Recomendado

### 1. Configuração Inicial
1. Cadastrar todos os veículos do condomínio
2. Fazer uma recarga inicial no sistema
3. Definir o valor mínimo para alerta

### 2. Operação Diária
1. Ao abastecer um veículo, acessar "Lançamento"
2. Selecionar o veículo
3. Preencher os dados do abastecimento
4. Salvar (o saldo será debitado automaticamente)

### 3. Recargas
1. Quando o saldo estiver baixo (alerta amarelo/vermelho)
2. Acessar "Recarga"
3. Registrar nova recarga com NF (se disponível)
4. O saldo será creditado automaticamente

### 4. Relatórios
1. Acessar "Relatórios"
2. Aplicar filtros desejados (veículo, período, combustível)
3. Gerar relatório
4. Analisar consumo, gastos e desempenho
5. Exportar para Excel (se necessário)

---

## Manutenção e Suporte

### Logs
- Todos os erros são registrados no log do PHP
- Verifique o arquivo de log em caso de problemas

### Backup
- Faça backup regular das tabelas:
  - `abastecimento_veiculos`
  - `abastecimento_lancamentos`
  - `abastecimento_recargas`
  - `abastecimento_saldo`

### Performance
- As tabelas possuem índices otimizados para consultas rápidas
- Views opcionais disponíveis para relatórios complexos

---

## Melhorias Futuras (Sugestões)

1. **Exportação de Relatórios:** Implementar exportação para Excel/PDF
2. **Gráficos:** Adicionar gráficos de consumo e gastos
3. **Alertas Automáticos:** Notificações quando o saldo atingir o mínimo
4. **Manutenção de Veículos:** Integrar com sistema de manutenção preventiva
5. **Comparativo de Postos:** Registrar posto de abastecimento para comparação de preços
6. **App Mobile:** Versão mobile para lançamento rápido
7. **Fotos de Comprovantes:** Upload de fotos de notas fiscais

---

## Contato e Suporte

Para dúvidas ou suporte técnico, entre em contato com a equipe de desenvolvimento.

**Desenvolvido para:** Serra da Liberdade  
**Data:** Novembro 2025  
**Versão:** 1.0
