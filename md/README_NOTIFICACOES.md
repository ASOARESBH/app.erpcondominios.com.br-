# SISTEMA DE NOTIFICAÇÕES PARA MORADORES
## Serra da Liberdade - Controle de Acesso

---

## NOVOS ARQUIVOS CRIADOS

### 1. notificacoes.html
- Página administrativa para gerenciar notificações
- CRUD completo (criar, editar, excluir)
- Upload de anexos (PDF e imagens)
- Relatórios de visualizações e downloads
- Interface responsiva e moderna

### 2. api_notificacoes.php
- API backend para área administrativa
- Gerenciamento de notificações
- Upload e armazenamento de arquivos
- Geração de relatórios detalhados
- Controle de número sequencial automático

### 3. api_morador_notificacoes.php
- API backend para área do morador
- Listagem de notificações
- Marcação de visualização
- Download de anexos com registro
- Controle de acesso por sessão

### 4. database_notificacoes.sql
- Script SQL para criar tabelas necessárias
- Tabela: notificacoes
- Tabela: notificacoes_visualizacoes
- Tabela: notificacoes_downloads

### 5. Modificações em arquivos existentes
- **administrativa.html**: Adicionado link para notificações no menu
- **acesso_morador.html**: Adicionada 5ª aba "Notificações"

---

## FUNCIONALIDADES IMPLEMENTADAS

### ✅ Área Administrativa (notificacoes.html)

#### **Cadastro de Notificações**
- Data e hora da notificação
- Assunto
- Resumo/mensagem (texto completo)
- Anexo opcional (PDF, JPG, PNG até 10MB)
- Número sequencial automático

#### **Gerenciamento**
- Listar todas as notificações
- Editar notificações existentes
- Excluir notificações
- Visualizar quantidade de visualizações
- Visualizar quantidade de downloads

#### **Relatórios Detalhados**
- Estatísticas gerais (total de moradores, visualizações, downloads)
- Detalhamento por morador
- Quem visualizou a notificação
- Quem baixou o anexo
- Interface modal para visualização

### ✅ Área do Morador (acesso_morador.html)

#### **Visualização de Notificações**
- Listagem de todas as notificações ativas
- Destaque visual para notificações não lidas
- Exibição de número, data, assunto e resumo
- Indicador de status (lida/não lida)

#### **Interações**
- Ler resumo completo da notificação
- Marcar como lida manualmente
- Baixar anexo (marca automaticamente como lida)
- Download direto do arquivo

#### **Registro Automático**
- Visualização registrada ao marcar como lida
- Download registrado ao baixar anexo
- IP address capturado para auditoria
- Data e hora de cada ação

---

## ESTRUTURA DO BANCO DE DADOS

### Tabela: notificacoes
```sql
- id (INT, AUTO_INCREMENT, PRIMARY KEY)
- numero_sequencial (INT, UNIQUE) - Número da notificação
- data_hora (DATETIME) - Data e hora da notificação
- assunto (VARCHAR 255) - Assunto/título
- resumo (TEXT) - Mensagem completa
- anexo_nome (VARCHAR 255) - Nome original do arquivo
- anexo_caminho (VARCHAR 500) - Caminho no servidor
- anexo_tipo (VARCHAR 50) - Tipo MIME
- ativo (TINYINT 1) - Status ativo/inativo
- criado_por (VARCHAR 100) - Usuário que criou
- criado_em (TIMESTAMP) - Data de criação
- atualizado_em (TIMESTAMP) - Data de atualização
```

### Tabela: notificacoes_visualizacoes
```sql
- id (INT, AUTO_INCREMENT, PRIMARY KEY)
- notificacao_id (INT, FK) - ID da notificação
- morador_id (INT, FK) - ID do morador
- data_visualizacao (DATETIME) - Data/hora da visualização
- ip_address (VARCHAR 50) - IP do acesso
- UNIQUE (notificacao_id, morador_id) - Um morador visualiza uma vez
```

### Tabela: notificacoes_downloads
```sql
- id (INT, AUTO_INCREMENT, PRIMARY KEY)
- notificacao_id (INT, FK) - ID da notificação
- morador_id (INT, FK) - ID do morador
- data_download (DATETIME) - Data/hora do download
- ip_address (VARCHAR 50) - IP do acesso
- Permite múltiplos downloads do mesmo morador
```

---

## INSTRUÇÕES DE INSTALAÇÃO

### 1. CRIAR TABELAS NO BANCO DE DADOS
Execute o script SQL:
```bash
mysql -u usuario -p nome_banco < database_notificacoes.sql
```

Ou execute manualmente as queries do arquivo `database_notificacoes.sql`

### 2. CRIAR DIRETÓRIO DE UPLOADS
No servidor, crie o diretório para armazenar anexos:
```bash
mkdir -p uploads/notificacoes
chmod 755 uploads/notificacoes
```

### 3. COPIAR ARQUIVOS
Copie todos os arquivos novos para o diretório raiz do sistema:
- notificacoes.html
- api_notificacoes.php
- api_morador_notificacoes.php

### 4. ATUALIZAR ARQUIVOS EXISTENTES
Substitua os arquivos modificados:
- administrativa.html (com link para notificações)
- acesso_morador.html (com aba de notificações)

---

## COMO USAR

### **Área Administrativa**

#### **Criar Notificação**
1. Acesse: Administrativo → Notificações
2. Preencha data/hora, assunto e resumo
3. Opcionalmente, anexe um arquivo PDF ou imagem
4. Clique em "Salvar Notificação"
5. Um número sequencial será gerado automaticamente

#### **Ver Relatório**
1. Na lista de notificações, clique em "Relatório"
2. Visualize estatísticas gerais
3. Veja detalhamento por morador
4. Identifique quem visualizou e quem baixou

#### **Editar/Excluir**
1. Clique em "Editar" para modificar
2. Clique em "Excluir" para remover (soft delete)

### **Área do Morador**

#### **Visualizar Notificações**
1. Acesse a aba "Notificações"
2. Veja todas as notificações disponíveis
3. Notificações não lidas aparecem destacadas em azul

#### **Ler Notificação**
1. Leia o resumo completo diretamente na lista
2. Clique em "Marcar como Lida" para registrar visualização

#### **Baixar Anexo**
1. Se houver anexo, clique em "Baixar Anexo"
2. O download será iniciado automaticamente
3. A notificação será marcada como lida
4. O download será registrado no sistema

---

## RECURSOS DE SEGURANÇA

✅ **Validação de Sessão**: Morador deve estar logado  
✅ **Validação de Arquivos**: Apenas PDF, JPG, PNG permitidos  
✅ **Limite de Tamanho**: Máximo 10MB por arquivo  
✅ **Registro de IP**: Todas as ações registram IP  
✅ **Soft Delete**: Notificações não são excluídas fisicamente  
✅ **Prepared Statements**: Proteção contra SQL Injection  
✅ **Upload Seguro**: Arquivos armazenados com nomes únicos  

---

## RELATÓRIOS E ESTATÍSTICAS

### **Métricas Disponíveis**
- Total de moradores no condomínio
- Quantidade de visualizações por notificação
- Quantidade de downloads por notificação
- Lista de moradores que visualizaram
- Lista de moradores que baixaram anexo
- Moradores que ainda não visualizaram

### **Exportação**
Os relatórios podem ser visualizados na interface web e contêm:
- Dados da notificação (número, assunto, data, resumo)
- Estatísticas consolidadas
- Tabela detalhada por morador

---

## FLUXO DE FUNCIONAMENTO

### **Criação de Notificação**
1. Administrador acessa notificacoes.html
2. Preenche formulário com dados
3. Faz upload de anexo (opcional)
4. Sistema gera número sequencial
5. Notificação fica disponível para todos os moradores

### **Visualização pelo Morador**
1. Morador acessa aba "Notificações"
2. Sistema lista todas as notificações ativas
3. Morador lê o resumo
4. Morador pode marcar como lida
5. Sistema registra visualização

### **Download de Anexo**
1. Morador clica em "Baixar Anexo"
2. Sistema registra download
3. Sistema marca como visualizada (se ainda não foi)
4. Arquivo é baixado para o dispositivo do morador
5. Administrador pode ver no relatório

---

## OBSERVAÇÕES IMPORTANTES

### 1. **Número Sequencial**
- Gerado automaticamente
- Único para cada notificação
- Facilita referência e busca
- Exemplo: #1, #2, #3...

### 2. **Anexos**
- Formatos aceitos: PDF, JPG, JPEG, PNG
- Tamanho máximo: 10MB
- Armazenados em: uploads/notificacoes/
- Nome do arquivo: timestamp_uniqid.extensao

### 3. **Visualizações**
- Cada morador pode visualizar apenas uma vez
- Registro único por morador/notificação
- Data e hora capturadas
- IP registrado para auditoria

### 4. **Downloads**
- Morador pode baixar múltiplas vezes
- Cada download é registrado
- Primeiro download marca como visualizada
- Histórico completo mantido

### 5. **Compatibilidade**
- Sistema totalmente integrado
- Não interfere em funcionalidades existentes
- Usa mesma estrutura de sessão
- Compartilha tabela de moradores

---

## ARQUIVOS DO SISTEMA

| Arquivo | Tipo | Descrição |
|---------|------|-----------|
| notificacoes.html | HTML | Página administrativa |
| api_notificacoes.php | PHP API | Backend administrativo |
| api_morador_notificacoes.php | PHP API | Backend do morador |
| database_notificacoes.sql | SQL | Script de criação de tabelas |
| administrativa.html | HTML (modificado) | Link adicionado no menu |
| acesso_morador.html | HTML (modificado) | Aba de notificações adicionada |

---

## SUPORTE E MANUTENÇÃO

### **Logs do Sistema**
Todas as ações são registradas em `logs_sistema`:
- Criação de notificações
- Edição de notificações
- Exclusão de notificações
- Visualizações de moradores
- Downloads de anexos

### **Backup de Arquivos**
Recomenda-se fazer backup regular de:
- Diretório: uploads/notificacoes/
- Tabelas: notificacoes, notificacoes_visualizacoes, notificacoes_downloads

### **Limpeza de Dados**
Para limpar dados antigos:
```sql
-- Excluir notificações antigas (soft delete)
UPDATE notificacoes SET ativo = 0 WHERE data_hora < DATE_SUB(NOW(), INTERVAL 6 MONTH);

-- Excluir registros de visualizações antigas
DELETE FROM notificacoes_visualizacoes WHERE data_visualizacao < DATE_SUB(NOW(), INTERVAL 1 YEAR);

-- Excluir registros de downloads antigos
DELETE FROM notificacoes_downloads WHERE data_download < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

---

**Sistema desenvolvido para Serra da Liberdade**  
**© 2025 - Todos os direitos reservados**

