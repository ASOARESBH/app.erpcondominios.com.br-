# Hotfix — Redirecionamento e acesso ao Painel Super-Admin

O login do usuário institucional `admin@erpcondominios.com.br` era autenticado, mas a tela de login redirecionava todo perfil ERP para o dashboard, ignorando o destino retornado pela API. Além disso, o filtro central de módulos não reconhecia `super_admin` como acesso administrativo total. Essas duas regras faziam o usuário entrar no dashboard comum, sem a experiência administrativa global esperada.

| Arquivo | Correção |
|---|---|
| `api/api_verificar_tipo_login.php` | Preserva a permissão global `super_admin` quando houver vínculo em `usuario_tenant`, retorna `is_super_admin` e define como destino inicial `?page=superadmin`. |
| `frontend/login.html` | Respeita o campo `redirect` devolvido pela autenticação, com validação restrita à rota interna do layout. |
| `api/api_permissoes_modulos.php` | Concede a `super_admin` o mesmo acesso amplo a módulos que o perfil `admin`, sem substituir a autorização específica da API Super-Admin. |
| `frontend/js/app-router.js` | Corrige URLs antigas ou cache que abram o dashboard para uma sessão global de Super-Admin, preservando a navegação quando ele estiver operando dentro de um tenant. |

## Implantação

Envie o conteúdo do pacote ZIP para a raiz da aplicação no HostGator, preservando todos os diretórios. Não há migration de banco para esta correção. Após o upload, saia do sistema e entre novamente com a conta institucional; use `Ctrl+F5` se o navegador mantiver arquivos em cache.

O resultado esperado é que a conta com `usuarios.permissao = 'super_admin'` seja redirecionada para `https://app.erpcondominios.com.br/frontend/layout-base.html?page=superadmin`. Contas com permissão `admin` continuam no dashboard operacional e não recebem acesso ao painel global.

> A regra evita que uma permissão local de `usuario_tenant` reduza uma conta cadastrada globalmente como `super_admin`. Ela não promove administradores comuns para Super-Admin.
