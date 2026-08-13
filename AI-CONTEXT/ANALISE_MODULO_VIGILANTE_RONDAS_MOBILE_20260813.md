# Análise — Módulo Vigilante/Rondas Mobile

**Data:** 13 de agosto de 2026

**Escopo:** Portal do Colaborador no aplicativo Flutter e API PHP autenticada.

**Situação:** Implementado localmente, validado por análise estática, testes automatizados e checagem passiva de autenticação no endpoint publicado. O deploy no HostGator ainda depende do envio dos arquivos do backend.

## 1. Objetivo

Disponibilizar ao vigilante o registro de ronda a partir do QR Code fixado em cada ponto, sem duplicar a administração web já existente e sem permitir que o aplicativo escolha livremente o condomínio ou a identidade operacional que será gravada.

O fluxo completo é: painel do colaborador → Vigilante → scanner QR existente → validação do ponto → confirmação → registro → resultado do SLA → atualização do histórico do dia. O registro é gravado diretamente em `ronda_registros`, portanto aparece nos painéis e relatórios administrativos existentes.

## 2. Arquitetura adotada

| Camada | Arquivo | Responsabilidade |
|---|---|---|
| Regra compartilhada | `api/helpers/ronda_helper.php` | Normalização de dias, janela ativa, cálculo de ciclo e SLA. |
| Administração web | `api/api_rondas_vigilante.php` | Continua responsável por rotas, pontos, QR e relatórios administrativos; agora reutiliza o helper. |
| API móvel | `api/api_colaborador_mobile.php` | Autentica Bearer, resolve tenant e vigilante, valida QR, registra e lista histórico. |
| App Flutter | `employee_vigilante_screen.dart` | Reutiliza `/employee/scan`, exibe confirmação, resultado e histórico. |

> O endpoint administrativo público de QR não foi alterado. O aplicativo utiliza exclusivamente as ações autenticadas do Portal do Colaborador.

## 3. Identidade e isolamento multi-tenant

O token Bearer da sessão móvel determina `tenant_id`, `usuario_id`, `sessao_id` e e-mail. A API nunca lê `tenant_id` ou `colaborador_id` do corpo/URL das ações de Vigilante.

A ponte de identidade primária compara `LOWER(usuarios.email)` da sessão com `LOWER(rh_colaboradores.email)` no mesmo `tenant_id`, exigindo colaborador ativo. Se a associação não for única, a API lista apenas os vigilantes ativos vinculados à rota e entrega ao aplicativo uma opção opaca, assinada por HMAC e vinculada a sessão, tenant, rota, colaborador e expiração de cinco minutos. O aplicativo devolve a opção; o servidor valida assinatura e vínculo novamente antes de gravar.

| Verificação | Resultado esperado |
|---|---|
| QR existe, mas pertence a outro tenant | HTTP 403, sem expor ponto, rota ou token. |
| QR inativo ou inexistente | HTTP 404. |
| Rota não programada para o dia | HTTP 409. |
| Registro fora da janela configurada | HTTP 409. |
| Vigilante não vinculado à rota | HTTP 403. |
| Mesmo ponto/ciclo/vigilante repetido | HTTP 409 por índice único. |

## 4. Contrato móvel

| Ação | Método | Entrada permitida | Saída principal |
|---|---|---|---|
| `vigilante_qr_detalhe` | GET | `token` QR hexadecimal de 64 caracteres | Ponto, rota, instruções, vigilante automático ou opções opacas de exceção. |
| `vigilante_registrar_leitura` | POST | `token`, `opcao_vigilante` opaca somente se exigida, GPS opcional | Status do SLA, atraso, ponto e rota. |
| `vigilante_historico_hoje` | GET | Nenhuma | Até 20 leituras do dia do colaborador resolvido pela sessão. |

A resposta de todas as ações permanece `{sucesso, mensagem, dados}`. O cliente verifica explicitamente `sucesso`, pois respostas 4xx chegam pelo `EmployeeApiClient` como respostas normais.

## 5. SLA, deduplicação e privacidade

O ciclo é calculado a partir de `hora_inicio`, `intervalo_minutos`, `repeticoes_por_dia`, `hora_fim` e tolerância. A chave permanece `sha256(tenant_id|rota_id|colaborador_id|YYYYMMDD:indice)` e é gravada em `ronda_registros.ciclo_chave`. O índice único do banco também inclui ponto, preservando a regra vigente de um registro por ponto, vigilante e ciclo.

O GPS é best-effort. Esta versão não pede geolocalização ao aparelho; caso um cliente compatível informe latitude/longitude/precisão, a API aceita apenas valores numéricos dentro de faixas válidas. Coordenadas não são registradas nos logs operacionais. Senhas, tokens, CPF e QR também são excluídos de `cm_log`.

## 6. Validações executadas

| Verificação | Resultado |
|---|---|
| `php -l api/api_colaborador_mobile.php` | Aprovado. |
| `php -l api/api_rondas_vigilante.php` e helper | Aprovado. |
| `php tests/test_ronda_helper.php` | Aprovado: dias, rota ativa, no prazo, atraso e fora da janela. |
| `dart analyze` nos quatro arquivos Flutter alterados | Aprovado, sem problemas. |
| `flutter test` | Aprovado. |
| Varredura estática de entrada sensível | Confirmado: ações novas não leem `tenant_id`/`colaborador_id` diretamente da requisição. |
| GET público sem Bearer no endpoint publicado | Recusado com `Sessão do colaborador não informada.` |

O `flutter analyze` geral não ficou completamente limpo por avisos e informações preexistentes fora do módulo Vigilante; não houve erro novo após a correção de compatibilidade do seletor de vigilante.

## 7. Checklist pós-deploy

1. Aplicar previamente `sql/migration_rondas_vigilante_mysql57.sql` se as tabelas `ronda_*` ainda não existirem.
2. Enviar `api/helpers/ronda_helper.php`, `api/api_rondas_vigilante.php` e `api/api_colaborador_mobile.php` ao mesmo caminho do servidor.
3. Garantir que o usuário do Portal do Colaborador e o registro em `rh_colaboradores` tenham o mesmo e-mail e pertençam ao mesmo tenant.
4. Vincular o colaborador ativo à rota em `ronda_vigilantes`.
5. Abrir o app, acessar Vigilante, ler um QR de ponto ativo na janela e confirmar o registro.
6. Repetir a mesma leitura no mesmo ciclo e confirmar o retorno HTTP 409 com a mensagem de duplicidade.
7. Consultar o dashboard/relatório administrativo de rondas para confirmar a presença do registro.
