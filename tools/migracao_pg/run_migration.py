from __future__ import annotations

import argparse
import atexit
import csv
import fcntl
import hashlib
import json
import os
import re
import signal
import stat
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

sys.dont_write_bytecode = True

from common import (generated_name, REHEARSAL_DATABASES, V9_DATABASE,
                    PRESERVED_PARTIAL_V10_DATABASE, V10B_DATABASE)

PGDATA = Path("/var/lib/erp-pg-rehearsal/pg-data")
PGSOCKET = Path("/var/lib/erp-pg-rehearsal/pg-socket")
PGPORT = 55432
PG_PYTHON = Path("/var/lib/erp-pg-rehearsal/v9/venv/bin/python3")
MYSQL_CONTAINER = "erp-mysql-source-20261108"
MYSQL_SECRET_SOURCE = Path("/var/lib/erp-pg-rehearsal/secrets/pgloader.cnf")
MYSQL_ADMIN_CONFIG = Path("/var/lib/erp-pg-rehearsal/secrets/client.cnf")
REPO_ROOT = Path("/opt/erpcondominios/source")
BASELINE_MAIN_COMMIT = "f60739713e6de3f2473b79ecd14a70fd700e5462"
APPROVED_RESUME_RUN_ID = "20261108T181755Z"
APPROVED_RESUME_SOURCE_COMMIT = "95fbf8a458cf86d2fe2d70679ba613526ca1b1de"
APPROVED_RESUME_SOURCE_ROWS = 202609
APPROVED_POST_FK_RUN_ID = "20261108T203651Z"
APPROVED_POST_FK_SOURCE_COMMIT = "d4447f9867cda910122f9b3b9e4d797b425cf5a4"
APPROVED_POST_FK_MANIFEST = Path("/root/erp_pg_v9_post_fk_20261108_manifest.json")
V9_ROOT = Path("/var/lib/erp-pg-rehearsal/v9")
RUN_ROOT = Path("/var/lib/erp-pg-rehearsal/v9/runs")
TMPFS_ROOT = Path("/dev/shm/erp-pg-v9")
V10B_ROOT = Path("/var/lib/erp-pg-rehearsal/v10b")
V10B_RUN_ROOT = V10B_ROOT / "runs"
V10B_TMPFS_ROOT = Path("/dev/shm/erp-pg-v10b")
V10_SOURCE_MANIFEST = Path("/root/erp_pg_v10_source_manifest.json")
V10_SOURCE_DUMP = Path("/var/backups/erpcondominios/ensaio-20261107T233109Z/inlaud99_erpcondor.mysql.sql.gz")
V10_SOURCE_COPY = Path("/var/lib/erp-pg-rehearsal/evidence/source-copy.sql.gz")
V10_SOURCE_RESTORE_STATUS = Path("/var/lib/erp-pg-rehearsal/evidence/mysql_source_restore.status")
V10_SOURCE_CONTAINER_ID_EVIDENCE = Path("/var/lib/erp-pg-rehearsal/evidence/mysql_source_container_id.txt")
V10_SOURCE_RESTORE_ATTESTATION = Path("/root/erp_pg_v10_source_restore_attestation.json")
V10_SOURCE_SCHEMA = "inlaud99_erpcondor"
V10_RESTORE_SCRIPT_SHA256 = "68148cb8f6edefd35ea96d326994d40e85d314914b690a3a78a16b4eb9c01fcc"
V10_EXPECTED_ZERO_DATE_COUNTS = {
    "contas_pagar.data_emissao": 1,
    "contratos.data_vencimento": 9,
    "dependentes.data_nascimento": 213,
    "inventario.data_baixa": 208,
    "inventario.data_compra": 3,
}
V10_EXPECTED_ENUM_COUNTS = {
    "contas_pagar.forma_pagamento": {"empty_enum_values": 1, "out_of_domain_values": 1},
    "contrato_documentos.tipo_documento": {"empty_enum_values": 25, "out_of_domain_values": 25},
    "email_log.tipo": {"empty_enum_values": 19, "out_of_domain_values": 19},
    "produtos_estoque.unidade_medida": {"empty_enum_values": 157, "out_of_domain_values": 157},
    "registros_acesso.tipo": {"empty_enum_values": 10, "out_of_domain_values": 10},
    "rh_banco_horas.tipo": {"empty_enum_values": 1, "out_of_domain_values": 1},
}
V10_EXPECTED_CATALOG_COUNTS = {
    "tables": 270,
    "columns": 3339,
    "views": 0,
    "indexes": 503,
    "primary_keys": 240,
    "foreign_keys": 11,
    "triggers": 7,
    "routines": 2,
    "events": 0,
    "auto_increment_columns": 232,
}
V10_PHASE_SEQUENCE = (
    "source_integrity", "catalog", "target_create", "schema", "constraints_indexes", "load",
    "on_update_triggers", "business_objects", "grants", "business_trigger_tests",
    "foreign_keys_not_valid", "identity_sequences", "analyze", "reconciliation",
)
V10_EXPECTED_SYNTHETIC_TESTS = frozenset({
    "erp_app_minimum_privileges", "on_update_timestamp_semantics",
    "after_qrcode_token_insert", "after_validacao_dispositivo_insert",
    "trg_alerta_estoque_baixo", "trg_atualizar_km_acumulado", "tr_dependente_ativar_veiculos",
    "tr_dependente_inativar_veiculos", "tr_dependente_deletar_veiculos",
    "corrigir_moradores_anexos_tenant", "mt_adicionar_coluna",
})
V10_EXPECTED_SOURCE_COLLATION_COUNTS = {
    "utf8_unicode_ci": 127,
    "utf8mb4_general_ci": 147,
    "utf8mb4_unicode_ci": 1098,
}
V10_EXPECTED_TENANT_SIGNATURE_SHA256 = "d51a1cdc300c7730eaf92bbee6ee6debaf3e947b3831390749d342efb7401f77"
V10_EXPECTED_NO_TENANT_NAMES_SHA256 = "70b1a0fee9e2686abb45bfd10330399a818b4ecf71ec908406a37bf249a4d5ca"
V10_EXPECTED_COLLATE_C_COLUMNS_SHA256 = "556e667799062d5038382723fa50fd7f08d68813ae3e1c02270da9f6d1818741"
V10_EXPECTED_BACKUP_CANDIDATES = {
    "avaliacoes_backup", "documentos_departamentos_migrado_bkp", "mt_os_chamados_id_zero_backup",
    "mt_protocolos_id_zero_backup", "rbac_sessoes_bkp_ids_a_partir_15_20261027",
    "rbac_usuario_grupos_bkp_ids_a_partir_15_20261027", "rbac_usuario_permissoes_bkp_ids_a_partir_15_20261027",
    "sessoes_usuarios_bkp_ids_a_partir_15_20261027", "usuarios_bkp_duplicata_admin_20261027",
    "usuarios_bkp_ids_a_partir_15_20261027", "usuario_modulos_bkp_ids_a_partir_15_20261027",
    "usuario_tenant_bkp_ids_a_partir_15_20261027",
}
V10_EXPECTED_TRIGGER_NAMES = {
    "after_qrcode_token_insert", "after_validacao_dispositivo_insert", "trg_alerta_estoque_baixo",
    "trg_atualizar_km_acumulado", "tr_dependente_ativar_veiculos",
    "tr_dependente_deletar_veiculos", "tr_dependente_inativar_veiculos",
}
V10_EXPECTED_PROCEDURE_NAMES = {"corrigir_moradores_anexos_tenant", "mt_adicionar_coluna"}
V10_EXPECTED_LEGACY_TABLES = {
    "rbac_sessoes_bkp_ids_a_partir_15_20261027",
    "rbac_usuario_grupos_bkp_ids_a_partir_15_20261027",
    "rbac_usuario_permissoes_bkp_ids_a_partir_15_20261027",
    "sessoes_usuarios_bkp_ids_a_partir_15_20261027",
    "usuario_modulos_bkp_ids_a_partir_15_20261027",
    "usuario_tenant_bkp_ids_a_partir_15_20261027",
    "usuarios_bkp_duplicata_admin_20261027",
    "usuarios_bkp_ids_a_partir_15_20261027",
}
CODE_ROOT = Path(__file__).resolve().parent
ACTIVE_PROCESS = None
RUN_LOCK_FD = None
CLUSTER_STARTED_BY_RUNNER = False
CURRENT_PHASE = "preflight"
TARGET_CREATED_BY_RUNNER = False


def as_postgres(*command):
    return ["/usr/sbin/runuser", "-u", "postgres", "--", "env", "-i", "PATH=/usr/bin:/bin",
            "HOME=/var/lib/postgresql", "LANG=C.UTF-8", "LC_ALL=C.UTF-8",
            f"PYTHONPATH={CODE_ROOT}", "PYTHONDONTWRITEBYTECODE=1", *map(str, command)]


def verify_code_provenance(expected_commit: str, expected_branch: str = "pg/03-conversao-schema"):
    repository = CODE_ROOT.parents[1]
    if not re.fullmatch(r"[0-9a-f]{40}", expected_commit):
        raise RuntimeError("an exact code commit is required")
    verify_baseline_ancestry(repository, expected_commit)
    def verify_repo(path: Path, branch_name: str, commit: str):
        def git(*args):
            return subprocess.run(["git", "-C", str(path), *args], capture_output=True, text=True)
        origin, branch, head, status = (git("remote", "get-url", "origin"), git("branch", "--show-current"),
                                        git("rev-parse", "HEAD"), git("status", "--porcelain", "--untracked-files=all"))
        return (origin.returncode == 0 and origin.stdout.strip() == "https://github.com/ASOARESBH/app.erpcondominios.com.br-.git"
                and branch.returncode == 0 and branch.stdout.strip() == branch_name
                and head.returncode == 0 and head.stdout.strip() == commit
                and status.returncode == 0 and not status.stdout.strip())
    if not verify_repo(repository, expected_branch, expected_commit):
        raise RuntimeError("migration code provenance is not the reviewed clean official commit")
    if not verify_repo(REPO_ROOT, "main", BASELINE_MAIN_COMMIT):
        raise RuntimeError("source classification repository is not the approved clean main baseline")


def verify_baseline_ancestry(repository: Path, commit: str):
    ancestry = subprocess.run(["git", "-C", str(repository), "merge-base", "--is-ancestor",
                               BASELINE_MAIN_COMMIT, commit], capture_output=True, text=True)
    if ancestry.returncode != 0:
        raise RuntimeError("migration code commit is not descended from the approved baseline")


def verify_resume_code_ancestry(repository: Path, commit: str,
                                source_commit: str = APPROVED_RESUME_SOURCE_COMMIT):
    ancestry = subprocess.run(["git", "-C", str(repository), "merge-base", "--is-ancestor",
                               source_commit, commit], capture_output=True, text=True)
    if ancestry.returncode != 0:
        raise RuntimeError("resume code commit is not descended from the approved partial-run commit")


def verify_private_mysql_config(path: Path):
    parent = path.parent.lstat()
    if stat.S_ISLNK(parent.st_mode) or not stat.S_ISDIR(parent.st_mode) or parent.st_uid != 0 or stat.S_IMODE(parent.st_mode) != 0o700:
        raise RuntimeError("private MySQL secret directory is unsafe")
    flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)
    fd = os.open(path, flags)
    try:
        item = os.fstat(fd)
        if (not stat.S_ISREG(item.st_mode) or item.st_uid != 0 or item.st_nlink != 1
                or stat.S_IMODE(item.st_mode) not in (0o400, 0o600) or item.st_size > 4096):
            raise RuntimeError("private MySQL configuration is unsafe")
    finally:
        os.close(fd)


def copy_private_config(source: Path, destination: Path, postgres_gid: int):
    source_fd = os.open(source, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    destination_fd = None
    try:
        item = os.fstat(source_fd)
        if (not stat.S_ISREG(item.st_mode) or item.st_uid != 0 or item.st_nlink != 1
                or stat.S_IMODE(item.st_mode) not in (0o400, 0o600) or item.st_size > 4096):
            raise RuntimeError("MySQL reader configuration changed during preflight")
        destination_fd = os.open(destination, os.O_CREAT | os.O_EXCL | os.O_WRONLY | getattr(os, "O_NOFOLLOW", 0), 0o440)
        os.fchown(destination_fd, 0, postgres_gid)
        os.fchmod(destination_fd, 0o440)
        while True:
            block = os.read(source_fd, 4096)
            if not block:
                break
            os.write(destination_fd, block)
        os.fsync(destination_fd)
    finally:
        os.close(source_fd)
        if destination_fd is not None:
            os.close(destination_fd)


def read_verified_routine_snapshot():
    query = """SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT CONCAT('S|',@@global.read_only,'|',@@global.super_read_only,'|',@@global.event_scheduler);
SELECT CONCAT('R|',ROUTINE_NAME,'|',ROUTINE_TYPE,'|',SHA2(ROUTINE_DEFINITION,256),'|',
OCTET_LENGTH(ROUTINE_DEFINITION),'|',SQL_DATA_ACCESS,'|',IS_DETERMINISTIC,'|',SECURITY_TYPE,'|',
REPLACE(IFNULL(SQL_MODE,''),'|',' ')) FROM information_schema.ROUTINES
WHERE ROUTINE_SCHEMA='inlaud99_erpcondor' ORDER BY ROUTINE_NAME;
SELECT CONCAT('P|',SPECIFIC_NAME,'|',ORDINAL_POSITION,'|',PARAMETER_NAME,'|',PARAMETER_MODE,'|',
DATA_TYPE,'|',DTD_IDENTIFIER) FROM information_schema.PARAMETERS
WHERE SPECIFIC_SCHEMA='inlaud99_erpcondor' ORDER BY SPECIFIC_NAME,ORDINAL_POSITION;
ROLLBACK;"""
    result = subprocess.run([
        "podman", "exec", MYSQL_CONTAINER, "mysql",
        "--defaults-extra-file=/run/rehearsal-secrets/client.cnf", "--protocol=TCP",
        "--host=127.0.0.1", "--port=3306", "--batch", "--raw", "--skip-column-names",
        "--default-character-set=utf8mb4", "--database=inlaud99_erpcondor", "-e", query,
    ], capture_output=True, text=True, timeout=60)
    if result.returncode:
        raise RuntimeError("read-only source routine fingerprint query failed")
    rows = [line.split("|") for line in result.stdout.splitlines() if line]
    safety = [row for row in rows if row[0] == "S"]
    routine_rows = [row for row in rows if row[0] == "R"]
    parameter_rows = [row for row in rows if row[0] == "P"]
    if safety != [["S", "1", "1", "OFF"]] or len(routine_rows) != 2:
        raise RuntimeError("source read-only or stored procedure inventory guard failed")
    parameters = {}
    for row in parameter_rows:
        if len(row) != 7:
            raise RuntimeError("stored procedure parameter metadata is malformed")
        parameters.setdefault(row[1], []).append({
            "position": int(row[2]), "name": row[3], "mode": row[4], "type": row[5], "dtd": row[6],
        })
    snapshot = {"schema": "inlaud99_erpcondor", "routines": []}
    for row in routine_rows:
        if len(row) != 9 or row[3] in ("", "NULL") or row[4] in ("", "NULL"):
            raise RuntimeError("stored procedure fingerprint is unavailable")
        snapshot["routines"].append({
            "name": row[1], "type": row[2], "definition_sha256": row[3],
            "definition_bytes": int(row[4]), "data_access": row[5],
            "deterministic": row[6], "security_type": row[7], "sql_mode": row[8],
            "parameters": parameters.pop(row[1], []),
        })
    if parameters:
        raise RuntimeError("unexpected stored procedure parameter metadata")
    snapshot["routines"].sort(key=lambda item: item["name"])
    expected = json.loads((CODE_ROOT / "expected_routines.json").read_text(encoding="utf-8"))
    if snapshot != expected:
        raise RuntimeError("stored procedure fingerprint differs from the approved versioned manifest")
    return snapshot


def cleanup_tmpfs_run(path: Path, postgres_gid: int):
    if not path.exists() and not path.is_symlink():
        return
    if path.is_symlink():
        raise RuntimeError("unexpected symlink in credential tmpfs")
    st = path.lstat()
    if (not stat.S_ISDIR(st.st_mode) or st.st_uid != 0 or st.st_gid != postgres_gid
            or stat.S_IMODE(st.st_mode) != 0o710):
        raise RuntimeError("unsafe abandoned credential directory")
    entries = list(path.iterdir())
    if entries:
        if len(entries) != 1 or entries[0].name != "client.cnf" or entries[0].is_symlink():
            raise RuntimeError("unexpected entry in credential tmpfs")
        file_stat = entries[0].lstat()
        if (not stat.S_ISREG(file_stat.st_mode) or file_stat.st_uid != 0
                or file_stat.st_gid != postgres_gid or stat.S_IMODE(file_stat.st_mode) != 0o440):
            raise RuntimeError("unsafe credential file in tmpfs")
        entries[0].unlink()
    path.rmdir()


def cleanup_tmpfs_root(path: Path, postgres_gid: int):
    if not path.exists() and not path.is_symlink():
        return
    if path.is_symlink():
        raise RuntimeError("credential tmpfs root cannot be a symlink")
    st = path.lstat()
    if (not stat.S_ISDIR(st.st_mode) or st.st_uid != 0 or st.st_gid != postgres_gid
            or stat.S_IMODE(st.st_mode) != 0o710):
        raise RuntimeError("credential tmpfs root has unsafe ownership or mode")
    if any(path.iterdir()):
        raise RuntimeError("credential tmpfs root is not empty after cleanup")
    path.rmdir()


def prepare_credential_tmpfs(postgres_gid: int, tmpfs_root: Path = TMPFS_ROOT):
    mount = subprocess.run(["findmnt", "-n", "-o", "FSTYPE", "--target", "/dev/shm"],
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if mount.returncode or mount.stdout.strip() != "tmpfs" or not Path("/dev/shm").is_mount():
        raise RuntimeError("credential directory is not on a verified tmpfs mount")
    if tmpfs_root.is_symlink():
        raise RuntimeError("credential tmpfs root cannot be a symlink")
    if tmpfs_root.exists():
        st = tmpfs_root.lstat()
        if (not stat.S_ISDIR(st.st_mode) or st.st_uid != 0 or st.st_gid != postgres_gid
                or stat.S_IMODE(st.st_mode) != 0o710):
            raise RuntimeError("credential tmpfs root has unsafe ownership or mode")
    else:
        tmpfs_root.mkdir(mode=0o710)
        os.chown(tmpfs_root, 0, postgres_gid)
        tmpfs_root.chmod(0o710)
    for child in tmpfs_root.iterdir():
        if not re.fullmatch(r"\d{8}T\d{6}Z", child.name):
            raise RuntimeError("unexpected directory in credential tmpfs root")
        cleanup_tmpfs_run(child, postgres_gid)


def release_runner_lock():
    global RUN_LOCK_FD
    if RUN_LOCK_FD is not None:
        fcntl.flock(RUN_LOCK_FD, fcntl.LOCK_UN)
        os.close(RUN_LOCK_FD)
        RUN_LOCK_FD = None


def acquire_runner_lock(run_root: Path = RUN_ROOT):
    global RUN_LOCK_FD
    path = run_root / ".runner.lock"
    flags = os.O_CREAT | os.O_RDWR | getattr(os, "O_NOFOLLOW", 0)
    fd = os.open(path, flags, 0o600)
    st = os.fstat(fd)
    if not stat.S_ISREG(st.st_mode) or st.st_nlink != 1 or st.st_uid != 0 or stat.S_IMODE(st.st_mode) != 0o600:
        os.close(fd)
        raise RuntimeError("runner lock file has unsafe ownership or mode")
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        os.close(fd)
        raise RuntimeError("another rehearsal runner is active")
    RUN_LOCK_FD = fd
    atexit.register(release_runner_lock)


def ensure_private_directory(path: Path, uid: int, gid: int, mode: int):
    if path.is_symlink():
        raise RuntimeError("private runner directory cannot be a symlink")
    try:
        path.mkdir(mode=mode)
        os.chown(path, uid, gid)
    except FileExistsError:
        pass
    item = path.lstat()
    if (not stat.S_ISDIR(item.st_mode) or item.st_uid != uid or item.st_gid != gid
            or stat.S_IMODE(item.st_mode) != mode):
        raise RuntimeError("private runner directory has unsafe ownership or mode")


def emit_log(path: Path, event: str, **fields):
    record = {"utc": datetime.now(timezone.utc).isoformat(timespec="seconds"), "event": event, **fields}
    with path.open("a", encoding="utf-8") as stream:
        stream.write(json.dumps(record, ensure_ascii=False, sort_keys=True) + "\n")
        stream.flush()
    path.chmod(0o600)


class PipelineSignal(Exception):
    pass


def handle_signal(signum, frame):
    raise PipelineSignal(f"signal {signum}")


def run_step(command: list[str], phase: str, log: Path, *, whitelist_json=False):
    global ACTIVE_PROCESS, CURRENT_PHASE
    CURRENT_PHASE = phase
    started = time.monotonic()
    emit_log(log, "phase_started", phase=phase)
    print(json.dumps({"event": "phase_started", "phase": phase}), flush=True)
    proc = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
                            bufsize=1, start_new_session=True)
    ACTIVE_PROCESS = proc
    error_type = "unknown"
    error_code = "unknown"
    error_stage = "none"
    sqlstate = "none"
    try:
        for raw_line in proc.stdout:
            line = raw_line.rstrip("\r\n")
            if not whitelist_json:
                for marker in ("CATALOG_GENERATION_FAILED type=", "LOAD_FAILED type=",
                               "RECONCILIATION_FAILED type=", "TRIGGER_TEST_FAILED type="):
                    if marker in line:
                        error_type = line.split(marker, 1)[1].split()[0]
                if line.startswith("RECONCILIATION_BLOCKED "):
                    error_type = "ReconciliationBlocked"
                code_match = re.search(r"(?:^|\s)code=([a-z0-9_]+)(?:\s|$)", line)
                if code_match:
                    error_code = code_match.group(1)
                stage_match = re.search(r"(?:^|\s)stage=([a-z0-9_]+)(?:\s|$)", line)
                if stage_match:
                    error_stage = stage_match.group(1)
                if "sqlstate=" in line:
                    sqlstate = line.rsplit("sqlstate=", 1)[1].split()[0]
                state_match = re.search(r"\bSQL state:\s*([0-9A-Z]{5})\b", line)
                if not state_match:
                    state_match = re.search(r"\b(?:ERROR|FATAL):\s*([0-9A-Z]{5})(?=[:\s])", line)
                if state_match:
                    error_type, error_code, sqlstate = "PostgreSQLError", "postgresql_error", state_match.group(1)
                continue
            try:
                item = json.loads(line)
            except (TypeError, json.JSONDecodeError):
                for marker in ("CATALOG_GENERATION_FAILED type=", "LOAD_FAILED type=",
                               "RECONCILIATION_FAILED type=", "TRIGGER_TEST_FAILED type="):
                    if marker in line:
                        error_type = line.split(marker, 1)[1].split()[0]
                if line.startswith("RECONCILIATION_BLOCKED "):
                    error_type = "ReconciliationBlocked"
                code_match = re.search(r"(?:^|\s)code=([a-z0-9_]+)(?:\s|$)", line)
                if code_match:
                    error_code = code_match.group(1)
                stage_match = re.search(r"(?:^|\s)stage=([a-z0-9_]+)(?:\s|$)", line)
                if stage_match:
                    error_stage = stage_match.group(1)
                if "sqlstate=" in line:
                    sqlstate = line.rsplit("sqlstate=", 1)[1].split()[0]
                state_match = re.search(r"\bSQL state:\s*([0-9A-Z]{5})\b", line)
                if not state_match:
                    state_match = re.search(r"\b(?:ERROR|FATAL):\s*([0-9A-Z]{5})(?=[:\s])", line)
                if state_match:
                    error_type, error_code, sqlstate = "PostgreSQLError", "postgresql_error", state_match.group(1)
                continue
            if item.get("event") == "table_loaded":
                safe = {"table": item.get("table"), "rows": item.get("source_rows"),
                        "elapsed_seconds": item.get("elapsed_seconds")}
                emit_log(log, "table_loaded", phase=phase, **{k: v for k, v in safe.items() if v is not None})
                print(json.dumps({"event": "table_loaded", "phase": phase,
                                  **{k: v for k, v in safe.items() if v is not None}}, ensure_ascii=False), flush=True)
            elif item.get("event") == "table_reconciled":
                safe = {"table": item.get("table"), "rows": item.get("rows"),
                        "row_count_match": item.get("row_count_match"), "rows_divergent": item.get("rows_divergent")}
                emit_log(log, "table_reconciled", **{k: v for k, v in safe.items() if v is not None})
                print(json.dumps({"event": "table_reconciled",
                                  **{k: v for k, v in safe.items() if v is not None}}, ensure_ascii=False), flush=True)
            elif item.get("status") == "PASS":
                emit_log(log, "synthetic_test_pass", test=item.get("test"))
                print(json.dumps({"event": "synthetic_test_pass", "test": item.get("test")}), flush=True)
            elif item.get("tables") is not None:
                safe = safe_summary_scalars(item)
                emit_log(log, "reconciliation_summary", **safe)
                print(json.dumps({"event": "reconciliation_summary", **safe}, ensure_ascii=False), flush=True)
            elif item.get("tables_loaded") is not None:
                emit_log(log, "loader_summary", tables_loaded=item.get("tables_loaded"),
                         source_rows_loaded=item.get("source_rows_loaded"), elapsed_seconds=item.get("elapsed_seconds"))
                print(json.dumps({"event": "loader_summary", "tables_loaded": item.get("tables_loaded"),
                                  "source_rows_loaded": item.get("source_rows_loaded"),
                                  "elapsed_seconds": item.get("elapsed_seconds")}), flush=True)
        return_code = proc.wait()
    except BaseException:
        if proc.poll() is None:
            try:
                os.killpg(proc.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
            try:
                proc.wait(timeout=120)
            except subprocess.TimeoutExpired:
                try:
                    os.killpg(proc.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                proc.wait()
        raise
    finally:
        if ACTIVE_PROCESS is proc:
            ACTIVE_PROCESS = None
    elapsed = round(time.monotonic() - started, 3)
    if return_code:
        emit_log(log, "step_failed", phase=phase, exit_code=proc.returncode,
                 error_type=error_type, error_code=error_code, error_stage=error_stage,
                 sqlstate=sqlstate, elapsed_seconds=elapsed)
        raise RuntimeError(f"phase {phase} failed; see private aggregate log")
    emit_log(log, "step_complete", phase=phase, exit_code=0, elapsed_seconds=elapsed)
    print(json.dumps({"event": "phase_complete", "phase": phase, "elapsed_seconds": elapsed}), flush=True)
    return proc.stdout


def verify_v10_source_manifest_payload(manifest: dict, source_sha256: str, source_bytes: int,
                                       copy_sha256: str, copy_bytes: int) -> str:
    if (set(manifest) != {"schema_version", "sha256", "size_bytes"}
            or manifest.get("schema_version") != 1
            or not re.fullmatch(r"[0-9a-f]{64}", str(manifest.get("sha256", "")))
            or source_sha256 != copy_sha256
            or source_sha256 != manifest.get("sha256")
            or source_bytes != copy_bytes
            or source_bytes != manifest.get("size_bytes")
            or source_bytes != 355697322):
        raise RuntimeError("source dump or evidence copy differs from the approved integrity manifest")
    return source_sha256


def verify_v10_source_integrity() -> tuple[str, int]:
    manifest_stat = V10_SOURCE_MANIFEST.lstat()
    if (not stat.S_ISREG(manifest_stat.st_mode) or stat.S_ISLNK(manifest_stat.st_mode)
            or manifest_stat.st_uid != 0 or stat.S_IMODE(manifest_stat.st_mode) != 0o600):
        raise RuntimeError("source integrity manifest has unsafe ownership or mode")
    manifest = json.loads(V10_SOURCE_MANIFEST.read_text(encoding="utf-8"))

    def inspect_source(path: Path):
        info = path.lstat()
        if (not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid != 0
                or info.st_size <= 0 or stat.S_IMODE(info.st_mode) & 0o077):
            raise RuntimeError("source dump artifact has unsafe ownership or mode")
        digest = hashlib.sha256()
        with path.open("rb") as stream:
            for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                digest.update(chunk)
        gzip_check = subprocess.run(["/usr/bin/gzip", "-t", str(path)],
                                    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        if gzip_check.returncode:
            raise RuntimeError("source dump gzip integrity check failed")
        return digest.hexdigest(), info.st_size

    source_sha, source_size = inspect_source(V10_SOURCE_DUMP)
    copy_sha, copy_size = inspect_source(V10_SOURCE_COPY)
    compared = subprocess.run(["/usr/bin/cmp", "-s", str(V10_SOURCE_DUMP), str(V10_SOURCE_COPY)])
    if compared.returncode:
        raise RuntimeError("source dump and evidence copy are not byte-identical")
    digest = verify_v10_source_manifest_payload(manifest, source_sha, source_size, copy_sha, copy_size)
    return digest, source_size


def verify_v10_source_restore_lineage(source_sha256: str, source_size_bytes: int,
                                      live_container_id: str) -> None:
    def read_root_private(path: Path) -> str:
        info = path.lstat()
        if (not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)
                or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o600):
            raise RuntimeError("source restore evidence has unsafe ownership or mode")
        return path.read_text(encoding="utf-8").strip()

    try:
        attestation = json.loads(read_root_private(V10_SOURCE_RESTORE_ATTESTATION))
    except (json.JSONDecodeError, UnicodeDecodeError):
        raise RuntimeError("source restore attestation is malformed") from None
    validate_v10_source_restore_payload(
        attestation, source_sha256, source_size_bytes, live_container_id,
        read_root_private(V10_SOURCE_RESTORE_STATUS),
        read_root_private(V10_SOURCE_CONTAINER_ID_EVIDENCE))


def validate_v10_source_restore_payload(attestation: dict, source_sha256: str,
                                       source_size_bytes: int, live_container_id: str,
                                       restore_status: str, recorded_container_id: str) -> None:
    expected_fields = {
        "schema_version", "source_schema", "source_sha256", "source_size_bytes",
        "restore_pipeline_exit", "restore_script_sha256", "container_id",
    }
    if (set(attestation) != expected_fields or attestation.get("schema_version") != 1
            or attestation.get("source_schema") != V10_SOURCE_SCHEMA
            or attestation.get("source_sha256") != source_sha256
            or attestation.get("source_size_bytes") != source_size_bytes
            or attestation.get("restore_pipeline_exit") != 0
            or attestation.get("restore_script_sha256") != V10_RESTORE_SCRIPT_SHA256
            or not re.fullmatch(r"[0-9a-f]{64}", str(live_container_id))
            or attestation.get("container_id") != live_container_id
            or restore_status != "RESTORE_PIPE_EXIT=0"
            or not re.fullmatch(r"[0-9a-f]{64}", recorded_container_id)
            or recorded_container_id != live_container_id):
        raise RuntimeError("MySQL source is not attested as the validated dump restoration")


def validate_v10_catalog_semantics(summary: dict) -> dict:
    if any(summary.get(key) != value for key, value in V10_EXPECTED_CATALOG_COUNTS.items()):
        raise RuntimeError("v10 structural catalog counts differ from the reviewed snapshot")
    tables = summary.get("table_names")
    if (not isinstance(tables, list) or len(tables) != 270 or len(set(tables)) != 270
            or any(not isinstance(table, str) or not table for table in tables)):
        raise RuntimeError("v10 catalog table-name inventory is incomplete or malformed")
    if summary.get("object_types") != {"BASE TABLE": 270}:
        raise RuntimeError("v10 catalog contains a non-table object or an unexpected object count")
    procedures = summary.get("procedure_names")
    functions = summary.get("function_names")
    triggers = summary.get("trigger_names")
    if (not isinstance(procedures, list) or set(procedures) != V10_EXPECTED_PROCEDURE_NAMES
            or not isinstance(functions, list) or functions
            or not isinstance(triggers, list) or set(triggers) != V10_EXPECTED_TRIGGER_NAMES):
        raise RuntimeError("v10 routine or trigger inventory differs from the reviewed snapshot")
    tenant_tables = summary.get("tenant_id_tables")
    no_tenant_tables = summary.get("no_tenant_id_tables")
    collate_c_columns = summary.get("collate_c_columns")
    on_update_columns = summary.get("on_update_current_timestamp_columns")
    if (not isinstance(tenant_tables, list) or len(tenant_tables) != 228
            or len(set(tenant_tables)) != 228 or not isinstance(no_tenant_tables, list)
            or len(no_tenant_tables) != 42 or len(set(no_tenant_tables)) != 42
            or set(tenant_tables) & set(no_tenant_tables)
            or set(tenant_tables) | set(no_tenant_tables) != set(tables)):
        raise RuntimeError("v10 tenant coverage differs from the reviewed table inventory")
    collate_c_sha256 = (hashlib.sha256("\n".join(sorted(collate_c_columns)).encode("utf-8")).hexdigest()
                        if isinstance(collate_c_columns, list)
                        and all(isinstance(item, str) for item in collate_c_columns) else None)
    if (not isinstance(collate_c_columns, list) or len(collate_c_columns) != 83
            or len(set(collate_c_columns)) != 83
            or collate_c_sha256 != V10_EXPECTED_COLLATE_C_COLUMNS_SHA256
            or summary.get("collate_c_columns_sha256") != collate_c_sha256):
        raise RuntimeError("v10 C-collation column inventory differs from the reviewed snapshot")
    if (not isinstance(on_update_columns, list) or len(on_update_columns) != 111
            or len(set(on_update_columns)) != 111
            or summary.get("on_update_columns_sha256") != hashlib.sha256(
                (CODE_ROOT / "expected_on_update_columns.json").read_bytes()).hexdigest()):
        raise RuntimeError("v10 ON UPDATE column inventory differs from the reviewed snapshot")
    if summary.get("source_collation_counts") != V10_EXPECTED_SOURCE_COLLATION_COUNTS:
        raise RuntimeError("v10 source collation counts differ from the reviewed snapshot")
    if (summary.get("tenant_signature_sha256") != V10_EXPECTED_TENANT_SIGNATURE_SHA256
            or summary.get("no_tenant_names_sha256") != V10_EXPECTED_NO_TENANT_NAMES_SHA256):
        raise RuntimeError("v10 tenant metadata fingerprints differ from the reviewed snapshot")
    if summary.get("zero_date_counts") != V10_EXPECTED_ZERO_DATE_COUNTS:
        raise RuntimeError("v10 zero-date inventory differs from its reviewed snapshot")
    if summary.get("invalid_enum_counts") != V10_EXPECTED_ENUM_COUNTS:
        raise RuntimeError("v10 ENUM anomaly counts differ from the reviewed snapshot")
    enum_counts = summary.get("invalid_enum_counts", {})
    if any(item["out_of_domain_values"] != item["empty_enum_values"]
           for item in enum_counts.values()):
        raise RuntimeError("v10 ENUM values outside the domain are not limited to the reviewed empty values")
    legacy = summary.get("legacy_tables")
    if not isinstance(legacy, list) or len(legacy) != len(set(legacy)) or set(legacy) != V10_EXPECTED_LEGACY_TABLES:
        raise RuntimeError("v10 legacy table classification differs from the reviewed eight-table inventory")
    decisions = summary.get("legacy_classification")
    if not isinstance(decisions, dict) or set(decisions) != V10_EXPECTED_BACKUP_CANDIDATES:
        raise RuntimeError("v10 backup-candidate classification is incomplete")
    for table, decision in decisions.items():
        expected_schema = "legado" if table in V10_EXPECTED_LEGACY_TABLES else "erp"
        expected_reason = ("backup_name_no_active_code_reference" if expected_schema == "legado"
                           else "active_code_reference")
        if (not isinstance(decision, dict) or decision.get("schema") != expected_schema
                or decision.get("reason") != expected_reason
                or not isinstance(decision.get("paths"), list)
                or (expected_schema == "legado" and decision["paths"])
                or (expected_schema == "erp" and not decision["paths"])):
            raise RuntimeError("v10 backup-candidate decision differs from the reviewed classification")
    return {
        "zero_date_cells": sum(V10_EXPECTED_ZERO_DATE_COUNTS.values()),
        "zero_date_columns": len(V10_EXPECTED_ZERO_DATE_COUNTS),
        "empty_enum_cells": sum(item["empty_enum_values"] for item in V10_EXPECTED_ENUM_COUNTS.values()),
        "enum_columns": len(V10_EXPECTED_ENUM_COUNTS),
        "nonempty_enum_anomalies": 0,
        "legacy_tables": len(V10_EXPECTED_LEGACY_TABLES),
        "tables": len(tables),
        "columns": V10_EXPECTED_CATALOG_COUNTS["columns"],
        "triggers": V10_EXPECTED_CATALOG_COUNTS["triggers"],
        "procedures": V10_EXPECTED_CATALOG_COUNTS["routines"],
        "identities": V10_EXPECTED_CATALOG_COUNTS["auto_increment_columns"],
        "tenant_id_tables": len(tenant_tables),
        "without_tenant_id_tables": len(no_tenant_tables),
        "collate_c_columns": len(collate_c_columns),
        "on_update_columns": len(on_update_columns),
    }


def verify_reconciliation_acceptance(summary: dict, csv_path: Path, expected_tables: set[str],
                                     legacy_tables: set[str], expected_rows: int = 202609) -> dict:
    required_zero = (
        "row_count_mismatches", "tables_with_row_hash_divergence", "row_hash_divergent_total",
        "pk_max_mismatches", "identity_max_mismatches", "sequences_not_ready",
        "numeric_sum_mismatches", "binary_mismatches", "tenant_bucket_mismatches",
        "timestamp_mismatches", "fk_tenant_mismatch_count", "fk_orphan_count_total",
    )
    if (summary.get("status") != "ACCEPTED" or summary.get("tables") != 270
            or summary.get("source_rows") != expected_rows or summary.get("destination_rows") != expected_rows
            or summary.get("foreign_keys") != 11 or summary.get("foreign_keys_valid") != 11
            or summary.get("foreign_keys_with_orphans") != 0
            or any(summary.get(key) != 0 for key in required_zero)
            or summary.get("identity_columns") != 232
            or summary.get("primary_key_columns") != 252):
        raise RuntimeError("reconciliation summary did not meet all v10 acceptance criteria")
    expected_fields = {
        "table", "schema", "source_rows", "destination_rows", "row_count_match", "rows_divergent",
        "pk_max_mismatch_count", "identity_max_match_count", "identity_count", "sequence_ready_count",
        "numeric_sum_mismatches", "binary_mismatches", "tenant_bucket_mismatches", "foreign_keys",
        "foreign_keys_valid", "foreign_keys_with_orphans", "fk_orphan_count", "fk_tenant_mismatches",
        "timestamp_mismatches",
    }
    with csv_path.open("r", newline="", encoding="utf-8") as stream:
        reader = csv.DictReader(stream)
        if reader.fieldnames is None or not expected_fields.issubset(reader.fieldnames):
            raise RuntimeError("reconciliation CSV is missing required table-level metrics")
        rows = list(reader)
    if (len(rows) != 270 or len({row.get("table") for row in rows}) != 270
            or len(expected_tables) != 270 or len(legacy_tables) != 8
            or not legacy_tables.issubset(expected_tables)):
        raise RuntimeError("reconciliation CSV does not contain exactly 270 unique tables")
    actual_schemas = {row["table"]: row["schema"] for row in rows}
    expected_schemas = {table: "legado" if table in legacy_tables else "erp" for table in expected_tables}
    if actual_schemas != expected_schemas:
        raise RuntimeError("reconciliation table names or schema assignments differ from the source catalog")
    numeric_fields = expected_fields - {"table", "schema", "row_count_match"}
    for row in rows:
        try:
            values = {key: int(row[key]) for key in numeric_fields}
        except (KeyError, TypeError, ValueError):
            raise RuntimeError("reconciliation table-level metrics are malformed") from None
        if (row["row_count_match"] != "True" or row["schema"] not in {"erp", "legado"}
                or values["source_rows"] != values["destination_rows"]
                or values["rows_divergent"] != 0 or values["pk_max_mismatch_count"] != 0
                or values["identity_max_match_count"] != values["identity_count"]
                or values["sequence_ready_count"] != values["identity_count"]
                or any(values[key] != 0 for key in (
                    "numeric_sum_mismatches", "binary_mismatches", "tenant_bucket_mismatches",
                    "foreign_keys_with_orphans", "fk_orphan_count", "fk_tenant_mismatches",
                    "timestamp_mismatches"))):
            raise RuntimeError("a table-level reconciliation metric did not pass")
    totals = {
        "tables": len(rows),
        "source_rows": sum(int(row["source_rows"]) for row in rows),
        "destination_rows": sum(int(row["destination_rows"]) for row in rows),
        "identities": sum(int(row["identity_count"]) for row in rows),
        "identity_max_matches": sum(int(row["identity_max_match_count"]) for row in rows),
        "sequences_ready": sum(int(row["sequence_ready_count"]) for row in rows),
        "foreign_keys": sum(int(row["foreign_keys"]) for row in rows),
        "foreign_keys_valid": sum(int(row["foreign_keys_valid"]) for row in rows),
        "foreign_keys_with_orphans": sum(int(row["foreign_keys_with_orphans"]) for row in rows),
        "fk_orphan_count": sum(int(row["fk_orphan_count"]) for row in rows),
    }
    if (totals["source_rows"] != expected_rows or totals["destination_rows"] != expected_rows
            or totals["identities"] != 232 or totals["identity_max_matches"] != 232
            or totals["sequences_ready"] != 232 or totals["foreign_keys"] != 11
            or totals["foreign_keys_valid"] != 11 or totals["foreign_keys_with_orphans"] != 0
            or totals["fk_orphan_count"] != 0):
        raise RuntimeError("table-level reconciliation totals differ from the approved source snapshot")
    return totals


def validate_v10_execution_history(records: list[dict], *, require_cluster_stopped: bool = False):
    started = [item for item in records if item.get("event") == "pipeline_started"]
    if (len(started) != 1 or started[0].get("database") != V10B_DATABASE
            or started[0].get("clean_start") is not True
            or started[0].get("resume_source_run") is not None
            or started[0].get("post_fk_source_run") is not None):
        raise RuntimeError("v10 execution history does not prove a clean target run")
    if any(item.get("event") in {"step_failed", "pipeline_setup_blocked", "pipeline_blocked"}
           for item in records):
        raise RuntimeError("v10 execution history contains a failed or blocked phase")
    phase_history = [(item.get("event"), item.get("phase")) for item in records
                     if item.get("event") in {"phase_started", "step_complete"}]
    expected_phase_history = [(event, phase) for phase in V10_PHASE_SEQUENCE
                               for event in ("phase_started", "step_complete")]
    if phase_history != expected_phase_history:
        raise RuntimeError("v10 execution history does not match the complete approved phase order")
    if (len([item for item in records if item.get("event") == "source_artifacts_integrity_verified"]) != 1
            or len([item for item in records if item.get("event") == "mysql_restore_lineage_verified"]) != 1
            or len([item for item in records if item.get("event") == "catalog_semantics_verified"]) != 1
            or len([item for item in records if item.get("event") == "empty_target_created"
                    and item.get("database") == V10B_DATABASE]) != 1
            or len([item for item in records if item.get("event") == "postgres_cluster_ready"]) != 1):
        raise RuntimeError("v10 execution history is missing a required integrity or target event")
    loader = [item for item in records if item.get("event") == "loader_summary"]
    if (len(loader) != 1 or loader[0].get("tables_loaded") != 270
            or loader[0].get("source_rows_loaded") != 202609):
        raise RuntimeError("v10 execution history does not prove the complete load")
    tests = [item.get("test") for item in records if item.get("event") == "synthetic_test_pass"]
    if len(tests) != len(V10_EXPECTED_SYNTHETIC_TESTS) or set(tests) != V10_EXPECTED_SYNTHETIC_TESTS:
        raise RuntimeError("v10 execution history does not prove every synthetic business-object test")
    reconciliation = [item for item in records if item.get("event") == "reconciliation_summary"]
    if (len(reconciliation) != 1 or reconciliation[0].get("status") != "ACCEPTED"
            or reconciliation[0].get("tables") != 270
            or reconciliation[0].get("source_rows") != 202609
            or reconciliation[0].get("destination_rows") != 202609):
        raise RuntimeError("v10 execution history does not prove a complete reconciliation")
    if len([item for item in records if item.get("event") == "table_level_reconciliation_verified"]) != 1:
        raise RuntimeError("v10 table-level reconciliation gate is missing")
    if any(item.get("event") == "pipeline_accepted" for item in records):
        raise RuntimeError("v10 execution history already contains a terminal acceptance event")
    def event_index(event: str, phase: str | None = None) -> int:
        matches = [index for index, item in enumerate(records)
                   if item.get("event") == event and (phase is None or item.get("phase") == phase)]
        if len(matches) != 1:
            raise RuntimeError("v10 execution history contains a missing or repeated milestone")
        return matches[0]

    ordered_milestones = [
        event_index("pipeline_started"), event_index("source_artifacts_integrity_verified"),
        event_index("mysql_restore_lineage_verified"), event_index("phase_started", "catalog"),
        event_index("step_complete", "catalog"), event_index("catalog_semantics_verified"),
        event_index("postgres_cluster_ready"), event_index("phase_started", "target_create"),
        event_index("empty_target_created"), event_index("step_complete", "target_create"),
        event_index("step_complete", "constraints_indexes"), event_index("step_complete", "load"),
        event_index("step_complete", "business_trigger_tests"),
        event_index("step_complete", "foreign_keys_not_valid"),
        event_index("step_complete", "identity_sequences"), event_index("step_complete", "analyze"),
        event_index("reconciliation_summary"), event_index("table_level_reconciliation_verified"),
    ]
    if ordered_milestones != sorted(ordered_milestones):
        raise RuntimeError("v10 execution milestones are out of order")
    if require_cluster_stopped:
        stopped = [item for item in records if item.get("event") in {
            "postgres_cluster_stopped", "postgres_cluster_already_stopped"}]
        if (len(stopped) != 1 or stopped[0].get("reason") != "pipeline_success"
                or records.index(stopped[0]) < ordered_milestones[-1]):
            raise RuntimeError("v10 execution history does not prove the rehearsal cluster stopped")


def read_v10_execution_log(path: Path, root_uid: int = 0) -> list[dict]:
    try:
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    except OSError:
        raise RuntimeError("v10 execution log cannot be opened safely") from None
    try:
        item = os.fstat(fd)
        if (not stat.S_ISREG(item.st_mode) or item.st_uid != root_uid
                or stat.S_IMODE(item.st_mode) != 0o600):
            raise RuntimeError("v10 execution log ownership or mode is unsafe")
        with os.fdopen(fd, "r", encoding="utf-8") as stream:
            fd = -1
            lines = stream.read().splitlines()
    finally:
        if fd >= 0:
            os.close(fd)
    records = []
    for line in lines:
        try:
            record = json.loads(line)
        except json.JSONDecodeError:
            raise RuntimeError("v10 execution log is malformed") from None
        if not isinstance(record, dict):
            raise RuntimeError("v10 execution log contains a malformed record")
        records.append(record)
    return records


def write_v10_conversion_report(run_dir: Path, run_id: str, code_commit: str,
                               source_sha256: str, source_size_bytes: int,
                               catalog_summary: dict, reconciliation: dict,
                               table_totals: dict, elapsed_seconds: float) -> Path:
    if not re.fullmatch(r"[0-9a-f]{64}", source_sha256):
        raise RuntimeError("source artifact digest is malformed")
    report = {
        "schema_version": 1,
        "run_id": run_id,
        "database": V10B_DATABASE,
        "code_commit": code_commit,
        "source_artifact": {
            "size_bytes": source_size_bytes,
            "sha256": source_sha256,
            "sha256_verified_against_root_manifest": True,
            "gzip_valid": True,
            "source_and_copy_byte_identical": True,
        },
        "source_restore_lineage": {
            "schema": V10_SOURCE_SCHEMA,
            "restore_pipeline_exit": 0,
            "container_identity_verified": True,
            "restore_script_sha256_verified": True,
        },
        "pipeline_elapsed_seconds": elapsed_seconds,
        "catalog": {
            "tables": catalog_summary.get("tables"),
            "columns": catalog_summary.get("columns"),
            "views": catalog_summary.get("views"),
            "indexes": catalog_summary.get("indexes"),
            "primary_key_tables": catalog_summary.get("primary_keys"),
            "triggers": catalog_summary.get("triggers"),
            "procedures": len(catalog_summary.get("procedure_names", [])),
            "functions": len(catalog_summary.get("function_names", [])),
            "events": catalog_summary.get("events"),
            "identity_columns": catalog_summary.get("auto_increment_columns"),
            "on_update_columns": len(catalog_summary.get("on_update_current_timestamp_columns", [])),
            "tenant_id_tables": len(catalog_summary.get("tenant_id_tables", [])),
            "without_tenant_id_tables": len(catalog_summary.get("no_tenant_id_tables", [])),
            "collate_c_columns": len(catalog_summary.get("collate_c_columns", [])),
            "legacy_tables": catalog_summary.get("legacy_tables"),
        },
        "zero_date_normalization": [
            {"table_column": name, "cells": count, "target": "NULL", "status": "NORMALIZED_ZERO_DATE"}
            for name, count in sorted(catalog_summary.get("zero_date_counts", {}).items())
        ],
        "enum_compatibility": [
            {"table_column": name, **counts, "target_type": "TEXT + CHECK",
             "empty_literal_preserved": True, "status": "PRESERVED_EMPTY_ENUM_VALUE"}
            for name, counts in sorted(catalog_summary.get("invalid_enum_counts", {}).items())
        ],
        "foreign_key_type_normalizations": catalog_summary.get("fk_type_normalizations"),
        "reconciliation": {
            "status": reconciliation.get("status"),
            "source_rows": reconciliation.get("source_rows"),
            "destination_rows": reconciliation.get("destination_rows"),
            "tables": reconciliation.get("tables"),
            "row_count_mismatches": reconciliation.get("row_count_mismatches"),
            "tables_with_row_hash_divergence": reconciliation.get("tables_with_row_hash_divergence"),
            "row_hash_divergent_total": reconciliation.get("row_hash_divergent_total"),
            "primary_key_columns": reconciliation.get("primary_key_columns"),
            "identity_columns": reconciliation.get("identity_columns"),
            "identity_max_mismatches": reconciliation.get("identity_max_mismatches"),
            "sequences_not_ready": reconciliation.get("sequences_not_ready"),
            "numeric_sum_mismatches": reconciliation.get("numeric_sum_mismatches"),
            "binary_mismatches": reconciliation.get("binary_mismatches"),
            "tenant_bucket_mismatches": reconciliation.get("tenant_bucket_mismatches"),
            "timestamp_samples": reconciliation.get("timestamp_samples"),
            "timestamp_mismatches": reconciliation.get("timestamp_mismatches"),
            "foreign_keys": reconciliation.get("foreign_keys"),
            "foreign_keys_valid": reconciliation.get("foreign_keys_valid"),
            "foreign_keys_with_orphans": reconciliation.get("foreign_keys_with_orphans"),
            "fk_orphan_count_total": reconciliation.get("fk_orphan_count_total"),
            "table_level_totals": table_totals,
            "elapsed_seconds": reconciliation.get("elapsed_seconds"),
        },
    }
    path = run_dir / "v10b_conversion_report.json"
    path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    path.chmod(0o600)
    os.chown(path, run_dir.stat().st_uid, run_dir.stat().st_gid)
    return path


def start_cluster(log: Path, run_dir: Path):
    global CLUSTER_STARTED_BY_RUNNER
    expected = {
        "data_directory": str(PGDATA),
        "listen_addresses": "",
        "port": str(PGPORT),
        "unix_socket_directories": str(PGSOCKET),
    }
    for setting, value in expected.items():
        result = subprocess.run(as_postgres("/usr/lib/postgresql/18/bin/postgres", "-D", PGDATA, "-C", setting),
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if result.returncode or result.stdout.strip() != value:
            raise RuntimeError("isolated PostgreSQL configuration guard failed")
    status = subprocess.run(as_postgres("/usr/lib/postgresql/18/bin/pg_ctl", "-D", PGDATA, "status"),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if status.returncode == 0:
        raise RuntimeError("refusing to reuse an already running PostgreSQL rehearsal cluster")
    if PGDATA.joinpath("postmaster.pid").exists():
        raise RuntimeError("PostgreSQL rehearsal state is ambiguous")
    result = subprocess.run(as_postgres("/usr/lib/postgresql/18/bin/pg_ctl", "-D", PGDATA,
                             "-l", run_dir / "postgresql.log", "start"),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if result.returncode:
        post_status = subprocess.run(as_postgres("/usr/lib/postgresql/18/bin/pg_ctl", "-D", PGDATA, "status"),
                                     stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if post_status.returncode == 0:
            CLUSTER_STARTED_BY_RUNNER = True
            try:
                stop_rehearsal_cluster(log, "start_command_failed_after_launch")
            except Exception as stop_error:
                emit_log(log, "cluster_stop_failed_after_start_error", error_type=type(stop_error).__name__)
        elif PGDATA.joinpath("postmaster.pid").exists():
            raise RuntimeError("PostgreSQL start failure left an ambiguous rehearsal cluster state")
        raise RuntimeError("isolated PostgreSQL cluster failed to start")
    started_here = True
    CLUSTER_STARTED_BY_RUNNER = True
    try:
        result = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-At", "-h", PGSOCKET,
                                 "-p", str(PGPORT), "-U", "postgres", "-d", "postgres", "-c",
                                 "SELECT current_setting('data_directory')||'|'||current_setting('listen_addresses')||'|'||current_setting('port')||'|'||current_setting('unix_socket_directories')||'|'||current_setting('server_version_num')"),
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        fields = result.stdout.strip().split("|")
        if (result.returncode or len(fields) != 5 or fields[0] != str(PGDATA) or fields[1] != ""
                or fields[2] != str(PGPORT) or fields[3] != str(PGSOCKET)
                or not 180000 <= int(fields[4]) < 190000):
            raise RuntimeError("connected PostgreSQL is not the approved isolated 18 cluster")
    except Exception:
        if started_here:
            try:
                stop_rehearsal_cluster(log, "post_start_guard_failed")
            except Exception as stop_error:
                emit_log(log, "cluster_stop_failed_after_guard", error_type=type(stop_error).__name__)
        raise
    emit_log(log, "postgres_cluster_ready", started_here=started_here, port=PGPORT,
             listen_addresses="", server_major=18)
    return started_here


def check_mysql_namespace():
    check = subprocess.run(["podman", "inspect", MYSQL_CONTAINER], stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE, text=True)
    if check.returncode:
        raise RuntimeError("isolated MySQL source container is unavailable")
    try:
        info = json.loads(check.stdout)[0]
    except (json.JSONDecodeError, IndexError, KeyError):
        raise RuntimeError("isolated MySQL source container metadata is unreadable")
    host = info.get("HostConfig", {})
    state = info.get("State", {})
    args = info.get("Args", [])
    security_opts = host.get("SecurityOpt") or []
    secret_mounts = [mount for mount in info.get("Mounts", [])
                     if mount.get("Destination") == "/run/rehearsal-secrets"]
    if (info.get("ImageName") != "docker.io/library/mysql:5.7" or not state.get("Running")
            or host.get("NetworkMode") != "none" or host.get("PortBindings") not in (None, {})
            or host.get("Privileged") is not False or host.get("CapAdd") not in (None, [])
            or "no-new-privileges" not in security_opts
            or "apparmor=erp-mysql-rehearsal" not in security_opts
            or info.get("AppArmorProfile") != "erp-mysql-rehearsal"):
        raise RuntimeError("MySQL source isolation guard failed")
    if (len(secret_mounts) != 1 or secret_mounts[0].get("Type") != "bind"
            or secret_mounts[0].get("Source") != "/var/lib/erp-pg-rehearsal/secrets"
            or secret_mounts[0].get("RW") is not False):
        raise RuntimeError("MySQL secret mount must be the expected read-only private bind mount")
    if "--bind-address=127.0.0.1" not in args or "--event-scheduler=OFF" not in args:
        raise RuntimeError("MySQL source loopback or event-scheduler guard failed")
    pid = int(state.get("Pid") or 0)
    if pid <= 1 or not Path(f"/proc/{pid}/ns/net").exists():
        raise RuntimeError("MySQL network namespace is unavailable")
    container_id = info.get("Id")
    if not isinstance(container_id, str) or not re.fullmatch(r"[0-9a-f]{64}", container_id):
        raise RuntimeError("MySQL source container identity is unavailable")
    return pid, container_id


def create_target_database(dbname: str):
    if dbname not in REHEARSAL_DATABASES:
        raise RuntimeError("target database is not an approved isolated rehearsal")
    db_ident = '"' + dbname.replace('"', '""') + '"'
    db_literal = "'" + dbname.replace("'", "''") + "'"
    check = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-At", "-h", PGSOCKET,
                            "-p", str(PGPORT), "-U", "postgres", "-d", "postgres", "-c",
                            f"SELECT COUNT(*) FROM pg_database WHERE datname={db_literal}"),
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if check.returncode or check.stdout.strip() != "0":
        raise RuntimeError("target database already exists or catalog query failed; refusing to drop or reuse it")
    create = subprocess.run(as_postgres("/usr/bin/createdb", "-h", PGSOCKET,
                             "-p", str(PGPORT), "-U", "postgres", "-T", "template0", "-E", "UTF8", dbname),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if create.returncode:
        raise RuntimeError("failed to create new isolated target database")
    alter = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-q", "-v", "ON_ERROR_STOP=1",
                            "-h", PGSOCKET, "-p", PGPORT, "-U", "postgres", "-d", "postgres", "-c",
                            f"ALTER DATABASE {db_ident} SET timezone TO 'America/Sao_Paulo'; "
                            f"ALTER DATABASE {db_ident} CONNECTION LIMIT 1; "
                            f"REVOKE CONNECT,TEMPORARY ON DATABASE {db_ident} FROM PUBLIC"),
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if alter.returncode:
        raise RuntimeError("failed to set rehearsal database timezone")
    verify = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-At", "-h", PGSOCKET,
                             "-p", str(PGPORT), "-U", "postgres", "-d", dbname, "-c",
                             "SELECT current_setting('TimeZone')||'|'||current_setting('server_encoding')"),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if verify.returncode or verify.stdout.strip() != "America/Sao_Paulo|UTF8":
        raise RuntimeError("target database timezone or encoding does not match the approved contract")


def validate_resume_history(records, previous_commit: str, batch_size: int = 1000):
    starts = [item for item in records if item.get("event") == "pipeline_started"]
    if (len(starts) != 1 or starts[0].get("code_commit") != previous_commit
            or starts[0].get("batch_size") != batch_size):
        raise RuntimeError("resume source commit differs from the approved partial run")
    completed = {item.get("phase") for item in records if item.get("event") == "step_complete"}
    required = {"catalog", "schema", "load", "constraints_indexes", "identity_sequences",
                "on_update_triggers", "business_objects", "grants"}
    if not required.issubset(completed):
        raise RuntimeError("partial run lacks required completed phases")
    failures = [item for item in records if item.get("event") == "step_failed"]
    targets = [item for item in records if item.get("event") == "empty_target_created"]
    loaded = [item for item in records if item.get("event") == "loader_metrics_verified"]
    blocked = [item for item in records if item.get("event") == "pipeline_blocked"]
    stopped = [item for item in records if item.get("event") == "postgres_cluster_stopped"]
    if (len(failures) != 1 or failures[0].get("phase") != "business_trigger_tests"
            or failures[0].get("error_code") != "unknown"
            or len(targets) != 1 or targets[0].get("database") != "erp_ensaio_v9"
            or len(loaded) != 1 or loaded[0].get("tables") != 270 or loaded[0].get("rows") != 202609
            or len(blocked) != 1 or len(stopped) != 1):
        raise RuntimeError("partial run is not eligible for the approved harness-only resume")
    forbidden = {"foreign_keys_not_valid", "analyze", "reconciliation"}
    if any(item.get("phase") in forbidden for item in records
           if item.get("event") in {"step_started", "step_complete", "step_failed"}):
        raise RuntimeError("partial run has advanced beyond the harness resume point")
    if any(item.get("event") == "pipeline_accepted" for item in records):
        raise RuntimeError("accepted runs cannot be resumed")


def validate_post_fk_resume_history(records, source_run_id: str):
    expected_tests = {
        "erp_app_minimum_privileges", "on_update_timestamp_semantics", "after_qrcode_token_insert",
        "after_validacao_dispositivo_insert", "trg_alerta_estoque_baixo", "trg_atualizar_km_acumulado",
        "tr_dependente_ativar_veiculos", "tr_dependente_inativar_veiculos", "tr_dependente_deletar_veiculos",
        "corrigir_moradores_anexos_tenant", "mt_adicionar_coluna",
    }
    starts = [item for item in records if item.get("event") == "pipeline_started"]
    if (len(starts) != 1 or starts[0].get("database") != "erp_ensaio_v9"
            or starts[0].get("code_commit") != APPROVED_POST_FK_SOURCE_COMMIT
            or starts[0].get("batch_size") != 1000 or starts[0].get("resume_source_run") != APPROVED_RESUME_RUN_ID):
        raise RuntimeError("post-FK run provenance differs from the approved rehearsal")
    phases = [item.get("phase") for item in records if item.get("event") == "step_complete"]
    expected_phases = ["resume_catalog_regeneration", "business_trigger_tests", "foreign_keys_not_valid", "analyze"]
    if phases != expected_phases:
        raise RuntimeError("post-FK run did not complete the exact approved phase sequence")
    tests = [item.get("test") for item in records if item.get("event") == "synthetic_test_pass"]
    if len(tests) != len(expected_tests) or set(tests) != expected_tests:
        raise RuntimeError("post-FK run lacks the exact approved synthetic test results")
    artifacts = [item for item in records if item.get("event") == "resume_artifacts_verified"]
    catalog_delta = [item for item in records if item.get("event") == "resume_catalog_delta_verified"]
    target = [item for item in records if item.get("event") == "resume_target_counts_verified"]
    if (len(artifacts) != 1 or artifacts[0].get("source_run") != APPROVED_RESUME_RUN_ID
            or artifacts[0].get("tables") != 270 or artifacts[0].get("rows") != APPROVED_RESUME_SOURCE_ROWS
            or len(catalog_delta) != 1 or catalog_delta[0].get("source_run") != APPROVED_RESUME_RUN_ID
            or catalog_delta[0].get("fk_type_normalizations") != 3
            or catalog_delta[0].get("source_rows") != APPROVED_RESUME_SOURCE_ROWS
            or len(target) != 1 or target[0].get("tables") != 270
            or target[0].get("rows") != APPROVED_RESUME_SOURCE_ROWS
            or target[0].get("existing_foreign_keys") != 0):
        raise RuntimeError("post-FK run lacks the approved source and target preflight evidence")
    failures = [item for item in records if item.get("event") == "step_failed"]
    blocked = [item for item in records if item.get("event") == "pipeline_blocked"]
    stopped = [item for item in records if item.get("event") == "postgres_cluster_stopped"]
    if (len(failures) != 1 or failures[0].get("phase") != "reconciliation"
            or failures[0].get("error_type") != "RuntimeError"
            or failures[0].get("error_code") != "unknown"
            or failures[0].get("sqlstate") != "none"
            or len(blocked) != 1 or len(stopped) != 1
            or blocked[0].get("stop_error") is not None or blocked[0].get("cleanup_error") is not None
            or stopped[0].get("reason") != "pipeline_failure"):
        raise RuntimeError("post-FK run did not stop at the reviewed reconciliation boundary")
    if any(item.get("event") in {"cluster_stop_failed_finally", "credential_tmpfs_cleanup_failed_finally"}
           for item in records):
        raise RuntimeError("post-FK source run recorded an incomplete cleanup")
    forbidden = {"empty_target_created", "loader_metrics_verified", "table_loaded", "table_reconciled",
                 "reconciliation_summary", "pipeline_accepted"}
    if any(item.get("event") in forbidden for item in records):
        raise RuntimeError("post-FK run contains a forbidden creation, load, or acceptance event")
    if any(item.get("event") == "step_failed" and item.get("phase") != "reconciliation" for item in records):
        raise RuntimeError("post-FK run has an unexpected failed phase")
    if source_run_id != APPROVED_POST_FK_RUN_ID:
        raise RuntimeError("post-FK source run identifier is not explicitly approved")


def validate_resume_table_inventory(metrics, legacy_tables, legacy_classification):
    if (not isinstance(metrics, list) or len(metrics) != 270
            or not isinstance(legacy_tables, list) or not isinstance(legacy_classification, dict)):
        raise RuntimeError("resume table inventory is malformed")
    names = [item.get("table") if isinstance(item, dict) else None for item in metrics]
    if (any(not isinstance(name, str) or not re.fullmatch(r"[A-Za-z0-9_]+", name) for name in names)
            or len(set(names)) != 270
            or any(not isinstance(name, str) for name in legacy_tables)
            or len(set(legacy_tables)) != len(legacy_tables)
            or not set(legacy_classification).issubset(set(names))
            or not set(legacy_tables).issubset(set(names))):
        raise RuntimeError("resume table inventory is inconsistent")
    legacy_from_classification = {
        name for name, decision in legacy_classification.items()
        if isinstance(decision, dict) and decision.get("schema") == "legado"
    }
    if legacy_from_classification != set(legacy_tables):
        raise RuntimeError("resume legacy classification is inconsistent")
    if any(not isinstance(item.get("source_rows"), int) or item["source_rows"] < 0 for item in metrics):
        raise RuntimeError("resume table row metrics are malformed")
    return names


def load_resume_artifacts(resume_run_id: str, previous_commit: str, batch_size: int, postgres_uid: int):
    if not re.fullmatch(r"\d{8}T\d{6}Z", resume_run_id):
        raise RuntimeError("resume run identifier is invalid")
    run_dir = RUN_ROOT / ("run_" + resume_run_id)
    log = RUN_ROOT / (resume_run_id + ".jsonl")
    for path, expected_mode, expected_uid in ((run_dir, 0o700, postgres_uid),
                                               (run_dir / "sql", 0o700, postgres_uid),
                                               (log, 0o600, 0)):
        st = path.lstat()
        if (stat.S_ISLNK(st.st_mode) or st.st_uid != expected_uid
                or stat.S_IMODE(st.st_mode) != expected_mode
                or (path == log and not stat.S_ISREG(st.st_mode))
                or (path != log and not stat.S_ISDIR(st.st_mode))):
            raise RuntimeError("resume artifact ownership or mode is unsafe")
    records = []
    for line in log.read_text(encoding="utf-8").splitlines():
        try:
            item = json.loads(line)
        except json.JSONDecodeError:
            raise RuntimeError("resume log is malformed") from None
        if isinstance(item, dict):
            records.append(item)
    validate_resume_history(records, previous_commit, batch_size)

    required_sql = {
        "001_schema.sql", "002_constraints_indexes.sql", "002_foreign_keys.sql",
        "002_validate_constraints.sql", "003_identity_sequences.sql",
        "004_on_update_timestamp.sql", "005_business_triggers_routines.sql", "006_grants.sql",
    }
    catalog_path = run_dir / "catalog.json"
    loader_path = run_dir / "loader_summary.json"
    routine_snapshot_path = run_dir / "routine_snapshot.json"
    required_files = (catalog_path, loader_path, routine_snapshot_path)
    for path in required_files:
        st = path.lstat()
        if (stat.S_ISLNK(st.st_mode) or not stat.S_ISREG(st.st_mode) or st.st_uid != postgres_uid
                or stat.S_IMODE(st.st_mode) != 0o600):
            raise RuntimeError("resume evidence ownership or mode is unsafe")
    catalog_summary = json.loads(catalog_path.read_text(encoding="utf-8"))
    loader_summary = json.loads(loader_path.read_text(encoding="utf-8"))
    routine_snapshot = json.loads(routine_snapshot_path.read_text(encoding="utf-8"))
    ddl_hashes = catalog_summary.get("ddl_sha256")
    if (not isinstance(ddl_hashes, dict) or set(ddl_hashes) != required_sql
            or catalog_summary.get("tables") != 270 or catalog_summary.get("columns") != 3339):
        raise RuntimeError("resume catalog summary does not match the approved v9 shape")
    if {path.name for path in (run_dir / "sql").glob("*.sql")} != required_sql:
        raise RuntimeError("resume DDL directory contains unexpected or missing SQL files")
    for name, expected_hash in ddl_hashes.items():
        path = run_dir / "sql" / name
        st = path.lstat()
        if (stat.S_ISLNK(st.st_mode) or not stat.S_ISREG(st.st_mode) or st.st_uid != postgres_uid
                or stat.S_IMODE(st.st_mode) != 0o600 or not re.fullmatch(r"[0-9a-f]{64}", expected_hash)
                or hashlib.sha256(path.read_bytes()).hexdigest() != expected_hash):
            raise RuntimeError("resume DDL artifact failed its recorded hash check")
    metrics = loader_summary.get("table_metrics")
    legacy_tables = catalog_summary.get("legacy_tables")
    legacy_classification = catalog_summary.get("legacy_classification")
    if (loader_summary.get("tables_loaded") != 270 or not isinstance(metrics, list) or len(metrics) != 270
            or not isinstance(legacy_tables, list) or len(set(legacy_tables)) != len(legacy_tables)
            or not isinstance(legacy_classification, dict)
            or loader_summary.get("dst_ambiguous_local_times")
            or loader_summary.get("zero_date_conversions") != catalog_summary.get("zero_date_counts")):
        raise RuntimeError("resume load evidence does not meet the approved v9 contract")
    validate_resume_table_inventory(metrics, legacy_tables, legacy_classification)
    if sum(item["source_rows"] for item in metrics) != loader_summary.get("source_rows_loaded"):
        raise RuntimeError("resume table metrics are malformed")
    return run_dir, log, catalog_summary, loader_summary, routine_snapshot


def replace_resume_column_type(sql_text: str, table: str, column: str, current_type: str, approved_type: str):
    marker = f'CREATE TABLE "erp"."{table}" ('
    start = sql_text.find(marker)
    end = sql_text.find("\n);", start) if start >= 0 else -1
    if start < 0 or end < 0:
        raise RuntimeError("resume DDL is missing an expected table definition")
    block = sql_text[start:end]
    pattern = re.compile(rf'(^[ \t]*"{re.escape(column)}"\s+){re.escape(current_type)}(?=\s|,|$)', re.M)
    normalized, count = pattern.subn(lambda match: match.group(1) + approved_type, block)
    if count != 1:
        raise RuntimeError("resume DDL type transition is not the exact approved column change")
    return sql_text[:start] + normalized + sql_text[end:]


def validate_resume_catalog_transition(previous_summary: dict, regenerated_summary: dict,
                                      previous_sql_dir: Path, regenerated_sql_dir: Path,
                                      postgres_uid: int | None = None):
    required_sql = {
        "001_schema.sql", "002_constraints_indexes.sql", "002_foreign_keys.sql",
        "002_validate_constraints.sql", "003_identity_sequences.sql", "004_on_update_timestamp.sql",
        "005_business_triggers_routines.sql", "006_grants.sql",
    }
    if ({path.name for path in previous_sql_dir.glob("*.sql")} != required_sql
            or {path.name for path in regenerated_sql_dir.glob("*.sql")} != required_sql):
        raise RuntimeError("resume DDL inventory differs from the reviewed file set")
    if any(summary.get(key) != value for summary in (previous_summary, regenerated_summary)
           for key, value in {"tables": 270, "columns": 3339, "foreign_keys": 11, "triggers": 7,
                              "routines": 2, "events": 0, "auto_increment_columns": 232}.items()):
        raise RuntimeError("source catalog counts differ from the approved resume snapshot")
    if ({key: value for key, value in previous_summary.items() if key != "ddl_sha256"}
            != {key: value for key, value in regenerated_summary.items()
                if key not in {"ddl_sha256", "fk_type_normalizations", "fk_type_normalizations_sha256"}}):
        raise RuntimeError("source catalog metadata changed since the partial target was loaded")

    expected = {
        ("aplicativos_versoes", "aplicativo_id", "aplicativos_catalogo", "id"):
            ("integer", "bigint", "widen_signed_integer_foreign_key", None),
        ("moradores_anexos", "morador_id", "moradores", "id"):
            ("integer", "bigint", "widen_signed_integer_foreign_key", None),
        ("rbac_auditoria", "sessao_id", "rbac_sessoes", "id"):
            ("numeric(20,0)", "bigint", "narrow_unsigned_bigint_with_verified_range",
             {"rows": 791, "nulls": 0, "fractional": 0, "negative": 0, "outside_signed_bigint": 0}),
    }
    normalizations = regenerated_summary.get("fk_type_normalizations")
    if not isinstance(normalizations, list) or len(normalizations) != len(expected):
        raise RuntimeError("regenerated FK normalization manifest differs from the approved scope")
    observed = {}
    for item in normalizations:
        if not isinstance(item, dict):
            raise RuntimeError("regenerated FK normalization manifest is malformed")
        key = (item.get("table"), item.get("column"), item.get("referenced_table"), item.get("referenced_column"))
        if key in observed or key not in expected:
            raise RuntimeError("regenerated FK normalization contains an unapproved column")
        observed[key] = item
    if set(observed) != set(expected):
        raise RuntimeError("regenerated FK normalization omitted an approved column")
    base_keys = {"table", "column", "referenced_table", "referenced_column", "source_pg_type",
                 "referenced_pg_type", "target_pg_type", "rule"}
    for key, (source_type, target_type, rule, counts) in expected.items():
        item = observed[key]
        expected_keys = base_keys | ({"source_counts"} if counts is not None else set())
        if (set(item) != expected_keys
                or (item["source_pg_type"], item["target_pg_type"], item["rule"])
                != (source_type, target_type, rule)
                or item["referenced_pg_type"] != target_type):
            raise RuntimeError("regenerated FK normalization type mapping differs from the reviewed contract")
        if counts is not None and item.get("source_counts") != counts:
            raise RuntimeError("regenerated unsigned bigint range evidence differs from the reviewed snapshot")
    manifest_json = json.dumps(normalizations, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    if hashlib.sha256(manifest_json.encode()).hexdigest() != regenerated_summary.get("fk_type_normalizations_sha256"):
        raise RuntimeError("regenerated FK normalization fingerprint is invalid")

    for name in sorted(required_sql):
        old_path = previous_sql_dir / name
        new_path = regenerated_sql_dir / name
        for path in (old_path, new_path):
            info = path.lstat()
            if (not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_mode & 0o077
                    or (postgres_uid is not None and info.st_uid != postgres_uid)):
                raise RuntimeError("resume DDL artifact is not a private regular file")
        old_sql = old_path.read_text(encoding="utf-8")
        new_sql = new_path.read_text(encoding="utf-8")
        old_hash = previous_summary.get("ddl_sha256", {}).get(name)
        new_hash = regenerated_summary.get("ddl_sha256", {}).get(name)
        if (not re.fullmatch(r"[0-9a-f]{64}", str(old_hash))
                or not re.fullmatch(r"[0-9a-f]{64}", str(new_hash))
                or hashlib.sha256(old_path.read_bytes()).hexdigest() != old_hash
                or hashlib.sha256(new_path.read_bytes()).hexdigest() != new_hash):
            raise RuntimeError("resume DDL hash does not match its private catalog summary")
        if name == "001_schema.sql":
            for (table, column, _ref_table, _ref_column), (old_type, new_type, _rule, _counts) in expected.items():
                new_sql = replace_resume_column_type(new_sql, table, column, new_type, old_type)
            if old_sql != new_sql:
                raise RuntimeError("schema DDL changed outside the three reviewed FK column types")
        elif name == "002_foreign_keys.sql":
            old_fk_statements = sorted(line.strip() for line in old_sql.splitlines()
                                       if " ADD CONSTRAINT " in line and " FOREIGN KEY " in line)
            new_fk_statements = sorted(line.strip() for line in new_sql.splitlines()
                                       if " ADD CONSTRAINT " in line and " FOREIGN KEY " in line)
            if old_fk_statements != new_fk_statements:
                raise RuntimeError("foreign key definitions changed during resume catalog regeneration")
            expected_non_fk = [
                "DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace "
                "WHERE c.contype='f' AND n.nspname='erp') THEN "
                "RAISE EXCEPTION 'foreign key type normalization requires zero existing foreign keys'; END IF; END $$;"
            ]
            for (table, column, ref_table, ref_column), (source_type, target_type, _rule, _counts) in expected.items():
                qtable = f'"erp"."{table}"'
                ref_qtable = f"erp.{ref_table}"
                allowed = ", ".join(quote_pg_literal(value) for value in sorted({source_type, target_type}))
                expected_non_fk.extend([
                    "DO $$ DECLARE actual_type text; BEGIN "
                    f"SELECT format_type(a.atttypid,a.atttypmod) INTO actual_type FROM pg_attribute a "
                    f"WHERE a.attrelid={quote_pg_literal(qtable)}::regclass AND a.attname={quote_pg_literal(column)} "
                    "AND a.attnum>0 AND NOT a.attisdropped; "
                    f"IF actual_type IS NULL OR actual_type NOT IN ({allowed}) THEN "
                    "RAISE EXCEPTION 'foreign key source type differs from the reviewed normalization'; END IF; END $$;",
                    "DO $$ DECLARE actual_type text; BEGIN "
                    f"SELECT format_type(a.atttypid,a.atttypmod) INTO actual_type FROM pg_attribute a "
                    f"WHERE a.attrelid={quote_pg_literal(ref_qtable)}::regclass "
                    f"AND a.attname={quote_pg_literal(ref_column)} AND a.attnum>0 AND NOT a.attisdropped; "
                    f"IF actual_type IS DISTINCT FROM {quote_pg_literal(target_type)} THEN "
                    "RAISE EXCEPTION 'foreign key referenced type differs from the reviewed normalization'; END IF; END $$;",
                ])
                if _rule == "narrow_unsigned_bigint_with_verified_range":
                    column_sql = f'"{column}"'
                    expected_non_fk.append(
                        f"DO $$ BEGIN IF (SELECT COUNT(*) FROM {qtable}) <> 791 OR EXISTS "
                        f"(SELECT 1 FROM {qtable} WHERE {column_sql} IS NULL OR "
                        f"({column_sql}::numeric <> trunc({column_sql}::numeric,0) OR {column_sql} < 0 "
                        f"OR {column_sql} > 9223372036854775807)) THEN "
                        "RAISE EXCEPTION 'unsigned foreign key values exceed the approved signed bigint range'; END IF; END $$;"
                    )
                expected_non_fk.append(
                    f"ALTER TABLE {qtable} ALTER COLUMN \"{column}\" TYPE bigint USING \"{column}\"::bigint;"
                )
                if _rule == "widen_signed_integer_foreign_key":
                    check_name = generated_name("ck", table, column, "mysql_signed_int_range")
                    expected_non_fk.append(
                        f"ALTER TABLE {qtable} ADD CONSTRAINT \"{check_name}\" "
                        f"CHECK (\"{column}\" BETWEEN -2147483648 AND 2147483647);"
                    )
            new_non_fk = [line.strip() for line in new_sql.splitlines() if line.strip()
                          and not (" ADD CONSTRAINT " in line and " FOREIGN KEY " in line)]
            if new_non_fk != expected_non_fk:
                raise RuntimeError("FK type-normalization DDL differs from the approved atomic bundle")
        elif old_sql != new_sql:
            raise RuntimeError("resume DDL changed outside the reviewed FK normalization files")


def load_post_fk_artifacts(source_run_id: str, postgres_uid: int):
    if source_run_id != APPROVED_POST_FK_RUN_ID:
        raise RuntimeError("post-FK source run identifier is not explicitly approved")
    run_dir = RUN_ROOT / ("run_" + source_run_id)
    log = RUN_ROOT / (source_run_id + ".jsonl")
    for path, expected_mode, expected_uid, directory in (
            (run_dir, 0o700, postgres_uid, True), (run_dir / "sql", 0o700, postgres_uid, True),
            (log, 0o600, 0, False)):
        item = path.lstat()
        if (stat.S_ISLNK(item.st_mode) or item.st_uid != expected_uid
                or stat.S_IMODE(item.st_mode) != expected_mode
                or (directory and not stat.S_ISDIR(item.st_mode))
                or (not directory and not stat.S_ISREG(item.st_mode))):
            raise RuntimeError("post-FK source run ownership or mode is unsafe")
    records = []
    for line in log.read_text(encoding="utf-8").splitlines():
        try:
            item = json.loads(line)
        except json.JSONDecodeError:
            raise RuntimeError("post-FK source run log is malformed") from None
        if isinstance(item, dict):
            records.append(item)
    validate_post_fk_resume_history(records, source_run_id)
    required_sql = {
        "001_schema.sql", "002_constraints_indexes.sql", "002_foreign_keys.sql",
        "002_validate_constraints.sql", "003_identity_sequences.sql",
        "004_on_update_timestamp.sql", "005_business_triggers_routines.sql", "006_grants.sql",
    }
    if {path.name for path in (run_dir / "sql").glob("*.sql")} != required_sql:
        raise RuntimeError("post-FK source DDL inventory is incomplete or unexpected")
    catalog_path = run_dir / "catalog.json"
    routine_path = run_dir / "routine_snapshot.json"
    for path in (catalog_path, routine_path):
        item = path.lstat()
        if (stat.S_ISLNK(item.st_mode) or not stat.S_ISREG(item.st_mode)
                or item.st_uid != postgres_uid or stat.S_IMODE(item.st_mode) != 0o600):
            raise RuntimeError("post-FK source evidence ownership or mode is unsafe")
    summary = json.loads(catalog_path.read_text(encoding="utf-8"))
    snapshot = json.loads(routine_path.read_text(encoding="utf-8"))
    ddl_hashes = summary.get("ddl_sha256")
    if (not isinstance(ddl_hashes, dict) or set(ddl_hashes) != required_sql
            or any(summary.get(key) != value for key, value in {
                "tables": 270, "columns": 3339, "foreign_keys": 11, "triggers": 7,
                "routines": 2, "events": 0, "auto_increment_columns": 232,
            }.items())):
        raise RuntimeError("post-FK source catalog summary differs from the approved rehearsal shape")
    for name, digest in ddl_hashes.items():
        path = run_dir / "sql" / name
        item = path.lstat()
        if (stat.S_ISLNK(item.st_mode) or not stat.S_ISREG(item.st_mode)
                or item.st_uid != postgres_uid or stat.S_IMODE(item.st_mode) != 0o600
                or not re.fullmatch(r"[0-9a-f]{64}", str(digest))
                or hashlib.sha256(path.read_bytes()).hexdigest() != digest):
            raise RuntimeError("post-FK source DDL failed its recorded hash check")
    return run_dir, log, summary, snapshot


def verify_post_fk_run_manifest(post_fk_run_id: str, postgres_uid: int, *,
                                manifest_path: Path | None = None, root_uid: int = 0):
    if post_fk_run_id != APPROVED_POST_FK_RUN_ID:
        raise RuntimeError("post-FK manifest run identifier is not explicitly approved")
    manifest_path = manifest_path or APPROVED_POST_FK_MANIFEST
    manifest_stat = manifest_path.lstat()
    if (stat.S_ISLNK(manifest_stat.st_mode) or not stat.S_ISREG(manifest_stat.st_mode)
            or manifest_stat.st_uid != root_uid or stat.S_IMODE(manifest_stat.st_mode) != 0o600):
        raise RuntimeError("post-FK run manifest ownership or mode is unsafe")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if (set(manifest) != {"schema_version", "post_fk_run_id", "post_fk_code_commit", "resume_run_id",
                          "resume_code_commit", "files"}
            or manifest.get("schema_version") != 1
            or manifest.get("post_fk_run_id") != APPROVED_POST_FK_RUN_ID
            or manifest.get("post_fk_code_commit") != APPROVED_POST_FK_SOURCE_COMMIT
            or manifest.get("resume_run_id") != APPROVED_RESUME_RUN_ID
            or manifest.get("resume_code_commit") != APPROVED_RESUME_SOURCE_COMMIT):
        raise RuntimeError("post-FK run manifest does not match the approved run provenance")
    post_dir = RUN_ROOT / ("run_" + APPROVED_POST_FK_RUN_ID)
    resume_dir = RUN_ROOT / ("run_" + APPROVED_RESUME_RUN_ID)
    expected_paths = {
        RUN_ROOT / (APPROVED_POST_FK_RUN_ID + ".jsonl"), post_dir / "catalog.json",
        post_dir / "routine_snapshot.json", RUN_ROOT / (APPROVED_RESUME_RUN_ID + ".jsonl"),
        resume_dir / "catalog.json", resume_dir / "loader_summary.json", resume_dir / "routine_snapshot.json",
    }
    required_sql = {
        "001_schema.sql", "002_constraints_indexes.sql", "002_foreign_keys.sql",
        "002_validate_constraints.sql", "003_identity_sequences.sql",
        "004_on_update_timestamp.sql", "005_business_triggers_routines.sql", "006_grants.sql",
    }
    expected_paths.update(post_dir / "sql" / name for name in required_sql)
    expected_paths.update(resume_dir / "sql" / name for name in required_sql)
    file_hashes = manifest.get("files")
    if (not isinstance(file_hashes, dict)
            or set(file_hashes) != {str(path.relative_to(RUN_ROOT)) for path in expected_paths}):
        raise RuntimeError("post-FK run manifest file inventory is incomplete or unexpected")
    for path in expected_paths:
        item = path.lstat()
        expected_uid = root_uid if path.suffix == ".jsonl" else postgres_uid
        if (stat.S_ISLNK(item.st_mode) or not stat.S_ISREG(item.st_mode)
                or item.st_uid != expected_uid or stat.S_IMODE(item.st_mode) != 0o600
                or not re.fullmatch(r"[0-9a-f]{64}", str(file_hashes[str(path.relative_to(RUN_ROOT))]))
                or hashlib.sha256(path.read_bytes()).hexdigest() != file_hashes[str(path.relative_to(RUN_ROOT))]):
            raise RuntimeError("post-FK run evidence differs from its root-owned manifest")


def validate_catalog_artifact_snapshot(source_summary: dict, current_summary: dict,
                                       source_sql_dir: Path, current_sql_dir: Path,
                                       postgres_uid: int):
    if source_summary != current_summary:
        raise RuntimeError("source catalog summary changed after the post-FK run")
    required_sql = set(source_summary.get("ddl_sha256", {}))
    if (required_sql != {path.name for path in source_sql_dir.glob("*.sql")}
            or required_sql != {path.name for path in current_sql_dir.glob("*.sql")}):
        raise RuntimeError("source catalog DDL inventory changed after the post-FK run")
    for name in required_sql:
        source = source_sql_dir / name
        current = current_sql_dir / name
        for path in (source, current):
            item = path.lstat()
            if (stat.S_ISLNK(item.st_mode) or not stat.S_ISREG(item.st_mode)
                    or item.st_uid != postgres_uid or stat.S_IMODE(item.st_mode) != 0o600):
                raise RuntimeError("source catalog DDL artifact ownership or mode is unsafe")
        if (source.read_bytes() != current.read_bytes()
                or hashlib.sha256(current.read_bytes()).hexdigest() != current_summary["ddl_sha256"][name]):
            raise RuntimeError("source catalog DDL changed after the post-FK run")


def quote_pg_ident(value: str) -> str:
    return '"' + value.replace('"', '""') + '"'


def quote_pg_literal(value: str) -> str:
    return "'" + value.replace("'", "''") + "'"


def safe_summary_scalars(item: dict) -> dict:
    return {key: value for key, value in item.items()
            if isinstance(value, (int, float, bool, str))
            and not key.endswith("_sha256") and not key.endswith("_fingerprint")}


def verify_resume_target(dbname: str, catalog_summary: dict, loader_summary: dict):
    legacy_tables = set(catalog_summary["legacy_tables"])
    metrics = loader_summary["table_metrics"]
    branches = []
    expected = {}
    for item in metrics:
        table = item["table"]
        schema = "legado" if table in legacy_tables else "erp"
        label = f"{schema}.{table}"
        expected[label] = int(item["source_rows"])
        qualified = f"{quote_pg_ident(schema)}.{quote_pg_ident(table)}"
        branches.append(f"SELECT {quote_pg_literal(label)}::text,COUNT(*)::bigint FROM {qualified}")
    query = " UNION ALL ".join(branches)
    preflight_sql = (
        "SELECT current_setting('TimeZone')||'|'||current_setting('server_encoding')||'|'||"
        "(SELECT COUNT(*) FROM pg_tables WHERE schemaname IN ('erp','legado'))||'|'||"
        "(SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema IN ('erp','legado') "
        "AND constraint_type='FOREIGN KEY')||'|'||"
        "(SELECT format_type(a.atttypid,a.atttypmod) FROM pg_attribute a "
        "WHERE a.attrelid='erp.aplicativos_versoes'::regclass AND a.attname='aplicativo_id' "
        "AND a.attnum>0 AND NOT a.attisdropped)||'|'||"
        "(SELECT format_type(a.atttypid,a.atttypmod) FROM pg_attribute a "
        "WHERE a.attrelid='erp.moradores_anexos'::regclass AND a.attname='morador_id' "
        "AND a.attnum>0 AND NOT a.attisdropped)||'|'||"
        "(SELECT format_type(a.atttypid,a.atttypmod) FROM pg_attribute a "
        "WHERE a.attrelid='erp.rbac_auditoria'::regclass AND a.attname='sessao_id' "
        "AND a.attnum>0 AND NOT a.attisdropped)"
    )
    preflight = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-At", "-h", PGSOCKET,
                               "-p", str(PGPORT), "-U", "postgres", "-d", dbname, "-c", preflight_sql),
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    fields = preflight.stdout.strip().split("|")
    if (preflight.returncode or len(fields) != 7 or fields[:3] != ["America/Sao_Paulo", "UTF8", "270"]
            or not fields[3].isdigit() or int(fields[3]) > 11
            or fields[4:] != ["integer", "integer", "numeric(20,0)"]):
        raise RuntimeError("partial rehearsal target does not match its expected pre-harness state")
    foreign_key_count = int(fields[3])
    result = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-A", "-t", "-F", "|",
                             "-v", "ON_ERROR_STOP=1", "-h", PGSOCKET, "-p", str(PGPORT),
                             "-U", "postgres", "-d", dbname, "-c", query),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if result.returncode:
        raise RuntimeError("partial target row-count verification failed")
    actual = {}
    for line in result.stdout.splitlines():
        parts = line.split("|")
        if len(parts) != 2 or not parts[1].isdigit() or parts[0] in actual:
            raise RuntimeError("partial target row-count result is malformed")
        actual[parts[0]] = int(parts[1])
    if actual != expected or sum(actual.values()) != loader_summary.get("source_rows_loaded"):
        raise RuntimeError("partial target counts differ from recorded loaded metrics")
    return foreign_key_count


def verify_post_fk_target(dbname: str, catalog_summary: dict, loader_summary: dict):
    metrics = loader_summary.get("table_metrics")
    legacy_tables = catalog_summary.get("legacy_tables")
    legacy_classification = catalog_summary.get("legacy_classification")
    validate_resume_table_inventory(metrics, legacy_tables, legacy_classification)
    if (loader_summary.get("tables_loaded") != 270
            or loader_summary.get("source_rows_loaded") != APPROVED_RESUME_SOURCE_ROWS
            or sum(item["source_rows"] for item in metrics) != APPROVED_RESUME_SOURCE_ROWS):
        raise RuntimeError("post-FK source row evidence differs from the approved loaded snapshot")
    preflight_sql = (
        "SELECT current_setting('TimeZone')||'|'||current_setting('server_encoding')||'|'||"
        "(SELECT COUNT(*) FROM pg_tables WHERE schemaname IN ('erp','legado'))||'|'||"
        "(SELECT COUNT(*) FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace "
        "WHERE c.contype='f' AND n.nspname IN ('erp','legado'))||'|'||"
        "(SELECT COUNT(*) FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace "
        "WHERE c.contype='f' AND NOT c.convalidated AND n.nspname IN ('erp','legado'))||'|'||"
        "(SELECT format_type(a.atttypid,a.atttypmod) FROM pg_attribute a "
        "WHERE a.attrelid='erp.aplicativos_versoes'::regclass AND a.attname='aplicativo_id' "
        "AND a.attnum>0 AND NOT a.attisdropped)||'|'||"
        "(SELECT format_type(a.atttypid,a.atttypmod) FROM pg_attribute a "
        "WHERE a.attrelid='erp.moradores_anexos'::regclass AND a.attname='morador_id' "
        "AND a.attnum>0 AND NOT a.attisdropped)||'|'||"
        "(SELECT format_type(a.atttypid,a.atttypmod) FROM pg_attribute a "
        "WHERE a.attrelid='erp.rbac_auditoria'::regclass AND a.attname='sessao_id' "
        "AND a.attnum>0 AND NOT a.attisdropped)"
    )
    preflight = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-At", "-v", "ON_ERROR_STOP=1",
                                           "-h", PGSOCKET, "-p", str(PGPORT), "-U", "postgres",
                                           "-d", dbname, "-c", preflight_sql),
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    fields = preflight.stdout.strip().split("|")
    if (preflight.returncode or fields != ["America/Sao_Paulo", "UTF8", "270", "11", "11",
                                           "bigint", "bigint", "bigint"]):
        raise RuntimeError("post-FK rehearsal target does not match the exact approved FK boundary")
    legacy = set(legacy_tables)
    expected = {}
    branches = []
    for item in metrics:
        table = item["table"]
        schema = "legado" if table in legacy else "erp"
        label = f"{schema}.{table}"
        if label in expected:
            raise RuntimeError("post-FK source table metrics contain duplicates")
        expected[label] = int(item["source_rows"])
        qualified = f"{quote_pg_ident(schema)}.{quote_pg_ident(table)}"
        branches.append(f"SELECT {quote_pg_literal(label)}::text,COUNT(*)::bigint FROM {qualified}")
    result = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-A", "-t", "-F", "|",
                                        "-v", "ON_ERROR_STOP=1", "-h", PGSOCKET, "-p", str(PGPORT),
                                        "-U", "postgres", "-d", dbname, "-c", " UNION ALL ".join(branches)),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if result.returncode:
        raise RuntimeError("post-FK table-count verification failed")
    actual = {}
    for line in result.stdout.splitlines():
        parts = line.split("|")
        if len(parts) != 2 or not parts[1].isdigit() or parts[0] in actual:
            raise RuntimeError("post-FK table-count result is malformed")
        actual[parts[0]] = int(parts[1])
    if actual != expected or sum(actual.values()) != APPROVED_RESUME_SOURCE_ROWS:
        raise RuntimeError("post-FK table counts differ from the approved loaded snapshot")
    return 11


def apply_sql(dbname: str, path: Path, phase: str, log: Path):
    command = as_postgres("/usr/bin/psql", "-X", "-q", "-v", "ON_ERROR_STOP=1", "-v", "VERBOSITY=verbose",
                          "-h", PGSOCKET, "-p", str(PGPORT), "-U", "postgres", "-d", dbname)
    if phase == "foreign_keys_not_valid":
        command.append("-1")
    command.extend(("-f", path))
    run_step(command, phase, log)


def run_reconciliation_step(base, dbname: str, mysql_config: Path, run_dir: Path, log: Path):
    version = "v10b" if dbname == V10B_DATABASE else "v9"
    return run_step([*base, str(CODE_ROOT / "reconcile.py"), "--mysql-config", str(mysql_config),
                     "--pg-socket", str(PGSOCKET), "--pg-port", str(PGPORT), "--database", dbname,
                     "--repo-root", str(REPO_ROOT), "--csv", str(run_dir / f"reconciliacao_{version}.csv"),
                     "--summary", str(run_dir / "reconciliation_summary.json")],
                    "reconciliation", log, whitelist_json=True)


def stop_rehearsal_cluster(log: Path, reason: str):
    global CLUSTER_STARTED_BY_RUNNER
    command = "/usr/lib/postgresql/18/bin/pg_ctl"
    status = subprocess.run(as_postgres(command, "-D", PGDATA, "status"),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if status.returncode != 0:
        if PGDATA.joinpath("postmaster.pid").exists():
            raise RuntimeError("rehearsal PostgreSQL status is ambiguous")
        CLUSTER_STARTED_BY_RUNNER = False
        emit_log(log, "postgres_cluster_already_stopped", reason=reason)
        return
    stopped = subprocess.run(as_postgres(command, "-D", PGDATA, "-w", "-t", "120", "stop", "-m", "fast"),
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    verified = subprocess.run(as_postgres(command, "-D", PGDATA, "status"),
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if stopped.returncode or verified.returncode == 0 or PGDATA.joinpath("postmaster.pid").exists():
        raise RuntimeError("isolated PostgreSQL cluster did not stop cleanly")
    CLUSTER_STARTED_BY_RUNNER = False
    emit_log(log, "postgres_cluster_stopped", reason=reason, mode="fast")


def main():
    global CURRENT_PHASE, TARGET_CREATED_BY_RUNNER
    ap = argparse.ArgumentParser(description="Run a clean, isolated MySQL→PostgreSQL rehearsal")
    ap.add_argument("--database", required=True)
    ap.add_argument("--expected-commit", required=True)
    ap.add_argument("--batch-size", type=int, default=1000)
    ap.add_argument("--resume-partial-target", action="store_true")
    ap.add_argument("--resume-post-fk-reconciliation", action="store_true")
    ap.add_argument("--source-run-id")
    args = ap.parse_args()
    if args.database == PRESERVED_PARTIAL_V10_DATABASE:
        raise SystemExit("erp_ensaio_v10 is a preserved partial target and is quarantined from all runner modes")
    if args.database not in REHEARSAL_DATABASES:
        raise SystemExit("refusing any destination except the approved isolated v9/v10b rehearsal databases")
    is_v10 = args.database == V10B_DATABASE
    if is_v10 and (args.resume_partial_target or args.resume_post_fk_reconciliation or args.source_run_id):
        raise SystemExit("v10b must start from zero and cannot use a resume mode")
    if args.batch_size < 1 or args.batch_size > 10000:
        raise SystemExit("batch size out of range")
    if args.resume_partial_target and args.resume_post_fk_reconciliation:
        raise SystemExit("resume modes are mutually exclusive")
    if (args.resume_partial_target or args.resume_post_fk_reconciliation) and args.batch_size != 1000:
        raise SystemExit("approved partial target can only resume with its original batch size")
    if args.resume_post_fk_reconciliation:
        if args.source_run_id != APPROVED_POST_FK_RUN_ID:
            raise SystemExit("post-FK reconciliation requires its exact approved source run id")
    elif args.source_run_id is not None:
        raise SystemExit("source run id is accepted only in post-FK reconciliation mode")
    if os.geteuid() != 0:
        raise SystemExit("runner must start as root so it can verify container namespace")
    signal.signal(signal.SIGTERM, handle_signal)
    signal.signal(signal.SIGINT, handle_signal)
    os.umask(0o077)
    expected_branch = "pg/03-1-ensaio-integral-v10" if is_v10 else "pg/03-conversao-schema"
    verify_code_provenance(args.expected_commit, expected_branch)
    if args.resume_partial_target:
        verify_resume_code_ancestry(CODE_ROOT.parents[1], args.expected_commit)
    elif args.resume_post_fk_reconciliation:
        verify_resume_code_ancestry(CODE_ROOT.parents[1], args.expected_commit,
                                    APPROVED_POST_FK_SOURCE_COMMIT)
    verify_private_mysql_config(MYSQL_SECRET_SOURCE)
    verify_private_mysql_config(MYSQL_ADMIN_CONFIG)
    source_sha256 = None
    source_size_bytes = None
    mysql_pid = None
    if is_v10:
        source_sha256, source_size_bytes = verify_v10_source_integrity()
        mysql_pid, mysql_container_id = check_mysql_namespace()
        verify_v10_source_restore_lineage(source_sha256, source_size_bytes, mysql_container_id)
    if not REPO_ROOT.is_dir() or not PG_PYTHON.is_file():
        raise SystemExit("official source clone or private run root is unavailable")
    postgres = __import__("pwd").getpwnam("postgres")
    active_root = V10B_ROOT if is_v10 else V9_ROOT
    active_run_root = V10B_RUN_ROOT if is_v10 else RUN_ROOT
    active_tmpfs_root = V10B_TMPFS_ROOT if is_v10 else TMPFS_ROOT
    run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    log = None
    run_dir = None
    tmpfs_run = None
    started = time.monotonic()
    CURRENT_PHASE = "runner_setup"
    try:
        ensure_private_directory(active_root, postgres.pw_uid, postgres.pw_gid, 0o700)
        ensure_private_directory(active_run_root, postgres.pw_uid, postgres.pw_gid, 0o700)
        acquire_runner_lock(active_run_root)
        log = active_run_root / (run_id + ".jsonl")
        log.touch(mode=0o600, exist_ok=False)
        log.chmod(0o600)
        run_dir = active_run_root / ("run_" + run_id)
        run_dir.mkdir(mode=0o700, exist_ok=False)
        (run_dir / "sql").mkdir(mode=0o700)
        for directory in (run_dir, run_dir / "sql"):
            os.chown(directory, postgres.pw_uid, postgres.pw_gid)
        prepare_credential_tmpfs(postgres.pw_gid, active_tmpfs_root)
        tmpfs_run = active_tmpfs_root / run_id
        tmpfs_run.mkdir(mode=0o710, exist_ok=False)
        os.chown(tmpfs_run, 0, postgres.pw_gid)
        tmpfs_run.chmod(0o710)
    except Exception as exc:
        cleanup_error = None
        try:
            if tmpfs_run is not None:
                cleanup_tmpfs_run(tmpfs_run, postgres.pw_gid)
            cleanup_tmpfs_root(active_tmpfs_root, postgres.pw_gid)
        except Exception as cleanup_exc:
            cleanup_error = type(cleanup_exc).__name__
        if log is not None:
            try:
                emit_log(log, "pipeline_setup_blocked", phase=CURRENT_PHASE,
                         error_type=type(exc).__name__, error_code="runner_setup_failed",
                         cleanup_error=cleanup_error, target_database=args.database,
                         target_created=False)
            except Exception:
                cleanup_error = cleanup_error or "LogWriteError"
        print("PIPELINE_BLOCKED phase=runner_setup code=runner_setup_failed target_created=false",
              file=sys.stderr, flush=True)
        if cleanup_error:
            print("PIPELINE_CLEANUP_INCOMPLETE see private run log", file=sys.stderr, flush=True)
        raise SystemExit(1)
    mysql_config = tmpfs_run / "client.cnf"
    cluster_started_here = False
    resume_run_dir = None
    resume_source_snapshot = None
    emit_log(log, "pipeline_started", database=args.database, batch_size=args.batch_size,
             mysql_container=MYSQL_CONTAINER, code_commit=args.expected_commit,
             branch=expected_branch, clean_start=is_v10,
             resume_source_run=APPROVED_RESUME_RUN_ID if args.resume_partial_target else None,
             post_fk_source_run=args.source_run_id if args.resume_post_fk_reconciliation else None)
    try:
        if is_v10:
            CURRENT_PHASE = "source_integrity"
            emit_log(log, "phase_started", phase=CURRENT_PHASE)
            emit_log(log, "source_artifacts_integrity_verified", size_bytes=source_size_bytes,
                     hash_verified=True, byte_identical=True, gzip_valid=True)
            emit_log(log, "mysql_restore_lineage_verified", source_schema=V10_SOURCE_SCHEMA,
                     restore_pipeline_exit=0, container_identity_match=True)
            emit_log(log, "step_complete", phase=CURRENT_PHASE, exit_code=0)
        copy_private_config(MYSQL_SECRET_SOURCE, mysql_config, postgres.pw_gid)
        if not is_v10:
            mysql_pid, _mysql_container_id = check_mysql_namespace()
        emit_log(log, "mysql_source_isolated", container=MYSQL_CONTAINER, network_mode="none",
                 read_only_source=True)
        snapshot = read_verified_routine_snapshot()
        routine_snapshot = run_dir / "routine_snapshot.json"
        routine_snapshot.write_text(json.dumps(snapshot, sort_keys=True) + "\n", encoding="utf-8")
        routine_snapshot.chmod(0o600)
        os.chown(routine_snapshot, postgres.pw_uid, postgres.pw_gid)
        emit_log(log, "routine_fingerprints_verified", count=2)
        base = ["nsenter", f"--net=/proc/{mysql_pid}/ns/net", "--", *as_postgres(PG_PYTHON, "-s")]
        if args.resume_post_fk_reconciliation:
            post_fk_run_dir, _post_fk_log, post_fk_summary, post_fk_snapshot = load_post_fk_artifacts(
                args.source_run_id, postgres.pw_uid)
            verify_post_fk_run_manifest(args.source_run_id, postgres.pw_uid)
            resume_run_dir, _resume_log, base_summary, loader_summary, base_snapshot = load_resume_artifacts(
                APPROVED_RESUME_RUN_ID, APPROVED_RESUME_SOURCE_COMMIT, args.batch_size, postgres.pw_uid)
            if snapshot != post_fk_snapshot or snapshot != base_snapshot:
                raise RuntimeError("routine snapshot changed after the post-FK source run")
            validate_resume_catalog_transition(base_summary, post_fk_summary,
                                               resume_run_dir / "sql", post_fk_run_dir / "sql",
                                               postgres.pw_uid)
            emit_log(log, "post_fk_artifacts_verified", source_run=args.source_run_id,
                     tables=270, rows=loader_summary.get("source_rows_loaded"))
            regenerated_catalog = run_dir / "catalog.json"
            run_step([*base, str(CODE_ROOT / "catalog.py"), "--mysql-config", str(mysql_config),
                      "--output", str(run_dir / "sql"), "--summary", str(regenerated_catalog),
                      "--repo-root", str(REPO_ROOT), "--target-database", args.database,
                      "--routine-snapshot", str(routine_snapshot)],
                     "post_fk_catalog_regeneration", log, whitelist_json=True)
            summary_info = regenerated_catalog.lstat()
            if (not stat.S_ISREG(summary_info.st_mode) or stat.S_ISLNK(summary_info.st_mode)
                    or summary_info.st_uid != postgres.pw_uid or stat.S_IMODE(summary_info.st_mode) != 0o600):
                raise RuntimeError("regenerated post-FK catalog summary ownership or mode is unsafe")
            catalog_summary = json.loads(regenerated_catalog.read_text(encoding="utf-8"))
            validate_catalog_artifact_snapshot(post_fk_summary, catalog_summary,
                                               post_fk_run_dir / "sql", run_dir / "sql", postgres.pw_uid)
            ddl_dir = run_dir / "sql"
            emit_log(log, "post_fk_catalog_verified", source_run=args.source_run_id,
                     fk_type_normalizations=3, source_rows=loader_summary.get("source_rows_loaded"))
            cluster_started_here = start_cluster(log, run_dir)
            existing_fk_count = verify_post_fk_target(args.database, catalog_summary, loader_summary)
            emit_log(log, "post_fk_target_verified", tables=270,
                     rows=loader_summary.get("source_rows_loaded"), existing_foreign_keys=existing_fk_count)
            if existing_fk_count != 11:
                raise RuntimeError("post-FK target lacks the exact reviewed foreign key count")
        elif args.resume_partial_target:
            if args.expected_commit == APPROVED_RESUME_SOURCE_COMMIT:
                raise RuntimeError("resume code must be a new commit descended from the partial-run commit")
            resume_run_dir, _resume_log, catalog_summary, loader_summary, resume_source_snapshot = load_resume_artifacts(
                APPROVED_RESUME_RUN_ID, APPROVED_RESUME_SOURCE_COMMIT, args.batch_size, postgres.pw_uid)
            if snapshot != resume_source_snapshot:
                raise RuntimeError("routine snapshot changed since the approved partial run")
            emit_log(log, "resume_artifacts_verified", source_run=APPROVED_RESUME_RUN_ID,
                     tables=270, rows=loader_summary.get("source_rows_loaded"))
            regenerated_catalog = run_dir / "catalog.json"
            run_step([*base, str(CODE_ROOT / "catalog.py"), "--mysql-config", str(mysql_config),
                      "--output", str(run_dir / "sql"), "--summary", str(regenerated_catalog),
                      "--repo-root", str(REPO_ROOT), "--target-database", args.database,
                      "--routine-snapshot", str(routine_snapshot)],
                     "resume_catalog_regeneration", log, whitelist_json=True)
            summary_info = regenerated_catalog.lstat()
            if (not stat.S_ISREG(summary_info.st_mode) or stat.S_ISLNK(summary_info.st_mode)
                    or summary_info.st_uid != postgres.pw_uid or stat.S_IMODE(summary_info.st_mode) != 0o600):
                raise RuntimeError("regenerated catalog summary ownership or mode is unsafe")
            regenerated_summary = json.loads(regenerated_catalog.read_text(encoding="utf-8"))
            validate_resume_catalog_transition(catalog_summary, regenerated_summary,
                                               resume_run_dir / "sql", run_dir / "sql", postgres.pw_uid)
            catalog_summary = regenerated_summary
            ddl_dir = run_dir / "sql"
            emit_log(log, "resume_catalog_delta_verified", source_run=APPROVED_RESUME_RUN_ID,
                     fk_type_normalizations=3, source_rows=loader_summary.get("source_rows_loaded"))
            cluster_started_here = start_cluster(log, run_dir)
            existing_fk_count = verify_resume_target(args.database, catalog_summary, loader_summary)
            emit_log(log, "resume_target_counts_verified", tables=270,
                     rows=loader_summary.get("source_rows_loaded"), existing_foreign_keys=existing_fk_count)
            if existing_fk_count != 0:
                raise RuntimeError("partial target contains foreign keys before the approved resume boundary")
        else:
            catalog = run_dir / "catalog.json"
            run_step([*base, str(CODE_ROOT / "catalog.py"), "--mysql-config", str(mysql_config),
                      "--output", str(run_dir / "sql"), "--summary", str(catalog),
                      "--repo-root", str(REPO_ROOT), "--target-database", args.database,
                      "--routine-snapshot", str(routine_snapshot)],
                     "catalog", log, whitelist_json=True)
            ddl_dir = run_dir / "sql"
            for name in ("001_schema.sql", "002_constraints_indexes.sql", "002_foreign_keys.sql", "002_validate_constraints.sql",
                         "003_identity_sequences.sql", "004_on_update_timestamp.sql",
                         "005_business_triggers_routines.sql", "006_grants.sql"):
                path = ddl_dir / name
                if not path.is_file() or path.stat().st_mode & 0o077:
                    raise RuntimeError("generated SQL file is missing or not private")
            catalog_summary = json.loads(catalog.read_text(encoding="utf-8"))
            if is_v10:
                CURRENT_PHASE = "catalog_semantics_gate"
                catalog_metrics = validate_v10_catalog_semantics(catalog_summary)
                emit_log(log, "catalog_semantics_verified", **catalog_metrics)
            cluster_started_here = start_cluster(log, run_dir)
            CURRENT_PHASE = "target_create"
            emit_log(log, "phase_started", phase=CURRENT_PHASE)
            create_target_database(args.database)
            TARGET_CREATED_BY_RUNNER = True
            emit_log(log, "empty_target_created", database=args.database)
            emit_log(log, "step_complete", phase=CURRENT_PHASE, exit_code=0)
            CURRENT_PHASE = "schema"
            apply_sql(args.database, ddl_dir / "001_schema.sql", "schema", log)
            if is_v10:
                apply_sql(args.database, ddl_dir / "002_constraints_indexes.sql", "constraints_indexes", log)
            run_step([*base, str(CODE_ROOT / "loader.py"), "--mysql-config", str(mysql_config),
                      "--pg-socket", str(PGSOCKET), "--pg-port", str(PGPORT), "--database", args.database,
                      "--repo-root", str(REPO_ROOT), "--evidence", str(run_dir / "loader_summary.json"),
                      "--batch-size", str(args.batch_size)], "load", log, whitelist_json=True)
            loader_summary = json.loads((run_dir / "loader_summary.json").read_text(encoding="utf-8"))
            if (loader_summary.get("tables_loaded") != 270
                    or loader_summary.get("zero_date_conversions") != catalog_summary.get("zero_date_counts")
                    or loader_summary.get("dst_ambiguous_local_times")):
                raise RuntimeError("loader transformations differ from metadata preflight or require review")
            if sum(item.get("source_rows", 0) for item in loader_summary.get("table_metrics", [])) != loader_summary.get("source_rows_loaded"):
                raise RuntimeError("loader row totals are internally inconsistent")
            emit_log(log, "loader_metrics_verified", tables=270,
                     rows=loader_summary.get("source_rows_loaded"),
                     zero_date_columns=len(loader_summary.get("zero_date_conversions", {})))
            if not is_v10:
                apply_sql(args.database, ddl_dir / "002_constraints_indexes.sql", "constraints_indexes", log)
                apply_sql(args.database, ddl_dir / "003_identity_sequences.sql", "identity_sequences", log)
            apply_sql(args.database, ddl_dir / "004_on_update_timestamp.sql", "on_update_triggers", log)
            apply_sql(args.database, ddl_dir / "005_business_triggers_routines.sql", "business_objects", log)
            apply_sql(args.database, ddl_dir / "006_grants.sql", "grants", log)
        if not args.resume_post_fk_reconciliation:
            harness = CODE_ROOT.parents[1] / "tests" / "migracao_pg" / "trigger_harness.py"
            run_step([*base, str(harness),
                      "--mysql-config", str(mysql_config), "--repo-root", str(REPO_ROOT),
                      "--pg-socket", str(PGSOCKET), "--pg-port", str(PGPORT), "--database", args.database],
                     "business_trigger_tests", log, whitelist_json=True)
            apply_sql(args.database, ddl_dir / "002_foreign_keys.sql", "foreign_keys_not_valid", log)
            if is_v10:
                apply_sql(args.database, ddl_dir / "003_identity_sequences.sql", "identity_sequences", log)
            run_step(as_postgres("/usr/bin/psql", "-X", "-q", "-v", "ON_ERROR_STOP=1", "-h", PGSOCKET,
                      "-p", str(PGPORT), "-U", "postgres", "-d", args.database, "-c", "ANALYZE;"),
                     "analyze", log)
        run_reconciliation_step(base, args.database, mysql_config, run_dir, log)
        summary_path = run_dir / "reconciliation_summary.json"
        summary = json.loads(summary_path.read_text(encoding="utf-8"))
        accepted_totals = None
        if is_v10:
            CURRENT_PHASE = "acceptance_gate"
            csv_path = run_dir / "reconciliacao_v10b.csv"
            accepted_totals = verify_reconciliation_acceptance(
                summary, csv_path, set(catalog_summary["table_names"]),
                set(catalog_summary["legacy_tables"]))
            if (summary.get("fk_type_normalizations") != catalog_summary.get("fk_type_normalizations")
                    or summary.get("fk_type_normalizations_sha256") != catalog_summary.get("fk_type_normalizations_sha256")):
                raise RuntimeError("reconciliation foreign key type manifest differs from the catalog")
            emit_log(log, "table_level_reconciliation_verified", **accepted_totals)
            if not TARGET_CREATED_BY_RUNNER:
                raise RuntimeError("v10 target was not created by this clean runner execution")
            validate_v10_execution_history(read_v10_execution_log(log))
        elif (summary.get("tables") != 270
              or summary.get("source_rows") != loader_summary.get("source_rows_loaded")
              or summary.get("destination_rows") != loader_summary.get("source_rows_loaded")
              or summary.get("foreign_keys_valid", 0) + summary.get("foreign_keys_with_orphans", 0) != 11
              or summary.get("fk_type_normalizations") != catalog_summary.get("fk_type_normalizations")
              or summary.get("fk_type_normalizations_sha256") != catalog_summary.get("fk_type_normalizations_sha256")
              or any(summary.get(k) != 0 for k in (
                  "row_count_mismatches", "tables_with_row_hash_divergence", "pk_max_mismatches",
                  "identity_max_mismatches", "sequences_not_ready", "numeric_sum_mismatches",
                  "binary_mismatches", "tenant_bucket_mismatches", "timestamp_mismatches",
                  "fk_tenant_mismatch_count"))):
            raise RuntimeError("reconciliation summary did not meet v9 acceptance")
        if cluster_started_here:
            stop_rehearsal_cluster(log, "pipeline_success")
            cluster_started_here = False
        cleanup_tmpfs_run(tmpfs_run, postgres.pw_gid)
        if is_v10:
            cleanup_tmpfs_root(active_tmpfs_root, postgres.pw_gid)
            validate_v10_execution_history(read_v10_execution_log(log), require_cluster_stopped=True)
        elapsed = round(time.monotonic() - started, 3)
        status = summary.get("status", "ACCEPTED")
        if is_v10:
            report_path = write_v10_conversion_report(run_dir, run_id, args.expected_commit,
                                                      source_sha256, source_size_bytes,
                                                      catalog_summary, summary, accepted_totals, elapsed)
            emit_log(log, "sanitized_report_written", report="v10b_conversion_report.json",
                     table_metrics="reconciliacao_v10b.csv", mode="0600")
        emit_log(log, "pipeline_accepted", database=args.database, tables=270,
                 rows=summary.get("source_rows"), status=status,
                 foreign_keys_valid=summary.get("foreign_keys_valid"),
                 foreign_keys_with_orphans=summary.get("foreign_keys_with_orphans"),
                 fk_orphan_count_total=summary.get("fk_orphan_count_total"), elapsed_seconds=elapsed,
                 source_hash_verified=bool(source_sha256), source_size_bytes=source_size_bytes)
        print(json.dumps({"status": status, "database": args.database,
                          "tables": 270, "rows": summary.get("source_rows"),
                          "foreign_keys_valid": summary.get("foreign_keys_valid"),
                          "foreign_keys_with_orphans": summary.get("foreign_keys_with_orphans"),
                          "fk_orphan_count_total": summary.get("fk_orphan_count_total"),
                          "source_hash_verified": bool(source_sha256), "source_size_bytes": source_size_bytes,
                          "identity_columns": summary.get("identity_columns"),
                          "report": str(report_path) if is_v10 else None,
                          "table_metrics": str(run_dir / "reconciliacao_v10b.csv") if is_v10 else None,
                          "elapsed_seconds": elapsed,
                          "run_dir": str(run_dir), "log": str(log)}, ensure_ascii=False), flush=True)
    except Exception as exc:
        stop_error = None
        if cluster_started_here or CLUSTER_STARTED_BY_RUNNER:
            try:
                stop_rehearsal_cluster(log, "pipeline_failure")
                cluster_started_here = False
            except Exception as stop_exc:
                stop_error = type(stop_exc).__name__
        cleanup_error = None
        try:
            cleanup_tmpfs_run(tmpfs_run, postgres.pw_gid)
        except Exception as cleanup_exc:
            cleanup_error = type(cleanup_exc).__name__
        emit_log(log, "pipeline_blocked", error_type=type(exc).__name__,
                 phase=CURRENT_PHASE, error_code="runner_exception",
                 stop_error=stop_error, cleanup_error=cleanup_error,
                 elapsed_seconds=round(time.monotonic() - started, 3),
                 target_database=args.database, target_created=TARGET_CREATED_BY_RUNNER)
        print(f"PIPELINE_BLOCKED phase={CURRENT_PHASE} code=runner_exception "
              f"target_created={str(TARGET_CREATED_BY_RUNNER).lower()}", file=sys.stderr, flush=True)
        if stop_error or cleanup_error:
            print("PIPELINE_CLEANUP_INCOMPLETE see private run log", file=sys.stderr, flush=True)
        raise SystemExit(1)
    finally:
        if cluster_started_here or CLUSTER_STARTED_BY_RUNNER:
            try:
                stop_rehearsal_cluster(log, "runner_finally")
                cluster_started_here = False
            except Exception as stop_exc:
                emit_log(log, "cluster_stop_failed_finally", error_type=type(stop_exc).__name__)
        try:
            cleanup_tmpfs_run(tmpfs_run, postgres.pw_gid)
        except Exception as cleanup_exc:
            emit_log(log, "credential_tmpfs_cleanup_failed_finally", error_type=type(cleanup_exc).__name__)
        if is_v10:
            try:
                cleanup_tmpfs_root(active_tmpfs_root, postgres.pw_gid)
            except Exception as cleanup_exc:
                emit_log(log, "credential_tmpfs_root_cleanup_failed_finally", error_type=type(cleanup_exc).__name__)


if __name__ == "__main__":
    main()
