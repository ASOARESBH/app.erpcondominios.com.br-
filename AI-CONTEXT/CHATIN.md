# Chat-In — comunicação interna (2026-11-05)

Comunicador interno do condomínio entre **usuários do painel** (administração/portaria) e **moradores** (Portal do Morador e app Flutter), com avisos automáticos do condomínio. Menu do painel: **Chat-In**, logo abaixo de **Recursos Humanos**.

## 1. Decisões do produto (confirmadas pelo usuário)
- Entrega completa de uma vez (painel, portal, app, integrações).
- **Todos com todos**: morador ↔ morador do mesmo condomínio e morador ↔ usuários. Exibição apenas de **nome + unidade** (morador) ou **nome + função** (usuário). Morador pode **ocultar-se da busca** de outros moradores e **bloquear** contatos.
- **Tempo real por polling** (~5 s com o chat visível, ~20 s minimizado, pausa com aba oculta) + **push FCM** no app. Sem WebSocket (hospedagem HostGator compartilhada).
- **Texto e emojis** (sem anexos nesta versão). Limite de 2000 caracteres.
- Histórico mantido: mensagens nunca são apagadas; arquivar só oculta a conversa da lista do participante (nova mensagem desarquiva).

## 2. Arquivos
| Camada | Arquivo |
|---|---|
| Migration | `sql/migration_chatin_mysql57.sql` (só `CREATE TABLE IF NOT EXISTS`; sem `INFORMATION_SCHEMA`) |
| Regras | `api/helpers/chatin_helper.php` |
| API | `api/api_chatin.php` |
| Canais de notificação | `api/helpers/notificacao_canais_helper.php`, `api/api_notificacao_canais.php` |
| Eventos | `api/api_eventos.php`, `frontend/pages/eventos.html`, `frontend/js/pages/eventos.js`, `assets/css/pages/eventos.css` |
| Widget global | `frontend/js/chatin-widget.js`, `assets/css/chatin.css` (carregados em `layout-base.html` e `portal_morador.html`) |
| Página do painel | `frontend/pages/chatin.html`, `frontend/js/pages/chatin.js`, `assets/css/pages/chatin.css` |
| Sistema › Controle de Acesso | `frontend/pages/config_controle_acesso.html`, `frontend/js/pages/config_controle_acesso.js`, `assets/css/pages/config_controle_acesso.css` (importa `config_visitantes.css`) |
| App Flutter | `lib/domain/entities/chatin_entity.dart`, `lib/data/datasources/remote/chatin_remote_datasource.dart`, `lib/presentation/screens/chatin/chatin_screen.dart`, rotas `/home/chatin` e `/home/chatin/:id`, `notification_service.dart` (canal `chatin` + deep link) |

## 3. Modelo de dados (todas as tabelas com `tenant_id`, índices iniciando por `tenant_id`)
- `chatin_conversas` — `tipo` `DIRETA` | `AVISOS`; `chave_unica` única por tenant: DIRETA = participantes ordenados (`M12|U3`), AVISOS = `AVISOS|M12` (uma por pessoa). Guarda `ultima_mensagem_id/em`.
- `chatin_participantes` — (`conversa_id`, `participante_tipo` USUARIO|MORADOR|SISTEMA, `participante_id`), `ultima_leitura_id`, `silenciado`, `arquivado`.
- `chatin_mensagens` — remetente (tipo/id/**nome snapshot**), `tipo` TEXTO|AVISO, `titulo`, `corpo`, `referencia_tipo/id` (acesso, evento, assembleia, documento), `idempotency_key` única por remetente.
- `chatin_presenca` — PK (tenant, tipo, id), `ultimo_ping`, `plataforma` (painel/portal/app), `conversa_aberta_id`. Online = ping nos últimos 60 s. Escrita com throttle de 15 s.
- `chatin_preferencias` — `visivel_busca`, `mostrar_online`, `notificar_push` (padrão 1).
- `chatin_bloqueios` — bloqueador → bloqueado (bloqueio esconde nos dois sentidos e impede envio).
- `notificacao_canais_config` — PK (tenant, evento, canal), `ativo`, `config_json` (reservado a provedores futuros).
- `eventos_condominio` — título, descrição, local, início/fim, `publico` TODOS|UNIDADES + `unidades_json`, `status` AGENDADO|CANCELADO|REALIZADO, `notificado_chatin_em`, `notificados_total`, autoria.

## 4. API `api/api_chatin.php`
Resposta padrão `{sucesso, mensagem, dados, codigo?}`.
- **Autenticação**: `Authorization: Bearer` (token de `sessoes_portal`) → MORADOR; senão sessão PHP do painel → USUARIO. Identidade e tenant **sempre** do servidor. Migration ausente → 503 `NAO_INSTALADO` (o widget se desativa silenciosamente).
- `GET sync&plataforma=&versao=&conversa_id=&apos_id=&marcar_lida=1` — toca presença, devolve `eu`, `versao`, `total_nao_lidas`; `conversas` só quando a versão mudou; mensagens novas e `contato_online` da conversa aberta.
- `GET conversas[&arquivadas=1]`, `GET contatos&q=`, `GET mensagens&conversa_id=&antes_id=&limite=`, `GET preferencias`.
- `POST abrir {tipo,id}`, `POST enviar {conversa_id, corpo, idempotency_key}` (201; repetição idempotente 200), `POST lida`, `POST arquivar`, `POST preferencias`, `POST bloquear {tipo,id,bloquear}`.
- Erros: 401 sessão; 403 bloqueado/sem acesso; 404 conversa de outro participante/tenant; 422 vazio/ >2000; 429 limite de **20 mensagens/min**.

## 5. Regras
- Busca: morador vê moradores ativos do mesmo tenant com `visivel_busca=1` (exceto ele) e usuários ativos vinculados ao tenant (`usuario_tenant.ativo`), **exceto `permissao = super_admin`**. Usuário vê todos os moradores e usuários. Bloqueados somem nos dois sentidos. Online primeiro.
- Morador não abre conversa com morador oculto; a administração sempre pode.
- Canal **Avisos do condomínio** (AVISOS) é somente leitura; remetente SISTEMA com nome "Portaria"/"Administração".
- `chatin_enviar_aviso()` entrega a cada destinatário na sua conversa AVISOS e dispara push.
- Push (app): respeita `notificar_push` do morador, chave `push_chatin_ativo` em `pwa_configuracoes` (padrão ligado), `fcm_project_id`; **não envia** se o morador está com a mesma conversa aberta no app (presença `plataforma=app` < 20 s). Dados: `origem=chatin`, `conversa_id`, `rota=/home/chatin/{id}`, canal Android `chatin`. Token inválido é desativado.
- Usuários do painel não recebem push (não há token FCM de usuário); veem o contador no botão flutuante.

## 6. Integrações "notificar por Chat-In"
- **Documentos** (`api_documentos.php`): checkbox no modal; ao salvar (novo ou edição) com `notificar_chatin=1`, avisa os moradores que podem ver o documento — `todos`/`moradores` → todos; `unidades_especificas` → moradores das unidades marcadas (`unidades.id` → `unidades.nome` → `moradores.unidade`); `usuarios` → ninguém (mensagem explica). Só documentos com `status=ativo`.
- **Assembleia** (`api_assembleia.php`): checkbox no modal (criar/editar) e ação `notificar_chatin {id}`; envia "Convocação: <nome>" com tipo, data, local e descrição a todos os moradores ativos.
- **Eventos** (`api_eventos.php`, RBAC `eventos`): criar/editar com aviso, reenviar aviso, cancelar com aviso de cancelamento; público todos ou unidades.
- **Chegada de visitante**: `controle_acesso_criar_notificacao_registro()` (chamado por Registro, Acessos de Visitantes, Console e Control iD) consulta os canais do evento `acesso.visitante_chegou`: EMAIL (e-mail existente), APP (push existente) e CHATIN ("Seu visitante chegou" para os moradores da unidade, remetente "Portaria"). Só ENTRADA e pessoa diferente de Morador. Depende da migration de `notificacoes_morador` já usada pelo hook.

## 7. Sistema › Controle de Acesso
Cartão em Sistema → página `config_controle_acesso` (módulo `sistema`). `GET/PUT api/api_notificacao_canais.php` (leitura RBAC `visitantes/visualizar`, gravação `sistema/configurar`, auditoria `rbacAuditar`). Padrão: E-mail e App **ligados**, Chat-In **desligado** (preserva o comportamento anterior até o condomínio optar). WhatsApp e SMS aparecem como **Em breve** e nunca são ativados pelo servidor.

**Preparado para WhatsApp/SMS**: marcar o canal como disponível em `notif_canais_catalogo()` e registrar um provedor com `notif_canais_registrar_provedor('WHATSAPP', fn($c,$tenant,$evento,$destinatarios,$mensagem): int)`; o hook já chama `notif_canais_disparar_externos()`.

## 8. Interface
- **Widget** (painel e portal): botão flutuante com contador; painel à direita com abas Conversas / Nova conversa, preferências, **fixar** (empurra o conteúdo ≥1100 px, lembrado em `localStorage` `chatin_fixado`) e **minimizar** (volta ao botão e desafixa). Enter envia, Shift+Enter quebra linha, seletor de emojis, mensagens pendentes/falhas com reenvio idempotente, "carregar anteriores".
- No Portal, carregar o script como `/frontend/js/chatin-widget.js` e chamar `ChatIn.iniciar({plataforma:'portal', token: () => Portal.token})` somente depois de a sessão Bearer ser validada. O widget usa `/api/api_chatin.php`, não coloca credencial em URL/log e encerra polling/widget no logout.
- **Página Chat-In** (painel): o mesmo componente em modo embutido (`ChatIn.montarEmbutido`); o botão flutuante fica oculto enquanto a página está aberta.
- Menu: item `chatin` (order 13.5) e `SEMPRE_VISIVEIS` em `permissoes-modulos.js` — disponível a todo usuário autenticado (sem módulo RBAC próprio).
- **App**: item no menu lateral e card no Início; ícone com badge de não lidas na AppBar (ping de presença a cada 30 s no `HomeScreen`); telas lista/contatos/preferências e conversa (polling 5 s, pausa em segundo plano); toque no push abre a conversa (inclusive com o app fechado, via `getInitialMessage`).

## 9. Limitações / próximos passos
- Sem anexos, sem edição/exclusão de mensagem, sem grupos (decisão de escopo).
- Push só para moradores (app). Usuário do painel é avisado pelo contador.
- Polling: custo proporcional a usuários com o chat aberto; `versao` evita remontar a lista a cada ciclo.
- Avisos de chegada de visitante dependem do hook existente (`notificacoes_morador` migrada).
