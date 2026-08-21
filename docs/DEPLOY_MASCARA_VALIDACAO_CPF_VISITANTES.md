# Máscara e validação de CPF — Visitantes

## Alteração entregue

A listagem de visitantes passa a formatar qualquer CPF armazenado sem pontuação no padrão `000.000.000-00`. O valor é formatado somente para apresentação; os registros existentes não são alterados por essa visualização.

No formulário de cadastro e de edição, o campo CPF aplica máscara durante a digitação e bloqueia o salvamento quando o número possui tamanho incorreto, dígitos repetidos ou dígitos verificadores inválidos. A mesma validação é aplicada no backend, que também persiste todo CPF válido já no formato padronizado.

## Arquivos para publicar

| Caminho no pacote | Destino no HostGator |
|---|---|
| `frontend/js/pages/visitantes.js` | `public_html/frontend/js/pages/visitantes.js` |
| `api/api_visitantes.php` | `public_html/api/api_visitantes.php` |
| `api/helpers/cpf_helper.php` | `public_html/api/helpers/cpf_helper.php` |

Não há migration, alteração de tabela ou manipulação retroativa de dados nesta atualização.

## Roteiro de validação

1. Abra **Visitantes > Listagem** e confirme que CPFs sem pontuação, como `94073163604`, são mostrados como `940.731.636-04`.
2. Abra **Cadastro**, selecione **CPF** e digite um CPF válido. Confirme a máscara durante a digitação.
3. Tente salvar `111.111.111-11` ou um CPF de 11 dígitos com dígitos verificadores incorretos. O cadastro deve ser bloqueado com mensagem clara.
4. Salve um CPF válido e confirme que a listagem o apresenta no padrão `000.000.000-00`.
5. Edite um visitante com CPF e confirme que a mesma validação é executada antes da atualização.

## Validação técnica realizada

Foram executados testes de CPF válido, CPF sem máscara, dígitos repetidos, dígito verificador incorreto e CPF incompleto. Também foi validado que a listagem mascara registros legados e que o frontend bloqueia uma tentativa de cadastro inválida antes de enviar `POST` ou `PUT`. As verificações de sintaxe JavaScript e PHP foram concluídas sem erros.
