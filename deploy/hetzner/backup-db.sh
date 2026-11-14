#!/usr/bin/env bash
set -euo pipefail
umask 077

readonly CONFIG_PATH='/etc/erpcondominios/erp_config.php'
readonly BACKUP_DIR='/var/backups/erpcondominios/daily'
readonly LOCK_FILE='/run/lock/erpcondominios-backup.lock'
readonly MIN_FREE_BYTES=$((10 * 1024 * 1024 * 1024))

if [[ ! -r "$CONFIG_PATH" ]]; then
    printf '%s\n' 'backup failed: private database config is unavailable' >&2
    exit 1
fi

DB_NAME=$(/usr/bin/php8.3 -d display_errors=0 -r 'require $argv[1]; if (!defined("ERP_DB_NAME")) { exit(2); } echo constant("ERP_DB_NAME");' "$CONFIG_PATH" 2>/dev/null) || {
    printf '%s\n' 'backup failed: private database config could not be read' >&2
    exit 1
}
if [[ ! "$DB_NAME" =~ ^erpcondominios(_stage_[0-9]{8})?$ ]]; then
    printf '%s\n' 'backup failed: configured database name is outside the allowlist' >&2
    exit 1
fi
if [[ $(mysql --protocol=socket --user=root --batch --skip-column-names -e "SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME}'") != 1 ]]; then
    printf '%s\n' 'backup failed: configured database does not exist' >&2
    exit 1
fi

install -d -o root -g root -m 0700 "$BACKUP_DIR"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    printf '%s\n' 'backup skipped: exclusive lock is held'
    exit 0
fi

free_bytes=$(df -PB1 "$BACKUP_DIR" | awk 'NR == 2 {print $4}')
if [[ ! "$free_bytes" =~ ^[0-9]+$ ]] || (( free_bytes < MIN_FREE_BYTES )); then
    printf '%s\n' 'backup failed: less than 10 GiB of free space' >&2
    exit 1
fi

stamp=$(date -u +%Y%m%dT%H%M%SZ)
name="erpcondominios-$stamp-$$.sql.gz"
final="$BACKUP_DIR/$name"
tmp="$BACKUP_DIR/.$name.partial"
manifest="$final.sha256"
manifest_tmp="$manifest.partial"
completed=0
cleanup() {
    rm -f -- "$tmp" "$manifest_tmp"
    if (( completed == 0 )) && [[ -e "$final" && ! -e "$manifest" ]]; then
        rm -f -- "$final"
    fi
}
trap cleanup EXIT

mysqldump \
    --protocol=socket \
    --user=root \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    --hex-blob \
    --default-character-set=utf8mb4 \
    --databases "$DB_NAME" \
    | gzip -c > "$tmp"

gzip -t "$tmp"
chmod 0600 "$tmp"
digest=$(sha256sum "$tmp" | awk '{print $1}')
printf '%s  %s\n' "$digest" "$name" > "$manifest_tmp"
chmod 0600 "$manifest_tmp"
mv -- "$tmp" "$final"
mv -- "$manifest_tmp" "$manifest"
completed=1
trap - EXIT
printf 'backup success: file=%s bytes=%s\n' "$name" "$(stat -c %s "$final")"
