# Controle de acesso das Ordens de Serviço — 2026-09-16

## Regra funcional

No módulo **Ordens de Serviço**, usuários com perfil `admin`, `administrador`, `gerente` ou `super_admin` podem visualizar e operar todas as ordens do tenant autenticado. Os demais perfis podem consultar, editar, interagir, finalizar, imprimir e administrar materiais/projeto somente das ordens cujo campo `criado_por_id` corresponde ao próprio usuário autenticado.

A regra é baseada no usuário que abriu a ordem, e não no atendente, morador, departamento, número informado pelo cliente ou qualquer campo enviado pelo navegador. Portanto, atribuir uma ordem a outro atendente não concede a esse atendente acesso ao registro.

## Proteção no servidor

A API `api/api_ordens_servico.php` passou a aplicar o escopo do proprietário em dashboard, relatórios, listagem paginada, busca por ID ou número legado, interações, finalização, materiais, projetos, imagens de capa, fotos, documentos e vínculos entre ordens. Também impede que perfis restritos assumam ordens do portal de outros usuários ou vinculem uma ordem própria a uma ordem que não possam consultar. Alterações de configurações do módulo permanecem exclusivas de administradores e gerentes.

A API `api/api_imagem_projeto.php` permite a visualização de projeto não publicado apenas ao proprietário ou a um perfil gestor. Projetos marcados explicitamente como públicos continuam disponíveis pelo fluxo público já existente.

A API `api/api_notificacoes_os.php` sincroniza e exibe, para usuários comuns, somente alertas de ordens abertas por eles. Administradores e gerentes mantêm o acompanhamento operacional das ordens atribuídas, relacionadas ou abertas por eles.

## Proteção na interface

A tela oculta a aba de configurações e exibe um aviso de escopo para usuários restritos. Na tabela, os botões de editar e excluir aparecem somente para o proprietário da ordem ou para um perfil gestor. O detalhe aplica modo somente leitura quando a ordem não pode ser editada pelo usuário atual, enquanto a leitura da própria ordem continua disponível.

Esses controles são apenas uma camada de usabilidade. A autorização efetiva permanece no backend e não depende de `localStorage`, parâmetros ocultos, IDs recebidos pelo cliente ou alterações no JavaScript.

## Validação

Foi realizada revisão estática das consultas e ações da API, incluindo os fluxos de impressão, imagens e notificações. A validação dinâmica contra o banco de produção depende de sessão autenticada com perfis distintos e deve ser executada no ambiente de homologação antes do piloto, verificando pelo menos um usuário comum, um gerente e um administrador.
