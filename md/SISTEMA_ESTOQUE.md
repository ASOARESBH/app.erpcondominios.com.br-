# Sistema de Gestão de Estoque - ERP Condomínio

## 📦 Módulos Criados

### 1. database_estoque.sql
- 4 tabelas principais
- Views para relatórios
- Triggers automáticos
- Stored procedures
- Dados de exemplo

### 2. api_estoque.php
- 20+ endpoints REST
- CRUD completo de produtos
- Entrada e saída de estoque
- Dashboard com estatísticas
- Relatórios diversos

### 3. estoque.html (Dashboard Principal)
- Cards de resumo
- Listagem de produtos
- CRUD completo
- Busca e filtros
- Alertas de estoque baixo

### 4. entrada_estoque.html
- Busca de produto
- Registro de entrada
- Nota fiscal
- Histórico

### 5. saida_estoque.html
- Busca de produto
- Seleção de destino (Morador/Administração)
- Vínculo com morador
- Histórico

### 6. relatorio_estoque.html
- Relatório de movimentação
- Relatório por morador
- Filtros por período
- Gráficos (Chart.js)

## 🎁 Funcionalidades Extras

✨ Código automático incremental
✨ Estoque mínimo/máximo
✨ Alertas automáticos
✨ Histórico completo
✨ Categorias de produtos
✨ Localização física
✨ Valor total do estoque
✨ Custo por morador
✨ Dashboard com gráficos
✨ Exportação de relatórios

## 📊 Estatísticas do Dashboard

- Total de produtos
- Valor total do estoque
- Produtos com estoque baixo
- Produtos zerados
- Movimentações do mês
- Entradas/Saídas do mês
- Produtos mais movimentados
- Alertas não lidos

## 🔧 Instalação

1. Executar database_estoque.sql
2. Upload de api_estoque.php
3. Upload dos 4 arquivos HTML
4. Atualizar administrativa.html

## 📝 Uso

### Cadastrar Produto
1. Acessar estoque.html
2. Clicar em "Novo Produto"
3. Preencher dados
4. Salvar

### Entrada de Estoque
1. Acessar entrada_estoque.html
2. Buscar produto
3. Informar quantidade
4. Registrar entrada

### Saída de Estoque
1. Acessar saida_estoque.html
2. Buscar produto
3. Selecionar destino
4. Se Morador → Selecionar morador
5. Registrar saída

### Relatórios
1. Acessar relatorio_estoque.html
2. Selecionar tipo de relatório
3. Definir período
4. Gerar relatório
5. Exportar (PDF/Excel)

## 🎨 Design

- Segue padrão administrativa.html
- Responsivo (desktop, tablet, mobile)
- Cards coloridos por categoria
- Badges de status
- Gráficos interativos
- Alertas visuais

## 🔒 Segurança

- Validação de dados
- SQL Injection prevention
- Logs de auditoria
- Controle de permissões

## 📈 Relatórios Disponíveis

1. **Movimentação por Período**
   - Entradas e saídas
   - Filtro por tipo
   - Valor total

2. **Consumo por Morador**
   - Total de retiradas
   - Quantidade total
   - Valor total

3. **Produtos com Estoque Baixo**
   - Alerta de reposição
   - Valor de reposição

4. **Histórico de Movimentações**
   - Todas as movimentações
   - Filtros diversos

## 🚀 Próximas Melhorias (Opcional)

- [ ] Código de barras
- [ ] Leitura de QR Code
- [ ] Notificações por email
- [ ] Integração com fornecedores
- [ ] Previsão de consumo
- [ ] App mobile
- [ ] Dashboard em tempo real
- [ ] Exportação automática

---

**Sistema completo e pronto para uso!** 🎉
