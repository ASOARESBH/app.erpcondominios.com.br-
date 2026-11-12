#!/usr/bin/env bash
set -euo pipefail
umask 077

readonly BACKUP_DIR='/var/backups/erpcondominios/daily'
readonly STATE_DIR='/var/lib/erpcondominios'
readonly STATE_FILE="$STATE_DIR/backup-integrity-verified-at"
fail() {
    printf 'backup integrity failed: %s\n' "$1" >&2
    exit 1
}

latest=$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'erpcondominios-*.sql.gz' ! -name '*.sha256' -printf '%T@ %p\n' | sort -nr | head -1 | cut -d' ' -f2-)
[[ -n "$latest" ]] || fail 'no backup file is present'
manifest="$latest.sha256"
[[ -s "$manifest" ]] || fail 'backup checksum manifest is missing'
[[ "$(stat -c '%a:%U:%G' "$latest")" == '600:root:root' ]] \
    || fail 'backup permissions are not root:root 0600'
[[ "$(stat -c '%a:%U:%G' "$manifest")" == '600:root:root' ]] \
    || fail 'checksum manifest permissions are not root:root 0600'
gzip -t "$latest" >/dev/null 2>&1 || fail 'gzip integrity check failed'
(
    cd "$BACKUP_DIR"
    sha256sum -c "$(basename "$manifest")" >/dev/null 2>&1
) || fail 'SHA-256 check failed'

install -d -o root -g root -m 0700 "$STATE_DIR"
tmp=$(mktemp "$STATE_DIR/.backup-integrity.XXXXXX")
trap 'rm -f -- "$tmp"' EXIT
printf '%s\n' "$(date -u +%s)" > "$tmp"
chmod 0600 "$tmp"
mv -f -- "$tmp" "$STATE_FILE"
trap - EXIT
printf 'backup integrity success\n'
