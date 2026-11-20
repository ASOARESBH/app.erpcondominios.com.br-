#!/usr/bin/env python3
"""Fail-closed P5 cutover catalog capture; only reads erp_pg_source."""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import signal
import time

from p5_common import (
    P5Error, emit_error, exclusive_lock, read_json_private, root_required,
    validate_approval, validate_root_directory, validate_root_file,
)

CONFIG = Path("/etc/erpcondominios/p5/catalog.json")
APPROVAL = Path("/etc/erpcondominios/p5/catalog-approval.json")
SOURCE_DEFAULTS = Path("/etc/erpcondominios/secrets/pg_reader.cnf")
BUNDLE_ROOT = Path("/var/lib/erpcondominios-pg/p2-bundles")
LOCK = BUNDLE_ROOT / ".p5-cutover-catalog.lock"
SOURCE_SCHEMA = "erp_pg_source"
PROFILE = "cutover"
EXPECTED_CONFIG = {
    "profile": PROFILE,
    "source_schema": SOURCE_SCHEMA,
    "source_defaults": str(SOURCE_DEFAULTS),
    "bundle_root": str(BUNDLE_ROOT),
    "approval_file": str(APPROVAL),
}
SHA_RE = re.compile(r"^[a-f0-9]{40}$")
BUNDLE_RE = re.compile(r"^catalog-[a-f0-9]{12}-collation-[a-f0-9]{8}-role-[a-f0-9]{8}-code-[a-f0-9]{8}$")
MIN_AVAILABLE_KIB = 1_572_864


def available_memory_kib(*, meminfo: Path = Path("/proc/meminfo")) -> int:
    try:
        for line in meminfo.read_text(encoding="ascii").splitlines():
            if line.startswith("MemAvailable:"):
                return int(line.split()[1])
    except (OSError, ValueError, IndexError) as error:
        raise P5Error("MEMORY_STATUS_UNAVAILABLE") from error
    raise P5Error("MEMORY_STATUS_UNAVAILABLE")


def check_memory_guard(reader=available_memory_kib) -> int:
    available = reader()
    if available < MIN_AVAILABLE_KIB:
        raise P5Error("MEMORY_GUARD_BELOW_1536_MIB")
    return available


def _repo_root() -> Path:
    return Path(__file__).resolve().parents[3]


def _check_defaults_file(path: Path, *, owner_uid: int = 0) -> None:
    validate_root_file(path, mode=0o600, owner_uid=owner_uid)
    # Parse only the minimal local client option file; values never enter logs or output.
    try:
        raw = path.read_text(encoding="utf-8")
    except OSError as error:
        raise P5Error("SOURCE_DEFAULTS_INVALID") from error
    values: dict[str, str] = {}
    section = None
    for original in raw.splitlines():
        line = original.strip()
        if not line or line.startswith("#") or line.startswith(";"):
            continue
        if line.startswith("!") or "\x00" in line:
            raise P5Error("SOURCE_DEFAULTS_INVALID")
        if line.startswith("[") and line.endswith("]"):
            section = line[1:-1].strip().lower()
            if section != "client":
                raise P5Error("SOURCE_DEFAULTS_INVALID")
            continue
        if section != "client" or "=" not in line:
            raise P5Error("SOURCE_DEFAULTS_INVALID")
        key, value = (piece.strip() for piece in line.split("=", 1))
        key = key.lower()
        allowed = {"host", "user", "password", "database", "port", "socket", "default-character-set"}
        if key in values or key not in allowed:
            raise P5Error("SOURCE_DEFAULTS_INVALID")
        values[key] = value
    host = values.get("host", "localhost").lower()
    if values.get("user") != "pg_reader" or values.get("database") != SOURCE_SCHEMA \
       or host not in {"localhost", "127.0.0.1"} or not values.get("password"):
        raise P5Error("SOURCE_NOT_ALLOWLISTED")
    if "port" in values and values["port"] != "3306":
        raise P5Error("SOURCE_NOT_ALLOWLISTED")
    if "socket" in values and values["socket"] not in {"/run/mysqld/mysqld.sock", "/var/run/mysqld/mysqld.sock"}:
        raise P5Error("SOURCE_NOT_ALLOWLISTED")


def check_configuration(*, owner_uid: int = 0) -> dict[str, str]:
    data = read_json_private(CONFIG, expected_keys=EXPECTED_CONFIG, owner_uid=owner_uid)
    if data != EXPECTED_CONFIG:
        raise P5Error("CATALOG_CONFIG_NOT_ALLOWLISTED")
    _check_defaults_file(SOURCE_DEFAULTS, owner_uid=owner_uid)
    validate_root_directory(BUNDLE_ROOT, mode=0o700, owner_uid=owner_uid)
    return {"status": "CHECK_READY", "profile": PROFILE, "source_schema": SOURCE_SCHEMA}


def _run_logged(argv: list[str], log_path: Path, *, cwd: Path, process_factory=subprocess.Popen,
                memory_reader=available_memory_kib, sleep=time.sleep, owner_uid: int = 0) -> int:
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
    try:
        fd = os.open(log_path, flags, 0o600)
        os.fchown(fd, owner_uid, 0 if owner_uid == 0 else os.getegid())
        with os.fdopen(fd, "wb") as log:
            check_memory_guard(memory_reader)
            process = process_factory(
                argv, stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT,
                env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"},
                cwd=str(cwd), close_fds=True, start_new_session=True,
            )
            log.flush()
            os.fsync(log.fileno())
            while process.poll() is None:
                try:
                    check_memory_guard(memory_reader)
                except P5Error:
                    os.killpg(process.pid, signal.SIGTERM)
                    process.wait()
                    raise
                sleep(2)
            return_code = process.wait()
    except P5Error:
        raise
    except OSError as error:
        raise P5Error("CATALOG_TOOL_UNAVAILABLE") from error
    if return_code != 0:
        raise P5Error("CATALOG_TOOL_FAILED")
    validate_root_file(log_path, mode=0o600, owner_uid=owner_uid)
    return return_code


def _read_last_json_line(path: Path) -> dict[str, object]:
    try:
        lines = path.read_text(encoding="utf-8").splitlines()
        result = json.loads(lines[-1]) if lines else None
    except Exception as error:
        raise P5Error("DDL_SUMMARY_INVALID") from error
    if not isinstance(result, dict):
        raise P5Error("DDL_SUMMARY_INVALID")
    return result


def capture(*, process_factory=subprocess.Popen, owner_uid: int = 0) -> dict[str, object]:
    root_required()
    started = time.monotonic()
    check_configuration(owner_uid=owner_uid)
    validate_approval(
        APPROVAL, action="catalog-capture", target=SOURCE_SCHEMA,
        source_schema=SOURCE_SCHEMA, destination="cutover-v12-bundle", owner_uid=owner_uid,
    )
    lock_fd = exclusive_lock(LOCK, owner_uid=owner_uid)
    try:
        repo = _repo_root()
        release_id = repo.name
        if not SHA_RE.fullmatch(release_id):
            raise P5Error("IMMUTABLE_RELEASE_REQUIRED")
        sys.path.insert(0, str(repo))
        try:
            from tools.migracao_pg import catalog_bundle as contract
            from tools.migracao_pg.loader import validate_bundle
        except Exception as error:
            raise P5Error("CATALOG_CONTRACT_UNAVAILABLE") from error
        attempt = f"{time.strftime('%Y%m%dT%H%M%SZ', time.gmtime())}-{os.getpid()}"
        work = BUNDLE_ROOT / f".p5-cutover-{release_id}-{attempt}"
        if work.exists() or work.is_symlink():
            raise P5Error("WORK_DIRECTORY_EXISTS")
        work.mkdir(mode=0o700)
        os.chown(work, 0, 0)
        snapshot_path = work / "catalog_snapshot.json"
        staging_root = work / "bundles"
        staging_root.mkdir(mode=0o700)
        os.chown(staging_root, 0, 0)
        python, nice, ionice = Path(sys.executable), Path("/usr/bin/nice"), Path("/usr/bin/ionice")
        for executable in (python, nice, ionice):
            if executable.is_symlink() or not executable.is_absolute() or not executable.is_file() or not os.access(executable, os.X_OK):
                raise P5Error("PINNED_RUNTIME_REQUIRED")
        wrapped = [str(nice), "-n", "19", str(ionice), "-c3", str(python), "-E", "-B", "-m"]
        catalog_log, ddl_log = work / "catalog.log", work / "ddl.log"
        _run_logged(wrapped + [
            "tools.migracao_pg.catalog", "--defaults-file", str(SOURCE_DEFAULTS), "--output", str(snapshot_path),
        ], catalog_log, cwd=repo, process_factory=process_factory, owner_uid=owner_uid)
        validate_root_file(snapshot_path, mode=0o600, owner_uid=owner_uid)
        try:
            snapshot = json.loads(snapshot_path.read_text(encoding="utf-8"))
            if not isinstance(snapshot, dict):
                raise ValueError
            catalog_counts = contract._validate_catalog_snapshot(snapshot)
        except Exception as error:
            raise P5Error("CATALOG_SNAPSHOT_REJECTED") from error
        _run_logged(wrapped + [
            "tools.migracao_pg.generate_ddl", "--snapshot", str(snapshot_path),
            "--application-role", "erp_app", "--output-dir", str(staging_root),
        ], ddl_log, cwd=repo, process_factory=process_factory, owner_uid=owner_uid)
        summary = _read_last_json_line(ddl_log)
        if summary.get("executable_complete") is not True or summary.get("load_executable_complete") is not True \
           or summary.get("review_items") != 0 or summary.get("blocked_tables") != 0:
            raise P5Error("BUNDLE_REQUIRES_REVIEW")
        try:
            contract._validate_generator_summary(summary, snapshot)
        except Exception as error:
            raise P5Error("DDL_PROVENANCE_INVALID") from error
        output_value = summary.get("output_directory")
        if not isinstance(output_value, str):
            raise P5Error("DDL_OUTPUT_DIRECTORY_INVALID")
        bundle_path = Path(output_value)
        if bundle_path.parent != staging_root or bundle_path.is_symlink() or not BUNDLE_RE.fullmatch(bundle_path.name):
            raise P5Error("DDL_OUTPUT_DIRECTORY_INVALID")
        contract._validate_bundle_files(bundle_path)
        try:
            report = contract._read_json(bundle_path / "report.json", "BUNDLE_REPORT_INVALID")
            bundle_counts = contract._validate_bundle_report(report)
            bundled_snapshot = contract._read_json(bundle_path / "catalog_snapshot.json", "BUNDLE_SNAPSHOT_INVALID")
        except Exception as error:
            raise P5Error("BUNDLE_VALIDATION_FAILED") from error
        if bundled_snapshot != snapshot:
            raise P5Error("BUNDLE_SNAPSHOT_MISMATCH")
        try:
            marker = (bundle_path / "BUNDLE_COMPLETE").read_text(encoding="ascii")
        except OSError as error:
            raise P5Error("BUNDLE_COMPLETION_MARKER_INVALID") from error
        if marker != "bundle_complete=true\n":
            raise P5Error("BUNDLE_COMPLETION_MARKER_INVALID")
        try:
            _path, validated_report, validated_snapshot = validate_bundle(
                bundle_path.name, require_executable=True, bundle_root=staging_root,
            )
        except Exception as error:
            raise P5Error("BUNDLE_VALIDATION_FAILED") from error
        if validated_report != report or validated_snapshot != snapshot:
            raise P5Error("BUNDLE_VALIDATION_RESULT_MISMATCH")
        final_bundle = BUNDLE_ROOT / bundle_path.name
        selection_path = BUNDLE_ROOT / f"cutover-v12-{release_id}.selection"
        if final_bundle.exists() or final_bundle.is_symlink() or selection_path.exists() or selection_path.is_symlink():
            raise P5Error("BUNDLE_OUTPUT_ALREADY_EXISTS")
        os.rename(bundle_path, final_bundle)
        contract._validate_bundle_files(final_bundle)
        try:
            _path, final_report, final_snapshot = validate_bundle(bundle_path.name, require_executable=True)
        except Exception as error:
            raise P5Error("BUNDLE_FINAL_VALIDATION_FAILED") from error
        if final_report != report or final_snapshot != snapshot:
            raise P5Error("BUNDLE_FINAL_VALIDATION_MISMATCH")
        contract._atomic_selection(selection_path, release_id, bundle_path.name)
        return {"status": "CATALOG_BUNDLE_READY", "profile": PROFILE, "source_schema": SOURCE_SCHEMA,
                "catalog": catalog_counts, "bundle": bundle_counts,
                "elapsed_seconds": round(time.monotonic() - started, 3)}
    finally:
        os.close(lock_fd)
        # Failed attempts and private logs remain root-only; never print or expose their contents.


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--check", action="store_true", help="validar apenas config e pré-condições")
    modes.add_argument("--apply", action="store_true", help="capturar catálogo após aprovação estruturada")
    args = parser.parse_args()
    try:
        result = check_configuration() if args.check else capture()
    except P5Error as error:
        return emit_error(error)
    except Exception:
        return emit_error(P5Error("INTERNAL_FAILURE"))
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
