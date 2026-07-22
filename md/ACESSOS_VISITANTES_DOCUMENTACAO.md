# 🎫 Sistema de Acessos de Visitantes com QR Code

## 🎯 Funcionalidade Implementada

Sistema completo de gerenciamento de acessos temporários para visitantes com geração de QR Code para uso nas cancelas do condomínio.

---

## 📋 Visão Geral

### **Objetivo**
Permitir o cadastro de períodos de permanência para visitantes, definindo tipos de acesso específicos e gerando QR Codes únicos para controle automatizado nas cancelas.

### **Componentes**
1. **Banco de Dados** - Tabela `acessos_visitantes`
2. **API REST** - `api_acessos_visitantes.php`
3. **Interface Web** - Aba "Acessos" em `visitantes.html`
4. **QR Code** - Geração e validação automática

---

## 🗄️ Estrutura do Banco de Dados

### **Tabela: acessos_visitantes**

```sql
CREATE TABLE `acessos_visitantes` (
  `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
  `visitante_id` INT(11) NOT NULL,
  `data_inicial` DATE NOT NULL,
  `data_final` DATE NOT NULL,
  `dias_permanencia` INT(11) NOT NULL,
  `tipo_acesso` ENUM('portaria', 'externo', 'lagoa') NOT NULL,
  `qr_code` VARCHAR(255) NOT NULL UNIQUE,
  `qr_code_imagem` TEXT NULL,
  `ativo` TINYINT(1) DEFAULT 1,
  `data_cadastro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `data_atualizacao` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`visitante_id`) REFERENCES `visitantes` (`id`) ON DELETE CASCADE
);
```

### **Campos Principais**

| Campo | Tipo | Descrição |
|-------|------|-----------|
| **visitante_id** | INT | ID do visitante (FK) |
| **data_inicial** | DATE | Data de início do acesso |
| **data_final** | DATE | Data de término do acesso |
| **dias_permanencia** | INT | Dias calculados automaticamente |
| **tipo_acesso** | ENUM | portaria, externo ou lagoa |
| **qr_code** | VARCHAR | Código único para validação |
| **qr_code_imagem** | TEXT | QR Code em base64 |
| **ativo** | TINYINT | 1=Ativo, 0=Inativo |

---

## 🔌 API REST

### **Endpoint Base**
```
api_acessos_visitantes.php
```

### **Métodos Disponíveis**

#### **1. Listar Acessos**
```http
GET /api_acessos_visitantes.php
GET /api_acessos_visitantes.php?visitante_id=123
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Acessos obtidos com sucesso",
  "dados": [
    {
      "id": 1,
      "visitante_id": 123,
      "visitante_nome": "João Silva",
      "documento": "123.456.789-00",
      "data_inicial": "2024-12-18",
      "data_final": "2024-12-25",
      "dias_permanencia": 8,
      "tipo_acesso": "portaria",
      "qr_code": "ACESSO-ABC123-1702900000",
      "ativo": 1
    }
  ]
}
```

#### **2. Cadastrar Acesso**
```http
POST /api_acessos_visitantes.php
Content-Type: application/json

{
  "visitante_id": 123,
  "data_inicial": "2024-12-18",
  "data_final": "2024-12-25",
  "tipo_acesso": "portaria"
}
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Acesso cadastrado com sucesso",
  "dados": {
    "id": 1,
    "qr_code": "ACESSO-ABC123-1702900000",
    "dias_permanencia": 8
  }
}
```

#### **3. Gerar QR Code**
```http
GET /api_acessos_visitantes.php?action=gerar_qrcode&id=1
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "QR Code gerado com sucesso",
  "dados": {
    "qr_code_imagem": "data:image/png;base64,...",
    "qr_data": "{...}",
    "acesso": {...}
  }
}
```

#### **4. Validar QR Code (Para Cancelas)**
```http
POST /api_acessos_visitantes.php?action=validar_qrcode
Content-Type: application/json

{
  "qr_code": "ACESSO-ABC123-1702900000"
}
```

**Resposta (Sucesso):**
```json
{
  "sucesso": true,
  "mensagem": "Acesso permitido",
  "dados": {
    "visitante": "João Silva",
    "documento": "123.456.789-00",
    "tipo_acesso": "portaria",
    "valido_ate": "2024-12-25"
  }
}
```

**Resposta (Erro):**
```json
{
  "sucesso": false,
  "mensagem": "Acesso negado: Período expirado"
}
```

#### **5. Calcular Dias**
```http
GET /api_acessos_visitantes.php?action=calcular_dias&data_inicial=2024-12-18&data_final=2024-12-25
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Dias calculados com sucesso",
  "dados": {
    "dias": 8
  }
}
```

#### **6. Excluir Acesso**
```http
DELETE /api_acessos_visitantes.php?id=1
```

---

## 🎨 Interface Web

### **Sistema de Abas**

A interface foi dividida em 2 abas principais:

1. **Visitantes** - Cadastro e gerenciamento de visitantes (existente)
2. **Acessos** - Cadastro e gerenciamento de acessos (NOVO)

### **Aba: Acessos**

#### **Formulário de Cadastro**

**Campos:**
- **Visitante*** - Select com todos os visitantes cadastrados
- **Data Inicial*** - Campo de data
- **Data Final*** - Campo de data
- **Dias de Permanência** - Calculado automaticamente
- **Tipo de Acesso*** - Seletor visual com 3 opções:
  - 🚪 **Acesso Portaria** (azul)
  - 🛣️ **Acesso Externo** (laranja)
  - 💧 **Acesso Lagoa** (verde)

**Cálculo Automático de Dias:**
```javascript
// Ao alterar data inicial ou final
const diffDays = Math.ceil((dt2 - dt1) / (1000 * 60 * 60 * 24)) + 1;
```

**Exibição:**
```
┌─────────────────────────┐
│          8              │
│  dias de permanência    │
└─────────────────────────┘
```

#### **Tabela de Acessos**

**Colunas:**
- ID
- Visitante
- Período (data inicial a data final)
- Dias
- Tipo de Acesso (badge colorido)
- Status (Ativo/Inativo)
- Ações (QR Code, Excluir)

**Badges de Tipo:**
- 🔵 **Portaria** - Azul
- 🟠 **Externo** - Laranja
- 🟢 **Lagoa** - Verde

**Badges de Status:**
- ✅ **Ativo** - Verde (dentro do período)
- ❌ **Inativo** - Vermelho (fora do período ou desativado)

---

## 🎫 Geração de QR Code

### **Processo**

1. Usuário clica no botão "QR Code" na tabela
2. Sistema chama API: `gerar_qrcode&id=X`
3. API gera QR Code via Google Charts API
4. QR Code contém dados em JSON:

```json
{
  "codigo": "ACESSO-ABC123-1702900000",
  "visitante": "João Silva",
  "documento": "123.456.789-00",
  "tipo_acesso": "portaria",
  "data_inicial": "2024-12-18",
  "data_final": "2024-12-25",
  "valido_ate": "2024-12-25"
}
```

5. Modal exibe QR Code com informações
6. Usuário pode baixar a imagem

### **Modal de QR Code**

```
┌─────────────────────────────────┐
│     QR Code de Acesso           │
├─────────────────────────────────┤
│   ┌─────────────────────┐       │
│   │                     │       │
│   │     [QR CODE]       │       │
│   │                     │       │
│   └─────────────────────┘       │
│                                 │
│   João Silva                    │
│   Documento: 123.456.789-00     │
│   Tipo: PORTARIA                │
│   Válido: 18/12/24 a 25/12/24   │
│                                 │
│   [Baixar QR Code] [Fechar]     │
└─────────────────────────────────┘
```

---

## 🚪 Validação nas Cancelas

### **Fluxo de Validação**

1. **Visitante apresenta QR Code** na cancela
2. **Leitor de QR Code** captura o código
3. **Sistema chama API** de validação:
   ```http
   POST /api_acessos_visitantes.php?action=validar_qrcode
   ```
4. **API verifica:**
   - ✅ QR Code existe no banco
   - ✅ Acesso está ativo
   - ✅ Data atual está dentro do período
   - ✅ Tipo de acesso corresponde à cancela

5. **Resultado:**
   - ✅ **Acesso Permitido** - Cancela libera
   - ❌ **Acesso Negado** - Cancela bloqueia

### **Logs de Auditoria**

Todos os acessos são registrados:
- `ACESSO_PERMITIDO` - Acesso liberado
- `ACESSO_NEGADO` - Acesso bloqueado (motivo)
- `ACESSO_CADASTRADO` - Novo acesso criado
- `ACESSO_EXCLUIDO` - Acesso removido

---

## 🎨 Estilos CSS

### **Sistema de Abas**
```css
.tabs { display: flex; border-bottom: 2px solid #f1f5f9; }
.tab { padding: 1rem 1.5rem; cursor: pointer; }
.tab.active { color: #3b82f6; border-bottom: 3px solid #3b82f6; }
.tab-content { display: none; }
.tab-content.active { display: block; }
```

### **Seletor de Tipo de Acesso**
```css
.tipo-acesso-selector { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
.tipo-acesso-option { border: 2px solid #e2e8f0; padding: 1rem; cursor: pointer; }
.tipo-acesso-option.selected { border-color: #3b82f6; background: #eff6ff; }
```

### **QR Code**
```css
.qr-container { text-align: center; padding: 2rem; background: #f8fafc; }
.qr-image { max-width: 300px; border: 4px solid #3b82f6; }
```

### **Badges**
```css
.badge { padding: 0.25rem 0.75rem; border-radius: 12px; font-weight: 600; }
.badge-portaria { background: #dbeafe; color: #1e40af; }
.badge-externo { background: #fef3c7; color: #92400e; }
.badge-lagoa { background: #d1fae5; color: #065f46; }
```

---

## 🧪 Como Testar

### **Teste 1: Cadastrar Acesso**
1. Acesse `visitantes.html`
2. Clique na aba "Acessos"
3. Selecione um visitante
4. Defina data inicial e final
5. Verifique cálculo automático de dias
6. Selecione tipo de acesso
7. Clique em "Salvar Acesso"
8. ✅ Deve aparecer na tabela

### **Teste 2: Gerar QR Code**
1. Na tabela de acessos
2. Clique no botão roxo "QR Code"
3. ✅ Deve abrir modal com QR Code
4. ✅ Informações devem estar corretas
5. Clique em "Baixar QR Code"
6. ✅ Deve fazer download da imagem

### **Teste 3: Validar QR Code**
1. Use um leitor de QR Code
2. Escaneie o QR Code gerado
3. Copie o código
4. Faça requisição POST para API:
   ```bash
   curl -X POST "http://seu-dominio.com/api_acessos_visitantes.php?action=validar_qrcode" \
        -H "Content-Type: application/json" \
        -d '{"qr_code":"ACESSO-ABC123-1702900000"}'
   ```
5. ✅ Deve retornar "Acesso permitido" se dentro do período

### **Teste 4: Excluir Acesso**
1. Na tabela de acessos
2. Clique no botão vermelho "Excluir"
3. Confirme a exclusão
4. ✅ Acesso deve ser removido da tabela

### **Teste 5: Validação de Período**
1. Cadastre um acesso com data final no passado
2. Tente validar o QR Code
3. ✅ Deve retornar "Acesso negado: Período expirado"

---

## 📊 Casos de Uso

### **Caso 1: Visitante de Final de Semana**
- **Visitante:** João Silva
- **Período:** 20/12/2024 a 22/12/2024 (3 dias)
- **Tipo:** Acesso Portaria
- **Fluxo:**
  1. Morador cadastra acesso
  2. Sistema gera QR Code
  3. Morador envia QR Code para João
  4. João apresenta QR Code na portaria
  5. Cancela valida e libera acesso

### **Caso 2: Prestador de Serviço**
- **Visitante:** Empresa de Manutenção
- **Período:** 18/12/2024 a 18/12/2024 (1 dia)
- **Tipo:** Acesso Externo
- **Fluxo:**
  1. Administração cadastra acesso
  2. Gera QR Code
  3. Envia para empresa
  4. Prestador apresenta na cancela externa
  5. Sistema valida tipo de acesso correto

### **Caso 3: Acesso à Lagoa**
- **Visitante:** Familiar do Morador
- **Período:** 25/12/2024 a 01/01/2025 (8 dias)
- **Tipo:** Acesso Lagoa
- **Fluxo:**
  1. Morador cadastra acesso
  2. Define tipo "Lagoa"
  3. Gera QR Code
  4. Visitante usa QR Code na cancela da lagoa
  5. Sistema valida e libera

---

## 🔒 Segurança

### **Medidas Implementadas**

1. **QR Code Único** - Código gerado com `uniqid()` + timestamp
2. **Validação de Período** - Verifica data inicial e final
3. **Status Ativo** - Apenas acessos ativos são válidos
4. **Tipo de Acesso** - Valida se tipo corresponde à cancela
5. **Logs de Auditoria** - Registra todas as tentativas
6. **Foreign Key** - Exclusão em cascata ao remover visitante
7. **SQL Injection** - Prepared statements em todas as queries

### **Código Único**
```php
$qr_code = 'ACESSO-' . strtoupper(uniqid()) . '-' . time();
// Exemplo: ACESSO-6584A2F1-1702900000
```

---

## 📱 Responsividade

### **Desktop**
- Seletor de tipo em 3 colunas
- Tabs em linha horizontal
- QR Code em tamanho completo

### **Tablet**
- Seletor de tipo em 3 colunas
- Tabs em linha horizontal
- QR Code ajustado

### **Mobile**
- Seletor de tipo em 1 coluna (empilhado)
- Tabs em linha horizontal (scroll)
- QR Code responsivo

---

## ✅ Checklist de Implementação

- [x] Tabela `acessos_visitantes` criada
- [x] API REST completa
- [x] Interface com sistema de abas
- [x] Formulário de cadastro de acesso
- [x] Cálculo automático de dias
- [x] Seletor visual de tipo de acesso
- [x] Tabela de acessos com badges
- [x] Geração de QR Code
- [x] Modal de visualização de QR Code
- [x] Download de QR Code
- [x] Validação de QR Code para cancelas
- [x] Logs de auditoria
- [x] Exclusão de acessos
- [x] Responsividade mobile
- [x] Documentação completa

---

## 📁 Arquivos Criados/Modificados

### **Criados:**
1. ✅ `create_acessos_visitantes.sql` - Script de criação da tabela
2. ✅ `api_acessos_visitantes.php` - API REST completa
3. ✅ `ACESSOS_VISITANTES_DOCUMENTACAO.md` - Esta documentação

### **Modificados:**
1. ✅ `visitantes.html` - Adicionado sistema de abas e aba de acessos
   - CSS para tabs, QR Code, badges
   - HTML com 2 abas (Visitantes e Acessos)
   - JavaScript para gerenciar acessos e QR Codes

---

## 🚀 Próximos Passos

### **Melhorias Futuras**

1. **Notificações**
   - Enviar QR Code por e-mail automaticamente
   - Notificar morador quando visitante acessar

2. **Relatórios**
   - Relatório de acessos por período
   - Estatísticas de uso por tipo de acesso

3. **Integração**
   - Integrar com sistema de cancelas físicas
   - App mobile para leitura de QR Code

4. **Validações Avançadas**
   - Limite de acessos por visitante
   - Horários permitidos por tipo de acesso
   - Blacklist de visitantes

---

## 📞 Suporte

Para dúvidas ou problemas:
1. Verifique os logs do sistema em `logs_sistema.html`
2. Consulte a documentação da API
3. Entre em contato com o suporte técnico

---

**Desenvolvido com ❤️ para o Condomínio Serra da Liberdade**

**Data:** 18 de Dezembro de 2024  
**Versão:** 1.0  
**Status:** ✅ Implementação Completa
