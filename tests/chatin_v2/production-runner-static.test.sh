#!/usr/bin/env bash
set -euo pipefail

repo_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
runner="$repo_root/deploy/hetzner/migrate-chatin-v2-production.sh"
sql="$repo_root/sql/migration_chatin_v2_mysql.sql"

bash -n "$runner"
help_output=$(bash "$runner" --help)
grep -Fq -- '--check' <<< "$help_output"
grep -Fq -- '--apply' <<< "$help_output"
grep -Fq 'erpcondominios_stage_20261114' <<< "$help_output"
grep -Fq 'pwa_configuracoes' "$runner"
grep -Fq 'MIGRATION_ATTEMPT_FILE' "$runner"
grep -Fq 'mysql_root' "$runner"

set +e
bash "$runner" >/dev/null 2>&1
noarg_status=$?
bash "$runner" --invalid >/dev/null 2>&1
invalid_status=$?
set -e
[[ "$noarg_status" -eq 64 ]]
[[ "$invalid_status" -eq 64 ]]

expected='a5d402a17e3789223a9d5a297b0c12eb78fa26232ebd6eb8dd8c1f575f5aa375'
actual=$(sha256sum "$sql" | awk '{print $1}')
[[ "$actual" == "$expected" ]]
grep -Fq "$expected" "$runner"

printf '%s\n' 'PASS: runner syntax, usage gates, pinned target and SQL checksum verified; no database or server was accessed.'
