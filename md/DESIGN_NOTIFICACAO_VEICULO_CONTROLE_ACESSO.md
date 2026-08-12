# Notificação de Veículo — Controle de Acesso

## Evento

O evento `veiculo_cadastrado` será criado após o cadastro bem-sucedido de um veículo pela API administrativa. O evento é complementar ao cadastro: uma falha no Firebase ou na tabela de notificações nunca poderá cancelar a gravação do veículo.

## Destinatário e isolamento

O destinatário é o morador vinculado ao veículo. O `tenant_id` vem da sessão administrativa, e o vínculo `veiculos.morador_id` é validado no mesmo tenant antes de persistir a notificação. Nenhum identificador de morador ou tenant recebido do cliente móvel é usado para compor o evento.

## Conteúdo

| Campo | Valor |
|---|---|
| Tipo | `veiculo_cadastrado` |
| Origem | `controle_acesso` |
| Título | `Veículo cadastrado` |
| Mensagem | `O veículo {placa} ({modelo}) foi cadastrado para a sua unidade.` |
| Rota do aplicativo | `/home/notifications` |
| Referência | `veiculo_id` |

A placa é exibida porque já é a informação operacional visível ao morador na tela de Veículos. TAG, documentos e dados de dependentes não são enviados no push.

## Persistência e duplicidade

A tabela `notificacoes_morador` será estendida com `veiculo_id`, mantendo `protocolo_id` para os eventos de encomenda já existentes. Uma chave única por tenant, morador, veículo e tipo impede que uma repetição do POST ou uma falha de rede gere dois avisos do mesmo cadastro.

## Push e aplicativo

O push reutiliza os tokens FCM, a chave de serviço e o mecanismo já validado para protocolos. A preferência `push_controle_acesso_ativo` pode desativar apenas alertas desse domínio, sem desativar avisos de encomendas. A lista de notificações do aplicativo apresentará ícone de veículo e o cabeçalho Controle de Acesso para esse tipo.
