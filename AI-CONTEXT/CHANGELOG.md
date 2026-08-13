# Changelog

Registro de alterações significativas no ERP.

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
