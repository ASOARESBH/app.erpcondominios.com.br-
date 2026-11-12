# Runbook Hetzner — ERP Condomínios HML

## Limites de operação

Este host serve HML pelo IP `2.29.161.106`. Não altera DNS, HostGator ou produção. MySQL 8.4 permanece somente em socket/localhost; nunca liberar 3306. Não modificar SSH, fail2ban, UFW ou AppArmor neste runbook. Integrações externas na HML permanecem neutralizadas. O ZIP de `uploads/` e `config/firebase/` ainda precisa ser entregue; não preencher esses diretórios com dados de produção até autorização.

## Release da aplicação

A fonte é exclusivamente `ASOARESBH/app.erpcondominios.com.br-`, branch `main`. Criar um diretório novo `/var/www/erpcondominios/releases/<SHA>`, transferir apenas os arquivos rastreados no SHA aprovado, não extrair sobre uma release existente e manter o código `root:www-data`, sem permissão de escrita para PHP. `current` é um symlink atômico para uma release. Mantenha cada release anterior para rollback e nunca inclua `.git`, segredos ou dados de upload no document root.

A configuração privada DB reside fora do webroot em `/etc/erpcondominios/erp_config.php`, `root:www-data 0640`; o segredo é mantido root-only em `/etc/erpcondominios/secrets/`. Não imprimir, versionar, transferir por chat ou copiar para logs. O host recebe o código de `main`; os scripts deste diretório documentam infraestrutura e não substituem o código de aplicação.

## Nginx e TLS de IP

A configuração de site é `nginx-ip.conf`, instalada como `/etc/nginx/sites-available/10-erpcondominios-ip.conf`; cópias de rollback locais preservam configurações anteriores. Validar sempre `nginx -t` antes de `systemctl reload nginx`. HTTP redireciona ao HTTPS somente para o IP; o default HTTP existente permanece inalterado. No TLS, o vhost principal serve HML pelo IP e o catchall apresenta o certificado IP e encerra com 444 para `Host` desconhecido. O certificado IP fica sob `/etc/letsencrypt/live/erpcondominios-ip/`, tem validade curta e não é wildcard de domínio. Não emitir certificado para hostname sem autorização DNS.

O vhost serve somente extensões de conteúdo permitidas, PHP existente fora de pastas internas bloqueadas, e HTML estático; extensões não permitidas retornam 404. Dotfiles, `.htaccess`, arquivos de configuração, logs, fontes, `vendor`, testes e diretórios operacionais são bloqueados. PHP enviado não é executado; `/uploads/` encaminha para a API autenticada. Mudanças no artefato precisam ser conferidas contra `origin/main` e testadas com `nginx -t` e smoke de rotas.

Certbot Snap renova por seu timer. O deploy hook executa `nginx -t` e recarrega o serviço após renovação válida. O `renew --dry-run` em staging passou em 12/11/2026. Verifique `systemctl list-timers`, `snap services certbot`, `certbot certificates` e o healthcheck. Certificado IP `shortlived` dura 160 horas; a renovação automática funcionando é requisito operacional. A falha do timer ou do healthcheck é registrada no journal, mas não existe alerta externo automático configurado; alguém precisa acompanhar os status/journals.

## Backup e verificação

Antes de habilitar o timer, provisionar `/var/backups/erpcondominios/daily/` como `root:root 0700`; a unit permite escrita apenas neste diretório e `/run/lock`. O script `backup-db.sh`, instalado como `/usr/local/sbin/erpcondominios-backup.sh`, exporta somente `erpcondominios` via socket local com routines, triggers, events e blobs. O timer diário (06:15 UTC, com atraso aleatório curto) chama o service root-only. O script usa lock, exige pelo menos 10 GiB livres, comprime, testa `gzip -t`, calcula SHA-256 e cria dump/manifesto como `root:root 0600`. A saída do job vai ao journal e não contém linhas do dump. A automação não expurga backups.

`erpcondominios-backup-integrity.service`/`.timer` executa diariamente às 07:00 UTC, após o backup. Valida o `gzip -t`, checksum SHA-256 e os modos `0600`; grava somente um marcador root-only em `/var/lib/erpcondominios/backup-integrity-verified-at`. O healthcheck passivo executado a cada cinco minutos não inicia/reinicia serviços; confere Nginx/PHP-FPM/MySQL, conexão MySQL por socket, HTTPS local, validade TLS, ao menos 10 GiB livres, idade do último backup (até 36 horas) e idade do marcador de integridade. O resultado/falha fica no journal; não há encaminhamento externo de alertas.

Para verificar sem expor conteúdo: `systemctl status erpcondominios-backup.service --no-pager`, `journalctl -u erpcondominios-backup.service`, `gzip -t <arquivo>` e `sha256sum -c <manifesto>` executados dentro do diretório privado. Não usar `cat`, não enviar dump/hash por chat/Git e não redirecionar stdout para arquivos não privados. Antes de qualquer DDL, executar e validar backup novo.

Os dumps são mantidos apenas no mesmo host e não têm retenção/expurgo automático; é um risco residual de perda do volume. O crescimento do espaço deve ser acompanhado até aprovar uma política de retenção que preserve a cópia histórica exigida. Uma cópia off-host criptografada e um exercício de restore são pendentes. Nenhum teste de restauração deve sobrescrever `erpcondominios`, bancos anteriores ou produção: o restore drill requer schema/VM temporário novo e isolado e transformação explicitamente testada; não improvisar `sed` sobre o dump. Até passar esse gate, o estado é “backup validado; restore drill e cópia off-host pendentes”.

## Logs

`logrotate` nativo é executado pelo timer do sistema. `erpcondominios-mysql.logrotate` mantém oito rotações semanais de error/slow logs; arquivos novos ficam `mysql:mysql 0600` porque consultas lentas podem carregar valores. A regra chama `mysqladmin flush-logs` por socket. `general_log=OFF`. Nginx e PHP-FPM usam as regras nativas; logs do sistema e dos timers ficam no journal. Não copiar linhas brutas para Git ou relatório.

## Rollback e critérios de parada

Rollback de código: apontar `current` para o symlink de release anterior preservada e validar Nginx. DDL H3 é additivo; rollback de banco requer restaurar o backup pré-DDL em uma cópia isolada ou desenvolver rollback explícito, nunca apagar colunas/tabelas automaticamente. Parar e pedir aprovação antes de DML migration, mudança em HostGator/DNS, integração externa real, exclusão de dados não temporários ou merge em `main`.

## Estado em 12/11/2026

H2 foi aceita; H3 aplicou e reconciliou apenas DDL estrutural idempotente aprovado. H4 responde por HTTPS no IP, sem DNS. Smoke não autenticado passou; login funcional, uploads, Firebase e QA completo dependem de conta de teste autorizada e dos ZIPs. Backup e healthcheck passaram após atualização e o dry-run Certbot staging também; não houve restore drill nem cópia off-host. PostgreSQL e artefatos `pg/*` continuam pausados/preservados.
