# Notificações de Encomendas — Desenho Técnico

## Objetivo

Este documento define a implementação de notificações de encomendas para o **ERP Condomínios**. O fluxo cria uma notificação persistente para o proprietário associado ao protocolo sempre que a portaria registra o recebimento de uma mercadoria ou confirma sua entrega. A solução mantém o isolamento por `tenant_id`, funciona dentro da tela de notificações mesmo sem Firebase e, quando o morador autoriza alertas no aparelho e o Firebase está configurado, também exibe uma notificação no display do dispositivo.

| Evento de negócio | Título apresentado | Corpo da notificação | Destinatário |
|---|---|---|---|
| Cadastro de protocolo pendente | **Sua encomenda chegou** | `Descrição da mercadoria: {descricao}` | Morador proprietário do protocolo |
| Registro de entrega | **Mercadoria recebida** | `{descricao}. Recebida por: {nome_recebedor}` | Mesmo morador proprietário do protocolo |

## Componentes

| Componente | Responsabilidade | Comportamento sem Firebase |
|---|---|---|
| `api_protocolos.php` | Detectar a criação e a entrega de um protocolo | Cria o evento persistente e não bloqueia o cadastro ou a entrega caso o push falhe |
| `protocol_notification_helper.php` | Criar o evento, verificar configuração do tenant e enviar FCM quando possível | Registra o estado `nao_configurado`, `sem_token` ou `desativado` |
| `notificacoes_morador` | Histórico individual de eventos do morador | Permite leitura no aplicativo, marcação de lida e rastreabilidade de cada encomenda |
| `api_portal_morador.php` | Disponibilizar `action=notificacoes` e `action=marcar_notificacao_lida` ao aplicativo autenticado | Lista os eventos persistidos por token e tenant |
| Aplicativo Flutter | Mostrar a lista, solicitar permissão e registrar ou desativar o token do dispositivo | A lista segue funcionando; somente o aviso no display fica indisponível |

## Segurança e isolamento multi-tenant

Cada consulta de criação, listagem, leitura e envio de push utiliza simultaneamente `tenant_id` e `morador_id`. O ID do morador é obtido exclusivamente do token Bearer da sessão do Portal do Morador. O protocolo e o morador são conferidos antes da geração do evento, evitando que um operador ou uma sessão de outro condomínio receba ou consulte informações de encomendas indevidas.

> O push é um canal complementar. A fonte oficial de informações é a tabela `notificacoes_morador`. Assim, uma falha temporária do Firebase, a ausência de token ou a recusa de permissão no aparelho não impede que o proprietário veja o aviso dentro do aplicativo.

## Exibição no dispositivo

O aplicativo disponibiliza a opção **"Alertas no dispositivo"** na tela de notificações. Ao habilitá-la, o app solicita a permissão nativa de notificações e registra o token FCM vinculado ao morador e ao tenant. Quando uma encomenda chega ou é entregue, o servidor envia uma mensagem FCM de alta prioridade. Em primeiro plano, o aplicativo converte a mensagem em notificação local; em segundo plano ou fechado, o sistema operacional mostra o alerta nativo.

Ao desabilitar a opção, o token é desativado no servidor. O morador continua vendo os eventos na lista interna, porém não recebe banners, sons ou alertas no display.

## Tratamento de falhas

O cadastro do protocolo e o registro da entrega permanecem transacionais do ponto de vista do processo de portaria: o evento interno é gravado e o envio push é tratado como operação secundária. O resultado é gravado no próprio evento para auditoria. Portanto, a falha de rede, token inexistente ou Firebase não configurado não pode impedir a operação principal do ERP.

## Pré-requisitos para push real

A exibição em segundo plano exige a configuração do Firebase já prevista no projeto: `google-services.json` no Android, `GoogleService-Info.plist` no iOS, `config/firebase/service-account.json` no servidor e `fcm_project_id` na configuração do tenant. Sem esses arquivos, o aplicativo continua compilando, a lista de notificações funciona e o sistema informa o estado de configuração no log técnico.

## Validação prevista

| Cenário | Resultado esperado |
|---|---|
| Portaria cria protocolo com descrição | Evento persistido: **Sua encomenda chegou** com a descrição informada |
| Dispositivo com alertas habilitados e token válido | Banner/alerta exibido pelo Android ou iOS |
| Dispositivo sem permissão ou Firebase não configurado | Evento disponível na lista interna; protocolo criado sem erro |
| Portaria registra entrega | Novo evento: **Mercadoria recebida** com descrição e nome de quem retirou |
| Morador abre a notificação | Evento marcado como lido e contador atualizado |
| Tenant diferente ou token inválido | Evento não é listado nem enviado para outro condomínio |

---

**Autor:** Manus AI  
**Data:** 12 de agosto de 2026
