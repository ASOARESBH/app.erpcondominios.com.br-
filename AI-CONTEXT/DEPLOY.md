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

## PostgreSQL de desenvolvimento — snapshot histórico P1 (2026-11-15; supersedido pelo registro de 16/11)

> O estado abaixo é preservado como histórico de 15/11. Seus bloqueios e pendências operacionais foram atualizados no registro P1 de 16/11 mais adiante neste arquivo; não usar este snapshot como status atual.

O cluster PostgreSQL 18.6 `18/main` foi iniciado localmente em `2.29.161.106`, com `listen_addresses=localhost`, somente loopback e porta 5432. O perfil de desenvolvimento é `shared_buffers=512MB`, `effective_cache_size=2GB`, `work_mem=8MB`. Foi criado apenas o banco vazio `erp_pg_dev` (UTF-8, dono `erp_owner`, timezone `America/Sao_Paulo`), com schemas `erp`/`legado` e collation ICU não determinística `ci`; nenhum dump, backup ou dado ERP foi lido/carregado. A configuração PostgreSQL original recebeu cópia root-only modo `0600` antes do ajuste.

O monitoramento de memória por mais de cinco minutos permaneceu acima de 5,5 GB disponíveis, PSI `avg10=0.00`; o PostgreSQL dev ficou ativo e serviços MySQL, Nginx e PHP-FPM de produção permaneceram ativos. `nginx -t` passou; smoke local confirmou `/` = 200 e `/frontend/login.html` = 301 para `/`.

P1 ainda não está aceito/completo: o backup diário só poderá ser copiado para `erp_pg_source` entre 23h–06h BRT, verificado e carregado com `--single-transaction`; não ler o banco de produção `erpcondominios` pelo pipeline. `php8.3-pgsql` está instalado e o `conf.d` do FPM atual inclui `pgsql`/`pdo_pgsql` **e** `mysqli`/`pdo_mysql`/`mysqlnd`; há somente `php8.3-fpm.service` e o pool `www.conf`. Portanto, isto não é runtime PG isolado. `pg_reader`, segunda instância PHP-FPM sem módulos MySQL, camada PHP de compatibilidade, vhosts `8443`/`8444`, Basic Auth e regras temporárias de firewall ainda não foram preparados. Não foram alterados configuração/serviços de produção, Nginx, UFW, DNS, releases ou `current`. Como não foi capturada uma baseline de checksums antes do P1, o critério de igualdade de checksums de produção antes/depois ainda não está demonstrado. Prosseguir com o FPM/vhosts somente depois que a camada de compatibilidade e seu teste diferencial estiverem revisados; qualquer ação de produção continua gate separado.

## Estado P1 verificado — Hetzner (2026-11-16)

### Identidade dos bancos

O nome “stage” é histórico: `erpcondominios_stage_20261114` é o schema MySQL de produção ativo desde a comutação atômica documentada em “Estado do cutover — 14/11/2026”. O schema `erpcondominios` é o rollback anterior, não a origem da migração.

| Banco/schema | Papel | Estado/limites |
| --- | --- | --- |
| MySQL `erpcondominios_stage_20261114` | Produção ativa | 276 tabelas-base; aprox. 455 MiB em `data_length+index_length`. Não foi escrito nesta etapa. |
| MySQL `erpcondominios` | Rollback antigo | Preservar; nunca usar como origem; nenhuma escrita. |
| MySQL `erp_pg_source` | Cópia isolada de origem | Mesmo dump verificado, cabeçalho indica `erpcondominios_stage_20261114`; 275 tabelas-base e 7 triggers. `pg_reader@localhost` read-only limitado ao schema. |
| MySQL `erp_hml_mysql` | Cópia HTTP de homologação | Restaurada do mesmo dump; 275 tabelas-base e 7 triggers. `erp_hml_app@localhost` tem privilégios somente neste clone. |
| PostgreSQL `erp_pg_dev` | Alvo de desenvolvimento | Schemas `erp`/`legado` existem; zero tabelas de aplicação carregadas. |

O dump gzip teve integridade e SHA-256 verificados; seu cabeçalho identifica o schema ativo acima. A cópia possui 275 tabelas versus 276 no schema atualmente ativo, 7 triggers e zero views/procedures/functions/events. O contrato anterior esperava duas procedures; localizar o artefato ausente antes do aceite P2. Os objetos nos clones não dependem de `DEFINER=erp_app`; o pipeline/rebuild deve ler exclusivamente `erp_pg_source` e implementar rotinas por comportamento verificado. O diretório físico do schema ativo tem birth time 2026-11-14 01:44:40 UTC (2026-11-13 22:44:40 BRT); o runbook atesta a promoção ao papel ativo no cutover de 14/11, mas não identifica o job administrativo que originalmente executou a criação.

### Aplicação e proteção

`/var/www/erpcondominios/current` aponta para `/var/www/erpcondominios/releases/7c834ff90905857c58431855d02d432cef91963f`. O arquivo `REVISION` não existe; o `REVISION` usado é o SHA do nome do release. Com o código desse release, usuário `www-data` e `ERP_CONFIG_PATH` privado de produção, `require api/config.php` + `conectar_banco()` retornaram classe `mysqli` e `SELECT DATABASE()` retornou `erpcondominios_stage_20261114`. A conclusão anterior “driver não é MySQL” foi incorreta: ela testou a presença da constante explícita `ERP_DB_DRIVER` e tratou sua ausência como não-MySQL, embora o código default do ERP seja MySQL. A checagem corrigida abriu a conexão efetiva e confirmou `mysqli`.

O master FPM `8444` é cópia isolada da configuração PHP de produção, com `mysqli` nativa e sem `auto_prepend_file` do shim. O master FPM `8443` é isolado, sem `mysqli`, com o drop-in por auto-prepend. Os vhosts mantêm TLS/Basic Auth, escutam somente em loopback; não há regras UFW para 8443/8444. Testes HTTP devem usar o túnel SSH. Configurações de produção, Nginx principal, FPM de produção, UFW global, DNS, `current`, os dois schemas de produção e seus dados não foram alterados.

Baseline antes/depois: checksums de 8 arquivos de configuração sem mudança. Smoke de produção antes/depois: raiz/IP 200; login legado 301 para `/`; API sem sessão 401. MemAvailable mínimo no restore HML: 1.572.864 KiB; leitura posterior: 4.055.712 KiB. P1 continua **preparada, mas não aceita**: falta executar a matriz HTTP de autenticação/sessão/RBAC em 8444. `erp_p3_runtime` foi removido; `erp_pg_source` permanece origem read-only. Esta preparação não autoriza conexão da produção ao PostgreSQL, deploy, DNS/cutover, migration/carga em `erp_pg_dev` ou merge.
