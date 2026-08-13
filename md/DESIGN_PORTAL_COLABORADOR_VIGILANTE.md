# Portal do Colaborador — Vigilante/Rondas Mobile

## Decisão de identidade

A sessão móvel autenticada contém `usuario_id`, `tenant_id`, `sessao_id` e `email`, provenientes exclusivamente do token Bearer. O aplicativo não envia `tenant_id` nem `colaborador_id` para registrar uma ronda.

O servidor resolve o vigilante em duas etapas:

| Situação | Resolução no servidor | Resultado |
|---|---|---|
| Um único `rh_colaboradores` ativo com o mesmo e-mail do usuário, no mesmo tenant | O `rh_colaboradores.id` é selecionado automaticamente | Leitura segue sem seleção manual |
| Nenhum ou mais de um colaborador com e-mail correspondente | A API registra divergência de cadastro e entrega somente os vigilantes ativos vinculados à rota como opções de exceção | O app pede confirmação do vigilante, usando token opaco de opção emitido pelo servidor |

Cada opção de fallback será um valor assinado pelo servidor, vinculado a `sessao_id`, `tenant_id`, `rota_id`, `colaborador_id` e expiração curta. O app devolve apenas esse valor opaco; nunca um `colaborador_id` cru. A API valida a assinatura, a sessão e o vínculo ativo do colaborador com a rota antes de gravar.

## Contrato das ações móveis

| Ação | Método | Requisitos | Retorno |
|---|---|---|---|
| `vigilante_qr_detalhe` | GET | Bearer + token QR hexadecimal de 64 caracteres | Ponto, rota e instruções do mesmo tenant; em caso de divergência de identidade, opções opacas de fallback |
| `vigilante_registrar_leitura` | POST | Bearer + token QR + GPS opcional + opção opaca de fallback quando exigida | Mensagem de negócio, status SLA, atraso, ponto e rota |
| `vigilante_historico_hoje` | GET | Bearer | Últimas leituras do colaborador resolvido no dia; sem expor leituras de terceiros |

## Garantias

A rota e o ponto são consultados por `tenant_id` da sessão e pelo token QR. O token nunca é usado como autorização entre tenants. A regra de ciclo, SLA e deduplicação é compartilhada em `api/helpers/ronda_helper.php` e usada pela API administrativa e pela API móvel. A chave de ciclo permanece `sha256(tenant_id|rota_id|colaborador_id|data:indice)`, preservando o índice único de `ronda_registros`.

As ações móveis mantêm o formato `{sucesso, mensagem, dados}`. Cada falha operacional é registrada por `cm_log` sem token, senha, CPF, coordenadas completas ou QR em texto. O painel administrativo existente continua consultando `ronda_registros`; assim, uma leitura criada no aplicativo aparece automaticamente no dashboard e relatórios.

## GPS e câmera

O scanner existente baseado em `mobile_scanner` será reutilizado. A câmera é requisito de hardware e a mensagem de permissão existente será preservada. O GPS é opcional e best-effort: permissão negada, GPS desligado ou indisponibilidade não bloqueiam a leitura. Quando disponível, latitude, longitude e precisão são enviados ao servidor e armazenados pela regra já existente.

## Observação de contexto

O arquivo solicitado `AI-CONTEXT/ANALISE_MODULO_VIGILANTE_RONDAS_MOBILE_20260813.md` não estava presente na cópia local do repositório na data da implementação. Esta decisão foi produzida a partir da leitura integral do `AI-CONTEXT/` disponível e do código administrativo de rondas existente.
