# Configuração Nginx/PHP-FPM — ERP Condomínios

## Estado vigente — 07/11/2026

**Resultado:** base Nginx/PHP-FPM instalada e validada dentro dos testes executados; **ERP não publicado**. A autorização específica do script v2 substitui, somente para esta preparação, os limites anteriores de ausência de listener/autostart: Nginx/PHP-FPM estão ativos e habilitados, HTTP atende apenas o catch-all, e o vhost ERP continua desabilitado. Gate 2/Fase 3 permanecem fechados.

**Decisão aprovada:** Nginx do Ubuntu (não Apache), PHP 8.3 do Ubuntu, PostgreSQL 18/PGDG e fuso `America/Sao_Paulo`. Foram observados Nginx 1.24.0 e PHP 8.3.6. Backup Hetzner desativado por decisão informada pelo usuário; o agente não alterou nem auditou essa opção no provedor.

### Artefatos instalados e versionados

Os seis arquivos abaixo foram recebidos por leitura dos paths autorizados da VM, sem copiar segredos, e preservados neste diretório de documentação:

| Path Nginx | Cópia versionada |
|---|---|
| `conf.d/erp-http.conf` | [nginx/conf.d/erp-http.conf](nginx/conf.d/erp-http.conf) |
| `snippets/erp-headers.conf` | [nginx/snippets/erp-headers.conf](nginx/snippets/erp-headers.conf) |
| `snippets/erp-cors-api.conf` | [nginx/snippets/erp-cors-api.conf](nginx/snippets/erp-cors-api.conf) |
| `snippets/erp-php.conf` | [nginx/snippets/erp-php.conf](nginx/snippets/erp-php.conf) |
| `sites-available/00-catchall.conf` | [nginx/sites-available/00-catchall.conf](nginx/sites-available/00-catchall.conf) |
| `sites-available/erpcondominios.conf.template` | [nginx/sites-available/erpcondominios.conf.template](nginx/sites-available/erpcondominios.conf.template) |

Somente `00-catchall.conf` está em `sites-enabled`. Nginx escuta HTTP/80 em IPv4/IPv6; não foi observado listener HTTPS/443. O template usa placeholders; não foram criados `current`, configuração operacional do ERP, domínio, TLS ou DNS.

### Verificações efetivas

- `nginx -t` e `php-fpm8.3 -t`: sucesso. O script também validou sintaticamente uma cópia temporária do template ERP sem habilitá-lo.
- `curl` local com `Host: x.invalid`: código HTTP `000`, saída 52 (resposta vazia), compatível com o encerramento `444` configurado. Isso é sucesso do critério do catch-all, não erro da preparação.
- Lint PHP 8.3.6 no clone oficial: 350 arquivos PHP rastreados, 349 aprovados e um erro de sintaxe em `api/teste_phpmailer.php`, linha 35 (`unexpected token "use"`). O arquivo não foi corrigido.
- Nenhum QA funcional de SPA/API, autenticação, tenant, RBAC, uploads, relatórios, CORS ou tradução de rotas foi executado.

### Tradução dos `.htaccess`: preparada, não homologada

O template fornecido pelo usuário pretende traduzir dez `.htaccess`: rewrites de ControlID/projetos/dashboard, bloqueios, uploads legados via API, cache, headers e CORS. A expressão “tradução fiel/testada” presente no artefato é uma alegação do script recebido, **não uma conclusão de homologação deste trabalho**. Aqui foram verificados somente sintaxe e catch-all. Ordem de locations, PATH_INFO, rewrites, fallback, autorização de arquivos e a origem CORS literal devem ser comparados funcionalmente com o código antes de habilitar o ERP.

### Pendências de deploy

Mapear diretórios graváveis persistentes fora do código em `/var/lib/erpcondominios`: logs, fotos utilizadas por `api_face_id.php` e `config/firebase/service-account.json` provisionado por `api_pwa_central.php`. Não copiar ou versionar service-account/segredos. `api/controlid_monitor.php` tem incidente legado reportado `Cannot redeclare fechar_conexao()`; não foi executado nem corrigido nesta preparação. Lint não detecta todos os erros de execução.

O incidente da umask v1, a correção v2 e as verificações do PostgreSQL estão em [INSTALACAO_SERVICOS.md](INSTALACAO_SERVICOS.md). A autorização corrente e o estado consolidado estão em [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md).

<details>
<summary>Histórico preservado: planejamento e restrições anteriores, substituídos pela autorização específica v2 onde expressamente indicado acima</summary>

**Status atual:** `DRAFT / BLOCKED / NOT_EXECUTED` — execução bloqueada porque acesso autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e mecanismo de recuperação ainda não foram validados.
**Gate 2:** `CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE` para migração/integração; não bloqueia a preparação local condicionada da Fase 2.5.
**Escopo autorizado condicionalmente (Fase 2.5):** instalação/configuração de Nginx/PHP-FPM após os gates técnicos cumulativos; hardening restritivo também somente após esses gates, dentro desta autorização condicional e sem autorização extra; teste de sintaxe e fixture local permitidos, sem serving externo do ERP.
**Branch de documentação:** `infra/hetzner-environment-preparation`
**Baseline de referência:** `f60739713e6de3f2473b79ecd14a70fd700e5462`

> A Fase 2.5 autoriza **condicionalmente** a preparação/configuração local após acesso autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e mecanismo de recuperação validados; o hardening restritivo fica dentro dessa autorização após esses gates, sem autorização extra. Não autoriza serving externo, DNS, deploy, migração, integração do ERP ou cutover. Escopo e status: [ESCOPO_E_STATUS.md](ESCOPO_E_STATUS.md).

## 1. Objetivo e limites

Este documento cobre a preparação/configuração de Nginx/PHP-FPM e testes locais de infraestrutura. A publicação da SPA/APIs e o QA funcional (sessão, RBAC, upload e fluxos) ficam fora desta fase.

A proposta **não foi aplicada**, não foi testada em uma VM e não representa um inventário do ambiente. Não há ação executada a registrar nesta revisão. A autorização condicional da Fase 2.5 não é evidência de execução: não presumir acesso à VM, SSH, Hetzner API, console operacional, instalação de pacotes, alteração de firewall, criação de serviço, configuração de Nginx/PHP-FPM, reload, deploy, backup ou migration.

Este documento não ativa domínio, DNS ou SSL/TLS. MySQL no HostGator é a origem atual documentada; seus dados/linhas reais não podem ser acessados, copiados ou submetidos a backup. PostgreSQL local vazio, com role mínima, poderá ser preparado somente dentro dos gates desta fase; são permitidos backup/restore apenas desse banco vazio. A cópia local protegida de diretórios compartilhados é obrigatória somente quando os paths estiverem explicitamente acordados; se não houver paths explicitamente acordados, nenhuma cópia de arquivos deve ser feita. Qualquer snapshot mínimo de configuração preexistente alterada deve ser privado, root-only e separado do backup/restore, servindo apenas como rollback. O teste de conexão permitido é separado, com PHP/PDO_PGSQL e `SELECT 1`, sem conexão, configuração ou teste funcional do ERP. PHP 8.3 é preferência condicionada à compatibilidade, não versão aprovada; comparar também 8.4/8.5 antes de fixar runtime. Migração, schema definitivo, dados reais, uploads preexistentes não acordados e conexão do ERP seguem bloqueados pelo Gate 2.

**Gate 2: CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE.** Não pode ser waived, contornado ou substituído por aprovação informal. O Gate 2 fecha migração, schema definitivo, dados reais, conexão/integração funcional do ERP, publicação/serving externo e cutover; não fecha a instalação/configuração local condicionada da Fase 2.5. Permanecem proibidos nesta fase a importação, o acesso/cópia/backup de dados/linhas do MySQL/HostGator, uploads não acordados, backup externo e qualquer publicação externa.

## 2. Estado e evidências disponíveis

| Item | Estado conhecido |
|---|---|
| Repositório | `https://github.com/ASOARESBH/app.erpcondominios.com.br-`; baseline informado acima. |
| VM declarada | `<hostname privado omitido>`, `<IP privado omitido>`, Helsinki, CPX32, 4 vCPU, 8 GB RAM e 160 GB. São dados declarados, não evidência de ambiente auditado. |
| Sistema operacional e pacotes | Desconhecidos. Ubuntu é apenas o sistema esperado. Versões de Nginx, PHP, PHP-FPM, extensões e socket não foram verificadas. |
| Acesso | SSH autenticado não confirmado; fingerprint do host não validada; chaves instaladas não confirmadas. A inspeção histórica observou `sshd` inativo/desabilitado; isso não foi revalidado nesta sessão. |
| Console | Na tentativa de 2026-11-06, a console abriu controle de acesso do provedor e a visualização expirou antes da autenticação. Nenhum acesso à VM foi realizado; não contornar o controle. |
| Rede e serviços | Firewall, portas, estado de serviços, discos, usuários, logs e recursos atuais são desconhecidos. |
| Arquitetura documentada | SPA em Vanilla JS, backend PHP 8.x, autenticação por sessão e infraestrutura histórica Apache/LiteSpeed com `.htaccess`. Nginx/PHP-FPM é uma proposta para o ambiente futuro, não o estado atual. |
| Document root | Pendente de inspeção da árvore e de teste. Não se assume a existência de `public/`, `dist/`, `frontend/` como raiz pública ou qualquer outro diretório. |
| Autorização e gate | Preparação privada e reversível é condicionada a acesso/fingerprint validados e inventário read-only completo; nada foi executado. Gate 2 está **CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE** e não autoriza migração, schema definitivo, dados reais/linhas do MySQL/HostGator, uploads não acordados ou conexão do ERP ao PostgreSQL. Isso não impede backup/restore local do banco vazio nem backup protegido de diretórios compartilhados acordados, dentro da Fase 2.5. |

## 3. Decisões e invariantes

1. O **document root exato** e o arquivo de entrada da SPA devem ser descobertos somente por inspeção autorizada da árvore e pela referência do roteador; não executar teste funcional da SPA nesta fase. Nenhuma configuração deve ser ativada antes dessa confirmação.
2. O único recebimento permitido nesta fase é um clone completo, com histórico, do remoto oficial `https://github.com/ASOARESBH/app.erpcondominios.com.br-`, em `source/`, fora do webroot, com o `HEAD` atual verificado. A evidência futura deve confirmar que `origin` aponta para esse remoto, que `origin/main` é a referência atual recebida, registrar o SHA de `origin/main`, confirmar a presença do baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` no histórico e registrar os commits/diferenças desde esse baseline. Não preparar, criar ou nomear `release/`, `current/` ou árvore candidata/publicável; não copiar `source/` para document root nem servir o checkout.
3. A rota da SPA só poderá usar fallback para o entrypoint comprovado. APIs, arquivos estáticos ausentes e caminhos sensíveis não podem cair no fallback da SPA.
4. PHP-FPM deve atender somente entrypoints PHP aprovados. Arquivos PHP auxiliares, configurações privadas, scripts de manutenção e qualquer PHP localizado em upload devem ser recusados ou mantidos fora da raiz pública.
5. APIs devem preservar a sessão PHP e as regras server-side já documentadas. O Nginx não substitui `auth_helper.php`, `verificarAutenticacao()` ou as verificações de RBAC.
6. O `tenant_id` e a identidade continuam sendo responsabilidades da sessão/API; não serão derivados de URL, cabeçalho ou payload pelo Nginx.
7. Logs devem ser separados por classe de tráfego e não devem registrar cookies, cabeçalhos de autorização, corpos de requisição ou segredos. O formato final deve evitar também a exposição de parâmetros de query sensíveis.
8. Toda configuração exige teste sintático. Fixture sintética pode ser validada em loopback/socket Unix; o ERP não pode ser servido externamente. Conflito/listener público bloqueia a preparação.

9. Nginx/PHP-FPM não devem ser habilitados para autostart nem mantidos como serviços persistentes nesta fase. Depois dos gates, o único start permitido é temporário, controlado e reversível, somente para fixture sintética em loopback/socket Unix; nenhum start/reload/enable pode criar serviço persistente, listener público ou serving do ERP. Antes de instalar qualquer pacote ou preparar a fixture, deve ser demonstrado que não haverá exposição externa; se houver conflito, comprometimento ou impossibilidade de demonstrar essa prevenção, parar e não instalar.

10. PostgreSQL só pode ser iniciado ou habilitado depois de demonstrados bind exclusivo em loopback, `pg_hba` seguro, firewall com `5432` negada para acesso externo e ausência de conflito. Pode permanecer ativo somente em modo local-only comprovado; se qualquer condição não puder ser demonstrada, não iniciar nem habilitar o serviço e parar.

## 4. Requisitos funcionais futuros do ERP

As subseções abaixo preservam requisitos úteis para uma eventual fase explicitamente autorizada. Não são autorização atual para expor endpoints, servir aplicação, acessar sessão/uploads, configurar CORS ou criar uma árvore de release; não devem ser testadas nem aplicadas agora.

### 4.1 SPA — requisito futuro, sem serving agora

- Servir o entrypoint e os assets a partir do document root validado.
- Usar `try_files` somente após confirmar o entrypoint real da SPA e a forma como `app-router.js` carrega os fragmentos de `frontend/pages/`.
- Permitir fallback apenas para rotas de navegação da SPA. Um arquivo estático inexistente deve retornar `404`, não o HTML da SPA.
- Não expor listagem de diretórios (`autoindex off`).
- Não usar o fallback da SPA para `/api/` ou para qualquer caminho de arquivo sensível.

### 4.2 APIs PHP — requisito futuro, sem exposição de endpoint agora

A árvore deve ser inspecionada para confirmar os caminhos canônicos. A documentação menciona APIs `api_*.php` e o arquivo sensível `api/config.php`; o padrão abaixo é uma política proposta, não uma confirmação da árvore publicada:

- permitir somente os scripts de API efetivamente identificados e aprovados;
- encaminhar esses scripts ao socket PHP-FPM validado, com `SCRIPT_FILENAME` derivado do document root validado;
- bloquear PHP genérico fora da allowlist;
- fazer com que `/api/` sem um endpoint conhecido retorne `404`, sem fallback para a SPA;
- bloquear acesso direto a `api/config.php` e a qualquer arquivo de configuração privada;
- preservar `HTTP_AUTHORIZATION` se algum endpoint validado o utilizar, sem registrar esse cabeçalho;
- não permitir execução por `PATH_INFO` ou por extensões alternativas (`.phar`, `.phtml` e equivalentes), salvo decisão explícita e comprovação de necessidade.

O PHP-FPM deve usar pool dedicado, usuário sem privilégios, `clear_env = yes`, saída de workers encaminhada a log controlado e `cgi.fix_pathinfo=0`, desde que a versão e a compatibilidade do ERP sejam primeiro validadas. Nome do pool, usuário, grupo, socket, limites de processos, timeout e tamanho máximo de corpo permanecem pendentes.

### 4.3 Arquivos estáticos — requisito futuro, sem publicação agora

Arquivos HTML, CSS, JavaScript, mapas, imagens, fontes e demais extensões comprovadas devem ser servidos diretamente somente quando existirem no document root aprovado. A localização real dos assets não deve ser presumida.

A política deve impedir a execução de PHP a partir de qualquer diretório de arquivos estáticos ou de upload. Arquivos não reconhecidos devem ser tratados de forma conservadora; não se deve habilitar tipos MIME ou caminhos adicionais apenas para contornar um erro sem investigar a árvore e o uso pelo frontend.

### 4.4 Uploads e conteúdo privado — requisito futuro, sem acesso agora

A preferência é manter uploads de moradores, documentos, contratos e logos fora da raiz pública, conforme o guia de segurança, e entregá-los por endpoint PHP que valide a sessão, o tenant e as permissões.

Se algum conteúdo comprovadamente público precisar permanecer sob a raiz web, ele deve ter uma localização separada, sem listagem de diretório, sem execução de scripts e com acesso somente aos tipos necessários. A localização real de `uploads/` não foi confirmada. Não se deve publicar todo o conteúdo legado por conveniência.

## 5. Proteções restritivas da configuração local

Aplicar estas regras à configuração local após confirmar os caminhos reais. Elas fazem parte da preparação condicionada, mas não autorizam serving externo do ERP ou publicação:

- **Checkout e metadados:** recusar `.git`, `.gitignore` e demais diretórios/arquivos de controle de versão; a validação deve confirmar que não existe `.git` dentro da raiz publicada.
- **Ambiente:** recusar `.env`, `.env.*` e nomes equivalentes de configuração local.
- **Configuração privada:** recusar `api/config.php` e todos os arquivos que contenham credenciais, chaves ou parâmetros privados. Credenciais não devem ser copiadas para este documento ou para o artefato público.
- **SQL, dumps e backups na raiz servida:** recusar diretórios `sql`, `dump`, `dumps`, `backup` e `backups`, além de extensões e nomes como `.sql`, `.sql.gz`, `.sql.bz2`, `.sql.xz`, `.dump`, `.bak`, `.backup`, `.old`, `.orig` e arquivos temporários. Migrations não são conteúdo público; isso não proíbe diretórios privados de backup/restore do banco vazio nem cópia protegida de diretórios compartilhados acordados fora do webroot.
- **Arquivos ocultos:** recusar arquivos iniciados por ponto, salvo eventual exceção explicitamente aprovada; a exceção não deve liberar `.git` ou `.env`.
- **Listagem:** manter `autoindex off` e não depender de `.htaccess` para obter essa proteção no Nginx.
- **PHP não autorizado:** recusar qualquer PHP que não esteja na allowlist de APIs confirmadas; recusar PHP e extensões executáveis em upload.
- **Respostas de arquivos inexistentes:** retornar `404` sem revelar caminho físico, stack trace ou conteúdo de configuração.

A resposta preferencial para conteúdo sensível é `404`, reduzindo a enumeração, embora o critério de aceite deva aceitar `403` quando uma camada de política já aprovada exigir esse código. Em ambos os casos, o conteúdo não pode ser entregue.

## 6. Logs separados e observabilidade — requisito futuro

Em uma fase futura, separadamente autorizada, a proposta é separar o acesso em pelo menos três fluxos. Não criar logs de serving ou de endpoints nesta fase:

1. **SPA/navegação:** requisições de documentos e rotas da aplicação.
2. **API:** requisições sob o caminho confirmado das APIs PHP, com erro Nginx separado quando possível.
3. **Arquivos estáticos:** assets efetivamente identificados na árvore, sem misturá-los ao log de navegação.

O PHP-FPM deve possuir log de erro próprio do pool, separado do access log do Nginx. Os caminhos finais, proprietário, permissões, rotação, retenção e integração com o sistema de logs da VM dependem do sistema operacional e da política de operação, ainda desconhecidos.

O formato de acesso deve registrar, no mínimo, horário, método, URI sem query sensível, status, bytes, tempo de atendimento e identificação não sensível do agente. Não deve registrar `Cookie`, `Authorization`, corpo de POST, senha, token, documento completo de morador ou dados de moradores. A política de logs deve ser revisada contra os endpoints antes da ativação.

## 7. Headers e CORS — requisitos futuros, sem teste nesta fase

Headers de baixo risco e compatíveis com a validação da SPA devem ser aplicados na resposta, preferencialmente com `always`:

- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: strict-origin-when-cross-origin`;
- `X-Frame-Options: SAMEORIGIN`, se a validação confirmar que não há integração legítima por iframe;
- `Permissions-Policy` restritiva, com permissões liberadas somente se houver requisito comprovado;
- ocultação de informação de versão do Nginx/PHP quando suportada sem alterar o comportamento da aplicação.

Uma política CSP não deve ser imposta de forma presumida: a SPA, scripts inline, estilos inline, service worker e integrações precisam ser inventariados. A alternativa segura é iniciar em `Content-Security-Policy-Report-Only` durante uma janela autorizada de validação e somente depois decidir a política de enforcement.

`Strict-Transport-Security` fica deliberadamente **fora desta especificação operacional**, pois domínio, DNS e SSL/TLS não fazem parte do escopo. Só poderá ser avaliado depois de HTTPS estar configurado, validado e estável.

Para APIs que exigem CORS, não usar `*` junto com credenciais. `https://app.erpcondominios.com.br` é somente um candidato declarado, não uma origem validada para esta VM; confirmar a origem por canal autorizado antes de qualquer configuração. Nenhum domínio ou tráfego é ativado nesta tarefa. `Access-Control-Allow-Credentials: true` só deve ser emitido para uma origem formalmente autorizada e somente onde necessário. A API continua responsável por autenticação, autorização, sessão e tenant.

## 8. Configuração preparada e validação local

Confirmar caminhos, socket, versão e document root antes de criar configuração. Após os gates cumulativos, somente uma fixture sintética temporária poderá ser iniciada de forma controlada e reversível em loopback/socket Unix, com rollback validado e confirmação de ausência de exposição. Nginx/PHP-FPM não devem ter autostart nem `enable` persistente; não configurar listener público nem servir checkout/ERP externamente. Qualquer configuração preexistente que venha a ser alterada exige, antes da alteração, snapshot mínimo, privado e root-only, tratado separadamente como controle de rollback; se nenhuma configuração preexistente for alterada, não criar snapshot. Serviços local-only e fixture temporária não equivalem a publicação, homologação ou serving externo e não alteram o status `BLOCKED / NOT EXECUTED`, que permanece bloqueado porque acesso autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e mecanismo de recuperação ainda não foram validados. A fixture deve ser removida ao final; sem domínio/DNS/TLS nesta fase.

## 9. Procedimento condicionado a acesso, inventário e autorização

1. **Validar pré-requisitos:** confirmar acesso autenticado legítimo, fingerprint confiável do host, inventário read-only completo, segunda sessão administrativa independente funcional e mecanismo de recuperação validado antes de qualquer instalação, preparação ou hardening. Validar a identidade da VM sem bypass ou SSH inseguro e sem contornar controle de acesso do provedor. O hardening restritivo está dentro da autorização condicional da Fase 2.5 somente após esses cinco gates, sem autorização extra; se qualquer gate falhar, não prosseguir. Gate 2 permanece **CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE**.
2. **Inventariar sem alterar:** registrar sistema, discos, usuários, serviços, portas/listeners, firewall, pacotes, logs, recursos, Nginx, PHP-FPM, extensões e sockets. Preservar serviços e dados existentes. Se houver conflito, comprometimento ou não for possível demonstrar prevenção de autostart/ativação/listener, parar e não instalar.
3. **Receber apenas o código autorizado:** confirmar que o remoto é exatamente `https://github.com/ASOARESBH/app.erpcondominios.com.br-` e fazer somente um clone completo, com histórico, em `source/`, fora do webroot. Verificar e registrar o SHA atual de `origin/main`, confirmar que o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` está presente no histórico e registrar os commits/diferenças desde esse baseline. Se o remoto divergir, `origin/main` ou seu SHA não puderem ser verificados, o baseline não estiver presente ou a integridade do clone falhar, parar. Não criar ou copiar `release/`, `current/` ou árvore candidata; não acessar, copiar ou fazer backup de dados/linhas reais do MySQL/HostGator, nem de uploads preexistentes não acordados ou segredos. A origem e os serviços existentes devem ser preservados.
4. **Preparar e validar:** somente após os cinco gates cumulativos do item 1, dentro da autorização condicional da Fase 2.5 e sem autorização extra, instalar/configurar Nginx/PHP-FPM; testar sintaxe e fixture local isolada. Nginx/PHP-FPM não devem ser habilitados para autostart; qualquer start/reload será somente temporário, controlado, reversível e restrito a bind local para a fixture. Não abrir listener público nem servir a aplicação externamente. O hardening restritivo de SSH/firewall e as atualizações só podem ocorrer nesse ponto, após acesso autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e mecanismo de recuperação validados.
5. **Testar localmente:** teste sintático e, se necessário, fixture em loopback/socket Unix. Falha, conflito ou listener público bloqueia continuidade.
6. **Testar PostgreSQL isoladamente:** se o serviço for iniciado ou habilitado, antes comprovar bind exclusivo em loopback, `pg_hba` seguro, firewall com `5432` negada para acesso externo e ausência de conflito; o serviço só pode permanecer ativo em modo local-only comprovado. Usar somente banco PostgreSQL local explicitamente vazio e, se criada, a role `erp_app` com apenas o atributo `LOGIN` e somente o privilégio de banco `CONNECT`. Revogar `CONNECT` e `TEMPORARY` de `PUBLIC`; a role não pode ter memberships, atributos administrativos (`SUPERUSER`, `CREATEDB`, `CREATEROLE`, `REPLICATION` ou `BYPASSRLS`), ownership, `CREATE`, `USAGE`, `TEMPORARY`, grants em schema/objeto ou qualquer privilégio DML. O teste separado de PHP/PDO_PGSQL limita-se a `SELECT 1`; validar também backup/restore desse banco vazio. A cópia local protegida de diretórios compartilhados é obrigatória se houver paths explicitamente acordados; sem paths acordados, nenhuma cópia deve ser feita. Isso não é conexão, configuração ou teste funcional do ERP e não autoriza schema, migration, importação, dados reais, uploads não acordados ou integração.
7. **Não testar funcionalmente o ERP:** não executar login/API/sessão/uploads/fluxos reais. Testes permitidos limitam-se à infraestrutura e fixtures sintéticas locais.
8. **Parar antes da exposição:** não realizar start/reload/enable que resulte em listener público ou serving externo, nem deploy, publicação, cutover, backup externo ou ativação de domínio/DNS/SSL/TLS. Para Nginx/PHP-FPM, somente start/reload temporário e controlado da fixture em loopback/socket Unix, seguido de encerramento e remoção, permanece permitido após bind local e rollback validados; não executar `enable` persistente nem criar autostart. Para PostgreSQL, start/enable só permanece permitido após bind exclusivo em loopback, `pg_hba` seguro, firewall com `5432` negada para acesso externo e ausência de conflito, podendo ficar ativo apenas em modo local-only demonstrado. Qualquer necessidade fora desses limites exige novo escopo e autorização explícita.

## 10. Testes permitidos e critérios de aceite desta preparação

Somente os testes abaixo podem ser considerados nesta fase. **Nenhum foi executado nesta sessão.** Cada item deve produzir evidência datada e sem segredos, sem alterar serviços, dados ou rede:

| ID | Teste | Critério | Evidência | Status | Owner |
|---|---|---|---|---|---|
| T01 | Inventário read-only | Acesso e fingerprint são validados; o inventário completo registra serviços, dados, portas/listeners e estado sem alteração. | Não coletada; acesso autenticado, fingerprint e inventário estão bloqueados. | `BLOCKED / NOT_EXECUTED` | Executor autorizado de infraestrutura |
| T02 | Prevenção | Há evidência de prevenção de autostart/ativação/listener; Nginx/PHP-FPM não são habilitados; em conflito, comprometimento ou dúvida, a instalação não prossegue. | Não coletada; nenhum serviço ou fixture foi iniciado. | `BLOCKED / NOT_EXECUTED` | Executor autorizado de infraestrutura |
| T03 | Clone privado | O remoto oficial é confirmado; existe clone completo, com histórico, em `source/` fora do webroot; `origin/main` e seu SHA são registrados; o baseline `f60739713e6de3f2473b79ecd14a70fd700e5462` está presente e os commits/diferenças desde ele são registrados; não há `release/`, `current/` ou árvore candidata. | Não coletada; nenhum clone foi recebido nesta sessão. | `BLOCKED / NOT EXECUTED` | Executor autorizado de infraestrutura |
| T04 | Configuração Nginx | `nginx -t` retorna sucesso; se autorizado, fixture sintética temporária em loopback/socket Unix é encerrada e removida; sem autostart ou serving externo do ERP. | Não coletada; teste sintático e fixture não executados. | `BLOCKED / NOT EXECUTED` | Executor autorizado de infraestrutura |
| T05 | Configuração PHP-FPM | `php-fpm -t` retorna sucesso; se autorizado, teste sintético usa apenas socket Unix/loopback temporário, sem autostart persistente. | Não coletada; teste sintático e fixture não executados. | `BLOCKED / NOT EXECUTED` | Executor autorizado de infraestrutura |
| T06 | PostgreSQL/PDO | Se iniciado/habilitado, PostgreSQL usa bind exclusivo em loopback, `pg_hba` seguro, firewall com `5432` negada para acesso externo e ausência de conflito, podendo permanecer ativo somente em modo local-only comprovado; `PUBLIC` não possui `CONNECT`/`TEMPORARY`; `erp_app` tem somente `LOGIN` e `CONNECT`, sem memberships, atributos administrativos, ownership, `CREATE`/`USAGE`/`TEMPORARY`, grants ou DML; `SELECT 1` é separado via PHP/PDO_PGSQL contra banco local vazio e o backup/restore é validado, sem conexão/configuração/teste funcional do ERP. | Não coletada; nenhum serviço PostgreSQL foi iniciado/habilitado, nenhum bind/`pg_hba`/firewall/conflito foi verificado, e nenhum banco, role, teste ou backup/restore foi executado. | `BLOCKED / NOT EXECUTED` | Executor autorizado de infraestrutura |
| T07 | Preservação | Serviços, origem MySQL/HostGator, dados/linhas reais, uploads preexistentes não acordados e configuração existente permanecem intocados; cópia local protegida é feita somente dos diretórios explicitamente acordados, sendo obrigatória quando esses paths existirem e proibida quando não houver paths acordados; snapshot mínimo de configuração preexistente alterada é privado, root-only e separado, apenas como rollback. | Não coletada; não houve acesso operacional, cópia de arquivos, alteração de configuração ou criação de snapshot. | `BLOCKED / NOT EXECUTED` | Executor autorizado de infraestrutura |

Não são testes desta fase: SPA, API, sessão, uploads, CORS, headers, exposição de endpoint, arquivos estáticos, publicação/serving externo, release/current, deploy, cutover, schema, migrations, importação ou conexão do ERP.

## 11. Pendências e bloqueadores

- Acesso autenticado à VM, fingerprint do host, usuário operacional e autorização de sudo.
- A preparação privada e reversível, inclusive hardening restritivo, é condicionada à validação cumulativa de acesso autenticado, fingerprint confiável, inventário read-only completo, segunda sessão administrativa independente e mecanismo de recuperação; após esses gates, permanece dentro da autorização condicional da Fase 2.5, sem autorização extra. Não instalar se houver conflito, comprometimento ou se não for possível demonstrar prevenção de autostart/listener. Gate 2 está **CLOSED / NÃO AUTORIZADO / NÃO-WAIVABLE** e não pode ser waived.
- Inventário auditado de Ubuntu, Nginx, PHP-FPM, extensões, socket, firewall e serviços.
- Document root e entrypoint exatos da SPA; somente clone completo e privado em `source/` com `HEAD` atual verificado é permitido, sem `release/`, `current/`, árvore candidata ou publicação do checkout.
- Caminhos canônicos e allowlist das APIs PHP, incluindo confirmação de `api/config.php` como arquivo a bloquear.
- Localização e política dos uploads, configuração privada e requisitos futuros de publicação, todos fora desta fase.
- Compatibilidade do código e dependências com PHP 8.3; nenhuma versão instalada foi confirmada.
- Limites de corpo, timeout, número de workers e requisitos de upload, que dependem de inspeção e testes.
- Política de retenção/rotação dos logs e integração com o sistema operacional.
- Origem CORS definitiva e posterior decisão de DNS/SSL/TLS, fora do escopo desta entrega.
- PostgreSQL local explicitamente vazio e teste separado PHP/PDO_PGSQL `SELECT 1`, sem conexão/configuração/teste funcional do ERP.
- É proibido acessar, copiar ou fazer backup do MySQL/HostGator e de suas linhas/dados reais, usar uploads preexistentes não acordados, criar schema definitivo, executar migrations, fazer importação, backup externo, cutover, deploy, publicação/serving externo ou listener público. Hardening de SSH/firewall somente após os cinco gates técnicos; dentro da autorização condicional da Fase 2.5, sem autorização extra. Não são proibidos o backup/restore local do banco vazio nem a cópia protegida de diretórios compartilhados explicitamente acordados, desde que preservada a origem; sem paths acordados, nenhuma cópia deve ser feita.
- Nenhuma configuração foi aplicada; portanto, não há resultado de teste, reload, endpoint, serviço ou estado atual a reportar.

</details>
