# Checklist de Homologação — Ambiente Hetzner

**Status do documento:** `BLOCKED / NOT EXECUTED`
**Execução nesta sessão:** checagem de rede TCP/22 executada; SSH/autenticação não executados
**Branch/worktree:** `infra/hetzner-environment-preparation`
**Baseline de código consultado:** `f60739713e6de3f2473b79ecd14a70fd700e5462`
**Escopo desta autorização:** preparação de infraestrutura após os gates técnicos: atualizações necessárias, hardening restritivo, instalação/configuração de serviços, PostgreSQL vazio com role mínima, clone privado completo, fixture sintética de infraestrutura somente em loopback/socket Unix, backup do banco vazio e restore exclusivamente desse PostgreSQL vazio, além de cópia local protegida dos diretórios compartilhados formalmente acordados, com checksum e preservação da origem, sem restaurar esses arquivos. Sem migração, linhas ou dados MySQL, deploy público, DNS, cutover ou QA/fixture funcional do ERP.

> A única checagem de rede direta à VM nesta sessão foi um teste TCP de alcance à porta 22, sem enviar dados de aplicação; também houve navegação passiva para a console, bloqueada pelo Security Check do provedor. Não houve handshake SSH, leitura de host key, autenticação, sessão na VM, instalação, alteração, clone, operação de banco/backup ou teste de serviço/aplicação. A checagem TCP não comprova SSH funcional, identidade do host nem acesso administrativo; nenhuma linha é evidência de execução na VM. A autorização cobre infraestrutura, não a homologação funcional do ERP.

> O Gate 2 é **CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE** para migração ou integração. Ele não pode ser aberto por inferência nem confundido com a preparação de infraestrutura. Itens proibidos não podem receber exceção via `WAIVED`; ver [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md).

## 1. Objetivo e regras de uso

Este documento reúne critérios da preparação autorizada: inventário, hardening restritivo, instalação/configuração de serviços, PostgreSQL vazio, clone privado, fixture/teste sintético de infraestrutura local, backup do banco vazio e restore exclusivamente do PostgreSQL vazio, além de cópia local protegida dos diretórios compartilhados acordados, com checksum e preservação da origem, sem restaurar esses arquivos. Deploy/serving público, DNS, cutover, migração, schema definitivo e QA ou fixture funcional do ERP permanecem bloqueados.

A aplicação é documentada como SPA Vanilla JS + PHP 8.x, com banco atual MySQL no HostGator. A compatibilidade efetiva com PHP 8.3 e PostgreSQL não está comprovada. Portanto, qualquer item relacionado à troca de banco, versão de runtime ou comportamento de produção deve permanecer bloqueado até que seja validado em ambiente autorizado e descartável, com evidência anexada.

### Regras obrigatórias

- Executar somente ações autorizadas na Fase 2.5 após os gates; essa autorização não equivale a autorização de deploy público, migração de dados, QA funcional ou Gate 2.
- Diferenciar o ciclo de vida dos serviços: após os gates técnicos, bind exclusivo em loopback, `pg_hba` seguro e ausência de conflito comprovados, o PostgreSQL pode ser iniciado e habilitado para permanecer ativo somente localmente; Nginx/PHP-FPM não podem ser habilitados para autostart e só podem ser iniciados/recarregados de forma controlada e temporária para fixture sintética em loopback/socket Unix, sendo encerrados ao final.
- Não registrar segredos, chaves privadas, tokens, cookies, senhas, dados de moradores ou dumps com dados pessoais.
- Sanitizar evidências: registrar comandos sem segredos, versões, códigos de retorno, hashes, nomes de arquivos e resultados objetivos; remover valores sensíveis.
- Não considerar uma VM declarada, uma versão esperada ou uma intenção de configuração como estado auditado.
- Não considerar `ping`, abertura de porta ou resposta HTTP isolada como prova de que o ERP está funcional.
- Não considerar a aplicação funcional apenas porque Nginx, PHP-FPM ou PostgreSQL responde: o sistema depende de dados, schema, permissões, sessão e integrações coerentes.
- O `tenant_id` deve permanecer derivado da sessão/contexto autorizado no backend; nunca deve ser aceito como atalho de isolamento a partir de payload, URL ou evidência parcial.
- Cada item deve ter critério, evidência e owner preenchidos antes de mudar seu status para `PASS`. `WAIVED` não se aplica a proibições, Gate 2, controles de segurança, privacidade ou integridade.
- Fixture sintética de infraestrutura, sem fluxo, schema ou dados do ERP, é permitida somente localmente em `127.0.0.1` ou socket Unix, após os gates técnicos; nenhum teste ou fixture funcional do ERP, API, HTTP público, RBAC/tenant, browser/login, upload ou fluxo pode receber `PASS` neste escopo e permanece `BLOCKED / NOT EXECUTED` como backlog futuro.
- Na VM, o clone completo e privado deve vir do remoto oficial, ficar em `source/` fora do webroot, manter `origin/main` atual e ter `HEAD`, SHA, baseline e diferenças desde o baseline verificados; não criar release/current nem publicar. Esta documentação pode ser commitada/pushada na branch autorizada, sem merge em main.

### Delimitação operacional e pré-condição cumulativa

A preparação privada/reversível só pode começar depois de, cumulativamente, (a) acesso autenticado e fingerprint da VM validados por canal confiável, (b) inventário somente leitura completo e confiável, (c) ausência de conflito, comprometimento ou indício de alteração indevida, (d) janela registrada quando necessária, (e) plano de rollback e snapshot de configuração definidos, e (f) segredo provisionado por canal seguro quando o teste técnico local exigir. A ausência de qualquer item mantém a preparação bloqueada.

Interromper se houver divergência de identidade, conflito/comprometimento ou exposição pública. Hardening restritivo de SSH/firewall e qualquer mudança controlada exigem todos os gates técnicos cumulativos, uma segunda sessão administrativa independente e um mecanismo de recuperação validados; essa exigência também está registrada na [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md). O PostgreSQL pode permanecer ativo somente após comprovar o modo local-only; Nginx/PHP-FPM não podem ter autostart e seu start/reload deve ser temporário e controlado, exclusivamente para fixture de infraestrutura em loopback/socket Unix. Não servir ERP externamente, consultar/copiar linhas ou dados MySQL, migrar dados ou executar QA/fixture funcional.

> Estão fora do escopo: dados/linhas MySQL/HostGator, migrations e schema definitivo, conexão funcional ou QA do ERP, serving externo, DNS, cutover, root por senha público, SSH global e qualquer bypass de autenticação, fingerprint, inventário ou Gate 2. Serviços e arquivos existentes devem ser preservados; nenhuma ação foi executada nesta sessão.

## 2. Estado conhecido, evidências e decisões vigentes

| Item | Estado nesta sessão | Evidência disponível | Decisão/implicação | Owner |
|---|---|---|---|---|
| Acesso à VM | `BLOCKED` / `NOT EXECUTED` | Em 2026-11-06T18:15:16Z, uma conexão TCP `connect_ex` do sandbox Manus à porta 22 foi aceita; não houve handshake/banner SSH, leitura de host key, autenticação ou sessão. A chave cliente conhecida está presente localmente com modo restrito, mas não está carregada em `ssh-agent`; processo/sessão SSH ativo não encontrado. Fingerprint não validada, nenhum registro de host key confiável no `known_hosts` do sandbox e instalação da chave no servidor/usuário autorizado não confirmados | TCP aberto e chave local não provam acesso. Não iniciar handshake/autenticação até validar fingerprint por canal independente e confirmar usuário autorizado e instalação da chave pública na conta correspondente | Infra/Operação |
| Console | `BLOCKED` / `NOT EXECUTED` | Em 2026-11-06, navegação no My Browser para a Hetzner permaneceu no Security Check automático; verificações passivas expiraram antes de abrir a console. Nenhuma autenticação ou informação da VM foi obtida | Acesso/fingerprint seguem sem validação; não contornar o controle do provedor | Infra/Operação |
| VM alvo | `BLOCKED` / `NOT EXECUTED` | Declaração do usuário: `<hostname privado omitido>`, `<IP privado omitido>`, Helsinki, CPX32, 4 vCPU, 8 GB RAM, 160 GB; Ubuntu 24.04 LTS escolhido como imagem, sem verificação de instalação | São parâmetros declarados, não fatos auditados por falta de acesso autenticado, fingerprint e inventário | Infra/Operação |
| OS, firewall, usuários, discos e serviços | `BLOCKED` / `NOT EXECUTED` | Estado atual desconhecido | Exige inventário somente leitura autorizado; acesso, fingerprint e inventário confiáveis ainda não disponíveis | Infra/Operação |
| ERP e runtime | `BLOCKED` / `NOT EXECUTED` | ERP documentado como SPA Vanilla JS + PHP 8.x; PHP 8.3 foi solicitado como alvo preferido, PostgreSQL como destino; baseline usa MySQL/mysqli | Comparar PHP 8.3/8.4/8.5, validar runtime/extensões e compatibilidade com PostgreSQL após acesso e inventário confiáveis | Backend/Infra |
| PostgreSQL | `BLOCKED` / `NOT EXECUTED` | PostgreSQL é destino definido; serviço/banco vazio, role mínima e teste técnico local autorizados em princípio, mas nada foi instalado/testado. Baseline usa `mysqli` e não contém PDO/PgSQL lexicalmente | Pode preparar banco vazio e validar PDO_PGSQL/`SELECT 1` em teste separado somente após os gates; não configurar, conectar ou testar o ERP nesta sessão | Backend/DBA |
| Gate 2 da migração | `CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE` | Catálogo live/reconciliação, compatibilidade, schema, dados e tenants não aprovados | Não abrir nem usar `WAIVED`; não migrar, apontar aplicação ou declarar sucesso | Produto/DBA |
| Backup e restore | `BLOCKED` / `NOT EXECUTED` | Nenhum backup/restore ou cópia desta VM foi executado nesta sessão | Após os gates da Fase 2.5, fazer obrigatoriamente backup e restore isolado exclusivamente do PostgreSQL novo vazio; para cada diretório compartilhado explicitamente acordado, cópia local protegida com checksum/integridade, sem restauração e com preservação da origem; snapshot de configuração é separado e não é backup de produção | DBA/Infra |
| Homologação funcional | `BLOCKED` / `NOT EXECUTED` | Nenhum browser, API autenticada, sessão, upload, fluxo ou fixture executado | Backlog futuro; não declarar release ou funcionalidade sem nova autorização/Gate 2 | QA/Produto |

## 3. Convenções de status e evidência

Use somente os status abaixo, salvo decisão formal registrada na seção de pendências:

- `PENDENTE`: ainda não avaliado ou bloqueado por pré-condição.
- `NOT EXECUTED`: não executado nesta sessão; usar junto de `PENDENTE` para preservar o fato de que não há evidência.
- `PASS`: critério atendido com evidência suficiente, sanitizada e reproduzível.
- `FAIL`: critério não atendido; registrar impacto e ação corretiva.
- `BLOCKED`: não pode ser avaliado por falta de acesso, autorização, dependência ou evidência.
- `WAIVED`: exceção formal somente para critério não proibido, sem violar limites de segurança, privacidade, dados ou gates; nunca aplicável ao Gate 2 ou a uma proibição do escopo.

Cada registro deve identificar, no mínimo, data/hora UTC, executor, comando ou teste (sem segredo), ambiente/release, código de saída ou resultado, caminho do artefato sanitizado e aprovação quando aplicável.

## 4. Fase 0 — Preflight, acesso e autorização

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Critério de saída:** todas as autorizações e pré-condições estão registradas; caso contrário, a fase seguinte permanece bloqueada.
**Owner da fase:** Infra/Operação, com aprovação de Produto e Segurança quando aplicável.

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| PF-01 | Confirmar escopo e janela | Autorização da Fase 2.5 registrada; janela definida quando necessária; expansão de escopo exige nova autorização | Referência da autorização, janela, executor e rollback | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| PF-02 | Confirmar identidade da VM | Identidade, IP, região, projeto e fingerprint são conferidos por canal confiável antes do primeiro SSH | Registro sanitizado da conferência e fingerprint validada | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| PF-03 | Confirmar credencial de acesso | Chave autorizada, usuário nominal, MFA/bastion quando exigidos e método de revogação estão definidos; nenhum segredo é copiado para o documento | Aprovação e teste controlado de autenticação, sem material secreto | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| PF-04 | Definir plano de rollback | Há procedimento reversível para a preparação e snapshot de configuração separado do backup/restore exclusivamente do PostgreSQL vazio; não inclui release/current, DNS, deploy ou cutover nesta autorização | Runbook versionado e aprovação do responsável | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| PF-05 | Confirmar origem do clone privado | O remoto oficial `https://github.com/ASOARESBH/app.erpcondominios.com.br-` está confirmado; o clone é completo, privado, fica em `source/` fora do webroot, preserva o histórico e verifica `origin/main` atual, `HEAD`, SHA, baseline e diferenças desde o baseline; não preparar release/current nem destino de deploy | URL remota oficial, caminho fora do webroot, SHA de `origin/main`/`HEAD`, presença do baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e lista sanitizada de diferenças, sem segredo | `BLOCKED` / `NOT EXECUTED` | Backend/Release |
| PF-06 | Não acessar linhas da origem | Não consultar/exportar/copiar/backup de linhas MySQL/HostGator; coleta metadata-only Fase 2.4 permanece separada | Declaração de escopo e registro de change futuro | `BLOCKED` / `NOT EXECUTED` | DBA/Segurança |
| PF-07 | Confirmar Gates de migração | Gate 2 permanece fechado e não pode ser dispensado por este checklist; catalogação e reconciliação live antecedem eventual proposta de abertura | Registro formal de gates e resultados live | `BLOCKED` / `NOT EXECUTED` | Produto/DBA |
| PF-08 | Preparar observabilidade | Canal para logs, métricas, incidentes e comunicação de rollback está disponível, sem despejar dados pessoais | Links/identificadores de observabilidade e contatos | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| PF-09 | Registrar ponto inicial | Inventário read-only completo; snapshot mínimo de configuração e cópia local protegida somente dos diretórios compartilhados formalmente acordados, com checksum/integridade e preservação da origem; não restaurar esses arquivos | Inventário, paths aprovados, manifesto, checksum da cópia e registro sanitizado de que a origem não foi alterada e não houve restore de diretórios | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| PF-10 | Verificar ausência de comprometimento/conflito | Não há indício de comprometimento, conflito operacional ou alteração indevida; qualquer dúvida interrompe a preparação | Evidência sanitizada da verificação e decisão de parada, se aplicável | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| PF-11 | Snapshot de configuração separado | Antes de qualquer mudança reversível, o snapshot/manifesto de configuração autorizado está definido e não contém segredos; ele não é backup/restore do PostgreSQL vazio | Manifesto sanitizado, referência ao cofre e aprovação | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |

**Bloqueadores atuais da fase:** somente a porta TCP/22 foi alcançável; handshake/banner SSH, autenticação e fingerprint confiável não foram obtidos; a console permaneceu no Security Check automático; inventário somente leitura completo não realizado; ausência de comprometimento, janela, rollback, snapshot de configuração e segredo seguro ainda não demonstrados. Backup/cópia da origem MySQL/HostGator e de dados ou diretórios não acordados não estão autorizados; somente a cópia local protegida dos diretórios compartilhados formalmente acordados poderá ser considerada em execução futura.

## 5. Fase 1 — Host e serviços

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Pré-condição:** PF-01 a PF-03, PF-09 a PF-11 em `PASS`, com acesso/fingerprint validados, inventário somente leitura completo e ausência de comprometimento demonstrada.
**Critério de saída:** host identificado e saudável para a carga proposta, com serviços mínimos inventariados e sem mudança não autorizada.
**Owner da fase:** Infra/Operação.

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| HS-01 | Identidade do sistema | Hostname, distribuição, versão do kernel e arquitetura correspondem ao alvo autorizado; Ubuntu é hipótese até validação | Saída sanitizada de identificação do sistema, data/hora e executor | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| HS-02 | Inventário de capacidade | CPU, RAM, swap, discos, partições, inode e espaço livre são suficientes para app, logs, PostgreSQL e backups | Inventário somente leitura e limiares definidos | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| HS-03 | Saúde de disco e filesystem | Filesystems montados, permissões e sinais de erro são compatíveis com a operação; não há partição crítica sem folga | Saídas sanitizadas de mounts, uso e saúde | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| HS-04 | Data, hora e timezone | Relógio sincronizado e timezone documentado; timestamps da aplicação e auditoria são consistentes | Estado de sincronização e configuração de timezone | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| HS-05 | Usuários e privilégios | Acesso é nominal e mínimo; não há uso operacional de root sem justificativa; contas/chaves não autorizadas são identificadas para tratamento | Inventário sem nomes sensíveis desnecessários e revisão de privilégios | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| HS-06 | Serviços instalados | Serviços presentes e habilitados são listados; nenhum serviço é iniciado, parado ou habilitado sem autorização | Inventário de unidades/processos e decisão para cada serviço | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| HS-07 | Inicialização e reinício | Política de restart e dependências entre Nginx, PHP-FPM e banco está documentada antes de qualquer alteração | Ordem de dependências e runbook | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| HS-08 | Limites operacionais | Limites de arquivos, processos, conexões e memória são adequados ou têm plano aprovado | Valores observados, limiares e justificativa | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| HS-09 | Pacotes e atualizações | Aplicar atualizações de segurança e instalar pacotes necessários após inventário; reboot somente com janela e recuperação confirmadas | Lista de versões, origem e estado antes/depois | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| HS-10 | Estado de sshd | Estado e configuração efetivos são auditados sem presumir que o serviço está ativo; acesso alternativo aprovado se necessário | Evidência sanitizada de configuração/estado | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |

## 6. Fase 2 — Rede e segurança

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Pré-condição:** Fase 1 com identidade, fingerprint e inventário validados.
**Critério de saída:** SSH restrito à origem administrativa; zero `5432` público e sem serving externo do ERP. Hardening restritivo e fixture sintética de infraestrutura são permitidos somente após os gates e apenas em `127.0.0.1`/socket Unix; testes ou fixtures funcionais do ERP permanecem bloqueados.
**Owner da fase:** Infra/Segurança.

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| NS-01 | Firewall de host | Inventariar primeiro; aplicar regras restritivas somente após confirmar origem administrativa, fingerprint, segunda sessão e recuperação; nunca liberar globalmente | Inventário sanitizado, regras efetivas, origem autorizada e plano de rollback, sem segredo | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| NS-02 | Firewall/provedor | Aplicar hardening do firewall/provedor somente após acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente funcional e mecanismo de recuperação validado; então restringir SSH à origem administrativa aprovada, sem abertura global ou portas públicas da aplicação | Matriz sanitizada de regras efetivas do provedor, confirmação do acesso autenticado, fingerprint e inventário read-only completos, teste da segunda sessão independente, recuperação/rollback e evidência de ausência de exposição | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| NS-03 | SSH administrativo | Após validar chave/fingerprint, usuário não-root/sudo, segunda sessão e recuperação, restringir SSH; desabilitar root por senha somente então e sem habilitar acesso global | Configuração efetiva sanitizada, fingerprint validada, teste da segunda sessão e rollback | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| NS-04 | Listeners e portas públicas | Não existe listener público nem porta pública em `80`, `443` ou `5432`; eventual PostgreSQL permanece somente local; não demonstrar prevenção de autostart/listener implica parar | Inventário local autorizado e comparação com matriz de zero portas públicas | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| NS-05 | TLS/HTTPS público | **Backlog futuro bloqueado.** Não testar ou publicar HTTPS nesta preparação | Inspeção TLS em change futuro autorizado | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| NS-06 | CORS e cookies | **Backlog futuro bloqueado.** Não testar origem, credenciais ou cookies do ERP | Headers/testes em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/Segurança |
| NS-07 | Headers HTTP | **Backlog futuro bloqueado.** Não validar headers nem política HTTP da SPA | Resposta HTTP e análise em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/Segurança |
| NS-08 | Diretórios sensíveis | **Backlog futuro bloqueado.** Não servir nem testar `/api/`, `.env`, dumps, logs ou uploads por HTTP | Testes HTTP negativos em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| NS-09 | Uploads e arquivos privados | **Backlog futuro bloqueado.** Não acessar, copiar, publicar ou testar uploads/arquivos preexistentes | Mapeamento e testes apenas em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/Segurança |
| NS-10 | Isolamento de tenant | **Backlog futuro bloqueado.** Não consultar tenant nem criar fixtures funcionais do ERP nesta preparação; fixture de infraestrutura local não testa isolamento | Testes com fixtures funcionais em homologação futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/QA |
| NS-11 | Autenticação negativa | **Backlog futuro bloqueado.** Não testar API, sessão, credencial ou resposta HTTP do ERP | Respostas sanitizadas em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Segurança |
| NS-12 | Injeção | **Backlog futuro bloqueado.** Não executar payloads, consultas ou testes funcionais do ERP | Casos sintéticos em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | Backend/QA |

## 7. Fase 3 — PostgreSQL local e conectividade técnica isolada

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Pré-condição:** pré-condição cumulativa da seção 1 atendida; PostgreSQL local, banco vazio, role `erp_app` com apenas `CONNECT` efetivo e teste técnico local PDO_PGSQL/`SELECT 1` separado podem ocorrer após autenticação segura e segredo fornecido por canal seguro. Antes de qualquer `start`/`enable`, deve ser comprovada a regra efetiva de firewall/`deny5432`, sem permissão pública para `5432`; depois dos gates, o PostgreSQL pode ser iniciado e habilitado para permanecer ativo somente se bind exclusivo em loopback, `pg_hba` seguro, ausência de conflito e modo local-only forem demonstrados. Nginx/PHP-FPM não fazem parte dessa exceção. Testes da aplicação, conexão/configuração funcional, schema, dados, migrations, backup/restore da origem e cutover estão fora da autorização atual; dados/schema dependem do Gate 2 formal.
**Critério de saída desta preparação:** PostgreSQL local ativo, banco vazio, permissões mínimas e conectividade técnica isolada verificados sem usar a aplicação/ERP; isso não é homologação nem aceite funcional.
**Owner da fase:** DBA/Backend, com aprovação de Produto.

> A decisão de destino PostgreSQL foi tomada pelo usuário, mas a existência/disponibilidade do serviço não prova compatibilidade com o ERP. O baseline consultado ainda usa `mysqli` e não tem referência lexical a PDO/PgSQL. A aplicação deve ser considerada **não funcional para fins de homologação** enquanto adapter, schema e dados compatíveis não estiverem implementados/migrados e validados.

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| PG-01 | Decisão de destino | PostgreSQL está definido como destino; MySQL permanece a origem e os limites de cutover estão registrados. Este `PASS` é exclusivamente a decisão de destino: não é gate operacional, de instalação, `start`/`enable`, backup/restore ou migração, não declara compatibilidade nem aprova o Gate 2. | Decisão do usuário registrada neste plano e no escopo da migração; Gate 2 segue `CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE`. | `PASS` | Produto/DBA |
| PG-02 | Instalação e versão | Versão, origem, configuração e ciclo de suporte do PostgreSQL são conhecidos e aprovados | Inventário sanitizado do servidor | `BLOCKED` / `NOT EXECUTED` | DBA/Infra |
| PG-03 | Escuta e ciclo de vida local | Antes de qualquer `start`/`enable`, comprovar a regra efetiva de firewall/`deny5432`, sem permissão pública para `5432`; depois dos gates, o banco deve escutar exclusivamente em loopback/socket Unix e o PostgreSQL pode ser iniciado e habilitado para permanecer ativo somente se bind local-only, `pg_hba` seguro e ausência de conflito forem demonstrados; se qualquer requisito não puder ser comprovado, abortar. Esta exceção não se aplica a Nginx/PHP-FPM, que não podem ter autostart | Configuração efetiva, estado do serviço, bind/listeners, regras de firewall/`deny5432`, teste de portas, evidência de ausência de conflito e validação do bloqueio público de `5432` | `BLOCKED` / `NOT EXECUTED` | DBA/Segurança |
| PG-04 | Role de teste mínima | `erp_app` só pode ter `CONNECT` efetivo no banco vazio, com `LOGIN` somente se segredo seguro for provisionado; revogar de `PUBLIC` `CONNECT` e `TEMPORARY` e verificar a ausência efetiva; não há memberships, ownership do banco/schema/objetos, `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION`, `BYPASSRLS`, `CREATE`, `USAGE` ou `TEMPORARY`, grants de schema/objeto ou qualquer DML para a role | Matriz de atributos administrativos, memberships, ownership e grants efetivos, sem segredo | `BLOCKED` / `NOT EXECUTED` | DBA/Segurança |
| PG-05 | Autenticação local | Método local de autenticação é seguro; conexões remotas são rejeitadas; nenhum segredo é registrado; não abrir listener público | Configuração sanitizada e teste local autorizado | `BLOCKED` / `NOT EXECUTED` | DBA/Segurança |
| PG-06 | Schema do ERP | **Gate 2 fechado.** Não criar nem testar schema, tabelas, constraints, views, funções ou migrations do ERP | Requer catálogo live reconciliado, autorização e mudança futura | `BLOCKED` / `NOT EXECUTED` | DBA/Backend |
| PG-07 | Compatibilidade SQL do ERP | **Gate 2 fechado.** Não executar consultas do ERP; análise/QA de compatibilidade requer fase específica autorizada | Suíte futura e relatório aprovado | `BLOCKED` / `NOT EXECUTED` | Backend/DBA |
| PG-08 | Dados migrados | **Fora da autorização atual.** Nenhum dado-fonte pode ser lido/exportado/copied/importado; reabrir somente em change posterior após Gate 2 | Aprovação e reconciliação formal futura, se autorizada | `BLOCKED` / `NOT EXECUTED` | DBA/Produto |
| PG-09 | Tenant e RBAC | **Gate 2/QA funcional fechado.** Não criar fixtures funcionais, consultar sessão/tenant ou testar permissões do ERP nesta preparação; fixture de infraestrutura local não substitui esses testes | Homologação funcional futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/Segurança |
| PG-10 | Migrations | **Fora da autorização atual.** Não criar, aplicar ou ensaiar migrations; requer Gate 2 e change posterior | Revisão e autorização futura | `BLOCKED` / `NOT EXECUTED` | DBA/Backend |
| PG-11 | Conectividade PHP técnica | Script PHP isolado com PDO_PGSQL conecta localmente ao banco vazio, executa apenas `SELECT 1` e não carrega `api/config.php` nem inicializa a aplicação | Teste local técnico, versão/extensão e evidência sem segredo | `BLOCKED` / `NOT EXECUTED` | Backend/Infra |
| PG-12 | Falha fechada da aplicação | Testes de endpoints/app são QA funcional futura; não executar nesta preparação | Casos e respostas sanitizadas em fase autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/QA |
| PG-13 | Teste do ERP sem dados/schema | **Não executar nesta preparação**; é teste de aplicação e depende de ambiente/autorização próprios | Aprovação de QA e plano de teste futuro | `BLOCKED` / `NOT EXECUTED` | QA/Produto |
| PG-14 | Gate de aplicação | Não liberar conexão ou tráfego sem schema/dados reconciliados e aprovação formal; Gate 2 não pode ser waived | Aprovação formal do Gate 2 e mudança separada | `BLOCKED` / `NOT EXECUTED` | Produto/DBA |

### Requisito futuro, não executável agora: sistema sem dados/schema

**Objetivo futuro:** demonstrar explicitamente que “processo web respondendo” não equivale a “ERP funcional”. Este requisito é do ERP, não da preparação local, e não está autorizado nesta fase. Os itens abaixo são requisitos de um change futuro, não instruções executáveis agora.

1. O change futuro deverá usar ambiente descartável e autorizado, com schema ausente ou banco vazio controlado, nunca dados reais; esta preparação não inicia release nem serve aplicação.
2. O plano futuro deverá limitar endpoints/fluxos a casos mínimos, com sessão de teste não sensível e autorização própria.
3. A evidência futura deverá confirmar ausência de sucesso falso, criação fora do schema, HTML/warnings, stack trace, credenciais ou dados.
4. O contrato futuro deverá definir código/status para dependência ausente, sem transformar indisponibilidade em lista vazia ou autorização implícita.
5. O resultado futuro só poderá ser `PASS` se o comportamento for controlado e a decisão de produto mantiver o sistema como **não funcional** até schema e dados migrados.
6. Qualquer repetição futura com fixture sintética ou migração autorizada exigirá nova autorização, reconciliação e Gate 2 quando aplicável.

**Critério de aceite:** o sistema só pode ser classificado como funcional para homologação quando schema compatível, dados necessários, permissões, migrations e fluxos principais forem comprovados. Sem esses itens, o resultado obrigatório é `BLOCKED`/`FAIL` funcional, ainda que Nginx, PHP-FPM e uma rota HTTP respondam.

## 8. Fase 4 — PHP, extensões e Composer

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Pré-condição:** inventário seguro concluído; qualquer preparação de runtime/configuração é privada, reversível e não publica a aplicação; compatibilidade PHP 8.3 ainda não presumida. A preparação desta fase não inicia Nginx/PHP-FPM; esses serviços permanecem sem autostart e só admitem start/reload temporário e controlado na Fase 5 para fixture local. O ciclo de vida local-only do PostgreSQL segue a Fase 3.
**Critério de saída:** runtime/configuração preparados somente quando autorizados, sem ativar Nginx/PHP-FPM, sem carregar a aplicação e sem teste funcional, API ou publicação.
**Owner da fase:** Backend/Release.

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| PHP-01 | Versão CLI e FPM | Versões CLI e FPM são conhecidas e compatíveis entre si e com a release; não assumir PHP 8.3 instalado | Saída de versão e configuração sanitizada | `BLOCKED` / `NOT EXECUTED` | Backend/Infra |
| PHP-02 | Extensões requeridas | Extensões usadas pelo código e bibliotecas estão presentes, habilitadas e na versão compatível, incluindo driver do banco escolhido | Manifesto de extensões e matriz de uso | `BLOCKED` / `NOT EXECUTED` | Backend |
| PHP-03 | Configuração PHP | `memory_limit`, timeouts, upload, timezone, erros e exposição de detalhes seguem a política; produção não exibe warnings/stack trace | Configuração efetiva e teste controlado | `BLOCKED` / `NOT EXECUTED` | Backend/Segurança |
| PHP-04 | Sessões PHP | **Backlog futuro bloqueado.** Não testar sessão, cookies, PHPSESSID ou comportamento da SPA nesta preparação | Configuração/teste em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/Segurança |
| PHP-05 | Composer lock | `composer.lock` da release é usado quando existente; resolução é determinística e sem atualização silenciosa | Hash do lock, comando autorizado e diff de dependências | `BLOCKED` / `NOT EXECUTED` | Backend/Release |
| PHP-06 | Auditoria de dependências | Dependências PHP são verificadas quanto a origem, vulnerabilidades conhecidas e licença; nenhuma biblioteca nova é introduzida sem registro | Relatório sanitizado e aprovação | `BLOCKED` / `NOT EXECUTED` | Segurança/Backend |
| PHP-07 | Autoload e permissões | **Backlog futuro bloqueado.** Não carregar a aplicação nem testar usuário de serviço ou escrita funcional nesta preparação | Teste em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/Infra |
| PHP-08 | Bibliotecas documentadas | TCPDF/FPDF, EmailProviderFactory/Curl e demais bibliotecas efetivamente usadas foram conferidas; não presumir PHPMailer ou outras dependências legadas | Matriz código→dependência e smoke tests | `BLOCKED` / `NOT EXECUTED` | Backend |
| PHP-09 | PHP lint | Lint de sintaxe somente no clone completo recebido, em `source/` fora do webroot, é permitido sem inicializar a aplicação nem executar teste funcional; registrar escopo e resultado | Comando sanitizado, caminho `source/`, arquivos abrangidos, versão, código de saída e resultado, sem dados sensíveis | `BLOCKED` / `NOT EXECUTED` | QA/Backend |
| PHP-10 | Erros de API | **Backlog futuro bloqueado.** Não testar API, JSON, autenticação ou CORS nesta preparação | Respostas sanitizadas em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | QA/Backend |

## 9. Fase 5 — Nginx e PHP-FPM

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Pré-condição:** Fase 2 e Fase 4 aptas, pré-condição cumulativa atendida e snapshot de configuração definido. A exceção de ciclo de vida do PostgreSQL pertence somente à Fase 3. Nginx/PHP-FPM não podem ser habilitados para autostart; start/reload controlado só pode ocorrer após os gates, segunda sessão e recuperação/rollback validados, de forma temporária e somente para fixture de infraestrutura local.
**Critério de saída:** arquivos/configuração de Nginx/PHP-FPM podem ser preparados fora dos caminhos ativos; fixture sintética de infraestrutura pode usar somente `127.0.0.1`/socket Unix, sem release/current, publicação, serving externo ou validação funcional, e deve ser encerrada/removida ao final; qualquer impossibilidade de demonstrar prevenção de autostart/listener exige parada.
**Owner da fase:** Infra/Backend.

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| NF-00 | Nginx/PHP-FPM | Instalar/configurar e validar sintaxe somente após acesso, fingerprint, inventário e gates; não habilitar autostart; iniciar/recarregar somente de forma temporária e controlada para fixture sintética em `127.0.0.1`/socket Unix, sem serving público ou teste funcional do ERP | Configuração sanitizada, teste de sintaxe, bind/listeners, autostart e evidência de início/reload temporário e encerramento da fixture, sem dados | `BLOCKED` / `NOT EXECUTED` | Infra |
| NF-01 | Server block | Configurar somente após confirmar árvore e fora do webroot; apontar, se necessário, apenas para fixture local autorizada, nunca para release/current, checkout público ou ERP externamente | Caminho sanitizado, raiz/socket local, teste de exposição negativa e rollback | `BLOCKED` / `NOT EXECUTED` | Infra |
| NF-02 | SPA fallback | **Backlog futuro bloqueado.** Não carregar rotas da SPA nem executar testes HTTP | Testes de rotas em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Frontend/QA |
| NF-03 | FastCGI | Teste sintético de fixture de infraestrutura permitido somente após os gates, em `127.0.0.1`/socket Unix, sem chamar endpoints, carregar configuração ou código funcional do ERP, nem servir publicamente | Fixture descartável, socket/bind local, comando sanitizado, resultado/código de saída e remoção, sem dados | `BLOCKED` / `NOT EXECUTED` | Infra/Backend |
| NF-04 | Arquivos estáticos | **Backlog futuro bloqueado.** Não publicar, servir ou validar JS/CSS, MIME, cache ou source maps | Inventário em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Frontend/Infra |
| NF-05 | Requisições perigosas | **Backlog futuro bloqueado.** Não executar testes HTTP nem expor `.env`, VCS, backups, dumps ou configurações | Testes negativos em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Segurança/QA |
| NF-06 | Limites | **Backlog futuro bloqueado.** Não testar upload, limites HTTP, buffers ou timeouts da aplicação | Valores/testes em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| NF-07 | Logs de acesso | **Backlog futuro bloqueado.** Não ativar logging de acesso nem testar tráfego da aplicação | Configuração/amostra em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| NF-08 | Health checks | **Backlog futuro bloqueado.** Não publicar health/readiness nem testar dependências, schema ou dados | Contrato/resultados em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Infra/QA |
| NF-09 | Reload seguro | Reload/start local controlado somente após teste sintático, snapshot/rollback, segunda sessão, recuperação e confirmação de ausência de exposure e autostart; limitado à fixture de infraestrutura local; ao final, encerrar o uso temporário e remover a fixture, nunca servir funcionalmente o ERP | Teste sintático, autorização/gate, estado antes/depois, bind/listeners, autostart, recuperação/rollback, encerramento da fixture e código de saída | `BLOCKED` / `NOT EXECUTED` | Infra |
| NF-10 | PHP-FPM pool do ERP | **Backlog funcional futuro bloqueado.** Não ativar pool/workers do ERP, slowlog ou executar restart funcional nesta preparação; eventual socket de fixture de infraestrutura permanece limitado ao loopback/socket Unix e ao start/reload temporário e controlado autorizado, sem autostart, devendo ser encerrado ao final | Configuração/métricas do ERP em fase futura autorizada; eventual fixture local deve ter evidência separada de autostart, início/reload e encerramento | `BLOCKED` / `NOT EXECUTED` | Infra/Backend |

## 10. Fase 6 — Clone privado (sem release/publicação)

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Pré-condição:** PF-05 aprovado; não há destino de deploy nesta autorização.
**Critério de saída:** clone completo do remoto oficial, privado e restrito a `source/` fora do webroot, com `origin/main` atual, `HEAD`, SHA, baseline e diferenças desde o baseline verificados; nenhuma release/publicação/alteração de `release` ou `current`.
**Owner da fase:** Release/Backend.

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| RR-01 | Clone completo e privado | Clonar somente o remoto oficial `https://github.com/ASOARESBH/app.erpcondominios.com.br-`, com histórico completo, em `source/` fora do webroot; preservar a privacidade, manter `origin/main` atual e verificar `HEAD`, SHA, baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e diferenças desde o baseline; não usar clone arbitrário | URL remota oficial, caminho fora do webroot, SHA de `origin/main`/`HEAD`, baseline presente e diff sanitizado desde o baseline, sem segredo | `BLOCKED` / `NOT EXECUTED` | Release |
| RR-02 | Release/publicação | **Backlog futuro bloqueado.** Não selecionar release/tag para publicação nem criar `release`/`current` | Identificador de release em change futuro autorizado | `BLOCKED` / `NOT EXECUTED` | Release |
| RR-03 | Baseline e diferenças do clone | Comparar somente o clone recebido com o baseline registrado, sem comparar código implantado nem preparar artefato de deploy; confirmar `origin/main` atual, SHA e commits/diferenças desde o baseline | SHA do baseline e de `origin/main`/`HEAD`, manifesto ou diff sanitizado e indicação de que não houve release/deploy | `BLOCKED` / `NOT EXECUTED` | Release |
| RR-04 | Integridade do clone | Verificar somente integridade, proprietário e permissões do clone privado em `source/`; não alterar working tree | Checksums e inventário sanitizados | `BLOCKED` / `NOT EXECUTED` | Release/Segurança |
| RR-05 | Arquivos proibidos | **Backlog futuro bloqueado.** Não montar raiz pública nem artefato; não copiar segredos, dumps, logs, backups ou dados preexistentes | Lista de exclusão em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Release/Segurança |
| RR-06 | Configuração externa | **Backlog futuro bloqueado.** Não injetar configuração/segredos nem conectar o clone à aplicação | Mapa de configuração em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/Segurança |
| RR-07 | Frontend/backend ativo | **Backlog futuro bloqueado.** Não ativar, servir ou testar SPA, APIs, autenticação, prepared statements ou tenant | Checklist/testes em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/QA |
| RR-08 | Dependências front-end | Inspecionar manifests e instalar apenas dependências necessárias à preparação; não buildar/publicar nem fazer smoke test do ERP | Manifestos, versões e decisão sanitizada; sem artefato público ou teste funcional | `BLOCKED` / `NOT EXECUTED` | Frontend/Segurança |
| RR-09 | Rollback de release | **Backlog futuro bloqueado.** Não preservar release anterior para troca nem testar rollback de publicação | Registro em change futuro autorizado | `BLOCKED` / `NOT EXECUTED` | Release/Infra |
| RR-10 | Permissões de deploy | **Backlog futuro bloqueado.** Não publicar, alterar permissões de deploy ou conceder escrita no código/serviço | Mapa de deploy em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Release/Segurança |

## 11. Fase 7 — Privacidade e exposição de dados

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Pré-condição:** ambiente com fixture funcional sintética ou dados formalmente autorizados em change futuro; a fixture sintética de infraestrutura local desta preparação não serve à QA do ERP; esta fase permanece bloqueada.
**Critério de saída:** requisito futuro de privacidade, não executável nem aprovável no escopo atual.
**Owner da fase:** Segurança/Privacidade, com QA e Backend.

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| PR-01 | Fixture de QA funcional | **Backlog futuro bloqueado.** Não criar, importar ou testar fixture/dados funcionais do ERP nesta preparação; fixture sintética de infraestrutura local não é fixture de QA | Declaração de origem em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Segurança/QA |
| PR-02 | Respostas de API | **Backlog futuro bloqueado.** Não testar API, respostas ou tenant nesta preparação | Contratos/respostas em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/Segurança |
| PR-03 | Autorização server-side | **Backlog futuro bloqueado.** Não testar criação, edição, exclusão ou RBAC do ERP | Matriz/testes em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/Segurança |
| PR-04 | Isolamento de tenant | **Backlog futuro bloqueado.** Não consultar ou testar isolamento de tenant | Casos negativos em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend/QA |
| PR-05 | Sessão/cookies | **Backlog futuro bloqueado.** Não testar PHPSESSID, tokens, headers ou sessão | Inspeção em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Segurança |
| PR-06 | Erros e stack traces | **Backlog futuro bloqueado.** Não provocar falhas nem testar respostas do ERP | Cenários em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Backend |
| PR-07 | Upload e download | **Backlog futuro bloqueado.** Não acessar, copiar, publicar ou testar uploads/downloads | Testes em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Segurança/Backend |
| PR-08 | Logs e observabilidade | **Backlog futuro bloqueado.** Não coletar logs da aplicação nem tráfego funcional nesta preparação | Busca/política em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Segurança/Infra |
| PR-09 | Ferramentas externas | **Backlog futuro bloqueado.** Não conectar ou testar integrações externas | Matriz/teste em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Segurança/Produto |
| PR-10 | Retenção e eliminação | **Backlog futuro bloqueado.** Não produzir fixtures, artefatos de publicação ou cópias de dados nesta preparação | Política em fase futura autorizada | `BLOCKED` / `NOT EXECUTED` | Privacidade/Infra |

## 12. Fase 8 — Logs, monitoramento e operação

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Pré-condição:** serviços e observabilidade autorizados.
**Critério de saída:** falhas são detectáveis, correlacionáveis e tratáveis sem exposição indevida.
**Owner da fase:** Infra/Operação.

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| LG-01 | Logs Nginx | Logs de acesso/erro têm rotação, retenção, timezone e correlação definidos, sem segredos | Configuração e amostra sanitizada | `BLOCKED` / `NOT EXECUTED` | Infra |
| LG-02 | Logs PHP-FPM | Erros, slow requests e falhas de worker são capturados no destino correto, com permissão restrita | Configuração e amostra sanitizada | `BLOCKED` / `NOT EXECUTED` | Backend/Infra |
| LG-03 | Logs da aplicação | `error_log` e logs estruturados não incluem dados pessoais, tokens ou conteúdo desnecessário | Amostra e regra de sanitização | `BLOCKED` / `NOT EXECUTED` | Backend/Segurança |
| LG-04 | Banco | Logs de conexão, erro, slow query e auditoria seguem retenção e acesso mínimo; parâmetros sensíveis não são expostos | Configuração sanitizada e amostra | `BLOCKED` / `NOT EXECUTED` | DBA/Segurança |
| LG-05 | Métricas | CPU, RAM, disco, workers, latência, 4xx/5xx, conexões, locks e backup têm limiares | Dashboard/alertas e teste de alerta | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| LG-06 | Health/readiness | **Futuro e bloqueado nesta preparação.** Diferenciar processo, dependências, schema e dados mínimos exige validação funcional do ERP; não publicar nem testar health/readiness nesta preparação | Contrato e respostas sanitizadas em change futuro autorizado, com schema/dados definidos | `BLOCKED` / `NOT EXECUTED` | Infra/QA |
| LG-07 | Auditoria | **Futuro e bloqueado nesta preparação.** Validar usuário, tenant, data, resultado e motivo em operações sensíveis exige schema, dados e validação funcional/RBAC do ERP; não emitir evento funcional nem consultar auditoria nesta preparação | Evento sintético e consulta em homologação futura autorizada, sem segredo | `BLOCKED` / `NOT EXECUTED` | Backend/Segurança |
| LG-08 | Incidente | Runbook informa como preservar evidência, bloquear acesso, revogar credenciais e comunicar incidente | Runbook aprovado e exercício sintético de infraestrutura, sem fluxo ou dados do ERP | `BLOCKED` / `NOT EXECUTED` | Segurança/Operação |

## 13. Fase 9 — Backups e restore

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Pré-condição:** acesso autenticado, fingerprint e inventário somente leitura completos e destino privado aprovados; o PostgreSQL novo vazio pode ter backup e restore isolado; para os diretórios compartilhados formalmente acordados, somente cópia local protegida com checksum/integridade e preservação da origem, sem restaurar esses arquivos. Snapshot/manifesto de configuração é somente controle separado de rollback, sem dados do banco e sem integrar ou substituir o backup/restore. É proibido acessar/copiar linhas ou dados MySQL/HostGator, dados não acordados, backup externo ou arquivos fora dos diretórios compartilhados acordados.
**Critério de saída:** backup e restore exclusivamente do PostgreSQL vazio, em destino local isolado, e cópia local protegida dos diretórios compartilhados acordados com checksum/integridade e origem preservada, sem restauração desses arquivos; eventual snapshot de configuração permanece separado como controle de rollback. Isso não é backup de produção nem comprovação de RPO/RTO produtivos.
**Owner da fase:** DBA/Infra.

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| BK-01 | Escopo do backup | No banco, somente PostgreSQL novo vazio; adicionalmente, somente cópia local protegida dos diretórios compartilhados formalmente acordados; excluir linhas/dados MySQL/HostGator, dados não acordados, arquivos fora dos paths aprovados e dados pessoais | Política de escopo, paths aprovados e confirmação sanitizada de que não houve acesso à origem proibida | `BLOCKED` / `NOT EXECUTED` | DBA/Infra |
| BK-02 | Execução controlada do backup | Executar apenas em destino local privado, com acesso mínimo: backup do banco vazio e cópia dos diretórios acordados; não fazer backup externo nem alterar a origem | ID do job, horário, paths, tamanho e checksum sem dados sensíveis | `BLOCKED` / `NOT EXECUTED` | DBA/Infra |
| BK-03 | Integridade do backup | Backup do banco vazio e cópia dos diretórios acordados passam verificação de integridade; nenhum arquivo não acordado é incluído | Resultado de verificação, manifestos e checksums sanitizados | `BLOCKED` / `NOT EXECUTED` | DBA |
| BK-04 | Restore isolado e origem preservada | Restaurar somente o banco PostgreSQL vazio em destino local isolado. Para diretórios acordados, verificar a integridade da cópia local sem restaurá-los nem alterar a origem. Nunca apontar para produção. | Registro do restore do banco, isolamento, integridade da cópia local e confirmação sanitizada da preservação da origem | `BLOCKED` / `NOT EXECUTED` | DBA/Infra |
| BK-05 | Reconciliação de dados de produção | **Fora do escopo/Gate 2 fechado.** Não reconciliar linhas/agregados da origem | Autorização e relatório futuro | `BLOCKED` / `NOT EXECUTED` | DBA/QA |
| BK-06 | RPO/RTO produtivos | **Fora do escopo desta preparação.** Definir em decisão operacional posterior; não alegar atingimento | Metas aprovadas e teste futuro | `BLOCKED` / `NOT EXECUTED` | Produto/Operação |
| BK-07 | Snapshot de configuração separado | Registrar somente snapshot/manifesto da configuração autorizada, sem segredos, dados do banco ou arquivos preexistentes; não tratar como backup/restore do banco | Manifesto sanitizado e referência ao cofre, sem segredo | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| BK-08 | Rollback pós-release/migration | **Futuro.** Nenhuma release/migration ou rollback do ERP nesta autorização | Change e exercício futuro autorizado | `BLOCKED` / `NOT EXECUTED` | DBA/Release |
| BK-09 | Limpeza | Não produzir fixtures funcionais nem cópias fora do escopo; remover somente temporários criados pela própria preparação e preservar os artefatos locais protegidos autorizados conforme política | Registro de limpeza, paths mantidos/removidos e confirmação de ausência de dados não acordados | `BLOCKED` / `NOT EXECUTED` | Infra/Privacidade |

## 14. Fase 10 — QA do ERP

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Pré-condição futura:** autorização separada de homologação, ambiente privado aprovado, eventual abertura formal do Gate 2 e fixture funcional sintética autorizada; a fixture de infraestrutura local desta preparação não é QA; nada disso está disponível ou autorizado nesta preparação.
**Critério de saída:** fluxos críticos passam em navegador e API sem erros, sem regressão de autorização, sem vazamento e com banco/schema/dados compatíveis. Nada disso é permitido pela autorização de preparação atual.
**Owner da fase:** QA, com Produto, Backend e Infra.

**Bloqueio vigente:** QA-01 a QA-16 são backlog de uma fase futura e permanecem `BLOCKED / NOT EXECUTED`; não iniciar browser/API/ERP, testes de segurança funcional, fixtures funcionais, fluxos de usuário ou testes com dados sob a autorização atual. Os status indicam planejamento futuro, não autorização.

### 14.1 Smoke técnico

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| QA-01 | HTTPS e carregamento inicial | **Futuro e bloqueado.** Não acessar URL, HTTPS ou SPA nesta preparação | Evidência em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Infra |
| QA-02 | AppRouter e assets | **Futuro e bloqueado.** Não carregar páginas, CSS ou JS nesta preparação | Evidência em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Frontend |
| QA-03 | Responsividade | **Futuro e bloqueado.** Não abrir browser nem avaliar viewport/telas | Matriz em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Frontend |
| QA-04 | API JSON | **Futuro e bloqueado.** Não chamar API nem avaliar respostas | Respostas em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Backend |
| QA-05 | Autenticação negativa | **Futuro e bloqueado.** Não testar cookie, sessão, endpoint ou RBAC | Casos em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Segurança |
| QA-06 | Injeção e escaping | **Futuro e bloqueado.** Não executar payloads, consultas ou testes do ERP | Casos em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Backend |

### 14.2 Fluxos de negócio e isolamento

| ID | Verificação | Critério verificável | Evidência a coletar | Status | Owner |
|---|---|---|---|---|---|
| QA-07 | Login/sessão | **Futuro e bloqueado.** Não testar login, sessão ou tenant | Casos em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Backend |
| QA-08 | Perfis RBAC | **Futuro e bloqueado.** Não testar perfis, escrita ou autorização server-side | Matriz em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Segurança |
| QA-09 | Moradores/dependentes | **Futuro e bloqueado.** Não consultar, alterar, anexar ou criar fixture funcional | Casos em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Produto |
| QA-10 | Registro/acesso | **Futuro e bloqueado.** Não testar registros, visitantes, tokens ou permissões | Casos em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Produto |
| QA-11 | Ordens de serviço | **Futuro e bloqueado.** Não testar listagem, filtros, edição, exclusão ou tenant | Casos em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Produto |
| QA-12 | RH e relatórios | **Futuro e bloqueado.** Não executar fluxos, relatórios, PDFs ou fixtures funcionais | Casos em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Produto |
| QA-13 | Chat-In e integrações | **Futuro e bloqueado.** Não conectar, testar comunicação ou integrações | Testes em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Produto |
| QA-14 | Erros e indisponibilidade | **Futuro e bloqueado.** Não injetar falhas nem testar dependências do ERP | Testes em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Backend |
| QA-15 | Migração de dados | **Futuro e bloqueado.** Não usar dados migrados nem comparar com a origem | Relatório em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/DBA |
| QA-16 | Sem dados/schema | **Futuro e bloqueado.** Não executar teste funcional sem dados/schema nesta preparação | Registro em QA futuro autorizado | `BLOCKED` / `NOT EXECUTED` | QA/Produto |

### 14.3 Critérios mínimos de aceite da QA

- Nenhum QA-01 a QA-16 pode receber `PASS` no escopo atual; qualquer aceite pertence a QA futuro autorizado.
- `PASS` futuro somente com evidência reproduzível, sanitizada e vinculada à release.
- Zero erro vermelho não tratado no console nos fluxos críticos.
- Zero resposta de API protegida com HTML, warning ou stack trace.
- Zero acesso cruzado entre tenants nas fixtures funcionais futuras.
- Zero bypass de RBAC por URL, payload, botão oculto ou sessão stale.
- Zero segredo, token, cookie ou dado pessoal em código, resposta, log, screenshot ou artefato.
- Smoke técnico sem dados/schema não conta como aceite funcional; a aplicação permanece `BLOCKED` até a migração autorizada e reconciliada.
- Falha em backup/restore, exposição de rede, autenticação, isolamento, integridade de release ou dados/schema é bloqueador de go.

## 15. Fase 11 — Go/No-Go

**Status da fase:** `BLOCKED` / `NOT EXECUTED`
**Owner da decisão:** Produto/Operação, com aceite técnico de Infra, Backend, DBA, Segurança e QA.

### 15.1 Pré-condições de Go

Todos os itens abaixo são requisitos de mudança futura; nenhum pode ser considerado atendido por este checklist. `WAIVED` não pode dispensar Gate 2, integridade, segurança, privacidade, dados ou autorização.

1. Acesso e identidade da VM foram confirmados por fingerprint validada e autorização registrada.
2. Inventário do host, recursos, usuários, discos, serviços e estado de `sshd` foi coletado sem pressupostos.
3. Firewall, portas, TLS, CORS, cookies, diretórios sensíveis e uploads foram verificados.
4. Runtime PHP, extensões, Composer lock, Nginx/FPM e dependências da release foram comprovados.
5. PostgreSQL está registrado como destino; compatibilidade efetiva e estratégia de cutover/rollback foram testadas/aprovadas; Gate 2 está formalmente aberto.
6. Schema, migrations e dados necessários foram migrados/reconciliados em ambiente autorizado; tenants e RBAC foram validados.
7. O teste de sistema sem dados/schema foi executado e documentou corretamente que o ERP não é funcional sem essas dependências.
8. Backup íntegro, restore isolado e rollback foram comprovados, com RPO/RTO aprovado.
9. QA técnico, de segurança, de privacidade e funcional passou nos fluxos críticos, incluindo cenários negativos.
10. Logs, alertas, runbooks, retenção e responsáveis de operação estão prontos.
11. Não existem bloqueadores críticos abertos nem exceções sem prazo.

### 15.2 Registro da decisão

| Campo | Preenchimento | Status |
|---|---|---|
| Decisão | `PENDENTE` — escolher `GO`, `NO-GO` ou `GO CONDICIONAL` somente após revisão | `BLOCKED` / `NOT EXECUTED` |
| Release/commit | Não preenchido nesta sessão | `BLOCKED` / `NOT EXECUTED` |
| Ambiente/VM | Não auditado nesta sessão | `BLOCKED` / `NOT EXECUTED` |
| Gate 2 | `CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE` | `BLOCKED` / `NOT EXECUTED` |
| QA owner | Não designado nesta sessão | `BLOCKED` / `NOT EXECUTED` |
| Infra owner | Não designado nesta sessão | `BLOCKED` / `NOT EXECUTED` |
| DBA owner | Não designado nesta sessão | `BLOCKED` / `NOT EXECUTED` |
| Segurança/privacidade | Não designado nesta sessão | `BLOCKED` / `NOT EXECUTED` |
| Aprovação final | Não realizada | `BLOCKED` / `NOT EXECUTED` |
| Plano de rollback | Não executado | `BLOCKED` / `NOT EXECUTED` |

**Regra:** na ausência de evidência suficiente, a decisão padrão é `NO-GO` ou `BLOCKED`, nunca `GO` por inferência.

## 16. Procedimento futuro condicionado a acesso e autorizações

1. Registrar esta autorização delimitada, a janela e o change controlado da operação permitida; obter aprovação separada somente para qualquer etapa fora do escopo, como QA do ERP, migração, release, deploy ou cutover.
2. Validar fingerprint e identidade do host; interromper se houver divergência.
3. Fazer inventário somente leitura do host e salvar evidência sanitizada.
4. Inventariar rede/serviços; listeners públicos, ativação ou reload exigem autorização/change próprio.
5. Preparar somente serviços locais vazios e clone privado autorizado; não criar/ativar release, não servir o ERP e não fazer deploy.
6. Testar apenas infraestrutura local permitida (serviços, portas locais, filesystem/logs e PDO_PGSQL `SELECT 1` isolado); não validar endpoints, login, APIs, sessão ou UX do ERP.
7. Não executar teste do ERP sem schema/dados, schema mínimo ou fixture funcional nesta fase; isso exige autorização específica futura e Gate 2 quando aplicável. Fixture de infraestrutura local não substitui essa validação.
8. Não executar migração nesta fase. A abertura de Gate 2 e a migração exigem change próprio, reconciliação live, backup da origem autorizado e rollback aprovado.
9. Deixar QA funcional, segurança/privacidade do ERP, testes de dados e fluxos de usuário bloqueados para fase futura; coletar apenas evidência sanitizada da infraestrutura local permitida.
10. Revisar todos os bloqueadores com os owners; emitir go/no-go formal; publicar somente com aprovação.
11. Após qualquer mudança permitida, repetir somente health checks locais de infraestrutura; smoke test do ERP, logs de aplicação, alertas públicos e verificação externa de exposição ficam para change separado.

## 17. Pendências e bloqueadores registrados

| ID | Pendência/bloqueador | Impacto | Condição para desbloquear | Status | Owner |
|---|---|---|---|---|---|
| BL-01 | SSH autenticado e fingerprint do host não confirmados | Nenhuma auditoria remota é confiável | Acesso autorizado e fingerprint validada | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| BL-02 | Console expirou e não há acesso à VM nesta sessão | Não é possível validar estado atual | Console/SSH funcional e autorizado | `BLOCKED` / `NOT EXECUTED` | Infra/Operação |
| BL-03 | Firewall, OS, usuários, recursos, discos e serviços desconhecidos | Impossível afirmar postura operacional ou de segurança | Inventário somente leitura | `BLOCKED` / `NOT EXECUTED` | Infra/Segurança |
| BL-04 | Compatibilidade PHP 8.3/PostgreSQL não comprovada | Migração e conexão da release podem falhar | Matriz de compatibilidade e testes descartáveis | `BLOCKED` / `NOT EXECUTED` | Backend/DBA |
| BL-05 | Gate 2 da migração fechado | Proíbe migração, cutover ou declaração de prontidão; não pode ser waived | Nova decisão formal de gate, nunca por este checklist | `BLOCKED` / `NOT EXECUTED` | Produto/DBA |
| BL-06 | Schema e dados não comprovados no alvo | ERP não pode ser considerado funcional | Migration, reconciliação e smoke test aprovados | `BLOCKED` / `NOT EXECUTED` | DBA/QA |
| BL-07 | Backup/restore não executados | Rollback e recuperação não demonstrados | Backup íntegro e restore isolado aprovados | `BLOCKED` / `NOT EXECUTED` | DBA/Infra |
| BL-08 | QA autenticado e testes de produção não executados | Não há aceite funcional ou de segurança | Janela, fixture funcional/dados autorizados e QA completo | `BLOCKED` / `NOT EXECUTED` | QA/Produto |
| BL-09 | Owners e aprovações finais não designados | Não há autoridade para go/no-go | Designação formal e aceite registrado | `BLOCKED` / `NOT EXECUTED` | Produto/Operação |

## 18. Evidências e encerramento

Antes de encerrar uma execução futura:

- preencher status, executor, data/hora e evidência para cada item avaliado;
- anexar somente artefatos sanitizados e sem segredos ou dados de moradores;
- registrar falhas, exceções, impacto, owner, prazo e plano de correção;
- confirmar a validação de sintaxe dos PHP alterados, testes de frontend/console aplicáveis e a integridade das alterações quando houver mudança de código;
- confirmar que não há caminhos absolutos ou segredos hardcoded no artefato da release;
- registrar a decisão final e manter o checklist como histórico imutável da execução aprovada;
- se qualquer pré-condição crítica permanecer sem evidência, manter `DRAFT`/`NOT EXECUTED` ou `BLOCKED` e não declarar homologação.
