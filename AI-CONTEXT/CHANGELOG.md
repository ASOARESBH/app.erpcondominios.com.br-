# Changelog

Registro de alterações significativas no ERP.

## [2.1.9] - 2026-11-03
### Corrigido
- **Moradores e Alertas da Unidade**: a leitura das regras de campos agora exige `moradores.visualizar`, enquanto alterações continuam exigindo `sistema.configurar`. O painel Alertas usa as permissões específicas retornadas pelo backend, oculta ações de escrita para visualizadores e não mantém o detalhe aberto quando a API não retorna um alerta válido. A avaliação operacional dos alertas continua no lançamento manual de entrada.
- **RBAC de Moradores**: grupo personalizado ou `compat-visualizador` que concede somente `moradores.visualizar` agora nega explicitamente permissões herdadas de escrita, inclusive de `compat-operador`; a revisão do tenant é criada/incrementada ao alterar políticas, invalidando caches de sessões ativas.

### Validado
- Teste em MariaDB descartável confirmou que grupo customizado somente leitura e `compat-visualizador` mantêm `moradores.visualizar` e negam criar/editar/excluir/exportar apesar do `compat-operador`; o operador compatível sem grupo somente leitura preserva edição. Invalidação de cache e criação da revisão RBAC ausente também passaram. `php -l`, `node --check` e `git diff --check` passaram.

## [2.1.8] - 2026-11-03
### Corrigido
- **Protocolos de Mercadorias**: removida a dependência da listagem global de usuários, que retornava 403 para funcionários sem `usuarios.visualizar`. O campo Recebedor (Portaria) passa a exibir o operador autenticado; a API grava esse nome a partir da sessão e preserva a autoria na edição. Escritas passam a exigir permissões RBAC do módulo Protocolos.

## [2.1.7] - 2026-11-02
### Implementado — web/backend
- **Alertas da Unidade**: criada aba Alertas em Moradores, APIs administrativas e Portal web, tabelas tenant-aware, alvos específicos, histórico, eventos automáticos e notificação pós-ciência humana. O domínio novo permanece separado do motor `alertas_acesso` de detecção pós-evento.
- Registro Manual e Controle de Acesso reavaliam alertas no servidor; ciência contextual tem token de uso único e gravação transacional. Leituras automáticas de QR/RFID bloqueiam `BLOQUEAR` e registram eventos não atribuídos a operador.
- Portal e painel administrativo podem usar modelos rápidos e prévias/resumos operacionais, detalhes com histórico em abas, duplicação e exportação CSV/impressão filtrada a 90 dias. KPIs incluem ativos/críticos/pendentes, expiração em 7 dias, acionamentos do dia e overrides no mês. `verificar` usa rate limit e cache de IDs de 30s invalidado em mutações; a gravação final reavalia regras sob lock. Limite server-side de 30 regras simultaneamente vigentes por unidade é aplicado na criação e retomada. O Portal cria/edita/pausa/cancela regras próprias, vê acionamentos e busca visitantes vinculados à sessão com documento mascarado; verifica conflitos antes de emitir QR e controla preferência individual de push, sem suprimir o inbox interno. Convites verificam alertas na data prevista (12h local) antes da confirmação de cadastro; a portaria reavalia no horário real. O QR permanece restrito ao mesmo tenant/morador. Override de supervisor é reenviado ao motor `alertas_acesso` após o commit. Aplicativo Flutter ficou explicitamente fora do escopo.
- Criado cron CLI idempotente de 10 minutos para expirar alertas e gerar inbox/lembrete aproximadamente 24h antes do fim da vigência. O código não configura o agendamento no cPanel.
### Validado
- `php -l` e `node --check` dos arquivos alterados, `git diff --check` e migration executada duas vezes em MariaDB descartável.
- Teste sintético local confirmou migration gate, isolamento de tenant, avaliação de regra, bloqueio automático, máscara de documento e atualização de contador.
- Teste MariaDB descartável do cron confirmou expiração no tenant A, preservação do alerta futuro no tenant B e reexecução sem duplicar lembrete, inbox ou histórico.
- Testes descartáveis também cobriram cache de 30s com invalidação, preferência push por morador/tenant, match de Familiar em `CONVIDADOS` e contagem de 30 alertas vigentes por unidade.
- Corrigidos os checks de metadata da migration e o gate HTTP 503 para não comparar strings com collations implícitas incompatíveis. Reaplicação local em MariaDB descartável com collations mistas passou duas vezes, incluindo backfill da sessão Portal; produção não foi alterada.
### Pendente
- Não foram executados teste de browser autenticado, hardware físico, deploy ou migration de produção. A migration `sql/migration_alertas_unidade_mysql57.sql` precisa de backup e aplicação autorizada antes do uso em produção.

## [2.1.6] - 2026-10-31
### Planejado
- **Lista de Convidados no Registro de Acesso**: analisados `registro.html`/`registro.js`, `api_registros.php`, `api_visitantes.php`, `api_acessos_visitantes.php`, `api_portal_morador.php`, RBAC e app Flutter para especificar uma nova aba "Lista de Convidados" (cadastro pela portaria e pelo morador, herança de visitante por CPF/RG, tipos Entregador Autorizado e Familiar, data prevista, dias de permanência, controle de entradas único/múltiplos de 2/indeterminado, origem do cadastro, autoria Usuário/Morador/Dependente e navegação por data). Decisões registradas: não reutilizar `acessos_visitantes` (regra de 1 acesso ativo por visitante é incompatível); `registros_acesso.tipo` é ENUM e precisa de migration + atualização de todas as whitelists; dependente não possui login hoje, então a autoria `DEPENDENTE` fica preparada mas não é gravada. Nenhuma alteração de código feita nesta tarefa — apenas análise e prompt em `PROMPT_LISTA_CONVIDADOS_20261031.md`.
### Adicionado
- **Registro Manual — Lista de Convidados (painel web)**: incluída tabela tenant-aware de convites com anfitrião morador/dependente, documento, período de validade, modalidade e limite de entradas, autoria, histórico, cancelamento e chave de idempotência. A tela inclui navegação por data, busca, filtros, formulário acessível e deep-link `?page=registro&aba=convidados`.
- A API de convidados aplica autenticação, isolamento pelo tenant da sessão e RBAC por ação. A movimentação Entrada/Saída revalida convite, documento, destino, validade e limite sob transação; os novos tipos **Entregador Autorizado** e **Familiar** são categorias próprias.
### Alterado
- **Relatórios**: os tipos novos foram incluídos em filtros, KPIs, análises, CSV, PDFs e histórico do Portal do Morador. As consultas de Relatórios passaram a identificar a ação própria para exigir `relatorios_acesso.visualizar`, sem confundir essa permissão com a listagem operacional de Registros Recentes.
- **Registro SPA**: respostas assíncronas de Convidados verificam a raiz ativa antes de atualizar a interface; a migration é `sql/migration_lista_convidados_mysql57.sql`.
### Validado
- `php -l` nos endpoints PHP alterados; `node --check` nos módulos JS alterados; `git diff --check`.
- Migration executada duas vezes em banco MariaDB descartável; `convidado_id` e os dois valores do ENUM foram confirmados. Consulta sintética validou estados por data e isolamento tenant-aware.
- A aplicação da migration na base de produção, o teste end-to-end autenticado e as fases Portal/App Mobile não fazem parte deste escopo web e ainda não foram executados.

## [2.1.5] - 2026-08-13
### Investigado
- **Módulo Vigilante/Rondas — mapeamento para o Portal do Colaborador (mobile)**: analisado por completo o módulo administrativo já existente (`api/api_rondas_vigilante.php`, `frontend/pages/rondas_vigilante.html`), incluindo tabelas (`ronda_rotas`, `ronda_pontos`, `ronda_vigilantes`, `ronda_registros`, `ronda_auditoria` — nenhuma documentada antes em `DATABASE.md`), regra de ciclo/SLA e deduplicação por `ciclo_chave`. Confirmado que os endpoints de leitura de QR (`qr_detalhe`, `registrar_leitura`) já existem e funcionam, mas **nenhum cliente os utiliza hoje** — não há tela de "bate-ponto" nem no painel web nem no app. Identificado um gap crítico de identidade: o Portal do Colaborador mobile autentica contra `usuarios`, enquanto `ronda_vigilantes` referencia `rh_colaboradores` — tabelas sem vínculo direto, só ponte possível via e-mail. Nenhuma alteração de código feita nesta tarefa — apenas análise e prompt para o Manus implementar o novo menu "Vigilante" no app. Detalhe completo em `ANALISE_MODULO_VIGILANTE_RONDAS_MOBILE_20260813.md`.

## [2.1.4] - 2026-08-13
### Investigado
- **Notificação de novo documento (GED/Documentos) para moradores**: diagnosticado que hoje não existe nenhum caminho que avise o morador quando um documento liberado para ele é cadastrado. A função `_notificar_novo_documento()` em `api/api_documentos.php` só envia e-mail para usuários internos do sistema (por `grupo_id` administrativo) e nem roda no caso mais comum (documento sem grupo específico); ela não tem relação com o campo `visibilidade`/`unidades_acesso`, que é o que realmente controla se um morador pode ver o documento (já implementado em `pd_where_visivel()`, `api/api_portal_documentos.php`). Nenhuma alteração de código feita nesta tarefa — apenas diagnóstico e prompt para o Manus implementar, seguindo o padrão já validado de `protocol_notification_helper.php`. Detalhe completo em `DEBUG_NOTIFICACAO_DOCUMENTOS_20260813.md`.

## [2.1.3] - 2026-08-13
### Investigado
- **Notificação de veículo entrando na unidade (Controle de Acesso)**: diagnosticado por que o morador não recebe aviso de "veículo entrou para sua unidade" apesar do histórico de acessos aparecer normalmente no painel web e no app. Causa raiz principal: o fluxo automático da catraca (`api/controlid/notifications/dao.php` e `api/controlid/new_user_identified.php`, via `push_registrar_acesso_erp()` em `api/controlid/_helper.php`) grava em `registros_acesso` mas nunca chama o helper de notificação — só o lançamento manual pelo painel (`api/api_registros.php`) está de fato ligado a `controle_acesso_criar_notificacao_registro()`. Também não há confirmação de que as migrações que adicionam `veiculo_id`/`registro_acesso_id` em `notificacoes_morador` já rodaram em produção. Nenhuma alteração de código feita nesta tarefa — apenas diagnóstico e prompt para o Manus corrigir. Detalhe completo em `DEBUG_NOTIFICACAO_CONTROLE_ACESSO_20260813.md`.

## [2.1.2] - 2026-08-13
### Documentado
- **AI-CONTEXT do App Mobile (Flutter)**: criados `APP_MOBILE_ARCHITECTURE.md` e `APP_MOBILE_BUSINESS_RULES.md`, mapeando toda a arquitetura (auth do morador vs. colaborador, roteamento, offline/sync do leiturista, OCR, notificações) e as regras de negócio tela a tela do repositório `aplicativoerpcondominios`, com tabela de endpoints por módulo. Objetivo: acelerar leitura futura do app por IA, sem necessidade de reler todo o código-fonte a cada tarefa. Indexado em `INDEX.md`.
- Durante o mapeamento, dois problemas reais foram identificados no app (não corrigidos nesta tarefa, apenas documentados):
  1. `documents_screen.dart` chama a ação errada da API de documentos (`documentos_listar` sem `pasta_id` em vez de `buscar`), deixando a tela de Documentos sempre vazia — detalhe completo em `DEBUG_DOCUMENTOS_PORTAL_VAZIO_20260813.md`.
  2. `tickets_screen.dart` chama `dioClient.initBaseUrl()`, método removido do `DioClient` na migração para URL fixa multi-tenant — quebra a criação de chamados e a visualização do histórico de interações no Portal do Morador (`NoSuchMethodError` capturado silenciosamente pela tela). Detalhe em `APP_MOBILE_ARCHITECTURE.md`, seção 6.

## [2.1.1] - 2026-08-13
### Corrigido
- **Portal do Morador PWA**: Removida a barra visual fixa de “Nova versão disponível!”. A atualização passou a seguir o ciclo nativo conservador do Service Worker: a nova versão permanece em espera e assume em um ciclo seguro, sem recarregar formulários em uso.
- Mantidos o registro em `/firebase-messaging-sw.js`, o listener `controllerchange`, Firebase Cloud Messaging, cache offline e o banner de instalação do PWA.


## [2.1.0] - 2026-02-xx
### Adicionado
- **Manual do Sistema**: Novo módulo com artigos interativos, busca, favoritos e relatórios.
- **Módulo GED (Documentos)**: Gerenciamento Eletrônico de Documentos integrado à Manutenção, com versionamento e visibilidade por unidade.
- **AI Context Framework**: Criação da base de conhecimento permanente (`AI-CONTEXT/`) para inteligências artificiais.

## [2.0.0] - 2026-01-25
### Adicionado
- **Sistema de Dependentes**: Cadastro completo vinculado a moradores.
- **Integração ControliD**: Rota de webhook para catracas.
- **App PWA**: Portal do Morador com Push Notifications (Firebase).

### Corrigido
- Loop de redirecionamento no `session-manager-core.js`.
- Correção de layout no CSS do `input-wrapper` na tela de login.
## [2.1.1] - 2026-08-13
### Corrigido
- **Portal do Morador — Documentos**: a tela móvel passou a usar a ação GED `buscar` com parâmetros `q`, `tipo` e `pagina`, eliminando a chamada incompatível a `documentos_listar` sem `pasta_id`.
- **Portal do Morador — Documentos**: respostas `sucesso: false`, erros de rede e formatos inválidos agora são apresentados como erro visível, em vez de lista vazia silenciosa.
- **Portal do Morador — Documentos**: adicionada paginação incremental de 30 documentos por página, mantendo as regras de visibilidade e download revalidado no backend.

### Documentado
- Registrado o diagnóstico confirmado de unidade, visibilidade e contrato da API em `AI-CONTEXT/DEBUG_DOCUMENTOS_PORTAL_VAZIO_20260813.md` e `AI-CONTEXT/API.md`.


## [2.1.3] - 2026-08-13
### Corrigido
- **Controle de Acesso / ControlID**: todos os modos automáticos passaram a criar um evento persistente `acesso_entrada` para o morador vinculado ao veículo após gravar o acesso no ERP.
- **Controle de Acesso / ControlID**: a resolução de `tenant_id` e unidade passou a ser obtida do morador associado ao veículo, sem aceitar contexto de tenant enviado pela catraca.
- **Controle de Acesso / ControlID**: a tentativa de persistência e push FCM foi isolada em tratamento não bloqueante; uma falha de notificação não interfere na liberação da catraca.

### Documentado
- Adicionada auditoria de produção e roteiro pós-deploy em `DEBUG_NOTIFICACAO_CONTROLE_ACESSO_20260813.md` e `sql/validacao_controlid_notificacao_pos_deploy.sql`.


## [2.1.4] - 2026-08-13
### Corrigido
- **Controle de Acesso — Veículos**: a migração de notificações passou a tornar `notificacoes_morador.protocolo_id` anulável, permitindo eventos `veiculo_cadastrado` e `acesso_entrada` sem protocolo associado.
- **Controle de Acesso — Veículos**: o helper valida previamente a compatibilidade do esquema e devolve `migracao_pendente` de forma não bloqueante quando a coluna ainda não aceitar `NULL`.

### Documentado
- Registrada a causa raiz e a validação esperada em `DEBUG_NOTIFICACAO_VEICULO_20260813.md`.


## [2.1.5] - 2026-08-13
### Adicionado
- **Financeiro — Inadimplência**: criado módulo Multi-Tenant para importação do Relatório de Inadimplência Detalhado BRCondos, preservação do PDF em BLOB, snapshots históricos, conciliação de totais, ranking por Gleba, comparação, CSV e geração de PDF pelo fluxo de impressão.
- Registrada a permissão `inadimplencia` com nível mínimo `gerente`, o submenu e card no Financeiro e o contrato da API em `AI-CONTEXT/INADIMPLENCIA.md`.
- **Portal do Colaborador — Vigilante/Rondas Mobile**: adicionadas as ações autenticadas `vigilante_qr_detalhe`, `vigilante_registrar_leitura` e `vigilante_historico_hoje`. O aplicativo consulta QR, confirma ponto/rota/instruções, registra leitura com resultado de SLA e mostra histórico do dia.
- **Identidade operacional segura**: a sessão Bearer resolve o tenant e associa `usuarios.email` a `rh_colaboradores.email` no mesmo condomínio. Situações de divergência usam somente opção opaca, assinada e temporária para vigilantes vinculados à rota; o cliente nunca fornece `tenant_id` ou `colaborador_id` livre.
- **Regra compartilhada de rondas**: criado `api/helpers/ronda_helper.php`, reutilizado pela API administrativa e pela móvel para dias ativos, ciclos e SLA sem duplicar cálculo.

### Validado
- O PDF BRCondos de referência foi processado com 41 unidades, 1.153 lançamentos, R$ 405.265,52 lançado e R$ 574.363,84 projetado, com totais conciliados e cobertura para Gleba com múltiplos lançamentos, proprietário `E OUTROS` e descrição extra longa.
- O parser possui fallback para ambientes PHP sem `mbstring`; consultas usam `tenant_id` exclusivamente da sessão e ordenações utilizam lista branca.
- QR de tenant diferente é recusado com HTTP 403, e a API revalida vínculo do vigilante, agenda, janela de ciclo e índice de duplicidade antes da gravação.
- Validadas sintaxe PHP, regras de ciclo/SLA, análise direcionada dos arquivos Flutter e testes Flutter. O detalhe técnico e roteiro pós-deploy estão em `ANALISE_MODULO_VIGILANTE_RONDAS_MOBILE_20260813.md`.
