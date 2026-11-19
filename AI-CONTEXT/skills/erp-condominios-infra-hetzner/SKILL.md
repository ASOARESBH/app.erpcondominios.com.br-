---
name: "erp-condominios-infra-hetzner"
description: "Use em qualquer tarefa no servidor Hetzner do ERP Condomínios: deploy, release, Nginx, PHP-FPM, bancos, backups, alertas, túnel 8443/8444, smoke ou diagnóstico de produção."
---

# Infra Hetzner — ERP Condomínios

Mapa operacional do servidor de produção. Evita redescobrir o ambiente a cada tarefa. O estado vivo e o histórico ficam em `AI-CONTEXT/DEPLOY.md` e `AI-CONTEXT/DATABASE.md` §18–§22. Leia só a seção que a tarefa precisa. Se algo aqui divergir do servidor, o servidor vale: relate a diferença e atualize o AI-CONTEXT.

## 1. Servidor
- VM Hetzner CPX32, Helsinki, IP `2.29.161.106`, Ubuntu 24.04, swap 4G, fuso `America/Sao_Paulo`.
- Acesso só por chave SSH (usuários `erpadmin` e `manus-admin`). Fingerprint do host: `SHA256:4q+ypYuD47tUP4AAN44+jmFQKbe8AwNrf7gwyhO50n8`.
- UFW libera apenas 22, 80 e 443. fail2ban ativo (journalmatch `_SYSTEMD_UNIT=ssh.service` + `_COMM=sshd`).
- Nunca afrouxe SSH, fail2ban, UFW ou AppArmor. Nunca abra porta de banco.

## 2. Stack
- Nginx 1.24, com os `.htaccess` traduzidos. A raiz serve o login; as rotas limpas vêm do snippet gerado por `deploy/hetzner/generate-clean-page-routes.py`. Sempre rode `nginx -t` antes de reload.
- PHP 8.3-FPM. Produção usa o pool `php8.3-fpm` padrão.
- MySQL 8.4 em localhost: sql_mode `NO_ENGINE_SUBSTITUTION`, `default_time_zone '-03:00'`, collation `utf8mb4_unicode_ci`, `log_bin_trust_function_creators=ON`, binlog 7 dias, slow log.
- PostgreSQL 18.6 em loopback: shared_buffers 512MB, effective_cache_size 2GB, work_mem 8MB, collation ICU `ci` (`und-u-ks-level2`, não determinística).

## 3. Bancos — nunca confundir
| Nome | Papel | Regra |
|---|---|---|
| `erpcondominios_stage_20261114` | MySQL de PRODUÇÃO ativa. O "stage" no nome é histórico, intencional desde o cutover de 14/11 | Nunca é alvo de carga nem de restore. Escrita só pela aplicação |
| `erpcondominios` | MySQL antigo de rollback | Nunca é origem nem alvo |
| `erp_pg_source` | Clone MySQL descartável, restaurado do backup diário | `pg_reader@localhost` tem exatamente SELECT, SHOW VIEW e TRIGGER, só aqui |
| `erp_hml_mysql` | Clone MySQL do ambiente 8444 | Usuário e DEFINER `erp_hml_app@localhost` |
| `erp_pg_dev` | PostgreSQL de teste do 8443 (schemas `erp` e `legado`) | Destrutível; integrações de saída sempre neutralizadas |

## 4. Ambientes web
- Produção: 443. Releases em `/var/www/erpcondominios/releases/<sha>`, com symlink `current`.
- 8443: PostgreSQL. Pool `php8.3-fpm-pg` sem mysqli e com auto_prepend do shim. Releases em `/var/www/erpcondominios-pg/`. Basic Auth com usuário `pg-review`.
- 8444: espelho MySQL com mysqli nativo.
- 8443 e 8444 escutam só em loopback. O acesso é por túnel: `ssh -L 8443:127.0.0.1:8443 -L 8444:127.0.0.1:8444 erpadmin@2.29.161.106`.

## 5. Deploy de release (padrão)
1. Monte a release imutável a partir de um commit identificado. Nunca edite arquivos dentro de `current`.
2. Rode `php -l` nos PHP alterados (`php -n -l` em `api/db/MysqliDropInFunctions.php`), depois `nginx -t` e `php-fpm8.3 -t`.
3. Troque o symlink e recarregue só o pool afetado. Guarde o caminho da release anterior para rollback.
4. Smoke: `/` 200, `/login.html` 301 (URL limpa), API anônima 401. Compare o manifesto de checksums de configuração antes e depois.
5. Deploy em produção exige autorização explícita do André, separada do merge.

## 6. Backups e alertas
- Backup MySQL diário local, com verificação de integridade (SHA-256 + `gzip -t` + cabeçalho do schema).
- Healthcheck e alertas por e-mail de `noreply@erpcondominios.com.br`, com resumo diário.
- O backup final da virada vai em `/var/backups/erpcondominios/mysql-final/`.
- Ainda não há cópia fora do servidor: é pendência conhecida, não invente destino.

## 7. Regras de segurança em toda tarefa
- Segredos ficam só em arquivos root `0600`. Credencial temporária é apagada com `shred -u` ao final.
- Nunca exiba no chat, em log ou em relatório: dados de pessoas, linhas de tabela, senhas, tokens, hashes ou DSN. Relate só contagens, nomes técnicos, tempos e SQLSTATE.
- Dumps nunca vão para o chat nem para o repositório.
- Carga pesada roda com `nice -n 19 ionice -c3`. Se a memória disponível ficar abaixo de 1,5 GiB por mais de 5 minutos, pare e relate.
- Mudança em produção, merge em `main`, DNS ou cutover: só com autorização explícita do André.
- Ao terminar, registre em `AI-CONTEXT/DEPLOY.md` ou `DATABASE.md` e no `CHANGELOG.md` o que mudou, sem apagar histórico.