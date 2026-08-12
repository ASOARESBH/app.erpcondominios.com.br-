# Hotfix — Carregamento de dados de condomínio no Super-Admin

O modal **Gerenciar Condomínio** chamava `GET /api/api_superadmin.php?action=tenant&id=1`, mas recebia HTTP 500 sem corpo JSON. A causa era uma divergência de esquema: a API consultava a coluna inexistente `usuario_tenant.created_at`, enquanto a tabela implantada no banco usa `usuario_tenant.criado_em`.

| Arquivo | Correção aplicada |
|---|---|
| `api/api_superadmin.php` | Substitui `created_at` por `criado_em` nas consultas de usuários vinculados e no gráfico de crescimento de usuários. |
| `frontend/js/pages/superadmin.js` | Trata respostas HTTP vazias ou inválidas sem executar `response.json()` cegamente, evitando o erro `Unexpected end of JSON input` e exibindo uma mensagem controlada. |

## Implantação

Envie os arquivos do ZIP para a raiz da aplicação no HostGator e preserve a estrutura de diretórios. Não há migration adicional. Depois do envio, use `Ctrl+F5` e abra novamente **Super Admin → Gerenciar** em um condomínio.

O modal deve preencher as abas **Informações**, **Usuários**, **Módulos** e **Plano**. O gráfico do dashboard global também passa a consultar a data real de criação dos vínculos de usuário.
