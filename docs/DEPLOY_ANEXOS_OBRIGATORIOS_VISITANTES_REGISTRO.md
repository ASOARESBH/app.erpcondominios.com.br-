# Foto e documento obrigatórios — Visitantes e Registro Manual

## Regra de negócio entregue

Todo cadastro de visitante passa a exigir **ao menos uma evidência**: **foto**, **documento digitalizado** ou ambos. Os anexos enviados seguem junto dos dados do cadastro e são gravados como BLOBs isolados por tenant. Se não houver nenhum anexo, ou se o anexo enviado falhar, o cadastro inteiro é cancelado; não permanece visitante sem evidência no banco.

No **Registro Manual**, os tipos **Visitante** e **Prestador** somente podem ser registrados a partir de um visitante localizado por documento. A API valida que o registro pertence ao tenant da sessão, possui `documento_arquivo` e que o arquivo correspondente está ativo em `tenant_arquivos`. Caso contrário, o sistema informa claramente que falta o documento anexado e bloqueia a entrada/saída.

## Arquivos para publicar

| Caminho no pacote | Destino no HostGator |
|---|---|
| `frontend/pages/visitantes.html` | `public_html/frontend/pages/visitantes.html` |
| `frontend/js/pages/visitantes.js` | `public_html/frontend/js/pages/visitantes.js` |
| `frontend/pages/registro.html` | `public_html/frontend/pages/registro.html` |
| `frontend/js/pages/registro.js` | `public_html/frontend/js/pages/registro.js` |
| `api/api_visitantes.php` | `public_html/api/api_visitantes.php` |
| `api/api_registros.php` | `public_html/api/api_registros.php` |

Não é necessário executar migration nesta atualização.

## Roteiro de validação

1. Abra **Visitantes > Cadastro** e tente salvar sem foto e sem documento. O sistema deve bloquear o cadastro.
2. Selecione apenas uma foto, preencha os demais campos válidos e conclua o cadastro. Confirme o registro na listagem.
3. Repita o cadastro usando apenas documento digitalizado. Confirme o registro na listagem.
4. Abra **Registro Manual**, selecione **Visitante** ou **Prestador**, informe o documento e pesquise.
5. Para um cadastro que possui somente foto, confirme a mensagem de pendência e a impossibilidade de registrar o acesso manual.
6. Para um cadastro com documento ativo, complete unidade, morador de destino e os demais dados. Confirme que o registro é criado.
7. Tente utilizar um identificador de visitante de outro tenant por requisição direta. A API deve bloquear a tentativa.

## Validação técnica realizada

O fluxo de cadastro foi testado sem anexo, com foto apenas e com documento apenas. O cenário utiliza `multipart/form-data`, contendo dados e os anexos efetivamente selecionados no mesmo `POST`. Foram validadas as sintaxes JavaScript/PHP, a persistência transacional prevista para os anexos e o filtro obrigatório de `tenant_id` na listagem e no Registro Manual.
