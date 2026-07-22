# Módulo de Inventário de Patrimônio

## ERP Condomínio — Gestão Inteligente - ERP Condomínio

**Data:** 20 de outubro de 2025  
**Versão:** 1.0

---

## 📋 Índice

1. [Visão Geral](#visão-geral)
2. [Estrutura do Banco de Dados](#estrutura-do-banco-de-dados)
3. [Arquivos do Módulo](#arquivos-do-módulo)
4. [Funcionalidades](#funcionalidades)
5. [Instalação](#instalação)
6. [Uso do Sistema](#uso-do-sistema)
7. [API Endpoints](#api-endpoints)
8. [Campos e Validações](#campos-e-validações)

---

## 📖 Visão Geral

O **Módulo de Inventário** é uma solução completa para gerenciamento de patrimônio do condomínio ERP Condomínio. Permite cadastro, controle, busca avançada e geração de relatórios de todos os bens do condomínio.

### Principais Recursos

- ✅ Cadastro completo de patrimônio
- ✅ Controle de responsáveis (tutela)
- ✅ Registro de baixas com motivo
- ✅ Busca avançada com múltiplos filtros
- ✅ Relatórios gerenciais
- ✅ Controle de situação contábil (imobilizado/circulante)
- ✅ Histórico de alterações via logs

---

## 🗄️ Estrutura do Banco de Dados

### Tabela: `inventario`

```sql
CREATE TABLE IF NOT EXISTS inventario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_patrimonio VARCHAR(50) NOT NULL UNIQUE,
    nome_item VARCHAR(255) NOT NULL,
    fabricante VARCHAR(100),
    modelo VARCHAR(100),
    numero_serie VARCHAR(100),
    nf VARCHAR(50),
    data_compra DATE,
    situacao ENUM('imobilizado', 'circulante') NOT NULL DEFAULT 'imobilizado',
    valor DECIMAL(10, 2),
    status ENUM('ativo', 'inativo') NOT NULL DEFAULT 'ativo',
    motivo_baixa TEXT,
    data_baixa DATE,
    tutela_usuario_id INT,
    observacoes TEXT,
    data_cadastro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_numero_patrimonio (numero_patrimonio),
    INDEX idx_nf (nf),
    INDEX idx_situacao (situacao),
    INDEX idx_status (status),
    INDEX idx_tutela (tutela_usuario_id),
    
    FOREIGN KEY (tutela_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);
```

### Relacionamentos

- **tutela_usuario_id** → **usuarios(id)**
  - Relacionamento com tabela de usuários
  - `ON DELETE SET NULL`: Se o usuário for excluído, o campo fica NULL

---

## 📁 Arquivos do Módulo

### 1. **database_inventario.sql**
Estrutura do banco de dados com:
- Criação da tabela `inventario`
- Índices para otimização
- Dados de exemplo
- Comentários explicativos

### 2. **api_inventario.php**
API REST completa com:
- **GET**: Listar inventário (com filtros)
- **POST**: Criar novo item
- **PUT**: Atualizar item existente
- **DELETE**: Excluir item

### 3. **inventario.html**
Interface principal com:
- Formulário de cadastro
- Sistema de busca avançada
- Tabela de listagem
- Edição e exclusão

### 4. **relatorios_inventario.html**
Página de relatórios com:
- Cards de resumo (totais)
- Filtros avançados
- 5 tipos de relatórios
- Exportação PDF/Excel (preparado)

### 5. **administrativa.html**
Página inicial atualizada com:
- Card de acesso ao inventário
- Submenu com inventário
- Layout integrado

---

## ⚙️ Funcionalidades

### 1. Cadastro de Patrimônio

**Campos Obrigatórios:**
- Número de Patrimônio (código da etiqueta)
- Nome do Item
- Situação (imobilizado/circulante)
- Status (ativo/inativo)

**Campos Opcionais:**
- Fabricante
- Modelo
- Número de Série
- NF (Nota Fiscal)
- Data de Compra
- Valor
- Tutela (Responsável)
- Observações

**Campos Condicionais:**
- **Motivo de Baixa** (obrigatório se status = inativo)
- **Data de Baixa** (opcional se status = inativo)

### 2. Busca Avançada

Filtros disponíveis:
- Número de Patrimônio (busca parcial)
- NF (busca parcial)
- Situação (imobilizado/circulante)
- Status (ativo/inativo)
- Tutela/Responsável

### 3. Relatórios

**Tipos de Relatórios:**

1. **Relatório Geral**
   - Lista todos os itens com filtros aplicados
   - Exibe: patrimônio, nome, fabricante, situação, status, valor, responsável

2. **Relatório por Situação**
   - Filtra por imobilizado ou circulante
   - Útil para controle contábil

3. **Relatório por Status**
   - Filtra por ativo ou inativo
   - Útil para inventário físico

4. **Relatório por Responsável**
   - Filtra por usuário responsável
   - Útil para controle de tutela

5. **Relatório de Baixas**
   - Lista apenas itens inativos
   - Exibe motivo e data de baixa
   - Útil para auditoria

**Cards de Resumo:**
- Total de Itens
- Total de Ativos
- Total de Inativos
- Valor Total (R$)

---

## 🔧 Instalação

### Passo 1: Criar Tabela no Banco de Dados

```bash
# Acesse o MySQL via phpMyAdmin ou terminal
mysql -u seu_usuario -p nome_banco < database_inventario.sql
```

Ou execute manualmente no phpMyAdmin:
1. Acesse phpMyAdmin
2. Selecione o banco de dados
3. Vá em "SQL"
4. Cole o conteúdo de `database_inventario.sql`
5. Clique em "Executar"

### Passo 2: Fazer Upload dos Arquivos

Via FTP/SFTP ou Gerenciador de Arquivos do cPanel:

```
/public_html/
├── api_inventario.php
├── inventario.html
├── relatorios_inventario.html
└── administrativa.html (substituir)
```

### Passo 3: Verificar Permissões

Certifique-se de que os arquivos têm permissão de leitura:
```bash
chmod 644 api_inventario.php
chmod 644 inventario.html
chmod 644 relatorios_inventario.html
chmod 644 administrativa.html
```

### Passo 4: Testar o Sistema

1. Acesse `administrativa.html`
2. Clique em "Acessar Inventário"
3. Cadastre um item de teste
4. Verifique se aparece na lista
5. Teste a busca e os relatórios

---

## 📖 Uso do Sistema

### Cadastrar Novo Item

1. Acesse **Inventário** no menu Administrativo
2. Preencha os campos obrigatórios:
   - Número de Patrimônio (ex: PAT-001)
   - Nome do Item (ex: Notebook Dell)
   - Situação (Imobilizado ou Circulante)
   - Status (Ativo ou Inativo)
3. Preencha os campos opcionais conforme necessário
4. Se Status = Inativo, preencha o **Motivo de Baixa**
5. Clique em **Salvar Item**

### Buscar Item

1. Use os filtros na seção "Buscar Patrimônio"
2. Preencha um ou mais campos de filtro
3. Clique em **Buscar**
4. Para limpar os filtros, clique em **Limpar Filtros**

### Editar Item

1. Na lista de patrimônio, clique no botão **Editar** (ícone de lápis)
2. Os dados serão carregados no formulário
3. Faça as alterações necessárias
4. Clique em **Atualizar Item**

### Excluir Item

1. Na lista de patrimônio, clique no botão **Excluir** (ícone de lixeira)
2. Confirme a exclusão
3. O item será removido permanentemente

### Gerar Relatórios

1. Acesse **Relatórios** no submenu
2. Selecione o tipo de relatório
3. Aplique os filtros desejados
4. Visualize os resultados na tabela
5. (Futuro) Clique em **Gerar PDF** ou **Exportar Excel**

---

## 🔌 API Endpoints

### GET - Listar Inventário

**Endpoint:** `api_inventario.php`

**Parâmetros (query string):**
- `numero_patrimonio` (string): Busca parcial
- `nf` (string): Busca parcial
- `situacao` (enum): 'imobilizado' ou 'circulante'
- `status` (enum): 'ativo' ou 'inativo'
- `tutela` (int): ID do usuário responsável

**Exemplo:**
```
GET api_inventario.php?status=ativo&situacao=imobilizado
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Inventário listado com sucesso",
  "dados": [
    {
      "id": 1,
      "numero_patrimonio": "PAT-001",
      "nome_item": "Notebook Dell",
      "fabricante": "Dell",
      "modelo": "Inspiron 15",
      "situacao": "imobilizado",
      "valor": "3500.00",
      "status": "ativo",
      "tutela_nome": "João Silva"
    }
  ]
}
```

### POST - Criar Item

**Endpoint:** `api_inventario.php`

**Body (JSON):**
```json
{
  "numero_patrimonio": "PAT-005",
  "nome_item": "Cadeira Ergonômica",
  "fabricante": "Flexform",
  "modelo": "Presidente Premium",
  "situacao": "circulante",
  "valor": 850.00,
  "status": "ativo",
  "tutela_usuario_id": 1
}
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Item cadastrado com sucesso",
  "dados": {
    "id": 5
  }
}
```

### PUT - Atualizar Item

**Endpoint:** `api_inventario.php`

**Body (JSON):**
```json
{
  "id": 5,
  "numero_patrimonio": "PAT-005",
  "nome_item": "Cadeira Ergonômica Premium",
  "status": "inativo",
  "motivo_baixa": "Cadeira com defeito no encosto",
  "data_baixa": "2025-10-20"
}
```

### DELETE - Excluir Item

**Endpoint:** `api_inventario.php`

**Body (JSON):**
```json
{
  "id": 5
}
```

---

## ✅ Campos e Validações

### Campos Obrigatórios

| Campo | Tipo | Validação |
|-------|------|-----------|
| numero_patrimonio | VARCHAR(50) | Único, não vazio |
| nome_item | VARCHAR(255) | Não vazio |
| situacao | ENUM | 'imobilizado' ou 'circulante' |
| status | ENUM | 'ativo' ou 'inativo' |

### Validação Condicional

- **Se status = 'inativo':**
  - `motivo_baixa` é **obrigatório**
  - `data_baixa` é opcional

### Campos com Relacionamento

- **tutela_usuario_id:**
  - FK para `usuarios(id)`
  - Pode ser NULL (sem responsável)
  - ON DELETE SET NULL

---

## 🎨 Interface do Usuário

### Cores e Badges

**Situação:**
- **Imobilizado**: Badge azul (#dbeafe)
- **Circulante**: Badge amarelo (#fef3c7)

**Status:**
- **Ativo**: Badge verde (#dcfce7)
- **Inativo**: Badge vermelho (#fee2e2)

### Responsividade

- **Desktop** (1920px+): Grid com múltiplas colunas
- **Tablet** (768px - 1024px): Grid adaptado
- **Mobile** (320px - 767px): Layout em coluna única

---

## 📊 Logs de Auditoria

Todas as operações são registradas na tabela `logs_sistema`:

- `INVENTARIO_CRIADO`: Item cadastrado
- `INVENTARIO_ATUALIZADO`: Item atualizado
- `INVENTARIO_EXCLUIDO`: Item excluído

**Exemplo de log:**
```
Tipo: INVENTARIO_CRIADO
Descrição: Item de inventário criado: Notebook Dell (Patrimônio: PAT-001)
Usuário: Sistema
Data: 2025-10-20 18:30:45
```

---

## 🔒 Segurança

### Proteções Implementadas

1. **SQL Injection:**
   - Uso de prepared statements
   - Sanitização com `real_escape_string()`

2. **Validação de Dados:**
   - Validação no backend (PHP)
   - Validação no frontend (HTML5 + JS)

3. **Integridade Referencial:**
   - Foreign Keys com ON DELETE SET NULL
   - Verificação de duplicatas

4. **Logs de Auditoria:**
   - Registro de todas as operações
   - Rastreabilidade completa

---

## 🚀 Melhorias Futuras

### Funcionalidades Planejadas

- [ ] Geração de PDF com jsPDF
- [ ] Exportação para Excel com SheetJS
- [ ] Upload de fotos do patrimônio
- [ ] QR Code para cada item
- [ ] Histórico de manutenções
- [ ] Agendamento de inventário físico
- [ ] Notificações de vencimento de garantia
- [ ] Dashboard com gráficos
- [ ] Integração com sistema contábil

---

## 📞 Suporte

Para dúvidas ou problemas:
1. Verifique a documentação
2. Consulte os logs do sistema
3. Entre em contato com o suporte técnico

---

## 📝 Changelog

### Versão 1.0 (20/10/2025)
- ✅ Criação do módulo de inventário
- ✅ Cadastro completo de patrimônio
- ✅ Busca avançada com filtros
- ✅ Relatórios gerenciais
- ✅ Controle de responsáveis
- ✅ Registro de baixas
- ✅ Integração com módulo administrativo

---

**ERP Condomínio — Gestão Inteligente - ERP Condomínio**  
Módulo de Inventário v1.0  
© 2025 - Todos os direitos reservados

