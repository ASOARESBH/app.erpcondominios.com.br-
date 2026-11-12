# Changelog

Registro de alterações significativas no ERP.

## Infraestrutura Hetzner — 2026-11-12
### Validado
- H1: MySQL 8.4.13 instalado e configurado para acesso local por socket/127.0.0.1, `NO_ENGINE_SUBSTITUTION`, timezone `-03:00`, `utf8mb4`/`utf8mb4_unicode_ci`, binlog, retenção de 7 dias e slow log. PHP `mysqli` conectou com sucesso à HML; porta 3306 sem regra UFW.
- H2: restauração aceita — 270 tabelas, 202.609 linhas reconciliadas em 270/270 tabelas, 7 triggers, 2 procedures, zero divergências, zero erros e zero definers incorretos. Evidência root-only; integrações externas neutralizadas apenas no HML.
- H3: reconciliação estrutural de 54 migrations contra catálogo live. Aplicadas somente quatro lacunas comprovadas: cinco tabelas de `alertas_acesso`, coluna de observação de abastecimento, `client_uuid`/índices únicos de leituras móveis e dois índices de O.S. Contagens pós-DDL preservaram as 270 tabelas de origem e 202.609 registros; cinco tabelas vazias novas. Script idempotente MySQL 8.4 em `sql/deploy/hetzner_cutover_migrations.sql`.
- O backup lógico imediatamente pré-DDL, os logs de aplicação e as evidências de reconciliação são root-only na VM. Nenhuma linha de negócio foi incluída em Git ou relatório.
### Pendente / gates
- H3 permanece parcial: migrations mistas/não idempotentes e seeds/backfills não aprovados seguem sem execução. `assembleias.tenant_id` já existe e a contagem nula/zero é 0. A v2 de notificações não foi aplicada; `protocolo_id` é `NOT NULL`, existem 643 registros e zero duplicidades na chave candidata. A migration `migration_consolidar_erpserra_para_erpcondor_mysql57.sql` permanece desativada e não executada.
- H4 deve começar pelo IP `2.29.161.106`, a partir de `origin/main`; DNS e HostGator não foram alterados. Aguardar os ZIPs de `uploads/` e `config/firebase/` antes de importar esses arquivos. Não tentar Certbot sem hostname apontado.
- PostgreSQL e o projeto de conversão continuam pausados; branches `pg/*`, dumps e ensaios históricos permanecem preservados. Não houve merge em `main` nem cutover.
### Histórico da falha inicial (resolvida)
- A primeira restauração H2 parou em `1419/HY000` ao criar rotinas/triggers com `log_bin=1` e `log_bin_trust_function_creators=0`; H3 e H4 foram suspensas até correção permanente autorizada e nova restauração. A HML parcial e os logs root-only dessa tentativa foram preservados; depois disso a restauração integral foi refeita e aceita.
- A triagem estática original encontrou 20 migrations estruturais idempotentes, 29 mistas, 4 estruturais não idempotentes e 1 bloqueada/requer revisão. O resultado efetivo por migration está na matriz `docs/infraestrutura/hetzner/MATRIZ_MIGRATIONS_H3.md`.

## [2.1.26] - 2026-11-05
### Adicionado
- **Chat-In** (menu abaixo de Recursos Humanos): comunicador interno entre usuários do painel e moradores (Portal e app). Botão flutuante + painel lateral fixável/minimizável, página do painel, status online, busca por nome/unidade, histórico permanente, bloqueio, ocultar-se da busca, emojis, push FCM no app com deep link. Detalhes em `AI-CONTEXT/CHATIN.md`. Migration `sql/migration_chatin_mysql57.sql`.
- **Avisos do condomínio** (canal somente leitura do Chat-In) alimentado por Documentos, Assembleia, Eventos e chegada de visitante.
- **Eventos** (Administrativo): a página stub virou módulo completo (`api/api_eventos.php`) com público por unidades, aviso/cancelamento pelo Chat-In.
- **Sistema › Controle de Acesso**: liga/desliga por condomínio o aviso de chegada de visitante por E-mail, Aplicativo e Chat-In; WhatsApp/SMS preparados ("Em breve") com registro de provedor.
- Opção "Notificar pelo Chat-In" em Documentos e Assembleia.
### Corrigido
- **Assembleia**: criar sempre falhava (`responder_criar` sem `global $body` e sem `tenant_id`); `_exigir_admin()` lia `role/perfil` em vez de `permissao` e bloqueava toda escrita; listagem mostrava assembleias de **todos os condomínios** e obter/atualizar/excluir/status filtravam só por id. Agora tudo filtra por tenant. Migration `sql/migration_assembleia_tenant_mysql57.sql` (sem `INFORMATION_SCHEMA`, com backfill); sem a coluna a API responde 409 orientando a migration.
- **Documentos (`api_documentos.php`)**: 12 funções usavam `$tenant_id` fora de escopo (SQL `tenant_id = AND`), quebrando p.ex. salvar documento com departamento ("Departamento inativo"/500), dashboard, unidades do seletor, grupos, pastas, tipos e compartilhamento. Adicionado `global $tenant_id`.
### Pendências conhecidas
- Assembleia: pautas, votos e participantes ainda não têm `tenant_id` próprio (dependem do id da assembleia; leitura/escrita por id da pauta não confere tenant). Corrigir em etapa própria.
- App Flutter não foi compilado neste ambiente (sem SDK): rodar `flutter analyze` antes do build.

## [2.1.25] - 2026-11-05
### Alterado
- **Ordens de Serviço — seleção de unidade e morador**: o campo livre Morador/Cliente foi substituído por selects encadeados. Unidades ativas são ordenadas numericamente; moradores ativos são carregados somente após escolher a unidade. A seleção apenas da unidade permanece válida para chamados de área comum.
- O frontend reutiliza APIs tenant-scoped existentes e cancela consultas de moradores obsoletas ao trocar unidade, fechar a modal ou desmontar a página. O backend continua resolvendo nome e snapshot da unidade a partir do `morador_id` quando um morador é selecionado.

## [2.1.24] - 2026-11-04
### Corrigido
- **Convidados (Portal/App) não encontrava visitante cadastrado pela portaria.** Caso real: GABRIEL ELIAS RODRIGUES SILVA (CPF 130.565.176-64), cadastrado no painel por usuário (`cadastrado_por_tipo=FUNCIONARIO`, sem unidade), aparecia como "Visitante não cadastrado". Causa: `buscar_documento` só considerava visitantes vinculados à unidade do morador (`pvu_condicao_visitante_unidade`). Agora a busca usa a **base de Visitantes do condomínio** (tenant), preferindo o cadastro da unidade; resposta traz `da_unidade`. Ao salvar, o convite é vinculado ao cadastro existente (nunca duplica); o estado `EXISTENTE_PORTARIA` deixou de existir.
- Vínculo seguro: `visitante_id` fora da unidade só é aceito quando o documento digitado é idêntico ao do cadastro (`portal_convidados_visitante_por_documento_informado`); caso contrário 403 `VISITANTE_FORA_DO_ESCOPO`. Portal e app enviam `visitante_id` + `tipo_documento` + `documento`. A lista "Visitante já cadastrado" do formulário continua restrita à unidade.
### Adicionado
- **CPF de morador ativo não pode ser cadastrado como visitante** — helper `api/helpers/visitantes_documento_helper.php` (`visitante_cpf_de_morador`, `visitante_mensagem_cpf_morador`) aplicado em: `api/api_visitantes.php` POST e PUT (mensagem com nome/unidade do morador, `dados.cpf_de_morador`), `GET api_visitantes.php?documento=` (avisa `cpf_de_morador` quando não é visitante), `api/api_portal_morador.php?action=visitantes` POST (Portal/App; mensagem sem expor o morador; também valida CPF e grava formatado) e cadastro automático de visitante do pré-cadastro de convidado. Página Visitantes (`visitantes.js`) mostra o aviso ao sair do campo documento.
### Observação
- `api/api_portal.php?action=cadastrar_visitante` é legado (colunas `nome/cpf/tipo` inexistentes, sem tenant) e não é chamado por portal/app; não recebeu a regra. Candidato a remoção.

## [2.1.23] - 2026-11-04
### Adicionado
- **Convidados (Portal do Morador + app): busca automática do documento em Visitantes.** Com CPF completo e válido (ou ao sair do campo, no RG) o formulário consulta `GET api/api_portal_convidados.php?acao=buscar_documento`. Visitante da unidade encontrado → nome preenchido e travado, convite vinculado ao cadastro. Não encontrado → aviso "Visitante não cadastrado"; o morador informa o nome e, ao salvar, o convidado é **cadastrado em Visitantes da unidade** (autoria `MORADOR`, CPF formatado) e vinculado ao convite. `criar` devolve `dados.visitante`: `VINCULADO` | `CADASTRADO` | `EXISTENTE_PORTARIA` | `NAO_CADASTRADO` | `FALHOU`; `cadastrar_visitante:false` desativa o cadastro.
- App Flutter: `GuestDocumentLookup` + `GuestsRemoteDataSource.buscarDocumento()`; `GuestEntity.visitanteId`; formulário com status (encontrado/não cadastrado/CPF inválido), nome travado quando vinculado, vínculo preservado na edição.
- Painel (Registro > Convidados): busca automática ao completar o CPF.
### Corrigido
- **Painel — mensagens do modal "Novo Convidado" apareciam na página atrás do modal** ("CPF inválido", "Visitante não encontrado", erros ao salvar/cancelar). `showFeedback()` passa a escrever no `[data-modal-feedback]` do modal aberto (`#convidadoModalFeedback`, `#convidadoCancelFeedback`); mensagens da busca ficam abaixo do documento (`#convidadoDocumentoFeedback`, estado `.error` novo em `assets/css/pages/registro.css`). Ao abrir um modal, o aviso dele é limpo.
- Painel: com o Nome somente leitura (antes da busca) o envio passava pela validação nativa e só falhava no servidor; agora o modal pede a busca/nome.
- Portal: editar convite vinculado a visitante não derruba mais o vínculo.
### Segurança / privacidade
- `buscar_documento` só revela nome de visitante **da unidade do morador**; documento de outra unidade responde "não cadastrado" e, ao salvar, não cria duplicado nem vincula (`EXISTENTE_PORTARIA`) — a portaria localiza pelo documento na chegada. CPF de morador ativo → 400 `CPF_DE_MORADOR`; CPF inválido → 400 `CPF_INVALIDO`. Falha ao cadastrar o visitante não desfaz o convite salvo (log `[PortalConvidados] falha ao cadastrar visitante`).

## [2.1.22] - 2026-11-04
### Corrigido e adicionado
- **RH — Banco de Horas**: removido o descarte silencioso de extras acima de 120 minutos; `bh_apurar_dia()` aplica `CREDITAR_TUDO` ou separa excedente conforme a política da escala.
- Adicionados mapeamento diário, hash da prévia/entrada, botão Gerar, confirmação, aplicação auditada em lotes de 20, histórico, bloqueio por colaborador, tratamento de períodos fechados e relatório individual/departamental com CSV/PDF.
- Reprocessamento altera somente linhas automáticas do tenant autenticado, preserva abatimentos/pagamentos manuais e não modifica as horas apuradas por mero limite diário. Escritas de Ponto, Abono e DSR compartilham lock/transação com o ledger; falta abonada preserva o DSR. RBAC foi aplicado a leituras RH/PDF, Abono, escala e lançamentos manuais.
- Abatimento acima do saldo exige confirmação explícita e justificativa, pode gerar saldo negativo auditado e respeita limite devedor opcional; pagamento continua limitado ao saldo positivo. Foram centralizados sinal, cálculo e formatação e corrigidos os filtros/gravações multi-tenant, idempotência, sincronização, relatórios e PDF. Exclusão manual requer permissão, motivo e auditoria; lançamentos automáticos não podem ser excluídos manualmente.
- A migration MySQL/MariaDB 5.7 corrige tenant histórico pela relação com o colaborador, adiciona campos/índices e apenas diagnostica `tipo=''`; não corrige tipos vazios. Após o phpMyAdmin retornar #1044, foi removida a dependência de `INFORMATION_SCHEMA`; a idempotência ignora somente coluna/índice duplicados.
- Aviso departamental indica cobertura parcial dos lotes; renderers escapam conteúdo dinâmico e fotos ficam restritas à mesma origem.
### Validado
- Migrations aplicadas duas vezes em MariaDB QA descartável, inclusive reaplicação após existir `excedente`; fixture sintética validou extras de 03:17 e totais de 07:09, além de política limitada de 02:00 + 01:17 excedente.
- Fixtures confirmaram isolamento tenant, preservação de lançamentos manuais, idempotência/replay, lock, lotes 20+2, DSR/falta abonada, períodos fechados, invalidação por alteração pós-prévia e consultas individuais/departamentais.
- `php -l`, `node --check` e `git diff --check` passaram. Não houve endpoint autenticado, renderização visual de PDF, consulta/correção/migration em produção ou deploy.

## [2.1.21] - 2026-11-04
### Adicionado
- **Convidados — pré-cadastro do morador (Portal do Morador + aplicativo)**: nova aba/tela **CONVIDADOS** no `frontend/portal_morador.html` e no app Flutter (`lib/presentation/screens/guests/guests_screen.dart`, rota `/home/guests`, item no menu lateral e card no início). O morador cadastra o convidado com os mesmos campos da Lista de Convidados (anfitrião morador/dependente, visitante já cadastrado da unidade ou CPF/RG + nome, tipo Visitante/Prestador/Entregador autorizado/Familiar, data prevista, dias de permanência, entradas únicas/múltiplas/livres, observação). O convite cai na tabela `convidados` e aparece imediatamente em **Registro > Convidados** para a portaria.
- Novo endpoint `api/api_portal_convidados.php` (Bearer `sessoes_portal`): `opcoes`, `listar` (`filtro=ativos|historico`), `obter`, `verificar_alertas`, `criar`, `editar`, `cancelar`. Morador, unidade, tenant e **origem** são definidos pelo servidor: `PORTAL_MORADOR` (portal) ou `APP_MORADOR` (`canal=app`); autoria `cadastrado_por_tipo=MORADOR`.
- Novo helper `api/helpers/convidados_core_helper.php`: validação, normalização, duplicidade, idempotência e persistência (`convidados_dados_entrada`, `convidados_persistir_novo`, `convidados_atualizar_existente`, `convidados_cancelar_existente`) compartilhados entre painel e portal/app — regra única, sem duplicação.
- Migration `sql/migration_convidados_pre_cadastro_morador_mysql57.sql` (idempotente): acrescenta `APP_MORADOR` ao ENUM `convidados.origem_cadastro` e o índice `idx_conv_tenant_autor_morador`. Sem ela, o app grava `PORTAL_MORADOR` (detecção do ENUM em tempo de execução) — nada quebra.
- Painel (Registro > Convidados): selo **Pré-cadastro** na origem, filtro **Origem** (inclui "Pré-cadastro do morador (Portal/App)", `GET listar&origem=MORADOR`), contador `resumo.pre_cadastros_morador`; na edição de um pré-cadastro a origem fica travada e é preservada pelo servidor.
- Testes de integração versionados em `tests/convidados_pre_cadastro/` (65 casos).
### Corrigido
- **Lista de Convidados do painel retornava HTTP 500 ao listar**: `convidados_sql_base()` declarava `array &$tipos`, mas recebia a string de tipos do `bind_param` → `TypeError` em toda chamada de `GET api_convidados.php?acao=listar`. Assinatura corrigida para `&$tipos`. Sem a correção, a portaria não veria nenhum convite (nem os pré-cadastros do morador).
- Edição de convite criado pelo morador no painel falharia: o select de origem do painel não possuía `PORTAL_MORADOR` e a API recusava a origem. Agora a origem do morador é preservada.
### Segurança
- Morador só lista/obtém/edita/cancela convites com `morador_id` = sessão; edita apenas os que ele mesmo cadastrou (convites da portaria são somente cancelamento); visitante vinculado precisa pertencer à unidade (`pvu_condicao_visitante_unidade`); documento completo só retorna ao próprio autor; `verificar_alertas` do portal devolve somente alertas da própria unidade (alertas de outra unidade que casam pelo documento não são expostos); limite de 100 pré-cadastros PREVISTO por morador (HTTP 429).

## [2.1.20] - 2026-11-04
### Corrigido
- **App/Portal do Morador — Visitantes por Gleba**: `GET api/api_portal_morador.php?action=visitantes` filtrava `morador_id = morador da sessão`, ocultando visitantes cadastrados por outros moradores da mesma unidade e visitantes da base geral autorizados para a Gleba. A lista agora usa o escopo da unidade do morador autenticado (moradores ativos da mesma unidade, mesmo tenant), por `morador_id`, `cadastrado_por_morador_id` ou autorização em `acessos_visitantes`. Novo helper `api/helpers/portal_visitantes_unidade_helper.php`.
- A resposta passou a trazer `cadastrado_por_nome`, `proprio` e `pode_excluir`; `ativo`/`id` são inteiros. A exclusão continua restrita ao autor (`morador_id` da sessão).
- `POST api/api_acessos_visitantes.php` (geração de QR pelo portal/app) usa o mesmo escopo de unidade; antes recusava visitantes da Gleba cadastrados por outro morador.
- App Flutter (`aplicativoerpcondominios`): `visitors_screen.dart` exibe "Visitantes da Unidade", contador, autor do cadastro e só mostra excluir quando `pode_excluir`; `access_screen.dart` lista apenas visitantes ativos no seletor do QR. `frontend/portal_morador.html` recebeu o mesmo tratamento de autor/exclusão.
- **App Flutter — build Android após atualização do Flutter SDK (3.47.1)**: `flutter run` falhava com "Gradle 8.12.0 is lower than Flutter's minimum supported version of 8.14.0". Atualizados wrapper Gradle 8.12 → 8.14, AGP 8.7.3 → 8.13.1, Kotlin 2.1.0 → 2.3.20 e Java 11 → 17, mantendo AGP 8.x.
### Validado
- `php -l` nos três PHP tocados; MariaDB descartável executando o endpoint real com fixture de duas Glebas, ex-morador inativo, Gleba 13/1330, unidade sem prefixo e outro tenant. Detalhes em `DEBUG_VISITANTES_APP_GLEBA_20261104.md`.

## [2.1.19] - 2026-11-04
### Corrigido
- **Registro Manual — revisão de integridade**: validar no backend que `dependente_id` pertence ao `morador_id` informado e ao tenant da sessão; a Entrada exige ambos ativos e a Saída histórica ainda pode ser encerrada, mas nunca com dependente de outro morador.
- Tipo e documento enviados para visitante precisam corresponder ao `visitante_id` do cadastro tenant-local. Permanência dos tipos de visitante aceita somente inteiro de 1 a 365 dias, com omissão igual a 1.
- O replay idempotente agora compara também o conjunto de ocupantes e devolve HTTP 409 `IDEMPOTENCY_CONFLICT` em colisão; o rollback é explícito antes da resposta.
- Pré-validação de Alertas com dependente sem morador informado deriva unidade do morador-pai consultado no tenant. O botão Voltar da etapa Veículo retorna a Destino quando Identificação foi omitida para Morador.
### Validado
- `php -l` nos endpoints/helpers tocados, `node --check` no módulo Registro e `git diff --check`.
- MariaDB descartável executou helpers reais de dependente, documento, permanência, unidade canônica e idempotência: vínculo correto/divergente, tenants cruzados, Entrada/Saída, 1/365 dias, ocupantes iguais/removidos/adicionados/divergentes e resposta 409 estruturada passaram.
- Smoke test Playwright com endpoints mockados incluiu retorno de Veículo para Destino no caso Morador; sem erros de página e sem overflow horizontal em 1366×768 e 375×812. Não houve sessão autenticada, deploy nem teste na base de produção.

## [2.1.18] - 2026-11-04
### Corrigido e alterado
- **Registro Manual — fluxo por etapas**: reorganizado em Acesso, Destino, Identificação e Veículo/Pedestre. Etapas concluídas agora ficam recolhidas com resumo e ação Editar; a etapa de identificação é omitida para Morador. Reabrir Destino preserva os campos posteriores e revalida alertas.
- O foco automático agora escolhe o primeiro controle realmente visível da etapa, ignorando ações Editar ocultas e campos dentro de seções escondidas.
- A API de Alertas passou a aceitar pré-validação parcial por etapa, sem token de ciência e sem revelar documentos de alvos futuros. A gravação mantém a reavaliação formal e o fluxo de `409 ALERTA_PENDENTE`.
- Corrigido o fallback `409` do Registro, que referenciava nome inexistente ao abrir o modal formal; as rotas normal e de fallback agora usam o modal existente. Limpar gera uma nova chave de idempotência. A identificação de Dependente foi alinhada ao tipo avaliado na gravação e a consulta de placa não troca automaticamente o destino escolhido.
### Validado
- `php -l` nos endpoints/helpers PHP e `node --check` no módulo Registro, além de `git diff --check`.
- Fixture MariaDB descartável confirmou os alertas gerais por etapa, contagem de específicos sem expor alvos, reclassificação conforme visitante/placa identificados e isolamento tenant A/B.
- Smoke test Playwright local com APIs interceptadas, sem sessão ou produção, passou em 1366×768 e 375×812: avanço Enter/foco visível, entrada, modal/popups, saída, bloqueio/override, documento obrigatório, Dependente/Pedestre, convite, resumo/edição de etapas, revalidação, `409`→ciência→token→retry com a mesma chave, atualização dos Registros Recentes, limpeza/idempotência e reentrada SPA sem handlers duplicados. Sem erros de página ou overflow horizontal.
- Em MariaDB descartável, o helper real rejeitou token ao mudar o contexto (`CIENCIA_TOKEN_DIVERGENTE`) e em outro tenant (`CIENCIA_TOKEN_INVALIDO`). Não houve teste de endpoint autenticado, deploy, nem alteração no banco de produção.

## [2.1.17] - 2026-11-03
### Corrigido e alterado
- **RBAC — permissões explícitas**: removida a negação implícita entre grupos; a ausência de grant é sem acesso, e somente registros `NEGAR` explícitos negam uma ação. Precedência definida como admin/super_admin, negar individual, permitir individual, negar de grupo e permitir de grupo.
- A Central de Usuários agora edita estados `HERDAR`/`PERMITIR`/`NEGAR` por ação, apresenta origem/prévia efetiva e valida grants em perfil, grupo, vínculo, exceção individual e reset. O backend compara efeitos antes/depois e rejeita autoelevação/concessões indiretas acima da permissão do configurador; grupos de compatibilidade não são atribuíveis manualmente.
- O perfil admin/super_admin é lido do registro atual no tenant, não de uma sessão/localStorage potencialmente desatualizados; a resposta efetiva e `is_admin` usam o mesmo papel do banco. Revisão do tenant revalida caches, MenuController e a sessão ativa sem novo login. Em falha da API, o menu operacional é preservado, escrita é bloqueada e aparece aviso.
- Os guards `PermissoesModulos` e `MenuController` alinham aliases de rota às chaves canônicas RBAC (incluindo `relatorios_acesso`, `administrativo` e rotas auxiliares); os casos legados conhecidos têm alias explícito e ações sem mapeamento negam por padrão.
- **Auditoria estática REPO_ONLY**: analisados 59 módulos ativos; encontrados gaps de enforcement, catálogo, controles e chaves/rotas em múltiplos módulos. Nenhuma base de produção foi consultada.
### Validado
- PHP lint nos cinco endpoints/helpers RBAC tocados, `node --check` em `usuarios.js`, `permissoes-modulos.js` e `menu-controller.js`, além de `git diff --check`.
- Fixture MariaDB descartável confirmou 59 módulos ativos e 199 ações seedadas e testou U1–U9: somente visualização, grants por grupo/usuário, interseção de grupos, NEGAR individual/de grupo, precedência, isolamento tenant, papel admin de banco versus sessão stale, recusa de grant/reset não autorizado, grant por admin e criação/incremento da revisão. Todos passaram; a auditoria adicional inspecionou 357 operações do código, REPO_ONLY.
- Não houve teste autenticado em browser, chamada direta com cookie real, deploy ou consulta/alteração da base de produção.

## [2.1.16] - 2026-11-03
### Corrigido
- **Moradores — permissões de edição**: a aplicação das permissões agora marca quais controles foram desabilitados pelo RBAC e remove `disabled` somente desses controles quando a resposta efetiva permite a ação. Estados `disabled` próprios de fluxos, como a cascata de Unidade → Morador, são preservados.
- Em falha ao carregar permissões, Moradores mantém as ações de escrita bloqueadas e exibe aviso ao usuário, sem conceder acesso por fallback.
### Validado
- `node --check frontend/js/pages/moradores.js`, `php -l api/api_moradores.php` e `git diff --check` passaram. Harness local confirmou reversão do bloqueio provisório para administrador, permanência do `disabled` preexistente, bloqueio de usuário somente leitura e aviso em falha de carregamento.
- Não houve teste autenticado no navegador nem validação em produção; a Parte 2 da auditoria RBAC permanece separada.

## [2.1.15] - 2026-11-03
### Corrigido
- **Alertas da Unidade — cadastro**: o `INSERT` de `alertas_unidade` possuía 24 parâmetros e placeholders, mas a assinatura de tipos mysqli tinha 23 caracteres. O mismatch lançava `ArgumentCountError`, revertia a transação e devolvia HTTP 500. Corrigida a assinatura para cobrir os 24 campos.
- O handler global de exceções agora registra classe, código e mensagem sanitizada no log PHP, mantendo ao cliente a resposta JSON genérica sem detalhes internos.
### Validado
- `php -l api/api_alertas_unidade.php` e `git diff --check` passaram. Com o helper `au_query` real e schema descartável, o bind antigo foi reproduzido como `ArgumentCountError`; a assinatura corrigida inseriu e recuperou os 24 parâmetros, incluindo campos `NULL` e chave de idempotência.
- A assinatura de UPDATE continua com 20 tipos para 20 parâmetros. Não houve teste autenticado no navegador, consulta ao log de produção, migration ou deploy.
- O HTTP 422 também mostrado na captura não foi atribuído a um campo específico porque o corpo JSON da resposta não estava disponível; ele pode ser uma tentativa distinta rejeitada por validação de negócio.

## [2.1.14] - 2026-11-03
### Alterado
- **Moradores — Lista de Moradores**: adicionado filtro de Unidade à barra, opções sem quantitativos em ordem natural e busca automática por texto/unidade/ordenação. O botão Buscar foi substituído por Limpar, que restaura a lista e ordenação padrão.
- A unidade pode ser combinada com texto como condição AND e usa `unidade_exata=1` com igualdade case/space-insensitive; chamadas legadas sem essa flag preservam o filtro antigo. Ordenações Unidade ascendente/descendente agora usam número natural no backend.
- Buscas concorrentes cancelam a requisição anterior e somente a resposta mais recente renderiza. Busca, unidade, ordenação e página são restauradas pela URL. Cadastro, edição, exclusão e operações de anexos recarregam a mesma consulta/página; se uma exclusão esvaziar a última página, a listagem recua.
### Validado
- `php -l api/api_moradores.php`, `node --check frontend/js/pages/moradores.js` e `git diff --check` passaram.
- Fixture MariaDB descartável confirmou igualdade normalizada sem incluir Gleba 10/100 ao filtrar Gleba 1, combinação AND, ordenação natural até Gleba 100 em páginas distintas, compatibilidade da consulta legada e busca de CPF mascarado.
- Harness local da tela confirmou `unidade_exata=1`, filtros combinados, cancelamento/latest-only, recuo de página, restauração de querystring, Limpar, mensagem de zero resultados, ordenação/deduplicação natural e inserção ordenada de unidade de deep-link. Revisão estática confirmou as chamadas de recarga da mesma página nos handlers de cadastro, edição, exclusão e anexos.
### Pendente
- Não houve teste autenticado no navegador/produção; as regressões visuais mobile e as chamadas de outros módulos à API permanecem sem validação ponta a ponta. Nenhuma migration foi criada/aplicada.

## [2.1.13] - 2026-11-03
### Alterado
- **Ordens de Serviço — Chamados**: o select Unidade agora exibe somente o nome e ordena as opções naturalmente no frontend, eliminando duplicatas por trim/caixa. A aba não consulta mais `unidades_com_os`, removendo uma requisição; deep-links e o ranking inserem unidades ausentes em sua posição natural. O contador da lista filtrada e os filtros cumulativos permanecem inalterados.
- `unidades_com_os` foi mantida no backend e documentada como ação tenant-safe/RBAC sem consumidor ativo identificado, candidata a remoção futura.
### Validado
- `node --check frontend/js/pages/ordens_servico.js` e `git diff --check` passaram.
- Harness local confirmou nomes sem quantitativos, ordem natural, deduplicação, placeholder `Todas`, inserção ordenada por URL/ranking e apenas uma chamada à API de unidades, sem request `unidades_com_os`. A ligação já existente Unidade → moradores e atualização dos filtros/lista foi revisada sem alteração.
### Pendente
- Não foi executado browser autenticado/DevTools; fluxos reais de deep-link, ranking, limpeza e regressão visual de Dashboard/Relatórios/impressão ainda precisam de confirmação no navegador.

## [2.1.12] - 2026-11-03
### Corrigido
- **Alertas da Unidade (Moradores › Alertas da Unidade)**:
  - **Ordem das unidades**: `api/api_alertas_unidade.php` (`acao=opcoes`) ordenava com `ORDER BY unidade` textual, o que colocava "Gleba 10" antes de "Gleba 2". Agora a lista é deduplicada por `TRIM`/caixa e ordenada em ordem natural (`strnatcasecmp`). O frontend reaplica `Intl.Collator('pt-BR', {numeric:true})`. Resultado: `200, ADMINISTRATIVO, Gleba 1, Gleba 2, … Gleba 10 … Gleba 100`. Vale para o filtro da lista e para o modal.
  - **Cascata Unidade → Morador titular → Dependente** (`frontend/js/pages/moradores-alertas.js`): o select de unidade não tinha evento, e o modal listava todos os moradores e dependentes do tenant. Agora:
    - o morador fica desabilitado até escolher a unidade e mostra só os moradores ativos dela (comparação `TRIM`/caixa); com um único morador, ele é pré-selecionado;
    - o dependente só fica habilitado quando o tipo do titular é "Dependente" e lista só os dependentes da unidade ou do morador escolhido; com tipo "Morador", fica desabilitado e não é enviado (a API recusa `dependente_id` nesse caso — `TITULAR_INVALIDO`);
    - escolher o dependente antes do titular preenche o morador responsável;
    - editar e duplicar reconstroem a cascata antes de aplicar os valores;
    - mensagens de validação para unidade e dependente ausentes.
  - A comparação de unidade divergente em `au_api_validar_titular()` passou a ignorar espaços e caixa, porque a lista agora usa a unidade com `TRIM`. O backend continua revalidando morador/dependente no tenant da sessão.
- **Testes**: `php -l` OK. Simulação em navegador (Playwright) da ordenação e da cascata (troca de unidade, unidade sem moradores, morador único, titular Morador × Dependente, dependente antes do morador, payload final) OK. Pendente: validação manual no ambiente com sessão real.

## [2.1.11] - 2026-11-03
### Implementado — web/backend
- **Ordens de Serviço**: adicionados filtros exatos de Unidade e Morador encadeados, chip de unidade/contagem, querystring restaurável ao atualizar/compartilhar e atalho clicável/acessível pelo ranking. A listagem continua cumulativa com tenant, RBAC, departamentos e Restritividade O.S.; sem permissão `moradores.visualizar`, apenas o seletor de morador fica indisponível.
- A API usa prepared statements para filtrar pela unidade snapshot da O.S. e só consulta `moradores.unidade` no fallback de registros legados sem unidade persistida. Criar/editar resolve nome/unidade pelo `morador_id` e tenant autenticado; ao editar o mesmo morador preserva o snapshot antigo, e só grava outra unidade em troca explícita do vínculo. O fluxo do Portal do Morador não foi modificado.
- **Moradores**: a listagem GET e sua contagem aplicam obrigatoriamente `tenant_id` da sessão antes dos filtros opcionais, fechando uma consulta que podia retornar registros de outros tenants.
- O filtro de O.S. usa `GET api/api_moradores.php?acao=select_por_unidade`, que retorna apenas ID/nome de moradores ativos e mantém o RBAC `moradores.visualizar` já exigido pelo endpoint.
- Adicionada migration MySQL/MariaDB 5.7 idempotente para `idx_os_tenant_unidade` e script somente leitura `sql/diagnostico_os_unidade_divergente.sql`; a migration não foi aplicada em produção.

## [2.1.10] - 2026-11-03
### Corrigido
- **Ordens de Serviço**: o formulário operacional passa a usar a identidade do usuário autenticado como atendente, sem depender da API global de usuários. Gestores recebem uma lista mínima de atendentes ativos do próprio tenant; o backend valida o tenant e impede atribuições novas a usuários inativos ou de outra empresa.
- O usuário comum não consegue reatribuir O.S. ao editar; o responsável anterior é preservado. A Restritividade O.S. inclui ordens criadas, atribuídas ou assumidas pelo usuário, respeitando o tenant e o esquema antigo sem a coluna opcional `assumido_por_id`. O vínculo pessoal não concede escrita sem as permissões aplicáveis.

## [2.1.9] - 2026-11-03
### Corrigido
- **Moradores e Alertas da Unidade**: a leitura das regras de campos agora exige `moradores.visualizar`, enquanto alterações continuam exigindo `sistema.configurar`. O painel Alertas usa as permissões específicas retornadas pelo backend, oculta ações de escrita para visualizadores e não mantém o detalhe aberto quando a API não retorna um alerta válido. A avaliação operacional dos alertas continua no lançamento manual de entrada.
- **RBAC de Moradores**: grupo personalizado ou `compat-visualizador` que concede somente `moradores.visualizar` agora nega explicitamente permissões herdadas de escrita, inclusive de `compat-operador`; a revisão do tenant é criada/incrementada ao alterar políticas, invalidando caches de sessões ativas.

### Validado
- Teste em MariaDB descartável confirmou que grupo customizado somente leitura e `compat-visualizador` mantêm `moradores.visualizar` e negam criar/editar/excluir/exportar apesar do `compat-operador`; o operador compatível sem grupo somente leitura preserva edição. Invalidação de cache e criação da revisão RBAC ausente também passaram. `php -l`, `node --check` e `git diff --check` passaram.

## [2.1.9] - 2026-11-04
### Planejado
- **RH — Banco de Horas: botão "Gerar" (reprocessar) + mapeamento espelho × banco**: causa confirmada em `rh_ponto_core.php` `_bh_sincronizar()` L401–407 — `min($extraLiquido, 120)` descarta silenciosamente horas extras acima de 2h/dia (caso Wanderson 17/10/2026: espelho 03:17, banco 02:00; outubro: espelho 07:09 × banco 04:47). Banco só é ressincronizado ao salvar/recalcular o espelho, ficando congelado em regras antigas. Especificado: função única `bh_apurar_dia()`, política configurável `CREDITAR_TUDO` (padrão) / `LIMITAR_E_SEPARAR` (excedente fora do saldo, nunca descartado), endpoint `mapear`, botão "Gerar" ao lado do filtro Colaborador com prévia (simular) → aplicar com hash, lotes por departamento, tabela `rh_banco_horas_reprocessamentos`. Nenhuma alteração de código — prompt em `PROMPT_BANCO_HORAS_REPROCESSAR_20261104.md`.

## [2.1.8] - 2026-11-03
### Corrigido
- **Protocolos de Mercadorias**: removida a dependência da listagem global de usuários, que retornava 403 para funcionários sem `usuarios.visualizar`. O campo Recebedor (Portaria) passa a exibir o operador autenticado; a API grava esse nome a partir da sessão e preserva a autoria na edição. Escritas passam a exigir permissões RBAC do módulo Protocolos.

## [2.1.8] - 2026-11-04
### Planejado
- **RH — Banco de Horas: abatimento maior que o saldo**: analisados `api_rh_banco_horas.php`, `rh_ponto_core.php` (`_bh_sincronizar`), `api_rh_ponto.php` (DSR), `api_rh_relatorios.php`, `recursos_humanos.js/.html`. Causa do bloqueio "Saldo insuficiente": `registrar` rejeita `minutos > saldo` para abatimento e pagamento (L187). Especificado: abatimento pode negativar o saldo com confirmação (HTTP 409 `CONFIRMAR_SALDO_NEGATIVO` + justificativa + auditoria); pagamento segue limitado ao saldo. Achados críticos: INSERTs em `rh_banco_horas` sem `tenant_id` (coluna `DEFAULT 1` → lançamentos de qualquer tenant caem no tenant 1); extrato/relatório sem filtro de tenant; saldo calculado em 4 lugares tratando qualquer tipo não-crédito como débito; linha de 15/09/2026 com `tipo` vazio no extrato; sem transação/lock no registrar. Nenhuma alteração de código — prompt em `PROMPT_BANCO_HORAS_ABATIMENTO_20261104.md`.

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
