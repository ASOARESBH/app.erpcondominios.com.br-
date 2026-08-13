# Incidente: acessos ControlID sem notificação no Portal do Morador

**Data:** 13 de agosto de 2026  
**Módulo:** Controle de Acesso / ControlID / Portal do Morador  
**Status:** Correção preparada e validada estaticamente; exige implantação e teste físico da catraca.

## Sintoma

A catraca ControlID e o controle administrativo registravam o acesso na tabela `registros_acesso`, mas o morador não recebia item em **Notificações** nem alerta push FCM. As notificações de encomendas continuavam chegando para o mesmo morador e dispositivo.

## Evidências de produção

A auditoria somente leitura para o morador `185`, tenant `1`, unidade **Gleba 133**, confirmou os seguintes fatos.

| Verificação | Resultado | Conclusão |
|---|---|---|
| Colunas `veiculo_id` e `registro_acesso_id` em `notificacoes_morador` | Presentes | Migrações de notificação instaladas |
| Índice `uq_notif_morador_registro_evento` | Presente | Deduplicação do evento por acesso disponível |
| Tokens `pwa_fcm_tokens` | Dois tokens Android ativos para o morador 185 | Dispositivo está registrado |
| Projeto FCM | `fcm_project_id = erp-condominios` | Firebase configurado no tenant |
| Eventos `acesso_entrada` e `acesso_saida` | Nenhuma linha encontrada | Falha ocorre antes do envio FCM |
| Eventos de encomenda | `push_status = enviado` | Transporte FCM funciona para o mesmo morador |

A causa raiz é a ausência de chamada ao helper `controle_acesso_criar_notificacao_registro()` no ponto central do ControlID. Todos os modos automáticos delegavam a gravação ao método `push_registrar_acesso_erp()` em `api/controlid/_helper.php`; ele inseria em `registros_acesso` e retornava sem criar o evento persistente.

## Caminhos automáticos cobertos

Os seguintes endpoints utilizam o helper compartilhado e, por consequência, passam a receber a correção centralizada.

| Origem | Endpoint/chamador | Fonte registrada |
|---|---|---|
| Online Mode Pro | `new_user_identified.php` | `online_pro` |
| Cartão | `new_card.php` | `online_card` |
| QR Code | `new_qrcode.php` | `online_qrcode` |
| TAG UHF | `new_uhf_tag.php` | `online_uhf` |
| Monitor Mode | `notifications/dao.php` | `monitor_dao` |
| Push Mode | `result.php` | `push_result` |

## Correção aplicada

O helper `api/controlid/_helper.php` passou a:

1. Carregar o helper de notificações de Controle de Acesso uma única vez.
2. Resolver `tenant_id` e unidade diretamente do morador vinculado ao veículo, sem aceitar esses valores do equipamento.
3. Gravar `unidade_destino` no registro automático para preservar histórico e consulta do Portal do Morador.
4. Obter `insert_id` após gravar o acesso e invocar `controle_acesso_criar_notificacao_registro()` com tipo **Entrada**.
5. Executar toda a etapa de notificação em `try/catch` não bloqueante. A decisão e a resposta da catraca nunca dependem da persistência ou do FCM.
6. Registrar diagnósticos em `error_log` e em `logs/access_notification.log`, sem expor token, senha ou chave privada.

Quando um registro não devolver ID positivo, a notificação é intencionalmente suprimida e o problema é registrado como `registro_sem_id_valido`. Isso evita associar múltiplos acessos ao identificador legado `0` e criar deduplicação incorreta.

## Validação pós-deploy obrigatória

Depois de substituir o helper no HostGator, deve-se provocar uma entrada real pela catraca para um veículo vinculado ao morador 185. Execute `sql/validacao_controlid_notificacao_pos_deploy.sql` e confirme que:

| Etapa | Resultado esperado |
|---|---|
| `registros_acesso` | Novo registro com ID positivo, unidade Gleba 133 e observação contendo ControlID |
| `notificacoes_morador` | Novo evento `acesso_entrada` com o mesmo `registro_acesso_id` |
| Push | `push_status = enviado` ou motivo técnico auditável em `push_detalhe` |
| Aplicativo | Item visível em Mais → Notificações e banner, caso alertas estejam permitidos |

## Arquivos relacionados

| Arquivo | Responsabilidade |
|---|---|
| `api/controlid/_helper.php` | Ponto central de todos os registros automáticos da catraca |
| `api/helpers/access_control_notification_helper.php` | Persistência multi-tenant e FCM dos eventos de acesso |
| `api/helpers/protocol_notification_helper.php` | Transporte FCM compartilhado |
| `api/api_registros.php` | Caminho manual de referência, mantido sem alteração nesta correção |
| `sql/validacao_controlid_notificacao_pos_deploy.sql` | Auditoria de verificação após deploy |
| `logs/access_notification.log` | Trilhas de diagnóstico de destinatário, token e FCM |

> A auditoria identificou sete registros históricos com `registros_acesso.id = 0`. Eles não são usados como chave para nova notificação ControlID. A correção não altera histórico legado; a normalização de IDs deve ser tratada em migração independente, após backup e auditoria própria.
