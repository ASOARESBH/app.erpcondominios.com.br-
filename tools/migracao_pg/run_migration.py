from __future__ import annotations

import argparse
import atexit
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
V9_ROOT = Path("/var/lib/erp-pg-rehearsal/v9")
RUN_ROOT = Path("/var/lib/erp-pg-rehearsal/v9/runs")
TMPFS_ROOT = Path("/dev/shm/erp-pg-v9")
CODE_ROOT = Path(__file__).resolve().parent
ACTIVE_PROCESS = None
RUN_LOCK_FD = None
CLUSTER_STARTED_BY_RUNNER = False


def as_postgres(*command):
    return ["runuser", "-u", "postgres", "--", "env", "-i", "PATH=/usr/bin:/bin",
            "HOME=/var/lib/postgresql", "LANG=C.UTF-8", "LC_ALL=C.UTF-8",
            f"PYTHONPATH={CODE_ROOT}", "PYTHONDONTWRITEBYTECODE=1", *map(str, command)]


def verify_code_provenance(expected_commit: str):
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
    if not verify_repo(repository, "pg/03-conversao-schema", expected_commit):
        raise RuntimeError("migration code provenance is not the reviewed clean official commit")
    if not verify_repo(REPO_ROOT, "main", BASELINE_MAIN_COMMIT):
        raise RuntimeError("source classification repository is not the approved clean main baseline")


def verify_baseline_ancestry(repository: Path, commit: str):
    ancestry = subprocess.run(["git", "-C", str(repository), "merge-base", "--is-ancestor",
                               BASELINE_MAIN_COMMIT, commit], capture_output=True, text=True)
    if ancestry.returncode != 0:
        raise RuntimeError("migration code commit is not descended from the approved baseline")


def verify_resume_code_ancestry(repository: Path, commit: str):
    ancestry = subprocess.run(["git", "-C", str(repository), "merge-base", "--is-ancestor",
                               APPROVED_RESUME_SOURCE_COMMIT, commit], capture_output=True, text=True)
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


def prepare_credential_tmpfs(postgres_gid: int):
    mount = subprocess.run(["findmnt", "-n", "-o", "FSTYPE", "--target", "/dev/shm"],
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if mount.returncode or mount.stdout.strip() != "tmpfs" or not Path("/dev/shm").is_mount():
        raise RuntimeError("credential directory is not on a verified tmpfs mount")
    if TMPFS_ROOT.is_symlink():
        raise RuntimeError("credential tmpfs root cannot be a symlink")
    if TMPFS_ROOT.exists():
        st = TMPFS_ROOT.lstat()
        if (not stat.S_ISDIR(st.st_mode) or st.st_uid != 0 or st.st_gid != postgres_gid
                or stat.S_IMODE(st.st_mode) != 0o710):
            raise RuntimeError("credential tmpfs root has unsafe ownership or mode")
    else:
        TMPFS_ROOT.mkdir(mode=0o710)
        os.chown(TMPFS_ROOT, 0, postgres_gid)
        TMPFS_ROOT.chmod(0o710)
    for child in TMPFS_ROOT.iterdir():
        if not re.fullmatch(r"\d{8}T\d{6}Z", child.name):
            raise RuntimeError("unexpected directory in credential tmpfs root")
        cleanup_tmpfs_run(child, postgres_gid)


def release_runner_lock():
    global RUN_LOCK_FD
    if RUN_LOCK_FD is not None:
        fcntl.flock(RUN_LOCK_FD, fcntl.LOCK_UN)
        os.close(RUN_LOCK_FD)
        RUN_LOCK_FD = None


def acquire_runner_lock():
    global RUN_LOCK_FD
    path = RUN_ROOT / ".runner.lock"
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
        raise RuntimeError("another v9 runner is active")
    RUN_LOCK_FD = fd
    atexit.register(release_runner_lock)


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
    global ACTIVE_PROCESS
    started = time.monotonic()
    print(json.dumps({"event": "phase_started", "phase": phase}), flush=True)
    proc = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
                            bufsize=1, start_new_session=True)
    ACTIVE_PROCESS = proc
    error_type = "unknown"
    error_code = "unknown"
    sqlstate = "none"
    try:
        for raw_line in proc.stdout:
            line = raw_line.rstrip("\r\n")
            if not whitelist_json:
                for marker in ("CATALOG_GENERATION_FAILED type=", "LOAD_FAILED type=",
                               "RECONCILIATION_FAILED type=", "TRIGGER_TEST_FAILED type="):
                    if marker in line:
                        error_type = line.split(marker, 1)[1].split()[0]
                code_match = re.search(r"(?:^|\s)code=([a-z0-9_]+)(?:\s|$)", line)
                if code_match:
                    error_code = code_match.group(1)
                if "sqlstate=" in line:
                    sqlstate = line.rsplit("sqlstate=", 1)[1].split()[0]
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
                code_match = re.search(r"(?:^|\s)code=([a-z0-9_]+)(?:\s|$)", line)
                if code_match:
                    error_code = code_match.group(1)
                if "sqlstate=" in line:
                    sqlstate = line.rsplit("sqlstate=", 1)[1].split()[0]
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
                safe = {k: v for k, v in item.items() if isinstance(v, (int, float, bool, str))}
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
                 error_type=error_type, error_code=error_code, sqlstate=sqlstate, elapsed_seconds=elapsed)
        raise RuntimeError(f"phase {phase} failed; see private aggregate log")
    emit_log(log, "step_complete", phase=phase, exit_code=0, elapsed_seconds=elapsed)
    print(json.dumps({"event": "phase_complete", "phase": phase, "elapsed_seconds": elapsed}), flush=True)
    return proc.stdout


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
    return pid


def create_target_database(dbname: str):
    check = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-At", "-h", PGSOCKET,
                            "-p", str(PGPORT), "-U", "postgres", "-d", "postgres", "-c",
                            "SELECT COUNT(*) FROM pg_database WHERE datname='erp_ensaio_v9'"),
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
                            "ALTER DATABASE erp_ensaio_v9 SET timezone TO 'America/Sao_Paulo'; "
                            "ALTER DATABASE erp_ensaio_v9 CONNECTION LIMIT 1; "
                            "REVOKE CONNECT,TEMPORARY ON DATABASE erp_ensaio_v9 FROM PUBLIC"),
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


def quote_pg_ident(value: str) -> str:
    return '"' + value.replace('"', '""') + '"'


def quote_pg_literal(value: str) -> str:
    return "'" + value.replace("'", "''") + "'"


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
        "AND constraint_type='FOREIGN KEY')"
    )
    preflight = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-At", "-h", PGSOCKET,
                               "-p", str(PGPORT), "-U", "postgres", "-d", dbname, "-c", preflight_sql),
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    fields = preflight.stdout.strip().split("|")
    if (preflight.returncode or len(fields) != 4 or fields[:3] != ["America/Sao_Paulo", "UTF8", "270"]
            or not fields[3].isdigit() or int(fields[3]) > 11):
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


def apply_sql(dbname: str, path: Path, phase: str, log: Path):
    command = as_postgres("/usr/bin/psql", "-X", "-q", "-v", "ON_ERROR_STOP=1", "-v", "VERBOSITY=sqlstate",
                          "-h", PGSOCKET, "-p", str(PGPORT), "-U", "postgres", "-d", dbname)
    if phase == "foreign_keys_not_valid":
        command.append("-1")
    command.extend(("-f", path))
    run_step(command, phase, log)


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
    ap = argparse.ArgumentParser(description="Run a clean, isolated MySQL→PostgreSQL v9 rehearsal")
    ap.add_argument("--database", required=True)
    ap.add_argument("--expected-commit", required=True)
    ap.add_argument("--batch-size", type=int, default=1000)
    ap.add_argument("--resume-partial-target", action="store_true")
    args = ap.parse_args()
    if args.database != "erp_ensaio_v9":
        raise SystemExit("refusing any destination except the approved empty rehearsal database erp_ensaio_v9")
    if args.batch_size < 1 or args.batch_size > 10000:
        raise SystemExit("batch size out of range")
    if args.resume_partial_target and args.batch_size != 1000:
        raise SystemExit("approved partial target can only resume with its original batch size")
    if os.geteuid() != 0:
        raise SystemExit("runner must start as root so it can verify container namespace")
    signal.signal(signal.SIGTERM, handle_signal)
    signal.signal(signal.SIGINT, handle_signal)
    os.umask(0o077)
    verify_code_provenance(args.expected_commit)
    if args.resume_partial_target:
        verify_resume_code_ancestry(CODE_ROOT.parents[1], args.expected_commit)
    verify_private_mysql_config(MYSQL_SECRET_SOURCE)
    verify_private_mysql_config(MYSQL_ADMIN_CONFIG)
    if not REPO_ROOT.is_dir() or not PG_PYTHON.is_file():
        raise SystemExit("official source clone or private run root is unavailable")
    postgres = __import__("pwd").getpwnam("postgres")
    if not V9_ROOT.exists():
        V9_ROOT.mkdir(mode=0o700)
        os.chown(V9_ROOT, postgres.pw_uid, postgres.pw_gid)
    if V9_ROOT.stat().st_uid != postgres.pw_uid or V9_ROOT.stat().st_mode & 0o077:
        raise SystemExit("private v9 root has unsafe ownership or mode")
    if not RUN_ROOT.exists():
        RUN_ROOT.mkdir(mode=0o700)
        os.chown(RUN_ROOT, postgres.pw_uid, postgres.pw_gid)
    if RUN_ROOT.stat().st_uid != postgres.pw_uid or RUN_ROOT.stat().st_mode & 0o077:
        raise SystemExit("private PostgreSQL run root has unsafe ownership or mode")
    acquire_runner_lock()
    run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    log = RUN_ROOT / (run_id + ".jsonl")
    log.touch(mode=0o600, exist_ok=False)
    log.chmod(0o600)
    run_dir = RUN_ROOT / ("run_" + run_id)
    run_dir.mkdir(mode=0o700, exist_ok=False)
    (run_dir / "sql").mkdir(mode=0o700)
    for directory in (run_dir, run_dir / "sql"):
        os.chown(directory, postgres.pw_uid, postgres.pw_gid)
    prepare_credential_tmpfs(postgres.pw_gid)
    tmpfs_run = TMPFS_ROOT / run_id
    tmpfs_run.mkdir(mode=0o710, exist_ok=False)
    os.chown(tmpfs_run, 0, postgres.pw_gid)
    tmpfs_run.chmod(0o710)
    mysql_config = tmpfs_run / "client.cnf"
    cluster_started_here = False
    resume_run_dir = None
    resume_source_snapshot = None
    started = time.monotonic()
    emit_log(log, "pipeline_started", database=args.database, batch_size=args.batch_size,
             mysql_container=MYSQL_CONTAINER, code_commit=args.expected_commit,
             resume_source_run=APPROVED_RESUME_RUN_ID if args.resume_partial_target else None)
    try:
        copy_private_config(MYSQL_SECRET_SOURCE, mysql_config, postgres.pw_gid)
        mysql_pid = check_mysql_namespace()
        emit_log(log, "mysql_source_isolated", container=MYSQL_CONTAINER, network_mode="none",
                 read_only_source=True)
        snapshot = read_verified_routine_snapshot()
        routine_snapshot = run_dir / "routine_snapshot.json"
        routine_snapshot.write_text(json.dumps(snapshot, sort_keys=True) + "\n", encoding="utf-8")
        routine_snapshot.chmod(0o600)
        os.chown(routine_snapshot, postgres.pw_uid, postgres.pw_gid)
        emit_log(log, "routine_fingerprints_verified", count=2)
        base = ["nsenter", f"--net=/proc/{mysql_pid}/ns/net", "--", *as_postgres(PG_PYTHON, "-s")]
        if args.resume_partial_target:
            if args.expected_commit == APPROVED_RESUME_SOURCE_COMMIT:
                raise RuntimeError("resume code must be a new commit descended from the partial-run commit")
            resume_run_dir, _resume_log, catalog_summary, loader_summary, resume_source_snapshot = load_resume_artifacts(
                APPROVED_RESUME_RUN_ID, APPROVED_RESUME_SOURCE_COMMIT, args.batch_size, postgres.pw_uid)
            if snapshot != resume_source_snapshot:
                raise RuntimeError("routine snapshot changed since the approved partial run")
            ddl_dir = resume_run_dir / "sql"
            emit_log(log, "resume_artifacts_verified", source_run=APPROVED_RESUME_RUN_ID,
                     tables=270, rows=loader_summary.get("source_rows_loaded"))
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
            cluster_started_here = start_cluster(log, run_dir)
            create_target_database(args.database)
            emit_log(log, "empty_target_created", database=args.database)
            apply_sql(args.database, ddl_dir / "001_schema.sql", "schema", log)
            run_step([*base, str(CODE_ROOT / "loader.py"), "--mysql-config", str(mysql_config),
                      "--pg-socket", str(PGSOCKET), "--pg-port", str(PGPORT), "--database", args.database,
                      "--repo-root", str(REPO_ROOT), "--evidence", str(run_dir / "loader_summary.json"),
                      "--batch-size", str(args.batch_size)], "load", log, whitelist_json=True)
            catalog_summary = json.loads(catalog.read_text(encoding="utf-8"))
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
            apply_sql(args.database, ddl_dir / "002_constraints_indexes.sql", "constraints_indexes", log)
            apply_sql(args.database, ddl_dir / "003_identity_sequences.sql", "identity_sequences", log)
            apply_sql(args.database, ddl_dir / "004_on_update_timestamp.sql", "on_update_triggers", log)
            apply_sql(args.database, ddl_dir / "005_business_triggers_routines.sql", "business_objects", log)
            apply_sql(args.database, ddl_dir / "006_grants.sql", "grants", log)
        harness = CODE_ROOT.parents[1] / "tests" / "migracao_pg" / "trigger_harness.py"
        run_step([*base, str(harness),
                  "--mysql-config", str(mysql_config), "--repo-root", str(REPO_ROOT),
                  "--pg-socket", str(PGSOCKET), "--pg-port", str(PGPORT), "--database", args.database],
                 "business_trigger_tests", log, whitelist_json=True)
        apply_sql(args.database, ddl_dir / "002_foreign_keys.sql", "foreign_keys_not_valid", log)
        run_step(as_postgres("/usr/bin/psql", "-X", "-q", "-v", "ON_ERROR_STOP=1", "-h", PGSOCKET,
                  "-p", str(PGPORT), "-U", "postgres", "-d", args.database, "-c", "ANALYZE;"),
                 "analyze", log)
        run_step([*base, str(CODE_ROOT / "reconcile.py"), "--mysql-config", str(mysql_config),
                  "--pg-socket", str(PGSOCKET), "--pg-port", str(PGPORT), "--database", args.database,
                  "--repo-root", str(REPO_ROOT), "--csv", str(run_dir / "reconciliacao_v9.csv"),
                  "--summary", str(run_dir / "reconciliation_summary.json")],
                 "reconciliation", log, whitelist_json=True)
        summary_path = run_dir / "reconciliation_summary.json"
        summary = json.loads(summary_path.read_text(encoding="utf-8"))
        if (summary.get("tables") != 270
                or summary.get("source_rows") != loader_summary.get("source_rows_loaded")
                or summary.get("destination_rows") != loader_summary.get("source_rows_loaded")
                or summary.get("foreign_keys_valid", 0) + summary.get("foreign_keys_with_orphans", 0) != 11
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
        elapsed = round(time.monotonic() - started, 3)
        status = summary.get("status", "ACCEPTED")
        emit_log(log, "pipeline_accepted", database=args.database, tables=270,
                 rows=summary.get("source_rows"), status=status,
                 foreign_keys_valid=summary.get("foreign_keys_valid"),
                 foreign_keys_with_orphans=summary.get("foreign_keys_with_orphans"),
                 fk_orphan_count_total=summary.get("fk_orphan_count_total"), elapsed_seconds=elapsed)
        print(json.dumps({"status": status, "database": args.database,
                          "tables": 270, "rows": summary.get("source_rows"),
                          "foreign_keys_valid": summary.get("foreign_keys_valid"),
                          "foreign_keys_with_orphans": summary.get("foreign_keys_with_orphans"),
                          "fk_orphan_count_total": summary.get("fk_orphan_count_total"),
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
                 stop_error=stop_error, cleanup_error=cleanup_error,
                 elapsed_seconds=round(time.monotonic() - started, 3),
                 partial_database=args.database)
        print(f"PIPELINE_BLOCKED type={type(exc).__name__} database={args.database}", file=sys.stderr, flush=True)
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


if __name__ == "__main__":
    main()
