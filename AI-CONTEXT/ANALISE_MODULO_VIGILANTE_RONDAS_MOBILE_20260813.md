# Análise: módulo Vigilante/Rondas (web) e o que falta para levá-lo ao Portal do Colaborador (mobile)

## Pedido do usuário
Criar, dentro do Portal do Colaborador do app Flutter, um novo menu **"Vigilante"**, posicionado logo abaixo de "Leitura de água" e ao lado de "Meus chamados" (ou seja: 8º item da grade 2 colunas, mesma linha de "Meus chamados", mesma coluna de "Leitura de água"). Esse menu deve abrir a câmera, ler o QR Code afixado fisicamente no ponto de ronda e registrar a leitura — funcionando como um "bate-ponto" de ronda. O usuário pediu para primeiro entender toda a regra de negócio do módulo já existente em `https://app.erpcondominios.com.br/frontend/layout-base.html?page=rondas_vigilante` antes de implementar.

## O que já existe (módulo administrativo web, 100% funcional)

### Tabelas envolvidas (inferidas do código, não documentadas ainda em `AI-CONTEXT/DATABASE.md`)
- `ronda_rotas` — rota/ronda: `nome`, `descricao`, `hora_inicio`, `hora_fim` (opcional), `intervalo_minutos` (tempo entre ciclos), `repeticoes_por_dia` (quantos ciclos são esperados no dia), `tolerancia_minutos` (tolerância de SLA), `dias_semana` (CSV de `0`–`6`, domingo=0), `ativo`.
- `ronda_pontos` — pontos de checagem de uma rota: `rota_id`, `nome`, `localizacao`, `instrucoes`, `ordem`, `token_qr` (64 caracteres hexadecimais, gerado com `bin2hex(random_bytes(32))` — **é isso que vira o QR Code impresso e afixado no ponto físico**), `ativo`.
- `ronda_vigilantes` — vínculo N:N entre `rota_id` e `colaborador_id` (referencia `rh_colaboradores.id`, **não** `usuarios.id`), `ativo`.
- `ronda_registros` — leitura registrada: `rota_id`, `ponto_id`, `colaborador_id`, `ciclo_chave` (hash único que evita duplicidade — ver abaixo), `previsto_em`, `status_sla` (`no_prazo`/`atrasado`), `atraso_minutos`, `latitude`/`longitude`/`precisao_metros` (opcionais), `ip`, `user_agent`, `registrado_em`.
- `ronda_auditoria` — log de auditoria de toda ação administrativa e de toda leitura QR.

### Regra de ciclo e SLA (função `rv_ciclo()` / `rv_status_sla()` em `api/api_rondas_vigilante.php`)
- Cada rota tem uma janela diária: começa em `hora_inicio`, repete a cada `intervalo_minutos`, até `repeticoes_por_dia` vezes (ou até `hora_fim`, se definida).
- Em qualquer instante, o "ciclo atual" é calculado por `floor((agora - inicio) / intervalo)`. Se esse índice for negativo (antes do início) ou >= `repeticoes_por_dia` (ronda do dia já encerrada) ou depois de `hora_fim`, a rota está **fora da janela** — leitura recusada.
- `status_sla` é `atrasado` se o horário atual passar do horário previsto do ciclo + `tolerancia_minutos`; senão `no_prazo`.
- **Chave de deduplicação**: `sha256(tenant_id|rota_id|colaborador_id|AAAAMMDD:indice_do_ciclo)`. Essa chave é gravada em `ronda_registros.ciclo_chave` com índice único — por isso o mesmo vigilante não consegue registrar duas vezes o mesmo ponto dentro do mesmo ciclo (erro 1062 → mensagem "Este ponto já foi registrado neste ciclo de ronda.").
- A rota só é válida no dia da semana atual (`dias_semana`).

### Endpoints já implementados em `api/api_rondas_vigilante.php` (backend pronto, únicos que interessam ao app)
- **`qr_detalhe`** (GET, parâmetro `token`): valida formato do token (64 hex), busca o ponto + rota + tenant pelo `token_qr`, retorna dados do ponto (nome, localização, instruções, rota) e a lista de vigilantes vinculados àquela rota. **Não exige sessão/tenant do chamador** — resolve o tenant a partir do próprio token do QR.
- **`registrar_leitura`** (POST, `token`, `colaborador_id`, `latitude`/`longitude`/`precisao_metros` opcionais): revalida o token, confere se a rota está programada para hoje, confere se o `colaborador_id` informado está vinculado à rota (`ronda_vigilantes`), calcula SLA/ciclo, grava em `ronda_registros`, audita, e devolve `status_sla` + `atraso_minutos` + nomes de ponto/rota. **Também não exige sessão** — o único dado sensível exigido é o `colaborador_id`, que hoje é **recebido cru do cliente, sem validação de identidade** (qualquer chamador que souber o token do QR e um `colaborador_id` válido da rota pode registrar em nome de outro vigilante). Isso é aceitável no desenho atual porque não existe cliente algum ainda usando esse endpoint — é o ponto que precisa de atenção ao construir o app (ver "Decisão de arquitetura" abaixo).
- Todas as demais ações (`listar_rotas`, `salvar_rota`, `salvar_ponto`, `regenerar_qr`, `vincular_vigilante`, `dashboard`, `relatorio`, etc.) são exclusivamente administrativas, exigem sessão web autenticada como `operador` ou superior (`verificarAutenticacao(true,'operador')`) e tenant resolvido da sessão — **não são usadas pelo app do vigilante**, só pelo painel.

### Frontend web (`frontend/pages/rondas_vigilante.html` + `frontend/js/pages/rondas_vigilante.js`)
- É **só o painel administrativo**: abas de Dashboard (KPIs + alertas de SLA), Rotas e Pontos (CRUD), QR Codes (gerar/imprimir etiquetas dos pontos) e Relatórios.
- **Confirmado por leitura completa do HTML/JS: não existe nenhuma tela de leitura de QR nesse módulo, nem no painel web, nem em nenhum outro lugar do projeto.** As funções `qr_detalhe` e `registrar_leitura` do backend nunca são chamadas por nenhum cliente hoje — foram construídas e testadas isoladamente, mas o "bate-ponto" em si (o app que o vigilante realmente usa no bolso) ainda não existe. É exatamente essa peça que falta e que o usuário está pedindo.

## Gaps identificados para levar isso ao Portal do Colaborador (mobile)

### 1. Identidade: `usuarios` (login do app) ≠ `rh_colaboradores` (quem pode ser vigilante) — gap crítico
- O Portal do Colaborador do app Flutter autentica contra a tabela **`usuarios`** (login por e-mail/senha em `api/api_colaborador_mobile.php`, função `cm_login()`, sessão validada por Bearer token em `cm_autenticar()`, tabela `sessoes_colaborador_mobile`).
- Mas `ronda_vigilantes.colaborador_id` e `ronda_registros.colaborador_id` referenciam **`rh_colaboradores.id`** — uma tabela de RH completamente separada, sem nenhuma coluna de vínculo com `usuarios` (confirmado por grep: `api_colaborador_mobile.php` nunca referencia `rh_colaboradores`).
- **Ponte disponível**: as duas tabelas têm coluna `email` (`usuarios.email` e `rh_colaboradores.email`, confirmado em `api_rh_colaboradores.php`). A forma mais segura de resolver `colaborador_id` a partir da sessão autenticada do app é buscar, no mesmo `tenant_id` da sessão, o `rh_colaboradores` cujo `email` bate com o `usuarios.email` da sessão.
- Se não houver correspondência (colaborador logado no app não tem cadastro em RH com o mesmo e-mail, ou o vigilante real usa um `rh_colaboradores` sem e-mail preenchido), a tela precisa de um plano B: usar a lista de `vigilantes` que `qr_detalhe` já retorna (nomes dos colaboradores vinculados àquela rota especificamente) como um seletor de confirmação — mas isso deve ser exceção, não o caminho principal.

### 2. Não seguir o padrão de "confiar no `colaborador_id` do cliente"
- Hoje `registrar_leitura` em `api_rondas_vigilante.php` aceita `colaborador_id` diretamente do corpo da requisição, sem checar identidade — porque foi desenhado para ser chamado por um cliente ainda a construir, sem sessão própria. Isso conflita com a regra do projeto de nunca confiar em identificadores de identidade vindos do cliente (mesma lição já registrada nas investigações de notificação de Controle de Acesso e Documentos).
- **Recomendação de arquitetura** (ver prompt para o Manus): não fazer o app Flutter chamar `api_rondas_vigilante.php` diretamente. Em vez disso, criar duas ações novas dentro de **`api_colaborador_mobile.php`** (`vigilante_qr_detalhe` e `vigilante_registrar_leitura`), autenticadas com o Bearer token do colaborador já existente (`cm_autenticar()`), que resolvem `tenant_id` e `colaborador_id` no servidor (nunca do cliente) e então reaproveitam a mesma lógica de negócio (ciclo, SLA, deduplicação) já validada em `api_rondas_vigilante.php`.

### 3. Duplicação de lógica de negócio a evitar
- As funções `rv_ciclo()`, `rv_status_sla()`, `rv_rota_ativa_hoje()`, `rv_dias_normalizar()` em `api_rondas_vigilante.php` não estão em um helper compartilhado — hoje só esse arquivo as usa. Se as novas ações do Portal do Colaborador precisarem do mesmo cálculo, o ideal é extrair essas funções para um arquivo compartilhado (`api/helpers/ronda_helper.php`) e `require_once` dos dois lados, para a regra de SLA nunca divergir entre o painel administrativo e o app do vigilante.

### 4. App Flutter já tem tudo pronto para a câmera — só falta a tela
- O app já usa o pacote **`mobile_scanner`** e já tem uma tela genérica de leitura (`lib/presentation/screens/employee/employee_qr_scanner_screen.dart`, rota `/employee/scan`), que devolve o texto lido via `Navigator.of(context).pop(value)` — já usada por `employee_receive_protocol_screen.dart` via `await context.push<String>('/employee/scan')`. Como o `token_qr` do ponto é só uma string hex crua (não uma URL), essa mesma tela de scanner **pode ser reaproveitada tal como está**, sem nenhuma mudança, para ler o QR do ponto de ronda.
- O menu "Vigilante" deve ser o 8º item da grade 2×N em `lib/presentation/screens/employee/employee_dashboard_screen.dart` (`_module(...)`), logo depois de "Meus chamados" (7º item) — isso o posiciona exatamente abaixo de "Leitura de água" (6º item, mesma coluna) e ao lado de "Meus chamados" (mesma linha), como pedido.

### 5. Nenhuma dessas tabelas/regras está documentada em `AI-CONTEXT/`
- `DATABASE.md` e `API.md` não têm nenhuma menção a `ronda_rotas`, `ronda_pontos`, `ronda_vigilantes`, `ronda_registros`, `ronda_auditoria` ou às ações de `api_rondas_vigilante.php`. Esta análise é o primeiro registro desse módulo no contexto de IA do projeto.

## Fontes locais
- `api/api_rondas_vigilante.php` (backend do painel administrativo + endpoints de leitura QR já prontos)
- `frontend/pages/rondas_vigilante.html`, `frontend/js/pages/rondas_vigilante.js` (painel administrativo web)
- `api/api_colaborador_mobile.php` (backend do Portal do Colaborador mobile — login, sessão Bearer, dashboard, chamados, leitura de água, protocolos)
- `api/api_rh_colaboradores.php` (confirma coluna `email` em `rh_colaboradores`)
- `lib/presentation/screens/employee/employee_dashboard_screen.dart` (grade de menus do Portal do Colaborador)
- `lib/presentation/screens/employee/employee_qr_scanner_screen.dart` (scanner de câmera já pronto, `mobile_scanner`)
- `lib/presentation/router/app_router.dart` (rotas `/employee/*`)
- `lib/core/network/employee_api_client.dart`, `lib/core/constants/app_constants.dart` (padrão de chamada Bearer ao `endpointColaborador`)
