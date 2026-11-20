from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import sys
import time
from typing import Any

from .catalog import (
    CATALOG_VERSION,
    SOURCE_GRANT_POLICY_ID,
    SOURCE_SCHEMA,
    TIME_RANGE_POLICY_ID,
    _collector_fingerprint,
    _structural_fingerprint,
)
from .ddl import canonical_fingerprint
from . import loader as loader_module
from .loader import LoaderError, _source_defaults_path, validate_bundle
from .type_mapping import BINARY_STORAGE_POLICY_ID, expected_binary_storage_manifest


BUNDLE_ROOT = Path("/var/lib/erpcondominios-pg/p2-bundles")
SOURCE_DEFAULTS = Path("/etc/erpcondominios/secrets/pg_reader.cnf")
MIN_AVAILABLE_KIB = 1_572_864
EXPECTED_TABLES = 276
EXPECTED_FOREIGN_KEYS = 11
EXPECTED_TRIGGERS = 7
BUNDLE_NAME_PATTERN = re.compile(
    r"^catalog-[a-f0-9]{12}-collation-[a-f0-9]{8}-role-[a-f0-9]{8}-code-[a-f0-9]{8}$"
)
EXPECTED_BINARY_REPORT = {
    "policy_id": BINARY_STORAGE_POLICY_ID,
    "columns": [{
        "table": "tenant_arquivos",
        "column": "conteudo",
        "encoding": "base64",
        "target_type": "TEXT",
        "target_collation": '"C"',
    }],
}


class CatalogBundleError(RuntimeError):
    def __init__(self, code: str) -> None:
        self.code = code
        super().__init__(code)


def _unique_object(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise CatalogBundleError("JSON_DUPLICATE_KEY")
        result[key] = value
    return result


def _read_json(path: Path, code: str) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=_unique_object)
    except (OSError, UnicodeError, json.JSONDecodeError) as error:
        raise CatalogBundleError(code) from error
    if not isinstance(payload, dict):
        raise CatalogBundleError(code)
    return payload


def _regular_file(path: Path, mode: int) -> bool:
    try:
        info = path.lstat()
    except OSError:
        return False
    return stat.S_ISREG(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == mode


def _validate_catalog_snapshot(snapshot: dict[str, Any]) -> dict[str, int]:
    if snapshot.get("catalog_version") != CATALOG_VERSION or CATALOG_VERSION != 12:
        raise CatalogBundleError("CATALOG_VERSION_NOT_ALLOWLISTED")
    if snapshot.get("source_schema") != SOURCE_SCHEMA or SOURCE_SCHEMA != "erp_pg_source":
        raise CatalogBundleError("SOURCE_SCHEMA_NOT_ALLOWLISTED")
    if snapshot.get("binary_storage_policy_id") != BINARY_STORAGE_POLICY_ID \
        or snapshot.get("binary_storage_required") is not True:
        raise CatalogBundleError("BINARY_STORAGE_POLICY_REQUIRED")
    if snapshot.get("time_range_policy_id") != TIME_RANGE_POLICY_ID:
        raise CatalogBundleError("TIME_POLICY_MISMATCH")
    if snapshot.get("collector_code_sha256") != _collector_fingerprint():
        raise CatalogBundleError("COLLECTOR_PROVENANCE_MISMATCH")
    if snapshot.get("structural_fingerprint_sha256") != _structural_fingerprint(snapshot):
        raise CatalogBundleError("CATALOG_STRUCTURAL_FINGERPRINT_MISMATCH")
    if snapshot.get("fingerprint_sha256") != canonical_fingerprint(snapshot):
        raise CatalogBundleError("CATALOG_FINGERPRINT_MISMATCH")
    if snapshot.get("catalog_consistency_check") != "PRE_POST_SIGNATURE_MATCH":
        raise CatalogBundleError("CATALOG_CONSISTENCY_NOT_PROVEN")
    if snapshot.get("source_grant_policy_id") != SOURCE_GRANT_POLICY_ID \
        or snapshot.get("source_grants_policy_accepted") is not True \
        or snapshot.get("source_schema_wide_select") is not True \
        or snapshot.get("unresolved_role_grants") is not False:
        raise CatalogBundleError("SOURCE_GRANTS_POLICY_NOT_ACCEPTED")
    if snapshot.get("source_routines_events_zero_verified") is not True:
        raise CatalogBundleError("SOURCE_ROUTINES_EVENTS_ZERO_NOT_VERIFIED")
    visibility = snapshot.get("metadata_visibility")
    expected_visibility = {"views": True, "triggers": True, "routines": False, "events": False}
    if not isinstance(visibility, dict) or any(
        type(visibility.get(key)) is not bool or visibility.get(key) is not expected
        for key, expected in expected_visibility.items()
    ):
        raise CatalogBundleError("METADATA_VISIBILITY_MISMATCH")
    tables = snapshot.get("tables")
    fks = snapshot.get("foreign_keys")
    triggers = snapshot.get("triggers")
    views = snapshot.get("views")
    routines = snapshot.get("routines")
    events = snapshot.get("events")
    reviews = snapshot.get("review_items")
    if not all(isinstance(value, list) for value in (tables, fks, triggers, views, routines, events, reviews)):
        raise CatalogBundleError("CATALOG_SHAPE_INVALID")
    base_tables = [item for item in tables if isinstance(item, dict) and item.get("type") == "BASE TABLE"]
    counts = {
        "tables": len(base_tables),
        "foreign_keys": len(fks),
        "triggers": len(triggers),
        "views": len(views),
        "routines": len(routines),
        "events": len(events),
        "reviews": len(reviews),
    }
    if counts != {
        "tables": EXPECTED_TABLES,
        "foreign_keys": EXPECTED_FOREIGN_KEYS,
        "triggers": EXPECTED_TRIGGERS,
        "views": 0,
        "routines": 0,
        "events": 0,
        "reviews": 0,
    }:
        raise CatalogBundleError("SOURCE_CATALOG_COUNTS_NOT_ALLOWLISTED")
    try:
        expected_manifest = expected_binary_storage_manifest(snapshot)
    except Exception as error:
        raise CatalogBundleError("BINARY_STORAGE_SOURCE_MANIFEST_INVALID") from error
    if len(expected_manifest) != 1:
        raise CatalogBundleError("BINARY_STORAGE_SOURCE_MANIFEST_INVALID")
    return counts


def _validate_bundle_report(report: dict[str, Any]) -> dict[str, int]:
    if report.get("source_schema") != SOURCE_SCHEMA or report.get("application_role") != "erp_app":
        raise CatalogBundleError("BUNDLE_TARGET_POLICY_MISMATCH")
    if report.get("binary_storage") != EXPECTED_BINARY_REPORT:
        raise CatalogBundleError("BUNDLE_BINARY_STORAGE_MANIFEST_MISMATCH")
    reviews = report.get("review_items")
    blocked = report.get("blocked_tables")
    unsupported = report.get("unsupported_indexes")
    deferred = report.get("deferred_indexes")
    triggers = report.get("translated_business_triggers")
    foreign_keys = report.get("foreign_keys")
    if isinstance(foreign_keys, dict) and foreign_keys.get("unsupported") != []:
        raise CatalogBundleError("BUNDLE_FOREIGN_KEY_UNSUPPORTED")
    if not all(isinstance(value, list) for value in (reviews, blocked, unsupported, deferred, triggers)) \
        or not isinstance(foreign_keys, dict):
        raise CatalogBundleError("BUNDLE_REPORT_SHAPE_INVALID")
    counts = {
        "reviews": len(reviews),
        "blocked_tables": len(blocked),
        "unsupported_indexes": len(unsupported),
        "deferred_indexes": len(deferred),
        "translated_business_triggers": len(triggers),
        "foreign_keys_generated_not_valid": foreign_keys.get("generated_not_valid", -1),
        "foreign_keys_deferred": len(foreign_keys.get("deferred", []))
        if isinstance(foreign_keys.get("deferred"), list) else -1,
    }
    if report.get("load_executable_complete") is not True or report.get("executable_complete") is not True:
        raise CatalogBundleError("BUNDLE_NOT_EXECUTABLE")
    if counts != {
        "reviews": 0,
        "blocked_tables": 0,
        "unsupported_indexes": 0,
        "deferred_indexes": 0,
        "translated_business_triggers": EXPECTED_TRIGGERS,
        "foreign_keys_generated_not_valid": EXPECTED_FOREIGN_KEYS,
        "foreign_keys_deferred": 0,
    }:
        raise CatalogBundleError("BUNDLE_REQUIRES_REVIEW")
    return counts


def _validate_generator_summary(summary: dict[str, Any], snapshot: dict[str, Any]) -> None:
    if summary.get("catalog_fingerprint_sha256") != snapshot.get("fingerprint_sha256") \
        or summary.get("application_role_sha256") != hashlib.sha256(b"erp_app").hexdigest():
        raise CatalogBundleError("DDL_GENERATION_PROVENANCE_MISMATCH")


def _available_memory_kib() -> int:
    try:
        for line in Path("/proc/meminfo").read_text(encoding="ascii").splitlines():
            if line.startswith("MemAvailable:"):
                return int(line.split()[1])
    except (OSError, ValueError, IndexError):
        pass
    raise CatalogBundleError("MEMORY_STATUS_UNAVAILABLE")


def _private_log(path: Path) -> int:
    try:
        return os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
    except OSError as error:
        raise CatalogBundleError("PRIVATE_LOG_CREATE_FAILED") from error


def _stop_child(process: subprocess.Popen[Any]) -> None:
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    try:
        process.wait(timeout=10)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        process.wait()


def _run_stage(
    argv: list[str],
    log_path: Path,
    stage: str,
    *,
    minimum_available_kib: int = MIN_AVAILABLE_KIB,
) -> tuple[int, int]:
    available = _available_memory_kib()
    if available < minimum_available_kib:
        raise CatalogBundleError("MEMORY_GUARD_BELOW_1536_MIB")
    fd = _private_log(log_path)
    minimum_observed = available
    stage_started = time.monotonic()
    next_progress = stage_started + 30
    env = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "ERP_P2_ROOT_LAUNCHER_VERIFIED": "1"}
    process: subprocess.Popen[Any] | None = None
    try:
        with os.fdopen(fd, "wb") as log:
            process = subprocess.Popen(
                argv,
                stdin=subprocess.DEVNULL,
                stdout=log,
                stderr=subprocess.STDOUT,
                env=env,
                start_new_session=True,
                close_fds=True,
            )
            try:
                available = _available_memory_kib()
            except CatalogBundleError:
                _stop_child(process)
                raise
            minimum_observed = min(minimum_observed, available)
            if available < minimum_available_kib:
                _stop_child(process)
                raise CatalogBundleError("MEMORY_GUARD_BELOW_1536_MIB")
            while process.poll() is None:
                time.sleep(2)
                try:
                    available = _available_memory_kib()
                except CatalogBundleError:
                    _stop_child(process)
                    raise
                minimum_observed = min(minimum_observed, available)
                if available < minimum_available_kib:
                    _stop_child(process)
                    raise CatalogBundleError("MEMORY_GUARD_BELOW_1536_MIB")
                if time.monotonic() >= next_progress:
                    elapsed = int(time.monotonic() - stage_started)
                    print(f"CATALOG_BUNDLE_PROGRESS=RUNNING STAGE={stage} ELAPSED_SECONDS={elapsed}", file=sys.stderr, flush=True)
                    next_progress = time.monotonic() + 30
            return process.returncode, minimum_observed
    except CatalogBundleError:
        if process is not None and process.poll() is None:
            _stop_child(process)
        raise
    except OSError as error:
        if process is not None and process.poll() is None:
            _stop_child(process)
        raise CatalogBundleError("P2_STAGE_EXECUTION_FAILED") from error
    except BaseException:
        if process is not None and process.poll() is None:
            _stop_child(process)
        raise


def _atomic_selection(path: Path, release_id: str, bundle_name: str) -> None:
    if not BUNDLE_NAME_PATTERN.fullmatch(bundle_name):
        raise CatalogBundleError("BUNDLE_NAME_INVALID")
    if path.exists() or path.is_symlink():
        raise CatalogBundleError("BUNDLE_SELECTION_ALREADY_EXISTS")
    payload = json.dumps(
        {"release_sha": release_id, "bundle_name": bundle_name},
        sort_keys=True,
        separators=(",", ":"),
    ) + "\n"
    temporary = path.with_name(f".{path.name}.{os.getpid()}.tmp")
    fd = -1
    try:
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
        with os.fdopen(fd, "w", encoding="ascii", newline="\n") as stream:
            fd = -1
            stream.write(payload)
            stream.flush()
            os.fsync(stream.fileno())
        os.chown(temporary, 0, 0)
        os.link(temporary, path)
        path_info = path.lstat()
        if not stat.S_ISREG(path_info.st_mode) or path_info.st_uid != 0 or stat.S_IMODE(path_info.st_mode) != 0o600:
            raise CatalogBundleError("BUNDLE_SELECTION_MODE_INVALID")
        directory_fd = os.open(path.parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
    except CatalogBundleError:
        raise
    except OSError as error:
        raise CatalogBundleError("BUNDLE_SELECTION_WRITE_FAILED") from error
    finally:
        if fd >= 0:
            os.close(fd)
        temporary.unlink(missing_ok=True)


def _validate_bundle_files(bundle_path: Path) -> None:
    try:
        root_info = bundle_path.lstat()
    except OSError as error:
        raise CatalogBundleError("BUNDLE_DIRECTORY_INVALID") from error
    if not stat.S_ISDIR(root_info.st_mode) or root_info.st_uid != 0 or stat.S_IMODE(root_info.st_mode) != 0o700:
        raise CatalogBundleError("BUNDLE_DIRECTORY_MODE_INVALID")
    for path in bundle_path.rglob("*"):
        info = path.lstat()
        if stat.S_ISLNK(info.st_mode) or info.st_uid != 0:
            raise CatalogBundleError("BUNDLE_OBJECT_INVALID")
        if stat.S_ISDIR(info.st_mode):
            if stat.S_IMODE(info.st_mode) != 0o700:
                raise CatalogBundleError("BUNDLE_DIRECTORY_MODE_INVALID")
        elif not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o600:
            raise CatalogBundleError("BUNDLE_FILE_MODE_INVALID")


def capture_catalog_bundle() -> dict[str, Any]:
    capture_started = time.monotonic()
    if os.geteuid() != 0 or os.environ.get("ERP_P2_ROOT_LAUNCHER_VERIFIED") != "1":
        raise CatalogBundleError("ROOT_RUNNER_VERIFICATION_REQUIRED")
    release_root = Path(__file__).resolve().parents[2]
    release_id = release_root.name
    if not re.fullmatch(r"[a-f0-9]{40}", release_id):
        raise CatalogBundleError("IMMUTABLE_RELEASE_REQUIRED")
    try:
        _source_defaults_path(str(SOURCE_DEFAULTS))
    except LoaderError as error:
        raise CatalogBundleError(error.code) from error
    try:
        bundle_root_info = BUNDLE_ROOT.lstat()
    except OSError as error:
        raise CatalogBundleError("BUNDLE_ROOT_UNAVAILABLE") from error
    if not stat.S_ISDIR(bundle_root_info.st_mode) or bundle_root_info.st_uid != 0 \
        or stat.S_IMODE(bundle_root_info.st_mode) != 0o700 or BUNDLE_ROOT.is_symlink():
        raise CatalogBundleError("BUNDLE_ROOT_MODE_INVALID")

    attempt_id = f"{time.strftime('%Y%m%dT%H%M%SZ', time.gmtime())}-{os.getpid()}"
    work = BUNDLE_ROOT / f".catalog-v12-{release_id}-{attempt_id}"
    if work.exists() or work.is_symlink():
        raise CatalogBundleError("CATALOG_CAPTURE_ALREADY_EXISTS")
    work.mkdir(mode=0o700)
    os.chown(work, 0, 0)
    staging_bundle_root = work / "bundles"
    staging_bundle_root.mkdir(mode=0o700)
    os.chown(staging_bundle_root, 0, 0)
    snapshot_path = work / "catalog_snapshot.json"
    catalog_log = work / "catalog.log"
    ddl_log = work / "ddl.log"
    selection_path = BUNDLE_ROOT / f"development-v12-{release_id}.selection"
    if selection_path.exists() or selection_path.is_symlink():
        raise CatalogBundleError("BUNDLE_SELECTION_ALREADY_EXISTS")

    python = Path(sys.executable)
    if python.is_symlink() or not python.is_absolute() or not python.is_file() or not os.access(python, os.X_OK):
        raise CatalogBundleError("PINNED_PYTHON_RUNTIME_REQUIRED")
    base_args = [str(python), "-E", "-B", "-m"]
    min_memory = _available_memory_kib()
    catalog_code, observed = _run_stage(
        base_args + ["tools.migracao_pg.catalog", "--defaults-file", str(SOURCE_DEFAULTS), "--output", str(snapshot_path)],
        catalog_log,
        "catalog",
    )
    min_memory = min(min_memory, observed)
    if catalog_code != 0:
        raise CatalogBundleError("CATALOG_CAPTURE_FAILED")
    if not _regular_file(snapshot_path, 0o600):
        raise CatalogBundleError("CATALOG_SNAPSHOT_MODE_INVALID")
    snapshot = _read_json(snapshot_path, "CATALOG_SNAPSHOT_INVALID")
    catalog_counts = _validate_catalog_snapshot(snapshot)

    ddl_code, observed = _run_stage(
        base_args + [
            "tools.migracao_pg.generate_ddl",
            "--snapshot", str(snapshot_path),
            "--application-role", "erp_app",
            "--output-dir", str(staging_bundle_root),
        ],
        ddl_log,
        "ddl",
    )
    min_memory = min(min_memory, observed)
    if ddl_code not in (0, 2):
        raise CatalogBundleError("DDL_GENERATION_FAILED")
    try:
        lines = ddl_log.read_text(encoding="utf-8").splitlines()
        summary = json.loads(lines[-1], object_pairs_hook=_unique_object) if lines else None
    except (OSError, UnicodeError, json.JSONDecodeError, CatalogBundleError) as error:
        raise CatalogBundleError("DDL_GENERATION_SUMMARY_INVALID") from error
    if not isinstance(summary, dict):
        raise CatalogBundleError("DDL_GENERATION_SUMMARY_INVALID")
    bundle_path_value = summary.get("output_directory")
    if not isinstance(bundle_path_value, str):
        raise CatalogBundleError("DDL_OUTPUT_DIRECTORY_INVALID")
    bundle_path = Path(bundle_path_value)
    if bundle_path.parent != staging_bundle_root or bundle_path.is_symlink():
        raise CatalogBundleError("DDL_OUTPUT_DIRECTORY_INVALID")
    bundle_name = bundle_path.name
    if not BUNDLE_NAME_PATTERN.fullmatch(bundle_name):
        raise CatalogBundleError("BUNDLE_NAME_INVALID")
    if summary.get("executable_complete") is not True or summary.get("load_executable_complete") is not True \
        or summary.get("review_items") != 0 or summary.get("blocked_tables") != 0:
        raise CatalogBundleError("BUNDLE_REQUIRES_REVIEW")
    _validate_generator_summary(summary, snapshot)
    _validate_bundle_files(bundle_path)
    report = _read_json(bundle_path / "report.json", "BUNDLE_REPORT_INVALID")
    bundle_counts = _validate_bundle_report(report)
    bundled_snapshot = _read_json(bundle_path / "catalog_snapshot.json", "BUNDLE_SNAPSHOT_INVALID")
    if bundled_snapshot != snapshot:
        raise CatalogBundleError("BUNDLE_SNAPSHOT_MISMATCH")
    if (bundle_path / "BUNDLE_COMPLETE").read_text(encoding="ascii") != "bundle_complete=true\n":
        raise CatalogBundleError("BUNDLE_COMPLETION_MARKER_INVALID")
    if ddl_code != 0:
        raise CatalogBundleError("BUNDLE_REQUIRES_REVIEW")
    previous_bundle_root = loader_module.BUNDLE_ROOT
    loader_module.BUNDLE_ROOT = staging_bundle_root
    try:
        _validated_path, validated_report, validated_snapshot = validate_bundle(bundle_name, require_executable=True)
    except LoaderError as error:
        raise CatalogBundleError(error.code) from error
    finally:
        loader_module.BUNDLE_ROOT = previous_bundle_root
    if validated_report != report or validated_snapshot != snapshot:
        raise CatalogBundleError("BUNDLE_VALIDATION_RESULT_MISMATCH")

    final_bundle = BUNDLE_ROOT / bundle_name
    if final_bundle.exists() or final_bundle.is_symlink():
        raise CatalogBundleError("BUNDLE_OUTPUT_ALREADY_EXISTS")
    try:
        os.rename(bundle_path, final_bundle)
    except OSError as error:
        raise CatalogBundleError("BUNDLE_PROMOTION_FAILED") from error
    _validate_bundle_files(final_bundle)
    try:
        _validated_path, final_report, final_snapshot = validate_bundle(bundle_name, require_executable=True)
    except LoaderError as error:
        raise CatalogBundleError(error.code) from error
    if final_report != report or final_snapshot != snapshot:
        raise CatalogBundleError("BUNDLE_FINAL_VALIDATION_MISMATCH")

    _atomic_selection(selection_path, release_id, bundle_name)
    return {
        "status": "CATALOG_BUNDLE_READY",
        "source_schema": SOURCE_SCHEMA,
        "catalog_version": CATALOG_VERSION,
        "bundle_executable_complete": True,
        "bundle_load_executable_complete": True,
        "catalog": catalog_counts,
        "bundle": bundle_counts,
        "memory_guard_min_available_kib": MIN_AVAILABLE_KIB,
        "minimum_available_kib_observed": min_memory,
        "elapsed_seconds": round(time.monotonic() - capture_started, 3),
        "selection_private": True,
    }


def main() -> int:
    if len(sys.argv) != 1:
        print("CATALOG_BUNDLE_ERROR=ARGUMENTS_NOT_ALLOWED", file=sys.stderr)
        return 64
    try:
        result = capture_catalog_bundle()
    except CatalogBundleError as error:
        print(f"CATALOG_BUNDLE_ERROR={error.code}", file=sys.stderr)
        return 2
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
