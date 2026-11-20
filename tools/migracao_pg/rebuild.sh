#!/usr/bin/env -S -i PATH=/usr/sbin:/usr/bin:/sbin:/bin /bin/bash --noprofile --norc
set -Eeuo pipefail
umask 077

readonly REPORT_ROOT='/var/backups/erpcondominios/p2-runs'
readonly LOCK_FILE='/run/lock/erp-p2-migration.lock'
readonly PROFILE_DEVELOPMENT='development'
readonly PROFILE_CUTOVER='cutover'
readonly DEFAULT_PYTHON='/var/www/erpcondominios-pg/p2-venv/bin/python'
readonly RELEASE_ROOT='/var/www/erpcondominios-pg/releases'
readonly SYSTEM_PATH='/usr/sbin:/usr/bin:/sbin:/bin'
readonly SOURCE_DEFAULTS_FILE='/etc/erpcondominios/secrets/pg_reader.cnf'

phase='startup'
runner_started_epoch=$(/usr/bin/date +%s)
private_tmp=''
cleanup() {
    local status=$?
    trap - EXIT
    if [[ -n "$private_tmp" ]]; then
        case "$private_tmp" in
            /tmp/erp-p2-rebuild.*)
                if ! /usr/bin/rm -rf -- "$private_tmp"; then
                    printf 'REBUILD_ERROR=PRIVATE_TEMP_CLEANUP_FAILED\n' >&2
                    status=70
                fi
                ;;
            *)
                printf 'REBUILD_ERROR=PRIVATE_TEMP_PATH_INVALID\n' >&2
                status=70
                ;;
        esac
    fi
    if (( status != 0 )); then
        printf 'REBUILD_STATUS=FAILED PHASE=%s EXIT=%s\n' "$phase" "$status" >&2
    fi
    exit "$status"
}
trap cleanup EXIT

usage() {
    printf 'Uso: %s development catalog-bundle | %s development catalog-rebind <report.json> | %s development reconcile <nome-bundle> <arquivo-defaults-source> <report.json> | %s {development|cutover} {preflight|load} <nome-bundle> <arquivo-defaults-source>\n' "$0" "$0" "$0" "$0" >&2
    exit 64
}

[[ $# -eq 2 || $# -eq 3 || $# -eq 4 || $# -eq 5 ]] || usage
profile=$1
command=$2
bundle=''
source_defaults_file=''
report_file=''
[[ "$profile" == "$PROFILE_DEVELOPMENT" || "$profile" == "$PROFILE_CUTOVER" ]] || { printf 'REBUILD_ERROR=PROFILE_NOT_ALLOWED\n' >&2; exit 64; }
if [[ "$command" == 'catalog-bundle' ]]; then
    [[ $# -eq 2 && "$profile" == "$PROFILE_DEVELOPMENT" ]] || usage
    source_defaults_file=$SOURCE_DEFAULTS_FILE
elif [[ "$command" == 'catalog-rebind' ]]; then
    [[ $# -eq 3 && "$profile" == "$PROFILE_DEVELOPMENT" ]] || usage
    source_defaults_file=$SOURCE_DEFAULTS_FILE
    report_file=$3
    [[ "$report_file" =~ ^/var/backups/erpcondominios/p2-runs/rebuild-[0-9]{8}T[0-9]{6}Z-[0-9]+\.json$ ]] || {
        printf 'REBUILD_ERROR=REPORT_FILE_NOT_ALLOWLISTED\n' >&2
        exit 78
    }
elif [[ "$command" == 'reconcile' ]]; then
    [[ $# -eq 5 && "$profile" == "$PROFILE_DEVELOPMENT" ]] || usage
    bundle=$3
    source_defaults_file=$4
    report_file=$5
    [[ "$bundle" =~ ^catalog-[a-f0-9]{12}-collation-[a-f0-9]{8}-role-[a-f0-9]{8}-code-[a-f0-9]{8}$ ]] || {
        printf 'REBUILD_ERROR=BUNDLE_NAME_INVALID\n' >&2
        exit 64
    }
    [[ "$report_file" =~ ^/var/backups/erpcondominios/p2-runs/rebuild-[0-9]{8}T[0-9]{6}Z-[0-9]+\.json$ ]] || {
        printf 'REBUILD_ERROR=REPORT_FILE_NOT_ALLOWLISTED\n' >&2
        exit 78
    }
else
    [[ $# -eq 4 ]] || usage
    [[ "$command" == 'preflight' || "$command" == 'load' ]] || usage
    bundle=$3
    source_defaults_file=$4
    [[ "$bundle" =~ ^catalog-[a-f0-9]{12}-collation-[a-f0-9]{8}-role-[a-f0-9]{8}-code-[a-f0-9]{8}$ ]] || {
        printf 'REBUILD_ERROR=BUNDLE_NAME_INVALID\n' >&2
        exit 64
    }
fi
[[ "$(/usr/bin/id -u)" -eq 0 ]] || { printf 'REBUILD_ERROR=ROOT_REQUIRED\n' >&2; exit 77; }
[[ "$source_defaults_file" == /* && -f "$source_defaults_file" && ! -L "$source_defaults_file" ]] || {
    printf 'REBUILD_ERROR=SOURCE_DEFAULTS_FILE_INVALID\n' >&2
    exit 78
}

phase='runtime-check'
script_path=$(/usr/bin/realpath -e -- "${BASH_SOURCE[0]}")
case "$script_path" in
    "$RELEASE_ROOT"/*/tools/migracao_pg/rebuild.sh) ;;
    *) printf 'REBUILD_ERROR=ROOT_EXECUTION_REQUIRES_IMMUTABLE_RELEASE\n' >&2; exit 69 ;;
esac
repo_root=$(/usr/bin/realpath -e -- "$(/usr/bin/dirname -- "$script_path")/../..")
release_id=$(/usr/bin/basename -- "$repo_root")
[[ "$release_id" =~ ^[a-f0-9]{40}$ ]] || {
    printf 'REBUILD_ERROR=RELEASE_REVISION_INVALID\n' >&2
    exit 69
}

trusted_directory_chain() {
    local current=$1 owner mode
    while [[ "$current" != '/' ]]; do
        [[ -d "$current" && ! -L "$current" ]] || return 1
        read -r owner mode < <(/usr/bin/stat -c '%u %a' -- "$current")
        [[ "$owner" == '0' ]] && (( (8#$mode & 0022) == 0 )) || return 1
        current=$(/usr/bin/dirname -- "$current")
    done
    return 0
}

trusted_file() {
    local path=$1 owner mode
    [[ -f "$path" && ! -L "$path" ]] || return 1
    read -r owner mode < <(/usr/bin/stat -c '%u %a' -- "$path")
    [[ "$owner" == '0' ]] && (( (8#$mode & 0022) == 0 ))
}

private_tmp=$(/usr/bin/mktemp -d -- /tmp/erp-p2-rebuild.XXXXXXXX)
[[ "$(/usr/bin/stat -c '%u:%a' -- "$private_tmp")" == '0:700' ]] || {
    printf 'REBUILD_ERROR=PRIVATE_TEMP_DIRECTORY_INVALID\n' >&2
    exit 69
}

trusted_directory_chain "$RELEASE_ROOT" || {
    printf 'REBUILD_ERROR=RELEASE_PATH_NOT_ROOT_CONTROLLED\n' >&2
    exit 69
}
trusted_directory_chain "$repo_root" || {
    printf 'REBUILD_ERROR=RELEASE_PATH_NOT_ROOT_CONTROLLED\n' >&2
    exit 69
}
trusted_directory_chain "$repo_root/tools" && trusted_directory_chain "$repo_root/tools/migracao_pg" || {
    printf 'REBUILD_ERROR=PYTHON_PACKAGE_PATH_NOT_ROOT_CONTROLLED\n' >&2
    exit 69
}
trusted_file "$script_path" || {
    printf 'REBUILD_ERROR=RUNNER_NOT_ROOT_CONTROLLED\n' >&2
    exit 69
}
if ! /usr/bin/find "$repo_root/tools" -type l -print0 > "$private_tmp/tool-symlinks.nul"; then
    printf 'REBUILD_ERROR=RELEASE_ENUMERATION_FAILED\n' >&2
    exit 69
fi
while IFS= read -r -d '' source_link; do
    printf 'REBUILD_ERROR=RELEASE_SOURCE_SYMLINK_FOUND\n' >&2
    exit 69
done < "$private_tmp/tool-symlinks.nul"
if ! /usr/bin/find "$repo_root" ! -type d ! -type f -print0 > "$private_tmp/release-special-objects.nul"; then
    printf 'REBUILD_ERROR=RELEASE_ENUMERATION_FAILED\n' >&2
    exit 69
fi
while IFS= read -r -d '' release_object; do
    printf 'REBUILD_ERROR=RELEASE_OBJECT_TYPE_INVALID\n' >&2
    exit 69
done < "$private_tmp/release-special-objects.nul"
if ! /usr/bin/find "$repo_root" -type d -print0 > "$private_tmp/release-directories.nul"; then
    printf 'REBUILD_ERROR=RELEASE_ENUMERATION_FAILED\n' >&2
    exit 69
fi
while IFS= read -r -d '' release_directory; do
    [[ -d "$release_directory" && ! -L "$release_directory" ]] || {
        printf 'REBUILD_ERROR=RELEASE_DIRECTORY_INVALID\n' >&2
        exit 69
    }
    read -r release_owner release_mode < <(/usr/bin/stat -c '%u %a' -- "$release_directory")
    [[ "$release_owner" == '0' ]] && (( (8#$release_mode & 0022) == 0 )) || {
        printf 'REBUILD_ERROR=RELEASE_DIRECTORY_NOT_ROOT_CONTROLLED\n' >&2
        exit 69
    }
done < "$private_tmp/release-directories.nul"
if ! /usr/bin/find "$repo_root" -type f -print0 > "$private_tmp/release-files.nul"; then
    printf 'REBUILD_ERROR=RELEASE_ENUMERATION_FAILED\n' >&2
    exit 69
fi
while IFS= read -r -d '' release_file; do
    release_relative_path=${release_file#"$repo_root"/}
    [[ "$release_relative_path" != *$'\n'* && "$release_relative_path" != *$'\r'* ]] || {
        printf 'REBUILD_ERROR=RELEASE_PATH_CONTROL_CHARACTER\n' >&2
        exit 69
    }
    trusted_file "$release_file" || {
        printf 'REBUILD_ERROR=RELEASE_FILE_NOT_ROOT_CONTROLLED\n' >&2
        exit 69
    }
done < "$private_tmp/release-files.nul"
if ! /usr/bin/find "$repo_root/tools" -type f \( -name '*.py' -o -name '*.pyc' \) -print0 > "$private_tmp/python-source-files.nul"; then
    printf 'REBUILD_ERROR=RELEASE_ENUMERATION_FAILED\n' >&2
    exit 69
fi
while IFS= read -r -d '' source_file; do
    trusted_file "$source_file" || {
        printf 'REBUILD_ERROR=PYTHON_SOURCE_NOT_ROOT_CONTROLLED\n' >&2
        exit 69
    }
done < "$private_tmp/python-source-files.nul"
revision_file="$repo_root/REVISION"
trusted_file "$revision_file" && [[ "$(<"$revision_file")" == "$release_id" ]] || {
    printf 'REBUILD_ERROR=RELEASE_REVISION_MISMATCH\n' >&2
    exit 69
}
manifest_file="$repo_root/RELEASE-MANIFEST.sha256"
trusted_file "$manifest_file" || {
    printf 'REBUILD_ERROR=RELEASE_MANIFEST_INVALID\n' >&2
    exit 69
}
if ! /usr/bin/find "$repo_root" -type l -print0 > "$private_tmp/release-symlinks.nul"; then
    printf 'REBUILD_ERROR=RELEASE_ENUMERATION_FAILED\n' >&2
    exit 69
fi
while IFS= read -r -d '' release_link; do
    printf 'REBUILD_ERROR=RELEASE_SYMLINK_FOUND\n' >&2
    exit 69
done < "$private_tmp/release-symlinks.nul"
manifest_paths=()
while IFS= read -r manifest_line || [[ -n "$manifest_line" ]]; do
    [[ ${#manifest_line} -gt 66 && ( ${manifest_line:64:2} == '  ' || ${manifest_line:64:2} == ' *' ) ]] || {
        printf 'REBUILD_ERROR=RELEASE_MANIFEST_FORMAT_INVALID\n' >&2
        exit 69
    }
    manifest_hash=${manifest_line:0:64}
    manifest_path=${manifest_line:66}
    [[ "$manifest_hash" =~ ^[a-f0-9]{64}$ && -n "$manifest_path" && "$manifest_path" != /* && "$manifest_path" != *\\* && "$manifest_path" != *$'\n'* && "$manifest_path" != *$'\r'* ]] || {
        printf 'REBUILD_ERROR=RELEASE_MANIFEST_ENTRY_INVALID\n' >&2
        exit 69
    }
    case "/$manifest_path/" in
        */../*|*/./*|*//*)
            printf 'REBUILD_ERROR=RELEASE_MANIFEST_PATH_INVALID\n' >&2
            exit 69
            ;;
    esac
    [[ "$manifest_path" != 'RELEASE-MANIFEST.sha256' && -f "$repo_root/$manifest_path" && ! -L "$repo_root/$manifest_path" ]] || {
        printf 'REBUILD_ERROR=RELEASE_MANIFEST_ENTRY_MISSING\n' >&2
        exit 69
    }
    manifest_paths+=("$manifest_path")
done < "$manifest_file"
(( ${#manifest_paths[@]} > 0 )) || {
    printf 'REBUILD_ERROR=RELEASE_MANIFEST_EMPTY\n' >&2
    exit 69
}
if ! printf '%s\0' "${manifest_paths[@]}" | /usr/bin/sort -z > "$private_tmp/manifest-paths-sorted.nul"; then
    printf 'REBUILD_ERROR=RELEASE_MANIFEST_SORT_FAILED\n' >&2
    exit 69
fi
if ! /usr/bin/find "$repo_root" -type f ! -path "$manifest_file" -printf '%P\0' | /usr/bin/sort -z > "$private_tmp/release-paths-sorted.nul"; then
    printf 'REBUILD_ERROR=RELEASE_ENUMERATION_FAILED\n' >&2
    exit 69
fi
mapfile -d '' -t manifest_paths_sorted < "$private_tmp/manifest-paths-sorted.nul"
mapfile -d '' -t release_paths_sorted < "$private_tmp/release-paths-sorted.nul"
[[ ${#manifest_paths_sorted[@]} -eq ${#release_paths_sorted[@]} ]] || {
    printf 'REBUILD_ERROR=RELEASE_MANIFEST_COVERAGE_MISMATCH\n' >&2
    exit 69
}
for index in "${!manifest_paths_sorted[@]}"; do
    if (( index > 0 )) && [[ "${manifest_paths_sorted[$index]}" == "${manifest_paths_sorted[$((index - 1))]}" ]]; then
        printf 'REBUILD_ERROR=RELEASE_MANIFEST_DUPLICATE_PATH\n' >&2
        exit 69
    fi
    [[ "${manifest_paths_sorted[$index]}" == "${release_paths_sorted[$index]}" ]] || {
        printf 'REBUILD_ERROR=RELEASE_MANIFEST_COVERAGE_MISMATCH\n' >&2
        exit 69
    }
done
cd "$repo_root"
/usr/bin/sha256sum --check --strict --status "$manifest_file" || {
    printf 'REBUILD_ERROR=RELEASE_CONTENT_MISMATCH\n' >&2
    exit 69
}

python_bin=$DEFAULT_PYTHON
[[ -f "$python_bin" && -x "$python_bin" && ! -L "$python_bin" ]] || {
    printf 'REBUILD_ERROR=PINNED_PYTHON_RUNTIME_REQUIRED\n' >&2
    exit 69
}
python_venv=$(/usr/bin/dirname -- "$(/usr/bin/dirname -- "$python_bin")")
trusted_directory_chain "$python_venv" || {
    printf 'REBUILD_ERROR=PINNED_PYTHON_PATH_OWNERSHIP_INVALID\n' >&2
    exit 69
}
if ! /usr/bin/find "$python_venv" -mindepth 1 -print0 > "$private_tmp/python-runtime-objects.nul"; then
    printf 'REBUILD_ERROR=PINNED_PYTHON_ENUMERATION_FAILED\n' >&2
    exit 69
fi
while IFS= read -r -d '' runtime_path; do
    if [[ -L "$runtime_path" ]]; then
        printf 'REBUILD_ERROR=PINNED_PYTHON_SYMLINK_FOUND\n' >&2
        exit 69
    fi
    read -r runtime_owner runtime_mode < <(/usr/bin/stat -c '%u %a' -- "$runtime_path")
    [[ "$runtime_owner" == '0' ]] && (( (8#$runtime_mode & 0022) == 0 )) || {
        printf 'REBUILD_ERROR=PINNED_PYTHON_CONTENT_NOT_ROOT_CONTROLLED\n' >&2
        exit 69
    }
done < "$private_tmp/python-runtime-objects.nul"
if ! /usr/bin/find "$python_venv" -type d -print0 > "$private_tmp/python-runtime-directories.nul"; then
    printf 'REBUILD_ERROR=PINNED_PYTHON_ENUMERATION_FAILED\n' >&2
    exit 69
fi
while IFS= read -r -d '' runtime_directory; do
    [[ -d "$runtime_directory" && ! -L "$runtime_directory" ]] || {
        printf 'REBUILD_ERROR=PINNED_PYTHON_DIRECTORY_INVALID\n' >&2
        exit 69
    }
    read -r runtime_directory_owner runtime_directory_mode < <(/usr/bin/stat -c '%u %a' -- "$runtime_directory")
    [[ "$runtime_directory_owner" == '0' ]] && (( (8#$runtime_directory_mode & 0022) == 0 )) || {
        printf 'REBUILD_ERROR=PINNED_PYTHON_DIRECTORY_NOT_ROOT_CONTROLLED\n' >&2
        exit 69
    }
done < "$private_tmp/python-runtime-directories.nul"
[[ -x /usr/bin/nice && -x /usr/bin/ionice && -x /usr/bin/flock ]] || {
    printf 'REBUILD_ERROR=REQUIRED_SYSTEM_TOOL_MISSING\n' >&2
    exit 69
}

phase='lock'
if [[ -L "$LOCK_FILE" ]]; then
    printf 'REBUILD_ERROR=LOCK_FILE_SYMLINK\n' >&2
    exit 78
fi
if [[ ! -e "$LOCK_FILE" ]]; then
    (umask 077; set -o noclobber; : > "$LOCK_FILE") 2>/dev/null || {
        printf 'REBUILD_ERROR=LOCK_FILE_CREATE_FAILED\n' >&2
        exit 78
    }
fi
[[ -f "$LOCK_FILE" && "$(/usr/bin/stat -c '%u:%a' "$LOCK_FILE")" == '0:600' ]] || {
    printf 'REBUILD_ERROR=LOCK_FILE_MODE_INVALID\n' >&2
    exit 78
}
exec 9<>"$LOCK_FILE"
/usr/bin/flock -n 9 || { printf 'REBUILD_ERROR=RUN_ALREADY_ACTIVE\n' >&2; exit 75; }

if [[ "$command" == 'catalog-bundle' ]]; then
    phase='catalog-bundle'
    set +e
    capture_json=$(/usr/bin/nice -n 19 /usr/bin/ionice -c 3 /usr/bin/env -i "PATH=$SYSTEM_PATH" ERP_P2_ROOT_LAUNCHER_VERIFIED=1 "$python_bin" -E -B -m tools.migracao_pg.catalog_bundle)
    capture_exit=$?
    set -e
    if (( capture_exit != 0 )); then
        printf 'REBUILD_ERROR=CATALOG_BUNDLE_CAPTURE_FAILED EXIT=%s\n' "$capture_exit" >&2
        exit 78
    fi
    /usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c '
import json,sys
d=json.load(sys.stdin)
assert d.get("status") == "CATALOG_BUNDLE_READY"
assert d.get("source_schema") == "erp_pg_source"
assert d.get("catalog_version") == 12
assert d.get("bundle_executable_complete") is True
assert d.get("bundle_load_executable_complete") is True
assert d.get("selection_private") is True
assert d.get("memory_guard_min_available_kib") == 1572864
assert d.get("minimum_available_kib_observed", 0) >= 1572864
assert d.get("catalog", {}).get("tables") == 276
assert d.get("catalog", {}).get("reviews") == 0
assert d.get("catalog", {}).get("foreign_keys") == 11
assert d.get("catalog", {}).get("triggers") == 7
assert d.get("bundle", {}).get("reviews") == 0
assert d.get("bundle", {}).get("translated_business_triggers") == 7
assert d.get("bundle", {}).get("foreign_keys_generated_not_valid") == 11
' <<< "$capture_json" || {
        printf 'REBUILD_ERROR=CATALOG_BUNDLE_GATE_FAILED\n' >&2
        exit 78
    }
    printf '%s\n' "$capture_json"
    printf 'REBUILD_STATUS=CATALOG_BUNDLE_READY PROFILE=development SOURCE=erp_pg_source\n'
    phase='complete'
    exit 0
fi

if [[ "$command" == 'catalog-rebind' ]]; then
    phase='catalog-rebind-report-validation'
    trusted_directory_chain "$REPORT_ROOT" && [[ "$(/usr/bin/stat -c '%u:%a' -- "$REPORT_ROOT")" == '0:700' ]] || {
        printf 'REBUILD_ERROR=REPORT_ROOT_MODE_INVALID\n' >&2
        exit 78
    }
    trusted_file "$report_file" && [[ "$(/usr/bin/stat -c '%u:%a' -- "$report_file")" == '0:600' ]] || {
        printf 'REBUILD_ERROR=REPORT_FILE_MODE_INVALID\n' >&2
        exit 78
    }
    phase='catalog-rebind'
    set +e
    capture_json=$(/usr/bin/nice -n 19 /usr/bin/ionice -c 3 /usr/bin/env -i "PATH=$SYSTEM_PATH" ERP_P2_ROOT_LAUNCHER_VERIFIED=1 "$python_bin" -E -B -m tools.migracao_pg.catalog_bundle "$report_file")
    capture_exit=$?
    set -e
    if (( capture_exit != 0 )); then
        printf 'REBUILD_ERROR=CATALOG_BUNDLE_REBIND_FAILED EXIT=%s\n' "$capture_exit" >&2
        exit 78
    fi
    /usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c '
import json,sys
d=json.load(sys.stdin)
assert d.get("status") == "CATALOG_BUNDLE_REBOUND"
assert d.get("source_schema") == "erp_pg_source"
assert d.get("catalog_version") == 12
assert d.get("bundle_executable_complete") is True
assert d.get("bundle_load_executable_complete") is True
assert d.get("selection_private") is True
assert d.get("database_connections") == 0
assert d.get("memory_guard_min_available_kib") == 1572864
assert d.get("minimum_available_kib_observed", 0) >= 1572864
assert d.get("catalog", {}).get("tables") == 276
assert d.get("catalog", {}).get("reviews") == 0
assert d.get("catalog", {}).get("foreign_keys") == 11
assert d.get("catalog", {}).get("triggers") == 7
assert d.get("bundle", {}).get("reviews") == 0
assert d.get("bundle", {}).get("translated_business_triggers") == 7
assert d.get("bundle", {}).get("foreign_keys_generated_not_valid") == 11
' <<< "$capture_json" || {
        printf 'REBUILD_ERROR=CATALOG_BUNDLE_REBIND_GATE_FAILED\n' >&2
        exit 78
    }
    printf 'REBUILD_CATALOG_REBIND_SUMMARY STATUS=CATALOG_BUNDLE_REBOUND SOURCE=erp_pg_source TABLES=276 FOREIGN_KEYS=11 TRIGGERS=7 REVIEWS=0 DATABASE_CONNECTIONS=0 SELECTION_PRIVATE=true\n'
    phase='complete'
    exit 0
fi

if [[ "$profile" == "$PROFILE_DEVELOPMENT" ]]; then
    phase='catalog-selection-validation'
    selection_file="/var/lib/erpcondominios-pg/p2-bundles/development-v12-${release_id}.selection"
    trusted_file "$selection_file" || {
        printf 'REBUILD_ERROR=CATALOG_BUNDLE_SELECTION_REQUIRED\n' >&2
        exit 78
    }
    [[ "$(/usr/bin/stat -c '%u:%a' -- "$selection_file")" == '0:600' ]] || {
        printf 'REBUILD_ERROR=CATALOG_BUNDLE_SELECTION_MODE_INVALID\n' >&2
        exit 78
    }
    selection_valid=$(/usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c '
import json,re,sys
def unique(pairs):
    d={}
    for k,v in pairs:
        if k in d: raise ValueError("duplicate")
        d[k]=v
    return d
try:
    with open(sys.argv[1], "r", encoding="ascii") as f: d=json.load(f, object_pairs_hook=unique)
except Exception: sys.exit(1)
base=isinstance(d,dict) and d.get("release_sha")==sys.argv[2] and d.get("bundle_name")==sys.argv[3] and isinstance(d.get("bundle_name"),str) and re.fullmatch(r"catalog-[a-f0-9]{12}-collation-[a-f0-9]{8}-role-[a-f0-9]{8}-code-[a-f0-9]{8}",d["bundle_name"])
ordinary=base and set(d)=={"release_sha","bundle_name"}
rebound=base and set(d)=={"release_sha","bundle_name","load_evidence"} and sys.argv[4] in {"preflight","reconcile"} and isinstance(d.get("load_evidence"),dict)
ok=ordinary or rebound
sys.exit(0 if ok else 1)
' "$selection_file" "$release_id" "$bundle" "$command" && printf 'yes' || printf 'no')
    [[ "$selection_valid" == 'yes' ]] || {
        printf 'REBUILD_ERROR=CATALOG_BUNDLE_SELECTION_MISMATCH\n' >&2
        exit 78
    }
fi

if [[ "$command" == 'reconcile' ]]; then
    phase='reconciliation-report-validation'
    trusted_directory_chain "$REPORT_ROOT" && [[ "$(/usr/bin/stat -c '%u:%a' -- "$REPORT_ROOT")" == '0:700' ]] || {
        printf 'REBUILD_ERROR=REPORT_ROOT_MODE_INVALID\n' >&2
        exit 78
    }
    trusted_file "$report_file" && [[ "$(/usr/bin/stat -c '%u:%a' -- "$report_file")" == '0:600' ]] || {
        printf 'REBUILD_ERROR=REPORT_FILE_MODE_INVALID\n' >&2
        exit 78
    }
    phase='reconciliation-retry-evidence-validation'
    retry_validation_json=$(/usr/bin/nice -n 19 /usr/bin/ionice -c 3 /usr/bin/env -i "PATH=$SYSTEM_PATH" ERP_P2_ROOT_LAUNCHER_VERIFIED=1 "$python_bin" -E -B -m tools.migracao_pg.loader validate-reconcile \
        --profile "$profile" \
        --bundle-name "$bundle" \
        --report-file "$report_file" \
        --retry-read-only)
    /usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c '
import json,sys
d=json.load(sys.stdin)
assert d.get("status") == "RECONCILIATION_RETRY_READY"
assert d.get("profile") == "development"
assert d.get("source_schema") == "erp_pg_source"
assert d.get("target_database") == "erp_pg_dev"
assert d.get("tables") == 276
assert type(d.get("rows")) is int and d["rows"] >= 0
assert d.get("retry_unused") is True
assert d.get("cleanup_confirmed") is True
' <<< "$retry_validation_json" || {
        printf 'REBUILD_ERROR=RECONCILIATION_RETRY_EVIDENCE_INVALID\n' >&2
        exit 78
    }
    printf 'REBUILD_RECONCILE_RETRY_EVIDENCE PASS PROFILE=development SOURCE=erp_pg_source TARGET=erp_pg_dev TABLES=276 RETRY_UNUSED=true CLEANUP_CONFIRMED=true\n'
fi

phase='bundle-validation'
if [[ "$command" == 'load' ]]; then
    report_file=''
    if [[ -e "$REPORT_ROOT" || -L "$REPORT_ROOT" ]]; then
        [[ -d "$REPORT_ROOT" && ! -L "$REPORT_ROOT" && "$(/usr/bin/stat -c '%u:%a' "$REPORT_ROOT")" == '0:700' ]] || {
            printf 'REBUILD_ERROR=REPORT_ROOT_MODE_INVALID\n' >&2
            exit 78
        }
    else
        /usr/bin/install -d -o root -g root -m 0700 "$REPORT_ROOT"
    fi
    report_dir=$(/usr/bin/realpath -e "$REPORT_ROOT")
    [[ "$report_dir" == "$REPORT_ROOT" ]] || { printf 'REBUILD_ERROR=REPORT_ROOT_MISMATCH\n' >&2; exit 78; }
    report_file="$report_dir/rebuild-$(/usr/bin/date -u +%Y%m%dT%H%M%SZ)-$$.json"
fi

phase='source-preflight'
preflight_json=$(/usr/bin/nice -n 19 /usr/bin/ionice -c 3 /usr/bin/env -i "PATH=$SYSTEM_PATH" ERP_P2_ROOT_LAUNCHER_VERIFIED=1 "$python_bin" -E -B -m tools.migracao_pg.loader preflight \
    --profile "$profile" \
    --bundle-name "$bundle" \
    --source-defaults-file "$source_defaults_file")
emit_summary() {
    local kind=$1 payload=$2
    printf '%s' "$payload" | /usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c '
import json,re,sys
import math
try:
    data=json.load(sys.stdin)
except Exception:
    print("REBUILD_ERROR=SUMMARY_JSON_INVALID", file=sys.stderr)
    raise SystemExit(65)
def n(value): return value if type(value) is int and value >= 0 else 0
def number(value): return str(value) if type(value) in (int,float) and math.isfinite(value) and value >= 0 else "unknown"
def b(value): return "true" if value is True else "false" if value is False else "unknown"
def safe_code(value): return value if isinstance(value,str) and re.fullmatch(r"[A-Z0-9_]{1,96}",value) else "none"
def safe_sqlstate(value): return value if isinstance(value,str) and re.fullmatch(r"[0-9A-Z]{5}",value) else "none"
def safe_profile(value): return value if value in {"development","cutover"} else "unknown"
def safe_target(value): return value if value in {"erp_pg_dev","erpcondominios_pg"} else "unknown"
kind=sys.argv[1]
if kind == "preflight":
    counts=data.get("source_table_counts",[]); text=data.get("text_policy_findings",[]); binary=data.get("binary_policy_findings",[])
    counts=counts if isinstance(counts,list) else []; text=text if isinstance(text,list) else []; binary=binary if isinstance(binary,list) else []
    rows=sum(n(item.get("rows")) for item in counts if isinstance(item,dict))
    text_nul=sum(n(item.get("cells_with_nul")) for item in text if isinstance(item,dict))
    text_failed=sum(1 for item in text if isinstance(item,dict) and item.get("scan_status","SCANNED") != "SCANNED")
    binary_nul=sum(n(item.get("python_cells_with_nul")) for item in binary if isinstance(item,dict))
    binary_failed=sum(1 for item in binary if isinstance(item,dict) and item.get("scan_status") == "FAILED")
    base64_cols=sum(1 for item in binary if isinstance(item,dict) and item.get("storage_encoding") == "base64")
    schema=data.get("target_scope",{}); schema=schema.get("target_schema_present") if isinstance(schema,dict) else None
    print("REBUILD_PREFLIGHT_SUMMARY STATUS=%s PROFILE=%s SOURCE=%s TARGET=%s TABLES=%s ROWS=%s BUNDLE_COMPLETE=%s REVIEWS=%s TEXT_COLUMNS=%s TEXT_NUL_CELLS=%s TEXT_SCAN_FAILURES=%s BINARY_COLUMNS=%s BINARY_NUL_CELLS=%s BINARY_SCAN_FAILURES=%s BASE64_COLUMNS=%s TARGET_SCHEMA_PRESENT=%s" % (
        safe_code(data.get("status")),safe_profile(data.get("profile")),"erp_pg_source" if data.get("source_schema") == "erp_pg_source" else "unknown",safe_target(data.get("target_database")),len(counts),rows,b(data.get("bundle_executable_complete")),n(data.get("bundle_review_items")),len(text),text_nul,text_failed,len(binary),binary_nul,binary_failed,base64_cols,b(schema)))
elif kind == "load":
    print("REBUILD_LOAD_SUMMARY STATUS=%s TABLES=%s ROWS=%s TABLE_FAILURES=%s ELAPSED_SECONDS=%s MEMORY_MIB_END=%s CLEANUP=%s SQLSTATE=%s" % (
        safe_code(data.get("status")),n(data.get("tables_loaded")),n(data.get("rows_loaded")),len(data.get("table_failures",[])) if isinstance(data.get("table_failures"),list) else 0,
        number(data.get("elapsed_seconds_total",data.get("elapsed_seconds"))),number(data.get("available_memory_mib_end")),
        "true" if all(data.get(k) is True for k in ("source_cleanup_confirmed","target_cleanup_confirmed","nul_spool_cleanup_confirmed")) else "false",safe_sqlstate(data.get("sqlstate"))))
elif kind == "reconcile":
    tables=data.get("tables",{}); tables=tables if isinstance(tables,dict) else {}; metrics=data.get("metrics",{}); metrics=metrics if isinstance(metrics,dict) else {}
    rows_source=sum(n(v.get("source_count")) for v in tables.values() if isinstance(v,dict)); rows_target=sum(n(v.get("target_count")) for v in tables.values() if isinstance(v,dict))
    passed=sum(1 for v in metrics.values() if v == "PASS"); diverged=sum(1 for v in metrics.values() if v == "DIVERGED"); blocked=sum(1 for v in metrics.values() if v == "BLOCKED")
    print("REBUILD_RECONCILE_SUMMARY STATUS=%s TABLES=%s ROWS_SOURCE=%s ROWS_TARGET=%s METRICS_PASS=%s METRICS_DIVERGED=%s METRICS_BLOCKED=%s ELAPSED_SECONDS=%s MEMORY_MIB_END=%s FAILURE_CODE=%s SQLSTATE=%s" % (
        safe_code(data.get("status")),len(tables),rows_source,rows_target,passed,diverged,blocked,number(data.get("elapsed_seconds_total")),number(data.get("available_memory_mib_end")),safe_code(data.get("failure_code")),safe_sqlstate(data.get("sqlstate"))))
else:
    print("REBUILD_ERROR=SUMMARY_KIND_INVALID", file=sys.stderr)
    raise SystemExit(65)
' "$kind"
}
emit_summary preflight "$preflight_json"
target_database=$(/usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c 'import json,re,sys; d=json.load(sys.stdin); n=d.get("target_database"); ok=isinstance(n,str) and re.fullmatch(r"erp[a-z0-9_]{3,60}",n) and d.get("profile") in {"development","cutover"}; sys.exit(65) if not ok or d.get("profile") != sys.argv[1] else print(n)' "$profile" <<< "$preflight_json")

if [[ "$command" == 'preflight' ]]; then
    phase='complete'
    printf 'REBUILD_STATUS=PREFLIGHT_COMPLETE PROFILE=%s TARGET=%s\n' "$profile" "$target_database"
    exit 0
fi

if [[ "$command" == 'reconcile' ]]; then
    phase='reconciliation-read-only-retry'
    set +e
    reconciliation_json=$(/usr/bin/nice -n 19 /usr/bin/ionice -c 3 /usr/bin/env -i "PATH=$SYSTEM_PATH" ERP_P2_ROOT_LAUNCHER_VERIFIED=1 "$python_bin" -E -B -m tools.migracao_pg.loader reconcile \
        --profile "$profile" \
        --bundle-name "$bundle" \
        --source-defaults-file "$source_defaults_file" \
        --report-file "$report_file" \
        --retry-read-only)
    reconciliation_exit=$?
    set -e
    emit_summary reconcile "$reconciliation_json"
    if (( reconciliation_exit != 0 )) || ! printf '%s' "$reconciliation_json" | /usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("status") == "RECONCILED" else 1)'; then
        printf 'REBUILD_ERROR=RECONCILIATION_NOT_ACCEPTED\n' >&2
        exit 78
    fi
    phase='complete'
    printf 'REBUILD_STATUS=RECONCILIATION_COMPLETE PROFILE=%s TARGET=%s\n' "$profile" "$target_database"
    exit 0
fi

if ! printf '%s' "$preflight_json" | /usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("bundle_executable_complete") is True else 1)'; then
    printf 'REBUILD_ERROR=BUNDLE_REQUIRES_REVIEW\n' >&2
    exit 78
fi

phase='target-rebuild'
load_result=$(/usr/bin/nice -n 19 /usr/bin/ionice -c 3 /usr/bin/env -i "PATH=$SYSTEM_PATH" ERP_P2_ROOT_LAUNCHER_VERIFIED=1 "$python_bin" -E -B -m tools.migracao_pg.loader load \
    --profile "$profile" \
    --bundle-name "$bundle" \
    --source-defaults-file "$source_defaults_file" \
    --report-file "$report_file")
emit_summary load "$load_result"
load_complete=0
if printf '%s' "$load_result" | /usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("status") == "LOAD_COMMITTED_PENDING_RECONCILIATION" else 1)'; then
    load_complete=1
fi

phase='reconciliation'
set +e
reconciliation_result=$(/usr/bin/nice -n 19 /usr/bin/ionice -c 3 /usr/bin/env -i "PATH=$SYSTEM_PATH" ERP_P2_ROOT_LAUNCHER_VERIFIED=1 "$python_bin" -E -B -m tools.migracao_pg.loader reconcile \
    --profile "$profile" \
    --bundle-name "$bundle" \
    --source-defaults-file "$source_defaults_file" \
    --report-file "$report_file")
reconciliation_exit=$?
set -e
emit_summary reconcile "$reconciliation_result"
if (( load_complete != 1 || reconciliation_exit != 0 )) || ! printf '%s' "$reconciliation_result" | /usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("status") == "RECONCILED" else 1)'; then
    printf 'REBUILD_ERROR=RECONCILIATION_NOT_ACCEPTED\n' >&2
    exit 78
fi

phase='complete'
runner_finished_epoch=$(/usr/bin/date +%s)
printf 'REBUILD_STATUS=LOAD_RECONCILED PROFILE=%s TARGET=%s ELAPSED_SECONDS=%s\n' "$profile" "$target_database" "$((runner_finished_epoch - runner_started_epoch))"
