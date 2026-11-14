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

## 7. Hetzner — HML MySQL lift-and-shift (2026-11-12)

A decisão operacional é hospedar a aplicação atual sobre MySQL 8.4.13; o projeto PostgreSQL fica pausado. A VM possui MySQL local-only em `127.0.0.1:3306`/socket, `NO_ENGINE_SUBSTITUTION`, timezone `-03:00`, servidor `utf8mb4`/`utf8mb4_unicode_ci`, binlog com retenção de 7 dias, slow log ativo e Event Scheduler desligado. PHP `mysqli` autenticou por socket com `erp_app`; a porta 3306 não tem regra UFW.

H2 foi aceita em 12/11: a evidência root-only registra 270 tabelas, 202.609 linhas reconciliadas em 270/270 tabelas, 7 triggers, 2 procedures, zero definers incorretos, zero divergências e zero erros de importação. A base `erpcondominios` continua sendo HML; integrações externas nela permanecem neutralizadas. Preservar os logs privados da tentativa anterior, o dump-fonte e os artefatos históricos PostgreSQL.

H3 comparou as 54 migrations de `origin/main` com catálogo `INFORMATION_SCHEMA` do HML. Foram aplicadas somente as lacunas estruturais idempotentes comprovadas: cinco tabelas `alertas_acesso_*`, a coluna `abastecimento_lancamentos.observacao`, as colunas/índices únicos `client_uuid` de `leituras` e `leituras_fotos`, e os índices `os_chamados.idx_origem_portal`/`idx_morador_id`. O script reproduzível MySQL 8.4 é `sql/deploy/hetzner_cutover_migrations.sql`; usa guards por `INFORMATION_SCHEMA` e SQL preparado, sem DML, DROP, TRUNCATE ou valores de integração. Pré-backup lógico e reconciliação das contagens foram registrados root-only na VM.

H3 permanece **PARCIAL**: migrations mistas/não idempotentes não foram executadas; qualquer DML aguarda autorização. `assembleias.tenant_id` e `idx_assembleias_tenant_data` já existem, com zero assembleias nulas, então não houve backfill. `migration_consolidar_erpserra_para_erpcondor_mysql57.sql` segue desativada e não executada. A v2 de notificações também não foi aplicada: `protocolo_id` continua `NOT NULL`; existem 643 linhas e zero duplicidades na chave candidata.

H4 foi publicada por IP `2.29.161.106` em HTTPS, sem mudar DNS, HostGator, firewall, SSH, fail2ban ou AppArmor. A release é `origin/main` em `f60739713e6de3f2473b79ecd14a70fd700e5462`, root-owned e imutável; `current` aponta para ela. A configuração privada `/etc/erpcondominios/erp_config.php` é `root:www-data 0640`; o segredo DB permanece root-only. O certificado Let's Encrypt de IP `shortlived` dura 160 horas; Certbot Snap 5.10.0, timer e deploy hook estão ativos, e o dry-run de renovação em staging passou.

Nginx redireciona HTTP para HTTPS e serve HML pelo Host IP; o catchall HTTP existente não foi alterado e o catchall TLS retorna 444 a Hosts desconhecidos. O vhost bloqueia dotfiles, configuração, fontes, testes, logs, SQL e extensões não públicas; caminhos legados `uploads/` são encaminhados à API tenant-aware. O ZIP de `uploads/` e `config/firebase/` ainda não foi recebido/importado; `shared/uploads` permanece vazio.

H5 smoke não autenticado passou: login HTTPS HTTP 200 com TLS validado, HTTP→HTTPS 301, PHP-FPM responde no endpoint real de login, API protegida retorna 401 JSON e caminhos internos/de teste testados retornam 404. PHP executou `SELECT 1` na HML. Login real, RBAC por sessão, formulários, uploads, PDFs e integrações aguardam conta de teste autorizada e ZIPs; não houve DML nem chamadas externas.

H6: backup lógico diário root-only via systemd está ativo; a execução passou `gzip -t` e verificação SHA-256, modo `0600`, em `/var/backups/erpcondominios/daily/`. O timer diário é 06:15 UTC, lock impede concorrência, exige 10 GiB livres e não há expurgo automático. Unit diária separada valida gzip, SHA-256 e modo `0600`; healthcheck passivo a cada cinco minutos verifica serviços sem iniciá-los, HTTPS/TLS, espaço, frescor do backup e marcador de integridade. Logrotate MySQL é semanal, oito rotações, arquivos novos `mysql:mysql 0600`; `general_log=OFF`. Backups locais sem cópia off-host, restore drill, política de retenção e alerta externo continuam pendentes; falhas ficam no journal. O script resolve `ERP_DB_NAME` do arquivo privado ativo, valida allowlist e existência do schema; validar o novo banco antes da troca e preservar os backups anteriores.

Estado: `H1_OK / H2_ACCEPTED / H3_PARTIAL / H4_IP_TLS_OK / H5_SMOKE_OK_AUTH_PENDING / H6_BACKUP_TIMER_OK`. Esta é HML pelo IP, não produção. Não alterar DNS/HostGator, não realizar cutover, não habilitar integrações, não aplicar DML ou migrations mistas, não apagar dados não temporários e não fazer merge em `main` sem autorização.
Nginx redireciona HTTP para HTTPS e serve HML pelo Host IP; o catchall HTTP existente não foi alterado e o catchall TLS encerra a conexão para Hosts desconhecidos (código interno 444). O vhost bloqueia dotfiles, configuração, fontes, testes, logs, SQL e extensões não públicas; caminhos legados `uploads/` são encaminhados à API tenant-aware. O ZIP de `uploads/` e `config/firebase/` ainda não foi recebido/importado; `shared/uploads` permanece vazio.
H6: backup lógico diário root-only via systemd está ativo; a execução passou `gzip -t` e verificação SHA-256, modo `0600`, em `/var/backups/erpcondominios/daily/`. O timer é 06:15 UTC; lock impede concorrência e o backup recusa iniciar com menos de 10 GiB livres. O healthcheck passivo de cinco minutos confere serviços sem iniciá-los, HTTPS/TLS, pelo menos 10 GiB livres, frescor até 36 horas, gzip/hash do último backup e modos `0600`. Logrotate MySQL é semanal, oito rotações, arquivos novos `mysql:mysql 0600`; `general_log=OFF`. Não há retenção/expurgo, cópia off-host ou alerta externo automático: restore drill, armazenamento fora do host, política de retenção e monitoramento do journal são pendentes.


## 8. Hetzner HML — alertas SMTP (2026-11-13)

Alertas locais usam `msmtp` sem substituir o MTA do sistema. `/etc/erpcondominios/secrets/msmtprc`, `smtp-password` do Brevo e `titan-app-password` são arquivos `root:root 0600` no diretório `root:root 0700`. TITAN é a conta primária (`smtp.titan.email`, porta 465, SSL/TLS obrigatório); após rejeição/falha conclusiva, o dispatcher tenta a conta Brevo, que usa STARTTLS/TLS conforme sua porta e credencial SMTP isolada. Em timeout ambíguo, não tenta fallback para evitar possível duplicidade e mantém a notificação pendente. Remetente e destinatário ficam fixos em `noreply@erpcondominios.com.br`. Credenciais são lidas via `passwordeval`, nunca por argumento, variável de ambiente ou log.

`/usr/local/lib/erpcondominios/alert-manager.py` é o dispatcher root-only. Ele verifica Nginx, PHP-FPM, MySQL, ping por socket, HTTPS local, validade do certificado (<72 h), espaço livre (<15 GiB), backup (>36 h), marcador de integridade vencido, timer/último resultado do Certbot, falhas das units de backup/integridade/healthcheck, credencial TITAN e prontidão da configuração de transporte. O estado por item fica root-only em `/var/lib/erpcondominios/alerts-state.json`; alertas do mesmo item têm cooldown de 60 min, e uma recuperação notifica uma vez quando um relay aceita a mensagem. As falhas pendentes são repetidas com backoff de 15 min. Journald recebe apenas IDs, categorias e sucesso/falha, nunca stderr do msmtp.

`erpcondominios-summary.timer` envia resumo às 08:00 `America/Sao_Paulo` após credenciais aprovadas e timer habilitado. O corpo inclui somente status, horários, contagens, tamanhos agregados, validade do certificado, disco livre, uptime e total agregado de IPs banidos pelo Fail2ban. Backup, integridade e `snap.certbot.renew.service` acionam `OnFailure` sem copiar linhas do journal para o e-mail. A unit de healthcheck termina com sucesso após processar todos os itens, e cada falha gera seu próprio alerta sem classificação genérica duplicada. Para instalar a app password TITAN via TTY, usar `/usr/local/lib/erpcondominios/set-titan-app-password.py`; em seguida validar a configuração dual com `configure-msmtp-failover.py`. O teste manual `systemctl start erpcondominios-alert-test.service` exige arquivo TITAN instalado; a simulação isolada `sudo -n /usr/bin/python3 /usr/local/lib/erpcondominios/alert-manager.py simulate-cycle` não altera disco nem serviço real.

**Gate operacional 2026-11-13:** o teste anterior do Brevo foi recusado em autenticação. André aprovou TITAN como primário e Brevo como fallback. Os quatro scripts operacionais de alertas foram revisados, passaram 21 testes unitários offline e foram instalados atomicamente na VM a partir do commit `6cdca818b99984042f4bf9f75b711edf7b3b8a59`; cópias anteriores root-only foram preservadas. A app password TITAN ainda precisa ser criada pelo usuário e instalada via TTY, sem chat; a credencial Brevo não foi validada e não será substituída por API key. A VM permanece com o `msmtprc` antigo de conta única; o conversor para duas contas ainda não foi executado. Nenhum dos três e-mails foi aceito até agora. `erpcondominios-summary.timer` permanece desabilitado, enquanto o healthcheck continua ativo. Não declarar alertas funcionais até a configuração dual, o teste SMTP e a simulação alerta/normalizado serem aceitos.

O status `accepted` significa que um dos relays SMTP aceitou a submissão; não comprova que o destinatário visualizou ou recebeu na caixa postal. Não registrar corpo, credencial, header SMTP bruto, hashes, dados de negócio ou stderr/debug do cliente em log, relatório ou Git. Antes do uso, confirmar modes/owner dos três arquivos de segredo e do diretório, TLS e envio real controlado. Não alterar DNS, HostGator, UFW, SSH ou Fail2ban para configurar o e-mail.


## Estado de import e hostname — 2026-11-14

O dump de produção mais recente foi importado isoladamente em `erpcondominios_stage_20261114` (275 tabelas) e promovido logicamente pela alteração atômica da configuração privada ativa. A configuração anterior está preservada em rollback root-only, e o schema antigo `erpcondominios` permanece intacto. Contagens agregadas: 204.965 linhas no schema novo e 202.630 no anterior; 27 tabelas diferem. Nenhum dado de negócio foi exposto. PHP-FPM/MySQL/Nginx permanecem ativos; PostgreSQL 18 permanece inativo. Backup pós-switch e integridade retornaram sucesso.

O A de `app.erpcondominios.com.br` ainda aponta ao IP antigo. O cPanel recusou salvar a edição individual por TXT do apex com TTLs inconsistentes (3600/300); nenhuma mudança em DNS ocorreu e MX/Titan continuam intactos. Não alterar TXT sem autorização específica.

A branch de infraestrutura prepara o nome no vhost HTTP para ACME e um vhost TLS separado com regras equivalentes ao vhost IP. Estes arquivos ainda não foram instalados. Após resolver o gate DNS: validar e instalar o vhost HTTP, alterar somente o A autorizado, confirmar propagação, emitir certificado HTTP-01, ativar TLS separado, validar SNI/SAN, redirect, login/assets, PHP e bloqueios, e executar `certbot renew --dry-run`. Não considerar o cutover do hostname concluído antes desses testes.


## Estado confirmado do hostname Hetzner — 2026-11-14

O registro `app.erpcondominios.com.br A` foi atualizado para `2.29.161.106` (TTL 14400) e confirmado nos dois nameservers autoritativos e em resolvedores públicos. Para ultrapassar a validação global do cPanel, foi alterado somente o TTL de um TXT existente no apex de 300 para 3600 segundos, sem mudar seu conteúdo. MX/Titan, `www.app.erpcondominios.com.br` e os demais registros permaneceram intocados; não há AAAA publicado para `app`.

O vhost HTTP continua aceitando o HTTP-01 no webroot `/var/www/letsencrypt` e redireciona a raiz para HTTPS. Foi emitido certificado Let's Encrypt específico com SAN `app.erpcondominios.com.br`; o vhost TLS está em arquivo separado. `nginx -t` e reload passaram, preservando o vhost/certificado por IP e o catchall. `certbot renew --dry-run` passou e `snap.certbot.renew.timer` está enabled/active.

Smoke público não autenticado pelo domínio e pelo IP passou para login, CSS/imagens, página de direitos, redirecionamento HTTP→HTTPS, API PHP protegida (401 JSON) e bloqueio de caminhos internos. Nenhuma credencial foi submetida e nenhum DML foi executado. A tela de login raiz ainda solicita `/js/config.js` (404); os fluxos de login usam URLs absolutas e o arquivo versionado `/frontend/js/config.js` responde, por isso o achado não bloqueia o smoke, mas precisa ser corrigido em branch própria.

O backup do schema ativo pós-switch passou `gzip -t`, verificação pelo manifesto SHA-256, proprietário/modo root `0600`; o serviço de integridade atualizou o marcador. O schema ativo continua com 275 tabelas e MySQL local-only; PostgreSQL 18 continua pausado. A entrega completa em produção ainda depende da revisão das integrações de produção, dos ZIPs ausentes de uploads/Firebase, de QA autenticado autorizado e da conclusão dos alertas SMTP. Não declarar os alertas e resumo diário ativos: TITAN app-password falta e `erpcondominios-summary.timer` permanece inativo.

O healthcheck corrigido foi revisado e publicado na branch `infra/hetzner-go-live` pelo commit `16d477097c5311e6aa17da77bedcac9d97b6b50a`, com 22 testes aprovados, e instalado na VM com cópia anterior root-only para rollback. O coletor agora marca probes MySQL/HTTPS saudáveis como normais; as únicas condições pendentes são configuração SMTP, falha de entrega anterior e app-password TITAN ausente. Healthcheck, backup e timers Certbot estão ativos; o resumo diário segue inativo. Nenhum teste de e-mail foi enviado nesta etapa.
