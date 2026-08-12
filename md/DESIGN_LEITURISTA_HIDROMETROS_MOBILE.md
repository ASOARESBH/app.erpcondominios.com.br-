# Portal do Colaborador — Leitura de Hidrômetros Móvel

## Objetivo

O módulo de leiturista permite que um colaborador autorizado lance **uma leitura por vez**, associada a um hidrômetro, morador, unidade e tenant. O fluxo preserva as regras existentes do ERP: somente hidrômetro ativo, leitura atual igual ou superior à última leitura, uma leitura por competência mensal e cálculo de consumo/valor no servidor.

## Fluxo operacional

| Etapa | Online | Offline |
|---|---|---|
| Selecionar morador ou unidade | Pesquisa na API do tenant | Usa a última pesquisa em cache apenas para exibição; não permite selecionar dados sem identificador salvo |
| Consultar hidrômetro | Exibe número, lacre e última leitura | Exibe o medidor previamente carregado na sessão de trabalho |
| Digitar ou reconhecer leitura | Aceita edição manual obrigatória após OCR | Aceita edição manual obrigatória após OCR |
| Fotografar medidor | Comprime e guarda em diretório privado do app | Comprime e guarda em diretório privado do app |
| Confirmar lançamento | Envia foto, grava leitura e vincula evidência | Cria item pendente no banco local e mostra confirmação de fila |
| Sincronizar | Não aplicável | Tenta quando o app volta ao primeiro plano, quando há conexão e ao tocar em `Sincronizar agora` |

## OCR e confirmação humana

O OCR é executado **no próprio aparelho** com o ML Kit de reconhecimento de texto em escrita latina. Ele não substitui a validação humana: o aplicativo extrai candidatos numéricos da foto, sugere a maior sequência plausível e exige que o colaborador confira ou corrija o campo antes de salvar. A imagem original fica em diretório privado do aplicativo até a sincronização confirmada.

## Armazenamento offline

A fila usa banco SQLite no armazenamento interno privado do aplicativo. Cada pendência possui um UUID do cliente, identificador do hidrômetro, leitura, data/hora, caminho privado da foto, texto OCR, estado de sincronização e mensagem de erro. O UUID é enviado ao servidor e possui índice único por tenant, prevenindo leitura duplicada quando uma requisição é repetida após perda de conexão.

O aplicativo verifica o espaço livre do aparelho antes de salvar uma foto. Abaixo de **200 MB**, bloqueia novas fotos e orienta o envio ou a exclusão de pendências. Entre **200 MB e 500 MB**, permite salvar, porém mantém um aviso persistente.

## Servidor e isolamento multi-tenant

A API móvel do colaborador executa todas as consultas com `tenant_id` extraído do token Bearer. O servidor usa o mesmo cálculo, validações mensais e estrutura de `leituras` existente. A foto é armazenada pelo helper de arquivos por tenant e vinculada à leitura somente após a gravação bem-sucedida.

## Prevenção de duplicidade

1. O aplicativo gera `client_uuid` antes de criar a pendência ou enviar a leitura.
2. A API procura uma leitura existente do mesmo tenant e UUID antes de inserir.
3. Em caso de repetição, a API retorna a leitura já gravada como sucesso idempotente.
4. A regra original de uma leitura por hidrômetro/competência continua ativa para UUIDs diferentes.

## Limites

A foto é reduzida no aparelho para no máximo 1600 px de largura e qualidade JPEG 80 antes de entrar na fila. O servidor continua a aplicar o limite já existente de 8 MB e permite apenas JPEG, PNG e WEBP.

## Módulos do Portal do Colaborador envolvidos

| Módulo | Responsabilidade |
|---|---|
| Leiturista | Pesquisa, leitura, foto/OCR, fila offline e sincronização |
| Perfil do colaborador | Exibe quantidade de pendências e permite sincronização manual |
| API do colaborador | Autenticação Bearer, consulta individual e gravação idempotente |
| API de leituras/fotos | Mantida como referência das regras financeiras e de evidência |

## Referências técnicas

O ML Kit oferece reconhecimento de texto no aparelho para Android e iOS; o aplicativo utiliza a escrita latina para os algarismos do mostrador. [1] [2]

[1]: https://pub.dev/packages/google_mlkit_text_recognition
[2]: https://developers.google.com/ml-kit/vision/text-recognition/v2
