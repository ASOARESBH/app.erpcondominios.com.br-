#!/usr/bin/env bash
# =============================================================================
# ERP Condomínios — Fase 2.5: base do servidor Hetzner (SEM aplicação publicada)
# Alvo: erp-condominios-app-hel1-01 (Ubuntu 24.04 LTS)
# Executar como root:
#   sudo bash fase2_5_base_servidor.sh 2>&1 | tee /root/fase2_5_$(date -u +%Y%m%dT%H%M%SZ).log
#
# FAZ
#   0. Pré-checagens (fail-closed)
#   1. Swap 4 GB + swappiness 10
#   2. Firewall local UFW: entrada só 22/80/443 (IPv4+IPv6)
#   3. Nginx (Ubuntu) + PHP 8.3-FPM (Ubuntu)
#      - catch-all ativo: qualquer Host recebe 444 (conexão encerrada)
#      - vhost do ERP PREPARADO e validado, mas NÃO habilitado
#        (tradução testada dos 10 .htaccess do repositório)
#   4. PostgreSQL 18 (PGDG), só localhost, banco VAZIO, fuso America/Sao_Paulo
#   5. Teste PDO pgsql isolado do ERP (SELECT 1)
#   6. Diretórios + clone do repositório oficial FORA do webroot
#
# NÃO FAZ
#   Não publica o ERP, não habilita vhost, não emite TLS, não mexe em DNS,
#   não instala MySQL, não importa dados, não altera SSH/fail2ban, não remove
#   o sudo NOPASSWD do manus-admin. Gate 2 / Fase 3 continuam fechados.
# =============================================================================
set -Eeuo pipefail
trap 'echo "[FALHA] linha $LINENO: $BASH_COMMAND" >&2' ERR
export DEBIAN_FRONTEND=noninteractive GIT_TERMINAL_PROMPT=0
# Não herdar umask restritiva da sessão (ex.: 077): arquivos de serviço precisam ser legíveis.
umask 022

REPO_HTTPS="https://github.com/ASOARESBH/app.erpcondominios.com.br-.git"
BASELINE_SHA="f60739713e6de3f2473b79ecd14a70fd700e5462"
PHPV="8.3"
PGV="18"
DB_NAME="erpcondominios"
SRC_DIR="/opt/erpcondominios/source"
CFG_DIR="/etc/erpcondominios"
DATA_DIR="/var/lib/erpcondominios"
WWW_DIR="/var/www/erpcondominios"
BKP_DIR="/var/backups/erpcondominios"

step(){ echo; echo "=================== $* ($(date -u +%FT%TZ))"; }
ok(){ echo "[OK] $*"; }
die(){ echo "[ABORTADO] $*" >&2; exit 1; }

# ---------------------------------------------------------------------------
step "0. Pré-checagens"
[[ $EUID -eq 0 ]] || die "execute como root (sudo)"
. /etc/os-release
[[ "$ID" == "ubuntu" && "$VERSION_ID" == "24.04" ]] || die "esperado Ubuntu 24.04, encontrado $PRETTY_NAME"
[[ "$(hostname)" == "erp-condominios-app-hel1-01" ]] || die "hostname inesperado: $(hostname)"
sshd -t || die "config SSH inválida — não prosseguir"
systemctl is-active --quiet ssh || die "ssh inativo"
for d in "$SRC_DIR" "$WWW_DIR"; do
  if [[ -e "$d" ]] && [[ -n "$(ls -A "$d" 2>/dev/null)" ]]; then
    [[ "$d" == "$SRC_DIR" && -d "$d/.git" ]] || [[ "$d" == "$WWW_DIR" && "$(ls -A "$d")" == "releases" ]] \
      || die "$d já existe e não está vazio — revisar manualmente"
  fi
done
for svc in apache2 mysql mariadb; do
  if systemctl list-unit-files "$svc.service" --no-legend 2>/dev/null | grep -q .; then
    die "$svc presente — revisar antes"
  fi
done
ok "pré-checagens"

# ---------------------------------------------------------------------------
step "1. Swap 4 GB"
if ! swapon --show=NAME --noheadings | grep -q '^/swapfile$'; then
  [[ -e /swapfile ]] && die "/swapfile existe mas não está ativo — revisar"
  fallocate -l 4G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
printf 'vm.swappiness = 10\n' > /etc/sysctl.d/90-erp-swap.conf
chmod 644 /etc/sysctl.d/90-erp-swap.conf
sysctl -q --system
ok "swap: $(swapon --show=SIZE --noheadings | head -1)"

# ---------------------------------------------------------------------------
step "2. Firewall local UFW (complementa o firewall Hetzner)"
apt-get update -q
apt-get install -y -q ufw
grep -q '^IPV6=yes' /etc/default/ufw || sed -i 's/^IPV6=.*/IPV6=yes/' /etc/default/ufw
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment 'SSH'          # SEMPRE antes do enable
ufw allow 80/tcp comment 'HTTP'
ufw allow 443/tcp comment 'HTTPS'
ufw --force enable
ufw status verbose
systemctl restart fail2ban
ok "UFW ativo; fail2ban reiniciado"

# ---------------------------------------------------------------------------
step "3. Nginx + PHP ${PHPV}-FPM"
apt-get install -y -q nginx \
  php${PHPV}-fpm php${PHPV}-cli php${PHPV}-common php${PHPV}-opcache php${PHPV}-readline \
  php${PHPV}-pgsql php${PHPV}-mysql php${PHPV}-mbstring php${PHPV}-curl php${PHPV}-gd \
  php${PHPV}-zip php${PHPV}-intl php${PHPV}-xml php${PHPV}-bcmath \
  git unzip rsync ca-certificates
# php-mysql permanece: o código atual usa mysqli; a camada PostgreSQL da Fase 3
# será testada lado a lado. Nenhum servidor MySQL é instalado aqui.

# ---- PHP: limites de produção
cat > /etc/php/${PHPV}/fpm/conf.d/90-erp.ini <<'EOF'
expose_php = Off
display_errors = Off
log_errors = On
memory_limit = 256M
upload_max_filesize = 32M
post_max_size = 40M
max_execution_time = 120
date.timezone = America/Sao_Paulo
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1
opcache.enable = 1
opcache.memory_consumption = 192
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
EOF
sed 's/^session.cookie_secure.*/session.cookie_secure = 0/' \
  /etc/php/${PHPV}/fpm/conf.d/90-erp.ini > /etc/php/${PHPV}/cli/conf.d/90-erp.ini
chmod 644 /etc/php/${PHPV}/fpm/conf.d/90-erp.ini /etc/php/${PHPV}/cli/conf.d/90-erp.ini

# ---- Pool FPM (8 GB RAM dividida com PostgreSQL)
POOL=/etc/php/${PHPV}/fpm/pool.d/www.conf
sed -i -E \
  -e 's/^;?pm = .*/pm = dynamic/' \
  -e 's/^;?pm.max_children = .*/pm.max_children = 24/' \
  -e 's/^;?pm.start_servers = .*/pm.start_servers = 4/' \
  -e 's/^;?pm.min_spare_servers = .*/pm.min_spare_servers = 2/' \
  -e 's/^;?pm.max_spare_servers = .*/pm.max_spare_servers = 8/' \
  -e 's/^;?pm.max_requests = .*/pm.max_requests = 500/' "$POOL"
grep -q '^env\[ERP_CONFIG_PATH\]' "$POOL" || \
  echo "env[ERP_CONFIG_PATH] = ${CFG_DIR}/erp_config.php" >> "$POOL"

# ---- Nginx: arquivos do ERP (conteúdo testado contra o repositório)
install -d -m 755 /var/www/letsencrypt
cat > /etc/nginx/conf.d/erp-http.conf <<'NGX_EOF'
# ERP Condomínios — contexto http (carregado por /etc/nginx/conf.d/*.conf)
server_tokens off;

# CORS da API: equivale ao "Header set" de api/.htaccess (sobrescreve o que o PHP enviar).
# Vazio = cabeçalho não é enviado (rotas fora de /api/).
map $uri $erp_cors_origin  { ~^/api/ "https://asl.erpcondominios.com.br";               default ""; }
map $uri $erp_cors_methods { ~^/api/ "POST, GET, OPTIONS, PUT, DELETE";                  default ""; }
map $uri $erp_cors_headers { ~^/api/ "Content-Type, Authorization, X-Requested-With";    default ""; }
map $uri $erp_cors_cred    { ~^/api/ "true";                                             default ""; }
NGX_EOF
cat > /etc/nginx/snippets/erp-headers.conf <<'NGX_EOF'
# Equivale a Header set de .htaccess raiz e frontend/.htaccess
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
NGX_EOF
cat > /etc/nginx/snippets/erp-cors-api.conf <<'NGX_EOF'
include snippets/erp-headers.conf;
add_header Access-Control-Allow-Origin      $erp_cors_origin;
add_header Access-Control-Allow-Methods     $erp_cors_methods;
add_header Access-Control-Allow-Headers     $erp_cors_headers;
add_header Access-Control-Allow-Credentials $erp_cors_cred;
NGX_EOF
cat > /etc/nginx/snippets/erp-php.conf <<'NGX_EOF'
# PHP-FPM 8.3. try_files fica na location que inclui este snippet.
fastcgi_split_path_info ^(.+?\.php)(/.*)$;
include fastcgi_params;
fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
fastcgi_param DOCUMENT_ROOT   $realpath_root;
fastcgi_param PATH_INFO       $fastcgi_path_info;
fastcgi_param HTTPS           $https if_not_empty;
fastcgi_pass unix:/run/php/php8.3-fpm.sock;
fastcgi_read_timeout 120s;
fastcgi_buffer_size 32k;
fastcgi_buffers 16 16k;
# Semântica de "Header set" do Apache: o valor do servidor prevalece sobre o do PHP.
fastcgi_hide_header X-Content-Type-Options;
fastcgi_hide_header X-Frame-Options;
fastcgi_hide_header X-Powered-By;
NGX_EOF
cat > /etc/nginx/sites-available/00-catchall.conf <<'NGX_EOF'
# Qualquer Host desconhecido (scanners, IP direto) é descartado.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    location ^~ /.well-known/acme-challenge/ { root /var/www/letsencrypt; default_type text/plain; try_files $uri =404; }
    location / { return 444; }
}
NGX_EOF
cat > /etc/nginx/sites-available/erpcondominios.conf.template <<'NGX_EOF'
# =============================================================================
# ERP Condomínios — vhost Nginx (tradução fiel dos 10 .htaccess do repositório)
# Preparado na Fase 2.5, HABILITADO somente na homologação.
# __ERP_SERVER_NAMES__ e __ERP_ROOT__ são substituídos na instalação.
# TLS: adicionado depois pelo certbot (--nginx).
# =============================================================================
server {
    listen 80;
    listen [::]:80;
    server_name __ERP_SERVER_NAMES__;

    root  __ERP_ROOT__;
    index login.html;                         # DirectoryIndex login.html
    charset utf-8;                            # AddDefaultCharset UTF-8
    autoindex off;                            # Options -Indexes
    client_max_body_size 40m;                 # = post_max_size do PHP

    access_log /var/log/nginx/erp_access.log;
    error_log  /var/log/nginx/erp_error.log warn;

    include snippets/erp-headers.conf;

    # ---- Rotas reescritas (.htaccess raiz, api/controlid, api/controlid/notifications)
    rewrite ^/(new_uhf_tag|new_user_identified|new_card|new_qrcode|device_is_alive)\.fcgi$ /api/controlid/$1.php last;
    rewrite ^/api/controlid/(push|result)$              /api/controlid/$1.php               last;
    rewrite ^/api/controlid/notifications/(dao|door)$   /api/controlid/notifications/$1.php last;
    rewrite ^/dashboard/?$                              /frontend/layout-base.html?page=dashboard? last;
    rewrite ^/projetos/?$                               /projetos_publico.php last;
    rewrite ^/projetos/(\d+)/?$                         /projetos_publico.php?id=$1 last;
    rewrite "^/projetos/([a-z0-9][a-z0-9-]{2,59})/(\d+)/?$" /projetos_publico.php?tenant_slug=$1&id=$2 last;
    rewrite "^/projetos/([a-z0-9][a-z0-9-]{2,59})/?$"       /projetos_publico.php?tenant_slug=$1 last;

    # ---- Let's Encrypt
    location ^~ /.well-known/acme-challenge/ { root /var/www/letsencrypt; default_type text/plain; try_files $uri =404; }

    # ---- uploads/.htaccess: nunca executar script; todo caminho legado vai para a API
    location ^~ /uploads/ {
        location ~* \.(php|php3|php4|php5|phtml|pl|py|jsp|asp|sh|cgi)$ { return 403; }
        location ~* "(^|/)(\.env(\..*)?|[^/]*\.(env|ini|log|bak|backup|old|orig|original|save|tmp|sql|dump|sqlite|zip|tar|gz|rar))$" { return 403; }
        location ~ /\. { return 403; }
        rewrite ^/uploads/(.+)$ /api/api_arquivos_tenant.php?acao=legado&caminho=uploads/$1 last;
        return 404;
    }

    # ---- BLOQUEIOS (ordem importa: regex é avaliada na ordem em que aparece)
    # .git, .htaccess, .env e qualquer arquivo/pasta oculta
    location ~ /\. { return 403; }
    # pastas internas (.htaccess raiz; api/error/.htaccess; config/firebase/.htaccess)
    location ~* ^/(api/(testeAPI|error)|backup|backups|logs?|storage|tmp|temp|config|deploy)(/|$) { return 403; }
    # pastas de documentação/ferramentas (não existem no release; defesa extra)
    location ~* "^/(AI-CONTEXT|md|md |docs|sql|tests|tools|bridge_local|Claude outputs|PHPMailer)(/|$)" { return 403; }
    # config.php, configuration.php, database.php, db.php, settings.php
    location ~* "(^|/)(config(\..*)?|configuration|database|db|settings)\.php$" { return 403; }
    # *debug*, *teste*, *test*, *diagnostico*, *backup*, *corrigida*, *final* .php
    location ~* "(^|/)([^/]*[_-])?(debug|teste|test|diagnostico|backup|corrigida|final)([_-][a-z0-9]+|_[0-9]+)*\.php$" { return 403; }
    # .env, .ini, .log, .bak, .sql, .zip etc. (+ .md, defesa extra)
    location ~* "(^|/)(\.env(\..*)?|[^/]*\.(env|ini|log|bak|backup|old|orig|original|save|tmp|sql|dump|sqlite|zip|tar|gz|rar|md))$" { return 403; }
    # frontend/.htaccess: nenhum PHP dentro de frontend/
    location ~* ^/frontend/.*\.php(/|$) { return 403; }

    # ---- PHP da API (api/.htaccess: CORS + OPTIONS 200; suporta PATH_INFO)
    location ~ ^/api/.+?\.php(/|$) {
        include snippets/erp-cors-api.conf;
        if ($request_method = OPTIONS) { return 200; }
        fastcgi_hide_header Access-Control-Allow-Origin;
        fastcgi_hide_header Access-Control-Allow-Methods;
        fastcgi_hide_header Access-Control-Allow-Headers;
        fastcgi_hide_header Access-Control-Allow-Credentials;
        try_files $fastcgi_script_name =404;
        include snippets/erp-php.conf;
    }
    # ---- Demais PHP (raiz). Inexistente cai em login.html, como no Apache.
    location ~ \.php(/|$) {
        try_files $fastcgi_script_name /login.html;
        include snippets/erp-php.conf;
    }

    # ---- frontend/.htaccess: cache
    location = /frontend/layout-base.html {
        include snippets/erp-headers.conf;
        add_header Cache-Control "no-cache, no-store, must-revalidate";
        add_header Pragma "no-cache";
        add_header Expires "0";
        try_files $uri =404;
    }
    location ~* ^/frontend/.+\.(css|js)$  { include snippets/erp-headers.conf; expires 30d; try_files $uri =404; }
    location ~* ^/frontend/.+\.(png|jpe?g)$ { include snippets/erp-headers.conf; expires 1y; try_files $uri =404; }
    location ~* ^/frontend/.+\.html$      { include snippets/erp-headers.conf; expires 1h; try_files $uri =404; }

    # ---- Estáticos: se não existir, 404 (não cai no login)
    location ~* \.(gif|jpg|jpeg|png|css|js|svg|ico|woff|woff2|ttf|eot|json|xml)$ { try_files $uri =404; }

    # ---- /api/ e /frontend/ sem arquivo: 404 (não cai no login)
    location /api/ {
        include snippets/erp-cors-api.conf;
        if ($request_method = OPTIONS) { return 200; }
        try_files $uri $uri/ =404;
    }
    location /frontend/ { try_files $uri $uri/ =404; }

    # ---- Qualquer outra rota: arquivo, pasta ou login.html (SPA fallback do .htaccess raiz)
    location / { try_files $uri $uri/ /login.html; }
}
NGX_EOF

# Lista de exclusão para os futuros releases (nada disso vai para o webroot).
# Obs.: config/firebase e as pastas de log/fotos serão links para ${DATA_DIR}
# (mapeamento de diretórios graváveis é tarefa da fase de deploy).
install -d -m 750 -o root -g www-data "$CFG_DIR"
cat > "$CFG_DIR/deploy-exclude.txt" <<'EOF'
.git/
.github/
.claude/
AI-CONTEXT/
Claude outputs/
md/
md */
docs/
sql/
tests/
tools/
bridge_local/
uploads/
*.md
*.bak
config/erp_config.php
EOF
chmod 644 "$CFG_DIR/deploy-exclude.txt"

rm -f /etc/nginx/sites-enabled/default
ln -sfn /etc/nginx/sites-available/00-catchall.conf /etc/nginx/sites-enabled/00-catchall.conf

# Valida o vhost do ERP SEM habilitá-lo (config de teste isolada)
VT=$(mktemp -d)
sed -e 's#__ERP_SERVER_NAMES__#erp-validacao.invalid#' -e "s#__ERP_ROOT__#${WWW_DIR}/current#" \
    -e 's#listen 80;#listen 127.0.0.1:18080;#' -e '/listen \[::\]:80;/d' \
    /etc/nginx/sites-available/erpcondominios.conf.template > "$VT/erp.conf"
ln -s /etc/nginx/snippets "$VT/snippets"; ln -s /etc/nginx/fastcgi_params "$VT/fastcgi_params"
cat > "$VT/nginx.conf" <<EOF
pid $VT/nginx.pid; error_log $VT/error.log;
events {}
http { include /etc/nginx/mime.types; include /etc/nginx/conf.d/erp-http.conf; include $VT/erp.conf; }
EOF
nginx -t -q -c "$VT/nginx.conf" || die "vhost do ERP inválido"
rm -rf "$VT"
ok "vhost do ERP validado (não habilitado)"

nginx -t
systemctl enable --now php${PHPV}-fpm nginx
systemctl restart php${PHPV}-fpm nginx
CODE=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: qualquer.invalid' http://127.0.0.1/ || true)
[[ "$CODE" == "000" ]] || die "catch-all deveria encerrar a conexão (444), respondeu $CODE"
ok "Nginx $(nginx -v 2>&1 | cut -d/ -f2) + PHP $(php -r 'echo PHP_VERSION;') — catch-all 444"

# ---------------------------------------------------------------------------
step "4. PostgreSQL ${PGV} (PGDG) — banco vazio, só localhost"
apt-get install -y -q postgresql-common
if [[ ! -f /etc/apt/sources.list.d/pgdg.list && ! -f /etc/apt/sources.list.d/pgdg.sources ]]; then
  /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
fi
apt-get install -y -q postgresql-${PGV} postgresql-client-${PGV}
PGCONF=/etc/postgresql/${PGV}/main
cat > ${PGCONF}/conf.d/90-erp.conf <<'EOF'
listen_addresses = 'localhost'
password_encryption = scram-sha-256
timezone = 'America/Sao_Paulo'
log_timezone = 'UTC'
shared_buffers = 1GB
effective_cache_size = 4GB
work_mem = 16MB
maintenance_work_mem = 256MB
max_connections = 100
log_min_duration_statement = 1000
log_connections = on
log_disconnections = on
EOF
chown postgres:postgres ${PGCONF}/conf.d/90-erp.conf; chmod 644 ${PGCONF}/conf.d/90-erp.conf
[[ -f ${PGCONF}/pg_hba.conf.orig ]] || cp -p ${PGCONF}/pg_hba.conf ${PGCONF}/pg_hba.conf.orig
chown postgres:postgres ${PGCONF}/pg_hba.conf.orig; chmod 640 ${PGCONF}/pg_hba.conf.orig
cat > ${PGCONF}/pg_hba.conf <<'EOF'
# TYPE  DATABASE  USER  ADDRESS        METHOD
local   all       postgres             peer
local   all       all                  peer
host    all       all   127.0.0.1/32   scram-sha-256
host    all       all   ::1/128        scram-sha-256
EOF
chown postgres:postgres ${PGCONF}/pg_hba.conf; chmod 640 ${PGCONF}/pg_hba.conf
# Valida a configuração como usuário postgres ANTES de reiniciar (mostra o erro real)
sudo -u postgres /usr/lib/postgresql/${PGV}/bin/postgres -D /var/lib/postgresql/${PGV}/main \
  -c config_file=${PGCONF}/postgresql.conf -C timezone >/dev/null \
  || die "configuração do PostgreSQL inválida/ilegível — ver mensagem acima"
systemctl restart postgresql@${PGV}-main
systemctl enable postgresql >/dev/null
if ss -ltnH '( sport = :5432 )' | awk '{print $4}' | grep -vqE '^(127\.0\.0\.1|\[::1\]):5432$'; then
  die "PostgreSQL escutando fora de localhost"
fi

install -d -m 700 -o root -g root "$CFG_DIR/secrets"
PASSF="$CFG_DIR/secrets/pg_erp_app.pass"
if [[ ! -s "$PASSF" ]]; then
  ( umask 077; openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | cut -c1-40 > "$PASSF" )
fi
APP_PASS="$(cat "$PASSF")"
[[ ${#APP_PASS} -ge 32 ]] || die "senha gerada curta demais"

sudo -u postgres psql -v ON_ERROR_STOP=1 -q <<EOF
DO \$\$BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='erp_owner') THEN
    CREATE ROLE erp_owner NOLOGIN;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='erp_app') THEN
    CREATE ROLE erp_app LOGIN;
  END IF;
END\$\$;
ALTER ROLE erp_app WITH LOGIN PASSWORD '${APP_PASS}' CONNECTION LIMIT 60;
EOF
unset APP_PASS
if ! sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1; then
  sudo -u postgres psql -v ON_ERROR_STOP=1 -q -c \
    "CREATE DATABASE ${DB_NAME} OWNER erp_owner ENCODING 'UTF8' TEMPLATE template0 LOCALE_PROVIDER icu ICU_LOCALE 'pt-BR' LOCALE 'C.UTF-8';"
fi
sudo -u postgres psql -v ON_ERROR_STOP=1 -q -d ${DB_NAME} <<EOF
REVOKE ALL ON DATABASE ${DB_NAME} FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE ${DB_NAME} TO erp_app;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
ALTER DATABASE ${DB_NAME} SET timezone TO 'America/Sao_Paulo';
EOF
TABLES=$(sudo -u postgres psql -tAd ${DB_NAME} -c "SELECT count(*) FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema');")
[[ "$TABLES" == "0" ]] || die "banco deveria estar vazio, tem $TABLES tabelas"
ok "PostgreSQL $(sudo -u postgres psql -tAc 'show server_version') — banco ${DB_NAME} vazio, só localhost"

# ---------------------------------------------------------------------------
step "5. Teste PDO pgsql (isolado do ERP)"
PGPASS_FILE="$PASSF" php -r '
$p = trim(file_get_contents(getenv("PGPASS_FILE")));
$pdo = new PDO("pgsql:host=127.0.0.1;port=5432;dbname=erpcondominios", "erp_app", $p,
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r = $pdo->query("SELECT 1 AS ok, current_setting(\x27TimeZone\x27) AS tz, now()::text AS agora")->fetch(PDO::FETCH_ASSOC);
echo "PDO_OK ok={$r["ok"]} tz={$r["tz"]} agora={$r["agora"]}\n";'

# ---------------------------------------------------------------------------
step "6. Diretórios + clone do repositório oficial (fora do webroot)"
install -d -m 755 /opt/erpcondominios
install -d -m 750 -o www-data -g www-data "$DATA_DIR" "$DATA_DIR/uploads" "$DATA_DIR/storage"
install -d -m 755 "$WWW_DIR" "$WWW_DIR/releases"     # sem 'current' nesta fase
install -d -m 700 "$BKP_DIR"
SRC_PENDENTE=""
if [[ -d "$SRC_DIR/.git" ]]; then
  git -C "$SRC_DIR" fetch --all --prune -q
elif git ls-remote "$REPO_HTTPS" HEAD >/dev/null 2>&1; then
  git clone -q "$REPO_HTTPS" "$SRC_DIR"
else
  KEY=/root/.ssh/erp_deploy_ro
  install -d -m 700 /root/.ssh
  [[ -f $KEY ]] || ssh-keygen -q -t ed25519 -N "" -C "hetzner-deploy-readonly" -f $KEY
  echo
  echo ">>> Repositório privado. Cadastre esta chave como DEPLOY KEY (somente leitura):"
  echo ">>> GitHub > app.erpcondominios.com.br- > Settings > Deploy keys > Add (NÃO marcar write)"
  cat ${KEY}.pub
  echo ">>> Depois rode novamente este script. Clone ADIADO."
  SRC_PENDENTE=1
fi
if [[ -z "$SRC_PENDENTE" ]]; then
  chown -R root:root "$SRC_DIR"
  echo "origin/main  = $(git -C "$SRC_DIR" rev-parse origin/main)"
  echo "HEAD         = $(git -C "$SRC_DIR" rev-parse HEAD)"
  echo "baseline     = $BASELINE_SHA ($(git -C "$SRC_DIR" cat-file -e "${BASELINE_SHA}^{commit}" 2>/dev/null && echo presente || echo AUSENTE))"
  echo "branches     = $(git -C "$SRC_DIR" branch -r | wc -l)"
fi

# ---------------------------------------------------------------------------
step "RESUMO"
echo "SO: $PRETTY_NAME / kernel $(uname -r)"
echo "Swap: $(swapon --show=SIZE --noheadings | head -1)"
echo -n "Serviços: "
for s in ssh fail2ban ufw nginx php${PHPV}-fpm postgresql@${PGV}-main; do printf '%s=%s ' "$s" "$(systemctl is-active "$s")"; done; echo
echo "Listeners:"; ss -ltnH | awk '{print "  "$4}' | sort -u
echo "Vhost ERP: /etc/nginx/sites-available/erpcondominios.conf.template (NÃO habilitado)"
echo "Segredo PG: $PASSF (600 root) — NÃO exibir, NÃO commitar"
echo "Clone: ${SRC_PENDENTE:+PENDENTE (deploy key)}${SRC_PENDENTE:-OK em $SRC_DIR}"
echo "PENDENTE (fora desta fase): dump HostGator + MySQL temporário, Fase 3, habilitar vhost, TLS, DNS, backups, remover NOPASSWD."
echo "FASE_2_5_BASE_OK"
