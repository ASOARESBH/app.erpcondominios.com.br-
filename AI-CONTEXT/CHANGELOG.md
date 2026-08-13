# Changelog

Registro de alterações significativas no ERP.

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
### Adicionado
- **Moradores — Ordenação paginada**: A lista principal recebeu seletor de nome, unidade e ID em ordem crescente/decrescente. A ordenação ocorre no servidor por lista branca SQL, preservando busca, paginação e a aba Dependentes. Validados sintaxe PHP/JS, as seis opções, propagação do parâmetro e fallback seguro para valores inválidos.

### Corrigido
- **Moradores — proporção da busca e ordenação**: Corrigido o `width: 100%` herdado pelo seletor de ordenação quando `flex-basis: auto` era aplicado. A regra foi limitada a `#ordenarMoradores`, com largura automática entre 200 e 240 px; a busca retoma o espaço principal da linha e as regras mobile existentes permanecem ativas. A causa foi confirmada visualmente no navegador antes da correção.

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

### Validado
- O PDF BRCondos de referência foi processado com 41 unidades, 1.153 lançamentos, R$ 405.265,52 lançado e R$ 574.363,84 projetado, com totais conciliados e cobertura para Gleba com múltiplos lançamentos, proprietário `E OUTROS` e descrição extra longa.
- O parser possui fallback para ambientes PHP sem `mbstring`; consultas usam `tenant_id` exclusivamente da sessão e ordenações utilizam lista branca.
