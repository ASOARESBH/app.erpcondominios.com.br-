#!/usr/bin/env bash
set -euo pipefail
umask 077

readonly EXPECTED_SERVER_IPV4='2.29.161.106'
readonly EXPECTED_DB_NAME='erpcondominios_stage_20261114'
readonly EXPECTED_MIGRATION_SHA256='a5d402a17e3789223a9d5a297b0c12eb78fa26232ebd6eb8dd8c1f575f5aa375'
readonly BASE_MAIN_SHA='2ad4b9ec652435ccdc21fb754bbdbfeca54f0ed4'
readonly CONFIG_PATH='/etc/erpcondominios/erp_config.php'
readonly BACKUP_DIR='/var/backups/erpcondominios/daily'
readonly BACKUP_STATE_FILE='/var/lib/erpcondominios/backup-integrity-verified-at'
readonly MIGRATION_STATE_DIR='/var/lib/erpcondominios/migrations'
readonly MIGRATION_STATE_FILE="$MIGRATION_STATE_DIR/chatin-v2-applied"
readonly MIGRATION_ATTEMPT_FILE="$MIGRATION_STATE_DIR/chatin-v2-attempt"
readonly MIGRATION_LOCK_DIR='/run/lock/erpcondominios'
readonly MIGRATION_LOCK_FILE='/run/lock/erpcondominios/chatin-v2-migration.lock'
readonly LOG_DIR='/var/lib/erpcondominios/migrations/logs'
readonly MYSQL_BIN='/usr/bin/mysql'
readonly PHP_BIN='/usr/bin/php8.3'
readonly PROCEDURE_NAME='sp_erp_chatin_v2_20261114'

usage() {
    cat <<'EOF'
Uso:
  migrate-chatin-v2-production.sh --check
  migrate-chatin-v2-production.sh --apply

--check  somente leitura: verifica servidor, schema, checksum e estado da migration.
--apply  exige root, backup novo íntegro e confirmação digitada no TTY; aplica uma vez.

A migration é destinada exclusivamente ao MySQL 8.4 em 2.29.161.106,
no schema configurado erpcondominios_stage_20261114. Não use em outro alvo.
EOF
}

fail() {
    if [[ -n "${log_file:-}" && -f "${log_file:-}" ]]; then
        printf 'runner_abort_utc=%s\nrunner_abort_reason=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" >> "$log_file" 2>/dev/null || true
    fi
    printf 'chatin-v2 migration: %s\n' "$1" >&2
    exit 1
}

if [[ $# -ne 1 ]]; then
    usage >&2
    exit 64
fi
MODE=$1
if [[ "$MODE" == '--help' || "$MODE" == '-h' ]]; then
    usage
    exit 0
fi
[[ "$MODE" == '--check' || "$MODE" == '--apply' ]] || {
    usage >&2
    exit 64
}

[[ $EUID -eq 0 ]] || fail 'execute como root; nenhuma alteração foi feita.'
[[ -d '/var/lib/erpcondominios' && ! -L '/var/lib/erpcondominios' \
    && "$(stat -c '%a:%U:%G' /var/lib/erpcondominios)" == '700:root:root' ]] \
    || fail 'diretório privado de estado /var/lib/erpcondominios não corresponde ao contrato esperado.'
if [[ -L "$MIGRATION_STATE_DIR" ]]; then
    fail 'diretório de estado da migration é symlink.'
fi
if [[ -e "$MIGRATION_STATE_DIR" ]]; then
    [[ -d "$MIGRATION_STATE_DIR" && "$(stat -c '%a:%U:%G' "$MIGRATION_STATE_DIR")" == '700:root:root' ]] \
        || fail 'diretório de estado da migration não é root-only.'
fi
for marker in "$MIGRATION_ATTEMPT_FILE" "$MIGRATION_STATE_FILE"; do
    if [[ -L "$marker" ]]; then
        fail 'marcador da migration é symlink; diagnóstico manual obrigatório.'
    fi
    if [[ -e "$marker" ]]; then
        [[ -f "$marker" && "$(stat -c '%a:%U:%G:%h' "$marker")" == '600:root:root:1' ]] \
            || fail 'marcador da migration não é arquivo root-only regular.'
    fi
done

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
RELEASE_ROOT=$(cd -- "$SCRIPT_DIR/../.." && pwd -P)
RELEASE_ID=$(basename -- "$RELEASE_ROOT")
readonly REPO_ROOT="$RELEASE_ROOT"
readonly SQL_FILE="$REPO_ROOT/sql/migration_chatin_v2_mysql.sql"
[[ "$RELEASE_ID" =~ ^[0-9a-f]{40}$ && ! -e "$RELEASE_ROOT/.git" ]] \
    || fail 'execute somente de release imutável identificada por SHA; nenhuma alteração foi feita.'

[[ -x "$MYSQL_BIN" ]] || fail 'cliente MySQL esperado não está instalado.'
[[ -x "$PHP_BIN" ]] || fail 'PHP CLI esperado não está instalado.'
[[ -f "$SQL_FILE" && ! -L "$SQL_FILE" && -r "$SQL_FILE" ]] || fail 'arquivo SQL da migration não está disponível como arquivo regular.'
sql_mode=$(stat -c '%a' "$SQL_FILE")
[[ "$sql_mode" =~ ^[0-7]{3,4}$ ]] || fail 'modo de arquivo SQL inválido.'
(( (8#$sql_mode & 0022) == 0 )) || fail 'arquivo SQL é gravável por grupo/outros; nenhuma alteração foi feita.'

actual_sql_sha=$(sha256sum "$SQL_FILE" | awk '{print $1}')
[[ "$actual_sql_sha" == "$EXPECTED_MIGRATION_SHA256" ]] \
    || fail 'checksum da migration não corresponde ao SQL revisado; nenhuma alteração foi feita.'

if ! ip -o -4 addr show scope global | awk '{sub(/\/.*/, "", $4); print $4}' | grep -Fxq "$EXPECTED_SERVER_IPV4"; then
    fail 'este host não corresponde ao IPv4 Hetzner autorizado; nenhuma alteração foi feita.'
fi

[[ -f "$CONFIG_PATH" && ! -L "$CONFIG_PATH" && -r "$CONFIG_PATH" ]] \
    || fail 'configuração privada da aplicação não está disponível.'
[[ "$(stat -c '%a:%U:%G' "$CONFIG_PATH")" == '640:root:www-data' ]] \
    || fail 'permissões da configuração privada divergem do contrato esperado.'

config_values=$(
    "$PHP_BIN" -d display_errors=0 -r '
        require $argv[1];
        foreach (["ERP_DB_HOST", "ERP_DB_NAME", "ERP_DB_USER", "ERP_DB_PASS"] as $name) {
            if (!defined($name)) { exit(3); }
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new mysqli(ERP_DB_HOST, ERP_DB_USER, ERP_DB_PASS, ERP_DB_NAME);
        if ($db->connect_errno) { exit(4); }
        $result = $db->query("SELECT DATABASE(), @@server_uuid, @@hostname, @@port, @@socket, VERSION()");
        if (!$result) { exit(5); }
        $row = $result->fetch_row();
        if (!$row || count($row) !== 6) { exit(6); }
        echo ERP_DB_HOST, "\t", ERP_DB_NAME, "\t", implode("\t", array_map("strval", $row)), "\n";
        $db->close();
    ' "$CONFIG_PATH" 2>/dev/null
) || fail 'não foi possível validar a configuração privada sem expor valores.'
IFS=$'\t' read -r app_host app_db app_dbname app_server_uuid app_hostname app_port app_socket app_version <<< "$config_values"
[[ -n "$app_dbname" && -n "$app_server_uuid" && -n "$app_hostname" && -n "$app_port" && -n "$app_socket" && -n "$app_version" ]] \
    || fail 'formato inesperado na identidade MySQL da aplicação.'
[[ "$app_host" == 'localhost' ]] || fail 'o MySQL configurado não é local; nenhuma alteração foi feita.'
[[ "$app_db" == "$EXPECTED_DB_NAME" && "$app_dbname" == "$EXPECTED_DB_NAME" ]] \
    || fail 'o schema ativo não corresponde ao alvo fixado; nenhuma alteração foi feita.'

for service in mysql.service nginx.service php8.3-fpm.service; do
    systemctl is-active --quiet "$service" \
        || fail "serviço obrigatório indisponível ($service); nenhuma alteração foi feita."
done

mysql_root() {
    "$MYSQL_BIN" --no-defaults --protocol=socket --host=localhost --user=root --batch --skip-column-names --silent "$@"
}

cli_identity=$(mysql_root --database="$EXPECTED_DB_NAME" --execute='SELECT DATABASE(), @@server_uuid, @@hostname, @@port, @@socket, VERSION()' 2>/dev/null) \
    || fail 'não foi possível consultar a identidade MySQL pelo socket local.'
IFS=$'\t' read -r cli_db cli_server_uuid cli_hostname cli_port cli_socket DB_VERSION <<< "$cli_identity"
[[ "$DB_VERSION" == 8.4.* && "$DB_VERSION" != *MariaDB* ]] \
    || fail 'o servidor não é MySQL 8.4; nenhuma alteração foi feita.'
[[ "$cli_db" == "$app_dbname" && "$cli_server_uuid" == "$app_server_uuid" \
    && "$cli_hostname" == "$app_hostname" && "$cli_port" == "$app_port" \
    && "$cli_socket" == "$app_socket" && "$DB_VERSION" == "$app_version" ]] \
    || fail 'o cliente MySQL CLI não aponta à mesma instância usada pela aplicação.'

mysql_root --database="$EXPECTED_DB_NAME" --execute='SELECT 1' >/dev/null 2>&1 \
    || fail 'o schema alvo não está acessível pelo socket local.'

base_tables=$(mysql_root --database="$EXPECTED_DB_NAME" --execute="
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_TYPE = 'BASE TABLE'
       AND TABLE_NAME IN (
         'tenants','chatin_conversas','chatin_mensagens','chatin_preferencias','chatin_presenca',
         'rbac_modulos','rbac_permissoes','rbac_grupos','rbac_grupo_permissoes',
         'rbac_configuracoes','rbac_auditoria','pwa_configuracoes'
       )
" 2>/dev/null) || fail 'não foi possível verificar os objetos base da migration.'
[[ "$base_tables" == '12' ]] || fail 'objetos base Chat-In/RBAC/PWA incompletos; nenhuma alteração foi feita.'
tenant_count=$(mysql_root --database="$EXPECTED_DB_NAME" --execute='SELECT COUNT(*) FROM tenants' 2>/dev/null) \
    || fail 'não foi possível validar a existência de tenants no schema-alvo.'
[[ "$tenant_count" =~ ^[0-9]+$ ]] && (( tenant_count > 0 )) \
    || fail 'schema-alvo sem tenants; nenhuma alteração foi feita.'

migration_state_count() {
    local flags a b c d e f g total
    flags=$(mysql_root --database="$EXPECTED_DB_NAME" --execute="
        SELECT
          EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_configuracoes'
               AND TABLE_TYPE = 'BASE TABLE'
          ),
          (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_preferencias'
              AND COLUMN_NAME IN ('som_ativo','som_tipo','som_volume','notificacao_navegador','permitir_cutucar')),
          (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_presenca'
              AND COLUMN_NAME IN ('digitando_conversa_id','digitando_ate')),
          EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatin_mensagens'
               AND COLUMN_NAME = 'tipo' AND COLUMN_TYPE LIKE '%CUTUCAR%'
          ),
          EXISTS (
            SELECT 1 FROM rbac_modulos
             WHERE chave = 'chatin' AND ativo = 1
          ),
          (SELECT COUNT(DISTINCT p.acao)
             FROM rbac_modulos m JOIN rbac_permissoes p ON p.modulo_id = m.id
            WHERE m.chave = 'chatin' AND p.acao IN ('visualizar','configurar')),
          (SELECT COUNT(DISTINCT tc.CONSTRAINT_NAME)
             FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
            WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
              AND tc.TABLE_NAME = 'chatin_preferencias'
              AND tc.CONSTRAINT_TYPE = 'CHECK'
              AND tc.CONSTRAINT_NAME IN ('chk_chatin_som_volume','chk_chatin_som_tipo'))
    " 2>/dev/null) || return 1
    IFS=$'\t' read -r a b c d e f g <<< "$flags"
    for flag in "$a" "$b" "$c" "$d" "$e" "$f" "$g"; do
        [[ "$flag" =~ ^[0-9]+$ ]] || return 1
    done
    (( b <= 5 && c <= 2 && f <= 2 && g <= 2 )) || return 1
    total=$((a + b + c + d + e + f + g))
    printf '%s\n' "$total"
}

STATE_COUNT=$(migration_state_count) || fail 'não foi possível avaliar o estado estrutural da migration.'
if (( STATE_COUNT != 0 && STATE_COUNT != 14 )); then
    fail 'estado estrutural parcial detectado; não aplique/reaplique automaticamente. Faça diagnóstico read-only.'
fi
if (( STATE_COUNT == 0 )) && { [[ -e "$MIGRATION_ATTEMPT_FILE" || -L "$MIGRATION_ATTEMPT_FILE" ]] \
    || [[ -e "$MIGRATION_STATE_FILE" || -L "$MIGRATION_STATE_FILE" ]]; }; then
    fail 'marcador anterior incompatível com schema não migrado; faça diagnóstico read-only e não reaplique.'
fi
if [[ -f "$MIGRATION_ATTEMPT_FILE" ]]; then
    grep -Fxq "database=$EXPECTED_DB_NAME" "$MIGRATION_ATTEMPT_FILE" \
        && grep -Fxq "base_main_sha=$BASE_MAIN_SHA" "$MIGRATION_ATTEMPT_FILE" \
        && grep -Fxq "sql_sha256=$EXPECTED_MIGRATION_SHA256" "$MIGRATION_ATTEMPT_FILE" \
        || fail 'marcador de tentativa pertence a outro alvo/versão; diagnóstico manual obrigatório.'
fi

temporary_routine_count=$(mysql_root --database="$EXPECTED_DB_NAME" --execute="
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.ROUTINES
     WHERE ROUTINE_SCHEMA = DATABASE()
       AND ROUTINE_NAME = '$PROCEDURE_NAME'
       AND ROUTINE_TYPE = 'PROCEDURE'
" 2>/dev/null) || fail 'não foi possível verificar a rotina auxiliar da migration.'
[[ "$temporary_routine_count" == '0' ]] \
    || fail 'rotina temporária de execução anterior ainda existe; faça diagnóstico read-only e não reaplique.'

MIGRATION_COMPLETE=0
if (( STATE_COUNT == 14 )); then
    missing_tenant_configs=$(mysql_root --database="$EXPECTED_DB_NAME" --execute="
        SELECT COUNT(*)
          FROM tenants t
          LEFT JOIN chatin_configuracoes c ON c.tenant_id = t.id
         WHERE c.tenant_id IS NULL
    " 2>/dev/null) || fail 'não foi possível verificar a configuração Chat-In por tenant.'
    missing_group_permissions=$(mysql_root --database="$EXPECTED_DB_NAME" --execute="
        SELECT COUNT(*)
          FROM rbac_grupos g
          JOIN rbac_modulos m ON m.chave = 'chatin'
          JOIN rbac_permissoes p ON p.modulo_id = m.id AND p.acao = 'visualizar'
          LEFT JOIN rbac_grupo_permissoes gp ON gp.grupo_id = g.id AND gp.permissao_id = p.id
         WHERE g.tenant_id IS NOT NULL AND gp.id IS NULL
    " 2>/dev/null) || fail 'não foi possível verificar os grants de visualização RBAC.'
    [[ "$missing_tenant_configs" =~ ^[0-9]+$ && "$missing_group_permissions" =~ ^[0-9]+$ ]] \
        || fail 'resultado inválido ao verificar linhas de configuração ou grants RBAC.'
    [[ "$missing_tenant_configs" == '0' && "$missing_group_permissions" == '0' ]] \
        || fail 'estrutura existe, mas configuração tenant ou seed RBAC está incompleto; interrompido para diagnóstico.'
    [[ -f "$MIGRATION_STATE_FILE" && ! -L "$MIGRATION_STATE_FILE" ]] \
        || fail 'estrutura parece completa sem marcador root-only de conclusão; não aceite nem reaplique automaticamente.'
    grep -Fxq "database=$EXPECTED_DB_NAME" "$MIGRATION_STATE_FILE" \
        && grep -Fxq "base_main_sha=$BASE_MAIN_SHA" "$MIGRATION_STATE_FILE" \
        && grep -Fxq "sql_sha256=$EXPECTED_MIGRATION_SHA256" "$MIGRATION_STATE_FILE" \
        && grep -Eq '^release_sha=[0-9a-f]{40}$' "$MIGRATION_STATE_FILE" \
        || fail 'marcador de conclusão não corresponde ao alvo/SQL esperados.'
    MIGRATION_COMPLETE=1
fi

print_check_summary() {
    printf 'Host: PASS (%s)\n' "$EXPECTED_SERVER_IPV4"
    printf 'Configuração: PASS (schema-alvo validado; valores privados omitidos)\n'
    printf 'MySQL: PASS (%s, socket local)\n' "$DB_VERSION"
    printf 'Release SHA: %s\n' "$RELEASE_ID"
    printf 'Objetos base: PASS\n'
    printf 'Checksum SQL: PASS (%s)\n' "$EXPECTED_MIGRATION_SHA256"
    if (( MIGRATION_COMPLETE == 1 )); then
        printf 'Estado da migration: COMPLETA (nenhuma reaplicação necessária)\n'
    else
        printf 'Estado da migration: NÃO APLICADA (pré-condições estruturais passaram)\n'
    fi
    printf 'SHA-base main do runner: %s\n' "$BASE_MAIN_SHA"
}

if [[ "$MODE" == '--check' ]]; then
    print_check_summary
    printf '%s\n' 'CHECK PASS: nenhuma escrita, backup, log ou serviço foi iniciado.'
    exit 0
fi

if (( MIGRATION_COMPLETE == 1 )); then
    print_check_summary
    printf '%s\n' 'Migration já completa; nenhuma operação foi executada.'
    exit 0
fi
(( STATE_COUNT == 0 )) \
    || fail 'a migration não está em estado totalmente não aplicado; nenhuma execução automática permitida.'

[[ -r /dev/tty && -w /dev/tty ]] || fail '--apply requer TTY interativo.'
if [[ -L "$MIGRATION_LOCK_DIR" ]]; then
    fail 'diretório de lock é symlink; nenhuma alteração foi feita.'
fi
if [[ ! -e "$MIGRATION_LOCK_DIR" ]]; then
    mkdir -m 0700 -- "$MIGRATION_LOCK_DIR" 2>/dev/null || true
fi
[[ -d "$MIGRATION_LOCK_DIR" && ! -L "$MIGRATION_LOCK_DIR" \
    && "$(stat -c '%a:%U:%G' "$MIGRATION_LOCK_DIR")" == '700:root:root' ]] \
    || fail 'diretório de lock não é root-only; nenhuma alteração foi feita.'
if [[ ! -e "$MIGRATION_LOCK_FILE" ]]; then
    ( set -o noclobber; : > "$MIGRATION_LOCK_FILE" ) 2>/dev/null \
        || fail 'não foi possível criar arquivo de lock sem sobrescrita.'
    chmod 0600 "$MIGRATION_LOCK_FILE"
fi
[[ -f "$MIGRATION_LOCK_FILE" && ! -L "$MIGRATION_LOCK_FILE" \
    && "$(stat -c '%a:%U:%G:%h' "$MIGRATION_LOCK_FILE")" == '600:root:root:1' ]] \
    || fail 'arquivo de lock não é um arquivo root-only regular.'
exec 9<>"$MIGRATION_LOCK_FILE"
flock -n 9 || fail 'outra execução da migration está em andamento.'

printf '\nATENÇÃO: --apply executará DDL e seeds RBAC no schema ativo.\n' >/dev/tty
printf 'Após a confirmação, o runner exigirá backup novo e integridade antes de qualquer SQL.\n' >/dev/tty
printf 'Digite exatamente: APLICAR chatin-v2 em %s\n> ' "$EXPECTED_DB_NAME" >/dev/tty
IFS= read -r confirmation </dev/tty || fail 'não foi possível ler confirmação interativa.'
[[ "$confirmation" == "APLICAR chatin-v2 em $EXPECTED_DB_NAME" ]] \
    || fail 'confirmação não corresponde; nenhuma alteração foi feita.'

[[ -d "$BACKUP_DIR" && ! -L "$BACKUP_DIR" ]] || fail 'diretório de backups não está provisionado como diretório regular.'
[[ "$(stat -c '%a:%U:%G' "$BACKUP_DIR")" == '700:root:root' ]] \
    || fail 'permissões do diretório de backups divergem do contrato esperado.'
free_bytes=$(df -PB1 "$BACKUP_DIR" | awk 'NR == 2 {print $4}')
[[ "$free_bytes" =~ ^[0-9]+$ ]] || fail 'não foi possível verificar espaço livre.'
(( free_bytes >= 10 * 1024 * 1024 * 1024 )) || fail 'menos de 10 GiB livres para produzir backup.'

for unit in erpcondominios-backup.service erpcondominios-backup-integrity.service; do
    unit_state=$(systemctl show --property=LoadState --value "$unit" 2>/dev/null || true)
    [[ "$unit_state" == 'loaded' ]] || fail "unit de backup/verificação indisponível ($unit)."
done

latest_backup() {
    [[ -d "$BACKUP_DIR" ]] || return 0
    find "$BACKUP_DIR" -maxdepth 1 -type f -name 'erpcondominios-*.sql.gz' -printf '%T@ %p\n' \
        | sort -nr | sed -n '1s/^[^ ]* //p'
}

previous_backup=$(latest_backup)
backup_started=$(date -u +%s)
systemctl start erpcondominios-backup.service >/dev/null 2>&1 \
    || fail 'backup novo falhou; migration não foi iniciada.'
systemctl start erpcondominios-backup-integrity.service >/dev/null 2>&1 \
    || fail 'verificação de integridade falhou; migration não foi iniciada.'

newest_backup=$(latest_backup)
[[ -n "$newest_backup" && "$newest_backup" != "$previous_backup" ]] \
    || fail 'não foi identificado um backup novo; migration não foi iniciada.'
newest_mtime=$(stat -c '%Y' "$newest_backup")
(( newest_mtime >= backup_started )) || fail 'backup encontrado não é posterior ao início desta execução.'
[[ "$(stat -c '%a:%U:%G' "$newest_backup")" == '600:root:root' ]] \
    || fail 'permissões do backup novo divergem do contrato esperado.'
[[ "$(stat -c '%h' "$newest_backup")" == '1' ]] \
    || fail 'backup novo possui hardlink inesperado.'
manifest="$newest_backup.sha256"
[[ -f "$manifest" && ! -L "$manifest" && "$(stat -c '%a:%U:%G' "$manifest")" == '600:root:root' ]] \
    || fail 'manifesto SHA-256 do backup está ausente ou com permissões incorretas.'
gzip -t "$newest_backup" >/dev/null 2>&1 || fail 'gzip do backup novo não passou na validação.'
(
    cd "$BACKUP_DIR"
    sha256sum -c "$(basename "$manifest")" >/dev/null 2>&1
) || fail 'checksum do backup novo não confere.'
[[ -f "$BACKUP_STATE_FILE" && ! -L "$BACKUP_STATE_FILE" ]] \
    || fail 'marcador de integridade do backup não foi criado.'
[[ "$(stat -c '%a:%U:%G' "$BACKUP_STATE_FILE")" == '600:root:root' ]] \
    || fail 'permissões do marcador de integridade divergem do contrato esperado.'
[[ "$(stat -c '%h' "$BACKUP_STATE_FILE")" == '1' ]] \
    || fail 'marcador de integridade possui hardlink inesperado.'
integrity_epoch=$(<"$BACKUP_STATE_FILE")
[[ "$integrity_epoch" =~ ^[0-9]+$ ]] && (( integrity_epoch >= backup_started && integrity_epoch >= newest_mtime )) \
    || fail 'marcador de integridade não confirma o backup recém-criado.'

[[ "$(migration_state_count)" == '0' ]] \
    || fail 'estado do schema mudou após o backup; migration interrompida.'
final_app_identity=$(
    "$PHP_BIN" -d display_errors=0 -r '
        require $argv[1];
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new mysqli(ERP_DB_HOST, ERP_DB_USER, ERP_DB_PASS, ERP_DB_NAME);
        if ($db->connect_errno) { exit(4); }
        $result = $db->query("SELECT DATABASE(), @@server_uuid, @@hostname, @@port, @@socket, VERSION()");
        if (!$result) { exit(5); }
        $row = $result->fetch_row();
        if (!$row || count($row) !== 6) { exit(6); }
        echo ERP_DB_HOST, "\t", ERP_DB_NAME, "\t", implode("\t", array_map("strval", $row)), "\n";
        $db->close();
    ' "$CONFIG_PATH" 2>/dev/null
) || fail 'não foi possível revalidar a instância da aplicação antes do SQL.'
IFS=$'\t' read -r final_host final_db final_dbname final_uuid final_hostname final_port final_socket final_version <<< "$final_app_identity"
[[ "$final_host" == 'localhost' && "$final_db" == "$EXPECTED_DB_NAME" && "$final_dbname" == "$EXPECTED_DB_NAME" \
    && "$final_uuid" == "$app_server_uuid" && "$final_hostname" == "$app_hostname" \
    && "$final_port" == "$app_port" && "$final_socket" == "$app_socket" && "$final_version" == "$app_version" ]] \
    || fail 'a identidade MySQL da aplicação mudou durante o backup; migration interrompida.'
final_cli_identity=$(mysql_root --database="$EXPECTED_DB_NAME" --execute='SELECT DATABASE(), @@server_uuid, @@hostname, @@port, @@socket, VERSION()' 2>/dev/null) \
    || fail 'não foi possível revalidar o socket MySQL antes do SQL.'
[[ "$final_cli_identity" == "$cli_identity" ]] \
    || fail 'a identidade do socket MySQL mudou durante o backup; migration interrompida.'
actual_sql_sha=$(sha256sum "$SQL_FILE" | awk '{print $1}')
[[ "$actual_sql_sha" == "$EXPECTED_MIGRATION_SHA256" ]] \
    || fail 'checksum SQL mudou durante a preparação; migration interrompida.'

install -d -o root -g root -m 0700 "$LOG_DIR"
chmod 0700 "$LOG_DIR"
log_file="$LOG_DIR/chatin-v2-$(date -u +%Y%m%dT%H%M%SZ)-$$.log"
( set -o noclobber; : > "$log_file" ) 2>/dev/null \
    || fail 'não foi possível criar o log root-only da migration.'
chmod 0600 "$log_file"
install -d -o root -g root -m 0700 "$MIGRATION_STATE_DIR"
chmod 0700 "$MIGRATION_STATE_DIR"
( set -o noclobber; printf 'attempted_utc=%s\ndatabase=%s\nrelease_sha=%s\nbase_main_sha=%s\nsql_sha256=%s\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$EXPECTED_DB_NAME" "$RELEASE_ID" "$BASE_MAIN_SHA" "$EXPECTED_MIGRATION_SHA256" \
    > "$MIGRATION_ATTEMPT_FILE" ) 2>/dev/null \
    || fail 'não foi possível criar marcador de tentativa sem sobrescrita.'
chmod 0600 "$MIGRATION_ATTEMPT_FILE"
printf 'started_utc=%s\ndatabase=%s\nmysql_version=%s\nrelease_sha=%s\nbase_main_sha=%s\nsql_sha256=%s\nbackup_integrity=PASS\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$EXPECTED_DB_NAME" "$DB_VERSION" "$RELEASE_ID" "$BASE_MAIN_SHA" "$EXPECTED_MIGRATION_SHA256" \
    >> "$log_file"

if mysql_root --database="$EXPECTED_DB_NAME" < "$SQL_FILE" >/dev/null 2>&1; then
    migration_exit=0
else
    migration_exit=$?
fi
if (( migration_exit != 0 )); then
    if mysql_root --database="$EXPECTED_DB_NAME" --execute="DROP PROCEDURE IF EXISTS \`$PROCEDURE_NAME\`" >/dev/null 2>&1; then
        printf 'temporary_procedure_cleanup=PASS\n' >> "$log_file"
    else
        printf 'temporary_procedure_cleanup=FAIL\n' >> "$log_file"
    fi
    printf 'migration_exit=%s\nresult=FAILED_POSSIBLE_PARTIAL_DDL_NO_RETRY\n' "$migration_exit" >> "$log_file"
    fail "MySQL informou falha (código $migration_exit). Pode haver DDL parcial; não reaplique. Log privado: $log_file"
fi

STATE_COUNT=$(migration_state_count) || fail "não foi possível validar pós-condições; preserve o estado e diagnostique. Log privado: $log_file"
(( STATE_COUNT == 14 )) || {
    printf 'postflight_structural_flags=%s_of_14\nresult=POSTFLIGHT_INCOMPLETE_NO_RETRY\n' "$STATE_COUNT" >> "$log_file"
    fail "pós-condições estruturais incompletas; não reaplique. Log privado: $log_file"
}
temporary_routine_count=$(mysql_root --database="$EXPECTED_DB_NAME" --execute="
    SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.ROUTINES
     WHERE ROUTINE_SCHEMA = DATABASE()
       AND ROUTINE_NAME = '$PROCEDURE_NAME'
       AND ROUTINE_TYPE = 'PROCEDURE'
" 2>/dev/null) || fail "não foi possível validar a limpeza da procedure temporária. Log privado: $log_file"
[[ "$temporary_routine_count" == '0' ]] \
    || fail "procedure temporária não foi removida; preserve o estado e não reaplique. Log privado: $log_file"

missing_configs=$(mysql_root --database="$EXPECTED_DB_NAME" --execute="
    SELECT COUNT(*)
      FROM tenants t
      LEFT JOIN chatin_configuracoes c ON c.tenant_id = t.id
     WHERE c.tenant_id IS NULL
" 2>/dev/null) || fail "não foi possível verificar a configuração por tenant. Log privado: $log_file"
[[ "$missing_configs" == '0' ]] \
    || fail "há tenants sem linha de configuração; não reaplique. Log privado: $log_file"

missing_group_permissions=$(mysql_root --database="$EXPECTED_DB_NAME" --execute="
    SELECT COUNT(*)
      FROM rbac_grupos g
      JOIN rbac_modulos m ON m.chave = 'chatin'
      JOIN rbac_permissoes p ON p.modulo_id = m.id AND p.acao = 'visualizar'
      LEFT JOIN rbac_grupo_permissoes gp ON gp.grupo_id = g.id AND gp.permissao_id = p.id
     WHERE g.tenant_id IS NOT NULL AND gp.id IS NULL
" 2>/dev/null) || fail "não foi possível verificar o seed RBAC. Log privado: $log_file"
[[ "$missing_group_permissions" == '0' ]] \
    || fail "há grupos sem decisão Chat-In após o seed; não reaplique. Log privado: $log_file"

install -d -o root -g root -m 0700 "$MIGRATION_STATE_DIR"
chmod 0700 "$MIGRATION_STATE_DIR"
state_tmp=$(mktemp "$MIGRATION_STATE_DIR/.chatin-v2-applied.XXXXXX")
trap 'rm -f -- "${state_tmp:-}"' EXIT
printf 'database=%s\napplied_utc=%s\nrelease_sha=%s\nbase_main_sha=%s\nsql_sha256=%s\n' \
    "$EXPECTED_DB_NAME" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$RELEASE_ID" "$BASE_MAIN_SHA" "$EXPECTED_MIGRATION_SHA256" > "$state_tmp"
chmod 0600 "$state_tmp"
mv -- "$state_tmp" "$MIGRATION_STATE_FILE"
trap - EXIT
printf 'postflight=PASS\nresult=APPLIED\n' >> "$log_file"

printf 'Migration Chat-In v2 aplicada e verificada no schema-alvo.\n'
printf 'Log root-only: %s\n' "$log_file"
printf 'Estado de execução root-only: %s\n' "$MIGRATION_STATE_FILE"
printf 'Nenhuma credencial ou linha de negócio foi exibida.\n'
