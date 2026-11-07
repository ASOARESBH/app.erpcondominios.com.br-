# Preparação de serviços — Ubuntu na VM Hetzner

## Execução autorizada e resultado — 07/11/2026

**Status vigente:** `BASE_PREPARADA / VERIFICACAO_READ_ONLY_OK / ERP_NAO_PUBLICADO / GATE_2_CLOSED`.

O usuário autorizou a execução integral e única da v2, confirmou segunda sessão administrativa e console de recuperação, permitiu expressamente o reinício previsto do fail2ban e aprovou as escolhas do script. Acesso SSH com fingerprint comparada e verificação estrita, inventário read-only e pré-checagens já haviam sido obtidos. A autorização específica mais recente admite UFW 22/80/443 IPv4/IPv6 e Nginx/PHP-FPM ativos/habilitados com catch-all; isso substitui somente os limites anteriores de instalação privada sem listener/autostart, **não os gates de migração, conexão do ERP, dados ou deploy**.

### Script e integridade

| Versão | Bytes | SHA-256 | Estado |
|---|---:|---|---|
| v1 | 21.740 | `7dcabbda5cd07e342fdd555eb67d002aa0182f042329400a083f145507bcc90e` | Descartada para novas execuções; interrompida no reinício PostgreSQL |
| [v2 versionada](scripts/fase2_5_base_servidor.sh) | 22.576 | `5ddf609b108095dfd510a3ff1c0654fbab1549dca3d527e3a3274f01fc8c473a` | Verificada no sandbox e na VM; executada uma vez, sem editar o anexo |

A v2 foi executada em 2026-11-07, 21:49:27–21:49:43 UTC. Script/SSH retornaram 0, `tee` retornou 0 e o marcador `FASE_2_5_BASE_OK` foi registrado. Log e evidências operacionais permanecem privados, fora do Git. Não foi necessária deploy key; o clone por HTTPS do repositório oficial terminou.

### Incidente da v1: causa, parada e correção

A v1 parou na linha 374, no reinício `postgresql@18-main`. O diagnóstico read-only confirmou `Permission denied` para `conf.d/90-erp.conf`: proprietário `root:root`, modo `0600`, ilegível por `postgres`.

**Causa atribuída ao executor:** o wrapper de execução aplicou `umask 077` para proteger o log, e o script a herdou na criação de arquivos de serviço. O original v1 não foi editado, mas seu ambiente de execução foi inadequado. Após a falha, não houve correção ou repetição sem nova autorização; somente os comandos de diagnóstico solicitados foram executados.

A v2 fornecida pelo usuário define `umask 022`, aplica proprietários/permissões explícitos, mantém a geração de segredo em escopo restritivo e valida a configuração como `postgres` antes do restart. Na verificação final: `90-erp.conf` pertence a `postgres:postgres`, modo `0644`; `pg_hba.conf`, ao mesmo proprietário, modo `0640`; `postgres -C timezone` retorna `America/Sao_Paulo`. Cluster online e teste PDO confirmam que o bloqueio de leitura foi resolvido.

### Componentes e decisões aprovadas

| Componente | Resultado observado |
|---|---|
| OS/kernel | Ubuntu 24.04.5 LTS; `6.8.0-149-generic` |
| Swap | 4 GiB ativos; sem uso na verificação |
| UFW | Ativo, entrada deny; allow 22/80/443 em IPv4/IPv6 conforme aprovação específica |
| Nginx | 1.24.0 do Ubuntu, ativo/habilitado; somente catch-all habilitado |
| PHP | 8.3.6 do Ubuntu, PHP-FPM ativo/habilitado e extensões do script; 349/350 arquivos rastreados aprovados em lint |
| PostgreSQL | 18.6, pacote `18.6-1.pgdg24.04+2`, PGDG no Ubuntu 24.04; cluster `18/main` online |
| Banco e timezone | `erpcondominios`, vazio conforme contagens executadas; `America/Sao_Paulo`; log timezone UTC |
| Código | Clone completo em `source/` fora do webroot; remoto oficial, HEAD/origin/main `f60739713e6de3f2473b79ecd14a70fd700e5462`, baseline presente e árvore limpa |
| Backup Hetzner | Desativado por decisão informada pelo usuário; opção do provedor não foi alterada/auditada pelo agente |

### Verificações concluídas

1. `ufw status verbose`: políticas acima. SSH/HTTP possuem listeners públicos; HTTPS/443 não possui listener. `5432` aparece somente em `127.0.0.1` e `::1`.
2. Catch-all local com Host arbitrário: HTTP `000`, curl saída 52, esperado para conexão encerrada por `444`; somente `00-catchall.conf` em `sites-enabled`.
3. Banco: zero tabelas em `public` na verificação read-only e zero tabelas não-sistema na checagem do script; PDO isolado executou a consulta técnica do script (`SELECT 1`, timezone e relógio), sem carregar o ERP ou criar objetos de aplicação.
4. `systemctl --failed`: zero unidades; `nginx -t` e `php-fpm8.3 -t`: sucesso; memória disponível ~7,0 GiB.
5. Checksums de 167 arquivos de configuração SSH/fail2ban iguais aos anteriores. O fail2ban foi reiniciado por autorização específica; não houve edição de sua configuração nem do hardening SSH.
6. `current` e configuração operacional do ERP ausentes; nenhuma migration, importação, MySQL servidor, TLS, DNS ou Fase 3 executada.

### Limites e pendências

`FASE_2_5_BASE_OK` comprova somente a base e os testes efetivamente realizados. O script concedeu `CONNECT, TEMPORARY` a `erp_app` e criou `erp_owner NOLOGIN`; não afirmar a política mínima `CONNECT` do runbook antigo como aplicada. Não houve mudança avulsa de privilégios. Revisão de grants, backups locais/restauração, retenção, limites de memória e hardening adicional dependem de tarefas próprias.

PHP lint não é homologação de execução: `api/teste_phpmailer.php` tem erro de sintaxe na linha 35, confirmado e não corrigido; `api/controlid_monitor.php` tem bug legado reportado `Cannot redeclare fechar_conexao()`, não reproduzido porque não se executou o ERP. Mapear logs, fotos de `api_face_id.php` e `config/firebase/service-account.json` via `api_pwa_central.php` para diretórios persistentes em `/var/lib/erpcondominios` antes de deploy. Segredos e service-account nunca entram no Git.

Tradução dos dez `.htaccess` é um template preparado; somente sintaxe/catch-all foram testados, sem homologação das rotas/CORS. Gate 2 continua fechado: sem schema/tabelas/dados de aplicação, sem integração PHP/PostgreSQL do ERP, sem habilitar vhost, `current`, TLS, DNS, merge em main, migração ou cutover.

<details>
<summary>Histórico preservado: runbook anterior à autorização e execução específica v2</summary>

**Status:** `DRAFT` / `BLOCKED` / `NOT_EXECUTED`
**Escopo:** preparação de infraestrutura expressamente autorizada na Fase 2.5, condicionada a acesso/fingerprint validados e inventário read-only completo; nenhuma execução é alegada.
**Baseline consultado:** `f60739713e6de3f2473b79ecd14a70fd700e5462`
**Branch de documentação:** `infra/hetzner-environment-preparation`

**Gate 2:** `CLOSED / NÃO AUTORIZADO` — **NÃO PODE SER WAIVED** para migração ou integração.

> O escopo autorizado, os gates e as proibições estão em [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md). As etapas abaixo ainda não foram executadas porque o acesso administrativo autenticado, a fingerprint confiável e o inventário read-only completo ainda não foram validados.

## 1. Objetivo

Executar, após os gates técnicos, uma preparação segura, auditável e reversível de uma VM Ubuntu destinada ao ERP Condomínios, contemplando:

- inventário somente leitura completo, após acesso administrativo e fingerprint SSH validados;
- instalação/configuração de Nginx e PHP-FPM, com teste sintático e fixture sintética em loopback/socket Unix; sem serving público do ERP ou do checkout;
- clone completo, privado e verificável somente em `source/`, com o HEAD atual conferido;
- PostgreSQL local e vazio, com role mínima e teste separado de `PDO_PGSQL`/`SELECT 1`; sem schema, dados, migração ou conexão do ERP;
- preservação dos serviços e dados existentes, com parada imediata diante de conflito, comprometimento ou impossibilidade de demonstrar as salvaguardas.

Atualizações de segurança do sistema, componentes necessários, hardening restritivo de SSH/UFW/Fail2ban, configuração dos serviços e clone completo estão autorizados somente após validação cumulativa de acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e caminho de recuperação validado. Como parte da mesma preparação condicionada, também estão autorizados o backup local root-only do PostgreSQL vazio com restore isolado e a cópia local protegida somente dos arquivos compartilhados em paths acordados, preservando a origem. Reboot exige janela e recuperação confirmadas; Certbot pode ser instalado, mas não emitir certificado nem alterar DNS. Smoke test do ERP, deploy/publicação, release/current, backup externo de produção, cutover e acesso às linhas MySQL permanecem fora do escopo.

Este documento é um plano. Não registra uma instalação, configuração, conexão, teste de produção ou alteração realizada na VM.

## 2. Estado, evidências e limites conhecidos

### 2.1 Evidências disponíveis

- A VM foi declarada pelo usuário como `<hostname privado omitido>`, endereço `<IP privado omitido>`, região Helsinki, perfil CPX32, 4 vCPU, 8 GB de RAM e 160 GB; Ubuntu 24.04 LTS foi escolhido como imagem. São declarações de destino, não auditoria do ambiente.
- O handoff histórico de 2026-11-05 registra que o SSH autenticado não foi confirmado, a fingerprint do host não foi validada, as chaves não tiveram instalação confirmada, o `sshd` foi visto inativo/desabilitado em inspeção anterior e o console serial expirou.
- Na sessão de 2026-11-06, a navegação pelo My Browser chegou ao controle de acesso do provedor da console Hetzner, mas expirou antes da autenticação; nenhum acesso à VM foi realizado.
- Firewall, sistema operacional efetivo, usuários, discos, recursos disponíveis e serviços atuais permanecem desconhecidos.
- O ERP é documentado como SPA em Vanilla JS com backend PHP 8.x. A arquitetura corrente documenta MySQL no HostGator e Apache/LiteSpeed com `.htaccess` no ambiente atual.
- PHP 8.3 foi indicado como alvo preferido, condicionado à compatibilidade; a compatibilidade do código e dependências ainda não foi comprovada. Em 2026-11-06 o PHP 8.3 já está em suporte de segurança apenas, até 2027-12-31; comparar com 8.4/8.5 e justificar o pin antes de instalar. [2]
- O usuário definiu PostgreSQL como destino futuro; o MySQL do HostGator continua sendo a origem atual até cutover autorizado. O Gate 2 permanece fechado porque a compatibilidade, o schema instalado e a reconciliação ainda não foram aprovados.
- No baseline `f60739713e6de3f2473b79ecd14a70fd700e5462`, não há `composer.json`, `composer.lock`, `package.json` nem `.env.example` rastreados. Esse é o estado observado do baseline, não uma proibição automática: a necessidade de um `.env.example` seguro e de eventual `.env` local será avaliada somente após analisar o checkout completo. A verificação lexical de `api/config.php` encontrou `mysqli` e `getenv`, sem referência a PDO ou PgSQL; isso não é prova de compatibilidade com PostgreSQL.
- As dependências documentadas incluem bibliotecas PHP como TCPDF/FPDF e possível legado de PHPMailer; o frontend usa bibliotecas via CDN ou localmente. Não há indicação canônica de uma etapa obrigatória de build com Node.js.
- O guia de testes informa que não há Jest/PHPUnit e que a validação corrente é manual, incluindo `php -l`, inspeções de saída estritamente JSON e verificações de segurança quando aplicáveis.

### 2.2 O que não foi feito

Houve uma tentativa de navegação à console Hetzner pelo My Browser, bloqueada antes da autenticação em controle de acesso do provedor. Nenhum SSH autenticado, uso da API da Hetzner, shell remoto, `apt`, alteração de firewall, ativação/reinício de serviço, instalação de PostgreSQL, Nginx, PHP, Composer, Git, Certbot, Fail2ban ou Redis foi executado. Também não houve checkout do código na VM, deploy, backup, migration ou teste contra produção.

Não há neste documento versões instaladas, credenciais, chaves, estado atual de serviços ou resultados de comandos apresentados como fatos.

## 3. Decisões e princípios de preparação

1. **Pré-condições antes de qualquer alteração.** A autorização de preparação já foi concedida. Acesso administrativo autenticado, fingerprint confiável, confirmação do sistema operacional, inventário read-only completo e confiável da VM e rollback revisado são precondições cumulativas; sem todos eles, não começar. Antes de qualquer hardening ou alteração de SSH/UFW/firewall, também devem estar validados, cumulativamente, acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e caminho de recuperação; se qualquer item faltar, abortar sem alterar SSH/UFW/firewall.
2. **Menor superfície de ataque.** Instalar somente serviços e extensões necessários ao caminho de execução comprovado. Não habilitar portas, daemon ou pacote apenas por conveniência.
3. **Runtime.** PHP 8.3 é a preferência declarada, não uma versão já instalada ou homologada. Comparar compatibilidade/suporte com 8.4/8.5 antes de fixar a versão. Instalar Composer se o código/dependências efetivamente exigirem; não executar smoke test funcional do ERP.
4. **Destino PostgreSQL definido; aplicação bloqueada até Gate 2.** A origem atual continua sendo MySQL no HostGator, que não será acessado nesta preparação. Após auditoria read-only, acesso administrativo seguro e todos os gates cumulativos de PostgreSQL — bind exclusivamente em loopback/socket Unix, `pg_hba.conf` restritivo, firewall sem exposição de `5432` e ausência de conflito ou comprometimento —, esta fase pode preparar PostgreSQL local, o banco vazio `erp_condominios` e a role mínima `erp_app`. Iniciar ou habilitar PostgreSQL somente depois de comprovar todos esses gates; se permanecer ativo, o serviço deve continuar local-only, sem listener público em IPv4 ou IPv6. Isso não autoriza schema, objetos, ownership, carga de dados, migrations, conexão ou integração do ERP, cutover ou waiver do Gate 2, que permanece `CLOSED / NÃO AUTORIZADO / NÃO PODE SER WAIVED`. [1] [3] [4]
5. **Separar preparação de publicação.** A autorização condicionada cobre somente clone privado completo do código-fonte em `source/`, preparação não habilitada de Nginx/PHP-FPM para fixture local temporária e PostgreSQL vazio isolado; não autoriza servir o checkout, configurar domínio, executar migrations, apontar tráfego ou publicar dados reais.
6. **Configuração reproduzível e sem segredos no repositório.** Credenciais, chaves privadas, tokens, senhas e dados de moradores nunca devem ser gravados neste documento, no Git ou em arquivos públicos.
7. **Preservar o modelo do ERP.** O backend continua PHP 8.x; o frontend continua Vanilla JS. Node.js, Redis e qualquer outro serviço não aprovado não devem ser instalados sem requisito comprovado e change separada.
8. **Limites.** Não fazer reboot sem janela e recuperação confirmadas. Não abrir portas públicas da aplicação, alterar DNS, emitir certificado, executar smoke test funcional, deploy/publicação, release/current, migração ou cutover. Não instalar Node.js/Redis sem necessidade comprovada.

## 4. Pré-condições e validações de acesso

Antes de executar qualquer alteração, cumprir os gates abaixo. A autorização da Fase 2.5 está registrada; confirmar janela quando aplicável e manter estes itens de segurança. Antes de qualquer hardening ou alteração de SSH/UFW/firewall, validar cumulativamente acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e caminho de recuperação validado; se qualquer item faltar, abortar sem alterar SSH/UFW/firewall:

- responsável pela operação e eventual janela de mudança;
- IP, hostname, usuário administrativo e método de autenticação aprovados;
- fingerprint SSH obtida da VM por canal confiável e comparada antes do primeiro login;
- console de recuperação ou procedimento de acesso alternativo;
- política de portas permitidas, origem dos acessos administrativos e contato de escalonamento;
- confirmação do Ubuntu efetivamente instalado, arquitetura, kernel e estado de suporte;
- inventário de disco, memória, swap, carga, interfaces, DNS e relógio do sistema;
- confirmação de que não há serviço ou dado existente que possa ser interrompido;
- política de backup local root-only do PostgreSQL vazio, restore isolado, destino protegido e cópia de arquivos compartilhados somente nos paths acordados, preservando a origem;
- antes de qualquer hardening ou alteração de SSH/UFW/firewall, validação cumulativa de acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e caminho de recuperação; se qualquer item faltar, abortar sem alterar SSH/UFW/firewall; quando autorizadas, as mudanças devem ser restritivas e reversíveis; publicação/DNS/deploy e consulta às linhas da origem permanecem fora do escopo;
- plano de rollback limitado a banco vazio e, se indispensável, snapshot somente da configuração modificada, root-only.

O inventário deve ser somente leitura e registrado em evidência operacional própria. Deve cobrir identidade do host, sistema operacional, kernel, usuário, carga, discos, memória, swap, interfaces, portas/listeners, serviços, firewall existente, permissões, espaço disponível e dados/serviços preexistentes. O inventário é somente leitura; alterações de preparação autorizadas podem ocorrer depois de concluído, revisado e sem conflito. Sem inventário read-only completo e revisado, não iniciar hardening nem alterar SSH/UFW/firewall.

Se o acesso administrativo autenticado, a fingerprint confiável, o sistema operacional, o inventário read-only completo, a segunda sessão administrativa independente ou o caminho de recuperação não puderem ser confirmados, parar sem tentativa de contorno e sem alterar SSH/UFW/firewall. A autorização da Fase 2.5 não substitui a validação técnica do acesso.

## 5. Sequência futura condicionada

### 5.1 Registro inicial e atualização de segurança

Após inventário e snapshot/rollback, aplicar atualizações de segurança do Ubuntu e dos pacotes necessários, registrando versões antes/depois. Não fazer `dist-upgrade` ou reboot sem janela, confirmação de acesso pós-reboot e rollback; não configurar atualizações automáticas não controladas.

### 5.2 Usuários, SSH e acesso administrativo

Antes de qualquer hardening ou alteração de SSH, validar cumulativamente acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e caminho de recuperação validado; se qualquer item faltar, abortar sem alterar SSH/UFW/firewall. Só então, e apenas com usuário não-root/sudo, chave e origem administrativa aprovados, restringir SSH e desabilitar root por senha. Fazer mudanças incrementais e manter sessão de retorno. Não abrir TCP/22 globalmente nem contornar o controle do provedor; diante de falha, parar.

### 5.3 Nginx

O Nginx é uma proposta para a camada HTTP da nova VM. Antes de instalar, deve ser confirmada a topologia desejada, inclusive se haverá proxy externo, balanceador, CDN ou outro terminador TLS.

A configuração futura deverá separar, no mínimo:

- raiz pública do ERP e arquivos que podem ser servidos diretamente;
- encaminhamento de scripts PHP para o socket/endpoint do PHP-FPM;
- bloqueio de acesso a arquivos de configuração, dependências, logs, diretórios privados e arquivos de controle;
- limites de tamanho, timeout e cabeçalhos coerentes com as APIs e uploads autorizados;
- logs de acesso e erro com retenção compatível com a política de privacidade;
- rota de saúde sem exposição de informações sensíveis, se uma rota for aprovada;
- redirecionamento HTTP para HTTPS somente depois de o certificado ser emitido e validado.

Se, e somente se, o inventário read-only não apontar conflito ou comprometimento, a preparação de pacotes/configuração deverá demonstrar antes da instalação como impedir habilitação/autostart e listeners públicos, inclusive scripts pós-instalação, site padrão e socket/porta de escuta. Sem essa demonstração reproduzível, abortar. Instalar/configurar Nginx e manter o site padrão desabilitado, sem `enable`/autostart. Validar a configuração; qualquer `start`/`reload` será temporário e controlado, exclusivamente para fixture sintética em `127.0.0.1`/socket Unix, sem expor o ERP/checkout. Após a fixture, removê-la e parar a instância iniciada para o teste, sem alterar eventual serviço preexistente; não abrir portas públicas, publicar o ERP ou alterar DNS.

### 5.4 PHP-FPM e extensões PHP

A versão do PHP-FPM ainda precisa de homologação. PHP 8.3 é o alvo solicitado, mas não está instalado nem teve compatibilidade comprovada. A página oficial informa que seu suporte ativo terminou em 2025-12-31 e o suporte de segurança termina em 2027-12-31; comparar com PHP 8.4/8.5 e registrar a decisão antes de fixar a major. [2] A análise de compatibilidade deverá considerar somente evidências do checkout privado e:

- restrições em `composer.json` e `composer.lock`;
- APIs e comportamentos usados pelo código procedural e pelas classes existentes;
- extensões realmente referenciadas;
- TCPDF/FPDF, legado de PHPMailer e demais dependências PHP;
- requisitos de sessão, upload, timezone, locale, limites de memória e execução;
- compatibilidade potencial com o banco, sem acessar MySQL/HostGator;
- ausência de necessidade de Composer nesta change.

A eventual instalação deve usar somente a versão aprovada e extensões justificadas pela auditoria. Instalar e configurar PHP-FPM/extensões depois de comprovar versão compatível, sem habilitar `enable`/autostart. Validar sintaxe e, se necessário, fazer `start`/`reload` temporário e controlado de pool/socket somente para fixture sintética local; após a fixture, removê-la e parar a instância iniciada para o teste, sem alterar eventual serviço preexistente. Não servir o ERP externamente nem executar smoke test funcional.

### 5.5 Git e Composer

Git é necessário somente para receber o clone completo e privado do repositório oficial em `/opt/erpcondominios/source/`, fora do webroot, após inventário read-only, acesso seguro e confirmação do destino privado. Isso não autoriza servir o checkout, criar `release`/`current`, ativar uma release ou publicar qualquer arquivo.

No baseline `f60739713e6de3f2473b79ecd14a70fd700e5462`, `composer.json` e `composer.lock` não estão rastreados. Revisar o clone completo e instalar Composer quando necessário para as dependências identificadas. Usar método oficial, verificar integridade e preservar lockfile sem atualizações implícitas; não instalar dependências nem executar scripts que inicializem o ERP sem necessidade comprovada.

O remoto exato aprovado é `https://github.com/ASOARESBH/app.erpcondominios.com.br-`. A evidência do clone deve demonstrar a árvore completa com histórico, o remoto exato, o HEAD atual de `origin/main` e seu SHA, o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` e as diferenças desde esse baseline. Se `origin/main` ou o SHA não puderem ser verificados, abortar; não substituir por branch, tag, `release` ou `current`. O checkout deve ocorrer somente em `source/`, com permissões mínimas e sem escrita ampla pelo usuário do processo PHP. Arquivos de configuração e segredos devem permanecer fora do repositório, fora deste documento, fora do chat e fora de logs.

### 5.6 PostgreSQL — preparação isolada, sem migração nem conexão do ERP

O destino PostgreSQL foi definido pelo usuário. A instalação local, a criação do banco vazio `erp_condominios` e da role `erp_app` estão autorizadas somente após todos os gates cumulativos: inventário read-only completo, confirmação de Ubuntu/acesso/fingerprint, seleção de versão suportada, verificação de pacote/origem e plano reversível. Essas ações não equivalem à migração, conexão, teste do ERP ou homologação.

Somente após todos os gates cumulativos e a comprovação local de bind exclusivamente em loopback/socket, de `pg_hba.conf` restritivo, da política de firewall sem exposição de `5432` e da ausência de conflito ou comprometimento, PostgreSQL poderá ser iniciado e habilitado. O serviço poderá permanecer ativo ao final, desde que continue local-only. A habilitação é apenas do serviço local e não pode resultar em autostart público nem em listener público em IPv4 ou IPv6; não publicar `5432`. Nesta preparação, não configurar nem iniciar o ERP e não conectar a aplicação ao MySQL ou ao PostgreSQL. Qualquer integração com o destino dependerá de adapter PHP, schema, dados, tenant/RBAC, reconciliação, gates e autorização próprios.

O banco deve permanecer vazio: nenhum schema, objeto, tabela, view, função, extensão de aplicação, migration, importação, dado ou ownership de objeto. `erp_app` deve ter atributo `LOGIN` e somente o privilégio `CONNECT` nesse banco; não deve ter `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION`, `BYPASSRLS`, `CREATE`, `TEMPORARY`, `USAGE`, DML ou ownership. Revogar `CONNECT` e `TEMPORARY` de `PUBLIC` no banco e verificar memberships e todos os atributos efetivos da role. Não criar schema nem conceder privilégios de schema/objetos. O segredo de `erp_app` deve ser fornecido apenas por mecanismo seguro, fora do Git, chat, documento e logs.

Antes de instalar pacotes, confirmar o Ubuntu efetivo, arquitetura e assinatura/origem dos pacotes. Em 2026-11-06 a versão candidata é PostgreSQL 18.6, a minor corrente da major 18; PGDG lista Ubuntu 24.04/Noble entre distribuições suportadas. A política recomenda a minor corrente e suporta cada major por cinco anos, mas isso não substitui a matriz de compatibilidade do ERP. [1] [3]

Antes de conectar a aplicação, ainda será necessário:

- definir e aprovar o método operacional de cutover/rollback (o destino PostgreSQL já foi decidido);
- inventário de queries, tipos, funções, collation, transações e comportamentos específicos do MySQL;
- validação do backend PHP e das dependências contra PostgreSQL;
- desenho de migração, estratégia de dupla escrita ou congelamento, reconciliação e rollback;
- backup restaurável e ambiente descartável para ensaio;
- testes de dados, tenant, autenticação, APIs e rotinas críticas;
- aprovação formal do Gate 2.

O serviço instalado/banco vazio deverá permanecer isolado da aplicação até concluir essa lista e receber autorização de mudança. Não executar migrations nem importar dados nesta fase.

Após todos os gates cumulativos, o backup local root-only e o restore isolado do PostgreSQL vazio são etapas obrigatórias desta preparação: limitar o backup ao banco vazio criado nesta change, gravá-lo em destino local previamente acordado, protegido, fora do webroot, com diretório root-only (`0700`) e arquivos root-only (`0600`), e restaurá-lo somente em banco/cluster descartável e isolado, sem qualquer conexão do ERP. Verificar a integridade do artefato e o resultado do restore sem criar schema, objetos ou dados de aplicação. O backup/restore não deve acessar, copiar, exportar ou restaurar MySQL/HostGator. Fora desse artefato, a única cópia de conteúdo preexistente autorizada é a cópia local protegida e separada, somente dos diretórios/arquivos compartilhados explicitamente acordados e registrados na evidência privada, conforme a seção 5.11, preservando a origem; nenhum outro conteúdo preexistente entra no backup/restore. Não considerar backup externo, de produção ou apenas a existência do arquivo como substituto desse teste. Snapshot de configuração é uma atividade distinta, permitida somente para a configuração modificada, root-only e se indispensável para rollback; não substitui o backup/restore nem a cópia dos diretórios acordados.

A ausência de `.env.example` no baseline não é uma proibição automática. Depois de analisar o checkout completo, seu `.gitignore`, a documentação e o contrato efetivamente usado pelo código, pode-se criar ou preservar um `.env.example` somente se necessário e sempre sem segredos, tokens, chaves, senhas ou dados reais; ele poderá ser versionado apenas como modelo sanitizado, conforme a change de documentação. Um `.env` local só pode ser preparado se essa análise provar necessidade: deve ser provisionado por mecanismo seguro, fora de `source/`, fora do webroot e fora de arquivos públicos, com proprietário root e permissões `0600` (e diretórios-pai protegidos), sem aparecer em comandos, histórico, processos, logs, evidências ou Git. Esse arquivo não deve ser criado por convenção, não deve conter dados reais além do estritamente necessário e não autoriza carregar configuração, iniciar ou ativar o ERP nesta preparação.

O teste PHP/PDO é separado do ERP: após confirmar o bind local, `pg_hba.conf`, firewall e a ausência de listener público, validar apenas que a extensão `PDO_PGSQL` está disponível e que uma conexão local ao banco vazio executa `SELECT 1`, sem carregar código do ERP, sem usar credencial em texto, sem criar objetos e sem testar APIs, autenticação, tenant, dados ou produção. Não é necessário encerrar o PostgreSQL após o teste: ele pode permanecer iniciado e habilitado para uso local futuro, desde que se verifique que continua local-only, sem autostart público nem listener público.

### 5.7 Certbot e HTTPS futuro

Certbot pode ser instalado como ferramenta para preparação futura; nesta fase não emitir certificados, abrir 80/443, configurar domínio/DNS ou alterar URLs/cookies. Não registrar e-mails operacionais, tokens ou material de certificado neste documento.

### 5.8 UFW e Fail2ban

Instalar/configurar UFW e Fail2ban somente depois de validar cumulativamente, antes do hardening, acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e caminho de recuperação validado. Se qualquer item faltar, abortar sem alterar SSH/UFW/firewall. Manter allowlist administrativa restrita. Nunca abrir SSH globalmente, 5432 publicamente ou 80/443 sem autorização de publicação. Registrar política antes/depois e validar acesso de retorno; parar se houver risco de bloqueio.

### 5.9 Node.js — decisão

Não há requisito canônico de Node.js para o runtime do ERP: a arquitetura documenta frontend Vanilla JS servido como arquivos e backend PHP 8.x. Portanto, a decisão preliminar é **não instalar Node.js como serviço nem como dependência de produção**.

A decisão só deve ser revista em change futura se uma auditoria do checkout demonstrar uma etapa de build, minificação, testes ou geração de artefatos indispensável ao release. Nesta preparação não instalar nem executar Node.js; não produzir artefatos, build ou release.

### 5.10 Redis — decisão

Não há evidência canônica de que o ERP necessite de Redis para sessão, cache, filas ou locks. A decisão preliminar é **não instalar Redis**.

Redis não deve ser instalado, iniciado ou configurado nesta preparação. Eventual necessidade, caso de uso, bind, autenticação, persistência, recuperação, limites e monitoramento exigem change futura separada e decisão arquitetural aprovada.

### 5.11 Backups locais e arquivos compartilhados

Depois de concluídos e revisados os gates de acesso, fingerprint, inventário e rollback, fazer cópia local somente dos diretórios/arquivos compartilhados explicitamente acordados e registrados na evidência operacional privada. Não presumir que todo `shared/` ou qualquer diretório semelhante esteja autorizado, não incluir paths não aprovados e não expor os nomes ou dados privados neste documento. Verificar a integridade da cópia por método não destrutivo. A origem deve permanecer no lugar, íntegra e inalterada: não mover, limpar, sobrescrever, truncar, reter permissões mais amplas ou remover arquivos da origem. O destino local deve ficar fora do webroot, em diretório protegido e root-only, com acesso mínimo, sem publicação, sem sincronização externa e sem inclusão no Git, logs ou evidências públicas.

Essa cópia de arquivos compartilhados é distinta e não substitui o backup local root-only e o restore isolado obrigatórios do PostgreSQL vazio descritos na seção 5.6. Também não se confunde com snapshot de configuração, que deve ser mínimo, privado e root-only e só pode cobrir a configuração modificada se indispensável para rollback. Nenhuma dessas atividades autoriza acessar, copiar ou respaldar linhas, dumps ou outros conteúdos do MySQL/HostGator; a autorização de arquivos permanece limitada aos diretórios/arquivos compartilhados previamente acordados, com a origem preservada.

## 6. Ordem resumida da preparação privada autorizada

Somente as etapas abaixo pertencem a esta preparação e todas são condicionais; interromper em qualquer falha de pré-condição, conflito, comprometimento ou impossibilidade de demonstrar prevenção de autostart/listeners:

1. registrar a autorização Fase 2.5, responsável, janela quando necessária e rollback;
2. validar acesso administrativo autenticado, fingerprint confiável e caminho de recuperação, sem alterar SSH/UFW/firewall;
3. concluir e revisar o inventário read-only completo e validar a segunda sessão administrativa independente antes de qualquer hardening ou alteração de SSH/UFW/firewall; abortar se qualquer item faltar;
4. verificar se existem dados ou serviços a preservar; abortar a preparação se o inventário apontar impacto ou se a preservação não estiver autorizada;
5. clonar de forma completa, privada e verificável somente em `source/`, conferindo remoto exato, `origin/main`, HEAD/SHA, baseline e diferenças;
6. preparar PostgreSQL local vazio com `erp_condominios` e `erp_app` com privilégio mínimo, depois de todos os gates cumulativos e da comprovação de bind local, `pg_hba.conf`, firewall sem exposição e ausência de conflito; iniciar e habilitar o serviço somente nessas condições, mantendo-o local-only;
7. executar obrigatoriamente o backup local root-only do banco vazio e o restore em destino isolado; em atividade separada, fazer a cópia local protegida somente dos diretórios/arquivos compartilhados previamente acordados, verificando a integridade e preservando a origem; tratar eventual snapshot apenas como cópia mínima e distinta da configuração modificada para rollback;
8. instalar/configurar Nginx/PHP-FPM e extensões aprovadas; validar sintaxe e fazer apenas `start`/`reload` temporário e controlado para fixture sintética local, sem `enable`/autostart e sem serving público do ERP;
9. registrar evidências sem segredos, incluindo preservação, permissões e estado final;
10. manter checkout privado e não servido publicamente; Nginx/PHP-FPM não ficam habilitados para autostart e só podem ser iniciados/recarregados temporariamente para a fixture local; PostgreSQL pode permanecer iniciado e habilitado, sempre local-only e sem listener público; Gate 2 fechado.

Smoke test funcional do ERP, deploy/publicação, release/current, emissão de certificado, DNS, migração, cutover e acesso às linhas MySQL/HostGator ficam fora desta ordem. Reboot exige janela e recuperação confirmadas; Node.js/Redis só se necessidade for comprovada.

## 7. Testes e critérios de aceite

Os critérios abaixo distinguem a evidência desta preparação de critérios de changes futuras. Nenhum item de change futura autoriza execução agora; a ausência de testes automatizados no projeto não reduz a necessidade de validação manual quando uma change própria for aprovada.

### 7.1 Sistema e acesso

- O sistema operacional, hostname, arquitetura, kernel, horário e espaço em disco correspondem ao inventário aprovado.
- Uma nova sessão SSH com a fingerprint validada funciona pelo método autorizado.
- Antes de qualquer hardening ou alteração de SSH/UFW/firewall, estão validados acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e caminho de recuperação; o acesso de recuperação continua disponível depois da alteração.
- Usuários e grupos respeitam o princípio do menor privilégio.
- Não há credencial, chave privada ou token em logs, histórico de shell ou arquivos versionados.

### 7.2 Serviços

- Validar sintaxe do Nginx e, quando necessário, fazer `start`/`reload` temporário e controlado para fixture sintética em loopback; remover a fixture e parar a instância criada para o teste, manter site padrão desabilitado, sem `enable`/autostart e sem expor a aplicação publicamente.
- Validar PHP-FPM e extensões; qualquer `start`/`reload` deve ser temporário, controlado, reversível e limitado a socket/loopback, sem `enable`/autostart e sem atender tráfego público do ERP; remover a fixture e parar a instância criada para o teste.
- Git acessa somente a origem exata autorizada; o clone é completo, privado, somente em `source/`, sem segredos, com HEAD atual de `origin/main`/SHA, baseline e diferenças registrados.
- PostgreSQL, se preparado após os gates de acesso, permanece local e vazio; `erp_condominios` e `erp_app` respeitam os atributos/privilégios mínimos, sem schema/objetos/DML, e não há listener público; o serviço pode permanecer iniciado e habilitado, desde que local-only.
- O teste permitido é separado do ERP: `PDO_PGSQL` local executa somente `SELECT 1`; o PostgreSQL não precisa ser encerrado depois, mas deve permanecer sem autostart público e sem listener público.
- O backup local root-only do PostgreSQL vazio é gravado no destino protegido acordado, sua integridade é verificada e o restore isolado em banco/cluster descartável é concluído sem conexão do ERP, sem acesso à origem MySQL/HostGator.
- Somente os paths de arquivos compartilhados previamente acordados são copiados, em atividade separada, para destino local protegido root-only; a integridade da cópia é verificada e a origem permanece íntegra, inalterada e preservada, sem exposição, sincronização externa ou versionamento.
- Se a análise do checkout provar necessidade de `.env`, ele é provisionado por mecanismo seguro, fora de `source/` e do webroot, root-only, sem versionamento/logs e sem ativação do ERP; `.env.example`, se necessário, não contém segredos.

### 7.3 Segurança de rede — changes futuras, não executáveis nesta preparação

- UFW/Fail2ban e hardening SSH só podem ser executados depois de validar cumulativamente acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e caminho de recuperação validado; se qualquer item faltar, abortar sem alterar SSH/UFW/firewall. As regras devem ser restritivas, documentadas e sem abertura global.
- HTTPS, Certbot, DNS e portas 80/443 exigem change separada e não são executados nesta preparação.
- Em qualquer change futura, Nginx não deverá servir `.env`, arquivos de configuração, diretórios de dependências, logs ou artefatos privados.

### 7.4 Aplicação e backend — change futura, não executável nesta preparação

Após um deploy separado e autorizado, os testes de aplicação deverão seguir o guia do projeto. Nada nesta seção autoriza deploy, publicação, conexão do ERP ou waiver do Gate 2:

- navegar na SPA e confirmar carregamento sem erros vermelhos no console;
- verificar respostas de API estritamente em JSON, sem HTML ou warnings PHP;
- verificar acesso sem `PHPSESSID` em APIs protegidas, esperando 401/403 conforme o contrato;
- exercitar entradas com aspas simples em ambiente descartável para confirmar prepared statements;
- validar autenticação, isolamento multi-tenant e fluxos críticos com dados sintéticos;
- executar `git diff --check` e verificações sintáticas apropriadas no artefato de release;
- não testar com dados de moradores reais durante a preparação.

O aceite da infraestrutura não deve ser confundido com aceite do deploy, do banco ou da migração.

## 8. Pendências e bloqueadores

- **Bloqueador:** acesso SSH autenticado e fingerprint do host ainda não confirmados.
- **Bloqueador:** sistema operacional real, estado do `sshd`, usuários e capacidade da VM ainda não auditados.
- **Bloqueador:** firewall, portas, discos, serviços e dados preexistentes desconhecidos.
- **Bloqueador:** acesso administrativo autenticado, fingerprint confiável, janela específica, inventário read-only completo, segunda sessão administrativa independente, caminho de recuperação e rollback ainda precisam ser confirmados antes de qualquer hardening ou alteração de SSH/UFW/firewall; a autorização vigente não contorna esses requisitos.
- **Bloqueador:** compatibilidade do código e das dependências com PHP 8.3 não comprovada.
- **Bloqueador de integração:** Gate 2 permanece `CLOSED / NÃO AUTORIZADO` e não pode ser waived; não conectar o ERP ao PostgreSQL, criar schema definitivo, carregar dados, aplicar migrations ou fazer cutover. A preparação isolada do serviço/banco vazio não altera esse bloqueio.
- **Pendente:** definir topologia Nginx, raiz pública, permissões, logs, limites e política de proxy/TLS.
- **Pendente:** fechar a matriz de extensões PHP baseada no código e no lockfile.
- **Pendente:** definir o procedimento aprovado para instalação e verificação de integridade do Composer.
- **Pendente:** definir domínio, DNS, e-mail operacional e método de desafio do Certbot.
- **Pendente:** aprovar a política de UFW, a origem administrativa e as regras de Fail2ban, condicionadas à validação prévia de acesso administrativo autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e caminho de recuperação.
- **Pendente:** demonstrar eventual necessidade de Node.js para build; a decisão atual é não instalar.
- **Pendente:** demonstrar eventual necessidade de Redis para cache, sessão ou fila; a decisão atual é não instalar.
- **Pendente:** preparar ambiente descartável e dados sintéticos para os testes do ERP.
- **Pendente:** definir operacionalmente retenção, nomenclatura e destino local protegido do backup/restore já autorizado; deploy, migrations, apontamento DNS e migração de banco continuam dependentes de documentos/mudanças próprios.

## 9. Resultado desta tarefa

Este arquivo registra o plano autorizado para preparação, mas nenhuma instalação, configuração, atualização, conexão ou validação de ambiente foi executada porque não se obteve canal administrativo autenticado e verificável. A próxima etapa é resolver acesso/fingerprint e inventariar o host; não contornar controle de acesso do provedor, não abrir SSH globalmente e não misturar serviço vazio com migração/produção.

## Referências

[1]: https://www.postgresql.org/support/versioning/ "Política oficial de versões PostgreSQL"
[2]: https://www.php.net/supported-versions.php "Versões PHP oficialmente suportadas"
[3]: https://www.postgresql.org/download/linux/ubuntu/ "Repositório PostgreSQL para Ubuntu"
[4]: https://www.postgresql.org/about/news/postgresql-186-1711-1615-1519-1424-and-19-beta-3-released-3365/ "Anúncio oficial do PostgreSQL 18.6, 13/08/2026"

</details>
