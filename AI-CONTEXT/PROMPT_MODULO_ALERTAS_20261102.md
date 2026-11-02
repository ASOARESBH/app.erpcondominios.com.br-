# PROMPT DE IMPLEMENTAÇÃO — Módulo "Alertas" (Moradores → aba Alertas)

> Gerado em 2026-11-02 com base na análise do código real: `frontend/pages/moradores.html` (abas Moradores / Dependentes / Relatórios), `frontend/js/pages/moradores.js`, `frontend/js/pages/registro.js`, `frontend/js/pages/registro-convidados.js`, `frontend/js/pages/acesso.js`, `api/api_alertas_acesso.php`, `api/helpers/alertas_acesso_helper.php`, `sql/migration_alertas_acesso_mysql57.sql`, `api/api_registros.php`, `api/api_acessos_visitantes.php`, `api/api_rfid.php`, `api/api_portal_morador.php`, `api/api_portal_dependentes.php`, `frontend/js/permissoes-modulos.js` e o app Flutter `aplicativoerpcondominios` (`lib/presentation/screens/*`).
> Telas alvo: `frontend/layout-base.html?page=moradores` (nova aba), `?page=registro` (Registro Manual), `?page=acesso` (Controle de Acesso), Portal do Morador e App.
> Copie tudo a partir da linha "INÍCIO DO PROMPT".

---

## INÍCIO DO PROMPT

Você é o tech lead, engenheiro de segurança e QA do **ERP CONDOMÍNIOS** (repositórios `app.erpcondominios.com.br-` e `aplicativoerpcondominios`). Implemente o módulo **Alertas da Unidade** como uma nova aba **"Alertas"** em Moradores, ao lado de *Dependentes* e *Relatórios*. Esses alertas são **avisos e restrições de portaria cadastrados pelo morador (Portal e App) ou pelo operador (sistema)**. Eles devem aparecer **antes** de o operador confirmar uma entrada no Registro Manual e no Controle de Acesso. O operador lê, dá ciente e assume a responsabilidade, e o sistema grava um log imutável.

**Objetivo de negócio:** o morador registra as próprias regras, por exemplo "não autorizo visitantes de 01/11 a 30/11" ou "entregas podem ficar na portaria, não interfonar". Assim a portaria não precisa incomodá-lo, e toda decisão fica rastreável.

O código deve ficar pronto para produção, sem gambiarras.

### 0. Regras obrigatórias (não pule)

1. **Leia antes de codar:** `AI-CONTEXT/INDEX.md`, `SKILL.md`, `ARCHITECTURE.md`, `FRONTEND.md`, `API.md`, `DATABASE.md`, `BUSINESS_RULES.md`, `SECURITY.md`, `MULTITENANT_ARCHITECTURE.md`, `APP_MOBILE_ARCHITECTURE.md`, `APP_MOBILE_BUSINESS_RULES.md`, `PROMPT_LISTA_CONVIDADOS_20261031.md` (mesmo padrão de tipos e autoria) e este arquivo.
2. Siga a sequência ANALISAR → ENTENDER → IDENTIFICAR CAUSA → PLANEJAR → IMPLEMENTAR → TESTAR → DOCUMENTAR → ATUALIZAR AI-CONTEXT. Antes de implementar, apresente o plano (arquivos, tabelas, endpoints).
3. **Multi-tenant:** o `tenant_id` vem sempre da sessão ou do token, nunca do payload. Todo SELECT usa `WHERE tenant_id = ?`. Todo UPDATE e DELETE usa `WHERE id = ? AND tenant_id = ?`.
4. Use **apenas prepared statements**. Isso vale inclusive onde o código legado usa interpolação, como em `api_alertas_acesso.php`: não copie esse padrão. Responda sempre `{sucesso, mensagem, dados}`, com `ob_start()`/`ob_end_clean()` e `set_exception_handler`. Nunca exponha erro de banco ao cliente.
5. No frontend, use **apenas Vanilla JS** (ES Module com `init()`/`destroy()`, padrão SPA do `layout-base.html`). Não use frameworks. Escape toda saída com `_esc()`.
6. Não edite arquivos `teste_*`, `debug_*`, `*_backup*`, `*_migrado*`, `*_mitigado*`, `*.bak`. Confirme o arquivo canônico pelo `fetch()` real do JS de página (ex.: existe `frontend/moradores.html` legado **e** `frontend/pages/moradores.html`; o canônico é o carregado pelo `layout-base.html?page=moradores`).
7. Faça migrations **idempotentes e compatíveis com MySQL 5.7**, em `sql/migration_alertas_unidade_mysql57.sql`, verificando colunas e índices via `INFORMATION_SCHEMA`.
8. **Não quebre nada existente:** cadastro de moradores e dependentes, relatórios, Registro Manual, Lista de Convidados, Controle de Acesso/RFID e o motor `alertas_acesso` (Sistema → Alertas de Acesso).

---

### 1. Contexto encontrado no código (não reinvente)

| Item | Situação atual | Implicação |
|---|---|---|
| `frontend/pages/moradores.html` | Já tem `.tabs` com `.tab-button[data-tab="moradores" / "dependentes" / "relatorios"]` + `.tab-content#tab-*`. | Adicionar `data-tab="alertas"` **depois de Dependentes e antes de Relatórios** (ou logo após Relatórios, se o layout quebrar), com `#tab-alertas`, reutilizando as mesmas classes. Suportar deep-link `?page=moradores&aba=alertas`. |
| Motor `alertas_acesso` (`api_alertas_acesso.php` + `helpers/alertas_acesso_helper.php` + 5 tabelas `alertas_acesso_*`) | Alertas **da administração** (ex.: carro suspeito, recado para a portaria) — detecção por critérios (placa, nome, CPF, unidade...), avaliados **depois** que o acesso é gravado (`alertas_acesso_processar_evento()` é chamado em `api_registros.php` ~L1165, `api_acessos_visitantes.php` ~L302, `api_rfid.php` ~L112/L204). Geram evento e entrega (sistema/e-mail/WhatsApp) e só podem ser reconhecidos no sino. RBAC `sistema`/`configurar`. Hoje o `registro.js` **ignora** o campo `alertas_acesso` da resposta. | **Não reutilize essas tabelas** para o novo módulo: o conceito é outro (pré-validação com ciente, cadastro pelo morador, vigência, público-alvo). Crie o domínio `alertas_unidade`. Reutilize o **estilo** do helper (normalização de texto/placa e matching). Opcional: quando um alerta de unidade **crítico** for desconsiderado (override), também chame `alertas_acesso_processar_evento()` com `origem='alerta_unidade_override'`, para a administração ser notificada pelo canal já existente. Documente a decisão. |
| Tipos de pessoa | `registros_acesso.tipo` aceita `Morador, Visitante, Prestador, Entregador Autorizado, Familiar` (whitelist em `api_registros.php` ~L101/L281). A Lista de Convidados usa `VISITANTE, PRESTADOR, ENTREGADOR_AUTORIZADO, FAMILIAR`. | O público-alvo do alerta usa **as mesmas 4 categorias** + `VEICULO` (placa) + `TODOS`. Centralize o mapa em um helper (ex.: `api/helpers/tipos_acesso_helper.php`) se ainda não existir. Não duplique strings. |
| Visitantes | `api/api_visitantes.php` com busca `?documento=`. | O seletor "visitante específico" busca nesse endpoint (nome/CPF/RG) com debounce e grava `visitante_id`. Se o visitante não existir, permitir apenas documento + nome (match por documento normalizado). |
| Dependentes | `api_dependentes.php?acao=listar&morador_id=`. Portal: `api_portal_dependentes.php` (Bearer `sessoes_portal`). **Dependente não tem login próprio.** | O alerta pode ter como **titular** o morador ou um dependente (escolhido na cascata). A autoria fica restrita a `USUARIO` ou `MORADOR`. `DEPENDENTE` fica preparado no ENUM, mas não é gravado até existir login de dependente. |
| Veículos | `api_veiculos.php` / `veiculos` (`placa`, `morador_id`). Registro Manual já envia `placa`. | Alertas por placa normalizam o formato (maiúsculas, sem hífen/espaço; Mercosul e antigo). Na pré-validação, comparar a placa digitada ou lida (RFID/LPR, se houver placa no evento). |
| Portal / App | `api_portal_morador.php` (token Bearer `sessoes_portal`). App Flutter em `lib/presentation/screens/` (`access_control`, `visitors`, `dependents`, `notifications`...), padrão `data/` → `domain/` → `presentation/` com providers. | Criar `api/api_portal_alertas.php` (escopo **somente do morador logado e da sua unidade**) e a tela `lib/presentation/screens/alerts/` no app, seguindo o padrão de `visitors`/`dependents`. |
| RBAC | `rbac_helper.php` (`rbacTabelasDisponiveis`, `rbacExigir`, `rbacPode`, `rbacAuditar`). Módulos `moradores`, `registro`, `acesso` em `permissoes-modulos.js`. | Gestão de alertas: módulo `moradores` (`visualizar/criar/editar/excluir`). Pré-validação e ciente: `registro` ou `acesso` (`visualizar`/`criar`). Override de crítico: nova ação `alertas_override` (ou reaproveitar `aprovar`, se existir). Fallback: `verificarAutenticacao(true,'operador')`. Audite tudo com `rbacAuditar`. |
| Notificações | `api/helpers/access_control_notification_helper.php`, `notificacoes_morador`, push FCM. | Notificar o morador (push + `notificacoes_morador`, `tipo='alerta_unidade_acionado'`) quando um alerta dele for acionado na portaria. Respeitar a preferência de notificação do morador. |

---

### 2. Modelo de dados (`sql/migration_alertas_unidade_mysql57.sql`)

```
alertas_unidade
  id, tenant_id,
  unidade (VARCHAR, mesmo formato de moradores.unidade) NOT NULL,
  morador_id INT NOT NULL,                 -- titular (sempre gravado)
  dependente_id INT NULL,                  -- quando o alerta é do dependente
  titulo VARCHAR(120) NOT NULL,
  mensagem TEXT NOT NULL,                  -- texto exibido ao operador (máx. 1000)
  nivel ENUM('INFORMATIVO','ATENCAO','CRITICO') NOT NULL,
  acao ENUM('APENAS_AVISAR','EXIGIR_CIENTE','CONTATAR_MORADOR','BLOQUEAR') NOT NULL,
  publico ENUM('TODOS','VISITANTE','PRESTADOR','ENTREGADOR_AUTORIZADO','FAMILIAR','VEICULO') NOT NULL,
  abrangencia ENUM('TODOS_DO_TIPO','ESPECIFICO') NOT NULL DEFAULT 'TODOS_DO_TIPO',
  vigencia_inicio DATETIME NOT NULL,
  vigencia_fim DATETIME NULL,              -- NULL = indeterminado (só operador pode criar)
  dias_semana VARCHAR(20) NULL,            -- ex.: '1,2,3,4,5' (recorrência opcional)
  hora_inicio TIME NULL, hora_fim TIME NULL,  -- janela diária opcional (suporta virada de dia)
  instrucao_operador VARCHAR(500) NULL,    -- ex.: "Ligar para (31) 9xxxx antes de liberar"
  telefone_contato VARCHAR(30) NULL,
  status ENUM('ATIVO','PAUSADO','EXPIRADO','CANCELADO') NOT NULL DEFAULT 'ATIVO',
  origem ENUM('SISTEMA','PORTAL','APP') NOT NULL,
  criado_por_tipo ENUM('USUARIO','MORADOR','DEPENDENTE') NOT NULL,
  criado_por_usuario_id INT NULL, criado_por_morador_id INT NULL,
  cancelado_por_tipo/_id, cancelado_em, motivo_cancelamento,
  total_acionamentos INT NOT NULL DEFAULT 0, ultimo_acionamento_em DATETIME NULL,
  idempotency_key VARCHAR(64) NULL,
  criado_em, atualizado_em
  ÍNDICES: (tenant_id, unidade, status), (tenant_id, status, vigencia_inicio, vigencia_fim),
           UNIQUE (tenant_id, idempotency_key)

alertas_unidade_alvos            -- usado quando abrangencia = 'ESPECIFICO' (N alvos por alerta)
  id, tenant_id, alerta_id,
  tipo_alvo ENUM('VISITANTE','DOCUMENTO','PLACA'),
  visitante_id INT NULL,
  documento_normalizado VARCHAR(20) NULL,  -- só dígitos (CPF) / alfanumérico (RG)
  placa_normalizada VARCHAR(10) NULL,
  nome_referencia VARCHAR(150) NULL,
  ÍNDICES: (tenant_id, documento_normalizado), (tenant_id, placa_normalizada), (tenant_id, alerta_id)

alertas_unidade_ciencias         -- LOG IMUTÁVEL (sem UPDATE/DELETE pela API)
  id BIGINT, tenant_id, alerta_id,
  contexto ENUM('REGISTRO_MANUAL','CONTROLE_ACESSO','RFID','CONVIDADOS','LPR'),
  registro_acesso_id INT NULL,             -- preenchido após gravar o acesso
  decisao ENUM('CIENTE_LIBERADO','CIENTE_NAO_LIBERADO','CONTATO_MORADOR_AUTORIZOU','CONTATO_MORADOR_NEGOU','OVERRIDE_SUPERVISOR'),
  justificativa VARCHAR(500) NULL,         -- obrigatória para CRITICO liberado e override
  usuario_id INT NOT NULL, usuario_nome VARCHAR(150),
  supervisor_usuario_id INT NULL,
  pessoa_nome, pessoa_documento_mascarado, tipo_pessoa, placa,
  snapshot_json MEDIUMTEXT,                -- cópia do alerta (título, mensagem, nível, ação) no momento
  ip VARCHAR(45), user_agent VARCHAR(255), criado_em DATETIME
  ÍNDICES: (tenant_id, alerta_id, criado_em), (tenant_id, usuario_id, criado_em), (tenant_id, criado_em)

alertas_unidade_historico        -- trilha de criação/edição/pausa/cancelamento (antes/depois em JSON)
```

Regras de dados:
- Ao **editar** um alerta já acionado, não reescreva o histórico: o `snapshot_json` da ciência preserva o texto que o operador leu.
- Expiração: uma rotina em `cron/` (seguir o padrão existente) muda `ATIVO → EXPIRADO` após `vigencia_fim`. A consulta de pré-validação também filtra por data, então o cron não é crítico para a correção.
- Datas: valide se a data existe de fato. **31/11 não existe**: o exemplo correto é "01/11 a 30/11". `vigencia_fim >= vigencia_inicio`. Grave e compare no fuso do tenant (America/Sao_Paulo por padrão).

---

### 3. Regras de negócio

#### 3.1 Cadastro do alerta (formulário em etapas — wizard de 4 passos)

1. **Para quem (titular):** Unidade\* → radio `Morador | Dependente` → Morador\* (cascata da unidade) → Dependente (se marcado). No Portal e no App, a unidade e o morador vêm fixos da sessão. O morador só escolhe se o alerta é dele ou de um dependente dele.
2. **Quem será afetado (público):** `Todos os acessos da unidade`, `Visitante`, `Prestador`, `Entregador Autorizado`, `Familiar`, `Veículo (placa)`.
   - Para pessoas: `Todos deste tipo` **ou** `Pessoa específica`. Pessoa específica usa a busca em visitantes (nome/CPF/RG, debounce de 300 ms, mínimo 3 caracteres) e permite adicionar **vários alvos** em chips. Se a pessoa não estiver cadastrada, o usuário informa documento + nome.
   - Para veículo: uma ou várias placas, com máscara e validação Mercosul/antiga.
   - O morador **não** pode criar alerta que mire moradores ou dependentes de outra unidade. A busca no Portal/App mostra apenas visitantes já vinculados à unidade dele, ou aceita documento livre (LGPD: não expor a base de visitantes do condomínio ao morador).
3. **Tipo e ação:**
   - **Nível:** `Informativo` (azul), `Atenção` (amarelo), `Crítico` (vermelho).
   - **Ação na portaria:**
     - `Apenas avisar` — banner exibido, sem bloqueio (só para Informativo).
     - `Exigir ciente` — modal; o operador precisa marcar "Li e assumo a responsabilidade" para continuar.
     - `Contatar o morador antes` — modal com o telefone/instrução; o operador registra "morador autorizou" ou "morador negou".
     - `Bloquear entrada` — botão "Registrar entrada" desabilitado; só um supervisor libera, com override, senha/2ª autenticação e justificativa obrigatória (RBAC `alertas_override`).
   - **Mensagem\*** (contador de caracteres), **instrução ao operador** e **telefone de contato** opcionais.
   - **Modelos rápidos** (preenchem os campos): "Não receber visitas no período", "Entregas: deixar na portaria sem interfonar", "Prestador X autorizado sem confirmação", "Não liberar a pessoa X", "Viagem — unidade vazia, não liberar ninguém", "Ligar antes de liberar".
4. **Quando:** início\*, fim (obrigatório para morador, máximo 365 dias; indeterminado só para operador), recorrência opcional (dias da semana + janela de horário). Mostre o resumo em linguagem natural antes de salvar: *"Visitantes da unidade 102 não serão liberados sem ciente entre 01/11/2026 e 30/11/2026, todos os dias."*

Validações (front **e** back): campos obrigatórios, whitelist de ENUMs, combinações inválidas (`APENAS_AVISAR` só com `INFORMATIVO`; `BLOQUEAR` só com `CRITICO`; `ESPECIFICO` exige ≥1 alvo), sem sobreposição duplicada idêntica (avisar e permitir), limite anti-abuso por unidade (ex.: 30 alertas ativos, configurável) e idempotência (`idempotency_key` gerada no front).

#### 3.2 Quem pode o quê
| Ator | Criar | Editar/Pausar/Cancelar | Ver |
|---|---|---|---|
| Operador/Admin (sistema) | Qualquer unidade | Qualquer alerta (os criados por morador geram notificação ao morador) | Todos + log de ciências |
| Morador (Portal/App) | Só da própria unidade, para si ou seus dependentes | Só os que ele criou; não altera alertas criados pela administração (apenas visualiza) | Os da unidade + histórico de acionamentos (quem, quando, decisão), **sem dados sensíveis do operador além do nome** |

#### 3.3 Pré-validação na portaria (o coração do módulo)

Crie o helper `api/helpers/alertas_unidade_helper.php` com a função `alertas_unidade_avaliar(mysqli $c, int $tenant_id, array $ctx): array`, onde `$ctx = {unidade, morador_id?, dependente_id?, tipo_pessoa, visitante_id?, documento?, placa?, data_hora}`. A função retorna os alertas aplicáveis, ordenados por nível (CRÍTICO > ATENÇÃO > INFORMATIVO) e com a ação mais restritiva no topo.

Regra de match: mesmo `tenant_id` + status ATIVO + dentro da vigência, do dia da semana e da janela de horário + (unidade igual **ou** placa ou documento do alvo igual, independentemente da unidade informada, para pegar o caso em que o operador seleciona a unidade errada) + público compatível (`TODOS` ou o tipo igual; `VEICULO` quando há placa) + abrangência (`TODOS_DO_TIPO`, ou um alvo que bata por `visitante_id`, documento ou placa normalizados).

Endpoint `GET/POST api/api_alertas_unidade.php?acao=verificar`. Fluxo:
1. **Registro Manual (`registro.js`):** quando o operador sair do campo unidade, pessoa/documento ou placa (debounce), chame `verificar` e mostre um **banner persistente** acima do botão "Registrar". Ao clicar em "Registrar", se houver alerta `EXIGIR_CIENTE`, `CONTATAR_MORADOR` ou `BLOQUEAR`, abra o **modal de ciente** (não feche com ESC/clique fora; foco preso; som curto e borda pulsante para CRÍTICO).
2. Ao confirmar, chame `POST ?acao=registrar_ciencia`. A API retorna um `ciencia_token` (assinado, uso único, validade de 5 min, vinculado a tenant + usuário + alerta + contexto).
3. `api_registros.php` (entrada) **revalida no servidor**: chama `alertas_unidade_avaliar()` de novo e exige um `ciencia_token` válido para cada alerta que demande ciente. Sem token, responde **409** `{codigo:'ALERTA_PENDENTE', alertas:[...]}`. A segurança não pode depender só do front. Na mesma transação, grava o acesso, atualiza `registro_acesso_id` nas ciências, incrementa `total_acionamentos` e marca o token como usado.
4. **Controle de Acesso (`acesso.js`/`api_rfid.php`) e app de portaria:** o mesmo helper. Para leituras automáticas (RFID/tag sem operador), alerta `BLOQUEAR` nega a liberação e gera um evento visível no Controle de Acesso. Os demais níveis geram evento para ciente posterior e notificam o operador. Documente o comportamento.
5. **Lista de Convidados (`registro-convidados.js`/`api_convidados.php`):** ao dar entrada de um convidado, use a mesma avaliação. Ao **cadastrar** um convite para a unidade, avise se houver alerta conflitante (ex.: morador bloqueou visitantes no período).
6. Chame também a avaliação quando o registro for de **saída**? **Não** — somente entrada, salvo `publico=VEICULO` com flag futura. Documente.

Depois da ciência: notifique o morador (push + `notificacoes_morador`), com a mensagem "Seu alerta '<título>' foi acionado na portaria às 14:32 — Visitante João S. — decisão: não liberado". Aplique throttle: no máximo 1 push por alerta a cada 10 minutos.

#### 3.4 Aba "Alertas" em Moradores (layout profissional)
- **KPIs no topo** (cards no padrão `rel-kpi-*` já usado em Relatórios): Ativos, Críticos ativos, Expiram em 7 dias, Acionamentos hoje, Overrides no mês.
- **Filtros:** busca (título/unidade/morador/placa/documento), unidade, nível, público, status, origem (Sistema/Portal/App), período de vigência. Persistir filtros por usuário em `localStorage` com try/catch.
- **Tabela** paginada no servidor: badge de nível colorido + ícone (não depender só de cor), título, unidade/titular, público e alvos (chips), vigência com barra de progresso, origem, total de acionamentos, status e ações (ver, editar, duplicar, pausar/retomar, cancelar com motivo).
- **Drawer de detalhes** com 3 abas: *Resumo*, *Acionamentos* (timeline das ciências: operador, decisão, justificativa, pessoa, link para o registro de acesso) e *Histórico de alterações*.
- **Exportação** CSV/PDF do log de ciências (reaproveitar o padrão de Relatórios) e um novo relatório "Alertas e Ciências" na aba Relatórios.
- **Estados vazios** com orientação e botão "Criar primeiro alerta", skeleton de carregamento, mensagens de erro amigáveis, acessibilidade (labels, `aria-live` para o banner, contraste AA) e layout responsivo.

#### 3.5 Portal do Morador e App
- Menu "Meus Alertas", com lista (ativos/expirados), criação pelo mesmo wizard simplificado (modelos rápidos em destaque), pausar/cancelar e histórico de acionamentos.
- App Flutter: `lib/presentation/screens/alerts/` + `data/` (repositório e DTO) + `domain/` (entidade e casos de uso) + provider, seguindo o padrão de `visitors`/`dependents`. Inclua deep-link da notificação `alerta_unidade_acionado` para o detalhe do alerta.
- Endpoints do portal (`api/api_portal_alertas.php`, Bearer `sessoes_portal`): `listar`, `detalhe`, `criar`, `atualizar`, `pausar`, `cancelar`, `acionamentos`, `buscar_visitante` (restrito). O `morador_id` e a `unidade` vêm **sempre do token**.

---

### 4. API (`api/api_alertas_unidade.php` — painel)
`listar` (filtros + paginação), `kpis`, `detalhe`, `opcoes` (unidades, moradores, dependentes, tipos), `buscar_alvo` (visitantes/placas), `salvar` (criar/editar, com histórico), `alterar_status` (pausar/retomar/cancelar), `verificar` (pré-validação), `registrar_ciencia`, `override` (supervisor), `ciencias` (log com filtros), `exportar`. Use os códigos HTTP corretos: 401/403/404/409/422/500. Aplique rate limit na `verificar` (é chamada com frequência), com cache curto de 30 s por tenant + unidade para a lista de alertas ativos, invalidado quando um alerta é salvo.

---

### 5. Ideias extras para deixar profissional (implemente as marcadas ✅; as 💡 ficam documentadas no ROADMAP)
- ✅ Modelos rápidos de alerta e resumo em linguagem natural antes de salvar.
- ✅ Ciência com token de uso único + revalidação no servidor + snapshot imutável do texto lido.
- ✅ Override de crítico só por supervisor, com justificativa e notificação à administração (via motor `alertas_acesso` existente).
- ✅ Notificação ao morador quando o alerta é acionado (com throttle).
- ✅ Expiração automática + aviso ao morador 24 h antes do fim ("Seu alerta termina amanhã — deseja prorrogar?").
- ✅ Match por placa/documento mesmo com unidade errada (proteção contra erro do operador).
- 💡 **Palavra-senha da unidade:** o morador define uma contrassenha que o visitante precisa informar na portaria.
- 💡 **Modo "Não perturbe / Viagem"** com um toque no app: cria alerta crítico pré-configurado com data de retorno.
- 💡 **Aprovação da administração** para alertas de morador que bloqueiam prestadores do próprio condomínio (configurável em Config. Moradores).
- 💡 **Painel do turno:** ao fazer login, o operador vê os alertas críticos ativos do dia ("briefing de turno").
- 💡 Integração com LPR/câmera quando houver leitura automática de placa (`frontend/pages/lpr.html`).
- 💡 Indicadores: alertas mais acionados, operadores com mais overrides e tempo médio de decisão.

---

### 6. Segurança e LGPD
- Documento sempre armazenado normalizado e exibido mascarado (`***.456.789-**`) nas listagens e no log de ciências.
- O morador nunca vê dados de visitantes de outras unidades nem a base completa.
- O log de ciências não tem UPDATE/DELETE pela API. Retenção configurável e documentada.
- CSRF nos POSTs do painel (seguir o padrão vigente), validação de tamanho de texto, `strip_tags` na entrada e escape na saída.
- `rbacAuditar` em criar, editar, cancelar, override e exportar.

---

### 7. Testes obrigatórios (registrar resultados em `AI-CONTEXT/TESTING.md`)
1. Migration roda duas vezes sem erro (idempotência) em MySQL 5.7.
2. Isolamento: alerta do tenant A nunca aparece no tenant B (`verificar`, `listar`, portal).
3. Morador não cria nem edita alerta de outra unidade (403) e não altera alerta criado pela administração.
4. Vigência: antes do início, dentro, após o fim, recorrência por dia da semana e janela que vira a meia-noite (22:00–06:00). Data inválida (31/11) → 422.
5. Visitante específico: bate por `visitante_id`, por documento com e sem máscara, e não bate com outro visitante da mesma unidade.
6. Placa: `ABC-1234`, `abc1234`, `ABC1D23` normalizam e batem.
7. Registro Manual: sem token → 409; token de outro usuário ou alerta → 409; token reutilizado → 409; token expirado → 409; fluxo correto grava o acesso + a ciência com `registro_acesso_id`.
8. `BLOQUEAR`: operador comum não consegue liberar; supervisor com justificativa consegue, gerando log e evento administrativo.
9. RFID/automático com `BLOQUEAR` → nega a liberação e registra o evento.
10. Regressão: Registro Manual sem alertas funciona igual a antes; Lista de Convidados, Dependentes, Relatórios e `alertas_acesso` intactos.
11. Portal/App: criar, pausar, cancelar, receber push de acionamento e abrir pelo deep-link.
12. Performance: `verificar` < 150 ms com 5.000 alertas no tenant (verificar uso de índices com `EXPLAIN`).

---

### 8. Documentação (mesma tarefa)
Atualize `AI-CONTEXT/`: `CHANGELOG.md`, `DATABASE.md` (novas tabelas), `API.md` (endpoints painel e portal), `BUSINESS_RULES.md` (seção Alertas da Unidade), `FRONTEND.md`, `MODULES.md`, `SECURITY.md`, `APP_MOBILE_BUSINESS_RULES.md`, `TESTING.md`, `ROADMAP.md` (ideias 💡) e `INDEX.md`. Registre explicitamente a decisão **"alertas_unidade (alertas do morador/unidade, pré-validação com ciente) ≠ alertas_acesso (alertas da administração: carro suspeito, recados — detecção pós-evento)"** e como os dois se integram.

### 9. Entrega
Ao final, apresente: arquivos criados e alterados, migration, prints/descrição das telas, resultado de cada teste do §7, pendências e riscos. Não faça commit sem autorização.

## FIM DO PROMPT
