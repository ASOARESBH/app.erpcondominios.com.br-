from __future__ import annotations

import argparse
import atexit
import fcntl
import hashlib
import json
import os
import re
import selectors
import signal
import stat
import subprocess
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

sys.dont_write_bytecode = True

from common import (generated_name, V10E_DATABASE, V10E_PGDATA, V10E_PGSOCKET,
                    V10E_PGPORT, open_directory_chain, open_file_from_directory)

V10E_ROOT = Path("/var/lib/erp-pg-rehearsal/v10e")
V10E_SECRETS = V10E_ROOT / "secrets"
V10E_CONTAINER_SECRETS = V10E_ROOT / "mysql-secrets"
V10E_SOURCE_MANIFEST = V10E_SECRETS / "source-manifest.json"
V10E_DUMP = V10E_ROOT / "source-copy.sql.gz"
V10E_MYSQL_DATA = V10E_ROOT / "mysql-data"
V10E_MYSQL_SOCKET = V10E_ROOT / "mysql-socket"
PGDATA = Path(V10E_PGDATA)
PGSOCKET = Path(V10E_PGSOCKET)
PGPORT = V10E_PGPORT
PG_PYTHON = V10E_ROOT / "venv/bin/python3"
MYSQL_CONTAINER = "erp-mysql-source-v10e"
MYSQL_SECRET_SOURCE = V10E_SECRETS / "pgloader.cnf"
MYSQL_ADMIN_CONFIG = V10E_CONTAINER_SECRETS / "client.cnf"
REPO_ROOT = Path("/opt/erpcondominios/source")
BASELINE_MAIN_COMMIT = "f60739713e6de3f2473b79ecd14a70fd700e5462"
APPROVED_C2_COMMIT = "c4703e9e153fd2d156f8c9dab398c12d72dd19cf"
APPROVED_RESUME_RUN_ID = "20261108T181755Z"
APPROVED_RESUME_SOURCE_COMMIT = "95fbf8a458cf86d2fe2d70679ba613526ca1b1de"
APPROVED_RESUME_SOURCE_ROWS = 202609
APPROVED_POST_FK_RUN_ID = "20261108T203651Z"
APPROVED_POST_FK_SOURCE_COMMIT = "d4447f9867cda910122f9b3b9e4d797b425cf5a4"
APPROVED_POST_FK_MANIFEST = Path("/root/erp_pg_v9_post_fk_20261108_manifest.json")
RUN_ROOT = V10E_ROOT / "runs"
PREPARATION_ROOT = V10E_ROOT / "preparations"
PREPARATION_LOCK = PREPARATION_ROOT / ".source-provision.lock"
TMPFS_ROOT = Path("/dev/shm/erp-pg-v10e")
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
            return subprocess.run(["git", "-C", str(path), *args], capture_output=True, text=True, timeout=30)
        origin, branch, head, status = (git("remote", "get-url", "origin"), git("branch", "--show-current"),
                                        git("rev-parse", "HEAD"), git("status", "--porcelain", "--untracked-files=all"))
        return (origin.returncode == 0 and origin.stdout.strip() == "https://github.com/ASOARESBH/app.erpcondominios.com.br-.git"
                and branch.returncode == 0 and branch.stdout.strip() == branch_name
                and head.returncode == 0 and head.stdout.strip() == commit
                and status.returncode == 0 and not status.stdout.strip())
    if not verify_repo(repository, "pg/03-4-fix-mysql-source-provisioning", expected_commit):
        raise RuntimeError("v10e code provenance is not the reviewed clean provisioning branch and commit")
    verify_approved_c2_ancestry(repository, expected_commit)
    if not verify_repo(REPO_ROOT, "main", BASELINE_MAIN_COMMIT):
        raise RuntimeError("source classification repository is not the approved clean main baseline")


def verify_baseline_ancestry(repository: Path, commit: str):
    ancestry = subprocess.run(["git", "-C", str(repository), "merge-base", "--is-ancestor",
                               BASELINE_MAIN_COMMIT, commit], capture_output=True, text=True, timeout=30)
    if ancestry.returncode != 0:
        raise RuntimeError("migration code commit is not descended from the approved baseline")


def verify_approved_c2_ancestry(repository: Path, commit: str):
    ancestry = subprocess.run(["git", "-C", str(repository), "merge-base", "--is-ancestor",
                               APPROVED_C2_COMMIT, commit], capture_output=True, text=True, timeout=30)
    if ancestry.returncode != 0:
        raise RuntimeError("v10e code commit is not descended from the C.2 harness fix")


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


def validate_v10e_source_manifest(manifest: dict, expected_commit: str) -> None:
    required = {
        "target_database", "code_branch", "code_commit", "source_schema", "dump_sha256",
        "dump_bytes", "mysql_version", "container_name", "container_id", "image_name",
        "image_id", "restore_status", "restored_at_utc",
    }
    if (not isinstance(manifest, dict) or set(manifest) != required
            or manifest.get("target_database") != V10E_DATABASE
            or manifest.get("code_branch") != "pg/03-4-fix-mysql-source-provisioning"
            or manifest.get("code_commit") != expected_commit
            or not re.fullmatch(r"[0-9a-f]{40}", str(manifest.get("code_commit", "")))
            or manifest.get("source_schema") != "inlaud99_erpcondor"
            or not re.fullmatch(r"[0-9a-f]{64}", str(manifest.get("dump_sha256", "")))
            or type(manifest.get("dump_bytes")) is not int or manifest["dump_bytes"] <= 0
            or not str(manifest.get("mysql_version", "")).startswith("5.7.")
            or manifest.get("container_name") != MYSQL_CONTAINER
            or not re.fullmatch(r"[0-9a-f]{64}", str(manifest.get("container_id", "")))
            or manifest.get("image_name") != "docker.io/library/mysql:5.7"
            or not re.fullmatch(r"sha256:[0-9a-f]{64}", str(manifest.get("image_id", "")))
            or manifest.get("restore_status") != "complete"):
        raise RuntimeError("v10e source manifest differs from the reviewed target contract")
    if not isinstance(manifest.get("restored_at_utc"), str) or not re.fullmatch(
            r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", manifest["restored_at_utc"]):
        raise RuntimeError("v10e source manifest timestamp is invalid")


def load_v10e_source_manifest(path: Path, expected_commit: str, expected_uid: int = 0) -> dict:
    fd = None
    try:
        fd = open_file_from_directory(path, expected_parent_uid=expected_uid, expected_parent_mode=0o700)
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != expected_uid or info.st_nlink != 1
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_size > 4096):
            raise RuntimeError("v10e source manifest has unsafe ownership or permissions")
        with os.fdopen(fd, "r", encoding="utf-8") as stream:
            fd = None
            manifest = json.load(stream)
    except (OSError, UnicodeError, json.JSONDecodeError):
        raise RuntimeError("v10e source manifest is unavailable or malformed") from None
    finally:
        if fd is not None:
            os.close(fd)
    validate_v10e_source_manifest(manifest, expected_commit)
    return manifest


def verify_v10e_container_secret_bundle(expected_uid: int = 0):
    directory_fd = None
    try:
        directory_fd = open_directory_chain(V10E_CONTAINER_SECRETS)
        directory = os.fstat(directory_fd)
        with os.scandir(directory_fd) as stream:
            names = {entry.name for entry in stream}
        if (not stat.S_ISDIR(directory.st_mode) or stat.S_ISLNK(directory.st_mode)
                or directory.st_uid != expected_uid or stat.S_IMODE(directory.st_mode) != 0o700
                or names != {"client.cnf", "root.pass"}):
            raise RuntimeError("v10e MySQL container secret bundle is unsafe")
        for name in names:
            item_fd = os.open(name, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0), dir_fd=directory_fd)
            try:
                item = os.fstat(item_fd)
                if (not stat.S_ISREG(item.st_mode) or item.st_uid != expected_uid or item.st_nlink != 1
                        or stat.S_IMODE(item.st_mode) != 0o400 or item.st_size > 4096):
                    raise RuntimeError("v10e MySQL container secret bundle is unsafe")
            finally:
                os.close(item_fd)
    except OSError:
        raise RuntimeError("v10e MySQL container secret bundle is unavailable") from None
    finally:
        if directory_fd is not None:
            os.close(directory_fd)


def verify_v10e_source_artifacts(expected_commit: str, expected_uid: int = 0,
                                  expected_root_uid: int | None = None) -> dict:
    manifest = load_v10e_source_manifest(V10E_SOURCE_MANIFEST, expected_commit, expected_uid)
    if expected_root_uid is None:
        expected_root_uid = __import__("pwd").getpwnam("postgres").pw_uid
    root_fd = open_directory_chain(V10E_ROOT)
    try:
        root_info = os.fstat(root_fd)
        if (not stat.S_ISDIR(root_info.st_mode) or root_info.st_uid != expected_root_uid
                or stat.S_IMODE(root_info.st_mode) != 0o700):
            raise RuntimeError("v10e private root has unsafe ownership or permissions")
    finally:
        os.close(root_fd)
    fd = None
    try:
        fd = open_file_from_directory(V10E_DUMP, expected_parent_uid=expected_root_uid,
                                      expected_parent_mode=0o700)
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != expected_uid
                or info.st_nlink != 1 or stat.S_IMODE(info.st_mode) != 0o400
                or info.st_size != manifest["dump_bytes"]):
            raise RuntimeError("v10e dump copy has unsafe metadata")
        digest = hashlib.sha256()
        with os.fdopen(fd, "rb") as stream:
            fd = None
            for block in iter(lambda: stream.read(1024 * 1024), b""):
                digest.update(block)
    except OSError:
        raise RuntimeError("v10e dump copy is unavailable") from None
    finally:
        if fd is not None:
            os.close(fd)
    if digest.hexdigest() != manifest["dump_sha256"]:
        raise RuntimeError("v10e dump copy differs from its private source manifest")
    verify_v10e_container_secret_bundle(expected_uid)
    result = subprocess.run(["podman", "inspect", MYSQL_CONTAINER], capture_output=True, text=True, timeout=30)
    if result.returncode:
        raise RuntimeError("v10e MySQL clone is unavailable")
    try:
        container = json.loads(result.stdout)[0]
    except (json.JSONDecodeError, IndexError, KeyError, TypeError, AttributeError):
        raise RuntimeError("v10e MySQL clone metadata is unreadable") from None
    if (not isinstance(container, dict) or not isinstance(container.get("HostConfig"), dict)
            or not isinstance(container.get("State"), dict) or not isinstance(container.get("Config"), dict)
            or not isinstance(container.get("Mounts"), list)
            or any(not isinstance(item, dict) for item in container.get("Mounts", []))
            or not isinstance(container.get("Args"), list)
            or any(not isinstance(item, str) for item in container.get("Args", []))
            or not isinstance(container["Config"].get("Env"), list)
            or any(not isinstance(item, str) for item in container["Config"].get("Env", []))):
        raise RuntimeError("v10e MySQL clone metadata is unreadable")
    mounts = container.get("Mounts", [])
    expected_mounts = {
        ("bind", "/var/lib/mysql", str(V10E_MYSQL_DATA), True),
        ("bind", "/var/run/mysqld", str(V10E_MYSQL_SOCKET), True),
        ("bind", "/run/rehearsal-secrets", str(V10E_CONTAINER_SECRETS), False),
    }
    actual_mounts = {(item.get("Type"), item.get("Destination"), item.get("Source"), item.get("RW"))
                     for item in mounts}
    host = container.get("HostConfig", {})
    state = container.get("State", {})
    config_env = set((container.get("Config") or {}).get("Env") or [])
    isolation_ok = (
        container.get("Name") == f"/{MYSQL_CONTAINER}"
        and container.get("Id") == manifest["container_id"]
        and container.get("Image") == manifest["image_id"]
        and container.get("ImageName") == manifest["image_name"]
        and state.get("Running") is True
        and int(state.get("Pid") or 0) > 1
        and Path(f"/proc/{int(state.get('Pid') or 0)}/ns/net").exists()
        and container.get("AppArmorProfile") == "erp-mysql-rehearsal"
        and host.get("NetworkMode") == "none"
        and host.get("PortBindings") in (None, {})
        and host.get("Privileged") is False
        and host.get("CapAdd") in (None, [])
        and "no-new-privileges" in (host.get("SecurityOpt") or [])
        and "apparmor=erp-mysql-rehearsal" in (host.get("SecurityOpt") or [])
        and actual_mounts == expected_mounts
        and "--bind-address=127.0.0.1" in (container.get("Args") or [])
        and "--event-scheduler=OFF" in (container.get("Args") or [])
        and "--local-infile=0" in (container.get("Args") or [])
        and "--secure-file-priv=NULL" in (container.get("Args") or [])
        and "--default-time-zone=-03:00" in (container.get("Args") or [])
        and "--innodb-buffer-pool-size=512M" in (container.get("Args") or [])
        and "--max-allowed-packet=256M" in (container.get("Args") or [])
        and "MYSQL_DATABASE=inlaud99_erpcondor" in config_env
        and "MYSQL_ROOT_PASSWORD_FILE=/run/rehearsal-secrets/root.pass" in config_env
        and "MYSQL_INITDB_SKIP_TZINFO=1" in config_env
    )
    if not isolation_ok:
        raise RuntimeError("v10e MySQL source isolation differs from its manifest")
    return manifest


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
    expected = load_v10e_code_json(CODE_ROOT / "expected_routines.json")
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


def acquire_runner_lock(path: Path | None = None):
    global RUN_LOCK_FD
    path = path or RUN_ROOT / ".runner.lock"
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
        raise RuntimeError("another v10e runner is active")
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


class StepTimeout(Exception):
    pass


def iter_process_lines(proc, deadline: float):
    selector = selectors.DefaultSelector()
    buffer = bytearray()
    selector.register(proc.stdout, selectors.EVENT_READ)
    try:
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise StepTimeout
            if not selector.select(min(remaining, 0.25)):
                continue
            chunk = os.read(proc.stdout.fileno(), 65536)
            if not chunk:
                if buffer:
                    yield buffer.decode("utf-8", errors="replace")
                return
            buffer.extend(chunk)
            newline = buffer.find(b"\n")
            if (newline > 4 * 1024 * 1024 or
                    (newline < 0 and len(buffer) > 4 * 1024 * 1024)):
                raise RuntimeError("subprocess output line exceeded its safety limit")
            while True:
                position = buffer.find(b"\n")
                if position < 0:
                    break
                line = bytes(buffer[:position])
                del buffer[:position + 1]
                yield line.decode("utf-8", errors="replace")
    finally:
        selector.close()


def terminate_process_group(proc):
    try:
        os.killpg(proc.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    deadline = time.monotonic() + 2
    try:
        proc.wait(timeout=max(0, deadline - time.monotonic()))
    except subprocess.TimeoutExpired:
        pass
    while time.monotonic() < deadline:
        try:
            os.killpg(proc.pid, 0)
        except ProcessLookupError:
            break
        time.sleep(0.05)
    else:
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
    try:
        proc.wait(timeout=5)
    except subprocess.TimeoutExpired:
        proc.kill()
        proc.wait(timeout=5)


def process_group_exists(pgid: int) -> bool:
    try:
        os.killpg(pgid, 0)
        return True
    except ProcessLookupError:
        return False


def handle_signal(signum, frame):
    raise PipelineSignal(f"signal {signum}")


def run_step(command: list[str], phase: str, log: Path, *, whitelist_json=False, timeout=900):
    global ACTIVE_PROCESS
    if type(timeout) is not int or timeout < 1 or timeout > 3600:
        raise ValueError("phase timeout is outside the approved bound")
    started = time.monotonic()
    print(json.dumps({"event": "phase_started", "phase": phase}), flush=True)
    proc = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
                            bufsize=1, start_new_session=True)
    ACTIVE_PROCESS = proc
    deadline = time.monotonic() + timeout
    error_type = "unknown"
    error_code = "unknown"
    error_stage = "none"
    sqlstate = "none"
    try:
        for raw_line in iter_process_lines(proc, deadline):
            line = raw_line.rstrip("\r\n")
            if line.startswith("MYSQL_SOURCE_FREEZE_EVENT "):
                fields = {}
                for token in line.split()[1:]:
                    if "=" in token:
                        key, value = token.split("=", 1)
                        fields[key] = value
                allowed_steps = {
                    "super_read_only_off_applied", "read_only_off_applied",
                    "read_only_restore_failed", "read_only_restore_unverified",
                    "read_only_restored_verified",
                }
                if (set(fields) == {"step", "target"} and fields.get("target") == "erp_ensaio_v10e"
                        and fields.get("step") in allowed_steps):
                    emit_log(log, "mysql_source_freeze_event", phase=phase,
                             target=fields["target"], step=fields["step"])
                    print(json.dumps({"event": "mysql_source_freeze_event", "phase": phase,
                                      "target": fields["target"], "step": fields["step"]}), flush=True)
                continue
            if not whitelist_json:
                for marker in ("CATALOG_GENERATION_FAILED type=", "LOAD_FAILED type=",
                               "RECONCILIATION_FAILED type=", "TRIGGER_TEST_FAILED type=",
                               "MYSQL_READER_PROVISION_FAILED type="):
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
                               "RECONCILIATION_FAILED type=", "TRIGGER_TEST_FAILED type=",
                               "MYSQL_READER_PROVISION_FAILED type="):
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
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise StepTimeout
        try:
            return_code = proc.wait(timeout=remaining)
        except subprocess.TimeoutExpired:
            raise StepTimeout from None
        if return_code == 0 and process_group_exists(proc.pid):
            terminate_process_group(proc)
            return_code = 125
            error_type = "ChildProcessLeak"
            error_code = "step_child_process"
            error_stage = phase
    except StepTimeout:
        terminate_process_group(proc)
        return_code = 124
        error_type = "TimeoutExpired"
        error_code = "step_timeout"
        error_stage = phase
    except BaseException:
        terminate_process_group(proc)
        raise
    finally:
        close_stdout = getattr(proc.stdout, "close", None)
        if callable(close_stdout):
            close_stdout()
        if ACTIVE_PROCESS is proc:
            ACTIVE_PROCESS = None
    elapsed = round(time.monotonic() - started, 3)
    if return_code:
        emit_log(log, "step_failed", phase=phase, exit_code=return_code,
                 error_type=error_type, error_code=error_code, error_stage=error_stage,
                 sqlstate=sqlstate, elapsed_seconds=elapsed)
        raise RuntimeError(f"phase {phase} failed; see private aggregate log")
    emit_log(log, "step_complete", phase=phase, exit_code=0, elapsed_seconds=elapsed)
    print(json.dumps({"event": "phase_complete", "phase": phase, "elapsed_seconds": elapsed}), flush=True)
    return None


def prepare_v10e_cluster_directories():
    postgres = __import__("pwd").getpwnam("postgres")
    if PGDATA.is_symlink():
        raise RuntimeError("v10e PGDATA cannot be a symlink")
    if not PGDATA.exists():
        initdb = subprocess.run(as_postgres(
            "/usr/lib/postgresql/18/bin/initdb", "-D", PGDATA, "--username=postgres",
            "--encoding=UTF8", "--locale=C.UTF-8", "--auth-local=peer", "--auth-host=reject",
            "--no-instructions"), stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if initdb.returncode:
            raise RuntimeError("isolated v10e PostgreSQL initdb failed")
    data = PGDATA.lstat()
    if (not stat.S_ISDIR(data.st_mode) or data.st_uid != postgres.pw_uid
            or stat.S_IMODE(data.st_mode) & 0o077):
        raise RuntimeError("v10e PGDATA has unsafe ownership or mode")
    config = PGDATA / "postgresql.conf"
    config_info = config.lstat()
    if (not stat.S_ISREG(config_info.st_mode) or stat.S_ISLNK(config_info.st_mode)
            or config_info.st_uid != postgres.pw_uid or stat.S_IMODE(config_info.st_mode) & 0o077):
        raise RuntimeError("v10e PostgreSQL configuration has unsafe ownership or mode")
    configured = {}
    for line in config.read_text(encoding="utf-8").splitlines():
        if not line.lstrip().startswith("#") and "=" in line:
            key, value = line.split("=", 1)
            configured[key.strip()] = value.split("#", 1)[0].strip()
    expected_config = {
        "listen_addresses": "''", "port": str(PGPORT),
        "unix_socket_directories": f"'{PGSOCKET}'", "timezone": "'America/Sao_Paulo'",
    }
    missing = []
    for setting, value in expected_config.items():
        if setting not in configured:
            missing.append(f"{setting} = {value}")
        elif configured[setting] != value:
            raise RuntimeError("v10e PostgreSQL configuration has a conflicting active setting")
    if missing:
        with config.open("a", encoding="utf-8") as stream:
            stream.write("\n" + "\n".join(missing) + "\n")
    os.chown(config, postgres.pw_uid, postgres.pw_gid)
    os.chmod(config, 0o600)
    if PGSOCKET.is_symlink():
        raise RuntimeError("v10e socket directory cannot be a symlink")
    if not PGSOCKET.exists():
        PGSOCKET.mkdir(mode=0o700)
        os.chown(PGSOCKET, postgres.pw_uid, postgres.pw_gid)
    socket_dir = PGSOCKET.lstat()
    if (not stat.S_ISDIR(socket_dir.st_mode) or socket_dir.st_uid != postgres.pw_uid
            or stat.S_IMODE(socket_dir.st_mode) != 0o700):
        raise RuntimeError("v10e PostgreSQL socket directory has unsafe ownership or mode")
    status = subprocess.run(as_postgres("/usr/lib/postgresql/18/bin/pg_ctl", "-D", PGDATA, "status"),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if status.returncode == 0 or PGDATA.joinpath("postmaster.pid").exists():
        raise RuntimeError("v10e PostgreSQL cluster must remain stopped during source preparation")


def start_cluster(log: Path, run_dir: Path):
    global CLUSTER_STARTED_BY_RUNNER
    prepare_v10e_cluster_directories()
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


def check_mysql_namespace(source_manifest: dict):
    check = subprocess.run(["podman", "inspect", MYSQL_CONTAINER], stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE, text=True, timeout=30)
    if check.returncode:
        raise RuntimeError("isolated MySQL source container is unavailable")
    try:
        info = json.loads(check.stdout)[0]
    except (json.JSONDecodeError, IndexError, KeyError, TypeError, AttributeError):
        raise RuntimeError("isolated MySQL source container metadata is unreadable")
    if (not isinstance(info, dict) or not isinstance(info.get("HostConfig"), dict)
            or not isinstance(info.get("State"), dict) or not isinstance(info.get("Mounts"), list)
            or any(not isinstance(item, dict) for item in info.get("Mounts", []))
            or not isinstance(info.get("Args"), list)
            or any(not isinstance(item, str) for item in info.get("Args", []))):
        raise RuntimeError("isolated MySQL source container metadata is unreadable")
    host = info.get("HostConfig", {})
    state = info.get("State", {})
    args = info.get("Args", [])
    security_opts = host.get("SecurityOpt") or []
    expected_mounts = {
        ("bind", "/var/lib/mysql", str(V10E_MYSQL_DATA), True),
        ("bind", "/var/run/mysqld", str(V10E_MYSQL_SOCKET), True),
        ("bind", "/run/rehearsal-secrets", str(V10E_CONTAINER_SECRETS), False),
    }
    actual_mounts = {(mount.get("Type"), mount.get("Destination"), mount.get("Source"), mount.get("RW"))
                     for mount in info.get("Mounts", [])}
    if (info.get("Name") != f"/{MYSQL_CONTAINER}"
            or info.get("Id") != source_manifest.get("container_id")
            or info.get("ImageName") != "docker.io/library/mysql:5.7" or not state.get("Running")
            or host.get("NetworkMode") != "none" or host.get("PortBindings") not in (None, {})
            or host.get("Privileged") is not False or host.get("CapAdd") not in (None, [])
            or "no-new-privileges" not in security_opts
            or "apparmor=erp-mysql-rehearsal" not in security_opts
            or info.get("AppArmorProfile") != "erp-mysql-rehearsal"
            or actual_mounts != expected_mounts):
        raise RuntimeError("MySQL source isolation guard failed")
    if ("--bind-address=127.0.0.1" not in args or "--event-scheduler=OFF" not in args
            or "--local-infile=0" not in args or "--secure-file-priv=NULL" not in args
            or "--default-time-zone=-03:00" not in args
            or "--innodb-buffer-pool-size=512M" not in args
            or "--max-allowed-packet=256M" not in args):
        raise RuntimeError("MySQL source loopback or event-scheduler guard failed")
    pid = int(state.get("Pid") or 0)
    if pid <= 1 or not Path(f"/proc/{pid}/ns/net").exists():
        raise RuntimeError("MySQL network namespace is unavailable")
    return pid


def create_target_database(dbname: str):
    if dbname != V10E_DATABASE:
        raise RuntimeError("refusing any database creation except the exclusive v10e target")
    check = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-At", "-h", PGSOCKET,
                            "-p", str(PGPORT), "-U", "postgres", "-d", "postgres", "-c",
                            f"SELECT COUNT(*) FROM pg_database WHERE datname={quote_pg_literal(V10E_DATABASE)} "
                            "OR (NOT datistemplate AND datname<>'postgres')"),
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if check.returncode or check.stdout.strip() != "0":
        raise RuntimeError("v10e cluster contains a user database or target already exists; refusing reuse")
    create = subprocess.run(as_postgres("/usr/bin/createdb", "-h", PGSOCKET,
                             "-p", str(PGPORT), "-U", "postgres", "-T", "template0", "-E", "UTF8", dbname),
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if create.returncode:
        raise RuntimeError("failed to create new isolated target database")
    alter = subprocess.run(as_postgres("/usr/bin/psql", "-X", "-q", "-v", "ON_ERROR_STOP=1",
                            "-h", PGSOCKET, "-p", PGPORT, "-U", "postgres", "-d", "postgres", "-c",
                            "ALTER DATABASE erp_ensaio_v10e SET timezone TO 'America/Sao_Paulo'; "
                            "ALTER DATABASE erp_ensaio_v10e CONNECTION LIMIT 1; "
                            "REVOKE CONNECT,TEMPORARY ON DATABASE erp_ensaio_v10e FROM PUBLIC"),
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


def validate_v10e_acceptance(catalog_summary: dict, loader_summary: dict, summary: dict):
    metrics = loader_summary.get("table_metrics", [])
    expected_tables = {"tables": 270, "columns": 3339, "primary_keys": 240,
                       "foreign_keys": 11, "triggers": 7, "routines": 2,
                       "events": 0, "auto_increment_columns": 232}
    if any(catalog_summary.get(key) != value for key, value in expected_tables.items()):
        raise RuntimeError("v10e catalog summary differs from the approved source snapshot")
    if (loader_summary.get("tables_loaded") != 270 or loader_summary.get("source_rows_loaded") != 202609
            or len(metrics) != 270 or len({item.get("table") for item in metrics}) != 270
            or sum(item.get("source_rows", 0) for item in metrics) != 202609
            or loader_summary.get("zero_date_conversions") != catalog_summary.get("zero_date_counts")
            or loader_summary.get("dst_ambiguous_local_times")):
        raise RuntimeError("v10e loader did not meet the exact 270-table and 202609-row contract")
    required_zero = ("row_count_mismatches", "tables_with_row_hash_divergence", "row_hash_divergent_total",
                     "pk_max_mismatches", "identity_max_mismatches", "sequences_not_ready",
                     "numeric_sum_mismatches", "binary_mismatches", "tenant_bucket_mismatches",
                     "timestamp_mismatches", "fk_tenant_mismatch_count", "foreign_keys_with_orphans",
                     "fk_orphan_count_total")
    if (summary.get("status") != "ACCEPTED" or summary.get("tables") != 270
            or summary.get("columns") != 3339 or summary.get("tables_with_primary_key") != 240
            or summary.get("source_rows") != 202609 or summary.get("destination_rows") != 202609
            or summary.get("identity_columns") != 232 or summary.get("foreign_keys") != 11
            or summary.get("foreign_keys_valid") != 11
            or summary.get("fk_type_normalizations") != catalog_summary.get("fk_type_normalizations")
            or summary.get("fk_type_normalizations_sha256") != catalog_summary.get("fk_type_normalizations_sha256")
            or any(summary.get(key) != 0 for key in required_zero)):
        raise RuntimeError("v10e reconciliation did not meet the exact zero-divergence acceptance contract")


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
    if dbname != V10E_DATABASE:
        raise RuntimeError("refusing SQL application to any database except the exclusive v10e target")
    command = as_postgres("/usr/bin/psql", "-X", "-q", "-v", "ON_ERROR_STOP=1", "-v", "VERBOSITY=verbose",
                          "-h", PGSOCKET, "-p", str(PGPORT), "-U", "postgres", "-d", dbname)
    if phase == "foreign_keys_not_valid":
        command.append("-1")
    command.extend(("-f", path))
    run_step(command, phase, log)


def run_reconciliation_step(base, dbname: str, mysql_config: Path, run_dir: Path, log: Path):
    if dbname != V10E_DATABASE:
        raise RuntimeError("refusing reconciliation of any database except the exclusive v10e target")
    return run_step([*base, str(CODE_ROOT / "reconcile.py"), "--mysql-config", str(mysql_config),
                     "--pg-socket", str(PGSOCKET), "--pg-port", str(PGPORT), "--database", dbname,
                     "--repo-root", str(REPO_ROOT), "--csv", str(run_dir / "reconciliacao_v10e.csv"),
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


def verify_v10e_toolchain():
    version = subprocess.run(["/usr/lib/postgresql/18/bin/postgres", "--version"],
                             capture_output=True, text=True, timeout=30)
    if version.returncode or not re.search(r"\b18\.6\b", version.stdout):
        raise RuntimeError("PostgreSQL 18.6 runtime is unavailable")
    python = subprocess.run([
        str(PG_PYTHON), "-B", "-c",
        "import importlib.metadata,json,psycopg; print(json.dumps({"
        "'pymysql':importlib.metadata.version('PyMySQL'),'psycopg':psycopg.__version__}))",
    ], capture_output=True, text=True, timeout=30)
    if python.returncode:
        raise RuntimeError("v10e Python runtime is unavailable")
    try:
        versions = json.loads(python.stdout)
    except json.JSONDecodeError:
        raise RuntimeError("v10e Python package metadata is invalid") from None
    if versions != {"pymysql": "1.2.3", "psycopg": "3.3.8"}:
        raise RuntimeError("v10e Python dependency versions differ from the reviewed pins")
    return {"postgresql": "18.6", **versions}


def validate_v10e_catalog_preflight(summary: dict, output_dir: Path, expected_uid: int = 0) -> None:
    expected = {
        "mysql_schema": "inlaud99_erpcondor", "tables": 270, "views": 0, "columns": 3339,
        "primary_keys": 240, "foreign_keys": 11, "triggers": 7, "routines": 2,
        "events": 0, "auto_increment_columns": 232,
    }
    if any(summary.get(key) != value for key, value in expected.items()):
        raise RuntimeError("v10e read-only catalog differs from the reviewed source snapshot")
    expected_files = {
        "001_schema.sql", "002_constraints_indexes.sql", "002_foreign_keys.sql",
        "002_validate_constraints.sql", "003_identity_sequences.sql",
        "004_on_update_timestamp.sql", "005_business_triggers_routines.sql", "006_grants.sql",
    }
    hashes = summary.get("ddl_sha256")
    if (not isinstance(hashes, dict) or set(hashes) != expected_files
            or any(not isinstance(value, str) or not re.fullmatch(r"[0-9a-f]{64}", value)
                   for value in hashes.values())):
        raise RuntimeError("v10e catalog SQL artifact set differs from the reviewed output contract")
    directory_fd = None
    try:
        directory_fd = open_directory_chain(output_dir)
        output_info = os.fstat(directory_fd)
        with os.scandir(directory_fd) as stream:
            names = {entry.name for entry in stream}
        if (not stat.S_ISDIR(output_info.st_mode) or output_info.st_uid != expected_uid
                or stat.S_IMODE(output_info.st_mode) != 0o700 or names != expected_files):
            raise RuntimeError("v10e catalog SQL artifact set or directory permissions are unsafe")
        for name in names:
            fd = None
            try:
                fd = os.open(name, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0), dir_fd=directory_fd)
                info = os.fstat(fd)
                if (not stat.S_ISREG(info.st_mode) or info.st_uid != expected_uid or info.st_nlink != 1
                        or stat.S_IMODE(info.st_mode) != 0o600 or info.st_size > 64 * 1024 * 1024):
                    raise RuntimeError("v10e catalog SQL artifact has unsafe metadata")
                digest = hashlib.sha256()
                with os.fdopen(fd, "rb") as artifact:
                    fd = None
                    for block in iter(lambda: artifact.read(1024 * 1024), b""):
                        digest.update(block)
            except OSError:
                raise RuntimeError("v10e catalog SQL artifact is unavailable or is a symlink") from None
            finally:
                if fd is not None:
                    os.close(fd)
            if digest.hexdigest() != hashes[name]:
                raise RuntimeError("v10e catalog SQL artifact hash or privacy check failed")
    except OSError:
        raise RuntimeError("v10e catalog output directory is unavailable or is a symlink") from None
    finally:
        if directory_fd is not None:
            os.close(directory_fd)


def load_v10e_private_json(path: Path, expected_uid: int = 0, max_bytes: int = 32 * 1024 * 1024) -> dict:
    fd = None
    try:
        fd = open_file_from_directory(path, expected_parent_uid=expected_uid, expected_parent_mode=0o700)
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != expected_uid or info.st_nlink != 1
                or stat.S_IMODE(info.st_mode) != 0o600 or info.st_size > max_bytes):
            raise RuntimeError("v10e private JSON artifact has unsafe metadata")
        with os.fdopen(fd, "rb") as stream:
            fd = None
            payload = stream.read(max_bytes + 1)
        if len(payload) > max_bytes:
            raise RuntimeError("v10e private JSON artifact exceeds its size limit")
        value = json.loads(payload.decode("utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError):
        raise RuntimeError("v10e private JSON artifact is unavailable or malformed") from None
    finally:
        if fd is not None:
            os.close(fd)
    if not isinstance(value, dict):
        raise RuntimeError("v10e private JSON artifact has an invalid shape")
    return value


def load_v10e_code_json(path: Path, expected_uid: int = 0, max_bytes: int = 1024 * 1024) -> dict:
    fd = None
    try:
        fd = open_file_from_directory(path, expected_parent_uid=expected_uid)
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != expected_uid or info.st_nlink != 1
                or stat.S_IMODE(info.st_mode) & 0o022 or info.st_size > max_bytes):
            raise RuntimeError("v10e versioned JSON artifact has unsafe metadata")
        with os.fdopen(fd, "rb") as stream:
            fd = None
            payload = stream.read(max_bytes + 1)
        if len(payload) > max_bytes:
            raise RuntimeError("v10e versioned JSON artifact exceeds its size limit")
        value = json.loads(payload.decode("utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError):
        raise RuntimeError("v10e versioned JSON artifact is unavailable or malformed") from None
    finally:
        if fd is not None:
            os.close(fd)
    if not isinstance(value, dict):
        raise RuntimeError("v10e versioned JSON artifact has an invalid shape")
    return value


def parse_arguments(argv=None):
    ap = argparse.ArgumentParser(description="Prepare and validate only the isolated MySQL v10e source")
    ap.add_argument("--mode", required=True, choices=("prepare-source",))
    ap.add_argument("--database", required=True)
    ap.add_argument("--expected-commit", required=True)
    ap.add_argument("--step-timeout", type=int, default=900)
    args = ap.parse_args(argv)
    if args.database != V10E_DATABASE:
        raise SystemExit("refusing any destination except the exclusive v10e rehearsal target")
    if args.step_timeout < 1 or args.step_timeout > 3600:
        raise SystemExit("phase timeout must be an integer between 1 and 3600 seconds")
    return args


def main():
    args = parse_arguments()
    if os.geteuid() != 0:
        raise SystemExit("source preparation must start as root")
    signal.signal(signal.SIGTERM, handle_signal)
    signal.signal(signal.SIGINT, handle_signal)
    os.umask(0o077)
    verify_code_provenance(args.expected_commit)
    source_manifest = verify_v10e_source_artifacts(args.expected_commit)
    versions = verify_v10e_toolchain()
    verify_private_mysql_config(MYSQL_SECRET_SOURCE)
    verify_private_mysql_config(MYSQL_ADMIN_CONFIG)
    if not REPO_ROOT.is_dir() or not PG_PYTHON.is_file():
        raise SystemExit("official source clone or private v10e runtime is unavailable")
    postgres = __import__("pwd").getpwnam("postgres")
    root_stat = V10E_ROOT.lstat()
    if (not stat.S_ISDIR(root_stat.st_mode) or root_stat.st_uid != postgres.pw_uid
            or stat.S_IMODE(root_stat.st_mode) != 0o700):
        raise SystemExit("private v10e root has unsafe ownership or mode")
    if (PGDATA.exists() or PGDATA.is_symlink() or PGSOCKET.exists() or PGSOCKET.is_symlink()
            or RUN_ROOT.exists() or RUN_ROOT.is_symlink()):
        raise SystemExit("v10e PostgreSQL data/socket/run paths already exist; refusing reuse or database creation")
    listener = subprocess.run(["ss", "-H", "-ltn", "sport", "=", ":55434"],
                              capture_output=True, text=True, timeout=10)
    if listener.returncode or listener.stdout.strip():
        raise SystemExit("v10e PostgreSQL port 55434 is unavailable or already listening")
    if PREPARATION_ROOT.exists() or PREPARATION_ROOT.is_symlink():
        raise SystemExit("v10e source preparation root already exists; refusing rerun or resume")
    PREPARATION_ROOT.mkdir(mode=0o700, exist_ok=False)
    if stat.S_IMODE(PREPARATION_ROOT.stat().st_mode) != 0o700 or PREPARATION_ROOT.stat().st_uid != 0:
        raise SystemExit("v10e preparation root has unsafe owner or mode")
    acquire_runner_lock(PREPARATION_LOCK)
    run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    run_dir = PREPARATION_ROOT / ("source_" + run_id)
    run_dir.mkdir(mode=0o700, exist_ok=False)
    log = run_dir / "preparation.jsonl"
    log.touch(mode=0o600, exist_ok=False)
    log.chmod(0o600)
    output_dir = run_dir / "sql"
    started = time.monotonic()
    emit_log(log, "v10e_source_manifest_verified", dump_bytes=source_manifest["dump_bytes"],
             mysql_version=source_manifest["mysql_version"])
    emit_log(log, "v10e_toolchain_verified", **versions)
    emit_log(log, "source_preparation_started", code_commit=args.expected_commit)
    try:
        mysql_pid = check_mysql_namespace(source_manifest)
        mysql_netns_inode = os.stat(f"/proc/{mysql_pid}/ns/net").st_ino
        emit_log(log, "mysql_source_isolated", container=MYSQL_CONTAINER, network_mode="none",
                 read_only_source=True, ports_published=False)
        provision = ["nsenter", f"--net=/proc/{mysql_pid}/ns/net", "--", str(PG_PYTHON), "-B",
                     str(CODE_ROOT / "mysql_source.py"), "--admin-config", str(MYSQL_ADMIN_CONFIG),
                     "--reader-config", str(MYSQL_SECRET_SOURCE), "--expected-commit", args.expected_commit,
                     "--expected-mysql-version", source_manifest["mysql_version"],
                     "--expected-netns-inode", str(mysql_netns_inode)]
        run_step(provision, "mysql_reader_provision", log, timeout=args.step_timeout)
        emit_log(log, "mysql_reader_provisioned", identity_validated=True, grants_minimal=True,
                 read_only_source=True, catalog_tables=270)
        snapshot = read_verified_routine_snapshot()
        routine_snapshot = run_dir / "routine_snapshot.json"
        routine_snapshot.write_text(json.dumps(snapshot, sort_keys=True) + "\n", encoding="utf-8")
        routine_snapshot.chmod(0o600)
        emit_log(log, "routine_fingerprints_verified", count=len(snapshot["routines"]))
        output_dir.mkdir(mode=0o700, exist_ok=False)
        catalog = run_dir / "catalog.json"
        command = ["nsenter", f"--net=/proc/{mysql_pid}/ns/net", "--", str(PG_PYTHON), "-B",
                   str(CODE_ROOT / "catalog.py"), "--mysql-config", str(MYSQL_SECRET_SOURCE),
                   "--output", str(output_dir), "--summary", str(catalog),
                   "--repo-root", str(REPO_ROOT), "--target-database", args.database,
                   "--routine-snapshot", str(routine_snapshot)]
        run_step(command, "read_only_catalog", log, whitelist_json=True, timeout=args.step_timeout)
        catalog_summary = load_v10e_private_json(catalog)
        validate_v10e_catalog_preflight(catalog_summary, output_dir)
        emit_log(log, "v10e_read_only_catalog_verified", tables=270, columns=3339,
                 primary_keys=240, foreign_keys=11, triggers=7, routines=2,
                 auto_increment_columns=232, ddl_files=8)
        emit_log(log, "source_preparation_ready", migration_started=False,
                 target_database_created=False, elapsed_seconds=round(time.monotonic() - started, 3))
        print(json.dumps({"status": "SOURCE_PREPARATION_READY", "mode": args.mode,
                          "database": args.database, "migration_started": False,
                          "target_database_created": False, "tables": 270, "columns": 3339,
                          "foreign_keys": 11, "run_dir": str(run_dir), "log": str(log)},
                         ensure_ascii=False), flush=True)
    except Exception as exc:
        emit_log(log, "source_preparation_blocked", error_type=type(exc).__name__,
                 elapsed_seconds=round(time.monotonic() - started, 3),
                 target_database_created=False)
        print(f"SOURCE_PREPARATION_BLOCKED type={type(exc).__name__}", file=sys.stderr, flush=True)
        raise SystemExit(1)
    finally:
        release_runner_lock()


if __name__ == "__main__":
    main()
