# Autenticação e Autorização

## 1. Fluxo de Login
1. Usuário acessa `login.html` e envia `email` + `senha` via `POST` para `api/api_login.php`.
2. O PHP valida as credenciais no banco (`usuarios` tabela), verifica `password_verify()`.
3. Se válido, inicia uma sessão PHP (`session_start()`), armazena `$_SESSION['usuario_id']`, `$_SESSION['usuario_nome']`, `$_SESSION['usuario_permissao']`.
4. Retorna JSON `{"sucesso": true}` com o cookie `PHPSESSID` no header.
5. O frontend redireciona para `layout-base.html`.

## 2. Validação em Cada Requisição
Toda API protegida inclui `auth_helper.php` e chama `verificarAutenticacao()`.
Esta função verifica se a sessão está ativa e se o nível de permissão é suficiente.

Quando as tabelas RBAC estão instaladas, operações também devem validar a ação com
`rbacExigir()` usando usuário e tenant autenticados. A decisão `admin/super_admin` é
resolvida pelo perfil atual do usuário no banco; o papel armazenado numa sessão antiga
não pode conceder acesso total após rebaixamento.

## 3. Hierarquia de Permissões
| Nível | Nome | Acesso |
|---|---|---|
| 1 | visualizador | Apenas leitura |
| 2 | operador | Leitura + criação básica |
| 3 | gerente | Edição avançada + relatórios |
| 4 | admin | Configurações + exclusão + integrações |

## 4. Portal do Morador (Sessão Separada)
O Portal do Morador usa uma sessão separada (`sessoes_portal` tabela) com token JWT-like, diferente da sessão do ERP administrativo.
