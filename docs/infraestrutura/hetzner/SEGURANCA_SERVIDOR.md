# Segurança do servidor — ERP Condomínios

**Status atual:** DRAFT / BLOCKED / NOT_EXECUTED — sem acesso administrativo autenticado, fingerprint confiável validada ou inventário read-only completo e confiável.
**Gate 2:** CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE
**Escopo:** preparação de infraestrutura autorizada na Fase 2.5, condicionada a acesso administrativo autenticado, fingerprint confiável do host validada e inventário read-only completo e confiável; sem publicação externa ou bypass de controle.
**Servidor declarado pelo usuário:** `<hostname privado omitido>` — `<IP privado omitido>`, Helsinki, CPX32, 4 vCPU, 8 GB RAM, 160 GB, Ubuntu 24.04 LTS escolhido mas não verificado. Essas informações são uma declaração de referência e não constituem auditoria do ambiente.

> Não contornar SSH/controle de acesso do provedor ou fingerprint. Escopo e estado: [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md).

## 1. Objetivo

Registrar o estado histórico conhecido sobre autenticação e acesso administrativo, delimitar o que não foi comprovado e estabelecer uma sequência segura para eventual preparação privada e reversível da VM. Qualquer preparação depende cumulativamente de acesso administrativo autenticado, identidade do host validada por canal confiável, inventário read-only completo, preservação dos serviços e dados existentes e caminho de recuperação validado. O Gate 2 permanece **CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE**.

Nenhuma sessão SSH, chamada à API da Hetzner, autenticação na console, instalação, alteração de firewall, alteração de serviços, atualização do sistema ou outra mudança na VM foi realizada. A única verificação nova foi uma conexão TCP à porta 22, sem enviar dados de aplicação; ela não constitui handshake SSH nem prova acesso administrativo.

## 2. Estado e evidências disponíveis

A tabela abaixo separa fatos históricos de lacunas atuais. Uma observação antiga não deve ser tratada como o estado atual da VM.

| Data/hora registrada | Fato ou tentativa | Limite da evidência |
|---|---|---|
| 2026-11-05 — horário não informado no handoff | Não foi confirmada nenhuma sessão SSH autenticada. | Não há evidência de acesso administrativo realizado nesta preparação. |
| 2026-11-05 — horário não informado no handoff | A fingerprint do host não foi validada. | A identidade criptográfica do host permanece não confirmada. |
| 2026-11-05 — horário não informado no handoff | A instalação das chaves públicas do Manus/usuário na VM não foi confirmada. | Não se deve presumir que qualquer chave esteja instalada ou autorizada. Nenhuma chave é reproduzida neste documento. |
| 2026-11-05 — horário não informado no handoff | O `sshd` foi observado como `inactive/disabled` em uma inspeção anterior. | A observação é histórica; o estado atual do serviço não foi verificado. |
| 2026-11-05 — horário não informado no handoff | A tentativa mais recente de console serial expirou. | Não houve confirmação de acesso operacional pela console. |
| 2026-11-06T08:15:33-03:00 | A navegação via My Browser à console Hetzner abriu controle de acesso do provedor; a verificação automática não concluiu e a visualização expirou em seguida. | Não houve autenticação no painel nem acesso à VM; o estado do host não foi obtido. |
| 2026-11-06T22:27:53Z | A chave cliente previamente conhecida foi encontrada no sandbox com permissões restritas; não foi carregada em `ssh-agent` e não havia processo/sessão SSH ativo no momento da verificação. | A chave privada não foi lida nem exibida; sua autorização na conta do servidor e o usuário SSH correspondente continuam sem confirmação. |
| 2026-11-06T18:15:16Z | Uma verificação de alcance TCP `connect_ex` do sandbox Manus à porta 22 foi aceita; nenhuma carga útil foi enviada e o socket foi fechado. | Não houve handshake/banner SSH, leitura de host key, autenticação ou sessão. TCP alcançável não valida `sshd`, usuário, fingerprint nem acesso administrativo. |
| 2026-11-06T22:23:36Z | My Browser navegou para os detalhes do servidor, mas permaneceu na página automática Security Check; a visualização posterior expirou. | Nenhuma autenticação ou informação da VM foi obtida; o controle do provedor não foi contornado. |
| 2026-11-06T08:18:02-03:00 — registro desta preparação | Este documento foi elaborado sem acesso à VM. | Não é evidência de estado do servidor, firewall, sistema operacional, usuários, discos ou serviços. |

### 2.1 O que permanece desconhecido

Não foram verificados nesta tarefa:

- fingerprint atual do host por canal independente;
- existência, instalação ou validade de chaves de acesso;
- estado atual do SSH/`sshd`, firewall da Hetzner ou firewall local;
- sistema operacional efetivamente instalado e versões dos pacotes;
- usuários, grupos, política de `sudo` e configuração efetiva do SSH;
- recursos, discos, serviços, logs e exposição de portas;
- aplicação, PHP, Nginx, banco de dados ou qualquer componente de deploy.

O firewall atual não foi verificado. O Gate 2 permanece **CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE**.

## 3. Decisões de segurança e limites

1. **Não aceitar identidade de host não validada.** Nenhuma sessão administrativa ou preparação deve prosseguir enquanto a fingerprint não for obtida e comparada por canal independente e confiável.
2. **Não usar bypass de autenticação ou de verificação.** Falha de console, controle de acesso do provedor, time-out ou ausência de SSH não justifica contornar controles, aceitar fingerprint sem validação ou inserir credenciais/chaves sem autorização.
3. **Gate de entrada obrigatório.** A preparação privada/reversível somente pode ser considerada depois de acesso autenticado, fingerprint validada e inventário read-only completo, com baseline registrada e caminho de recuperação disponível.
4. **Preservar serviços e dados existentes.** Não substituir, remover, importar, publicar ou alterar dados e serviços preexistentes. Parar imediatamente diante de conflito, indício de comprometimento, serviço desconhecido afetado ou impossibilidade de controlar o autostart/listener não intencional; PostgreSQL local-only somente pode iniciar/habilitar após os gates específicos da decisão 9.
5. **Hardening autorizado, sem contorno.** Qualquer hardening de SSH, UFW ou firewall local/do provedor somente pode ocorrer após confirmar cumulativamente acesso administrativo autenticado, fingerprint confiável do host por canal independente, inventário read-only completo, segunda sessão administrativa independente funcional e recuperação validada. Somente então estão autorizados usuário nominal não-root/sudo mínimo, chaves autorizadas, configuração restritiva de SSH, UFW/Fail2ban e updates necessários. Não abrir SSH globalmente nem alterar o firewall do provedor de forma ampla; diante de risco de perder acesso, parar.
6. **Privilégio mínimo.** Usar administrador nominal não-root e `sudo` mínimo; validar uma segunda sessão e recuperação antes de desabilitar root por SSH. Não armazenar senhas/chaves no Git/chat/logs; não permitir login de root por senha público nem SSH global.
7. **Não expor serviços.** São proibidos listener ou porta pública, DNS, testes remotos, deploy, publicação e servir a aplicação. A exposição não deve ser inferida nem habilitada pela existência da VM.
8. **Nginx/PHP-FPM.** Instalar/configurar após os gates; testes de sintaxe e fixture sintética podem operar somente em loopback/socket Unix. Não servir o ERP externamente, ativar DNS ou expor o checkout.
9. **PostgreSQL local vazio e isolado.** O banco `erp_condominios` deve permanecer vazio, sem schema ou objetos. A role `erp_app` deve ter somente o atributo `LOGIN` e o privilégio `CONNECT` nesse banco, sem `TEMPORARY` direto nem qualquer outro grant; `PUBLIC CONNECT` e `PUBLIC TEMPORARY` devem estar revogados. A role não pode ter memberships, `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION` ou `BYPASSRLS`, ser proprietária do banco, schema ou objetos, nem receber `CREATE`, `USAGE` ou privilégios DML em schema/objetos. Depois de comprovados bind exclusivo em loopback, `pg_hba` seguro, firewall seguro com regra explícita de `deny`/bloqueio de TCP/5432 fora de loopback e ausência de listener público/conflito, PostgreSQL pode ser iniciado e habilitado e permanecer ativo somente para uso local; esse firewall/deny de 5432 é pré-condição tanto para `start` quanto para `enable`.
10. **Separar o teste PDO do ERP.** O teste PDO, se autorizado pelos gates, deve ser uma verificação independente de conectividade local ao banco vazio; não pode configurar, conectar ou testar funcionalmente o ERP.
11. **Não misturar segurança com migração.** Permanecem proibidos migração de dados, schema/migrations, conexão do ERP, alteração do MySQL/HostGator, acesso a dados ou uploads preexistentes, backup externo, DNS/cutover e publicação.

## 4. Preparação autorizada condicionada a gates

O conteúdo desta seção descreve a preparação autorizada, mas não é registro de execução nem autorização para contornar o Gate 2. Nenhum item foi executado. A execução exige acesso administrativo autenticado e autorizado, fingerprint confiável validada, inventário read-only completo e confiável, segunda sessão administrativa independente e recuperação validada.

### 4.1 Pré-condições, inventário e parada segura

- Confirmar formalmente o servidor, o responsável, a janela, a origem administrativa autorizada e um canal de recuperação independente.
- Obter e comparar a fingerprint por canal independente, sem aceitar automaticamente a chave apresentada pelo cliente; repetir a conferência por segunda pessoa, sessão ou canal independente.
- Completar inventário read-only do sistema, usuários, permissões efetivas, discos, serviços, processos, listeners locais, configurações e logs relevantes, sem alterar estado.
- Antes de qualquer hardening de SSH, UFW ou firewall, confirmar cumulativamente acesso administrativo autenticado, fingerprint confiável obtida por canal independente, inventário read-only completo, segunda sessão administrativa independente funcionando e recuperação validada.
- Registrar baseline, estado anterior, escopo, método de rollback e evidências sem inserir credenciais, tokens, chaves privadas, dados de moradores ou outros segredos.
- Antes de qualquer preparação, confirmar que alterações não afetarão serviços/dados existentes nem criarão exposição pública. Serviço local e loopback para testes sintéticos são permitidos. Se houver conflito/comprometimento, interromper.

### 4.2 Clone privado e verificação de baseline

- Receber o clone completo somente em `source/`, de forma privada e fora do webroot, sem usar `release/` ou `current/` e sem servir a aplicação.
- Verificar e registrar explicitamente a URL do remoto autorizado, o SHA de `origin/main`, o SHA de `HEAD`, o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e o diff desde o baseline (incluindo árvore de trabalho/índice, quando aplicável) antes de qualquer preparação; a verificação deve demonstrar que o `HEAD` é o esperado e que não há alteração não autorizada.
- Manter o clone e quaisquer artefatos de preparação privados, sem publicação, deploy, DNS, cutover, release ou cópia para diretórios de serviço.
- Não acessar, copiar, exportar ou fazer backup do MySQL/HostGator, de dados ou uploads preexistentes; não importar dados, schema ou migrations.

### 4.3 Nginx e PHP-FPM sem publicação externa

- Instalar/configurar Nginx/PHP-FPM após os gates, com baseline e rollback registrados;
- Validar sintaxe; após todos os gates cumulativos, start/reload controlado de Nginx/PHP-FPM e fixture sintética são permitidos exclusivamente em loopback/socket Unix; não servir o ERP externamente nem criar listener público.
- Não prosseguir se a preparação puder iniciar automaticamente, abrir listener ou afetar serviço existente sem uma prevenção verificável e reversível.
- Se houver alteração de configuração autorizada, qualquer snapshot deve abranger somente a configuração alterada e permanecer root-only; não criar backup externo.

### 4.4 PostgreSQL local vazio e teste PDO separado

- Preparar apenas o banco local vazio `erp_condominios`, sem schema, objetos, migrations ou importação.
- Restringir `erp_app` ao atributo `LOGIN` e ao privilégio `CONNECT` somente, sem `TEMPORARY` direto nem qualquer outro grant; revogar `CONNECT` e `TEMPORARY` de `PUBLIC`. A role não pode ter memberships, `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION` ou `BYPASSRLS`, ser proprietária do banco, schema ou objetos, nem receber `CREATE`, `USAGE` ou privilégios DML em schema/objetos.
- Depois de comprovar bind exclusivo em loopback, `pg_hba` seguro, firewall seguro com regra explícita de `deny`/bloqueio de TCP/5432 fora de loopback, ausência de listener público e ausência de conflito, PostgreSQL pode ser iniciado e habilitado e permanecer ativo para uso local; o firewall/deny de 5432 deve estar validado antes de qualquer `start` ou `enable`; validar `SELECT 1` local sem expor o serviço.
- Executar, se autorizado, um teste PDO separado do ERP, limitado à conexão local e ao `SELECT 1` no banco vazio; não alterar configuração/conexão do ERP nem executar teste funcional da aplicação.
- Fazer `pg_dump` e restore técnico somente do banco PostgreSQL vazio, em ambiente isolado/seguro; manter apenas cópia local protegida dos diretórios compartilhados previamente acordados e validar a integridade dessa cópia sem restaurar esses diretórios; manter qualquer snapshot de configuração separado, mínimo e root-only, exclusivamente como controle de rollback. Não fazer backup, restore ou snapshot de dados preexistentes, nem backup externo.

### 4.5 Hardening restritivo após os gates cumulativos

O hardening restritivo de usuário/chaves/sudo, SSH, UFW/Fail2ban ou firewall está incluído na autorização condicional da Fase 2.5, sem autorização adicional, somente após validar cumulativamente acesso administrativo autenticado, fingerprint confiável por canal independente, inventário read-only completo, segunda sessão administrativa independente e recuperação validada. Não contornar o controle do provedor nem executar probes remotos do ERP. Aplicar uma mudança por vez, de forma reversível, e registrar baseline/rollback:

- usar administrador nominal não-root e `sudo` mínimo por allowlist, sem root/senha compartilhados e sem `NOPASSWD` amplo;
- não desativar o acesso existente antes de segunda sessão e rollback comprovados;
- restringir eventual administração à origem autorizada em `/32`, sem `0.0.0.0/0`;
- validar qualquer configuração antes de eventual reload e preservar o canal de recuperação;
- aplicar mudanças futuras de forma reversível, registrando responsável, estado anterior, critério de aceite e rollback;
- não copiar comandos deste documento como autorização atual; nenhum comando operacional é fornecido aqui.

### 4.6 Proibições de migração e publicação

Permanecem proibidos o bypass de acesso, SSH inseguro, alterações de SSH/firewall sem change específico, listener ou porta pública, testes remotos de serviços/aplicação/ERP, DNS, `release/`, `current/`, deploy, publicação, servir a aplicação, start/reload de Nginx/PHP-FPM fora do teste controlado de fixture local após os gates ou qualquer enable desses serviços, conexão/configuração/teste funcional do ERP, acesso/cópia/backup do MySQL/HostGator, dados ou uploads preexistentes, schema/migrations/importação, backup externo, cutover e qualquer claim de ação executada. A única checagem remota nesta atualização foi TCP connect à porta 22; ela não inicia o handshake SSH pelo cliente, não valida a host key e não é evidência de serviço ou acesso autenticado.

## 5. Testes e critérios de aceite

Foi executada somente uma checagem TCP de alcance à porta 22, sem iniciar handshake SSH pelo cliente, ler banner/host key, autenticar ou acessar a VM. Nenhum teste de serviço, PostgreSQL, PDO, Nginx/PHP-FPM, ERP ou aplicação foi executado. Os itens abaixo são critérios para a preparação autorizada e não reabrem nem dispensam o Gate 2, que permanece **CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE**:

- acesso administrativo autenticado, fingerprint confiável comparada por canais independentes, inventário read-only completo, segunda sessão administrativa independente e recuperação validada, sem divergência ou comprometimento;
- URL do remoto autorizado, SHA de `origin/main`, SHA de `HEAD`, baseline e diff do clone completo verificados, com o clone privado exclusivamente em `source/`;
- serviços e dados existentes preservados, sem conflito, e demonstrado o controle de autostart e listener público para cada serviço; Nginx/PHP-FPM somente com start/reload controlado para fixture local;
- nenhum listener/porta pública, DNS, teste remoto, deploy, publicação ou serving de aplicação;
- Nginx/PHP-FPM configurados e sintaticamente validados; start/reload controlado e teste de fixture local, se feitos, sem serving externo do ERP;
- PostgreSQL local vazio `erp_condominios`, sem schema/objetos, com `erp_app` limitado ao atributo `LOGIN` e ao privilégio `CONNECT`, sem `TEMPORARY` direto, qualquer outro grant, memberships, `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION`, `BYPASSRLS`, ownership ou grants `CREATE`/`USAGE`/DML, e `PUBLIC CONNECT`/`PUBLIC TEMPORARY` revogados;
- eventual `SELECT 1` somente local, com bind em loopback, `pg_hba` seguro e firewall seguro com `deny`/bloqueio de TCP/5432 fora de loopback verificados antes de qualquer `start`/`enable`; PostgreSQL pode estar iniciado/habilitado e permanecer local-only, sem listener público;
- eventual teste PDO separado do ERP, sem configuração ou teste funcional do ERP;
- backup e restore técnico limitados ao banco vazio; cópia local protegida limitada aos diretórios compartilhados acordados, com integridade verificada sem restaurar esses diretórios; qualquer snapshot limitado à configuração alterada, separado do backup/restore e root-only;
- qualquer hardening restritivo de SSH/UFW/firewall realizado somente após acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e recuperação validada; sem abertura global;
- evidências registradas sem segredos, dados de moradores, dados/uploads preexistentes ou alegação de execução.

A aprovação futura destes critérios não comprova compatibilidade do ERP nem autoriza migração, produção, DNS/cutover ou publicação. Gate 2 é **CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE**.

## 6. Pendências e bloqueadores

- **Acesso:** não há sessão SSH autenticada confirmada.
- **Identidade do host:** fingerprint ainda não validada por canal independente.
- **Autorização de chaves:** instalação das chaves públicas do Manus/usuário não confirmada e não autorizada nesta tarefa.
- **Serviço de acesso:** `sshd` foi visto `inactive/disabled` somente em inspeção histórica; estado atual desconhecido e sem autorização para ativar ou recarregar.
- **Console:** a tentativa mais recente de console serial expirou; em 2026-11-06, My Browser chegou ao controle de acesso do provedor da console Hetzner, mas expirou antes da autenticação.
- **Rede:** firewall da Hetzner, UFW, listeners e portas efetivamente expostas não foram verificados; não são autorizados testes remotos ou alterações.
- **Sistema:** sistema operacional, usuários, permissões, recursos, discos, serviços e versões instaladas permanecem desconhecidos.
- **Recuperação:** método de rollback, canal de emergência e recuperação ainda precisam ser validados antes de qualquer preparação permitida.
- **Segunda sessão:** segunda sessão administrativa independente ainda não foi validada.
- **Inventário:** o inventário read-only completo ainda não foi obtido; sem ele não há preparação privada/reversível.
- **Clone:** clone completo privado em `source/`, URL do remoto, SHA de `origin/main`, SHA de `HEAD`, baseline e diff ainda não foram recebidos/verificados.
- **Serviços e banco:** não foi preparado Nginx/PHP-FPM nem PostgreSQL; não foi executado teste PDO e nenhuma configuração do ERP foi feita.
- **Escopo:** preparação condicionada, banco vazio e demais limites permanecem conforme [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md); nada foi executado porque acesso, fingerprint e inventário não foram validados.
- **Gate 2:** permanece **CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE**; migração, produção e publicação continuam bloqueadas.

Enquanto essas pendências não forem resolvidas por evidência verificável e autorização adequada, a preparação deve permanecer em estado **DRAFT / BLOCKED / NOT_EXECUTED**.
