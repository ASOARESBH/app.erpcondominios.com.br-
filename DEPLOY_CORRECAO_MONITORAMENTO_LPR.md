# Deploy — Correção de Monitoramento Local e LPR

## Objetivo

Este pacote corrige o pareamento de máquinas Windows no módulo **Dispositivos → Monitoramento local e LPR**. A correção remove a dependência visual de um agente já vinculado ao tenant, mantém o pareamento protegido pelo código temporário e melhora os diagnósticos apresentados ao gestor.

## Arquivos do pacote

| Caminho | Finalidade |
|---|---|
| `api/api_monitoramento.php` | Localiza a solicitação pendente pelo código, associa a máquina ao tenant ativo e protege o tenant contra parâmetros externos. |
| `frontend/js/pages/dispositivos.js` | Carrega o controlador Monitoring junto ao módulo Dispositivos. |
| `frontend/js/pages/dispositivos_monitoring.js` | Valida o código, orienta o gestor, abre o modal da credencial e atualiza a lista de máquinas. |
| `frontend/pages/dispositivos.html` | Contém a interface de Monitoring e os atributos de preenchimento seguro dos dispositivos. |
| `assets/css/pages/dispositivos.css` | Contém os estilos da interface Monitoring publicada. |
| `frontend/js/session-manager-core.js` | Trata abortos de verificação como condição transitória, sem registrar erro crítico ou disparar logout. |

## Aplicação no HostGator

Envie o ZIP para a raiz do ERP e extraia preservando as pastas internas. Substitua os arquivos existentes. Esta correção não requer migration, pois utiliza as tabelas `monitoramento_agentes`, `monitoramento_sessoes`, `monitoramento_cameras` e `monitoramento_configuracoes` já existentes no módulo.

Depois da extração, atualize o navegador com **Ctrl+F5**. Caso haja cache de opcode no servidor, aguarde alguns segundos e recarregue novamente.

## Fluxo de validação

| Etapa | Resultado esperado |
|---|---|
| 1. No Windows, abrir o painel local Monitoring e solicitar pareamento | O painel gera e envia um código temporário no formato `ABCD-EFGH`. |
| 2. No ERP, abrir Dispositivos → Monitoramento local e LPR | A lista pode continuar em `0 agentes` enquanto a máquina ainda não foi habilitada; isso é esperado. |
| 3. Colar o código e informar nome/local/responsável | O ERP localiza a solicitação pendente pelo código, sem expor agentes de outros tenants. |
| 4. Habilitar a máquina | O ERP associa a máquina ao tenant da sessão e exibe a credencial única. |
| 5. Copiar a credencial no painel Windows | O agente faz login, envia heartbeat e passa a aparecer em Máquinas do Tenant. |

> O código de pareamento não é a credencial operacional. Ele expira em 30 minutos e só deve ser usado para habilitar a instalação que o gerou.

## Diagnóstico esperado

Se o painel Windows ainda não enviou a solicitação, o ERP exibirá: **“Não há solicitação pendente para este código”**. Nesse caso, gere ou envie um novo código pelo painel local. O sistema não cria automaticamente um agente a partir de um código digitado sem a solicitação e a identificação de hardware correspondentes.

## Segurança e isolamento

O tenant da máquina é obtido somente do contexto operacional autenticado. Nenhum parâmetro de navegador pode definir o tenant da ativação. A lista do gestor mostra apenas agentes já vinculados ao condomínio ativo; solicitações ainda não vinculadas são localizadas unicamente após a validação do código temporário.

## Recuperação de credencial de máquina já ativa

Caso a máquina tenha sido habilitada antes da exibição correta do modal, não é necessário revogar ou cadastrar outra instalação. Na tabela **Máquinas do Tenant**, use a ação **Nova credencial** da máquina ativa.

A confirmação da ação gera um novo segredo operacional, exibe-o uma única vez no modal e encerra as sessões locais anteriores daquela máquina. Copie o novo valor para o campo **Credencial da máquina** do painel local Windows e execute novamente **Entrar e ativar sincronização**.

> A rotação não altera o `install_id`, o vínculo ao tenant, as câmeras ou o histórico de eventos. Ela invalida somente a credencial anterior e as sessões emitidas com ela.
