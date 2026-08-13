# Hotfix Veículos — Edição, Paginação e Estabilidade

Este pacote corrige a tela de **Veículos** do ERP Condomínios e preserva o layout existente do sistema.

| Correção | Resultado |
|---|---|
| Botão Editar | O clique é tratado por delegação de eventos e abre automaticamente a aba **Cadastrar**, preenchendo o formulário com o veículo selecionado. |
| Erro 503 | A API não executa mais `ALTER TABLE` durante uma requisição. Alterações estruturais foram transferidas para uma migration. |
| Paginação | A lista inicial mostra 20 veículos. O usuário pode selecionar 20, 50 ou 100 registros por página e navegar pelos controles inferior da tabela. |
| Busca | A pesquisa usa paginação no servidor e consulta somente o tenant autenticado. |
| Multi-Tenant | Listagens, consultas por ID, relatórios, inserções e vínculo de dependentes utilizam o `tenant_id` obtido da sessão. |
| Falhas de API | Erros inesperados são capturados e retornam JSON seguro, sem expor detalhes técnicos ao navegador. |

## Implantação

1. Faça backup do banco `inlaud99_erpcondor` via phpMyAdmin.
2. Execute `sql/migration_veiculos_paginacao_mysql57.sql`. Ela garante a coluna `tipo` de modo idempotente, fora do fluxo da API.
3. Envie os arquivos do ZIP ao HostGator preservando a estrutura de pastas.
4. Use `Ctrl+F5` no navegador e abra **Veículos**.
5. Valide a paginação, clique em **Editar** e confirme que a aba de cadastro abre preenchida. Salve uma alteração para confirmar que o registro permanece no tenant ativo.

Não há alteração de credenciais nem de configuração em `api/config.php`.
