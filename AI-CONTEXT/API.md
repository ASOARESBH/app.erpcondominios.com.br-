# Mapa de APIs

As APIs estão localizadas na pasta `/api/` e respondem primariamente em JSON.

## 1. Estrutura de Chamada
**Endpoint Padrão**: `GET/POST /api/api_nome_modulo.php?action=nome_da_acao`

## 2. Padrão de Resposta
Toda API deve retornar obrigatoriamente a estrutura:
```json
{
    "sucesso": true|false,
    "mensagem": "Texto descritivo",
    "dados": { ... } // Opcional
}
```

## 3. Autenticação nas APIs
O arquivo `auth_helper.php` deve ser incluído no topo.
```php
require_once 'config.php';
require_once 'auth_helper.php';
verificarAutenticacao(true, 'operador'); // true = exige login, 'operador' = nivel minimo
```

## 4. Prevenção de Erros de Output
Muitas APIs usam o padrão `ob_start()` no início e `ob_end_clean()` antes de enviar o JSON, para evitar que warnings do PHP quebrem o parse do JSON no frontend.

## 5. GED — Portal do Morador

O endpoint `api/api_portal_documentos.php` é o consumidor autenticado das tabelas GED. O token Bearer determina o morador; o cliente nunca informa `tenant_id`, `morador_id` ou `unidade_id`.

| Ação | Parâmetros | Uso correto |
|---|---|---|
| `pastas_listar` | Nenhum | Navegação por pastas com contagem de itens visíveis |
| `documentos_listar` | `pasta_id` obrigatório, `tipo` opcional | Lista de uma pasta específica; `pasta_id=0` representa itens sem pasta |
| `buscar` | `q`, `tipo`, `pagina` | Lista plana e paginada entre todas as pastas; é a ação usada pelo aplicativo móvel |
| `visualizar` / `download` | `id` | Revalida a visibilidade do documento antes de transmitir arquivo ou link externo |

> A resposta de erro de uma API (`sucesso: false`) deve ser apresentada explicitamente pelo cliente, inclusive se o cliente HTTP optar por não transformar HTTP 400 em exceção.


## 6. Controle de Acesso — Eventos automáticos ControlID

Os endpoints do diretório `api/controlid/` não recebem sessão PHP, pois são acionados diretamente pela catraca. Os modos `new_user_identified`, `new_card`, `new_qrcode`, `new_uhf_tag`, `notifications/dao` e `result` convergem para `push_registrar_acesso_erp()` em `api/controlid/_helper.php`.

Depois de inserir um acesso liberado em `registros_acesso`, o helper resolve `tenant_id` e unidade a partir do morador vinculado ao veículo e chama `controle_acesso_criar_notificacao_registro()`. A chamada é complementar, cercada por `try/catch` e não altera a resposta física da catraca. O evento persistido usa `tipo=acesso_entrada`, `registro_acesso_id` e rota `/home/notifications`.

A API nunca aceita `tenant_id` do payload ControlID. Se a inserção não gerar identificador positivo, o helper registra `registro_sem_id_valido` e não cria um evento idempotente com chave insegura.


## 7. Portal do Colaborador — Vigilante/Rondas Mobile

As ações abaixo pertencem a `api/api_colaborador_mobile.php` e exigem `Authorization: Bearer <colaborador_token>`. O token determina `tenant_id`, usuário e e-mail; as ações não aceitam `tenant_id` nem `colaborador_id` brutos enviados pelo cliente.

| Ação | Método | Entrada | Regras e retorno |
|---|---|---|---|
| `vigilante_qr_detalhe` | GET | `token` QR hexadecimal de 64 caracteres | Localiza ponto e rota ativos, bloqueia QR de outro tenant com HTTP 403 e retorna ponto, rota e instruções sem expor `token_qr`. Resolve o colaborador pelo e-mail; em divergência de cadastro entrega somente opções opacas e temporárias dos vigilantes vinculados à rota. |
| `vigilante_registrar_leitura` | POST | `token`, `opcao_vigilante` opaca quando solicitada; `latitude`, `longitude`, `precisao_metros` opcionais | Revalida tenant, vínculo à rota, dia ativo, janela/SLA e duplicidade. Grava em `ronda_registros`, audita em `ronda_auditoria` e retorna status do SLA, atraso, ponto e rota. |
| `vigilante_historico_hoje` | GET | Nenhuma | Resolve unicamente o colaborador pelo e-mail da sessão e retorna até 20 leituras dele no dia corrente. |

A regra compartilhada de dias, ciclo e SLA está em `api/helpers/ronda_helper.php` e é usada também por `api/api_rondas_vigilante.php`. A deduplicação usa o ciclo calculado e o índice único de `ronda_registros`; a segunda leitura do mesmo ponto no ciclo retorna HTTP 409.
> O cliente móvel deve apresentar `mensagem` do backend quando `sucesso` for `false`, inclusive em HTTP 4xx, pois `EmployeeApiClient` não transforma status abaixo de 500 em exceção.
