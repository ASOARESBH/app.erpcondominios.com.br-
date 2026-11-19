# Guia de Deploy (HostGator)

## 1. Ambiente
O sistema é hospedado em uma revenda HostGator (cPanel).
- **Pasta raiz**: `/public_html/` ou pasta do domínio `app.erpcondominios.com.br`
- **Banco de Dados**: MySQL via phpMyAdmin.

## 2. Processo de Deploy
1. **Compactação**: Todo código deve ser entregue em um arquivo `.zip`.
2. **Upload**: Feito manualmente pelo usuário via Gerenciador de Arquivos do cPanel.
3. **Extração**: Extrair o `.zip` sobrescrevendo os arquivos existentes.

## 3. Arquivos Sensíveis (NUNCA sobrescrever em produção)
- `api/config.php`: Contém as credenciais reais do banco de dados (`inlaud99_admin`).
- Pasta `uploads/`: Contém arquivos de moradores, contratos e logos.
- Arquivos `.htaccess`: Regras de segurança e redirecionamento.

## 4. Migrações de Banco de Dados
Sempre que houver alteração de banco, deve ser gerado um arquivo `.sql` separado e as instruções de execução (via phpMyAdmin) devem ser fornecidas ao usuário.

## 5. Alertas da Unidade — rotina de expiração
Após backup, deploy dos arquivos e aplicação de `sql/migration_alertas_unidade_mysql57.sql`, cadastrar no cPanel > Cron Jobs a rotina `cron/alertas_unidade_a_cada_10_minutos.php` a cada 10 minutos (`*/10 * * * *`). O comando deve usar o binário PHP CLI disponível na conta e o caminho absoluto do domínio, por exemplo: `*/10 * * * * /usr/local/bin/php /home/USUARIO/app.erpcondominios.com.br/cron/alertas_unidade_a_cada_10_minutos.php`. A rotina só aceita CLI, usa lock para evitar concorrência, expira alertas vencidos e deduplica o lembrete de 24 horas. A migration, deploy e agendamento de produção não são executados por esta alteração de código; confirmar o caminho real e o binário no cPanel antes de ativar.

## 6. Chat-In (2026-11-05)
Após backup: executar no phpMyAdmin `sql/migration_chatin_mysql57.sql` e `sql/migration_assembleia_tenant_mysql57.sql` (conferir `assembleias_sem_tenant = 0`). Enviar os arquivos web. O widget se desativa sozinho enquanto a migration não existir. Para desligar o push do Chat-In em um condomínio: `pwa_configuracoes` chave `push_chatin_ativo` = '0'. App: `flutter analyze` + novo build para o push/telas do Chat-In.


## URL limpa de login — gate de publicação e Nginx

A branch `fix/url-limpa-login` parte da `main` atual e adiciona `<base href="/frontend/">` em `frontend/login.html`, além da documentação. Até o merge do PR e publicação desse release, não alterar os vhosts Nginx de produção. Depois de publicado: fazer backup root-only dos dois vhosts (domínio e IP), servir `/` via `alias` para o `frontend/login.html`, redirecionar `/frontend/login.html` e `/login.html` para `/` preservando query strings, validar `nginx -t` e só então recarregar. Evitar loop: a raiz não deve redirecionar para `/frontend/login.html`. O rollback restaura os vhosts salvos e recarrega após `nginx -t`; o rollback do código aponta `current` ao release anterior.

Critérios pós-publicação: `/` retorna 200 `text/html` com o `<base>`; os dois caminhos legados retornam 301 à raiz com query preservada; JS, catálogos, CSS e assets retornam 200; `/login_morador.html` e `/login_fornecedor.html` continuam acessíveis. QA autenticado de login, logout, sessão expirada, recuperação de senha, idiomas e ausência de 404 deve ser feito no navegador autorizado, sem alterar fluxos JavaScript para corrigir URL.

## Rotas limpas da SPA — gate e rollback

A mudança `feature/urls-limpas` adiciona rotas de primeiro nível `/<slug>` para os fragments `frontend/pages/*.html`. O Nginx serve internamente o shell sem reescrever a URL; a barra final é removida por `history.replaceState`, enquanto paths fora do allowlist mantêm o fallback já existente. Após PR revisado e merge aprovado pelo André, a sequência autorizada é: backup dos vhosts; instalar `deploy/hetzner/erp-pages.conf` como `/etc/nginx/snippets/erp-pages.conf` e os vhosts; `nginx -t` e reload; só então publicar o release imutável do código e fazer o smoke. A janela entre Nginx e release deve ser imediata. Não houve alteração de produção nesta branch. O gerador versionado `deploy/hetzner/generate-clean-page-routes.py` produz `deploy/hetzner/erp-pages.conf`; rode `python3 deploy/hetzner/generate-clean-page-routes.py --check` em CI/revisão e regenere após adicionar/remover uma página. O gerador falha em slug reservado/inválido, colisão e snippet obsoleto.

Após o release: fazer backup root-only dos vhosts atuais do domínio e IP; instalar o snippet em `/etc/nginx/snippets/erp-clean-page-routes.conf`; instalar os dois vhosts versionados; executar `nginx -t`; confirmar com `nginx -T` que o snippet está incluído uma vez por vhost e que a regra não precede os bloqueios explícitos; recarregar Nginx somente depois dos gates. A allowlist fica após bloqueios de caminhos reservados e antes das regex genéricas de estáticos/PHP. Não capturar `/projetos`, `/api`, `/uploads`, `/config`, `/frontend`, arquivos físicos ou logins alternativos. `/layout-base.html` deve preservar a query e redirecionar para `/frontend/layout-base.html`; a raiz `/` continua servindo login e os caminhos antigos `?page=` continuam compatíveis.

Rollback: restaurar os dois vhosts e snippet do backup, validar com `nginx -t` e recarregar; apontar `current` ao release anterior se a regressão estiver no código. Não alterar DNS nem executar o deploy de produção como parte da criação do PR. Após publicação, smoke-testar deep links, refresh/F5, back/forward, assets, logins alternativos, API, uploads/config e `/projetos` em hostname e IP. O QA de navegação autenticada exige conta de teste não produtiva fornecida pelo administrador; não registrar credenciais.

## Chat-In v2 — preparo de migration na Hetzner

O PR #13 foi mesclado em `main` no commit `2ad4b9ec652435ccdc21fb754bbdbfeca54f0ed4`; isso **não** significa que a migration esteja aplicada nem autoriza sua execução. A migration versionada é `sql/migration_chatin_v2_mysql.sql` (MySQL 8.4). Use apenas o runner versionado `deploy/hetzner/migrate-chatin-v2-production.sh`, na release imutável correspondente; ele fixa host `2.29.161.106`, schema ativo `erpcondominios_stage_20261114` e SHA-256 do SQL. Não altere o alvo ou checksum sem nova revisão.

Primeiro execute `sudo /var/www/erpcondominios/current/deploy/hetzner/migrate-chatin-v2-production.sh --check` pelo TTY SSH autorizado. Esse modo é read-only e não inicia backup; compara a identidade da conexão mysqli do ERP com o cliente MySQL `--no-defaults`, incluindo UUID/socket, e exige `pwa_configuracoes`. O modo `--apply` é separado, requer confirmação TTY exata e lock root-only sem symlink; antes de aplicar, executa backup novo via `erpcondominios-backup.service`, valida manifesto/checksum/gzip pelo serviço de integridade e exige 10 GiB livres. Grava marcador persistente root-only de tentativa antes do SQL, log sanitizado, verifica pós-condições granularmente tenant/RBAC e cria marcador de conclusão. Após qualquer falha/interrupção depois do marcador de tentativa, não reaplique sem diagnóstico/aprovação. O operador deve comprovar externamente SHA/aprovação da release; o estado completo confia no marcador e não revalida semântica integral dos tipos/checks a cada `--check`. Não usar phpMyAdmin, cron ou CI para esta migration.

Se houver erro durante SQL, pode existir DDL parcial mesmo que o runner tente remover somente a procedure temporária. Pare e faça diagnóstico read-only; nunca reaplique automaticamente, faça DROP/TRUNCATE ou restaure o backup sobre o banco ativo. A migration não foi aplicada durante a preparação do runner; execução e estado atuais estão registrados abaixo e no runbook operacional.

### Execução Chat-In v2 — 2026-11-15

O PR #14 foi mesclado em `main` no commit `7c834ff90905857c58431855d02d432cef91963f`. A release imutável desse SHA foi ativada em `/var/www/erpcondominios/current`; a release anterior `3f78734566c9d2ceac38e265e72f7cb79a2a4e90` foi preservada para rollback. A migration foi aplicada uma única vez, após `--check` passar e confirmação TTY literal. O `--check` posterior indicou `COMPLETA`; MySQL 8.4.13, Nginx, PHP-FPM e MySQL foram confirmados ativos.

O runner criou backup novo e a verificação reportou sucesso; `gzip -t`, manifesto SHA-256 e modo `0600` foram verificados, com integridade recente. Esse resultado não substitui restore drill nem cópia off-host, que seguem pendentes. Smoke não autenticado passou para raiz/login, Portal do Morador e seus dois logins alternativos, assets do widget/Configuração Chat-In e API sem sessão (401 esperado). QA autenticado do widget/configuração, teste FCM real e teste Flutter não foram executados.

Causa do `301` em `/config_chatin`: o snippet Nginx instalado estava defasado e não tinha a rota, embora o release/catalog source a contivesse. Sem o allowlist regex, a requisição caía no `location /`, fazia fallback para `/login.html` e a location exata legada enviava `301 /`. O snippet foi substituído pelo arquivo gerado do mesmo SHA da release, com backup root-only e rollback disponível; `nginx -t`, reload e smoke após estabilização passaram. Testes locais com `--noproxy` e testes públicos via DNS confirmaram `200` para `/config_chatin`, `/` e a rota legada de layout. O runbook operacional mantém causa, backup e evidências detalhados.

## PostgreSQL de desenvolvimento — preparação P1 parcial (2026-11-15)

O cluster PostgreSQL 18.6 `18/main` foi iniciado localmente em `2.29.161.106`, com `listen_addresses=localhost`, somente loopback e porta 5432. O perfil de desenvolvimento é `shared_buffers=512MB`, `effective_cache_size=2GB`, `work_mem=8MB`. Foi criado apenas o banco vazio `erp_pg_dev` (UTF-8, dono `erp_owner`, timezone `America/Sao_Paulo`), com schemas `erp`/`legado` e collation ICU não determinística `ci`; nenhum dump, backup ou dado ERP foi lido/carregado. A configuração PostgreSQL original recebeu cópia root-only modo `0600` antes do ajuste.

O monitoramento de memória por mais de cinco minutos permaneceu acima de 5,5 GB disponíveis, PSI `avg10=0.00`; o PostgreSQL dev ficou ativo e serviços MySQL, Nginx e PHP-FPM de produção permaneceram ativos. `nginx -t` passou; smoke local confirmou `/` = 200 e `/frontend/login.html` = 301 para `/`.

P1 ainda não está aceito/completo: o backup diário só poderá ser copiado para `erp_pg_source` entre 23h–06h BRT, verificado e carregado com `--single-transaction`; não ler o banco de produção `erpcondominios` pelo pipeline. `php8.3-pgsql` está instalado e o `conf.d` do FPM atual inclui `pgsql`/`pdo_pgsql` **e** `mysqli`/`pdo_mysql`/`mysqlnd`; há somente `php8.3-fpm.service` e o pool `www.conf`. Portanto, isto não é runtime PG isolado. `pg_reader`, segunda instância PHP-FPM sem módulos MySQL, camada PHP de compatibilidade, vhosts `8443`/`8444`, Basic Auth e regras temporárias de firewall ainda não foram preparados. Não foram alterados configuração/serviços de produção, Nginx, UFW, DNS, releases ou `current`. Como não foi capturada uma baseline de checksums antes do P1, o critério de igualdade de checksums de produção antes/depois ainda não está demonstrado. Prosseguir com o FPM/vhosts somente depois que a camada de compatibilidade e seu teste diferencial estiverem revisados; qualquer ação de produção continua gate separado.


## Release PostgreSQL isolado — vhost de revisão 8443 (2026-11-18)

Este release serve exclusivamente ao teste manual do PostgreSQL; não é deploy da aplicação de produção. O vhost escuta somente em `127.0.0.1:8443`, mantém TLS e Basic Auth, e não recebeu regra UFW. Produção segue MySQL: `/var/www/erpcondominios/current` permaneceu na release `7c834ff90905857c58431855d02d432cef91963f`; não houve alteração no pool PHP padrão, no vhost de produção, DNS, crons ou bancos ativos/rollback.

Release imutável ativo: `/var/www/erpcondominios-pg/releases/ca4135c0b9cc891dcb80773e9337ca642dc05032`, com árvore combinada de `main` (`8a094acb2e1968ab92109a6e77faba16494fb7b5`) + `pg/integracao` (`45a1de72725b5e1f1563e915a1807a0baced335b`). `current` desse root isolado aponta ao release; rollback isolado: `/var/www/erpcondominios-pg/releases/0578cf3ba43b79376e86ccf746d4d86965912af8`. O pacote passou manifesto SHA-256, `nginx -t` e `php-fpm8.3 -t`; depois da troca atômica, somente `php8.3-fpm-pg` e Nginx foram recarregados. O pool PG usa configuração privada opt-in `ERP_DB_DRIVER=pgsql`, schema `erp`, alvo `erp_pg_dev`, tradutor fail-closed e integrações de saída desligadas. Não registrar os valores dessa configuração.

O certificado TLS de domínio que já serve o vhost produtivo foi referenciado pelo vhost 8443 (sem copiar nem imprimir chave); o hostname `app.erpcondominios.com.br` valida a cadeia e o SAN. Para chegar ao listener loopback mantendo o certificado confiável, o operador deve fazer o túnel SSH na máquina de teste, com sua chave autorizada:

```powershell
ssh -i "$HOME\.ssh\manus_erp_hetzner" -N -o ExitOnForwardFailure=yes -L 8443:127.0.0.1:8443 manus-admin@2.29.161.106
```

Com o túnel aberto, adicionar temporariamente, como administrador, esta linha ao arquivo `C:\Windows\System32\drivers\etc\hosts`:

```text
127.0.0.1 app.erpcondominios.com.br
```

Abrir `https://app.erpcondominios.com.br:8443/` e, depois do teste, remover a linha de `hosts` e encerrar o túnel. Isso altera apenas a resolução local durante o teste, não o DNS público. Basic Auth exige usuário `pg-review`; a senha é mantida somente no arquivo root-only `/root/carga-pg/pg-8443-review.pass` (`0600`) e não deve ser enviada pelo chat. O administrador pode obtê-la diretamente em sua sessão SSH autorizada, por exemplo `sudo -n cat /root/carga-pg/pg-8443-review.pass`; não reutilizar a senha Basic como credencial ERP. Usar somente usuários sintéticos de teste, nunca credenciais de moradores/funcionários reais. O login Portal Bearer ainda pode responder 503 no perfil PostgreSQL por contrato P3.2; esse comportamento não deve ser confundido com aceitação do Portal.

Smoke de 18/11, realizado localmente com hostname/domínio e certificado verificado, sem sessão ERP: sem Basic Auth `/` → 401; com Basic `/` → 200 e `<base href="/frontend/">`; `/frontend/login.html` → 301 para `/`; `?expired=1` preservado; `/login.html` → 301; `config.js`, `i18n.js`, JSON `pt-BR` e CSS → 200; API sem sessão → 401; tentativa de caminho Firebase → 404; logins alternativos de morador e fornecedor → 200. O arquivo Basic original foi restaurado após o smoke temporário; a conta `pg-review` é separada e persistente para a revisão manual. Checksums de 134 arquivos/configurações do baseline fora do vhost PG permaneceram iguais. Smoke da produção antes/depois: raiz 200, login legado 301, API anônima 401; `current` produtivo permaneceu na mesma release. Não foi feito login autenticado real, não se testou comportamento funcional de módulo/Portal, e o smoke não remove a pendência P2 da FK `42804` nem substitui reconciliação integral.

Para rollback da release isolada, sem tocar produção:

```bash
sudo bash -c 'set -e; tmp=/var/www/erpcondominios-pg/.current-rollback-$$; ln -s /var/www/erpcondominios-pg/releases/0578cf3ba43b79376e86ccf746d4d86965912af8 "$tmp"; mv -Tf "$tmp" /var/www/erpcondominios-pg/current; systemctl reload php8.3-fpm-pg; systemctl reload nginx'
```

Se for necessário reverter também o certificado/ajustes específicos do vhost, restaurar somente o backup isolado `/root/carga-pg/8443-release-backup-20261118T225054Z/pg-vhost-before-cert-fix.conf`, executar `nginx -t` e recarregar Nginx; não restaurar configuração de produção como parte desse rollback.
Smoke de 18/11, realizado localmente com hostname/domínio e certificado verificado, sem sessão ERP: sem Basic Auth `/` → 401; com Basic `/` → 200 e `<base href="/frontend/">`; `/frontend/login.html` → 301 para `/`; `?expired=1` preservado; `/login.html` → 301; `config.js`, `i18n.js`, JSON `pt-BR` e CSS → 200; API sem sessão → 401; tentativa de caminho Firebase → 404; logins alternativos de morador e fornecedor → 200. O arquivo Basic original foi restaurado após o smoke temporário; a conta `pg-review` é separada e persistente para a revisão manual. Do manifesto de baseline (135 arquivos), 133 configurações não relacionadas ao vhost/Basic PG mantiveram o checksum; as duas diferenças esperadas foram o vhost 8443 e o arquivo htpasswd PG, ambos do ambiente de teste. O pool PHP-FPM PG ficou inalterado; o arquivo privado de configuração do driver PG não constava no manifesto e foi verificado separadamente, sem divulgar valores. Smoke da produção antes/depois: raiz 200, login legado 301, API anônima 401; `current` produtivo permaneceu na mesma release. Não foi feito login autenticado real, não se testou comportamento funcional de módulo/Portal, e o smoke não remove a pendência P2 da FK `42804` nem substitui reconciliação integral.


## Runbook P5 — virada MySQL → PostgreSQL (planejamento, não executar)

**Estado:** plano apenas. Nenhum congelamento, backup final, target definitivo, troca de driver, DNS ou cutover foi executado/autorizado nesta rodada. Produção continua MySQL em `erpcondominios_stage_20261114`; `erpcondominios` permanece o rollback antigo. O `pg_reader` não terá grant em nenhum dos dois.

**Evidência de 19/11:** o refresh de `erp_pg_source` a partir do backup diário passou. A medição disponível cobre somente o trecho de restore, com 30 s e `MemAvailable` mínima de 3.411.900 KiB; isso não é duração end-to-end e não deve ser usado como tal. O runner imutável instalado ainda era catálogo v7 e bloqueou no preflight com 74 reviews. A versão WIP v8 da branch traduziu os dois CHECKs; o bundle diagnóstico v8d tem 64 reviews textuais e `executable_complete=false`. Nenhuma versão v8 do rebuild/load, reconciliação ou trigger pós-carga foi executada.

### Horários propostos (BRT; data a ser combinada com André)

- **21:30 — T−30 min, go/no-go técnico:** confirmar janela e responsável; conferir espaço, serviços, integridade do backup diário, saúde de MySQL/PostgreSQL, release/hash aprovado, target P5 e plano de rollback. Sem todos os itens, não iniciar.
- **22:00 — T0, congelamento:** colocar aplicação em manutenção/503 controlado, confirmar bloqueio de novos writes, pausar somente os jobs de negócio identificados e confirmar transações ativas encerradas. A inspeção de 19/11 encontrou zero crontabs ativos de usuários e nenhum cron de negócio ERP; os timers `erpcondominios-backup.timer`, `erpcondominios-backup-integrity.timer` e `erpcondominios-healthcheck.timer` são operacionais e não devem ser parados por padrão. O timer `erpcondominios-summary.timer` estava desativado. Revalidar no horário; não assumir estado estático. Ainda não há mecanismo de manutenção implantado/revisado neste plano: implementar/testar antes da janela.
- **22:10 — T+10 min, backup final:** gerar dump consistente do schema ativo `erpcondominios_stage_20261114` em `/var/backups/erpcondominios/mysql-final/` com diretório root `0700`, arquivo e manifesto `0600`; produzir SHA-256 e passar `gzip -t`; validar cabeçalho e contagem de objetos, sem imprimir linhas. O script diário atual grava em `daily/`, não substitui o runner `mysql-final` dedicado. Não improvisar comando/credencial na noite: um runner final root-only que fixa o schema ativo e o diretório precisa ser revisado e testado antes.
- **22:20 — T+20 min, refresh do clone:** conferir novamente hash/gzip/cabeçalho e, na janela, restaurar somente `mysql-final` para `erp_pg_source`, usando o transformador aprovado (reescrita do schema para o clone e dos sete DEFINERs para `pg_reader@localhost`), `nice -n 19`, `ionice -c3`, lock exclusivo, guard de 1,5 GiB e logs root `0600`. O refresh de 19/11 já passou fora da janela; seu restore medido em 30 s cobre apenas esse trecho e não representa o tempo total. Pós-check agregado esperado: 276 tabelas, 7 triggers, 0 views/routines/events; grant do leitor limitado ao clone. Se falhar, preservar logs/backup, manter manutenção, abortar a troca e voltar ao MySQL.
- **22:35 — T+35 min, execução única:** rodar `rebuild.sh cutover load <bundle> <source-defaults-file>` no SHA/release P2 aprovado; a origem permanece exclusivamente `erp_pg_source`. O target PostgreSQL definitivo deve estar previamente provisionado, isolado, aprovado em arquivo root-only `0600` e amarrado ao marker one-shot `0600`. O perfil cutover recusa os bancos protegidos e não lê produção. Executar reconstrução e reconciliação no mesmo runner; não repetir no target definitivo.
- **Até 23:15 — T+75 min, GO/NO-GO:** só avançar com 100% das tabelas/linhas, hashes por linha após normalizações aprovadas, somas numéricas/lengths, IDs máximos/sequences, distribuição tenant, 11/11 FKs e zero órfãos, sete triggers habilitadas e neutralização operacional revisada. Qualquer diferença, falha, review pendente ou relatório parcial = **NO-GO**, manter manutenção, não trocar conexão e restabelecer serviço MySQL.
- **23:15–23:30 — troca controlada:** somente após aceite do gate, fazer backup/checksum root-only da configuração privada atual, trocar o driver/config para PG por comutação atômica do arquivo aprovado e manter o hostname/DNS inalterado. Smoke autenticado ERP/super-admin/morador/Portal Bearer, APIs essenciais, módulos críticos, uploads/PDFs e jobs permitidos; somente então remover manutenção/liberar writes.

Os horários acima são uma proposta de agenda, não promessa de duração. Substituí-los por medições ponta a ponta de refresh + carga + triggers + reconciliação quando o ensaio de desenvolvimento estiver desbloqueado, além do tempo do restore final; os 30 s medidos no restore de `erp_pg_source` são parciais e não servem como tempo end-to-end. Reservar janela com folga mínima de 2× a maior duração observada. O servidor está em BRT−3; registrar todos os marcos também em UTC.

### Comandos conceituais e gates

```bash
# T−30: estado e backup final (somente após implementação/revisão do runner dedicado)
sudo systemctl --no-pager status mysql postgresql nginx php8.3-fpm
sudo /usr/local/sbin/erpcondominios-mysql-final-backup --schema erpcondominios_stage_20261114 --output-dir /var/backups/erpcondominios/mysql-final
sudo sha256sum -c /var/backups/erpcondominios/mysql-final/<arquivo>.sql.gz.sha256
sudo gzip -t /var/backups/erpcondominios/mysql-final/<arquivo>.sql.gz

# Depois do restore verificado exclusivamente em erp_pg_source e do bundle totalmente aprovado:
sudo /var/www/erpcondominios-pg/releases/<SHA>/tools/migracao_pg/rebuild.sh cutover preflight <bundle> /etc/erpcondominios/secrets/pg_reader.cnf
sudo /var/www/erpcondominios-pg/releases/<SHA>/tools/migracao_pg/rebuild.sh cutover load <bundle> /etc/erpcondominios/secrets/pg_reader.cnf
```

`<SHA>` deve ser substituído pela release imutável P2 validada para a janela; já existe uma release versionada sob `/var/www/erpcondominios-pg/releases/`, mas o target/configuração/marker de cutover ainda não foram provisionados ou aprovados. `/usr/local/sbin/erpcondominios-mysql-final-backup` continua sendo um placeholder: o runner dedicado não existe. Não copiar os comandos para uma sessão de produção antes de concluir os gates e validar os arquivos privados. O `rebuild.sh` mantém `development` fixo em `erp_pg_dev`; `cutover` só aceita o banco definitivo explicitamente aprovado por arquivo e marker root-only one-shot. Relatórios do runner permanecem em `/var/backups/erpcondominios/p2-runs/`, modo root `0700`; nunca imprimir DSN, senha, linhas ou conteúdo de log.

### Rollback

Antes de retirar manutenção, qualquer falha do preflight, load, reconciliação, `nginx -t`, conexão de aplicação ou smoke é **NO-GO**. Manter a manutenção, não liberar novos writes, preservar `mysql-final/`, e reverter de forma atômica a configuração privada para o backup MySQL capturado em T−30; apontar `current` para a release MySQL previamente registrada; recarregar apenas os serviços de aplicação necessários e confirmar, como `www-data`, `mysqli` + `SELECT DATABASE() = erpcondominios_stage_20261114`, raiz/login/API e crons conforme baseline. A sintaxe do comando de rollback precisa ser transformada em runner root-only de um comando e testada antes da janela; o path real do `ERP_CONFIG_PATH` de produção ainda deve ser confirmado. Não apagar nem escrever no MySQL ativo/rollback. Se já houve escrita de negócio em PostgreSQL após a liberação, parar e convocar André: retorno cego ao MySQL perderia essas gravações; exige plano separado de reconciliação.

**Pontos de bloqueio hoje:** mecanismo de manutenção não preparado; runner de backup final ainda precisa ser criado/testado; target PostgreSQL definitivo e marcador one-shot não provisionados; runner development imutável instalado é v7 e seu preflight bloqueou com 74 reviews; o bundle diagnóstico v8d contém 64 defaults textuais ainda não aprovados, portanto rebuild/load/reconciliação/triggers pós-carga ainda não ocorreram; path da configuração privada de produção e comando atômico de rollback precisam ser validados. Nenhum desses itens foi contornado.
