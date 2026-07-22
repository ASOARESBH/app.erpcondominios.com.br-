# Plano de Arquitetura Multi-Tenant para ERP Condomínios

## 1. Visão Geral da Transformação

O sistema ERP Condomínios (anteriormente ERP Condomínio) foi originalmente projetado para atender a um único condomínio. A análise do código-fonte e do banco de dados (181 tabelas, mais de 230 endpoints de API em PHP) revelou que não há isolamento lógico de dados por condomínio. O objetivo deste plano é detalhar a estratégia técnica para transformar o sistema em uma arquitetura Multi-Tenant (Múltiplos Inquilinos) mantendo o mesmo banco de dados (Single Database, Shared Schema).

**Abordagem escolhida:** Banco de Dados Único com Isolamento Lógico (Tenant ID).
Esta abordagem é a mais adequada para a pilha tecnológica atual (PHP + MySQL + Vanilla JS) e permite escalabilidade com menor custo de infraestrutura inicial.

## 2. Análise da Situação Atual

### 2.1. Banco de Dados
- Nenhuma das 151 tabelas de negócio possui uma coluna identificadora de condomínio (`tenant_id`).
- A tabela `empresa` atua como um singleton (ID = 1 fixo), representando os dados do condomínio único.
- Chaves estrangeiras (quando existem) não garantem isolamento entre diferentes condomínios.

### 2.2. Backend (APIs PHP)
- As APIs fazem consultas globais: `SELECT * FROM moradores`, sem filtro de condomínio.
- A autenticação (`validar_login.php`) cria uma sessão genérica (`$_SESSION['usuario_id']`), mas não associa o usuário a uma empresa específica.
- Permissões (`usuario_modulos`) são globais ao sistema, não por condomínio.
- Há *hardcodes* de domínio (`https://app.erpcondominios.com.br`) nos headers de CORS em 62 arquivos.

### 2.3. Frontend (Vanilla JS)
- O SPA (`app-router.js`) assume um ambiente único.
- O URL base (`APP_BASE_PATH`) é detectado dinamicamente, o que facilita a migração.
- Não há seleção de contexto de condomínio na interface do usuário.

## 3. Plano de Execução em Fases

A transformação deve ser executada em fases incrementais para garantir a estabilidade do sistema.

### Fase 1: Preparação da Estrutura de Banco de Dados

Nesta fase, introduziremos a coluna `tenant_id` em todas as tabelas de negócio.

1. **Renomear e Adaptar a Tabela `empresa`:**
   - Renomear para `tenants` (ou `condominios`).
   - Adicionar campo `subdominio` (ex: `serra`, `valedoipê`) para identificação via URL.
   - O registro atual (ID=1) será o tenant pioneiro.

2. **Injeção de `tenant_id` nas Tabelas de Negócio:**
   - Adicionar coluna `tenant_id INT NOT NULL` nas tabelas principais: `usuarios`, `moradores`, `veiculos`, `visitantes`, `contas_pagar`, `contas_receber`, `os_chamados`, `estoque`, etc.
   - Definir `tenant_id = 1` como valor padrão temporário para os dados legados.
   - Criar Foreign Keys referenciando `tenants(id)`.

3. **Tabela de Relacionamento de Usuários (Opcional, mas recomendado):**
   - Se um usuário puder pertencer a mais de um condomínio (ex: um síndico profissional), criar tabela `usuario_tenant` (`usuario_id`, `tenant_id`, `permissao`).
   - Se for 1:1, manter `tenant_id` direto na tabela `usuarios`.

### Fase 2: Refatoração do Motor de Autenticação e Sessão

A segurança do isolamento de dados começa na autenticação.

1. **Identificação do Tenant no Login:**
   - **Via Subdomínio:** Extrair o subdomínio da URL de origem (`$_SERVER['HTTP_ORIGIN']` ou `HTTP_HOST`) para identificar qual condomínio está sendo acessado. Ex: `serra.erpcondominios.com.br`.
   - **Via Domínio Único (app.erpcondominios.com.br):** Se for domínio único, o usuário informa o e-mail, e o sistema identifica a qual tenant ele pertence. Se pertencer a mais de um, apresenta uma tela de seleção de contexto.

2. **Atualização do `validar_login.php` e `auth_helper.php`:**
   - Modificar o script de login para gravar `$_SESSION['tenant_id']`.
   - O `auth_helper.php` deve validar não apenas se o usuário está logado, mas garantir que o `tenant_id` está presente na sessão.

### Fase 3: Isolamento Lógico nas APIs (Backend)

Esta é a fase mais trabalhosa. O isolamento de dados deve ser garantido em nível de query SQL.

1. **Refatoração da Classe `ApiBase`:**
   - Adicionar um método `getTenantId()` que retorna o tenant atual da sessão.
   - Todas as queries devem ser ajustadas.

2. **Atualização Global de Queries (CRUD):**
   - **SELECT:** Todo `SELECT` deve incluir `WHERE tenant_id = ?`.
   - **INSERT:** Todo `INSERT` deve incluir o `tenant_id` da sessão ativa.
   - **UPDATE/DELETE:** Todo `UPDATE` e `DELETE` deve validar se o registro pertence ao `tenant_id` atual (`WHERE id = ? AND tenant_id = ?`).

3. **Remoção de Hardcodes:**
   - Substituir URLs fixas (`app.erpcondominios.com.br`) nos cabeçalhos de CORS (`Access-Control-Allow-Origin`) por validação dinâmica baseada na variável global de configuração ou na origem da requisição.

### Fase 4: Adaptações no Frontend

O frontend precisará refletir o contexto do condomínio atual.

1. **Identidade Visual Dinâmica:**
   - O logo e o nome da empresa no menu lateral devem ser carregados dinamicamente da API `/api/api_empresa.php` (que agora retornará os dados do `tenant_id` logado).
   
2. **Seleção de Contexto (Se aplicável):**
   - Se houver suporte a usuários multi-tenant (ex: Administradora), criar um componente `<app-tenant-selector>` no cabeçalho (`layout-base.html`) para alternar entre condomínios. A troca de contexto fará uma chamada à API para atualizar a sessão e recarregar a página.

### Fase 5: Módulos de Sistema e Permissões

1. **Isolamento de Permissões:**
   - A tabela `usuario_modulos` deve ser adaptada para incluir `tenant_id`. Assim, um usuário pode ser 'Admin' no Condomínio A e 'Operador' no Condomínio B.
   - A API `api_permissoes_modulos.php` deve filtrar pelo tenant ativo.

2. **Painel Super-Admin:**
   - Criar um painel de administração global (Super-Admin) acessível apenas pela equipe desenvolvedora/dona do software.
   - Este painel permitirá criar novos condomínios (tenants), ativar/inativar contas, gerenciar limites de uso e visualizar faturamento global.

## 4. Riscos e Mitigações

| Risco | Impacto | Mitigação |
|---|---|---|
| **Vazamento de Dados (Cross-Tenant Data Leak)** | Alto | Implementar o `tenant_id` na classe base `ApiBase` ou criar uma camada de abstração de banco de dados (Query Builder) que injete a cláusula `WHERE tenant_id = ?` automaticamente em todas as queries. Revisão manual obrigatória em todas as 230+ APIs. |
| **Erros de Integridade (Chaves Estrangeiras)** | Médio | Ao adicionar `tenant_id` nas tabelas filhas, garantir que a criação das constraints ocorra apenas após a higienização dos dados legados (setando `tenant_id = 1` para tudo que já existe). |
| **Problemas de CORS** | Baixo | Utilizar o script global de CORS já presente em algumas APIs e remover completamente as menções hardcoded a domínios específicos. |
| **Complexidade de Deploy** | Médio | Criar scripts SQL de migração estruturados (migrations) para rodar no HostGator sem intervenção manual complexa. |

## 5. Próximos Passos Imediatos

Para iniciar a transformação no novo repositório `app.erpcondominios.com.br-`, sugere-se a seguinte ordem de execução:

1. **Criar a Migration Inicial (SQL):** Script que altera a tabela `empresa` e adiciona `tenant_id` nas 10 tabelas principais (`usuarios`, `moradores`, `unidades`, `veiculos`, `visitantes`, `contas_pagar`, `contas_receber`, `os_chamados`, `produtos_estoque`, `leituras`).
2. **Atualizar `auth_helper.php` e `validar_login.php`:** Injetar o conceito de tenant na sessão.
3. **Refatorar o CRUD de Moradores:** Usar como "Módulo Piloto" para validar o isolamento de dados no backend e frontend.
4. **Remover Hardcodes de CORS:** Limpar os 62 arquivos identificados com domínio fixo.
