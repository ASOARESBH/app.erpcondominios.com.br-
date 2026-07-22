# Relatório de Análise e Plano de Transformação Multi-Tenant
## ERP Condomínios — `app.erpcondominios.com.br`

**Data:** 22 de Julho de 2026
**Repositório Novo:** [app.erpcondominios.com.br-](https://github.com/ASOARESBH/app.erpcondominios.com.br-)
**Repositório Legado:** [app.erpcondominios.com.br](https://github.com/ASOARESBH/app.erpcondominios.com.br)

---

## 1. Status da Transferência

A aplicação foi transferida com sucesso para o novo repositório. O primeiro commit no repositório `app.erpcondominios.com.br-` contém a cópia integral do sistema legado como ponto de partida para a transformação Multi-Tenant.

| Item | Quantidade |
|---|---|
| Total de arquivos transferidos | 860 |
| Endpoints de API (PHP) | 230+ |
| Módulos de Frontend (HTML/JS) | 50+ |
| Tabelas no Banco de Dados | 181 |
| Views/Procedures no Banco | 30+ |

---

## 2. Diagnóstico do Sistema Atual (Single-Tenant)

### 2.1. Banco de Dados — Ausência Total de Isolamento

O banco de dados `inlaud99_erpserra` contém **151 tabelas de negócio** (excluindo views e backups) e nenhuma delas possui uma coluna de identificação de condomínio (`tenant_id` ou `empresa_id`). A tabela `empresa` funciona como um **singleton absoluto**: há apenas um registro (ID=1) e todas as APIs que a consultam usam `LIMIT 1` ou `WHERE id = 1` de forma implícita.

As tabelas de negócio que precisarão receber `tenant_id` estão distribuídas nos seguintes domínios funcionais:

| Domínio | Tabelas Principais |
|---|---|
| **Condomínio** | `moradores`, `unidades`, `veiculos`, `dependentes`, `visitantes` |
| **Financeiro** | `contas_pagar`, `contas_receber`, `planos_contas`, `contas_bancarias`, `movimentacoes_bancarias` |
| **Manutenção** | `os_chamados`, `produtos_estoque`, `inventario`, `hidrometros`, `leituras` |
| **Contratos/Administrativo** | `contratos`, `protocolos`, `notificacoes`, `documentos` |
| **RH** | `rh_colaboradores`, `rh_ponto_lancamento`, `rh_escala` |
| **Segurança/Acesso** | `registros_acesso`, `acessos_visitantes`, `controlid_dispositivos` |
| **Sistema** | `usuarios`, `usuario_modulos`, `configuracoes`, `logs_sistema` |

### 2.2. Backend — Autenticação Sem Contexto de Tenant

O arquivo `validar_login.php` (endpoint principal de autenticação) busca o usuário apenas pelo e-mail na tabela `usuarios`, sem qualquer referência a qual condomínio ele pertence. A sessão PHP resultante armazena apenas:

```
$_SESSION['usuario_id']
$_SESSION['usuario_nome']
$_SESSION['usuario_email']
$_SESSION['usuario_funcao']
$_SESSION['usuario_departamento']
$_SESSION['usuario_permissao']
$_SESSION['usuario_logado']
$_SESSION['login_timestamp']
```

Não há `$_SESSION['tenant_id']`. Isso significa que, mesmo que adicionemos `tenant_id` nas tabelas, as APIs não saberiam qual tenant filtrar sem essa informação na sessão.

Adicionalmente, **62 arquivos PHP** contêm o domínio `app.erpcondominios.com.br` hardcoded no cabeçalho `Access-Control-Allow-Origin`, o que bloquearia requisições de outros domínios de condomínio.

### 2.3. Frontend — SPA Sem Seleção de Contexto

O frontend é uma SPA (Single Page Application) gerenciada pelo `app-router.js`. O `APP_BASE_PATH` é detectado dinamicamente via `window.location.origin`, o que é um ponto positivo para a migração. Entretanto, não existe nenhum mecanismo de seleção ou exibição do contexto de condomínio ativo na interface.

---

## 3. Arquitetura Multi-Tenant Proposta

A arquitetura sugerida na imagem do usuário define que **todos os usuários acessam pelo mesmo endereço** (`app.erpcondominios.com.br`) e, após a autenticação, o sistema identifica automaticamente o condomínio (Tenant), o perfil de acesso (RBAC) e os módulos habilitados.

```
app.erpcondominios.com.br
        |
   Tela de Login
        |
  JWT + Tenant + Perfil
        |
  ┌─────┼──────┐
  |     |      |
Admin  Morador  Portaria
  |     |      |
Dashboard  Portal  Controle Acesso
```

**Abordagem de Banco de Dados Escolhida:** Banco Único com Isolamento Lógico (Single Database, Shared Schema). Esta é a abordagem mais adequada para a pilha tecnológica PHP/MySQL/HostGator, pois não requer múltiplas instâncias de banco de dados, é mais simples de gerenciar e tem menor custo operacional.

---

## 4. Plano de Execução em 5 Fases

### Fase 1 — Estrutura de Banco de Dados (Fundação)

**Objetivo:** Criar a tabela mestre de tenants e injetar `tenant_id` nas tabelas de negócio.

**Ações:**

1. **Criar tabela `tenants`** (evolução da tabela `empresa`):

```sql
CREATE TABLE `tenants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) NOT NULL UNIQUE COMMENT 'Identificador único ex: serra-liberdade',
  `razao_social` varchar(255) NOT NULL,
  `nome_fantasia` varchar(255) DEFAULT NULL,
  `cnpj` varchar(20) NOT NULL,
  `plano` enum('basico','profissional','enterprise') DEFAULT 'basico',
  `status` enum('ativo','inativo','suspenso') DEFAULT 'ativo',
  `modulos_habilitados` json DEFAULT NULL COMMENT 'Lista de módulos ativos para este tenant',
  `logo_url` varchar(500) DEFAULT NULL,
  `email_principal` varchar(255) NOT NULL,
  `data_criacao` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrar dados da tabela empresa para tenants
INSERT INTO `tenants` SELECT id, 'serra-liberdade', razao_social, nome_fantasia,
  cnpj, 'profissional', situacao, NULL, logo_url, email_principal, data_criacao
FROM `empresa`;
```

2. **Adicionar `tenant_id` nas tabelas de negócio** (script de migração):

```sql
-- Exemplo para as tabelas principais
ALTER TABLE `usuarios` ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `moradores` ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `veiculos` ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `contas_pagar` ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `contas_receber` ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
ALTER TABLE `os_chamados` ADD COLUMN `tenant_id` int(11) NOT NULL DEFAULT 1 AFTER `id`;
-- ... (demais tabelas)

-- Criar índices para performance
ALTER TABLE `usuarios` ADD INDEX `idx_tenant` (`tenant_id`);
ALTER TABLE `moradores` ADD INDEX `idx_tenant` (`tenant_id`);
-- ... (demais tabelas)
```

3. **Criar tabela `usuario_tenant`** para suporte a usuários multi-condomínio:

```sql
CREATE TABLE `usuario_tenant` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `permissao` enum('admin','gerente','operador','visualizador') DEFAULT 'operador',
  `ativo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_usuario_tenant` (`usuario_id`, `tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

### Fase 2 — Refatoração da Autenticação

**Objetivo:** Fazer o sistema identificar o tenant durante o login e armazená-lo na sessão.

**Arquivo: `api/validar_login.php`** — Modificações necessárias:

```php
// ANTES (linha 72):
$stmt = $conexao->prepare("SELECT id, nome, email, senha, funcao, departamento,
  permissao, ativo, sessao_inativa FROM usuarios WHERE email = ? LIMIT 1");

// DEPOIS — Join com tenant:
$stmt = $conexao->prepare("
  SELECT u.id, u.nome, u.email, u.senha, u.funcao, u.departamento,
    ut.permissao, u.ativo, u.sessao_inativa, ut.tenant_id, t.nome_fantasia as tenant_nome
  FROM usuarios u
  INNER JOIN usuario_tenant ut ON ut.usuario_id = u.id
  INNER JOIN tenants t ON t.id = ut.tenant_id
  WHERE u.email = ? AND t.slug = ? AND t.status = 'ativo'
  LIMIT 1
");
// $tenant_slug é extraído do subdomínio ou do campo de login
$stmt->bind_param("ss", $email, $tenant_slug);
```

**Arquivo: `api/auth_helper.php`** — Adicionar validação de tenant:

```php
// Adicionar na sessão:
$_SESSION['tenant_id'] = $usuario['tenant_id'];
$_SESSION['tenant_nome'] = $usuario['tenant_nome'];

// Na função verificarAutenticacao():
$tenant_id = $_SESSION['tenant_id'] ?? null;
if (!$tenant_id) {
    // Retornar erro 401 — sem contexto de tenant
}
```

**Identificação do Tenant** — Dois modos suportados:

| Modo | Como Funciona | Exemplo |
|---|---|---|
| **Subdomínio** | Extrair o prefixo do `HTTP_HOST` | `serra.erpcondominios.com.br` → slug = `serra` |
| **Domínio Único** | Usuário informa e-mail; sistema identifica o tenant automaticamente | `app.erpcondominios.com.br` → busca tenant pelo e-mail |

---

### Fase 3 — Isolamento nas APIs (Backend)

**Objetivo:** Garantir que nenhuma query retorne dados de outro tenant.

**Arquivo: `api/api_base.php`** — Adicionar método de tenant:

```php
// Adicionar na classe ApiBase:
protected function getTenantId(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $tenant_id = $_SESSION['tenant_id'] ?? null;
    if (!$tenant_id) {
        http_response_code(403);
        echo json_encode(['sucesso' => false, 'mensagem' => 'Contexto de tenant inválido.']);
        exit;
    }
    return (int)$tenant_id;
}
```

**Padrão de Query com Tenant** — Aplicar em todas as APIs:

```php
// ANTES:
$stmt = $conexao->prepare("SELECT * FROM moradores WHERE ativo = 1");

// DEPOIS:
$tenant_id = $this->getTenantId();
$stmt = $conexao->prepare("SELECT * FROM moradores WHERE ativo = 1 AND tenant_id = ?");
$stmt->bind_param("i", $tenant_id);
```

**Escopo de Impacto:** Todos os 230+ arquivos de API precisarão ser revisados. Prioridade de refatoração:

| Prioridade | Módulos |
|---|---|
| **Alta** | `api_moradores.php`, `api_usuarios.php`, `api_empresa.php`, `validar_login.php`, `auth_helper.php` |
| **Média** | `api_contas_pagar.php`, `api_contas_receber.php`, `api_os_*`, `api_estoque.php`, `api_visitantes.php` |
| **Baixa** | `api_manual.php`, `api_logs_*.php`, APIs de relatórios |

**Remoção de Hardcodes de CORS:**

```php
// ANTES (em 62 arquivos):
header('Access-Control-Allow-Origin: https://app.erpcondominios.com.br');

// DEPOIS — Dinâmico:
$allowed_origins = ['https://app.erpcondominios.com.br', 'https://*.erpcondominios.com.br'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins) || preg_match('/^https:\/\/[a-z0-9-]+\.erpcondominios\.com\.br$/', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
```

---

### Fase 4 — Adaptações no Frontend

**Objetivo:** Exibir o contexto do condomínio ativo e suportar troca de tenant.

1. **Identidade Visual Dinâmica:** O logo e o nome do condomínio no menu lateral já são carregados via API (`api_empresa.php`). Após a refatoração desta API para retornar dados do `tenant_id` da sessão, o frontend funcionará automaticamente.

2. **Exibição do Tenant Ativo:** No `layout-base.html`, adicionar um indicador visual do condomínio ativo no cabeçalho.

3. **Seleção de Tenant (Para Administradoras):** Se o usuário pertencer a mais de um condomínio, exibir um seletor no cabeçalho. A troca de contexto chamará uma nova API `api/trocar_tenant.php` que atualiza a sessão e recarrega o dashboard.

4. **Atualização do `app-router.js`:** Adicionar verificação de `tenant_id` no fluxo de carregamento de páginas.

---

### Fase 5 — Painel Super-Admin e Onboarding

**Objetivo:** Criar a infraestrutura de gestão de múltiplos condomínios.

1. **Módulo Super-Admin** (novo módulo no sistema):
   - Acessível apenas por usuários com permissão `super_admin` (nível acima de `admin`).
   - Funcionalidades: criar novo tenant, ativar/inativar condomínios, definir plano contratado, visualizar uso por tenant.

2. **Script de Onboarding:** Ao criar um novo condomínio, executar automaticamente:
   - Inserção na tabela `tenants`.
   - Criação do usuário administrador inicial.
   - Seed de dados padrão (módulos habilitados, planos de contas padrão, categorias de estoque padrão).

---

## 5. Riscos e Mitigações

| Risco | Impacto | Mitigação |
|---|---|---|
| **Vazamento de Dados (Cross-Tenant)** | **Crítico** | Implementar `getTenantId()` na `ApiBase` e criar um script de auditoria automática que verifica se todas as queries contêm `AND tenant_id = ?`. Testes de integração obrigatórios antes de cada deploy. |
| **Erros de Integridade (FK)** | Alto | Executar a migration de `tenant_id` com `DEFAULT 1` antes de criar as Foreign Keys. Validar integridade com `SELECT` antes de adicionar constraints. |
| **Performance com Múltiplos Tenants** | Médio | Criar índices compostos `(tenant_id, campo_filtro)` nas tabelas de maior volume. Avaliar particionamento de tabelas por `tenant_id` se o volume crescer. |
| **Problemas de CORS** | Baixo | Centralizar a lógica de CORS em `api_base.php` e remover todos os hardcodes. |
| **Sessões Cruzadas** | Médio | Garantir que `session_regenerate_id(true)` seja chamado na troca de tenant, além de no login. |

---

## 6. Resumo Executivo

| Etapa | Status | Esforço Estimado |
|---|---|---|
| Transferência para novo repositório | ✅ **Concluído** | — |
| Análise do sistema legado | ✅ **Concluído** | — |
| Migration de banco de dados (Fase 1) | ⏳ Pendente | 1-2 dias |
| Refatoração de autenticação (Fase 2) | ⏳ Pendente | 1-2 dias |
| Isolamento nas APIs (Fase 3) | ⏳ Pendente | 5-10 dias |
| Adaptações no Frontend (Fase 4) | ⏳ Pendente | 2-3 dias |
| Painel Super-Admin (Fase 5) | ⏳ Pendente | 3-5 dias |

O sistema está pronto para iniciar a transformação. O novo repositório `app.erpcondominios.com.br-` contém a base completa e o histórico de desenvolvimento. A recomendação é iniciar imediatamente pela **Fase 1** (migration de banco de dados) e **Fase 2** (autenticação), pois são o alicerce de todo o isolamento de dados.
