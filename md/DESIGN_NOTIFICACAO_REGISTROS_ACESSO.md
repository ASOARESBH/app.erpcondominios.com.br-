# Notificações de Entrada e Saída — Controle de Acesso

## Evento

Toda inserção bem-sucedida em `registros_acesso` associada a um morador ou a uma unidade gera um evento persistente de Controle de Acesso. Os tipos são `acesso_entrada` e `acesso_saida`.

## Destinatários

Quando o registro possui `morador_id`, somente esse morador recebe o evento. Quando há apenas `unidade_destino`, a API localiza os moradores ativos da mesma unidade e tenant. Essa resolução ocorre no servidor, nunca no aplicativo.

## Conteúdo de privacidade

O aviso inclui apenas o tipo de movimentação, data/hora, placa, modelo opcional, categoria (`Morador`, `Visitante` ou `Prestador`) e unidade. Não inclui CPF, RG, TAG, token, documento, observação interna ou dados de outros moradores.

| Tipo | Título | Exemplo de mensagem |
|---|---|---|
| `acesso_entrada` | `Entrada registrada` | `Entrada de Visitante registrada para Gleba 133. Placa ABC-1234.` |
| `acesso_saida` | `Saída registrada` | `Saída de Morador registrada para Gleba 133. Placa PBB0172.` |

## Não duplicidade

A tabela de notificações recebe `registro_acesso_id` e uma chave única por tenant, morador, registro e tipo. Assim, os fluxos manual, visitante/QR e console físico podem chamar o mesmo helper sem gerar dois avisos para a mesma movimentação.

## Resiliência

O registro de acesso é prioritário. Falhas de esquema, FCM, token ou rede são registradas em log e não cancelam a entrada/saída já gravada. O evento fica disponível no aplicativo assim que a persistência no banco ocorre; o push é complementar.
