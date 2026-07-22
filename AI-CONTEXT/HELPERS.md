# Helpers (Funções Auxiliares)

## 1. `auth_helper.php`
- `verificarAutenticacao()`: Valida sessão ativa e permissão mínima.
- `verificarPermissao()`: Checa nível de hierarquia.
- `obterUsuarioAutenticado()`: Retorna dados da sessão atual.
- `retornarSucesso() / retornarErro()`: Formatação padrão de JSON.

## 2. JS Helpers
- `ui-component-pattern.js`: Padrões para construção de UI.
- `session-manager-core.js`: Helper global para lidar com tokens, logout e expiração de sessão.
