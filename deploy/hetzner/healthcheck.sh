#!/usr/bin/env bash
set -euo pipefail

readonly IP='2.29.161.106'
readonly CERT='/etc/letsencrypt/live/erpcondominios-ip/fullchain.pem'
readonly BACKUP_DIR='/var/backups/erpcondominios/daily'
readonly STATE_FILE='/var/lib/erpcondominios/backup-integrity-verified-at'
readonly MIN_FREE_BYTES=$((10 * 1024 * 1024 * 1024))
readonly MAX_BACKUP_AGE_SECONDS=$((36 * 60 * 60))
fail() {
    printf 'healthcheck failed: %s\n' "$1" >&2
    exit 1
}

for unit in nginx php8.3-fpm mysql; do
    systemctl is-active --quiet "$unit" || fail "service $unit is not active"
done

mysqladmin --protocol=socket --user=root ping >/dev/null 2>&1 || fail 'mysql socket ping failed'
/usr/bin/curl --fail --silent --show-error --output /dev/null \
    --max-time 8 --resolve "$IP:443:127.0.0.1" "https://$IP/login.html" \
    || fail 'local HTTPS login route failed'
/usr/bin/openssl x509 -in "$CERT" -noout -checkend 86400 >/dev/null 2>&1 \
    || fail 'IP TLS certificate expires within 24 hours'

free_bytes=$(df -PB1 "$BACKUP_DIR" | awk 'NR == 2 {print $4}')
[[ "$free_bytes" =~ ^[0-9]+$ ]] && (( free_bytes >= MIN_FREE_BYTES )) \
    || fail 'less than 10 GiB of free disk space'

latest=$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'erpcondominios-*.sql.gz' ! -name '*.sha256' -printf '%T@ %p\n' | sort -nr | head -1 | cut -d' ' -f2-)
[[ -n "$latest" ]] || fail 'no backup file is present'
manifest="$latest.sha256"
[[ -s "$manifest" ]] || fail 'backup checksum manifest is missing'
[[ "$(stat -c '%a:%U:%G' "$latest")" == '600:root:root' ]] \
    || fail 'backup file permissions are not root:root 0600'
[[ "$(stat -c '%a:%U:%G' "$manifest")" == '600:root:root' ]] \
    || fail 'backup manifest permissions are not root:root 0600'
backup_mtime=$(stat -c %Y "$latest")
now=$(date +%s)
(( now - backup_mtime <= MAX_BACKUP_AGE_SECONDS )) || fail 'latest backup is older than 36 hours'

[[ -s "$STATE_FILE" ]] || fail 'backup integrity has not been checked'
[[ "$(stat -c '%a:%U:%G' "$STATE_FILE")" == '600:root:root' ]] \
    || fail 'backup integrity status permissions are not root:root 0600'
verified_at=$(cat "$STATE_FILE")
[[ "$verified_at" =~ ^[0-9]+$ ]] || fail 'backup integrity status is invalid'
integrity_age=$(( now - verified_at ))
(( integrity_age >= -300 && integrity_age <= MAX_BACKUP_AGE_SECONDS )) \
    || fail 'backup integrity verification is older than 36 hours'

printf 'healthcheck success\n'
