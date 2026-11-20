#!/usr/bin/env python3
"""One-shot, fail-closed final MySQL dump runner for the P5 freeze window."""
from __future__ import annotations

import argparse
import datetime as dt
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import time

from p5_common import (
    P5Error, emit_error, exclusive_lock, read_json_private, root_required,
    validate_approval, validate_no_symlink_components, validate_root_directory,
    validate_root_file,
)

SCHEMA = "erpcondominios_stage_20261114"
SOURCE_HOST = "localhost"
BACKUP_ROOT = Path("/var/backups/erpcondominios/mysql-final")
CONFIG = Path("/etc/erpcondominios/p5/mysql-final.json")
SECRET_FILE = Path("/etc/erpcondominios/secrets/mysql-final.cnf")
APPROVAL = Path("/etc/erpcondominios/p5/mysql-final-approval.json")
CONFIG_EXPECTED = {
    "schema": SCHEMA,
    "host": SOURCE_HOST,
    "backup_root": str(BACKUP_ROOT),
    "secret_file": str(SECRET_FILE),
    "approval_file": str(APPROVAL),
}
MYSQLDUMP = Path("/usr/bin/mysqldump")
GZIP = Path("/usr/bin/gzip")
NICE = Path("/usr/bin/nice")
IONICE = Path("/usr/bin/ionice")
MIN_AVAILABLE_KIB = 1_572_864
HEADER_RE = re.compile(rb"(?m)^-- Server version [^\r\n]{1,80}\r?\n")
CREATE_DB_RE = re.compile(
    rb"(?im)^(?:/\*!\d{5,6}(?:\s+IF NOT EXISTS)?\*/\s*)?CREATE\s+DATABASE"
    rb"(?:\s+/\*!\d{5,6}\s+IF NOT EXISTS\*/)?\s+`erpcondominios_stage_20261114`"
    rb"(?:\s+/\*!\d{5,6}\s+DEFAULT CHARACTER SET [A-Za-z0-9_]+"
    rb"(?:\s+COLLATE [A-Za-z0-9_]+)?\s*\*/)?\s*;"
)
USE_DB_RE = re.compile(rb"(?i)USE `erpcondominios_stage_20261114`\s*;")


def available_memory_kib(*, meminfo: Path = Path("/proc/meminfo")) -> int:
    try:
        for line in meminfo.read_text(encoding="ascii").splitlines():
            if line.startswith("MemAvailable:"):
                return int(line.split()[1])
    except (OSError, ValueError, IndexError) as error:
        raise P5Error("MEMORY_STATUS_UNAVAILABLE") from error
    raise P5Error("MEMORY_STATUS_UNAVAILABLE")


def check_memory_guard(reader=available_memory_kib) -> int:
    value = reader()
    if value < MIN_AVAILABLE_KIB:
        raise P5Error("MEMORY_GUARD_BELOW_1536_MIB")
    return value


def _guarded_wait(processes, *, memory_reader=available_memory_kib, sleep=time.sleep) -> None:
    try:
        while any(process.poll() is None for process in processes):
            check_memory_guard(memory_reader)
            sleep(2)
    except P5Error:
        for process in processes:
            if process.poll() is None:
                process.terminate()
        for process in processes:
            process.wait()
        raise


def _prioritized(command: list[str]) -> list[str]:
    return [str(NICE), "-n", "19", str(IONICE), "-c3", *command]


def _check_secret(path: Path, *, owner_uid: int = 0) -> None:
    validate_root_file(path, mode=0o600, owner_uid=owner_uid)
    try:
        raw = path.read_text(encoding="utf-8")
    except OSError as error:
        raise P5Error("MYSQL_SECRET_INVALID") from error
    section = None
    values: dict[str, str] = {}
    for original in raw.splitlines():
        line = original.strip()
        if not line or line.startswith("#") or line.startswith(";"):
            continue
        if line.startswith("!") or "\x00" in line:
            raise P5Error("MYSQL_SECRET_INVALID")
        if line.startswith("[") and line.endswith("]"):
            section = line[1:-1].strip().lower()
            if section != "client":
                raise P5Error("MYSQL_SECRET_INVALID")
            continue
        if section != "client" or "=" not in line:
            raise P5Error("MYSQL_SECRET_INVALID")
        key, value = (piece.strip() for piece in line.split("=", 1))
        key = key.lower()
        if key in values or key not in {"host", "user", "password", "database", "port", "socket"}:
            raise P5Error("MYSQL_SECRET_INVALID")
        values[key] = value
    # No dedicated final-dump account/privilege contract is documented. Do not infer root,
    # reuse pg_reader (read-only), or invent an account name; deployment remains blocked.
    raise P5Error("MYSQL_CREDENTIAL_POLICY_UNCONFIGURED")


def check_configuration(*, owner_uid: int = 0) -> dict[str, str]:
    data = read_json_private(CONFIG, expected_keys=CONFIG_EXPECTED, owner_uid=owner_uid)
    if data != CONFIG_EXPECTED:
        raise P5Error("BACKUP_CONFIG_NOT_ALLOWLISTED")
    _check_secret(SECRET_FILE, owner_uid=owner_uid)
    validate_no_symlink_components(BACKUP_ROOT)
    if BACKUP_ROOT.exists():
        validate_root_directory(BACKUP_ROOT, mode=0o700, owner_uid=owner_uid)
    else:
        raise P5Error("BACKUP_ROOT_NOT_PROVISIONED")
    for executable in (MYSQLDUMP, GZIP):
        if executable.is_symlink() or not executable.is_file() or not os.access(executable, os.X_OK):
            raise P5Error("PINNED_TOOL_UNAVAILABLE")
    for executable in (NICE, IONICE):
        if executable.is_symlink() or not executable.is_file() or not os.access(executable, os.X_OK):
            raise P5Error("PINNED_TOOL_UNAVAILABLE")
    return {"status": "CHECK_READY", "schema": SCHEMA}


def _run(argv: list[str], *, runner=subprocess.run) -> int:
    try:
        result = runner(argv, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                        env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"}, close_fds=True, check=False)
    except OSError as error:
        raise P5Error("BACKUP_TOOL_UNAVAILABLE") from error
    if result.returncode != 0:
        raise P5Error("BACKUP_TOOL_FAILED")
    return result.returncode


def _fsync_dir(path: Path) -> None:
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_NOFOLLOW", 0))
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _verify_dump(path: Path, *, runner=subprocess.run, memory_reader=available_memory_kib,
                 owner_uid: int = 0) -> tuple[str, int]:
    validate_root_file(path, mode=0o600, owner_uid=owner_uid)
    check_memory_guard(memory_reader)
    _run(_prioritized([str(GZIP), "-t", "--", str(path)]), runner=runner)
    check_memory_guard(memory_reader)
    try:
        with gzip.open(path, "rb") as stream:
            header = stream.read(262144)
            # Require both the dump's server header and exact explicit schema selection.
            if not HEADER_RE.search(header) or not CREATE_DB_RE.search(header) or not USE_DB_RE.search(header):
                raise P5Error("DUMP_HEADER_SCHEMA_INVALID")
    except P5Error:
        raise
    except Exception as error:
        raise P5Error("DUMP_GZIP_INVALID") from error
    # Hash the exact stored artifact (compressed bytes); never print this value.
    with path.open("rb") as artifact:
        digest = hashlib.file_digest(artifact, "sha256").hexdigest()
    return digest, path.stat().st_size


def create_backup(*, runner=subprocess.run, popen_factory=subprocess.Popen, memory_reader=available_memory_kib,
                  sleep=time.sleep, owner_uid: int = 0, now: dt.datetime | None = None) -> dict[str, object]:
    root_required()
    started = time.monotonic()
    check_configuration(owner_uid=owner_uid)
    check_memory_guard(memory_reader)
    validate_approval(APPROVAL, action="mysql-final-backup", target=SCHEMA, source_schema=None,
                      destination=str(BACKUP_ROOT), owner_uid=owner_uid)
    lock = exclusive_lock(BACKUP_ROOT / ".p5-final-backup.lock", owner_uid=owner_uid)
    try:
        marker = BACKUP_ROOT / ".p5-final-backup.consumed"
        if marker.exists() or marker.is_symlink():
            raise P5Error("BACKUP_APPROVAL_ALREADY_CONSUMED")
        # Consume before launching mysqldump; a partial/failed attempt is never silently retried.
        flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
        marker_fd = os.open(marker, flags, 0o600)
        with os.fdopen(marker_fd, "w", encoding="ascii") as stream:
            stream.write("consumed=true\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.chown(marker, owner_uid, 0)
        os.chmod(marker, 0o600)
        _fsync_dir(BACKUP_ROOT)
        timestamp = (now or dt.datetime.now(dt.timezone.utc)).astimezone(dt.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        dump_path = BACKUP_ROOT / f"mysql-final-{timestamp}.sql.gz"
        manifest_path = BACKUP_ROOT / f"mysql-final-{timestamp}.manifest.json"
        if dump_path.exists() or dump_path.is_symlink() or manifest_path.exists() or manifest_path.is_symlink():
            raise P5Error("BACKUP_OUTPUT_EXISTS")
        temp_path = BACKUP_ROOT / f".mysql-final-{timestamp}.{os.getpid()}.tmp"
        try:
            fd = os.open(temp_path, flags, 0o600)
            os.fchown(fd, owner_uid, 0)
            with os.fdopen(fd, "wb") as raw:
                # mysqldump stdout is piped to gzip; both subprocess outputs are suppressed.
                # Secrets are read only from --defaults-extra-file and never enter argv/env.
                try:
                    dump = popen_factory(
                        _prioritized([str(MYSQLDUMP), f"--defaults-extra-file={SECRET_FILE}", "--single-transaction",
                                      "--routines", "--events", "--triggers", "--databases", SCHEMA]),
                        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                        env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"}, close_fds=True,
                    )
                    assert dump.stdout is not None
                    compressor = popen_factory(_prioritized([str(GZIP), "-n", "-c"]), stdin=dump.stdout, stdout=raw,
                                                  stderr=subprocess.DEVNULL,
                                                  env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"}, close_fds=True)
                    dump.stdout.close()
                    _guarded_wait([dump, compressor], memory_reader=memory_reader, sleep=sleep)
                    gzip_code = compressor.wait()
                    dump_code = dump.wait()
                except OSError as error:
                    raise P5Error("BACKUP_TOOL_UNAVAILABLE") from error
                raw.flush()
                os.fsync(raw.fileno())
            if gzip_code != 0 or dump_code != 0:
                raise P5Error("BACKUP_TOOL_FAILED")
            os.chmod(temp_path, 0o600)
            digest, size = _verify_dump(
                temp_path, runner=runner, memory_reader=memory_reader, owner_uid=owner_uid,
            )
            os.link(temp_path, dump_path)
            temp_path.unlink()
            os.chown(dump_path, owner_uid, 0)
            os.chmod(dump_path, 0o600)
            manifest = {"schema": SCHEMA, "host": SOURCE_HOST, "dump": dump_path.name,
                        "sha256": digest, "bytes": size, "gzip_test": "PASS", "header_schema": "PASS"}
            manifest_tmp = BACKUP_ROOT / f".{manifest_path.name}.{os.getpid()}.tmp"
            mfd = os.open(manifest_tmp, flags, 0o600)
            os.fchown(mfd, owner_uid, 0)
            with os.fdopen(mfd, "w", encoding="ascii", newline="\n") as stream:
                json.dump(manifest, stream, sort_keys=True, separators=(",", ":"))
                stream.write("\n")
                stream.flush()
                os.fsync(stream.fileno())
            os.link(manifest_tmp, manifest_path)
            manifest_tmp.unlink()
            os.chown(manifest_path, owner_uid, 0)
            os.chmod(manifest_path, 0o600)
            _fsync_dir(BACKUP_ROOT)
            return {"status": "BACKUP_VERIFIED", "schema": SCHEMA, "gzip_test": "PASS",
                    "header_schema": "PASS", "bytes": size,
                    "elapsed_seconds": round(time.monotonic() - started, 3)}
        except P5Error:
            raise
        except Exception as error:
            raise P5Error("BACKUP_WRITE_FAILED") from error
        finally:
            temp_path.unlink(missing_ok=True)
    finally:
        os.close(lock)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--check", action="store_true")
    modes.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    try:
        result = check_configuration() if args.check else create_backup()
    except P5Error as error:
        return emit_error(error)
    except Exception:
        return emit_error(P5Error("INTERNAL_FAILURE"))
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
