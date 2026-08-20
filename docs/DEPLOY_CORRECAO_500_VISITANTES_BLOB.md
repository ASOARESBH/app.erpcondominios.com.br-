# Correção HTTP 500 — cadastro e edição de visitantes

## Causa identificada

A API de visitantes chamava a função central `registrar_log()` com um argumento extra: o objeto de conexão do banco. A função aceita somente `tipo`, `descricao` e, opcionalmente, `usuario`. Em PHP 8, o quarto argumento gera `ArgumentCountError` após a operação de banco, encerra a resposta e produz HTTP 500 com corpo vazio. Por isso o navegador apresentava `Unexpected end of JSON input` mesmo quando a atualização já havia alcançado a consulta SQL.

## Correção aplicada

As chamadas de auditoria foram corrigidas para a assinatura oficial. A API agora possui um tratador global de exceções que sempre retorna JSON, inclusive em falhas inesperadas. O frontend lê a resposta como texto com validação defensiva antes de interpretar JSON, oferecendo mensagem compreensível em vez de falha de parser.

O upload de foto e documento continua usando `tenant_file_gravar_upload()`, que persiste o conteúdo em `tenant_arquivos`. Antes de gravar qualquer BLOB, a API confirma que o visitante pertence ao tenant autenticado. Isso impede anexos órfãos e garante que a foto e o documento fiquem isolados pelo `tenant_id`.

## Arquivos para publicar

| Caminho no pacote | Destino no HostGator |
|---|---|
| `api/api_visitantes.php` | `public_html/api/api_visitantes.php` |
| `frontend/js/pages/visitantes.js` | `public_html/frontend/js/pages/visitantes.js` |

## Roteiro de validação

1. Atualize os dois arquivos preservando a estrutura de pastas.
2. Faça **Ctrl+F5** no navegador.
3. Abra um visitante existente e altere um campo simples, como observação. A resposta deve ser JSON e exibir sucesso.
4. Envie uma foto ou um documento na edição. Confirme que a listagem atualiza o indicador correspondente.
5. No banco, confirme que o registro em `visitantes` contém o caminho lógico do arquivo e que existe uma linha ativa correspondente em `tenant_arquivos` com o mesmo `tenant_id`.

## Validação técnica

Foram verificadas as assinaturas da função de auditoria, a sintaxe PHP/JavaScript, o fluxo de anexo multipart e o tratamento de resposta JSON. A regra de isolamento permanece baseada no tenant da sessão e todos os BLOBs são validados antes da associação ao visitante.
