# Integração ERP CONDOMÍNIOS MONITORING em Dispositivos

## Objetivo

A página `frontend/pages/dispositivos.html`, carregada por `layout-base.html?page=dispositivos`, concentra a ativação e a política do agente Windows do ERP CONDOMÍNIOS MONITORING. O módulo não mistura credenciais de câmeras com o ERP: URLs RTSP e senhas de equipamentos permanecem no computador local.

## Fluxo de ativação

1. O agente Windows é iniciado e o operador entra no painel local.
2. O agente gera um código temporário de pareamento e envia uma solicitação para `api_monitoramento.php?action=solicitar_pareamento`.
3. O operador acessa **Acesso → Dispositivos → ERP CONDOMÍNIOS MONITORING** no tenant correto.
4. No cartão **Habilitar esta máquina**, cola o código do painel local e preenche nome, local, responsável e observação.
5. O ERP chama `api_monitoramento.php?action=habilitar_agente` e gera uma credencial aleatória de máquina.
6. A credencial é exibida uma única vez no modal e deve ser copiada para o painel local. O código de pareamento não é a credencial operacional.
7. O agente faz login com usuário, senha do ERP, `agent_id`, credencial e identidade da instalação. O ERP valida tenant, status, instalação e fingerprint.

## Configuração do tenant

O cartão **Motor e política LPR** grava:

- ativação do módulo;
- retenção em dias, inicialmente 30;
- FastALPR como motor principal;
- Frigate como fallback opt-in;
- backend ONNX (`cpu`, `openvino` ou `directml`);
- confiança mínima;
- janela de deduplicação.

A política é devolvida no heartbeat e aplicada pelo agente sem armazenar URL RTSP no ERP.

## Segurança

Todas as chamadas do navegador usam a sessão PHP com `credentials: include`. A API resolve o tenant a partir da sessão autenticada; nenhum `tenant_id` enviado pela interface é confiado para usuários comuns. A credencial de máquina não é listada, armazenada em claro nem exibida novamente. A revogação invalida as sessões ativas do agente.

A página lista somente metadados seguros dos agentes: nome, instalação truncada, status, versão, heartbeat e ações administrativas. O código de pareamento completo é digitado pelo operador e não é retornado pela listagem.

## Arquivos envolvidos

| Arquivo | Responsabilidade |
|---|---|
| `frontend/pages/dispositivos.html` | Blocos de ativação, configuração e modal da credencial única. |
| `frontend/js/pages/dispositivos.js` | Lifecycle da página e integração com o módulo Monitoring. |
| `frontend/js/pages/dispositivos_monitoring.js` | Comunicação com API, habilitação, revogação, configuração e renderização. |
| `assets/css/pages/dispositivos.css` | Visual responsivo do painel Monitoring. |
| `api/api_monitoramento.php` | Pareamento, habilitação, heartbeat, eventos e configuração. |
| `api/monitoramento_helper.php` | Contexto de agente, sessão bearer e contexto web tenant-safe. |
| `sql/migration_monitoramento_lpr_mysql57.sql` | Tabelas do domínio Monitoring compatíveis com MySQL/MariaDB 5.7. |

## Homologação

Antes de publicar no HostGator, validar em banco de homologação:

1. sessão de usuário vinculada ao tenant correto;
2. solicitação de pareamento criada pelo agente;
3. código válido, inválido e expirado;
4. habilitação única e cópia da credencial;
5. login do agente e heartbeat;
6. revogação e bloqueio da sincronização;
7. salvamento da política LPR e retorno no heartbeat;
8. tentativa de acesso com outro tenant sem vazamento de dados.

A entrega para o HostGator deve ser feita em ZIP, após `php -l`, verificação de `git diff --check` e testes de sessão em ambiente de homologação.
