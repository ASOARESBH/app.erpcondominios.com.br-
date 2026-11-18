#!/usr/bin/env -S -i PATH=/usr/sbin:/usr/bin:/sbin:/bin /bin/bash --noprofile --norc
set -Eeuo pipefail
umask 077

readonly TARGET_DATABASE='erp_pg_dev'
readonly REPORT_ROOT='/var/backups/erpcondominios/p2-runs'
readonly LOCK_FILE='/run/lock/erp-p2-development.lock'
readonly ALLOWED_PROFILE='development'
readonly DEFAULT_PYTHON='/var/www/erpcondominios-pg/p2-venv/bin/python'
readonly RELEASE_ROOT='/var/www/erpcondominios-pg/releases'
readonly SYSTEM_PATH='/usr/sbin:/usr/bin:/sbin:/bin'

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
    printf 'Uso: %s development {preflight|load} <nome-bundle> <arquivo-defaults-source>\n' "$0" >&2
    exit 64
}

[[ $# -eq 4 ]] || usage
profile=$1
command=$2
bundle=$3
source_defaults_file=$4
[[ "$profile" == "$ALLOWED_PROFILE" ]] || { printf 'REBUILD_ERROR=PROFILE_NOT_ALLOWED\n' >&2; exit 64; }
[[ "$command" == 'preflight' || "$command" == 'load' ]] || usage
[[ "$bundle" =~ ^catalog-[a-f0-9]{12}-collation-[a-f0-9]{8}-role-[a-f0-9]{8}-code-[a-f0-9]{8}$ ]] || {
    printf 'REBUILD_ERROR=BUNDLE_NAME_INVALID\n' >&2
    exit 64
}
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

phase='bundle-validation'
report_file=''
if [[ "$command" == 'load' ]]; then
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
    --bundle-name "$bundle" \
    --source-defaults-file "$source_defaults_file")
printf '%s\n' "$preflight_json"

if [[ "$command" == 'preflight' ]]; then
    phase='complete'
    printf 'REBUILD_STATUS=PREFLIGHT_COMPLETE TARGET=%s\n' "$TARGET_DATABASE"
    exit 0
fi

if ! printf '%s' "$preflight_json" | /usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("bundle_executable_complete") is True else 1)'; then
    printf 'REBUILD_ERROR=BUNDLE_REQUIRES_REVIEW\n' >&2
    exit 78
fi

phase='target-rebuild'
load_result=$(/usr/bin/nice -n 19 /usr/bin/ionice -c 3 /usr/bin/env -i "PATH=$SYSTEM_PATH" ERP_P2_ROOT_LAUNCHER_VERIFIED=1 "$python_bin" -E -B -m tools.migracao_pg.loader load \
    --bundle-name "$bundle" \
    --source-defaults-file "$source_defaults_file" \
    --report-file "$report_file")
printf '%s\n' "$load_result"

phase='reconciliation'
reconciliation_result=$(/usr/bin/nice -n 19 /usr/bin/ionice -c 3 /usr/bin/env -i "PATH=$SYSTEM_PATH" ERP_P2_ROOT_LAUNCHER_VERIFIED=1 "$python_bin" -E -B -m tools.migracao_pg.loader reconcile \
    --bundle-name "$bundle" \
    --source-defaults-file "$source_defaults_file" \
    --report-file "$report_file")
printf '%s\n' "$reconciliation_result"
if ! printf '%s' "$reconciliation_result" | /usr/bin/env -i "PATH=$SYSTEM_PATH" "$python_bin" -E -B -c 'import json,sys; d=json.load(sys.stdin); sys.exit(0 if d.get("status") == "RECONCILED" else 1)'; then
    printf 'REBUILD_ERROR=RECONCILIATION_NOT_ACCEPTED\n' >&2
    exit 78
fi

phase='complete'
runner_finished_epoch=$(/usr/bin/date +%s)
printf 'REBUILD_STATUS=LOAD_RECONCILED TARGET=%s ELAPSED_SECONDS=%s\n' "$TARGET_DATABASE" "$((runner_finished_epoch - runner_started_epoch))"
