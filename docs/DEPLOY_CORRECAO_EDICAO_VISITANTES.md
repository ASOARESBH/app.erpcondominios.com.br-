# Correção da edição de visitantes

## Escopo

Esta atualização corrige o comportamento do ícone **Editar** na aba **Listagem** do módulo **Visitantes**. Antes da correção, o sistema preenchia o formulário internamente, mas mantinha a aba Listagem visível. Como o formulário Cadastro fica oculto nesse estado, a edição aparentava não executar nenhuma ação.

A correção centraliza a navegação entre as abas e faz com que o comando de edição ative automaticamente a aba **Cadastro** antes de exibir o formulário preenchido. Também foram adicionadas validações defensivas e logs no console do navegador para os casos de registro ausente no cache ou formulário incompleto.

## Arquivo para publicar

| Caminho no pacote | Destino no HostGator |
|---|---|
| `frontend/js/pages/visitantes.js` | `public_html/frontend/js/pages/visitantes.js` |

Não há alteração de banco de dados, API, tabelas ou dados de visitantes nesta entrega.

## Publicação

Faça backup do arquivo existente e envie o arquivo corrigido preservando exatamente a estrutura de pastas. Após o upload, acesse o módulo Visitantes e atualize o navegador com **Ctrl+F5** para garantir que a versão do JavaScript seja recarregada.

## Roteiro de validação

1. Abra **Visitantes > Listagem**.
2. Clique no ícone de edição de qualquer registro.
3. Confirme que a aba **Cadastro** é aberta automaticamente.
4. Confirme que o título muda para **Editar Visitante**, os campos são preenchidos e o botão apresenta **Atualizar Visitante**.
5. Sem alterar dados, clique em **Cancelar Edição** e confirme que o formulário retorna ao modo de cadastro.
6. Edite um campo de teste e atualize o visitante; confirme que o registro continua pertencendo ao tenant da sessão.

## Evidências de validação

O controlador JavaScript foi validado por teste de regressão que confirma a ativação da aba Cadastro, o preenchimento dos campos e o modo de atualização. A sintaxe do JavaScript e da API PHP foi verificada sem erros. A API continua atualizando o registro por `tenant_id` obtido da sessão.
