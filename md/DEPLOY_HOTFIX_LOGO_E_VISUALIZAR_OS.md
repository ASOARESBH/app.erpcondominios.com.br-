# Hotfix — Logo do tenant e visualização de Ordens de Serviço

Este hotfix corrige dois efeitos identificados após a atualização anterior. O primeiro é o erro 404 para uma URL legada como `logo.jpg`, que deixava a barra lateral sem imagem. O segundo é a quebra do atributo `onclick` do botão **Visualizar**, causada por aspas concorrentes ao transmitir o número da Ordem de Serviço ao JavaScript.

| Arquivo | Correção aplicada |
|---|---|
| `frontend/js/user-profile-sidebar.js` | A logo é validada antes de ser usada. Apenas URLs da API central, da marca institucional ou do caminho legado controlado `/uploads/logo/` são aceitas. URL inválida ou erro de imagem retorna à marca institucional sem ocultar o elemento. |
| `frontend/js/sessao_manager.js` | O carregador legado não sobrescreve mais a logo já validada pela barra lateral. |
| `frontend/js/pages/ordens_servico.js` | Os botões de visualização usam atributos HTML com aspas seguras e número da O.S. serializado corretamente. Isso permite abrir registros válidos e mantém a compatibilidade temporária com O.S. legadas. |

## Implantação

Envie o conteúdo do pacote à raiz da aplicação no HostGator, mantendo a estrutura de pastas. Não há migration adicional para este hotfix. Depois de enviar, atualize a página com `Ctrl+F5` ou limpe o cache do navegador.

A logo institucional continua sendo exclusiva da tela de login. Após autenticação, a barra lateral carregará a marca do tenant ativo quando houver uma URL válida; se a logo antiga não estiver disponível, mostrará a marca institucional, sem fazer requisições para `logo.jpg` ou outro caminho inválido.

## Teste rápido

| Cenário | Resultado esperado |
|---|---|
| Abrir o sistema autenticado | A área da logo não fica vazia. |
| Cache antigo contém `logo.jpg` | Não ocorre erro 404; a marca institucional é mostrada até a API confirmar a logo correta. |
| Clicar no ícone de olho de uma O.S. | A chamada é enviada com `id` e número válidos, e o detalhe é aberto. |
| Trocar de tenant | A logo é consultada novamente pela sessão do tenant ativo. |
