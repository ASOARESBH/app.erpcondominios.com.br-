# App Mobile (Flutter) — Arquitetura

> Cobre o repositório `aplicativoerpcondominios` (Flutter/Dart), consumidor das APIs PHP documentadas em [API.md](API.md), [DATABASE.md](DATABASE.md) e [TENANT_FILE_STORAGE.md](TENANT_FILE_STORAGE.md). Este documento é o mapa arquitetural do app; as regras de negócio por tela estão em [APP_MOBILE_BUSINESS_RULES.md](APP_MOBILE_BUSINESS_RULES.md).

## 1. Visão geral

O app tem **dois contextos de autenticação totalmente independentes**, cada um com seu próprio login, token, chave de armazenamento seguro e cliente HTTP:

- **Portal do Morador** — app principal, login por CPF+senha. É o que aparece ao abrir o app.
- **Portal do Colaborador** — uso operacional interno (portaria/zeladoria/leiturista), login por e-mail corporativo+senha. Não existe botão visível de acesso: o usuário precisa tocar **5 vezes em até 3 segundos** no logo da tela de login do morador (`login_screen.dart`, `_handleLogoTap`) para navegar a `/employee/login`. É um ponto de entrada intencional, não documentado na UI.

Os dois fluxos nunca compartilham sessão: logar como colaborador não sobrescreve o token/dados do morador e vice-versa.

## 2. Stack técnica

- **Flutter + Riverpod** (`flutter_riverpod`) para estado (providers em `lib/presentation/providers/`).
- **go_router** para navegação declarativa (`lib/presentation/router/app_router.dart`), com duas `ShellRoute` (uma para o shell do morador `/home/*`, outra para o shell do colaborador `/employee/*`).
- **Dio** para HTTP, com dois clientes distintos:
  - `DioClient` (`lib/core/network/dio_client.dart`) — Portal do Morador, injeta `Authorization: Bearer <portal_token>` via interceptor em toda rota exceto login/recuperação de senha.
  - `EmployeeApiClient` (`lib/core/network/employee_api_client.dart`) — Portal do Colaborador, exige token de colaborador em cada chamada (`_requireToken()`), lança `StateError` se ausente.
  - **Ambos** configuram `validateStatus: (status) => status != null && status < 500` — ou seja, respostas HTTP 4xx **não** viram exceção Dio; chegam como resposta normal e a tela precisa checar `data['sucesso']` manualmente. Isso é uma fonte real de bugs silenciosos quando uma tela só confere `if (data['sucesso'] == true)` e ignora o `else` (ver `documents_screen.dart` no `DEBUG_DOCUMENTOS_PORTAL_VAZIO_20260813.md`).
- **URL base fixa**: `https://app.erpcondominios.com.br` (`AppConstants.baseUrl`). Não existe troca de URL por condomínio — o multi-tenant é resolvido **apenas** pelo token Bearer no backend (ver [MULTITENANT_ARCHITECTURE.md](MULTITENANT_ARCHITECTURE.md)). Comentários no código deixam explícito que `updateBaseUrl`/`initBaseUrl` foram removidos do `DioClient` — ver seção 6 sobre um bug remanescente dessa remoção.

## 3. Estrutura de pastas (`lib/`)

```
core/
  constants/app_constants.dart   — nomes de rotas de API, actions, chaves de storage
  network/                       — DioClient, EmployeeApiClient, NotificationService
  ocr/                           — leitura de hidrômetro por foto (ML Kit, on-device)
  offline/                       — fila local (sqlite) de leituras de hidrômetro do colaborador
  security/                      — SecureStorageService (tokens/sessão), BiometricService
  theme/                         — AppTheme (cores, light/dark)
  utils/                         — Validators, formatadores de input
data/
  datasources/remote/            — AuthRemoteDataSource (só o fluxo de login do morador está nessa camada)
  models/                        — MoradorModel, LoginResponseModel
  repositories/                  — AuthRepositoryImpl
domain/
  entities/                      — MoradorEntity, MoradorSessionEntity, TicketEntity, VisitorEntity, WaterMeterEntity...
  repositories/                  — AuthRepository (abstrata)
presentation/
  providers/                     — auth_provider (morador), employee_auth_provider, notification_provider
  router/app_router.dart         — GoRouter, rotas nomeadas, guarda de autenticação
  screens/                       — uma pasta por módulo (ver tabela em APP_MOBILE_BUSINESS_RULES.md)
  widgets/common/                — EmptyState, ErrorState, LoadingOverlay
```

**Observação de arquitetura**: apenas o fluxo de autenticação do morador segue Clean Architecture completa (datasource → repository → provider). Todas as demais telas do morador e do colaborador chamam `dioClient.dio` / `employeeApiProvider` **diretamente dentro do `State` da tela**, sem passar por repository/datasource — é o padrão predominante no app, não uma exceção.

## 4. Guarda de rotas (`app_router.dart`)

- `redirect()` só atua nas rotas do morador. Rotas que começam com `/employee` retornam `null` imediatamente (`isEmployeeRoute`) — a guarda de autenticação do colaborador é feita dentro do próprio `EmployeeShellScreen` (`_restoring` + `state.isAuthenticated`), não no roteador.
- Durante `authState.isLoading` ou na rota `splash`, o redirect não navega — evita loop de redirecionamento enquanto a `SplashScreen` decide a sessão inicial.
- Não autenticado fora de página pública → `/login`. Autenticado em `/login` ou `/forgot-password` → `/home`.
- O roteador é criado **uma única vez** (`Provider<GoRouter>`) e usa `refreshListenable` (`_AuthRouterRefresh`) que escuta o `authProvider` — evitando recriar toda a árvore de navegação a cada mudança de sessão (comentário no código indica que a versão anterior causava concorrência com a navegação da tela de login).

## 5. Sessão e armazenamento seguro

`SecureStorageService` (flutter_secure_storage) guarda:

- Morador: `portal_token` + `morador_session` (JSON único com moradorId/nome/unidade/email — desenhado assim de propósito para evitar múltiplas operações concorrentes no Android Keystore logo após o login; chaves antigas `morador_id`/`morador_nome`/... continuam sendo lidas como fallback de instalações antigas).
- Colaborador: `colaborador_token` + `colaborador_session` (JSON com `usuario` e `tenant`).
- Preferências: biometria habilitada, dark mode (chaves existem no storage, mas não há tela liga/desliga dark mode nem fluxo de biometria implementado nas telas lidas — `BiometricService` existe mas não é chamado por nenhuma tela atual).

`EmployeeAuthNotifier.restoreSession()`: se a checagem de sessão falhar por **erro de rede** (não por HTTP 401), a sessão local é mantida como autenticada mesmo sem confirmação do servidor — decisão deliberada ("uma indisponibilidade transitória não descarta uma sessão local"); os endpoints protegidos continuam validando o token no servidor a cada chamada, então isso não é uma falha de segurança, apenas de UX otimista offline.

## 6. Bug conhecido: `tickets_screen.dart` chama método removido do `DioClient`

`lib/presentation/screens/tickets/tickets_screen.dart` (Portal do Morador, aba "Chamados") chama `await widget.dioClient.initBaseUrl();` em dois pontos:

- `_NewTicketSheetState._submit()` (linha ~251) — antes de criar um chamado.
- `_TicketDetailSheetState._loadDetail()` (linha ~325) — antes de buscar o histórico de interações do chamado.

`DioClient` **não possui mais** o método `initBaseUrl()` (removido junto da migração para URL fixa multi-tenant — ver comentário na linha 11 de `dio_client.dart`: *"NÃO há troca dinâmica de URL — removido updateBaseUrl/initBaseUrl."*). Como `dioClient` é declarado como `dynamic` nesses dois widgets (não `DioClient`), o projeto **compila normalmente**, mas em tempo de execução essa chamada lança `NoSuchMethodError`, capturado pelo `catch (e)` da própria tela e exibido como `SnackBar('Erro: ...')`. Na prática: **abrir um novo chamado e visualizar o histórico de um chamado existente estão quebrados no Portal do Morador**. A lista de chamados em si carrega normalmente (não usa esse client).

Correção sugerida (não aplicada nesta análise): remover as duas chamadas a `initBaseUrl()` em `tickets_screen.dart` — o `DioClient` já inicializa a `baseUrl` fixa no construtor, nenhuma inicialização adicional é necessária.

## 7. Recursos offline (Portal do Colaborador — leiturista de hidrômetro)

- `WaterMeterOfflineService` mantém uma fila local em SQLite (`erp_leiturista_offline.db`) por `tenant_id`, com fotos gravadas em armazenamento **privado** do app (nunca na galeria do aparelho).
- Requer no mínimo 200MB livres para aceitar nova foto; avisa (mas não bloqueia) abaixo de 500MB.
- `WaterMeterSyncService` sincroniza automaticamente ao detectar conectividade (`connectivity_plus`) e ao retomar o app (`AppLifecycleState.resumed`), usando `client_uuid` gerado no aparelho para tornar o reenvio idempotente após queda de conexão. Upload de foto e registro da leitura são duas chamadas separadas e independentes (a foto pode já estar enviada e só a leitura pendente, ou vice-versa).
- `WaterMeterOcrService` roda reconhecimento de texto **no aparelho** (Google ML Kit, sem enviar a imagem para nenhum serviço externo de OCR) só para **sugerir** a leitura; a tela exige confirmação humana explícita (checkbox "Conferi a leitura no visor do hidrômetro") antes de permitir salvar quando uma foto foi tirada.

## 8. Notificações

- Firebase Cloud Messaging, **opt-in explícito** — nunca habilitado automaticamente. A lista de notificações em `/home/notifications` é sempre funcional via API mesmo com push desabilitado no aparelho (o backend é a fonte de verdade, o push é só um "avisador" complementar).
- Canais Android: `encomendas`, `controle_acesso`, `erp_condominios` (geral).
- Registro/remoção do token FCM acontece em `POST /api/api_portal_morador.php?action=registrar_token_push|desativar_token_push` (chamado por `NotificationManager`, **não** pelo endpoint `api_pwa_push.php` — ver observação na seção 4 de [APP_MOBILE_BUSINESS_RULES.md](APP_MOBILE_BUSINESS_RULES.md) sobre constantes de endpoint declaradas e não usadas).
- Falhas de sincronização de push nunca bloqueiam login/navegação (`unawaited(...)` + try/catch silencioso em `NotificationManager.syncAfterAuthenticatedSession()`).

## 9. Dependências relevantes (pubspec.yaml)

Flutter + Riverpod, go_router, Dio, flutter_secure_storage, local_auth (biometria, não usado nas telas), firebase_core/firebase_messaging/flutter_local_notifications, connectivity_plus, sqflite + path/path_provider (fila offline), disk_space_plus (checagem de espaço livre), google_mlkit_text_recognition (OCR on-device), image_picker (foto do hidrômetro), mobile_scanner (QR/código de barras), qr_flutter (geração de QR Code de acesso), fl_chart (gráfico de consumo de água), intl (formatação de data).
