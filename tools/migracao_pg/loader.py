from __future__ import annotations

import argparse
import base64
import copy
from configparser import RawConfigParser
from dataclasses import replace
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import tempfile
import threading
import time
from typing import Any

if os.geteuid() == 0 and os.environ.get("ERP_P2_ROOT_LAUNCHER_VERIFIED") != "1":
    raise SystemExit("LOADER_ROOT_EXECUTION_REQUIRES_REBUILD_RUNNER")

from .catalog import CatalogError, SOURCE_GRANT_POLICY_ID, SOURCE_SCHEMA, TIME_RANGE_POLICY_ID, _collector_fingerprint, _mysql_socket_options, _structural_fingerprint, _validate_defaults_file, collect_catalog
from .ddl import GENERATOR_VERSION, LEGACY_SCHEMA, MYSQL_UNSIGNED_BIGINT_MAX, POSTGRES_SEQUENCE_MAX, TARGET_SCHEMA, _schema_for_table, canonical_fingerprint
from .generate_ddl import _generator_fingerprint
from .generated_columns import is_generated_column, target_generated_column
from .type_mapping import (
    TemporalValueError,
    TypeMappingError,
    map_mysql_type,
    normalize_temporal_value,
    parse_mysql_enum,
)

BUNDLE_ROOT = Path("/var/lib/erpcondominios-pg/p2-bundles")
TARGET_DATABASE = "erp_pg_dev"
CUTOVER_TARGET_DATABASE = "erpcondominios_pg"
DEVELOPMENT_MIGRATION_ROLE = "erp_p2_loader"
DEVELOPMENT_APPLICATION_ROLE = "erp_app"
CUTOVER_MIGRATION_ROLE = "erp_p2_cutover_loader"
CUTOVER_APPLICATION_ROLE = "erpcondominios_app"
MIGRATION_TARGET_CONFIGS = {
    "development": Path("/etc/erpcondominios-pg/secrets/p2-loader.cnf"),
    "cutover": Path("/etc/erpcondominios-pg/secrets/p2-loader-cutover.cnf"),
}
CUTOVER_TARGET_APPROVAL_FILE = Path("/etc/erpcondominios-pg/secrets/p2-cutover-target.approved")
CUTOVER_TARGET_EXECUTION_MARKER = Path("/etc/erpcondominios-pg/secrets/p2-cutover-execution.started")
PROTECTED_TARGET_DATABASES = frozenset({
    "erp_pg_dev", "erp_pg_source", "erp_hml_mysql", "erpcondominios", "erpcondominios_stage_20261114",
})
SOURCE_SECRET_ROOT = Path("/etc/erpcondominios/secrets")
REPORT_ROOT = Path("/var/backups/erpcondominios/p2-runs")
MYSQL_BATCH_SIZE = 1
MIN_AVAILABLE_MIB = 1536
MAX_ROW_BYTES = 128 * 1024 * 1024
MEMORY_SAMPLE_INTERVAL_SECONDS = 10
SCRIPT_NAMES = (
    "00_tables.sql",
    "05_on_update_triggers.sql",
    "10_indexes.sql",
    "20_foreign_keys_not_valid.sql",
    "30_post_load_sequences.sql",
    "40_business_triggers.sql",
)


class LoaderError(RuntimeError):
    def __init__(self, code: str, sqlstate: str | None = None) -> None:
        self.code = code
        self.sqlstate = sqlstate if isinstance(sqlstate, str) and re.fullmatch(r"[0-9A-Z]{5}", sqlstate) else None
        super().__init__(code)


def _sqlstate(error: BaseException) -> str | None:
    value = getattr(error, "sqlstate", None) or getattr(error, "pgcode", None)
    return value if isinstance(value, str) and re.fullmatch(r"[0-9A-Z]{5}", value) else None


def _unique_json_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise LoaderError("DUPLICATE_JSON_KEY")
        result[key] = value
    return result


def _nul_aware_json_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    normalized_keys: set[str] = set()
    for key, value in pairs:
        normalized_key = key.replace("\x00", "")
        if normalized_key in normalized_keys:
            raise LoaderError("JSON_NUL_NORMALIZATION_KEY_COLLISION")
        normalized_keys.add(normalized_key)
        result[key] = value
    return result


def _read_json(path: Path) -> tuple[dict[str, Any], bytes]:
    try:
        if path.is_symlink() or not path.is_file():
            raise LoaderError("BUNDLE_FILE_INVALID")
        raw = path.read_bytes()
        payload = json.loads(raw.decode("utf-8"), object_pairs_hook=_unique_json_pairs)
    except LoaderError:
        raise
    except Exception as error:
        raise LoaderError("BUNDLE_JSON_INVALID") from error
    if not isinstance(payload, dict):
        raise LoaderError("BUNDLE_JSON_INVALID")
    return payload, raw


def _bundle_path(bundle_name: str) -> Path:
    if not re.fullmatch(r"catalog-[a-f0-9]{12}-collation-[a-f0-9]{8}-role-[a-f0-9]{8}-code-[a-f0-9]{8}", bundle_name):
        raise LoaderError("BUNDLE_NAME_INVALID")
    root = BUNDLE_ROOT.resolve(strict=True)
    candidate = BUNDLE_ROOT / bundle_name
    if candidate.is_symlink() or not candidate.is_dir():
        raise LoaderError("BUNDLE_DIRECTORY_INVALID")
    resolved = candidate.resolve(strict=True)
    if resolved.parent != root:
        raise LoaderError("BUNDLE_PATH_OUTSIDE_ALLOWLIST")
    return resolved


def _validate_executable_bundle_ownership(bundle: Path) -> None:
    if os.geteuid() != 0:
        raise LoaderError("ROOT_REQUIRED_FOR_EXECUTABLE_BUNDLE")
    current = bundle
    filesystem_root = Path("/")
    while current != filesystem_root:
        try:
            info = current.lstat()
        except OSError as error:
            raise LoaderError("BUNDLE_PATH_UNAVAILABLE") from error
        if (
            not stat.S_ISDIR(info.st_mode)
            or info.st_uid != 0
            or stat.S_IMODE(info.st_mode) & 0o022
            or current.is_symlink()
        ):
            raise LoaderError("BUNDLE_DIRECTORY_NOT_ROOT_CONTROLLED")
        current = current.parent
    root_info = filesystem_root.lstat()
    if not stat.S_ISDIR(root_info.st_mode) or root_info.st_uid != 0:
        raise LoaderError("BUNDLE_DIRECTORY_NOT_ROOT_CONTROLLED")
    for name in ("BUNDLE_COMPLETE", "report.json", "catalog_snapshot.json", *SCRIPT_NAMES):
        path = bundle / name
        try:
            info = path.lstat()
        except OSError as error:
            raise LoaderError("BUNDLE_FILE_UNAVAILABLE") from error
        if (
            not stat.S_ISREG(info.st_mode)
            or info.st_uid != 0
            or stat.S_IMODE(info.st_mode) & 0o022
            or path.is_symlink()
        ):
            raise LoaderError("BUNDLE_FILE_NOT_ROOT_CONTROLLED")


def validate_bundle(bundle_name: str, *, require_executable: bool = True) -> tuple[Path, dict[str, Any], dict[str, Any]]:
    bundle = _bundle_path(bundle_name)
    if require_executable:
        _validate_executable_bundle_ownership(bundle)
    marker = bundle / "BUNDLE_COMPLETE"
    if marker.is_symlink() or not marker.is_file() or marker.read_text(encoding="utf-8") != "bundle_complete=true\n":
        raise LoaderError("BUNDLE_COMPLETION_MARKER_INVALID")

    report, _ = _read_json(bundle / "report.json")
    snapshot, snapshot_raw = _read_json(bundle / "catalog_snapshot.json")
    if require_executable and report.get("load_executable_complete") is not True:
        raise LoaderError("BUNDLE_REQUIRES_REVIEW")
    if require_executable and report.get("executable_complete") is not True:
        raise LoaderError("BUNDLE_EXECUTION_INCOMPLETE")
    if report.get("source_schema") != SOURCE_SCHEMA or snapshot.get("source_schema") != SOURCE_SCHEMA:
        raise LoaderError("BUNDLE_SOURCE_SCHEMA_MISMATCH")
    if report.get("target_schema") != TARGET_SCHEMA:
        raise LoaderError("BUNDLE_TARGET_SCHEMA_MISMATCH")
    if snapshot.get("catalog_version") != 9 or snapshot.get("time_range_policy_id") != TIME_RANGE_POLICY_ID \
        or canonical_fingerprint(snapshot) != snapshot.get("fingerprint_sha256"):
        raise LoaderError("BUNDLE_CATALOG_FINGERPRINT_MISMATCH")
    if report.get("catalog_fingerprint_sha256") != snapshot.get("fingerprint_sha256"):
        raise LoaderError("BUNDLE_REPORT_CATALOG_MISMATCH")
    if snapshot.get("collector_code_sha256") != _collector_fingerprint():
        raise LoaderError("BUNDLE_COLLECTOR_CODE_MISMATCH")
    if snapshot.get("structural_fingerprint_sha256") != _structural_fingerprint(snapshot):
        raise LoaderError("BUNDLE_STRUCTURAL_FINGERPRINT_MISMATCH")
    if snapshot.get("source_grant_policy_id") != SOURCE_GRANT_POLICY_ID \
        or snapshot.get("source_grants_policy_accepted") is not True \
        or snapshot.get("source_routines_events_zero_verified") is not True \
        or snapshot.get("source_schema_wide_select") is not True:
        raise LoaderError("BUNDLE_SOURCE_GRANT_PROOF_MISSING")
    visibility = snapshot.get("metadata_visibility")
    if not isinstance(visibility, dict) \
        or any(visibility.get(key) is not True for key in ("views", "triggers")) \
        or any(visibility.get(key) is not False for key in ("routines", "events")) \
        or snapshot.get("source_routines_events_zero_verified") is not True \
        or snapshot.get("routines") != [] or snapshot.get("events") != []:
        raise LoaderError("BUNDLE_METADATA_VISIBILITY_UNPROVEN")
    if report.get("generator_version") != GENERATOR_VERSION:
        raise LoaderError("BUNDLE_GENERATOR_VERSION_MISMATCH")
    if report.get("generator_code_sha256") != _generator_fingerprint():
        raise LoaderError("BUNDLE_GENERATOR_CODE_MISMATCH")
    if report.get("snapshot_file_sha256") != hashlib.sha256(snapshot_raw).hexdigest():
        raise LoaderError("BUNDLE_SNAPSHOT_CHECKSUM_MISMATCH")

    script_hashes = report.get("generated_scripts_sha256")
    if not isinstance(script_hashes, dict) or set(script_hashes) != set(SCRIPT_NAMES):
        raise LoaderError("BUNDLE_SCRIPT_MANIFEST_INVALID")
    for name in SCRIPT_NAMES:
        path = bundle / name
        if path.is_symlink() or not path.is_file():
            raise LoaderError("BUNDLE_SCRIPT_MISSING")
        raw = path.read_bytes()
        if hashlib.sha256(raw).hexdigest() != script_hashes[name]:
            raise LoaderError("BUNDLE_SCRIPT_CHECKSUM_MISMATCH")
        if not raw.startswith(b"-- LOAD ARTIFACT: DO NOT APPLY unless report.json has executable_complete=true and all migration reviews are approved.\n"):
            raise LoaderError("BUNDLE_SCRIPT_SAFETY_HEADER_MISSING")
        expected_header = f"-- Generator code SHA-256: {report['generator_code_sha256']}\n".encode("ascii")
        if expected_header not in raw[:512]:
            raise LoaderError("BUNDLE_SCRIPT_GENERATOR_MISMATCH")

    tables = snapshot.get("tables")
    generated_tables = report.get("generated_tables")
    if not isinstance(tables, list) or not isinstance(generated_tables, list):
        raise LoaderError("BUNDLE_TABLE_MANIFEST_INVALID")
    base_tables = [item.get("name") for item in tables if isinstance(item, dict) and item.get("type") == "BASE TABLE"]
    if len(base_tables) != len(set(base_tables)) or len(generated_tables) != len(set(generated_tables)):
        raise LoaderError("BUNDLE_TABLE_MANIFEST_DUPLICATE")
    if require_executable and sorted(base_tables) != sorted(generated_tables):
        raise LoaderError("BUNDLE_TABLE_MANIFEST_INCOMPLETE")
    if not set(generated_tables).issubset(set(base_tables)):
        raise LoaderError("BUNDLE_TABLE_MANIFEST_INVALID")
    return bundle, report, snapshot


def _read_available_memory_mib() -> int:
    try:
        for line in Path("/proc/meminfo").read_text(encoding="ascii").splitlines():
            if line.startswith("MemAvailable:"):
                return int(line.split()[1]) // 1024
    except Exception as error:
        raise LoaderError("MEMORY_STATUS_UNAVAILABLE") from error
    raise LoaderError("MEMORY_STATUS_UNAVAILABLE")


def _available_memory_mib_or_none() -> int | None:
    try:
        return _read_available_memory_mib()
    except Exception:
        return None


def _require_load_runtime() -> None:
    if os.geteuid() != 0:
        raise LoaderError("ROOT_REQUIRED_FOR_PRIVATE_SOURCE_CREDENTIAL")
    if _read_available_memory_mib() < MIN_AVAILABLE_MIB:
        raise LoaderError("MEMORY_PRECHECK_BELOW_1536_MIB")
    try:
        import importlib.metadata as metadata
        if metadata.version("PyMySQL") != "1.1.2":
            raise LoaderError("PYMYSQL_VERSION_MISMATCH")
        if metadata.version("psycopg") != "3.3.8":
            raise LoaderError("PSYCOPG_VERSION_MISMATCH")
    except LoaderError:
        raise
    except Exception as error:
        raise LoaderError("PINNED_DEPENDENCIES_REQUIRED") from error


class _MemoryGuard:
    def __init__(self) -> None:
        self.low_since: float | None = None
        self.last_sampled_at: float | None = None
        self._state_lock = threading.Lock()
        self._stop_event = threading.Event()
        self._limit_event = threading.Event()
        self._monitor_error = threading.Event()
        self._connection: Any = None
        self._monitor_thread: threading.Thread | None = None

    def sample(self) -> int:
        self._raise_if_stopped()
        available = _read_available_memory_mib()
        now = time.monotonic()
        self._observe(available, now)
        return available

    def sample_if_due(self) -> int | None:
        with self._state_lock:
            due = self.last_sampled_at is None or time.monotonic() - self.last_sampled_at >= MEMORY_SAMPLE_INTERVAL_SECONDS
        if due:
            return self.sample()
        return None

    def _observe(self, available: int, now: float) -> None:
        with self._state_lock:
            self.last_sampled_at = now
            if available >= MIN_AVAILABLE_MIB:
                self.low_since = None
            elif self.low_since is None:
                self.low_since = now
            elif now - self.low_since >= 300:
                self._limit_event.set()
        self._raise_if_stopped()

    def _raise_if_stopped(self) -> None:
        if self._monitor_error.is_set():
            raise LoaderError("MEMORY_STATUS_UNAVAILABLE")
        if self._limit_event.is_set():
            raise LoaderError("MEMORY_BELOW_1536_MIB_FOR_5_MINUTES")

    def start_monitor(self, connection: Any) -> None:
        self._connection = connection
        self._stop_event.clear()
        self._monitor_error.clear()
        self._monitor_thread = threading.Thread(target=self._monitor, name="erp-p2-memory-guard", daemon=True)
        self._monitor_thread.start()

    def set_connection(self, connection: Any) -> None:
        self._connection = connection

    def stop_monitor(self) -> None:
        self._stop_event.set()
        thread = self._monitor_thread
        if thread is not None:
            thread.join(timeout=10)
        self._connection = None
        self._monitor_thread = None

    def _monitor(self) -> None:
        while not self._stop_event.wait(MEMORY_SAMPLE_INTERVAL_SECONDS):
            try:
                available = _read_available_memory_mib()
                self._observe(available, time.monotonic())
            except LoaderError as error:
                if error.code == "MEMORY_BELOW_1536_MIB_FOR_5_MINUTES":
                    self._cancel_active_query()
                    return
                self._monitor_error.set()
                self._cancel_active_query()
                return
            except Exception:
                self._monitor_error.set()
                self._cancel_active_query()
                return

    def _cancel_active_query(self) -> None:
        connection = self._connection
        if connection is not None:
            try:
                cancel = getattr(connection, "cancel_safe", None)
                if callable(cancel):
                    cancel(timeout=5)
                else:
                    close = getattr(connection, "close", None)
                    if callable(close):
                        close()
            except Exception:
                pass


def _source_defaults_path(argument: str | None = None) -> Path:
    raw_path = argument or os.environ.get("ERP_P2_SOURCE_DEFAULTS_FILE", "")
    if not raw_path:
        raise LoaderError("SOURCE_DEFAULTS_FILE_REQUIRED")
    candidate = Path(raw_path)
    if not candidate.is_absolute() or candidate.is_symlink():
        raise LoaderError("SOURCE_DEFAULTS_FILE_PATH_INVALID")
    try:
        root = SOURCE_SECRET_ROOT.resolve(strict=True)
        if root != SOURCE_SECRET_ROOT:
            raise LoaderError("SOURCE_DEFAULTS_PATH_CHAIN_UNSAFE")
        root_info = root.lstat()
        if not stat.S_ISDIR(root_info.st_mode) or root_info.st_uid != 0 or stat.S_IMODE(root_info.st_mode) & 0o022:
            raise LoaderError("SOURCE_DEFAULTS_PATH_CHAIN_UNSAFE")
        resolved = candidate.resolve(strict=True)
        if resolved != candidate:
            raise LoaderError("SOURCE_DEFAULTS_PATH_CHAIN_UNSAFE")
        resolved.relative_to(root)
        current = candidate
        while current != root:
            info = current.lstat()
            if current.is_symlink() or info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o022:
                raise LoaderError("SOURCE_DEFAULTS_PATH_CHAIN_UNSAFE")
            current = current.parent
    except Exception as error:
        if isinstance(error, LoaderError):
            raise
        raise LoaderError("SOURCE_DEFAULTS_FILE_OUTSIDE_ALLOWLIST") from error
    try:
        _validate_defaults_file(resolved)
        _mysql_socket_options(resolved)
    except CatalogError as error:
        raise LoaderError(error.code) from error
    return resolved


def _connect_source(defaults_file: Path):
    try:
        import pymysql
    except ImportError as error:
        raise LoaderError("PYMYSQL_REQUIRED") from error
    converters = pymysql.converters.conversions.copy()
    for type_name in ("DATE", "NEWDATE", "DATETIME", "DATETIME2", "TIMESTAMP", "TIMESTAMP2", "TIME", "TIME2"):
        field_type = getattr(pymysql.constants.FIELD_TYPE, type_name, None)
        if field_type is not None:
            converters[field_type] = lambda value: value
    try:
        connection = pymysql.connect(
            read_default_file=str(defaults_file),
            read_default_group="client",
            database=SOURCE_SCHEMA,
            charset="utf8mb4",
            autocommit=True,
            cursorclass=pymysql.cursors.DictCursor,
            connect_timeout=10,
            read_timeout=60,
            write_timeout=30,
            conv=converters,
            **_mysql_socket_options(defaults_file),
        )
    except Exception as error:
        raise LoaderError("MYSQL_SOURCE_CONNECTION_FAILED") from error
    try:
        with connection.cursor(pymysql.cursors.Cursor) as cursor:
            cursor.execute("SELECT CURRENT_USER(), DATABASE(), @@version")
            row = cursor.fetchone()
            if not row or str(row[0]) != "pg_reader@localhost" or str(row[1]) != SOURCE_SCHEMA:
                raise LoaderError("MYSQL_SOURCE_IDENTITY_OR_DATABASE_MISMATCH")
            cursor.execute("SET SESSION time_zone = '+00:00'")
            cursor.execute("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ")
        current_snapshot = collect_catalog(connection, keep_snapshot=True)
        from pymysql.constants import SERVER_STATUS
        if not (int(getattr(connection, "server_status", 0)) & SERVER_STATUS.SERVER_STATUS_IN_TRANS):
            raise LoaderError("MYSQL_SOURCE_CONSISTENT_SNAPSHOT_NOT_ACTIVE")
        return connection, current_snapshot
    except LoaderError:
        connection.close()
        raise
    except CatalogError as error:
        connection.close()
        raise LoaderError(error.code) from error
    except Exception as error:
        connection.close()
        raise LoaderError("MYSQL_SOURCE_PREFLIGHT_FAILED") from error


def _validate_target_config(config: dict[str, Any], profile: str = "development") -> dict[str, Any]:
    expected_keys = {"host", "port", "dbname", "user", "password", "schema", "timezone", "application_user"}
    if not isinstance(config, dict) or set(config) != expected_keys:
        raise LoaderError("TARGET_CONFIG_SHAPE_INVALID")
    if profile == "development":
        database_allowed = config["dbname"] == TARGET_DATABASE
        expected_user = DEVELOPMENT_MIGRATION_ROLE
        expected_application_user = DEVELOPMENT_APPLICATION_ROLE
    elif profile == "cutover":
        database_allowed = config["dbname"] == CUTOVER_TARGET_DATABASE
        expected_user = CUTOVER_MIGRATION_ROLE
        expected_application_user = CUTOVER_APPLICATION_ROLE
    else:
        raise LoaderError("TARGET_PROFILE_NOT_ALLOWLISTED")
    if not database_allowed or config["schema"] != TARGET_SCHEMA:
        raise LoaderError("TARGET_CONFIG_SCOPE_MISMATCH")
    if config["host"] not in {"localhost", "127.0.0.1", "::1", "/run/postgresql", "/var/run/postgresql"}:
        raise LoaderError("TARGET_HOST_MUST_BE_LOCAL")
    if isinstance(config["port"], bool) or not isinstance(config["port"], (int, str)):
        raise LoaderError("TARGET_PORT_INVALID")
    if isinstance(config["port"], str) and not re.fullmatch(r"[0-9]{1,5}", config["port"]):
        raise LoaderError("TARGET_PORT_INVALID")
    config["port"] = int(config["port"])
    if not 1 <= config["port"] <= 65535:
        raise LoaderError("TARGET_PORT_INVALID")
    if config["user"] != expected_user or config["application_user"] != expected_application_user:
        raise LoaderError("TARGET_ROLE_SEPARATION_REQUIRED")
    if not isinstance(config["password"], str) or not config["password"] or "\x00" in config["password"]:
        raise LoaderError("TARGET_CREDENTIAL_MISSING")
    if config["timezone"] != "UTC":
        raise LoaderError("TARGET_TIMEZONE_MISMATCH")
    return config


def _load_target_config(profile: str = "development") -> dict[str, Any]:
    if profile not in MIGRATION_TARGET_CONFIGS:
        raise LoaderError("TARGET_PROFILE_NOT_ALLOWLISTED")
    path = MIGRATION_TARGET_CONFIGS[profile]
    for directory in (path.parent, *path.parent.parents):
        if directory == Path("/"):
            break
        try:
            parent_info = directory.lstat()
        except OSError as error:
            raise LoaderError("TARGET_CONFIG_DIRECTORY_INVALID") from error
        if not stat.S_ISDIR(parent_info.st_mode) or parent_info.st_uid != 0 \
            or stat.S_IMODE(parent_info.st_mode) & 0o022 or directory.is_symlink():
            raise LoaderError("TARGET_CONFIG_DIRECTORY_INVALID")
    try:
        fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0))
    except OSError as error:
        raise LoaderError("TARGET_CONFIG_UNAVAILABLE") from error
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o600:
            raise LoaderError("TARGET_CONFIG_MODE_OR_OWNER_INVALID")
        parser = RawConfigParser(interpolation=None, strict=True, delimiters=("=",), inline_comment_prefixes=None)
        with os.fdopen(fd, "r", encoding="utf-8", errors="strict") as stream:
            fd = -1
            parser.read_file(stream)
    except LoaderError:
        raise
    except Exception as error:
        raise LoaderError("TARGET_CONFIG_PARSE_FAILED") from error
    finally:
        if fd >= 0:
            os.close(fd)
    if parser.defaults() or parser.sections() != ["postgresql"]:
        raise LoaderError("TARGET_CONFIG_SHAPE_INVALID")
    config = dict(parser.items("postgresql", raw=True))
    if profile == "cutover":
        try:
            approval_info = CUTOVER_TARGET_APPROVAL_FILE.lstat()
            if not stat.S_ISREG(approval_info.st_mode) or approval_info.st_uid != 0 \
                or stat.S_IMODE(approval_info.st_mode) != 0o600 \
                or CUTOVER_TARGET_APPROVAL_FILE.is_symlink():
                raise LoaderError("CUTOVER_TARGET_APPROVAL_INVALID")
            approved_database = CUTOVER_TARGET_APPROVAL_FILE.read_text(encoding="ascii").strip()
        except LoaderError:
            raise
        except Exception as error:
            raise LoaderError("CUTOVER_TARGET_APPROVAL_UNAVAILABLE") from error
        if approved_database != config.get("dbname"):
            raise LoaderError("CUTOVER_TARGET_NOT_APPROVED")
    return _validate_target_config(config, profile)


def _consume_cutover_execution_marker(database: str) -> None:
    if os.geteuid() != 0:
        raise LoaderError("CUTOVER_EXECUTION_MARKER_ROOT_REQUIRED")
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
    try:
        fd = os.open(CUTOVER_TARGET_EXECUTION_MARKER, flags, 0o600)
    except FileExistsError as error:
        raise LoaderError("CUTOVER_EXECUTION_ALREADY_CONSUMED") from error
    except OSError as error:
        raise LoaderError("CUTOVER_EXECUTION_MARKER_CREATE_FAILED") from error
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o600:
            raise LoaderError("CUTOVER_EXECUTION_MARKER_MODE_INVALID")
        payload = (database + "\n").encode("ascii", errors="strict")
        remaining = memoryview(payload)
        while remaining:
            written = os.write(fd, remaining)
            if written <= 0:
                raise LoaderError("CUTOVER_EXECUTION_MARKER_WRITE_FAILED")
            remaining = remaining[written:]
        os.fsync(fd)
    except LoaderError:
        raise
    except Exception as error:
        raise LoaderError("CUTOVER_EXECUTION_MARKER_WRITE_FAILED") from error
    finally:
        os.close(fd)


def _connect_target(config: dict[str, Any]):
    try:
        import psycopg
    except ImportError as error:
        raise LoaderError("PSYCOPG_REQUIRED") from error
    try:
        return psycopg.connect(
            host=config["host"],
            port=config["port"],
            dbname=config["dbname"],
            user=config["user"],
            password=config["password"],
            connect_timeout=10,
            application_name=(
                "erp-p2-cutover-rebuild"
                if config.get("dbname") == CUTOVER_TARGET_DATABASE
                else "erp-p2-development-rebuild"
            ),
            autocommit=True,
        )
    except Exception as error:
        raise LoaderError("POSTGRES_TARGET_CONNECTION_FAILED", _sqlstate(error)) from error


def _target_preflight(
    connection: Any,
    expected_user: str = "erp_p2_loader",
    application_user: str = "erp_app",
    expected_database: str = TARGET_DATABASE,
) -> dict[str, Any]:
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                """SELECT current_database(), current_user,
                          COALESCE((SELECT rolsuper FROM pg_roles WHERE rolname=current_user), TRUE),
                          COALESCE((SELECT rolcreatedb FROM pg_roles WHERE rolname=current_user), TRUE),
                          COALESCE((SELECT rolcreaterole FROM pg_roles WHERE rolname=current_user), TRUE),
                          COALESCE((SELECT rolreplication FROM pg_roles WHERE rolname=current_user), TRUE),
                          COALESCE((SELECT rolbypassrls FROM pg_roles WHERE rolname=current_user), TRUE),
                          COALESCE((SELECT rolcanlogin FROM pg_roles WHERE rolname=current_user), FALSE),
                          has_database_privilege(current_user, current_database(), 'CREATE'),
                          (SELECT nspowner = (SELECT oid FROM pg_roles WHERE rolname=current_user)
                             FROM pg_namespace WHERE nspname = %s),
                          (SELECT nspowner = (SELECT oid FROM pg_roles WHERE rolname=current_user)
                             FROM pg_namespace WHERE nspname = %s),
                          EXISTS(SELECT 1 FROM pg_roles WHERE rolname = %s),
                          COALESCE((SELECT rolsuper FROM pg_roles WHERE rolname = %s), TRUE),
                          COALESCE((SELECT rolcreatedb FROM pg_roles WHERE rolname = %s), TRUE),
                          COALESCE((SELECT rolcreaterole FROM pg_roles WHERE rolname = %s), TRUE),
                          COALESCE((SELECT rolreplication FROM pg_roles WHERE rolname = %s), TRUE),
                          COALESCE((SELECT rolbypassrls FROM pg_roles WHERE rolname = %s), TRUE),
                          (SELECT count(*) FROM pg_auth_members WHERE member =
                             (SELECT oid FROM pg_roles WHERE rolname = %s)),
                          (SELECT count(*) FROM pg_auth_members WHERE member =
                             (SELECT oid FROM pg_roles WHERE rolname = current_user))""",
                (TARGET_SCHEMA, LEGACY_SCHEMA, application_user, application_user, application_user,
                 application_user, application_user, application_user, application_user),
            )
            row = cursor.fetchone()
    except Exception as error:
        raise LoaderError("POSTGRES_TARGET_PREFLIGHT_FAILED", _sqlstate(error)) from error
    if not row or len(row) != 19:
        raise LoaderError("POSTGRES_TARGET_PREFLIGHT_INVALID")
    if row[0] != expected_database:
        raise LoaderError("POSTGRES_TARGET_DATABASE_MISMATCH")
    if row[1] != expected_user:
        raise LoaderError("POSTGRES_TARGET_ROLE_MISMATCH")
    if row[1] == application_user:
        raise LoaderError("TARGET_ROLE_SEPARATION_REQUIRED")
    if not bool(row[11]):
        raise LoaderError("TARGET_APPLICATION_ROLE_MISSING")
    if any(bool(value) for value in row[12:17]):
        raise LoaderError("TARGET_APPLICATION_ROLE_PRIVILEGE_INVALID")
    if int(row[17]) != 0:
        raise LoaderError("TARGET_APPLICATION_ROLE_MEMBERSHIP_FORBIDDEN")
    if int(row[18]) != 0:
        raise LoaderError("TARGET_MIGRATOR_ROLE_MEMBERSHIP_FORBIDDEN")
    if any(bool(row[index]) for index in (2, 3, 4, 5, 6)) or not bool(row[7]):
        raise LoaderError("TARGET_MIGRATOR_PRIVILEGE_INVALID")
    can_create_schema = bool(row[8])
    owns_schemas = all(bool(value) for value in row[9:11] if value is not None)
    if not can_create_schema:
        raise LoaderError("POSTGRES_TARGET_CREATE_SCHEMA_PRIVILEGE_REQUIRED")
    if not owns_schemas:
        raise LoaderError("POSTGRES_TARGET_SCHEMA_OWNER_REQUIRED")
    return {
        "database_matches": True,
        "local_endpoint": True,
        "migrator_login_non_elevated": True,
        "migrator_role_memberships": 0,
        "schema_create_privilege": can_create_schema,
        "existing_schemas_owned_or_absent": owns_schemas,
        "application_role_separate": True,
        "application_role_non_elevated": True,
        "application_role_memberships": 0,
    }


def _verify_application_legacy_isolation(connection: Any, application_user: str) -> None:
    query = """
        SELECT has_schema_privilege(%s, 'legado', 'USAGE'),
               has_schema_privilege(%s, 'legado', 'CREATE'),
               has_table_privilege(%s, 'legado.auditoria_nul', 'SELECT'),
               has_table_privilege(%s, 'legado.auditoria_nul', 'INSERT'),
               has_table_privilege(%s, 'legado.auditoria_nul', 'UPDATE'),
               has_table_privilege(%s, 'legado.auditoria_nul', 'DELETE'),
               has_table_privilege(%s, 'legado.auditoria_nul', 'TRUNCATE'),
               has_table_privilege(%s, 'legado.auditoria_nul', 'REFERENCES'),
               has_table_privilege(%s, 'legado.auditoria_nul', 'TRIGGER')
    """
    try:
        with connection.cursor() as cursor:
            cursor.execute(query, (application_user,) * 9)
            row = cursor.fetchone()
    except Exception as error:
        raise LoaderError("TARGET_LEGACY_PRIVILEGE_CHECK_FAILED", _sqlstate(error)) from error
    if not row or len(row) != 9 or any(value is None or bool(value) for value in row):
        raise LoaderError("TARGET_RUNTIME_ACCESS_TO_LEGACY_DETECTED")


def _row_count(connection: Any, table: str, schema: str = TARGET_SCHEMA) -> int:
    from psycopg import sql
    if schema not in {TARGET_SCHEMA, LEGACY_SCHEMA}:
        raise LoaderError("TARGET_SCHEMA_NOT_ALLOWLISTED")
    try:
        with connection.cursor() as cursor:
            cursor.execute(sql.SQL("SELECT count(*) FROM {}.{}").format(sql.Identifier(schema), sql.Identifier(table)))
            result = cursor.fetchone()
    except Exception as error:
        raise LoaderError("POSTGRES_TARGET_COUNT_FAILED", _sqlstate(error)) from error
    if not result or isinstance(result[0], bool) or not isinstance(result[0], int):
        raise LoaderError("POSTGRES_TARGET_COUNT_INVALID")
    return result[0]


def _source_row_count(connection: Any, table: str) -> int:
    import pymysql

    try:
        with connection.cursor(pymysql.cursors.Cursor) as cursor:
            cursor.execute(f"SELECT COUNT(*) FROM {_mysql_ident(SOURCE_SCHEMA)}.{_mysql_ident(table)}")
            row = cursor.fetchone()
    except Exception as error:
        raise LoaderError("SOURCE_TABLE_COUNT_FAILED", _sqlstate(error)) from error
    if not row or isinstance(row[0], bool) or not isinstance(row[0], int) or row[0] < 0:
        raise LoaderError("SOURCE_TABLE_COUNT_INVALID")
    return row[0]


def _source_count_value(row: Any, error_code: str = "SOURCE_ROW_COUNT_INVALID") -> int:
    value = row.get("row_count") if isinstance(row, dict) else row[0] if isinstance(row, (tuple, list)) and len(row) == 1 else None
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        raise LoaderError(error_code)
    return value


def _source_value_bytes(value: Any, column: dict[str, Any]) -> bytes:
    if value is None:
        return b"N"
    if isinstance(value, (bytes, bytearray, memoryview)):
        raw = bytes(value)
    elif isinstance(value, str):
        raw = value.encode(_source_text_codec(column.get("character_set")), errors="strict")
    elif hasattr(value, "isoformat") and callable(value.isoformat):
        raw = value.isoformat().encode("utf-8")
    else:
        raw = str(value).encode("utf-8")
    return type(value).__name__.encode("ascii", errors="replace") + b":" + raw


def _audit_pk_text(table: dict[str, Any], columns: list[dict[str, Any]], source_row: Any) -> str:
    pk = table.get("primary_key", [])
    ordered_pk = sorted(pk, key=lambda item: int(item.get("ordinal", 0))) if pk else []
    positions = {str(column["name"]): index for index, column in enumerate(columns)}
    if not ordered_pk:
        digest = hashlib.sha256()
        for value, column in zip(source_row, columns, strict=True):
            raw = _source_value_bytes(value, column)
            digest.update(len(raw).to_bytes(8, "big"))
            digest.update(raw)
        return json.dumps({"source_row_sha256": digest.hexdigest()}, sort_keys=True, separators=(",", ":"))
    values: dict[str, Any] = {}
    for item in ordered_pk:
        name = item.get("column")
        if not isinstance(name, str) or name not in positions:
            raise LoaderError("AUDIT_PRIMARY_KEY_METADATA_INVALID")
        raw = _source_value_bytes(source_row[positions[name]], columns[positions[name]])
        values[name] = {"source_value_base64": base64.b64encode(raw).decode("ascii")}
    return json.dumps(values, ensure_ascii=True, sort_keys=True, separators=(",", ":"))


def _mysql_ident(identifier: str) -> str:
    if not isinstance(identifier, str) or not identifier or "\x00" in identifier:
        raise LoaderError("MYSQL_IDENTIFIER_INVALID")
    return "`" + identifier.replace("`", "``") + "`"


def _source_non_null_cell_count(source: Any, table_name: str, column_name: str) -> int:
    import pymysql

    try:
        with source.cursor(pymysql.cursors.Cursor) as cursor:
            cursor.execute(
                f"SELECT COUNT(*) FROM {_mysql_ident(SOURCE_SCHEMA)}.{_mysql_ident(table_name)} "
                f"WHERE {_mysql_ident(column_name)} IS NOT NULL"
            )
            row = cursor.fetchone()
    except Exception as error:
        raise LoaderError("SOURCE_TEXT_CELL_COUNT_FAILED", _sqlstate(error)) from error
    if not row or len(row) != 1 or isinstance(row[0], bool) or not isinstance(row[0], int) or row[0] < 0:
        raise LoaderError("SOURCE_TEXT_CELL_COUNT_INVALID")
    return row[0]


def _scan_source_text_policy(source: Any, snapshot: dict[str, Any], memory_guard: _MemoryGuard) -> list[dict[str, Any]]:
    import pymysql

    text_types = {"char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set", "json"}
    findings: list[dict[str, Any]] = []
    for table in snapshot.get("tables", []):
        if table.get("type") != "BASE TABLE":
            continue
        table_name = str(table.get("name", ""))
        for column in table.get("columns", []):
            data_type = str(column.get("data_type", "")).lower()
            if data_type not in text_types:
                continue
            column_name = str(column.get("name", ""))
            character_set = column.get("character_set")
            predicate = f"{_mysql_ident(column_name)} IS NOT NULL"
            query = (
                f"SELECT CAST({_mysql_ident(column_name)} AS BINARY) "
                f"FROM {_mysql_ident(SOURCE_SCHEMA)}.{_mysql_ident(table_name)} WHERE {predicate}"
            )
            counts = {
                "table": table_name,
                "column": column_name,
                "character_set": column.get("character_set"),
                "collation": column.get("source_collation"),
                "name_suggests_sensitive": bool(re.search(
                    r"(?:token|hash|sha|password|senha|signature|assinatura|payload|secret|chave|key|hmac|slug|uuid|path|caminho|file|arquivo)",
                    column_name,
                    re.IGNORECASE,
                )),
                "cells_scanned": 0,
                "method_a_python_string_cells_with_nul": 0,
                "method_a_decode_failures": 0,
                "method_b_cells_with_nul": 0,
                "python_cells_with_nul": 0,
                "invalid_charset_cells_with_nul": 0,
                "cells_with_nul": 0,
                "nul_bytes": 0,
                "only_end": 0,
                "only_beginning": 0,
                "in_middle": 0,
                "entirely_nul": 0,
                "mixed_edges": 0,
                "json_escaped_nul_cells": 0,
                "non_utf8_cells": 0,
                "non_utf8_cells_with_nul": 0,
                "invalid_utf8_cells_after_nul": 0,
                "invalid_declared_charset_cells_after_nul": 0,
                "detector_disagreement": False,
                "scan_status": "SCANNED",
            }
            if isinstance(character_set, str) and character_set.lower() == "binary":
                try:
                    counts["cells_expected"] = _source_non_null_cell_count(source, table_name, column_name)
                except LoaderError as error:
                    if error.code.startswith("MEMORY_"):
                        raise
                    counts["scan_status"] = "FAILED"
                    counts["scan_failure_code"] = error.code
                    findings.append(counts)
                    continue
                except Exception:
                    counts["scan_status"] = "FAILED"
                    counts["scan_failure_code"] = "SOURCE_TEXT_CELL_COUNT_FAILED"
                    findings.append(counts)
                    continue
                counts["scan_status"] = "BINARY_CHARSET_REQUIRES_REVIEW"
                findings.append(counts)
                continue
            try:
                codec = _source_text_codec(character_set)
            except LoaderError:
                try:
                    counts["cells_expected"] = _source_non_null_cell_count(source, table_name, column_name)
                except LoaderError as error:
                    if error.code.startswith("MEMORY_"):
                        raise
                    counts["scan_status"] = "FAILED"
                    counts["scan_failure_code"] = error.code
                    findings.append(counts)
                    continue
                except Exception:
                    counts["scan_status"] = "FAILED"
                    counts["scan_failure_code"] = "SOURCE_TEXT_CELL_COUNT_FAILED"
                    findings.append(counts)
                    continue
                counts["scan_status"] = "UNSUPPORTED_CHARSET_REQUIRES_REVIEW"
                findings.append(counts)
                continue
            try:
                with source.cursor(pymysql.cursors.Cursor) as aggregate_cursor:
                    aggregate_cursor.execute(
                        f"SELECT COUNT(*), COALESCE(SUM(INSTR(CAST(CONVERT({_mysql_ident(column_name)} USING utf8mb4) AS BINARY), 0x00) > 0), 0) "
                        f"FROM {_mysql_ident(SOURCE_SCHEMA)}.{_mysql_ident(table_name)} WHERE {predicate}"
                    )
                    aggregate_row = aggregate_cursor.fetchone()
                if not aggregate_row or len(aggregate_row) != 2:
                    raise LoaderError("SOURCE_TEXT_POLICY_AGGREGATE_INVALID")
                expected_cells = int(aggregate_row[0])
                counts["cells_expected"] = expected_cells
                counts["method_b_cells_with_nul"] = int(aggregate_row[1])
                with source.cursor(pymysql.cursors.SSCursor) as cursor:
                    cursor.execute(query)
                    while True:
                        rows = cursor.fetchmany(MYSQL_BATCH_SIZE)
                        if not rows:
                            break
                        for (value,) in rows:
                            memory_guard.sample_if_due()
                            if not isinstance(value, (bytes, bytearray, memoryview)):
                                raise LoaderError("SOURCE_TEXT_BINARY_CAST_INVALID")
                            raw = bytes(value)
                            counts["cells_scanned"] += 1
                            try:
                                decoded = raw.decode(codec, errors="strict")
                            except (UnicodeDecodeError, LookupError):
                                counts["method_a_decode_failures"] += 1
                                counts["invalid_declared_charset_cells_after_nul"] += 1
                                if raw:
                                    try:
                                        raw.decode("utf-8", errors="strict")
                                    except UnicodeDecodeError:
                                        counts["non_utf8_cells"] += 1
                                continue
                            nul_count = decoded.count("\x00")
                            if nul_count:
                                normalized_text = decoded.replace("\x00", "")
                                try:
                                    removed_bytes = len(raw) - len(normalized_text.encode(codec, errors="strict"))
                                except (UnicodeEncodeError, LookupError) as error:
                                    raise LoaderError("SOURCE_TEXT_ENCODING_FAILED") from error
                                counts["python_cells_with_nul"] += 1
                                counts["cells_with_nul"] += 1
                                counts["nul_bytes"] += removed_bytes
                                position = _nul_position(decoded)
                                position_key = {
                                    "end_only": "only_end", "beginning_only": "only_beginning",
                                    "middle": "in_middle", "entirely_nul": "entirely_nul", "mixed_edges": "mixed_edges",
                                }.get(position)
                                if position_key:
                                    counts[position_key] += 1
                            try:
                                raw.decode("utf-8", errors="strict")
                            except UnicodeDecodeError:
                                counts["non_utf8_cells"] += 1
                                if nul_count:
                                    counts["non_utf8_cells_with_nul"] += 1
                                counts["invalid_utf8_cells_after_nul"] += 1
                            if nul_count:
                                counts["method_a_python_string_cells_with_nul"] += 1
                            candidate = decoded.replace("\x00", "")
                            if data_type == "json":
                                try:
                                    parsed = json.loads(
                                        decoded.replace("\x00", "\\u0000"),
                                        object_pairs_hook=_nul_aware_json_pairs,
                                    )
                                except (TypeError, ValueError) as error:
                                    raise LoaderError("SOURCE_JSON_INVALID") from error
                                if _json_contains_nul(parsed):
                                    _remove_json_nul(parsed)
                                    counts["json_escaped_nul_cells"] += 1
                counts["detector_disagreement"] = (
                    counts["cells_scanned"] != expected_cells
                    or counts["method_a_python_string_cells_with_nul"] != counts["python_cells_with_nul"]
                    or counts["method_b_cells_with_nul"] != counts["python_cells_with_nul"]
                )
            except LoaderError as error:
                if error.code.startswith("MEMORY_"):
                    raise
                counts["scan_status"] = "FAILED"
                counts["scan_failure_code"] = error.code
                findings.append(counts)
                continue
            except Exception as error:
                counts["scan_status"] = "FAILED"
                counts["scan_failure_code"] = "SOURCE_TEXT_POLICY_SCAN_FAILED"
                findings.append(counts)
                continue
            findings.append(counts)
    return findings


def _scan_source_binary_policy(source: Any, snapshot: dict[str, Any], memory_guard: _MemoryGuard) -> list[dict[str, Any]]:
    import pymysql

    binary_types = {"binary", "varbinary", "tinyblob", "blob", "mediumblob", "longblob"}
    findings: list[dict[str, Any]] = []
    for table in snapshot.get("tables", []):
        if table.get("type") != "BASE TABLE":
            continue
        table_name = str(table.get("name", ""))
        for column in table.get("columns", []):
            data_type = str(column.get("data_type", "")).lower()
            if data_type not in binary_types:
                continue
            column_name = str(column.get("name", ""))
            predicate = f"{_mysql_ident(column_name)} IS NOT NULL"
            table_sql = f"{_mysql_ident(SOURCE_SCHEMA)}.{_mysql_ident(table_name)}"
            quoted_column = _mysql_ident(column_name)
            item = {
                "table": table_name,
                "column": column_name,
                "data_type": data_type,
                "cells_scanned": 0,
                "method_b_cells_with_nul": 0,
                "python_cells_with_nul": 0,
                "nul_bytes": 0,
                "binary_bytes_scanned": 0,
                "bytea_policy": "raw_bytes_without_text_decode_or_nul_normalization",
            }
            try:
                with source.cursor(pymysql.cursors.Cursor) as aggregate_cursor:
                    aggregate_cursor.execute(
                        f"SELECT COUNT(*), COALESCE(SUM(INSTR(BINARY {quoted_column}, 0x00) > 0), 0) "
                        f"FROM {table_sql} WHERE {predicate}"
                    )
                    aggregate_row = aggregate_cursor.fetchone()
                if not aggregate_row or len(aggregate_row) != 2:
                    raise LoaderError("SOURCE_BINARY_POLICY_AGGREGATE_INVALID")
                expected_cells = int(aggregate_row[0])
                item["cells_expected"] = expected_cells
                item["method_b_cells_with_nul"] = int(aggregate_row[1])
                with source.cursor(pymysql.cursors.SSCursor) as cursor:
                    cursor.execute(
                        f"SELECT CAST({quoted_column} AS BINARY) FROM {table_sql} WHERE {predicate}"
                    )
                    while True:
                        rows = cursor.fetchmany(MYSQL_BATCH_SIZE)
                        if not rows:
                            break
                        for row in rows:
                            memory_guard.sample_if_due()
                            if not row or not isinstance(row[0], (bytes, bytearray, memoryview)):
                                raise LoaderError("SOURCE_BINARY_VALUE_NOT_BYTES")
                            raw = bytes(row[0])
                            item["cells_scanned"] += 1
                            item["binary_bytes_scanned"] += len(raw)
                            nul_count = raw.count(b"\x00")
                            if nul_count:
                                item["python_cells_with_nul"] += 1
                                item["nul_bytes"] += nul_count
                if item["cells_scanned"] != expected_cells \
                    or item["method_b_cells_with_nul"] != item["python_cells_with_nul"]:
                    raise LoaderError("SOURCE_BINARY_NUL_DETECTOR_DISAGREEMENT")
            except LoaderError as error:
                if error.code.startswith("MEMORY_"):
                    raise
                item["scan_status"] = "FAILED"
                item["scan_failure_code"] = error.code
                findings.append(item)
                continue
            except Exception as error:
                item["scan_status"] = "FAILED"
                item["scan_failure_code"] = "SOURCE_BINARY_POLICY_SCAN_FAILED"
                findings.append(item)
                continue
            findings.append(item)
    return findings


def _row_size_bytes(row: Any) -> int:
    total = 0
    for value in row:
        if value is None:
            continue
        if isinstance(value, bytes):
            total += len(value)
        elif isinstance(value, str):
            total += len(value.encode("utf-8"))
        elif isinstance(value, (bytearray, memoryview)):
            total += len(value)
        else:
            total += len(str(value).encode("utf-8"))
    return total


def _text_findings_require_review(findings: list[dict[str, Any]]) -> bool:
    for item in findings:
        charset = item.get("character_set")
        if item.get("scan_status", "SCANNED") != "SCANNED" or item.get("method_a_decode_failures", 0) \
            or item.get("detector_disagreement", False) \
            or (isinstance(charset, str) and charset.lower() == "binary"):
            return True
        if (charset is None or str(charset).lower() in {"utf8", "utf8mb3", "utf8mb4"}) and item.get("non_utf8_cells", 0):
            return True
    return False


def _text_policy_table_failures(findings: list[dict[str, Any]]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    for item in findings:
        charset = str(item.get("character_set") or "").lower()
        decode_failures = int(item.get("method_a_decode_failures", 0) or 0)
        non_utf8 = int(item.get("non_utf8_cells", 0) or 0)
        disagreement = item.get("detector_disagreement") is True
        scan_incomplete = item.get("scan_status", "SCANNED") != "SCANNED"
        if not (decode_failures or disagreement or scan_incomplete or charset == "binary" or (charset in {"", "utf8", "utf8mb3", "utf8mb4"} and non_utf8)):
            continue
        failure_code = item.get("scan_failure_code")
        if isinstance(failure_code, str) and re.fullmatch(r"[A-Z0-9_]{1,96}", failure_code):
            reason, affected = failure_code, max(0, int(item.get("cells_expected", item.get("cells_scanned", 0)) or 0))
        elif decode_failures:
            reason, affected = "SOURCE_TEXT_DECODE_FAILURE", decode_failures
        elif charset == "binary":
            reason, affected = "SOURCE_TEXT_CHARSET_BINARY", int(item.get("cells_expected", item.get("cells_scanned", 0)) or 0)
        elif non_utf8:
            reason, affected = "SOURCE_TEXT_INVALID_UTF8", non_utf8
        elif disagreement:
            reason, affected = "SOURCE_TEXT_DETECTOR_DISAGREEMENT", int(item.get("cells_expected", item.get("cells_scanned", 0)) or 0)
        elif item.get("scan_status") == "UNSUPPORTED_CHARSET_REQUIRES_REVIEW":
            reason, affected = "SOURCE_TEXT_CHARSET_UNSUPPORTED", int(item.get("cells_expected", item.get("cells_scanned", 0)) or 0)
        else:
            reason, affected = "SOURCE_TEXT_SCAN_INCOMPLETE", int(item.get("cells_expected", item.get("cells_scanned", 0)) or 0)
        failures.append({
            "table": str(item.get("table", "")),
            "column": str(item.get("column", "")),
            "reason": reason,
            "affected_cells": max(0, affected),
        })
    return failures


def _binary_policy_table_failures(findings: list[dict[str, Any]]) -> list[dict[str, Any]]:
    failures: list[dict[str, Any]] = []
    for item in findings:
        if item.get("scan_status") != "FAILED":
            continue
        reason = item.get("scan_failure_code")
        if not isinstance(reason, str) or not re.fullmatch(r"[A-Z0-9_]{1,96}", reason):
            reason = "SOURCE_BINARY_POLICY_SCAN_FAILED"
        failures.append({
            "table": str(item.get("table", "")),
            "column": str(item.get("column", "")),
            "reason": reason,
            "affected_cells": max(0, int(item.get("cells_expected", item.get("cells_scanned", 0)) or 0)),
        })
    return failures


def _validate_row_size(row: Any) -> None:
    if _row_size_bytes(row) > MAX_ROW_BYTES:
        raise LoaderError("SOURCE_ROW_EXCEEDS_128_MIB")


def _json_contains_nul(value: Any) -> bool:
    if isinstance(value, str):
        return "\x00" in value
    if isinstance(value, list):
        return any(_json_contains_nul(item) for item in value)
    if isinstance(value, dict):
        return any(_json_contains_nul(key) or _json_contains_nul(item) for key, item in value.items())
    return False


def _json_nul_count(value: Any) -> int:
    if isinstance(value, str):
        return value.count("\x00")
    if isinstance(value, list):
        return sum(_json_nul_count(item) for item in value)
    if isinstance(value, dict):
        return sum(_json_nul_count(key) + _json_nul_count(item) for key, item in value.items())
    return 0


def _remove_json_nul(value: Any) -> Any:
    if isinstance(value, str):
        return value.replace("\x00", "")
    if isinstance(value, list):
        return [_remove_json_nul(item) for item in value]
    if isinstance(value, dict):
        normalized: dict[Any, Any] = {}
        for key, item in value.items():
            normalized_key = _remove_json_nul(key)
            if normalized_key in normalized:
                raise LoaderError("JSON_NUL_NORMALIZATION_KEY_COLLISION")
            normalized[normalized_key] = _remove_json_nul(item)
        return normalized
    return value


def _source_text_codec(character_set: str | None) -> str:
    codecs = {
        "utf8": "utf-8",
        "utf8mb3": "utf-8",
        "utf8mb4": "utf-8",
        "latin1": "cp1252",
        "ascii": "ascii",
        "binary": "latin-1",
        "ucs2": "utf-16-be",
        "utf16": "utf-16-be",
        "utf16le": "utf-16-le",
        "utf32": "utf-32-be",
        "sjis": "cp932",
        "cp932": "cp932",
        "ujis": "euc_jp",
        "eucjpms": "euc_jp",
        "gbk": "gbk",
        "gb2312": "gb2312",
        "big5": "big5",
        "euckr": "euc_kr",
    }
    if character_set is None:
        return "utf-8"
    try:
        return codecs[str(character_set).lower()]
    except KeyError as error:
        raise LoaderError("SOURCE_TEXT_CHARSET_UNSUPPORTED") from error


def _text_bytes(value: Any, column: dict[str, Any]) -> bytes:
    if isinstance(value, (bytes, bytearray, memoryview)):
        return bytes(value)
    if isinstance(value, str):
        try:
            return value.encode(_source_text_codec(column.get("character_set")), errors="strict")
        except (UnicodeEncodeError, LookupError) as error:
            raise LoaderError("SOURCE_TEXT_ENCODING_FAILED") from error
    raise LoaderError("SOURCE_TEXT_VALUE_NOT_STRING")


def _nul_source_byte_count(value: str, column: dict[str, Any]) -> int:
    try:
        width = len("\x00".encode(_source_text_codec(column.get("character_set")), errors="strict"))
    except (UnicodeEncodeError, LookupError) as error:
        raise LoaderError("SOURCE_TEXT_ENCODING_FAILED") from error
    return value.count("\x00") * width


def _nul_position(raw: bytes | str) -> str:
    nul = "\x00" if isinstance(raw, str) else b"\x00"
    positions = [index for index, value in enumerate(raw) if value == nul]
    if not positions:
        return "none"
    if len(positions) == len(raw):
        return "entirely_nul"
    first, last = positions[0], positions[-1]
    if all(byte == 0 for byte in raw[: last + 1]) and last < len(raw) - 1:
        return "beginning_only"
    if all(byte == 0 for byte in raw[first:]) and first > 0:
        return "end_only"
    if any(byte != 0 for byte in raw[:first]) and any(byte != 0 for byte in raw[first + 1:]):
        return "middle"
    return "mixed_edges"


def _nul_report_rows(counts: dict[tuple[str, str], dict[str, Any]]) -> list[dict[str, Any]]:
    return [
        {"table": table, "column": column, **values, "status": "NORMALIZED_NUL"}
        for (table, column), values in sorted(counts.items())
    ]


def _record_nul_value(
    table_name: str | None,
    column_name: str,
    raw_value: bytes,
    nul_normalization_counts: dict[tuple[str, str], dict[str, int]] | None,
    nul_records: list[tuple[str, str, bytes, str]] | None,
    *,
    json_escaped: bool = False,
    position_override: str | None = None,
    nul_byte_count: int | None = None,
) -> None:
    if nul_normalization_counts is None or nul_records is None or table_name is None:
        raise LoaderError("SOURCE_TEXT_NUL_REPORT_REQUIRED")
    key = (table_name, column_name)
    counts = nul_normalization_counts.setdefault(key, {
        "cells_with_nul": 0, "nul_bytes": 0, "only_end": 0, "only_beginning": 0,
        "in_middle": 0, "entirely_nul": 0, "mixed_edges": 0, "json_escaped_nul_cells": 0,
    })
    counts["cells_with_nul"] += 1
    count = raw_value.count(b"\x00") if nul_byte_count is None else nul_byte_count
    if isinstance(count, bool) or not isinstance(count, int) or count < 0:
        raise LoaderError("SOURCE_TEXT_NUL_BYTE_COUNT_INVALID")
    counts["nul_bytes"] += count
    if json_escaped:
        counts["json_escaped_nul_cells"] += 1
    position = position_override or (_nul_position(raw_value) if b"\x00" in raw_value else "json_escaped")
    position_key = {
        "end_only": "only_end", "beginning_only": "only_beginning", "middle": "in_middle",
        "entirely_nul": "entirely_nul", "mixed_edges": "mixed_edges",
    }.get(position)
    if position_key:
        counts[position_key] += 1
    nul_records.append((table_name, column_name, raw_value, position))


def _zero_date_report_rows(counts: dict[tuple[str, str], int]) -> list[dict[str, Any]]:
    return [
        {"table": table, "column": column, "count": count, "status": "NORMALIZED_ZERO_DATE"}
        for (table, column), count in sorted(counts.items())
    ]


def _restore_temporal_not_null(
    target: Any,
    report: dict[str, Any],
    mappings: dict[str, dict[str, Any]],
    zero_date_counts: dict[tuple[str, str], int],
) -> list[dict[str, Any]]:
    from psycopg import sql

    results = []
    candidates = report.get("zero_date_nullable_columns")
    if not isinstance(candidates, list):
        raise LoaderError("BUNDLE_ZERO_DATE_NULLABILITY_METADATA_INVALID")
    seen: set[tuple[str, str]] = set()
    for item in candidates:
        if not isinstance(item, dict):
            raise LoaderError("BUNDLE_ZERO_DATE_NULLABILITY_METADATA_INVALID")
        table = item.get("table")
        column = item.get("column")
        if not isinstance(table, str) or not isinstance(column, str) or table not in mappings \
            or (table, column) in seen or column not in mappings[table].get("types", {}):
            raise LoaderError("BUNDLE_ZERO_DATE_NULLABILITY_METADATA_INVALID")
        seen.add((table, column))
        count = zero_date_counts.get((table, column), 0)
        if isinstance(count, bool) or not isinstance(count, int) or count < 0:
            raise LoaderError("ZERO_DATE_NORMALIZATION_COUNT_INVALID")
        schema = mappings[table].get("target_schema")
        if schema not in {TARGET_SCHEMA, LEGACY_SCHEMA}:
            raise LoaderError("BUNDLE_TABLE_SCHEMA_INVALID")
        status = "LEFT_NULLABLE_FOR_ZERO_DATE" if count else "RESTORED_NOT_NULL"
        if count == 0:
            statement = sql.SQL("ALTER TABLE {}.{} ALTER COLUMN {} SET NOT NULL").format(
                sql.Identifier(schema), sql.Identifier(table), sql.Identifier(column)
            )
            try:
                with target.cursor() as cursor:
                    cursor.execute(statement)
            except Exception as error:
                raise LoaderError("POSTGRES_TEMPORAL_NOT_NULL_RESTORE_FAILED", _sqlstate(error)) from error
        results.append({"table": table, "column": column, "zero_date_count": count, "status": status})
    return results


def _enum_extension_report_rows(counts: dict[tuple[str, str, str], int]) -> list[dict[str, Any]]:
    grouped: dict[tuple[str, str], dict[str, Any]] = {}
    for (table, column, value), count in counts.items():
        entry = grouped.setdefault((table, column), {"counts": [], "empty_label_cells": 0})
        entry["counts"].append(count)
        if value == "":
            entry["empty_label_cells"] += count
    return [
        {
            "table": table,
            "column": column,
            "cells_outside_declared_labels": sum(values["counts"]),
            "distinct_extra_values": len(values["counts"]),
            "counts_by_distinct_extra_value": sorted(values["counts"]),
            "empty_label_cells": values["empty_label_cells"],
            "status": "ENUM_EXTENDED_FROM_EXISTING_VALUES",
        }
        for (table, column), values in sorted(grouped.items())
    ]


def normalize_mysql_value(
    value: Any,
    column: dict[str, Any],
    *,
    no_backslash_escapes: bool = False,
    normalization_counts: dict[tuple[str, str], int] | None = None,
    nul_normalization_counts: dict[tuple[str, str], dict[str, int]] | None = None,
    nul_records: list[tuple[str, str, bytes, str]] | None = None,
    enum_value_counts: dict[tuple[str, str, str], int] | None = None,
    table_name: str | None = None,
) -> Any:
    if value is None:
        return None
    data_type = str(column.get("data_type", "")).lower()
    column_type = str(column.get("column_type", ""))
    source_raw_value = bytes(value) if isinstance(value, (bytes, bytearray, memoryview)) else None
    if data_type in {"tinyblob", "blob", "mediumblob", "longblob", "binary", "varbinary"}:
        if not isinstance(value, (bytes, bytearray, memoryview)):
            raise LoaderError("BINARY_COLUMN_VALUE_NOT_BYTES")
        return bytes(value)
    if data_type == "bit":
        match = re.fullmatch(r"bit\s*\(\s*(\d+)\s*\)", column_type, re.IGNORECASE)
        if not match:
            raise LoaderError("BIT_COLUMN_METADATA_INVALID")
        width = int(match.group(1))
        if isinstance(value, (bytes, bytearray, memoryview)):
            number = int.from_bytes(bytes(value), "big")
        elif isinstance(value, int) and not isinstance(value, bool):
            number = value
        else:
            raise LoaderError("BIT_COLUMN_VALUE_INVALID")
        if number < 0 or number >= (1 << width):
            raise LoaderError("BIT_COLUMN_VALUE_OUT_OF_RANGE")
        return format(number, f"0{width}b")
    if data_type in {"date", "datetime", "timestamp", "time"}:
        if isinstance(value, (bytes, bytearray, memoryview)):
            try:
                value = bytes(value).decode("ascii", errors="strict")
            except UnicodeDecodeError as error:
                raise LoaderError("SOURCE_TEMPORAL_VALUE_NOT_ASCII") from error
        try:
            raw_temporal = value.strip() if isinstance(value, str) else None
            zero_date = data_type in {"date", "datetime", "timestamp"} and raw_temporal is not None \
                and re.fullmatch(r"0000-00-00(?:\s+00:00:00(?:\.0+)?)?", raw_temporal) is not None
            normalized = normalize_temporal_value(
                value,
                data_type,
                zero_date_to_null=True,
                source_timezone="UTC",
                target_timezone="America/Sao_Paulo",
                time_as_interval=column.get("time_uses_interval") if data_type == "time" else None,
            )
            if zero_date and normalization_counts is None:
                raise LoaderError("ZERO_DATE_NORMALIZATION_REPORT_REQUIRED")
            if zero_date:
                key = (table_name or "<unknown>", str(column.get("name", "<unknown>")))
                normalization_counts[key] = normalization_counts.get(key, 0) + 1
            return normalized
        except TemporalValueError as error:
            raise LoaderError(error.code) from error
    if isinstance(value, (bytes, bytearray, memoryview)):
        try:
            value = bytes(value).decode(_source_text_codec(column.get("character_set")), errors="strict")
        except (UnicodeDecodeError, LookupError) as error:
            raise LoaderError("SOURCE_TEXT_INVALID_UTF8") from error
    if isinstance(value, str):
        raw_value = source_raw_value if source_raw_value is not None else _text_bytes(value, column)
        if data_type == "json":
            physical_nul = "\x00" in value
            parse_value = value.replace("\x00", "\\u0000") if physical_nul else value
            try:
                decoded_json = json.loads(parse_value, object_pairs_hook=_nul_aware_json_pairs)
            except (TypeError, ValueError) as error:
                raise LoaderError("SOURCE_JSON_INVALID") from error
            semantic_nul = _json_contains_nul(decoded_json)
            if physical_nul or semantic_nul:
                semantic_nul_count = _json_nul_count(decoded_json)
                physical_nul_count = value.count("\x00")
                escaped_nul_count = semantic_nul_count - physical_nul_count
                if escaped_nul_count < 0:
                    raise LoaderError("JSON_NUL_NORMALIZATION_COUNT_INVALID")
                try:
                    codec = _source_text_codec(column.get("character_set"))
                    nul_byte_count = _nul_source_byte_count(value, column)
                    if escaped_nul_count:
                        nul_byte_count += escaped_nul_count * 6 * len("\\".encode(codec, errors="strict"))
                except (UnicodeEncodeError, LookupError) as error:
                    raise LoaderError("SOURCE_TEXT_ENCODING_FAILED") from error
                _record_nul_value(
                    table_name,
                    str(column.get("name", "<unknown>")),
                    raw_value,
                    nul_normalization_counts,
                    nul_records,
                    json_escaped=semantic_nul and not physical_nul,
                    position_override=_nul_position(value) if physical_nul else "json_escaped",
                    nul_byte_count=nul_byte_count,
                )
                decoded_json = _remove_json_nul(decoded_json)
                value = json.dumps(decoded_json, ensure_ascii=False, separators=(",", ":"))
        elif "\x00" in value:
            _record_nul_value(
                table_name,
                str(column.get("name", "<unknown>")),
                raw_value,
                nul_normalization_counts,
                nul_records,
                position_override=_nul_position(value),
                nul_byte_count=_nul_source_byte_count(value, column),
            )
            value = value.replace("\x00", "")
    if data_type == "enum":
        if not isinstance(value, str):
            raise LoaderError("ENUM_VALUE_TYPE_INVALID")
        try:
            labels = parse_mysql_enum(column_type, no_backslash_escapes=no_backslash_escapes)
        except TypeMappingError as error:
            raise LoaderError("ENUM_METADATA_INVALID") from error
        if value not in labels:
            if enum_value_counts is None:
                raise LoaderError("ENUM_EXTENSION_REPORT_REQUIRED")
            key = (table_name or "<unknown>", str(column.get("name", "<unknown>")), value)
            enum_value_counts[key] = enum_value_counts.get(key, 0) + 1
    return value


def _validate_column_mappings(snapshot: dict[str, Any], report: dict[str, Any], table_names: list[str]) -> dict[str, dict[str, Any]]:
    overrides = report.get("collation_overrides")
    if not isinstance(overrides, dict):
        raise LoaderError("BUNDLE_COLLATION_OVERRIDES_INVALID")
    decisions = report.get("collation_decisions")
    if not isinstance(decisions, list):
        raise LoaderError("BUNDLE_COLLATION_DECISIONS_INVALID")
    collations: dict[str, str] = {}
    for decision in decisions:
        if not isinstance(decision, dict) or not isinstance(decision.get("table"), str) \
            or not isinstance(decision.get("column"), str) or decision.get("target") not in {"ci", "C"}:
            raise LoaderError("BUNDLE_COLLATION_DECISION_INVALID")
        key = f"{decision['table']}.{decision['column']}"
        if key in collations:
            raise LoaderError("BUNDLE_COLLATION_DECISION_DUPLICATE")
        collations[key] = decision["target"]
    expected_collations = {
        f"{table['name']}.{column['name']}"
        for table in snapshot.get("tables", [])
        if table.get("type") == "BASE TABLE" and table.get("name") in table_names
        for column in table.get("columns", [])
        if str(column.get("data_type", "")).lower() in {"char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext", "enum"}
    }
    if set(collations) != expected_collations or any(collations.get(key) != value for key, value in overrides.items()):
        raise LoaderError("BUNDLE_COLLATION_DECISIONS_MISMATCH")
    no_backslash_escapes = bool(snapshot.get("source_no_backslash_escapes"))
    table_map = {item["name"]: item for item in snapshot["tables"] if item.get("type") == "BASE TABLE"}
    if set(table_names) - set(table_map):
        raise LoaderError("BUNDLE_TABLE_METADATA_MISSING")
    table_schemas = report.get("table_schemas")
    expected_schemas = {table_name: _schema_for_table(table_name) for table_name in table_names}
    if not isinstance(table_schemas, dict) or table_schemas != expected_schemas:
        raise LoaderError("BUNDLE_TABLE_SCHEMA_ROUTING_MISMATCH")
    mappings: dict[str, dict[str, Any]] = {}
    for table_name in table_names:
        table = table_map[table_name]
        columns = table.get("columns")
        if not isinstance(columns, list) or not columns:
            raise LoaderError("BUNDLE_COLUMN_METADATA_MISSING")
        ordered = sorted(columns, key=lambda item: item.get("ordinal", 0))
        if len({item.get("name") for item in ordered}) != len(ordered):
            raise LoaderError("BUNDLE_COLUMN_METADATA_DUPLICATE")
        mapped: dict[str, Any] = {}
        copy_columns: list[dict[str, Any]] = []
        generated_columns: list[dict[str, Any]] = []
        enum_labels: dict[str, tuple[str, ...]] = {}
        for column in ordered:
            name = column.get("name")
            if not isinstance(name, str) or not name:
                raise LoaderError("BUNDLE_COLUMN_METADATA_INVALID")
            key = f"{table_name}.{name}"
            target_collation = overrides.get(key, collations.get(key))
            if is_generated_column(column):
                generated_spec = target_generated_column(table_name, column)
                if generated_spec is None:
                    raise LoaderError("GENERATED_COLUMN_TRANSLATION_REQUIRED")
                generated_columns.append({"name": name, **generated_spec})
            else:
                copy_columns.append(column)
            try:
                mapped[name] = map_mysql_type(
                    str(column.get("column_type", "")),
                    target_collation=target_collation,
                    column_name=name,
                    no_backslash_escapes=no_backslash_escapes,
                    time_uses_interval=column.get("time_uses_interval"),
                )
                if str(column.get("data_type", "")).lower() == "enum":
                    enum_labels[name] = parse_mysql_enum(
                        str(column.get("column_type", "")),
                        no_backslash_escapes=no_backslash_escapes,
                    )
            except TypeMappingError as error:
                raise LoaderError(error.code) from error
        if not copy_columns:
            raise LoaderError("GENERATED_TABLE_WITHOUT_COPY_COLUMNS")
        table["columns"] = ordered
        mappings[table_name] = {
            "columns": ordered,
            "copy_columns": copy_columns,
            "generated_columns": generated_columns,
            "types": mapped,
            "enum_labels": enum_labels,
            "target_schema": expected_schemas[table_name],
        }
    return mappings


def _execute_bundle_script(connection: Any, script_path: Path, expected_sha256: str) -> None:
    try:
        import psycopg
        if script_path.is_symlink() or not script_path.is_file():
            raise LoaderError("BUNDLE_SCRIPT_MISSING")
        raw = script_path.read_bytes()
        if hashlib.sha256(raw).hexdigest() != expected_sha256:
            raise LoaderError("BUNDLE_SCRIPT_CHECKSUM_MISMATCH")
        with psycopg.ClientCursor(connection) as cursor:
            cursor.execute(raw.decode("utf-8", errors="strict"))
    except LoaderError:
        raise
    except Exception as error:
        raise LoaderError("POSTGRES_BUNDLE_SCRIPT_FAILED", _sqlstate(error)) from error


def _copy_table(
    source: Any,
    target: Any,
    table: dict[str, Any],
    mapped: dict[str, Any],
    no_backslash_escapes: bool,
    memory_guard: _MemoryGuard,
    normalization_counts: dict[tuple[str, str], int] | None = None,
    nul_normalization_counts: dict[tuple[str, str], dict[str, Any]] | None = None,
    nul_spool: Any | None = None,
    enum_value_counts: dict[tuple[str, str, str], int] | None = None,
) -> int:
    import pymysql
    from psycopg import sql

    table_name = str(table["name"])
    columns = mapped["columns"]
    column_names = [str(column["name"]) for column in columns]
    copy_columns = mapped.get("copy_columns")
    if not isinstance(copy_columns, list) or not copy_columns:
        raise LoaderError("COPY_COLUMN_MAPPING_INVALID")
    copy_column_names = [str(column["name"]) for column in copy_columns]
    copy_positions = {name: index for index, name in enumerate(column_names)}
    textual_types = {"char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set", "json"}
    mysql_columns = ", ".join(
        f"CAST({_mysql_ident(str(column['name']))} AS BINARY)"
        if str(column.get("data_type", "")).lower() in textual_types else _mysql_ident(str(column["name"]))
        for column in columns
    )
    source_query = f"SELECT {mysql_columns} FROM {_mysql_ident(SOURCE_SCHEMA)}.{_mysql_ident(table_name)}"
    expected_count: int | None = None
    try:
        row_size_expr = " + ".join(
            f"COALESCE(CAST(OCTET_LENGTH({_mysql_ident(name)}) AS DECIMAL(65,0)), 0)"
            for name in column_names
        )
        with source.cursor(pymysql.cursors.Cursor) as count_cursor:
            count_cursor.execute(
                f"SELECT COUNT(*), COALESCE(MAX({row_size_expr}), 0) "
                f"FROM {_mysql_ident(SOURCE_SCHEMA)}.{_mysql_ident(table_name)}"
            )
            count_row = count_cursor.fetchone()
            if not count_row or len(count_row) != 2:
                raise LoaderError("SOURCE_TABLE_PREFLIGHT_INVALID")
            expected_count = int(count_row[0])
            maximum_row_bytes = int(count_row[1])
            if maximum_row_bytes > MAX_ROW_BYTES:
                raise LoaderError("SOURCE_ROW_EXCEEDS_128_MIB")
        target_schema = mapped.get("target_schema", TARGET_SCHEMA)
        if target_schema not in {TARGET_SCHEMA, LEGACY_SCHEMA}:
            raise LoaderError("TARGET_SCHEMA_NOT_ALLOWLISTED")
        copy_statement = sql.SQL("COPY {}.{} ({}) FROM STDIN").format(
            sql.Identifier(target_schema),
            sql.Identifier(table_name),
            sql.SQL(", ").join(sql.Identifier(name) for name in copy_column_names),
        )
        copied = 0
        with source.cursor(pymysql.cursors.SSCursor) as source_cursor:
            source_cursor.execute(source_query)
            with target.cursor() as target_cursor:
                with target_cursor.copy(copy_statement) as copy:
                    while True:
                        rows = source_cursor.fetchmany(MYSQL_BATCH_SIZE)
                        if not rows:
                            break
                        for row in rows:
                            memory_guard.sample_if_due()
                            _validate_row_size(row)
                            row_nul_records: list[tuple[str, str, bytes, str]] = []
                            normalized = []
                            for column in copy_columns:
                                try:
                                    normalized.append(normalize_mysql_value(
                                        row[copy_positions[str(column["name"])]],
                                        column,
                                        no_backslash_escapes=no_backslash_escapes,
                                        normalization_counts=normalization_counts,
                                        nul_normalization_counts=nul_normalization_counts,
                                        nul_records=row_nul_records,
                                        enum_value_counts=enum_value_counts,
                                        table_name=table_name,
                                    ))
                                except LoaderError as error:
                                    error.column = str(column["name"])
                                    raise
                            pk_text = _audit_pk_text(table, columns, row) if row_nul_records else ""
                            for nul_table, nul_column, original, position in row_nul_records:
                                if nul_spool is None:
                                    raise LoaderError("SOURCE_TEXT_NUL_SPOOL_REQUIRED")
                                record = [nul_table, nul_column, pk_text, base64.b64encode(original).decode("ascii"), position]
                                nul_spool.write((json.dumps(record, ensure_ascii=True, separators=(",", ":")) + "\n").encode("utf-8"))
                            copy.write_row(normalized)
                            copied += 1
                            memory_guard.sample_if_due()
        if copied != expected_count:
            raise LoaderError("SOURCE_ROW_COUNT_CHANGED_DURING_COPY")
        return copied
    except LoaderError as error:
        if expected_count is not None:
            error.source_rows = expected_count
        raise
    except Exception as error:
        wrapped = LoaderError("TABLE_COPY_FAILED", _sqlstate(error))
        if expected_count is not None:
            wrapped.source_rows = expected_count
        raise wrapped from error


def _apply_nul_audit(target: Any, spool: Any, mappings: dict[str, dict[str, Any]]) -> int:
    from psycopg import sql

    spool.seek(0)
    statement = sql.SQL(
        "COPY {}.{} (\"tabela\", \"coluna\", \"pk\", \"valor_original\") FROM STDIN"
    ).format(sql.Identifier(LEGACY_SCHEMA), sql.Identifier("auditoria_nul"))
    count = 0
    try:
        with target.cursor() as target_cursor:
            with target_cursor.copy(statement) as copy:
                for line in spool:
                    try:
                        record = json.loads(line)
                    except (TypeError, ValueError) as error:
                        raise LoaderError("NUL_AUDIT_SPOOL_INVALID") from error
                    if not isinstance(record, list) or len(record) != 5:
                        raise LoaderError("NUL_AUDIT_SPOOL_INVALID")
                    table, column, pk_text, encoded, position = record
                    if not isinstance(table, str) or table not in mappings or not isinstance(column, str) \
                        or column not in mappings[table]["types"] or not isinstance(pk_text, str) or not isinstance(encoded, str) \
                        or position not in {"beginning_only", "end_only", "middle", "entirely_nul", "mixed_edges", "json_escaped"}:
                        raise LoaderError("NUL_AUDIT_SPOOL_INVALID")
                    column_metadata = next(
                        (item for item in mappings[table].get("columns", []) if item.get("name") == column), None
                    )
                    if column_metadata is None or str(column_metadata.get("data_type", "")).lower() not in {
                        "char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set", "json"
                    }:
                        raise LoaderError("NUL_AUDIT_COLUMN_NOT_TEXTUAL")
                    try:
                        original = base64.b64decode(encoded, validate=True)
                    except (ValueError, base64.binascii.Error) as error:
                        raise LoaderError("NUL_AUDIT_SPOOL_INVALID") from error
                    if b"\x00" not in original and position != "json_escaped":
                        raise LoaderError("NUL_AUDIT_SPOOL_INVALID")
                    copy.write_row((table, column, pk_text, original))
                    count += 1
    except LoaderError:
        raise
    except Exception as error:
        raise LoaderError("NUL_AUDIT_COPY_FAILED", _sqlstate(error)) from error
    return count


def _apply_enum_checks(
    target: Any,
    report: dict[str, Any],
    mappings: dict[str, dict[str, Any]],
    enum_value_counts: dict[tuple[str, str, str], int],
) -> None:
    import psycopg
    from psycopg import sql

    expected = {
        (table, column)
        for table, mapping in mappings.items()
        for column in mapping.get("enum_labels", {})
    }
    checks = report.get("enum_checks_deferred")
    if checks is None and not expected:
        checks = []
    if not isinstance(checks, list):
        raise LoaderError("BUNDLE_ENUM_CHECK_METADATA_INVALID")
    seen: set[tuple[str, str]] = set()
    for item in checks:
        if not isinstance(item, dict):
            raise LoaderError("BUNDLE_ENUM_CHECK_METADATA_INVALID")
        table = item.get("table")
        column = item.get("column")
        constraint = item.get("constraint_name")
        key = (table, column)
        if not isinstance(table, str) or not isinstance(column, str) or not isinstance(constraint, str) \
            or key not in expected or key in seen or table not in mappings:
            raise LoaderError("BUNDLE_ENUM_CHECK_METADATA_INVALID")
        labels = mappings[table]["enum_labels"].get(column)
        target_schema = mappings[table].get("target_schema", TARGET_SCHEMA)
        if target_schema not in {TARGET_SCHEMA, LEGACY_SCHEMA} or not isinstance(labels, tuple) or not labels:
            raise LoaderError("BUNDLE_ENUM_CHECK_METADATA_INVALID")
        seen.add(key)
        allowed = list(labels)
        empty_label_cells = enum_value_counts.get((table, column, ""), 0)
        if "" not in allowed and empty_label_cells > 0:
            allowed.append("")
        for extra_table, extra_column, value in enum_value_counts:
            if (extra_table, extra_column) == key and value not in allowed:
                allowed.append(value)
        statement = sql.SQL("ALTER TABLE {}.{} ADD CONSTRAINT {} CHECK ({} IN ({}))").format(
            sql.Identifier(target_schema),
            sql.Identifier(table),
            sql.Identifier(constraint),
            sql.Identifier(column),
            sql.SQL(", ").join(sql.Literal(value) for value in allowed),
        )
        try:
            with target.cursor() as cursor:
                cursor.execute(statement)
        except Exception as error:
            raise LoaderError("POSTGRES_ENUM_CHECK_FAILED", _sqlstate(error)) from error
    report["enum_empty_label_counts"] = [
        {"table": table, "column": column, "count": count}
        for (table, column, value), count in sorted(enum_value_counts.items())
        if value == "" and count > 0
    ]
    if seen != expected:
        raise LoaderError("BUNDLE_ENUM_CHECK_COVERAGE_MISMATCH")


def _fetch_count(connection: Any, statement: Any, code: str) -> int:
    try:
        with connection.cursor() as cursor:
            cursor.execute(statement)
            row = cursor.fetchone()
    except Exception as error:
        raise LoaderError(code, _sqlstate(error)) from error
    if not row or isinstance(row[0], bool) or not isinstance(row[0], int) or row[0] < 0:
        raise LoaderError(code + "_INVALID")
    return row[0]


def _unique_index_parts(item: dict[str, Any], mapping: dict[str, Any]) -> tuple[list[Any], list[Any], list[tuple[str, Any]], list[Any]]:
    from psycopg import sql

    columns = item.get("columns")
    if not isinstance(columns, list) or not columns:
        raise LoaderError("BUNDLE_UNIQUE_INDEX_COLUMNS_INVALID")
    metadata = {str(column.get("name")): column for column in mapping.get("columns", [])}
    expressions: list[Any] = []
    null_checks: list[Any] = []
    ci_columns: list[tuple[str, Any]] = []
    index_parts: list[Any] = []
    for part in columns:
        if not isinstance(part, dict) or not isinstance(part.get("name"), str):
            raise LoaderError("BUNDLE_UNIQUE_INDEX_COLUMN_INVALID")
        name = part["name"]
        column = metadata.get(name)
        type_mapping = mapping.get("types", {}).get(name)
        if column is None or type_mapping is None:
            raise LoaderError("BUNDLE_UNIQUE_INDEX_COLUMN_NOT_FOUND")
        identifier = sql.Identifier(name)
        prefix_length = part.get("prefix_length")
        if prefix_length is not None:
            if isinstance(prefix_length, bool) or not isinstance(prefix_length, int) or not 1 <= prefix_length <= 65535:
                raise LoaderError("BUNDLE_UNIQUE_PREFIX_LENGTH_INVALID")
            data_type = str(column.get("data_type", "")).lower()
            if data_type in {"char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext", "enum"}:
                expression = sql.SQL("left({}::text, {})").format(identifier, sql.Literal(prefix_length))
            elif data_type in {"tinyblob", "blob", "mediumblob", "longblob", "binary", "varbinary"}:
                expression = sql.SQL("substring({} from 1 for {})").format(identifier, sql.Literal(prefix_length))
            else:
                raise LoaderError("BUNDLE_UNIQUE_PREFIX_TYPE_INVALID")
        else:
            expression = identifier
        direction = str(part.get("direction", "ASC")).upper()
        if direction not in {"ASC", "DESC"}:
            raise LoaderError("BUNDLE_UNIQUE_INDEX_DIRECTION_INVALID")
        expressions.append(expression)
        null_checks.append(sql.SQL("{} IS NOT NULL").format(expression))
        index_parts.append(sql.SQL("{} {}").format(expression, sql.SQL(direction)))
        if type_mapping.collation_sql == "ci":
            ci_columns.append((name, expression))
    return expressions, null_checks, ci_columns, index_parts


def _duplicate_group_count(target: Any, schema: str, table: str, expressions: list[Any], null_checks: list[Any]) -> int:
    from psycopg import sql

    if schema not in {TARGET_SCHEMA, LEGACY_SCHEMA} or not expressions or len(expressions) != len(null_checks):
        raise LoaderError("UNIQUE_SCAN_METADATA_INVALID")
    statement = sql.SQL(
        "SELECT count(*) FROM (SELECT 1 FROM {}.{} WHERE {} GROUP BY {} HAVING count(*) > 1) AS duplicate_groups"
    ).format(
        sql.Identifier(schema),
        sql.Identifier(table),
        sql.SQL(" AND ").join(null_checks),
        sql.SQL(", ").join(expressions),
    )
    return _fetch_count(target, statement, "POSTGRES_UNIQUE_SCAN_FAILED")


def _text_collation_collision_groups(target: Any, schema: str, table: str, column: str, expression: Any) -> int:
    from psycopg import sql

    statement = sql.SQL(
        "SELECT count(*) FROM (SELECT 1 FROM {}.{} WHERE {} IS NOT NULL "
        "GROUP BY {} COLLATE {} HAVING count(DISTINCT {} COLLATE \"C\") > 1) AS collation_groups"
    ).format(
        sql.Identifier(schema),
        sql.Identifier(table),
        expression,
        expression,
        sql.Identifier(TARGET_SCHEMA, "ci"),
        expression,
    )
    return _fetch_count(target, statement, "POSTGRES_COLLATION_SCAN_FAILED")


def _set_column_collation_c(target: Any, schema: str, table: str, column: str, type_sql: str) -> None:
    from psycopg import sql

    if schema not in {TARGET_SCHEMA, LEGACY_SCHEMA} or not re.fullmatch(
        r"(?:CHARACTER VARYING|VARCHAR|CHARACTER|CHAR|TEXT)(?:\(\d+\))?", type_sql, re.IGNORECASE
    ):
        raise LoaderError("COLLATION_CONVERSION_METADATA_INVALID")
    statement = sql.SQL("ALTER TABLE {}.{} ALTER COLUMN {} TYPE {} COLLATE \"C\" USING {}::{} COLLATE \"C\"").format(
        sql.Identifier(schema),
        sql.Identifier(table),
        sql.Identifier(column),
        sql.SQL(type_sql),
        sql.Identifier(column),
        sql.SQL(type_sql),
    )
    try:
        with target.cursor() as cursor:
            cursor.execute(statement)
    except Exception as error:
        raise LoaderError("POSTGRES_COLLATION_CONVERSION_FAILED", _sqlstate(error)) from error


def _apply_unique_constraints(target: Any, report: dict[str, Any], mappings: dict[str, dict[str, Any]]) -> list[dict[str, Any]]:
    from psycopg import sql

    primary_keys = report.get("primary_keys_post_load")
    unique_indexes = report.get("unique_indexes_post_load")
    if not isinstance(primary_keys, list) or not isinstance(unique_indexes, list):
        raise LoaderError("BUNDLE_UNIQUE_PLAN_INVALID")
    collision_before: dict[tuple[str, str, str], int] = {}
    conversion_by_key: dict[tuple[str, str, str], list[str]] = {}
    conversion_candidates: set[tuple[str, str, str]] = set()
    for item in primary_keys:
        if not isinstance(item, dict) or item.get("table") not in mappings:
            raise LoaderError("BUNDLE_PRIMARY_KEY_PLAN_INVALID")
        table = item["table"]
        schema = item.get("target_schema")
        names = item.get("columns")
        constraint = item.get("constraint_name")
        mapping = mappings[table]
        if schema != mapping.get("target_schema") or not isinstance(names, list) or not names or not isinstance(constraint, str) \
            or any(not isinstance(name, str) or name not in mapping["types"] for name in names):
            raise LoaderError("BUNDLE_PRIMARY_KEY_PLAN_INVALID")
        expressions = [sql.Identifier(name) for name in names]
        null_checks = [sql.SQL("{} IS NOT NULL").format(expression) for expression in expressions]
        key = ("PRIMARY_KEY", table, constraint)
        count = _duplicate_group_count(target, schema, table, expressions, null_checks)
        collision_before[key] = count
        changed: list[str] = []
        if count:
            for name in names:
                type_mapping = mapping["types"][name]
                column = next(col for col in mapping["columns"] if col.get("name") == name)
                textual = str(column.get("data_type", "")).lower() in {"char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext", "enum"}
                if textual and type_mapping.collation_sql == "ci":
                    column_groups = _text_collation_collision_groups(target, schema, table, name, sql.Identifier(name))
                    if column_groups:
                        conversion_candidates.add((schema, table, name))
                        changed.append(name)
        conversion_by_key[key] = changed
    for item in unique_indexes:
        if not isinstance(item, dict) or item.get("table") not in mappings:
            raise LoaderError("BUNDLE_UNIQUE_INDEX_PLAN_INVALID")
        table = item["table"]
        schema = item.get("target_schema")
        name = item.get("generated_name")
        mapping = mappings[table]
        if schema != mapping.get("target_schema") or not isinstance(name, str):
            raise LoaderError("BUNDLE_UNIQUE_INDEX_PLAN_INVALID")
        expressions, null_checks, ci_columns, _index_parts = _unique_index_parts(item, mapping)
        key = ("UNIQUE_INDEX", table, name)
        count = _duplicate_group_count(target, schema, table, expressions, null_checks)
        collision_before[key] = count
        changed = []
        if count:
            for column, expression in ci_columns:
                column_groups = _text_collation_collision_groups(target, schema, table, column, expression)
                if column_groups:
                    conversion_candidates.add((schema, table, column))
                    changed.append(column)
        conversion_by_key[key] = changed
    for schema, table, column in sorted(conversion_candidates):
        type_mapping = mappings[table]["types"][column]
        _set_column_collation_c(target, schema, table, column, type_mapping.sql_type)
        mappings[table]["types"][column] = replace(type_mapping, collation_sql="C")
    results: list[dict[str, Any]] = []
    unique_keys: set[tuple[str, tuple[str, ...]]] = set()
    for item in primary_keys:
        if not isinstance(item, dict) or item.get("table") not in mappings:
            raise LoaderError("BUNDLE_PRIMARY_KEY_PLAN_INVALID")
        table = item["table"]
        schema = item.get("target_schema")
        columns = item.get("columns")
        constraint = item.get("constraint_name")
        mapping = mappings[table]
        if schema != mapping.get("target_schema") or not isinstance(columns, list) or not columns or not isinstance(constraint, str):
            raise LoaderError("BUNDLE_PRIMARY_KEY_PLAN_INVALID")
        names = [name for name in columns if isinstance(name, str)]
        if len(names) != len(columns) or any(name not in mapping["types"] for name in names):
            raise LoaderError("BUNDLE_PRIMARY_KEY_PLAN_INVALID")
        from psycopg import sql as psycopg_sql
        null_predicate = psycopg_sql.SQL(" OR ").join(
            psycopg_sql.SQL("{} IS NULL").format(psycopg_sql.Identifier(name)) for name in names
        )
        null_rows = _fetch_count(
            target,
            psycopg_sql.SQL("SELECT count(*) FROM {}.{} WHERE {} IS TRUE").format(
                psycopg_sql.Identifier(schema), psycopg_sql.Identifier(table), null_predicate
            ),
            "POSTGRES_PRIMARY_KEY_NULL_SCAN_FAILED",
        )
        expressions = [psycopg_sql.Identifier(name) for name in names]
        null_checks = [psycopg_sql.SQL("{} IS NOT NULL").format(expression) for expression in expressions]
        key = ("PRIMARY_KEY", table, constraint)
        collision_groups = _duplicate_group_count(target, schema, table, expressions, null_checks)
        changed_columns = conversion_by_key.get(key, [])
        collision_groups_under_ci = collision_before.get(key, 0)
        status = "CREATED"
        if null_rows:
            status = "BLOCKED_NULL_PRIMARY_KEY"
        elif collision_groups:
            status = "BLOCKED_LITERAL_PRIMARY_KEY_COLLISION"
        else:
            statement = psycopg_sql.SQL("ALTER TABLE {}.{} ADD CONSTRAINT {} PRIMARY KEY ({})").format(
                psycopg_sql.Identifier(schema),
                psycopg_sql.Identifier(table),
                psycopg_sql.Identifier(constraint),
                psycopg_sql.SQL(", ").join(psycopg_sql.Identifier(name) for name in names),
            )
            try:
                with target.cursor() as cursor:
                    cursor.execute(statement)
            except Exception as error:
                raise LoaderError("POSTGRES_PRIMARY_KEY_CREATE_FAILED", _sqlstate(error)) from error
            unique_keys.add((table, tuple(names)))
        results.append({
            "kind": "PRIMARY_KEY",
            "table": table,
            "columns": names,
            "collision_groups_under_ci": collision_groups_under_ci,
            "remaining_duplicate_groups": collision_groups,
            "null_rows": null_rows,
            "columns_converted_to_C": changed_columns,
            "supports_foreign_key": status == "CREATED",
            "status": status,
        })

    for item in unique_indexes:
        if not isinstance(item, dict) or item.get("table") not in mappings:
            raise LoaderError("BUNDLE_UNIQUE_INDEX_PLAN_INVALID")
        table = item["table"]
        schema = item.get("target_schema")
        name = item.get("generated_name")
        mapping = mappings[table]
        if schema != mapping.get("target_schema") or not isinstance(name, str):
            raise LoaderError("BUNDLE_UNIQUE_INDEX_PLAN_INVALID")
        expressions, null_checks, ci_columns, index_parts = _unique_index_parts(item, mapping)
        key = ("UNIQUE_INDEX", table, name)
        collision_groups = _duplicate_group_count(target, schema, table, expressions, null_checks)
        changed_columns = conversion_by_key.get(key, [])
        collision_groups_under_ci = collision_before.get(key, 0)
        status = "CREATED" if not collision_groups else "BLOCKED_LITERAL_UNIQUE_COLLISION"
        if not collision_groups:
            statement = sql.SQL("CREATE UNIQUE INDEX {} ON {}.{} ({})").format(
                sql.Identifier(name),
                sql.Identifier(schema),
                sql.Identifier(table),
                sql.SQL(", ").join(index_parts),
            )
            try:
                with target.cursor() as cursor:
                    cursor.execute(statement)
            except Exception as error:
                raise LoaderError("POSTGRES_UNIQUE_INDEX_CREATE_FAILED", _sqlstate(error)) from error
            if not item.get("prefix_index"):
                columns = [str(part["name"]) for part in item["columns"]]
                unique_keys.add((table, tuple(columns)))
        results.append({
            "kind": "UNIQUE_INDEX",
            "table": table,
            "index": str(item.get("index", "")),
            "columns": [str(part.get("name", "")) for part in item["columns"]],
            "collision_groups_under_ci": collision_groups_under_ci,
            "remaining_duplicate_groups": collision_groups,
            "columns_converted_to_C": changed_columns,
            "supports_foreign_key": status == "CREATED" and not bool(item.get("prefix_index")),
            "status": status,
        })
    report["unique_constraint_results"] = results
    return results


def _apply_foreign_keys(target: Any, report: dict[str, Any], mappings: dict[str, dict[str, Any]], unique_results: list[dict[str, Any]]) -> None:
    from psycopg import sql

    foreign_keys = report.get("foreign_keys_post_load")
    if not isinstance(foreign_keys, list):
        raise LoaderError("BUNDLE_FOREIGN_KEY_PLAN_INVALID")
    unique_keys = {
        (item["table"], tuple(item["columns"]))
        for item in unique_results
        if item.get("status") == "CREATED" and item.get("supports_foreign_key") is True
    }
    results: list[dict[str, Any]] = []
    allowed = {"NO ACTION", "RESTRICT", "CASCADE", "SET NULL", "SET DEFAULT"}
    for item in foreign_keys:
        if not isinstance(item, dict):
            raise LoaderError("BUNDLE_FOREIGN_KEY_PLAN_INVALID")
        table = item.get("table")
        ref_table = item.get("referenced_table")
        columns = item.get("columns")
        ref_columns = item.get("referenced_columns")
        schema = item.get("target_schema")
        ref_schema = item.get("referenced_schema")
        constraint = item.get("constraint_name")
        update_rule = item.get("update_rule")
        delete_rule = item.get("delete_rule")
        if table not in mappings or ref_table not in mappings or not isinstance(columns, list) or not isinstance(ref_columns, list) \
            or not columns or len(columns) != len(ref_columns) or not isinstance(constraint, str) \
            or schema != mappings[table].get("target_schema") or ref_schema != mappings[ref_table].get("target_schema") \
            or update_rule not in allowed or delete_rule not in allowed:
            raise LoaderError("BUNDLE_FOREIGN_KEY_PLAN_INVALID")
        source_columns = [value for value in columns if isinstance(value, str)]
        target_columns = [value for value in ref_columns if isinstance(value, str)]
        if len(source_columns) != len(columns) or len(target_columns) != len(ref_columns) \
            or any(name not in mappings[table]["types"] for name in source_columns) \
            or any(name not in mappings[ref_table]["types"] for name in target_columns):
            raise LoaderError("BUNDLE_FOREIGN_KEY_COLUMNS_INVALID")
        if (ref_table, tuple(target_columns)) not in unique_keys:
            raise LoaderError("FK_TARGET_UNIQUE_CONSTRAINT_UNAVAILABLE")
        joins = sql.SQL(" AND ").join(
            sql.SQL("r.{} = s.{}").format(sql.Identifier(target), sql.Identifier(source))
            for source, target in zip(source_columns, target_columns, strict=True)
        )
        nonnull = sql.SQL(" AND ").join(sql.SQL("s.{} IS NOT NULL").format(sql.Identifier(source)) for source in source_columns)
        orphan_query = sql.SQL(
            "SELECT count(*) FROM {}.{} AS s WHERE {} AND NOT EXISTS (SELECT 1 FROM {}.{} AS r WHERE {})"
        ).format(sql.Identifier(schema), sql.Identifier(table), nonnull, sql.Identifier(ref_schema), sql.Identifier(ref_table), joins)
        orphan_rows = _fetch_count(target, orphan_query, "POSTGRES_FOREIGN_KEY_ORPHAN_SCAN_FAILED")
        add_constraint = sql.SQL(
            "ALTER TABLE {}.{} ADD CONSTRAINT {} FOREIGN KEY ({}) REFERENCES {}.{} ({}) "
            "ON UPDATE {} ON DELETE {} NOT VALID"
        ).format(
            sql.Identifier(schema), sql.Identifier(table), sql.Identifier(constraint),
            sql.SQL(", ").join(sql.Identifier(value) for value in source_columns),
            sql.Identifier(ref_schema), sql.Identifier(ref_table),
            sql.SQL(", ").join(sql.Identifier(value) for value in target_columns),
            sql.SQL(update_rule), sql.SQL(delete_rule),
        )
        try:
            with target.cursor() as cursor:
                cursor.execute(add_constraint)
                if orphan_rows == 0:
                    cursor.execute(sql.SQL("ALTER TABLE {}.{} VALIDATE CONSTRAINT {}").format(
                        sql.Identifier(schema), sql.Identifier(table), sql.Identifier(constraint)
                    ))
        except Exception as error:
            raise LoaderError("POSTGRES_FOREIGN_KEY_CREATE_FAILED", _sqlstate(error)) from error
        results.append({
            "table": table,
            "constraint_name": constraint,
            "referenced_schema": ref_schema,
            "referenced_table": ref_table,
            "columns": source_columns,
            "referenced_columns": target_columns,
            "update_rule": update_rule,
            "delete_rule": delete_rule,
            "orphan_rows": orphan_rows,
            "status": "VALIDATED" if orphan_rows == 0 else "NOT_VALID_ORPHANS_REPORTED",
        })
    report["foreign_key_results"] = results


def _write_report_exclusive(report: dict[str, Any], path: Path) -> None:
    try:
        root = REPORT_ROOT.resolve(strict=True)
        root_info = root.stat()
        if not stat.S_ISDIR(root_info.st_mode) or root_info.st_uid != 0 or stat.S_IMODE(root_info.st_mode) != 0o700:
            raise LoaderError("REPORT_DIRECTORY_MODE_INVALID")
        candidate = path.absolute()
        if candidate.parent.resolve(strict=True) != root or candidate.is_symlink():
            raise LoaderError("REPORT_PATH_OUTSIDE_ALLOWLIST")
        raw = (json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode("utf-8")
        fd = os.open(candidate, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "wb") as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
    except LoaderError:
        raise
    except FileExistsError as error:
        raise LoaderError("REPORT_ALREADY_EXISTS") from error
    except Exception as error:
        raise LoaderError("REPORT_WRITE_FAILED") from error


def _replace_report(report: dict[str, Any], path: Path) -> None:
    temporary: Path | None = None
    try:
        root = REPORT_ROOT.resolve(strict=True)
        candidate = path.absolute()
        if candidate.parent.resolve(strict=True) != root or candidate.is_symlink() or not candidate.is_file():
            raise LoaderError("REPORT_PATH_OUTSIDE_ALLOWLIST")
        raw = (json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode("utf-8")
        fd, name = tempfile.mkstemp(prefix=".p2-report-", dir=root)
        temporary = Path(name)
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "wb") as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, candidate)
        directory_fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
    except LoaderError:
        raise
    except Exception as error:
        raise LoaderError("REPORT_UPDATE_FAILED") from error
    finally:
        if temporary is not None:
            try:
                temporary.unlink(missing_ok=True)
            except OSError:
                pass


def _read_reconciliation_report(path: Path) -> dict[str, Any]:
    try:
        root = REPORT_ROOT.resolve(strict=True)
        root_info = root.stat()
        candidate = path.absolute()
        if root != REPORT_ROOT or not stat.S_ISDIR(root_info.st_mode) or root_info.st_uid != 0 \
            or stat.S_IMODE(root_info.st_mode) != 0o700 or candidate.parent.resolve(strict=True) != root \
            or candidate.is_symlink():
            raise LoaderError("REPORT_PATH_OUTSIDE_ALLOWLIST")
        fd = os.open(candidate, os.O_RDONLY | os.O_NOFOLLOW | getattr(os, "O_CLOEXEC", 0))
    except LoaderError:
        raise
    except Exception as error:
        raise LoaderError("LOAD_REPORT_UNAVAILABLE") from error
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o600:
            raise LoaderError("LOAD_REPORT_MODE_OR_OWNER_INVALID")
        with os.fdopen(fd, "r", encoding="utf-8", errors="strict") as stream:
            fd = -1
            result = json.load(stream)
    except LoaderError:
        raise
    except Exception as error:
        raise LoaderError("LOAD_REPORT_INVALID") from error
    finally:
        if fd >= 0:
            os.close(fd)
    if not isinstance(result, dict):
        raise LoaderError("LOAD_REPORT_INVALID")
    return result


def _validate_source_snapshot(current: dict[str, Any], expected: dict[str, Any]) -> None:
    if current.get("structural_fingerprint_sha256") != expected.get("structural_fingerprint_sha256"):
        raise LoaderError("SOURCE_CATALOG_DRIFT_FROM_BUNDLE")
    if current.get("source_schema") != SOURCE_SCHEMA \
        or current.get("source_grant_policy_id") != SOURCE_GRANT_POLICY_ID \
        or expected.get("source_grant_policy_id") != SOURCE_GRANT_POLICY_ID \
        or current.get("source_grants_policy_accepted") is not True \
        or expected.get("source_grants_policy_accepted") is not True \
        or current.get("source_routines_events_zero_verified") is not True \
        or expected.get("source_routines_events_zero_verified") is not True:
        raise LoaderError("SOURCE_CATALOG_PROOF_INVALID")
    if current.get("source_schema_wide_select") is not True \
        or expected.get("source_schema_wide_select") is not True:
        raise LoaderError("SOURCE_SCHEMA_SELECT_PROOF_INVALID")
    current_visibility = current.get("metadata_visibility")
    expected_visibility = expected.get("metadata_visibility")
    if not isinstance(current_visibility, dict) or not isinstance(expected_visibility, dict) \
        or any(current_visibility.get(key) is not True or expected_visibility.get(key) is not True for key in ("views", "triggers")) \
        or any(current_visibility.get(key) is not False or expected_visibility.get(key) is not False for key in ("routines", "events")) \
        or current.get("routines") != [] or expected.get("routines") != [] \
        or current.get("events") != [] or expected.get("events") != []:
        raise LoaderError("SOURCE_METADATA_VISIBILITY_UNPROVEN")
    if current.get("catalog_consistency_check") != "PRE_POST_SIGNATURE_MATCH" \
        or expected.get("catalog_consistency_check") != "PRE_POST_SIGNATURE_MATCH":
        raise LoaderError("SOURCE_CATALOG_CONSISTENCY_UNPROVEN")
    timestamp_contract = {"source_session": "UTC", "target_wall_time": "America/Sao_Paulo"}
    if current.get("timestamp_transfer_contract") != timestamp_contract \
        or expected.get("timestamp_transfer_contract") != timestamp_contract:
        raise LoaderError("SOURCE_TIMESTAMP_TRANSFER_CONTRACT_INVALID")


def run_preflight(bundle_name: str, defaults_file: str | None = None, profile: str = "development") -> dict[str, Any]:
    bundle, report, snapshot = validate_bundle(bundle_name, require_executable=False)
    target_config = _load_target_config(profile)
    source_defaults = _source_defaults_path(defaults_file)
    source, current_snapshot = _connect_source(source_defaults)
    memory_guard = _MemoryGuard()
    nul_findings: list[dict[str, Any]] = []
    binary_findings: list[dict[str, Any]] = []
    try:
        _validate_source_snapshot(current_snapshot, snapshot)
        memory_guard.start_monitor(None)
        nul_findings = _scan_source_text_policy(source, snapshot, memory_guard)
        binary_findings = _scan_source_binary_policy(source, snapshot, memory_guard)
        source_report: list[dict[str, Any]] = []
        try:
            for table in snapshot["tables"]:
                if table.get("type") != "BASE TABLE":
                    continue
                name = str(table["name"])
                with source.cursor() as cursor:
                    cursor.execute(f"SELECT COUNT(*) AS row_count FROM {_mysql_ident(SOURCE_SCHEMA)}.{_mysql_ident(name)}")
                    row = cursor.fetchone()
                source_report.append({"table": name, "rows": _source_count_value(row)})
        finally:
            source.rollback()
    finally:
        memory_guard.stop_monitor()
        source.close()

    if report.get("application_role_sha256") != hashlib.sha256(target_config["application_user"].encode("utf-8")).hexdigest():
        raise LoaderError("BUNDLE_APPLICATION_ROLE_MISMATCH")
    target = _connect_target(target_config)
    try:
        target_summary = _target_preflight(target, target_config["user"], target_config["application_user"], target_config["dbname"])
        with target.cursor() as cursor:
            cursor.execute("SELECT count(*) FROM pg_namespace WHERE nspname = %s", (TARGET_SCHEMA,))
            target_schema_present = bool(cursor.fetchone()[0])
        target_summary["target_schema_present"] = target_schema_present
    finally:
        target.close()

    return {
        "status": "PREFLIGHT_READ_ONLY",
        "profile": profile,
        "source_schema": SOURCE_SCHEMA,
        "target_database": target_config["dbname"],
        "bundle_executable_complete": report.get("executable_complete") is True,
        "bundle_load_executable_complete": report.get("load_executable_complete") is True,
        "bundle_review_items": len(report.get("review_items", [])),
        "bundle_blocked_tables": len(report.get("blocked_tables", [])),
        "source_catalog_matches_bundle": True,
        "text_policy_findings": nul_findings,
        "binary_policy_findings": binary_findings,
        "text_nul_detector_methods": {
            "method_a": "legacy Python string membership (U+0000 in decoded value)",
            "method_b": "MySQL SUM(INSTR(BINARY column, 0x00) > 0)",
            "method_c": "Python b'\\x00' in bytes from CAST(column AS BINARY)",
        },
        "binary_nul_detector_methods": {
            "method_b": "MySQL SUM(INSTR(BINARY column, 0x00) > 0)",
            "method_c": "Python b'\\x00' in bytes from CAST(column AS BINARY)",
            "normalization": "none; COPY to BYTEA preserves source bytes",
        },
        "target_scope": target_summary,
        "source_table_counts": source_report,
    }


def run_load(bundle_name: str, defaults_file: str | None = None, report_path: Path | None = None, profile: str = "development") -> dict[str, Any]:
    if report_path is None:
        raise LoaderError("LOAD_REPORT_FILE_REQUIRED")
    _require_load_runtime()
    bundle, report, snapshot = validate_bundle(bundle_name, require_executable=True)
    source_defaults = _source_defaults_path(defaults_file)
    target_config = _load_target_config(profile)
    if report.get("application_role_sha256") != hashlib.sha256(target_config["application_user"].encode("utf-8")).hexdigest():
        raise LoaderError("BUNDLE_APPLICATION_ROLE_MISMATCH")
    table_names = list(report["generated_tables"])
    mappings = _validate_column_mappings(snapshot, report, table_names)
    _validated_identity_plan(snapshot, table_names, mappings, (report.get("auto_increment") or {}).get("columns"))
    table_map = {item["name"]: item for item in snapshot["tables"] if item.get("type") == "BASE TABLE"}
    started_at = time.time()
    memory_guard = _MemoryGuard()
    initial_report = {
        "status": "STARTED",
        "profile": profile,
        "source_schema": SOURCE_SCHEMA,
        "target_database": target_config["dbname"],
        "target_schema": TARGET_SCHEMA,
        "legacy_schema": LEGACY_SCHEMA,
        "bundle_catalog_fingerprint": report.get("catalog_fingerprint_sha256"),
        "started_at_epoch": int(started_at),
        "available_memory_mib_start": memory_guard.sample(),
        "text_policy_findings": [],
        "binary_policy_findings": [],
        "table_failures": [],
    }
    _write_report_exclusive(initial_report, report_path)
    memory_guard.start_monitor(None)
    source = None
    target = None
    copied_rows: list[dict[str, Any]] = []
    normalization_counts: dict[tuple[str, str], int] = {}
    nul_normalization_counts: dict[tuple[str, str], dict[str, Any]] = {}
    enum_value_counts: dict[tuple[str, str, str], int] = {}
    nul_spool: Any | None = None
    nul_audit_rows = 0
    nul_spool_cleanup_confirmed: bool | None = None
    committed = False
    rollback_confirmed = True
    source_cleanup_confirmed: bool | None = None
    target_cleanup_confirmed: bool | None = None
    table_failures: list[dict[str, Any]] = []
    skipped_by_policy: dict[str, list[dict[str, Any]]] = {}
    try:
        source, current_snapshot = _connect_source(source_defaults)
        _validate_source_snapshot(current_snapshot, snapshot)
        text_policy_findings = _scan_source_text_policy(source, snapshot, memory_guard)
        binary_policy_findings = _scan_source_binary_policy(source, snapshot, memory_guard)
        initial_report["text_policy_findings"] = text_policy_findings
        initial_report["binary_policy_findings"] = binary_policy_findings
        policy_failures = _text_policy_table_failures(text_policy_findings) + _binary_policy_table_failures(binary_policy_findings)
        for finding in policy_failures:
            skipped_by_policy.setdefault(finding["table"], []).append(finding)
            table_failures.append({**finding, "source_rows": None, "target_rows": None})
        initial_report["table_failures"] = policy_failures
        _replace_report(initial_report, report_path)
        nul_spool = tempfile.TemporaryFile(mode="w+b", prefix="p2-nul-audit-", dir=str(REPORT_ROOT))
        target = _connect_target(target_config)
        memory_guard.set_connection(target)
        target_preflight = _target_preflight(target, target_config["user"], target_config["application_user"], target_config["dbname"])
        if profile == "cutover":
            _consume_cutover_execution_marker(target_config["dbname"])
        target.autocommit = False
        import psycopg
        from psycopg import sql
        try:
            rollback_confirmed = False
            with target.transaction():
                with target.cursor() as cursor:
                    cursor.execute("SELECT pg_advisory_xact_lock(%s, %s)", (20261117, 2026))
                    cursor.execute("SELECT current_database()")
                    if cursor.fetchone()[0] != target_config["dbname"]:
                        raise LoaderError("POSTGRES_TARGET_DATABASE_MISMATCH")
                    for schema_name in (TARGET_SCHEMA, LEGACY_SCHEMA):
                        cursor.execute(sql.SQL("DROP SCHEMA IF EXISTS {} CASCADE").format(sql.Identifier(schema_name)))
                memory_guard.sample()
                _execute_bundle_script(
                    target,
                    bundle / "00_tables.sql",
                    report["generated_scripts_sha256"]["00_tables.sql"],
                )
                _verify_application_legacy_isolation(target, target_config["application_user"])
                target_preflight["legacy_audit_access_revoked"] = True
                memory_guard.sample()
                no_backslash_escapes = bool(snapshot.get("source_no_backslash_escapes"))
                for table_name in table_names:
                    memory_guard.sample()
                    policy_reasons = skipped_by_policy.get(table_name, [])
                    if policy_reasons:
                        try:
                            source_rows_expected = _source_row_count(source, table_name)
                        except LoaderError:
                            source_rows_expected = None
                        for item in table_failures:
                            if item.get("table") == table_name and item.get("source_rows") is None:
                                item["source_rows"] = source_rows_expected
                                item["target_rows"] = 0
                        copied_rows.append({"table": table_name, "source_rows": source_rows_expected, "target_rows": 0, "status": "SKIPPED_POLICY"})
                        continue

                    spool_offset = nul_spool.tell()
                    normalization_before = dict(normalization_counts)
                    nul_before = copy.deepcopy(nul_normalization_counts)
                    enum_before = dict(enum_value_counts)
                    try:
                        with target.transaction():
                            copied = _copy_table(
                                source,
                                target,
                                table_map[table_name],
                                mappings[table_name],
                                no_backslash_escapes,
                                memory_guard,
                                normalization_counts,
                                nul_normalization_counts,
                                nul_spool,
                                enum_value_counts,
                            )
                            memory_guard.sample()
                            target_count = _row_count(target, table_name, mappings[table_name].get("target_schema", TARGET_SCHEMA))
                            if target_count != copied:
                                raise LoaderError("TARGET_ROW_COUNT_MISMATCH")
                    except Exception as caught:
                        error = caught if isinstance(caught, LoaderError) else LoaderError("TABLE_COPY_FAILED", _sqlstate(caught))
                        nul_spool.seek(spool_offset)
                        nul_spool.truncate()
                        normalization_counts.clear()
                        normalization_counts.update(normalization_before)
                        nul_normalization_counts.clear()
                        nul_normalization_counts.update(nul_before)
                        enum_value_counts.clear()
                        enum_value_counts.update(enum_before)
                        source_rows_expected = getattr(error, "source_rows", None)
                        if source_rows_expected is None:
                            try:
                                source_rows_expected = _source_row_count(source, table_name)
                            except LoaderError:
                                source_rows_expected = None
                        target_count = _row_count(target, table_name, mappings[table_name].get("target_schema", TARGET_SCHEMA))
                        failure = {
                            "table": table_name,
                            "column": getattr(error, "column", None),
                            "reason": error.code,
                            "source_rows": source_rows_expected,
                            "target_rows": target_count,
                        }
                        table_failures.append(failure)
                        copied_rows.append({"table": table_name, "source_rows": source_rows_expected, "target_rows": target_count, "status": "FAILED"})
                        continue
                    memory_guard.sample()
                    copied_rows.append({"table": table_name, "source_rows": copied, "target_rows": target_count, "status": "COPIED"})
                memory_guard.sample()
                report["zero_date_nullability_results"] = _restore_temporal_not_null(
                    target, report, mappings, normalization_counts
                )
                if nul_normalization_counts:
                    nul_audit_rows = _apply_nul_audit(target, nul_spool, mappings)
                memory_guard.sample()
                _apply_enum_checks(target, report, mappings, enum_value_counts)
                memory_guard.sample()
                unique_results = _apply_unique_constraints(target, report, mappings)
                memory_guard.sample()
                for script_name in SCRIPT_NAMES[1:]:
                    memory_guard.sample()
                    _execute_bundle_script(
                        target,
                        bundle / script_name,
                        report["generated_scripts_sha256"][script_name],
                    )
                    memory_guard.sample()
                _apply_foreign_keys(target, report, mappings, unique_results)
                memory_guard.sample()
        except LoaderError:
            raise
        except Exception as error:
            raise LoaderError("POSTGRES_REBUILD_TRANSACTION_FAILED", _sqlstate(error)) from error
        committed = True
        source_cleanup_confirmed = True
        if source is not None:
            try:
                source.rollback()
            except Exception:
                source_cleanup_confirmed = False
            try:
                source.close()
            except Exception:
                source_cleanup_confirmed = False
            else:
                source = None

        if target is not None:
            try:
                target.close()
                target_cleanup_confirmed = True
            except Exception:
                target_cleanup_confirmed = False
            else:
                target = None

        try:
            if nul_spool is not None:
                nul_spool.close()
            nul_spool_cleanup_confirmed = True
        except Exception:
            nul_spool_cleanup_confirmed = False

        if source_cleanup_confirmed is not True or target_cleanup_confirmed is not True or nul_spool_cleanup_confirmed is not True:
            raise LoaderError("POST_LOAD_CLEANUP_UNCONFIRMED")

        report_result = {
            "status": "LOAD_PARTIAL_PENDING_RECONCILIATION" if table_failures else "LOAD_COMMITTED_PENDING_RECONCILIATION",
            "profile": profile,
            "source_schema": SOURCE_SCHEMA,
            "target_database": target_config["dbname"],
            "target_schema": TARGET_SCHEMA,
            "bundle_catalog_fingerprint": report.get("catalog_fingerprint_sha256"),
            "generator_version": report.get("generator_version"),
            "tables_loaded": sum(item.get("status") == "COPIED" for item in copied_rows),
            "rows_loaded": sum(item["source_rows"] or 0 for item in copied_rows if item.get("status") == "COPIED"),
            "elapsed_seconds": round(time.time() - started_at, 3),
            "available_memory_mib_end": _available_memory_mib_or_none(),
            "source_cleanup_confirmed": source_cleanup_confirmed,
            "target_cleanup_confirmed": target_cleanup_confirmed,
            "nul_spool_cleanup_confirmed": nul_spool_cleanup_confirmed,
            "nul_audit_rows": nul_audit_rows,
            "table_counts": copied_rows,
            "table_failures": table_failures,
            "text_policy_findings": initial_report.get("text_policy_findings", []),
            "binary_policy_findings": initial_report.get("binary_policy_findings", []),
            "nul_values_normalized": _nul_report_rows(nul_normalization_counts),
            "zero_dates_normalized": _zero_date_report_rows(normalization_counts),
            "zero_date_nullability_results": report.get("zero_date_nullability_results", []),
            "enum_values_outside_declared": _enum_extension_report_rows(enum_value_counts),
            "unique_constraint_results": report.get("unique_constraint_results", []),
            "foreign_key_results": report.get("foreign_key_results", []),
            "target_scope": target_preflight,
        }
        _replace_report(report_result, report_path)
        return report_result
    except LoaderError as error:
        reported_error = error
        try:
            memory_guard._raise_if_stopped()
        except LoaderError as memory_error:
            reported_error = memory_error
        if target is not None and not committed:
            try:
                target.rollback()
                rollback_confirmed = True
            except Exception as rollback_error:
                rollback_confirmed = False
                if reported_error.code != "POSTGRES_ROLLBACK_UNCONFIRMED":
                    reported_error = LoaderError("POSTGRES_ROLLBACK_UNCONFIRMED", _sqlstate(rollback_error))
        if target is not None:
            try:
                target.close()
                target_cleanup_confirmed = True
            except Exception:
                target_cleanup_confirmed = False
            else:
                target = None
        if source is not None:
            try:
                source.rollback()
                if source_cleanup_confirmed is not False:
                    source_cleanup_confirmed = True
            except Exception:
                source_cleanup_confirmed = False
            try:
                source.close()
                if source_cleanup_confirmed is not False:
                    source_cleanup_confirmed = True
            except Exception:
                source_cleanup_confirmed = False
            else:
                source = None
        try:
            if nul_spool is not None:
                nul_spool.close()
            nul_spool_cleanup_confirmed = True
        except Exception:
            nul_spool_cleanup_confirmed = False
        failed = {
            **initial_report,
            "status": ("LOAD_COMMITTED_CLEANUP_UNCONFIRMED" if reported_error.code == "POST_LOAD_CLEANUP_UNCONFIRMED" else "LOAD_COMMITTED_REPORT_UPDATE_FAILED") if committed else (
                "FAILED_ROLLED_BACK" if rollback_confirmed else "FAILED_ROLLBACK_UNCONFIRMED"
            ),
            "failure_code": reported_error.code,
            "sqlstate": reported_error.sqlstate,
            "table_failures": table_failures,
            "elapsed_seconds": round(time.time() - started_at, 3),
            "available_memory_mib_end": _available_memory_mib_or_none(),
            "source_cleanup_confirmed": source_cleanup_confirmed,
            "target_cleanup_confirmed": target_cleanup_confirmed,
            "nul_spool_cleanup_confirmed": nul_spool_cleanup_confirmed,
            "nul_values_normalized": _nul_report_rows(nul_normalization_counts),
            "text_policy_findings": initial_report.get("text_policy_findings", []),
            "binary_policy_findings": initial_report.get("binary_policy_findings", []),
            "zero_dates_normalized": _zero_date_report_rows(normalization_counts),
            "zero_date_nullability_results": report.get("zero_date_nullability_results", []),
            "enum_values_outside_declared": _enum_extension_report_rows(enum_value_counts),
        }
        try:
            _replace_report(failed, report_path)
        except LoaderError:
            pass
        if reported_error is not error:
            raise reported_error from error
        raise
    finally:
        memory_guard.stop_monitor()
        if source is not None:
            try:
                source.rollback()
            except Exception:
                pass
            try:
                source.close()
            except Exception:
                pass
        if target is not None:
            try:
                target.close()
            except Exception:
                pass
        if nul_spool is not None and not nul_spool.closed:
            try:
                nul_spool.close()
            except Exception:
                pass


def _validated_identity_plan(snapshot: dict[str, Any], table_names: list[str], mappings: dict[str, dict[str, Any]],
                             identity_plan: Any) -> dict[str, list[dict[str, Any]]]:
    if not isinstance(identity_plan, list):
        raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")
    snapshot_tables = snapshot.get("tables")
    if not isinstance(snapshot_tables, list):
        raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")
    snapshot_by_name = {
        item.get("name"): item for item in snapshot_tables
        if isinstance(item, dict) and isinstance(item.get("name"), str)
    }
    if len(snapshot_by_name) != len(snapshot_tables):
        raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")
    expected_identity_keys: set[tuple[str, str]] = set()
    for table_name in table_names:
        table = snapshot_by_name.get(table_name)
        if not isinstance(table, dict) or not isinstance(table.get("columns"), list):
            raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")
        table_auto_increment_found = False
        for column in table["columns"]:
            if not isinstance(column, dict) or not isinstance(column.get("name"), str):
                raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")
            if "auto_increment" in str(column.get("extra", "")).lower():
                expected_identity_keys.add((table_name, column["name"]))
                table_auto_increment_found = True
        if table_auto_increment_found and "source_next_auto_increment" not in table:
            raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")

    identities_by_table: dict[str, list[dict[str, Any]]] = {}
    seen_identity_keys: set[tuple[str, str]] = set()
    for item in identity_plan:
        if not isinstance(item, dict) or not isinstance(item.get("table"), str) or not isinstance(item.get("column"), str):
            raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")
        table_name, column_name = item["table"], item["column"]
        key = (table_name, column_name)
        strategy = item.get("strategy")
        if key in seen_identity_keys or key not in expected_identity_keys or table_name not in mappings \
            or column_name not in mappings[table_name]["types"] or strategy not in {"identity", "unsigned_sequence_pair"} \
            or item.get("target_schema") != mappings[table_name]["target_schema"]:
            raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")
        seen_identity_keys.add(key)
        source_next = item.get("source_next_auto_increment")
        max_next = MYSQL_UNSIGNED_BIGINT_MAX if strategy == "unsigned_sequence_pair" else POSTGRES_SEQUENCE_MAX
        snapshot_next = snapshot_by_name[table_name]["source_next_auto_increment"]
        if source_next != snapshot_next:
            raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")
        if source_next is not None and (
            isinstance(source_next, bool) or not isinstance(source_next, int) or source_next < 1 or source_next > max_next
        ):
            raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")
        low_sequence, high_sequence = item.get("low_sequence"), item.get("high_sequence")
        if strategy == "identity" and (low_sequence is not None or high_sequence is not None):
            raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")
        if strategy == "unsigned_sequence_pair" and (
            not isinstance(low_sequence, str) or not re.fullmatch(r"[a-z_][a-z0-9_]{0,62}", low_sequence)
            or not isinstance(high_sequence, str) or not re.fullmatch(r"[a-z_][a-z0-9_]{0,62}", high_sequence)
        ):
            raise LoaderError("BUNDLE_IDENTITY_PLAN_INVALID")
        identities_by_table.setdefault(table_name, []).append({
            "column": column_name,
            "strategy": strategy,
            "low_sequence": low_sequence,
            "high_sequence": high_sequence,
        })
    if seen_identity_keys != expected_identity_keys:
        raise LoaderError("BUNDLE_IDENTITY_PLAN_INCOMPLETE")
    return identities_by_table


def run_reconcile(bundle_name: str, defaults_file: str | None, report_path: Path, profile: str = "development") -> dict[str, Any]:
    from .reconcile import reconcile

    started_at = time.time()
    bundle, bundle_report, snapshot = validate_bundle(bundle_name, require_executable=True)
    del bundle
    report = _read_reconciliation_report(report_path)
    if report.get("profile", "development") != profile:
        raise LoaderError("LOAD_PROFILE_MISMATCH")
    if report.get("status") not in {
        "LOAD_COMMITTED_PENDING_RECONCILIATION",
        "LOAD_PARTIAL_PENDING_RECONCILIATION",
        "LOAD_COMMITTED_RECONCILED",
        "LOAD_COMMITTED_RECONCILIATION_DIVERGED",
        "LOAD_COMMITTED_RECONCILIATION_BLOCKED",
        "LOAD_PARTIAL_RECONCILIATION_DIVERGED",
        "LOAD_PARTIAL_RECONCILIATION_BLOCKED",
    }:
        raise LoaderError("LOAD_COMMIT_EVIDENCE_INVALID")
    if report.get("bundle_catalog_fingerprint") != bundle_report.get("catalog_fingerprint_sha256"):
        raise LoaderError("LOAD_SNAPSHOT_MISMATCH")

    source_defaults = _source_defaults_path(defaults_file)
    target_config = _load_target_config(profile)
    if bundle_report.get("application_role_sha256") != hashlib.sha256(target_config["application_user"].encode("utf-8")).hexdigest():
        raise LoaderError("BUNDLE_APPLICATION_ROLE_MISMATCH")
    table_names = list(bundle_report.get("generated_tables", []))
    mappings = _validate_column_mappings(snapshot, bundle_report, table_names)
    identity_plan = (bundle_report.get("auto_increment") or {}).get("columns")
    identities_by_table = _validated_identity_plan(snapshot, table_names, mappings, identity_plan)
    for table_name, mapping in mappings.items():
        mapping["identity_columns"] = identities_by_table.get(table_name, [])

    source = None
    target = None
    memory_guard = _MemoryGuard()
    result: dict[str, Any] | None = None
    cleanup_failures: list[str] = []
    prior_status = report.get("status")
    load_was_partial = prior_status == "LOAD_PARTIAL_PENDING_RECONCILIATION" or bool(report.get("table_failures"))
    try:
        if any(report.get(key) is not True for key in (
            "source_cleanup_confirmed",
            "target_cleanup_confirmed",
            "nul_spool_cleanup_confirmed",
        )):
            raise LoaderError("LOAD_CLEANUP_UNCONFIRMED")
        source, current_snapshot = _connect_source(source_defaults)
        _validate_source_snapshot(current_snapshot, snapshot)
        target = _connect_target(target_config)
        with target.cursor() as cursor:
            cursor.execute("BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY")
        memory_guard.start_monitor(target)
        memory_guard.sample()
        def _reconcile_checkpoint(active_connection: Any) -> None:
            memory_guard.set_connection(active_connection)
            memory_guard.sample_if_due()

        comparison_report = dict(report)
        if comparison_report.get("table_failures"):
            comparison_report["status"] = "LOAD_PARTIAL_PENDING_RECONCILIATION"
        elif comparison_report.get("status") not in {"LOAD_PARTIAL_PENDING_RECONCILIATION", "LOAD_COMMITTED_PENDING_RECONCILIATION"}:
            comparison_report["status"] = "LOAD_COMMITTED_PENDING_RECONCILIATION"
        result = reconcile(
            source, target, snapshot, comparison_report, mappings,
            checkpoint=_reconcile_checkpoint,
            temp_root=REPORT_ROOT,
            expected_target_database=target_config["dbname"],
        )
        if load_was_partial and result.get("status") == "RECONCILED":
            result = {"status": "BLOCKED", "failure_code": "PARTIAL_LOAD_CANNOT_BE_ACCEPTED"}
        memory_guard._raise_if_stopped()
        result["elapsed_seconds_total"] = round(time.time() - started_at, 3)
        result["available_memory_mib_end"] = _available_memory_mib_or_none()
        if result.get("status") == "RECONCILED":
            report["status"] = "LOAD_COMMITTED_RECONCILED"
        elif result.get("status") == "DIVERGED":
            report["status"] = "LOAD_PARTIAL_RECONCILIATION_DIVERGED" if load_was_partial else "LOAD_COMMITTED_RECONCILIATION_DIVERGED"
        else:
            report["status"] = "LOAD_PARTIAL_RECONCILIATION_BLOCKED" if load_was_partial else "LOAD_COMMITTED_RECONCILIATION_BLOCKED"
        report["reconciliation"] = result
        report["reconciliation_previous_status"] = prior_status
    except LoaderError as error:
        failure = {
            "status": "BLOCKED",
            "failure_code": error.code,
            "elapsed_seconds_total": round(time.time() - started_at, 3),
            "available_memory_mib_end": _available_memory_mib_or_none(),
        }
        report["status"] = "LOAD_PARTIAL_RECONCILIATION_BLOCKED" if load_was_partial else "LOAD_COMMITTED_RECONCILIATION_BLOCKED"
        report["reconciliation"] = failure
        report["reconciliation_previous_status"] = prior_status
        result = failure
    except Exception:
        failure = {
            "status": "BLOCKED",
            "failure_code": "RECONCILIATION_RUNNER_FAILURE",
            "elapsed_seconds_total": round(time.time() - started_at, 3),
            "available_memory_mib_end": _available_memory_mib_or_none(),
        }
        report["status"] = "LOAD_PARTIAL_RECONCILIATION_BLOCKED" if load_was_partial else "LOAD_COMMITTED_RECONCILIATION_BLOCKED"
        report["reconciliation"] = failure
        report["reconciliation_previous_status"] = prior_status
        result = failure
    finally:
        try:
            memory_guard.stop_monitor()
        except Exception:
            cleanup_failures.append("MEMORY_MONITOR")
        if target is not None:
            try:
                target.rollback()
            except Exception:
                cleanup_failures.append("TARGET_ROLLBACK")
            try:
                target.close()
            except Exception:
                cleanup_failures.append("TARGET_CLOSE")
        if source is not None:
            try:
                source.rollback()
            except Exception:
                cleanup_failures.append("SOURCE_ROLLBACK")
            try:
                source.close()
            except Exception:
                cleanup_failures.append("SOURCE_CLOSE")

    if result is None:
        result = {"status": "BLOCKED", "failure_code": "RECONCILIATION_RUNNER_FAILURE"}
    if cleanup_failures:
        result = {
            "status": "BLOCKED",
            "failure_code": "RECONCILIATION_CLEANUP_FAILED",
            "cleanup_failures": cleanup_failures,
            "elapsed_seconds_total": round(time.time() - started_at, 3),
            "available_memory_mib_end": _available_memory_mib_or_none(),
        }
        report["status"] = "LOAD_PARTIAL_RECONCILIATION_BLOCKED" if load_was_partial else "LOAD_COMMITTED_RECONCILIATION_BLOCKED"
        report["reconciliation"] = result
        report["reconciliation_previous_status"] = prior_status

    try:
        _replace_report(report, report_path)
    except LoaderError:
        return {
            "status": "BLOCKED",
            "failure_code": "RECONCILIATION_REPORT_WRITE_FAILED",
            "elapsed_seconds_total": round(time.time() - started_at, 3),
            "available_memory_mib_end": _available_memory_mib_or_none(),
        }
    return result


def _parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Loader transacional do P2/P5; source fixo em erp_pg_source")
    parser.add_argument("command", choices=("preflight", "load", "reconcile"))
    parser.add_argument("--profile", choices=("development", "cutover"), default="development")
    parser.add_argument("--bundle-name", required=True)
    parser.add_argument("--source-defaults-file")
    parser.add_argument("--report-file")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = _parse_args(argv)
    try:
        if args.command == "preflight":
            result = run_preflight(args.bundle_name, args.source_defaults_file, args.profile)
        elif args.command == "load":
            if not args.report_file:
                raise LoaderError("LOAD_REPORT_FILE_REQUIRED")
            result = run_load(args.bundle_name, args.source_defaults_file, Path(args.report_file), args.profile)
        else:
            if not args.report_file:
                raise LoaderError("LOAD_REPORT_FILE_REQUIRED")
            result = run_reconcile(args.bundle_name, args.source_defaults_file, Path(args.report_file), args.profile)
        print(json.dumps(result, ensure_ascii=False, sort_keys=True))
        return 0 if args.command != "reconcile" or result.get("status") == "RECONCILED" else 2
    except LoaderError as error:
        print(f"LOADER_ERROR={error.code}", file=sys.stderr)
        return 2
    except Exception:
        print("LOADER_ERROR=UNEXPECTED_FAILURE", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
