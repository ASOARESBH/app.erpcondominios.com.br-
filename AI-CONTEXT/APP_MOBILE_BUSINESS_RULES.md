# App Mobile (Flutter) — Regras de Negócio por Módulo

> Complementa [APP_MOBILE_ARCHITECTURE.md](APP_MOBILE_ARCHITECTURE.md). Lista, tela a tela, o que cada módulo faz, com quais endpoints fala e quais regras de negócio estão implementadas no cliente (o backend pode ter regras adicionais — este documento descreve apenas o que o app Flutter faz). Datas e nomes de arquivo refletem o estado do código em 2026-08-13.

## 1. Mapa de endpoints por tela (Portal do Morador)

Todas as respostas seguem o padrão `{sucesso, mensagem, dados}` (ver [API.md](API.md)). Base fixa: `https://app.erpcondominios.com.br`.

| Tela | Constante (`AppConstants`) | Arquivo PHP | Ações/parâmetros usados pelo app |
|---|---|---|---|
| Login | `endpointLogin` | `api_portal.php` | `action=login` (`cpf` só dígitos, `senha`) |
| Verificar sessão | `endpointVerifySession` | `api_portal.php` | `action=verificar_sessao` |
| Logout | `endpointLogout` | `logout_morador.php` | POST sem parâmetros |
| Esqueci senha | `endpointPasswordRecovery` | `api_recuperar_senha.php` | POST `{email}` |
| Perfil | `endpointPortal` | `api_portal_morador.php` | `action=perfil` (GET leitura, PUT `{telefone, celular}`) |
| Alterar senha | `endpointPortal` | `api_portal_morador.php` | PUT `action=alterar_senha` `{senha_atual, senha_nova}` |
| Visitantes | `endpointPortal` | `api_portal_morador.php` | `action=visitantes` (GET lista / POST cria / DELETE `id`) |
| Veículos (somente leitura) | `endpointPortal` | `api_portal_morador.php` | `action=veiculos` |
| Notificações (lista + marcar lida) | `endpointPortal` | `api_portal_morador.php` | `action=notificacoes` (`limite`), `action=marcar_notificacao_lida` |
| Registrar/remover token push | `endpointPortal` | `api_portal_morador.php` | `action=registrar_token_push` / `action=desativar_token_push` |
| Controle de Acesso (histórico) | `endpointPortal` | `api_portal_morador.php` | `action=controle_acesso` (`limite`, `tipo`, `data_inicio`, `data_fim`) |
| Acessos (gerar/revogar QR) | `endpointAcessos` | `api_acessos_visitantes.php` | GET lista por `morador_id`; POST cria; DELETE `id`; QR gerado client-side apontando para `action=gerar_qrcode&id=` |
| Dependentes | `endpointDependentes` | `api_portal_dependentes.php` | `action=listar` / `action=criar` / `action=excluir` |
| Hidrômetro (somente leitura) | `endpointHidrometro` | `api_morador_hidrometro.php` | GET sem parâmetros — retorna `{hidrometro, leituras}` |
| Documentos | `endpointDocumentos` | `api_portal_documentos.php` | ⚠ ver bug documentado em `DEBUG_DOCUMENTOS_PORTAL_VAZIO_20260813.md` |
| Projetos (somente leitura) | `endpointProjetos` | `api_portal_projetos.php` | `acao=listar` / `acao=detalhe&id=` |
| Marketplace | `endpointMarketplace` | `api_portal_marketplace.php` | `acao=vitrine` / `acao=meus_pedidos` / `acao=contratar` |
| Chamados (tickets) | `endpointOS` | `api_portal_os.php` | `action=listar_assuntos`, `action=listar` (`pagina`, `status`), `action=abrir`, `action=detalhe&id=` — ⚠ ver bug do `initBaseUrl()` em `APP_MOBILE_ARCHITECTURE.md` §6 |
| Protocolos (somente leitura) | `endpointProtocolos` | `api_morador_protocolos.php` | GET (`status` opcional) |

**Constantes declaradas e não usadas por nenhuma tela lida**: `endpointPushToken` (`/api/api_pwa_push.php`) e `endpointNotificacoes` (`/api/api_morador_notificacoes.php`). O fluxo real de push e de notificações passa por `endpointPortal` (`api_portal_morador.php`) com as actions correspondentes. Isso não é um bug funcional, mas pode confundir quem for alterar o app assumindo que esses dois endpoints estão em uso — vale checar se `api_pwa_push.php`/`api_morador_notificacoes.php` ainda são chamados por algum outro cliente (painel web, app antigo) antes de remover ou alterar contrato.

## 2. Mapa de endpoints (Portal do Colaborador)

Único endpoint para tudo: `endpointColaborador` → `api_colaborador_mobile.php`, roteado por `action`. Autenticação sempre via header `Authorization: Bearer <colaborador_token>` (exceto login).

| Ação (`AppConstants`) | Uso |
|---|---|
| `actionLoginColaborador` | Login (e-mail, senha, `tenant_id` opcional quando o e-mail pertence a mais de um condomínio) |
| `actionSessaoColaborador` | Restaurar/validar sessão ao abrir o shell do colaborador |
| `actionDashboardColaborador` | Métricas do dashboard (`protocolos_pendentes`, `chamados_abertos`, `entregas_hoje`) |
| `actionMoradoresColaborador` | Busca de morador por nome/unidade (usado em Receber Mercadoria e em Leitura de Hidrômetro) |
| `actionAssuntosColaborador` / `actionChamadosColaborador` / `actionAbrirChamadoColaborador` | Assuntos disponíveis, listar meus chamados, abrir chamado |
| `actionProtocolosColaborador` (`status=pendente|entregue`) | Lista de protocolos |
| `actionBuscarProtocoloQr` (`codigo=`) | Localiza protocolo por código lido no scanner |
| `actionReceberProtocolo` / `actionEntregarProtocolo` | Registrar recebimento / confirmar entrega |
| `actionHidrometrosLeiturista` (`morador_id=`) | Hidrômetros ativos da unidade do morador selecionado |
| `actionFotoHidrometro` (multipart) / `actionRegistrarLeituraHidrometro` | Upload de foto e registro da leitura (fila offline, ver arquitetura) |
| `post('logout')` | Encerra sessão no servidor (best-effort; sessão local é sempre limpa mesmo se essa chamada falhar) |

## 3. Portal do Morador — regras por tela

**Login** (`login_screen.dart`): CPF validado como 11 dígitos (aceita com ou sem máscara, limpa antes de validar/enviar), senha mínima de 4 caracteres no cliente (mais permissivo que a troca de senha no Perfil, que exige 6 — inconsistência de UX a considerar se alguém for padronizar). Toque quíntuplo no logo em até 3s abre o login do colaborador.

**Dashboard** (`dashboard_screen.dart`): não faz nenhuma chamada de rede — renderiza a partir da sessão local (`authProvider.session`), garantindo que a tela inicial nunca fica em loading após o login. Grade fixa com 12 módulos.

**Visitantes**: cadastro (nome completo, tipo de documento em `CPF/RG/CNH/Passaporte`, número, telefone, celular, e-mail, observação) + lista com exclusão (confirmação via diálogo). Não há edição, apenas criar/excluir.

**Acessos (QR Code)**: gera autorização de entrada vinculando um visitante já cadastrado + dados opcionais de veículo (placa maiúscula, modelo, cor) + período (`data_inicial`/`data_final`, valida final ≥ inicial) + tipo de acesso (`portaria`/`externo`/`lagoa`). O QR exibido é gerado **no cliente** (`qr_flutter`) a partir de uma URL de download (`api_acessos_visitantes.php?action=gerar_qrcode&id=`) — o QR não é obtido do servidor, apenas a URL que ele codifica. Permite revogar (exclui) o acesso.

**Controle de Acesso**: histórico somente leitura de entradas/saídas destinadas à unidade do morador; filtros por tipo (Morador/Visitante/Prestador) e período (data inicial/final, limite fixo de 100 registros por consulta). Unidade e tenant são sempre resolvidos no backend a partir do token — o app nunca informa qual unidade consultar.

**Dependentes**: cadastro (nome, parentesco em lista fechada, CPF, data de nascimento, e-mail, celular, observação) + lista + exclusão. Sem edição.

**Hidrômetro**: somente leitura — dados do hidrômetro ativo da unidade (número, lacre, data de instalação, status) + histórico de leituras em tabela + gráfico de barras dos últimos 6 meses de consumo (`fl_chart`). Todo cálculo de consumo/valor vem pronto do backend.

**Veículos**: somente leitura — lista veículos do morador e de dependentes (mostra `dependente_nome` quando aplicável), com placa, modelo, cor, TAG e status ativo/inativo.

**Chamados (Tickets)**: lista paginada com filtro por status (`aberto`/`andamento`/`finalizado`), abre chamado via bottom sheet (título, assunto opcional vindo de `listar_assuntos`, descrição até 2000 caracteres) e mostra detalhe/histórico de interações em outro bottom sheet. **Ambos os fluxos de escrita/detalhe estão quebrados** pelo bug do `initBaseUrl()` documentado em `APP_MOBILE_ARCHITECTURE.md` §6 — apenas a listagem funciona hoje.

**Protocolos**: somente leitura — encomendas/mercadorias recebidas na portaria para a unidade, com filtro pendente/entregue.

**Documentos**: ver diagnóstico completo em `DEBUG_DOCUMENTOS_PORTAL_VAZIO_20260813.md` — a tela sempre aparece vazia porque chama a ação errada da API (`documentos_listar` sem `pasta_id` em vez de `buscar`).

**Projetos**: lista pública de obras/projetos do condomínio com percentual de execução e linha do tempo de atualizações (somente leitura, sem interação do morador além de abrir o detalhe).

**Marketplace**: aba "Vitrine" lista produtos/serviços de fornecedores parceiros; tocar em um item abre modal para descrever a necessidade e enviar uma solicitação de contratação (`acao=contratar`). Aba "Meus Pedidos" mostra o status das solicitações já enviadas (somente leitura).

**Perfil**: exibe nome/CPF/unidade/e-mail (somente leitura, vindos do cadastro), permite atualizar telefone/celular e trocar senha (exige senha atual + nova + confirmação, valida coincidência apenas no cliente antes de enviar).

**Notificações**: lista paginada (limite 50) com contagem de não lidas, permite marcar individualmente ou todas como lidas, e um toggle "Alertas no dispositivo" que ativa/desativa push (opt-in) sem afetar a disponibilidade da lista em si.

## 4. Portal do Colaborador — regras por tela

**Login**: e-mail corporativo + senha. Quando o e-mail está vinculado a mais de um tenant, a API responde `requer_selecao_tenant=true` com a lista de condomínios e a tela pede a escolha antes de autenticar de fato (novo POST com `tenant_id`).

**Dashboard**: métricas (pendentes/chamados/entregas de hoje) + atalhos de navegação para os 7 módulos operacionais.

**Chamados do colaborador**: abrir chamado (título, assunto — ao escolher, preenche automaticamente o departamento do assunto —, prioridade `baixa/media/alta/urgente`, descrição) e listar os próprios chamados abertos (somente status, sem thread de interação nesta tela).

**Protocolos**: lista segmentada Pendentes/Entregues. Cartão de protocolo pendente navega para a tela de Entrega levando o registro via `extra` do GoRouter (evita nova busca).

**Receber mercadoria**: busca morador por nome/unidade com debounce de 350ms (mínimo 2 caracteres), permite ler código via câmera (reaproveitando a tela de QR Scanner) ou digitar manualmente, registra descrição da mercadoria, código/rastreio, página (opcional), data/hora de recebimento (editável, padrão agora) e observação. O colaborador autenticado é gravado automaticamente como quem recebeu — não há campo para escolher outro operador.

**Entrega**: localiza o protocolo por código/QR (bloqueia se já estiver `entregue`), exige nome de quem retirou + CPF completo (11 dígitos, somente números) de confirmação antes de liberar a entrega — regra de auditoria explícita no texto da tela ("o nome do recebedor fica registrado na auditoria").

**QR Scanner**: tela genérica (câmera com lanterna/troca de câmera), devolve o texto lido via `Navigator.pop(value)`; é reaproveitada tanto por Receber Mercadoria quanto por Entrega — não é uma tela de negócio própria.

**Leitura de hidrômetro (leiturista)**: busca morador → lista hidrômetros ativos da unidade dele → cada hidrômetro indica se **já existe leitura lançada na competência atual** (`leitura_no_mes`), bloqueando nova leitura duplicada no mesmo mês → tela de lançamento permite leitura manual (validação: não pode ser menor que a leitura anterior) e/ou foto com sugestão OCR (checkbox de confirmação humana obrigatória quando há foto) → grava na fila offline local e tenta sincronizar imediatamente; se offline, fica pendente até haver conexão (ver `APP_MOBILE_ARCHITECTURE.md` §7). Contador de pendências e botão de sincronização manual sempre visíveis na tela.

## 5. Regras cross-cutting (valem para os dois portais)

- **Tenant/unidade sempre resolvidos no backend pelo token** — o app nunca envia `tenant_id`/`unidade_id` escolhido livremente pelo usuário, exceto a seleção explícita de tenant no login do colaborador (que a API então valida contra o e-mail informado).
- **Padrão de resposta**: toda tela espera `{sucesso, mensagem, dados}`; como os dois clientes HTTP aceitam qualquer status < 500 como resposta "normal" (ver arquitetura §2), **qualquer tela nova deve checar `sucesso` explicitamente e tratar o caso `false` com feedback ao usuário** — não presumir que ausência de exceção significa sucesso. O bug de Documentos é o exemplo real desse padrão de erro passando despercebido.
- **CPF/telefone**: sempre limpos de máscara (`replaceAll(RegExp(r'[^\d]'), '')`) antes de enviar ao backend, tanto no login do morador quanto na confirmação de identidade na Entrega do colaborador.
- **Confirmações destrutivas** (excluir visitante, dependente, revogar acesso) sempre passam por `AlertDialog` de confirmação antes da chamada de API.
