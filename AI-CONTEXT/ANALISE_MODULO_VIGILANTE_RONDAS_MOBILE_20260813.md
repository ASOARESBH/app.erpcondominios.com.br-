# Análise — Módulo Vigilante/Rondas Mobile

**Data:** 13 de agosto de 2026

## Pedido do usuário
Criar, dentro do Portal do Colaborador do app Flutter, um novo menu **"Vigilante"**, posicionado logo abaixo de "Leitura de água" e ao lado de "Meus chamados" (8º item da grade 2 colunas). Esse menu deve abrir a câmera, ler o QR Code afixado no ponto de ronda e registrar a leitura — funcionando como um "bate-ponto". O usuário pediu para primeiro entender toda a regra de negócio do módulo já existente em `https://app.erpcondominios.com.br/frontend/layout-base.html?page=rondas_vigilante` antes de implementar.

## Situação geral
O backend já expõe endpoints relacionados a rondas (`api/api_rondas_vigilante.php`) que implementam regras de ciclo, SLA, QR e gravação em `ronda_registros`. Essas ações administrativas e públicas existem, mas não havia cliente móvel oficial até a proposta atual. O branch de integração traz uma implementação móvel e API autenticada (validações unitárias e testes automatizados locais) — a documentação a seguir reúne a análise original e as adaptações arquiteturais propostas/implementadas.

## O que já existe (módulo administrativo web)
- Tabelas principais (resumo):
  - `ronda_rotas` — nome, descricao, hora_inicio, hora_fim (opcional), intervalo_minutos, repeticoes_por_dia, tolerancia_minutos, dias_semana, ativo.
  - `ronda_pontos` — rota_id, nome, localizacao, instrucoes, ordem, token_qr (64 hex), ativo.
  - `ronda_vigilantes` — vínculo N:N entre `rota_id` e `colaborador_id` (referência `rh_colaboradores.id`, não `usuarios.id`).
  - `ronda_registros` — rota_id, ponto_id, colaborador_id, ciclo_chave, previsto_em, status_sla, atraso_minutos, latitude/longitude/precisao_metros (opcionais), ip, user_agent, registrado_em.
  - `ronda_auditoria` — logs de auditoria.

- Endpoints relevantes (backend):
  - `qr_detalhe` (GET, token): valida token (64 hex), retorna ponto+rota e lista de vigilantes vinculados ao ponto. Resolve tenant a partir do token; originalmente não exigia sessão.
  - `registrar_leitura` (POST, token, colaborador_id, gps opt.): revalida token, checa agenda/rota/ligacao do colaborador, calcula SLA/ciclo, grava em `ronda_registros` e audita. Hoje aceita `colaborador_id` do cliente (sem validar identidade) — desenho aceitável enquanto não havia cliente móvel, mas inseguro para um app autenticado.

## Regra de ciclo e SLA
- Ciclos: janela diária começa em `hora_inicio`, repete a cada `intervalo_minutos` até `repeticoes_por_dia` (ou `hora_fim` se fornecido).
- Ciclo atual: floor((agora - inicio)/intervalo); fora da janela → leitura recusada.
- `status_sla`: `atrasado` se hora atual > previsto + tolerancia; senão `no_prazo`.
- Deduplicação: `ciclo_chave = sha256(tenant_id|rota_id|colaborador_id|YYYYMMDD:indice)`; índice único impede duplicidade (erro 1062 → "Este ponto já foi registrado neste ciclo de ronda.").
- Rota só válida nos dias listados em `dias_semana`.

## Gaps e decisões arquiteturais cruciais
1) Identidade: `usuarios` (login do app) ≠ `rh_colaboradores` (vigilantes)
- O app mobile autentica `usuarios` via `api/api_colaborador_mobile.php` (sessão Bearer). As tabelas de RH usam `rh_colaboradores.id`. Ponte segura: resolver `rh_colaboradores` por `LOWER(usuarios.email) = LOWER(rh_colaboradores.email)` no mesmo `tenant_id` da sessão.
- Se não houver correspondência única, usar como exceção a lista de vigilantes retornada por `qr_detalhe` como seletor de confirmação, mas preferir sempre a resolução por e-mail do servidor.

2) Não confiar em `colaborador_id` enviado pelo cliente
- Não permitir que o app passe `colaborador_id` cru. Em vez disso, criar ações autenticadas dentro de `api/api_colaborador_mobile.php` (ex.: `vigilante_qr_detalhe`, `vigilante_registrar_leitura`, `vigilante_historico_hoje`) que resolvem `tenant_id` e `colaborador_id` no servidor a partir da sessão Bearer.
- A implementação trazida incorpora esse princípio: o cliente móvel nunca fornece `tenant_id` nem `colaborador_id` livres.

3) Evitar duplicação de lógica (extração do helper)
- Extrair funções de ciclo/SLA para `api/helpers/ronda_helper.php` e `require_once` tanto em `api/api_rondas_vigilante.php` (admin) quanto em `api/api_colaborador_mobile.php` (móvel). Isso garante consistência em cálculos de ciclo/SLA.

4) Identidade opaca para exceções (HMAC temporário)
- Quando houver ambiguidade/mais de um `rh_colaboradores` com o mesmo e-mail/tenant, a API pode entregar ao app uma opção opaca assinada por HMAC (vinculada à sessão, tenant, rota, colaborador e expiração curta ~5 min). O app devolve a opção; o servidor valida a assinatura antes de gravar. Isso preserva segurança sem expor IDs.

5) App Flutter: câmera e tela de scanner
- O app já usa `mobile_scanner` e possui `lib/presentation/screens/employee/employee_qr_scanner_screen.dart` (rota `/employee/scan`) que retorna o texto lido via `Navigator.pop(value)`. O token QR é apenas string hex; essa tela pode ser reaproveitada sem mudanças.
- O menu "Vigilante" deve ser inserido como 8º item na grade em `lib/presentation/screens/employee/employee_dashboard_screen.dart` (`_module(...)`) logo após "Meus chamados".

## Contrato móvel proposto/implementado
- `vigilante_qr_detalhe` (GET, token): retorna ponto, rota, instruções, e, se aplicável, o vigilante resolvido pelo servidor ou opções opacas.
- `vigilante_registrar_leitura` (POST, token, opcao_vigilante_opaca opt., gps opt.): registra leitura resolvendo identidade pela sessão/opção opaca, valida agenda/janela, calcula SLA e grava; devolve {sucesso, mensagem, dados} com status_sla e atraso.
- `vigilante_historico_hoje` (GET): lista até 20 leituras do dia do colaborador resolvido pela sessão.

## Validações e privacidade
- QR de outro tenant → HTTP 403 sem expor dados do ponto.
- QR inexistente/inativo → HTTP 404.
- Rota não programada ou fora da janela → HTTP 409.
- Vigilante não vinculado → HTTP 403 (ou escolha opaca apenas quando for caso excepcional).
- Duplicidade de registro → HTTP 409 por índice único.
- GPS é opcional; se fornecido, é validado quanto a formato/intervalos; coordenadas não são escritas em logs operacionais.
- Não logar senhas, tokens, CPF ou QR em logs de auditoria/regulares.

## Implementação, testes e checklist pós-deploy (resumo integrado)
- Criar/extrair `api/helpers/ronda_helper.php` e adaptá-lo para ambos os endpoints.
- Adicionar ações autenticadas em `api/api_colaborador_mobile.php`: `vigilante_qr_detalhe`, `vigilante_registrar_leitura`, `vigilante_historico_hoje`.
- Reusar `api/api_rondas_vigilante.php` para administração (Rotas/Pontos/QR/Relatórios) e garantir que ele também `require_once` do helper.
- No app Flutter: adicionar menu "Vigilante" (8º item), reaproveitar `/employee/scan`, criar tela de confirmação e histórico.
- Testes a executar antes do deploy: `php -l` em arquivos PHP alterados, testes PHP unitários do helper, `dart analyze` e `flutter test` nos arquivos Flutter alterados.

Checklist pós-deploy técnico:
1. Aplicar `sql/migration_rondas_vigilante_mysql57.sql` se necessário.
2. Enviar `api/helpers/ronda_helper.php`, `api/api_rondas_vigilante.php` e `api/api_colaborador_mobile.php` ao servidor no mesmo caminho.
3. Garantir que o usuário do Portal do Colaborador e o registro em `rh_colaboradores` tenham o mesmo e-mail e pertençam ao mesmo tenant.
4. Vincular o colaborador ativo à rota em `ronda_vigilantes`.
5. No app, acessar Vigilante, ler um QR ativo na janela e confirmar registro.
6. Repetir a mesma leitura no mesmo ciclo e confirmar retorno HTTP 409 de duplicidade.
7. Conferir dashboard/relatório administrativo para o registro.

## Fontes locais e referências de implementação
- `api/api_rondas_vigilante.php` (backend do painel administrativo + lógica de leitura QR existente)
- `frontend/pages/rondas_vigilante.html`, `frontend/js/pages/rondas_vigilante.js` (painel administrativo web)
- `api/api_colaborador_mobile.php` (Portal do Colaborador mobile — login, sessão Bearer, dashboard, chamados; novas ações aqui)
- `api/api_rh_colaboradores.php` (confirma coluna `email` em `rh_colaboradores`)
- `lib/presentation/screens/employee/employee_dashboard_screen.dart` (grade de menus do Portal do Colaborador)
- `lib/presentation/screens/employee/employee_qr_scanner_screen.dart` (scanner de câmera já pronto)
- `lib/core/network/employee_api_client.dart`, `lib/core/constants/app_constants.dart`

---

Este documento reúne a investigação técnica inicial (gaps e recomendações) com as decisões e implementações de arquitetura trazidas pelo branch de integração, priorizando segurança de identidade, reutilização de lógica compartilhada e não expor tenant/IDs pelo cliente.
