# Incidente: veículo cadastrado sem notificação ao morador

**Data:** 13 de agosto de 2026  
**Módulo:** Veículos / Controle de Acesso / Portal do Morador  
**Status:** Correção pronta para implantação.

## Evidência e causa raiz

A API administrativa `api/api_veiculos.php` já chamava `controle_acesso_criar_notificacao_veiculo()` depois de inserir o veículo. O cadastro não falhava porque a chamada é não bloqueante. Entretanto, a auditoria de produção mostrou que `notificacoes_morador.protocolo_id` estava definido como `NOT NULL`, embora a notificação de veículo grave `protocolo_id = NULL`.

Assim, a inserção do evento `veiculo_cadastrado` falhava no banco antes do FCM. O dispositivo e o Firebase não eram a causa: os tokens Android do morador 185 estavam ativos e os eventos de encomenda para o mesmo morador exibiam `push_status = enviado`.

| Camada | Resultado |
|---|---|
| Cadastro do veículo | Executado antes da etapa de notificação |
| `veiculo_id` em `notificacoes_morador` | Existente |
| `registro_acesso_id` em `notificacoes_morador` | Existente |
| `protocolo_id` | Não aceitava `NULL`, incompatível com veículos e acessos |
| Evento `veiculo_cadastrado` | Não persistido |
| Token/Firebase | Funcionante para o morador auditado |

## Correção

A migração `sql/migration_notificacoes_veiculos_acessos_v2.sql` torna `protocolo_id` anulável, preservando notificações de protocolos existentes e permitindo eventos de veículo ou acesso sem relação com protocolo. Ela também cria, se ausentes, as colunas e os índices de deduplicação de veículo e acesso de maneira compatível com MariaDB 5.7.

O helper `access_control_notification_helper.php` agora valida explicitamente se `protocolo_id` aceita `NULL`. Quando a migração ainda não foi aplicada, devolve `migracao_pendente` de forma segura, registra auditoria e não bloqueia o cadastro do veículo nem o acesso físico.

## Teste obrigatório

Após importar a migração e substituir o helper, cadastre um veículo novo para uma unidade com morador e token FCM ativo. A resposta de `api_veiculos.php` deve conter `notificacao_veiculo` com `sucesso=true`; `notificacoes_morador` deve conter `tipo=veiculo_cadastrado`; o aplicativo deve mostrar o cartão de Controle de Acesso e, com alertas autorizados, o push no dispositivo.
