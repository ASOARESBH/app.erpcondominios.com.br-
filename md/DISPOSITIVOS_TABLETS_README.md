# 📱 Sistema de Autenticação de Tablets

## 📋 Visão Geral

Sistema completo de autenticação e gerenciamento de tablets (dispositivos) autorizados para validação de QR Code na portaria do ERP Serra da Liberdade.

**Objetivo**: Garantir que apenas tablets autorizados possam liberar acessos, com controle total sobre dispositivos ativos/inativos e rastreamento de validações.

---

## 🔐 Arquitetura de Segurança

### Validação em Camadas

```
1️⃣ TABLET → Valida token do dispositivo (12 caracteres)
2️⃣ QR CODE → Valida token do visitante (32 caracteres)
3️⃣ ACESSO → Libera ou nega entrada
```

### Componentes

1. **Token do Tablet**: 12 caracteres alfanuméricos (fácil digitação)
2. **Secret do Tablet**: 32 caracteres (contra-chave para segurança futura)
3. **Cadastro Centralizado**: Gerenciamento via interface web
4. **Rastreamento**: Todas as validações são registradas

---

## 🗄️ Estrutura do Banco de Dados

### Tabela: `dispositivos_tablets`

```sql
CREATE TABLE dispositivos_tablets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,                -- Ex: "Tablet Portaria Principal"
    token VARCHAR(12) UNIQUE NOT NULL,         -- Ex: "A9F3K7L2Q8M4"
    secret VARCHAR(32) NOT NULL,               -- Contra-chave (uso futuro)
    status ENUM('ativo', 'inativo') DEFAULT 'ativo',
    local VARCHAR(100),                        -- Ex: "Portaria Principal"
    descricao TEXT,
    ultimo_acesso DATETIME,
    total_validacoes INT DEFAULT 0,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    criado_por INT,
    FOREIGN KEY (criado_por) REFERENCES usuarios(id)
);
```

### Tabela: `logs_validacoes_dispositivo`

```sql
CREATE TABLE logs_validacoes_dispositivo (
    id INT PRIMARY KEY AUTO_INCREMENT,
    dispositivo_id INT NOT NULL,
    token_qrcode VARCHAR(32),
    acesso_id INT,
    resultado ENUM('sucesso', 'falha') NOT NULL,
    motivo_falha VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent TEXT,
    validado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (dispositivo_id) REFERENCES dispositivos_tablets(id),
    FOREIGN KEY (acesso_id) REFERENCES acessos_visitantes(id)
);
```

### View: `view_estatisticas_dispositivos`

Estatísticas em tempo real:
- Total de dispositivos
- Dispositivos ativos/inativos
- Validações hoje
- Taxa de sucesso

---

## 📁 Arquivos do Sistema

### Backend (PHP)

| Arquivo | Descrição | Tamanho |
|---------|-----------|---------|
| `dispositivo_token_manager.php` | Gerenciador de tokens e dispositivos | ~8 KB |
| `api_dispositivos.php` | API REST completa (CRUD) | ~5 KB |
| `api_validar_token.php` | API de validação com autenticação | ~7 KB |
| `create_dispositivos_tablets.sql` | Script SQL para criar tabelas | ~4 KB |

### Frontend (HTML)

| Arquivo | Descrição | Tamanho |
|---------|-----------|---------|
| `dispositivos.html` | Interface de gerenciamento | ~15 KB |
| `console_acesso.html` | Console para tablets (atualizado) | ~30 KB |

---

## 🚀 Instalação

### 1. Executar SQL

```bash
# No phpMyAdmin ou MySQL CLI
mysql -u usuario -p inlaud99_erpserra < create_dispositivos_tablets.sql
```

### 2. Upload dos Arquivos

Fazer upload via FTP/cPanel:
- `dispositivo_token_manager.php`
- `api_dispositivos.php`
- `api_validar_token.php` (atualizado)
- `dispositivos.html`
- `console_acesso.html` (atualizado)

### 3. Verificar Permissões

```bash
chmod 644 *.php
chmod 644 *.html
```

---

## 📱 Uso do Sistema

### Passo 1: Cadastrar Dispositivo

1. Acesse `dispositivos.html` no sistema
2. Clique em "Cadastrar Novo Dispositivo"
3. Preencha:
   - **Nome**: Ex: "Tablet Portaria Principal"
   - **Local**: Ex: "Portaria Principal"
   - **Descrição**: Ex: "Samsung Galaxy Tab A7"
4. Clique em "Cadastrar Dispositivo"
5. **IMPORTANTE**: Anote o token gerado (12 caracteres)

**Exemplo de Token Gerado:**
```
A9F3K7L2Q8M4
```

### Passo 2: Configurar Tablet

1. No tablet, acesse `console_acesso.html`
2. Na primeira vez, será solicitado o token
3. Digite o token de 12 caracteres
4. Clique em "Validar Token"
5. ✅ Tablet configurado e pronto para uso!

### Passo 3: Validar QR Code

1. No console, clique em "LER QR CODE"
2. Escaneie o QR Code do visitante
3. O sistema valida:
   - ✅ Token do tablet (autorizado?)
   - ✅ QR Code do visitante (válido?)
4. Resultado: Acesso Autorizado ou Negado

---

## 🔧 API Endpoints

### Gerenciamento de Dispositivos

#### Cadastrar Dispositivo
```http
POST /api_dispositivos.php?action=cadastrar
Content-Type: application/json

{
  "nome": "Tablet Portaria Principal",
  "local": "Portaria Principal",
  "descricao": "Samsung Galaxy Tab A7"
}
```

**Resposta:**
```json
{
  "sucesso": true,
  "mensagem": "Dispositivo cadastrado com sucesso",
  "dados": {
    "dispositivo_id": 1,
    "token": "A9F3K7L2Q8M4",
    "secret": "f3a7b2c9d4e5f6g7h8i9j0k1l2m3n4o5"
  }
}
```

#### Listar Dispositivos
```http
GET /api_dispositivos.php?action=listar
GET /api_dispositivos.php?action=listar&status=ativo
```

#### Validar Token do Dispositivo
```http
GET /api_dispositivos.php?action=validar_token&token=A9F3K7L2Q8M4
```

#### Atualizar Status
```http
POST /api_dispositivos.php?action=atualizar_status
Content-Type: application/json

{
  "id": 1,
  "status": "inativo"
}
```

#### Estatísticas
```http
GET /api_dispositivos.php?action=estatisticas
```

**Resposta:**
```json
{
  "sucesso": true,
  "dados": {
    "total_dispositivos": 3,
    "dispositivos_ativos": 2,
    "dispositivos_inativos": 1,
    "validacoes_hoje": 45,
    "validacoes_sucesso_hoje": 42,
    "validacoes_falha_hoje": 3,
    "taxa_sucesso": 93.33
  }
}
```

### Validação de QR Code

#### Validar com Autenticação de Dispositivo
```http
POST /api_validar_token.php?action=validar_e_usar
Content-Type: application/json

{
  "token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
  "dispositivo_token": "A9F3K7L2Q8M4",
  "local": "portaria"
}
```

**Resposta (Sucesso):**
```json
{
  "sucesso": true,
  "mensagem": "Acesso autorizado",
  "dados": {
    "visitante": {
      "nome": "João Silva",
      "documento": "123.456.789-00"
    },
    "acesso": {
      "tipo_acesso": "Visitante",
      "data_final": "2024-12-31"
    }
  }
}
```

**Resposta (Dispositivo Não Autorizado):**
```json
{
  "sucesso": false,
  "mensagem": "Dispositivo não autorizado",
  "dados": {
    "motivo": "dispositivo_inativo"
  }
}
```

---

## 📊 Monitoramento

### Estatísticas Disponíveis

1. **Total de Dispositivos**: Quantidade cadastrada
2. **Dispositivos Ativos**: Autorizados a validar
3. **Dispositivos Inativos**: Desativados temporariamente
4. **Validações Hoje**: Total de tentativas
5. **Taxa de Sucesso**: Percentual de validações bem-sucedidas

### Logs de Validação

Cada validação registra:
- ✅ Dispositivo que validou
- ✅ Token do QR Code
- ✅ Resultado (sucesso/falha)
- ✅ Motivo da falha (se houver)
- ✅ IP e User Agent
- ✅ Data e hora

### Consultar Histórico

```sql
SELECT 
    d.nome as dispositivo,
    v.nome_completo as visitante,
    l.resultado,
    l.validado_em
FROM logs_validacoes_dispositivo l
JOIN dispositivos_tablets d ON l.dispositivo_id = d.id
LEFT JOIN acessos_visitantes a ON l.acesso_id = a.id
LEFT JOIN visitantes v ON a.visitante_id = v.id
ORDER BY l.validado_em DESC
LIMIT 100;
```

---

## 🔒 Segurança

### Token do Dispositivo

- **Formato**: 12 caracteres alfanuméricos
- **Caracteres**: A-Z, 2-9 (exclui I, O, 0, 1 para evitar confusão)
- **Exemplo**: `A9F3K7L2Q8M4`
- **Único**: Não pode haver dois dispositivos com mesmo token

### Secret (Contra-chave)

- **Formato**: 32 caracteres hexadecimais
- **Uso**: Reservado para assinatura de requisições (implementação futura)
- **Armazenamento**: Apenas no banco de dados

### Validação em Camadas

1. **Primeira camada**: Valida se o dispositivo está autorizado
2. **Segunda camada**: Valida se o QR Code é válido
3. **Terceira camada**: Registra a validação para auditoria

### Revogação de Acesso

Para revogar acesso de um tablet:
1. Acesse `dispositivos.html`
2. Clique em "Desativar" no dispositivo
3. ✅ Tablet não poderá mais validar QR Codes

---

## 🛠️ Resolução de Problemas

### Erro: "Dispositivo não autorizado"

**Causas possíveis:**
- Token incorreto
- Dispositivo desativado
- Token não cadastrado

**Solução:**
1. Verificar se token está correto (12 caracteres)
2. Verificar status do dispositivo em `dispositivos.html`
3. Se necessário, cadastrar novo dispositivo

### Erro: "Token não encontrado"

**Causa**: Token do dispositivo não existe no banco

**Solução**:
1. Cadastrar dispositivo em `dispositivos.html`
2. Anotar token gerado
3. Reconfigurar tablet com novo token

### Tablet não salva configuração

**Causa**: localStorage desabilitado ou navegador em modo privado

**Solução**:
1. Sair do modo privado
2. Habilitar cookies e localStorage
3. Reconfigurar tablet

---

## 📈 Próximas Melhorias

### Curto Prazo
- [ ] Rotação automática de tokens
- [ ] Notificações de tentativas não autorizadas
- [ ] Relatório de uso por dispositivo

### Médio Prazo
- [ ] Assinatura de requisições com secret
- [ ] Autenticação biométrica
- [ ] Modo offline com sincronização

### Longo Prazo
- [ ] Integração com reconhecimento facial
- [ ] Dashboard em tempo real
- [ ] App nativo para tablets

---

## 📞 Suporte

Para dúvidas ou problemas:
- 📧 Email: suporte@serraliberdade.com.br
- 📱 WhatsApp: (31) 99999-9999
- 🌐 Site: https://help.manus.im

---

## 📄 Licença

Sistema proprietário - ERP Serra da Liberdade
© 2024 Todos os direitos reservados

---

**Versão**: 1.0.0  
**Data**: 26/12/2024  
**Autor**: Manus AI
