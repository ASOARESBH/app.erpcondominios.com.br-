from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import threading
import time
from typing import Any

if os.geteuid() == 0 and os.environ.get("ERP_P2_ROOT_LAUNCHER_VERIFIED") != "1":
    raise SystemExit("LOADER_ROOT_EXECUTION_REQUIRES_REBUILD_RUNNER")

from .catalog import CatalogError, SOURCE_SCHEMA, _mysql_socket_options, _validate_defaults_file, collect_catalog
from .ddl import GENERATOR_VERSION, TARGET_SCHEMA, canonical_fingerprint
from .generate_ddl import _generator_fingerprint
from .type_mapping import (
    TemporalValueError,
    TypeMappingError,
    map_mysql_type,
    normalize_temporal_value,
    parse_mysql_enum,
)

REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
BUNDLE_ROOT = REPOSITORY_ROOT / "sql" / "postgresql"
TARGET_DATABASE = "erp_pg_dev"
TARGET_CONFIG = Path("/etc/erpcondominios-pg/postgresql/erp_config_pg_dev.php")
SOURCE_SECRET_ROOT = Path("/etc/erpcondominios-pg/secrets")
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
    if require_executable and report.get("executable_complete") is not True:
        raise LoaderError("BUNDLE_REQUIRES_REVIEW")
    if report.get("source_schema") != SOURCE_SCHEMA or snapshot.get("source_schema") != SOURCE_SCHEMA:
        raise LoaderError("BUNDLE_SOURCE_SCHEMA_MISMATCH")
    if report.get("target_schema") != TARGET_SCHEMA:
        raise LoaderError("BUNDLE_TARGET_SCHEMA_MISMATCH")
    if snapshot.get("catalog_version") != 2 or canonical_fingerprint(snapshot) != snapshot.get("fingerprint_sha256"):
        raise LoaderError("BUNDLE_CATALOG_FINGERPRINT_MISMATCH")
    if report.get("catalog_fingerprint_sha256") != snapshot.get("fingerprint_sha256"):
        raise LoaderError("BUNDLE_REPORT_CATALOG_MISMATCH")
    collector_path = REPOSITORY_ROOT / "tools" / "migracao_pg" / "catalog.py"
    if snapshot.get("collector_code_sha256") != hashlib.sha256(collector_path.read_bytes()).hexdigest():
        raise LoaderError("BUNDLE_COLLECTOR_CODE_MISMATCH")
    if snapshot.get("source_grants_read_only") is not True or snapshot.get("source_schema_wide_select") is not True:
        raise LoaderError("BUNDLE_SOURCE_GRANT_PROOF_MISSING")
    visibility = snapshot.get("metadata_visibility")
    if not isinstance(visibility, dict) or any(visibility.get(key) is not True for key in ("views", "triggers", "routines", "events")):
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
        if not raw.startswith(b"-- REVIEW ARTIFACT: DO NOT APPLY until report.json has executable_complete=true and required reviews are approved.\n"):
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
                connection.cancel_safe(timeout=5)
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


def _load_target_config() -> dict[str, Any]:
    path = TARGET_CONFIG
    if path.is_symlink() or not path.is_file():
        raise LoaderError("TARGET_CONFIG_UNAVAILABLE")
    for directory in (path.parent, *path.parent.parents):
        if directory == Path("/"):
            break
        try:
            parent_info = directory.lstat()
        except OSError as error:
            raise LoaderError("TARGET_CONFIG_DIRECTORY_INVALID") from error
        if (
            not stat.S_ISDIR(parent_info.st_mode)
            or parent_info.st_uid != 0
            or stat.S_IMODE(parent_info.st_mode) & 0o022
            or directory.is_symlink()
        ):
            raise LoaderError("TARGET_CONFIG_DIRECTORY_INVALID")
    try:
        info = path.stat()
    except OSError as error:
        raise LoaderError("TARGET_CONFIG_UNAVAILABLE") from error
    if not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o600:
        raise LoaderError("TARGET_CONFIG_MODE_INVALID")
    try:
        import pwd
        root_uid = pwd.getpwnam("root").pw_uid
        service_user = pwd.getpwnam("erp-pg")
        owner = pwd.getpwuid(info.st_uid).pw_name
    except Exception as error:
        raise LoaderError("TARGET_CONFIG_OWNER_INVALID") from error
    if owner not in {"root", "erp-pg"} or (owner == "root" and info.st_uid != root_uid):
        raise LoaderError("TARGET_CONFIG_OWNER_INVALID")
    if os.geteuid() != 0 and info.st_uid != os.geteuid():
        raise LoaderError("TARGET_CONFIG_NOT_READABLE_BY_CALLER")

    php = r'''require $argv[1];
$cfg = [
  "driver" => ERP_DB_DRIVER,
  "host" => ERP_PG_HOST,
  "port" => ERP_PG_PORT,
  "dbname" => ERP_PG_NAME,
  "user" => ERP_PG_USER,
  "password" => ERP_PG_PASS,
  "schema" => ERP_PG_SCHEMA,
  "timezone" => ERP_TIMEZONE
];
    echo json_encode($cfg, JSON_THROW_ON_ERROR);
'''
    php_path = shutil.which("php", path="/usr/bin:/bin")
    if not php_path:
        raise LoaderError("PHP_CLI_UNAVAILABLE")
    command = [php_path, "-n", "-r", php, str(path)]
    child_env = {"PATH": "/usr/bin:/bin", "HOME": "/"}
    if os.geteuid() == 0 and owner == "erp-pg":
        runuser_path = shutil.which("runuser", path="/usr/sbin:/usr/bin:/bin")
        if not runuser_path:
            raise LoaderError("RUNUSER_UNAVAILABLE")
        command = [runuser_path, "-u", "erp-pg", "--", *command]
        child_env["HOME"] = service_user.pw_dir
    elif os.geteuid() == 0 and info.st_uid != root_uid:
        raise LoaderError("TARGET_CONFIG_OWNER_INVALID")
    try:
        result = subprocess.run(
            command,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            check=False,
            timeout=10,
            text=True,
            encoding="utf-8",
            env=child_env,
        )
        if result.returncode != 0:
            raise LoaderError("TARGET_CONFIG_LOAD_FAILED")
        config = json.loads(result.stdout)
    except LoaderError:
        raise
    except Exception as error:
        raise LoaderError("TARGET_CONFIG_LOAD_FAILED") from error
    expected_keys = {"driver", "host", "port", "dbname", "user", "password", "schema", "timezone"}
    if not isinstance(config, dict) or set(config) != expected_keys:
        raise LoaderError("TARGET_CONFIG_SHAPE_INVALID")
    if str(config["driver"]).lower() != "pgsql" or config["dbname"] != TARGET_DATABASE or config["schema"] != TARGET_SCHEMA:
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
    if not isinstance(config["user"], str) or not config["user"] or not isinstance(config["password"], str) or not config["password"]:
        raise LoaderError("TARGET_CREDENTIAL_MISSING")
    if not isinstance(config["timezone"], str) or not config["timezone"]:
        raise LoaderError("TARGET_TIMEZONE_MISSING")
    return config


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
            application_name="erp-p2-development-rebuild",
            autocommit=True,
        )
    except Exception as error:
        raise LoaderError("POSTGRES_TARGET_CONNECTION_FAILED", _sqlstate(error)) from error


def _target_preflight(connection: Any) -> dict[str, Any]:
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                """SELECT current_database(), current_user,
                          COALESCE((SELECT rolsuper FROM pg_roles WHERE rolname=current_user), FALSE),
                          has_database_privilege(current_user, current_database(), 'CREATE'),
                          (SELECT nspowner = (SELECT oid FROM pg_roles WHERE rolname=current_user)
                             FROM pg_namespace WHERE nspname = %s)""",
                (TARGET_SCHEMA,),
            )
            row = cursor.fetchone()
    except Exception as error:
        raise LoaderError("POSTGRES_TARGET_PREFLIGHT_FAILED", _sqlstate(error)) from error
    if not row or row[0] != TARGET_DATABASE:
        raise LoaderError("POSTGRES_TARGET_DATABASE_MISMATCH")
    is_superuser = bool(row[2])
    can_create_schema = bool(row[3])
    owns_schema = bool(row[4]) if row[4] is not None else True
    if not can_create_schema:
        raise LoaderError("POSTGRES_TARGET_CREATE_SCHEMA_PRIVILEGE_REQUIRED")
    if not is_superuser and not owns_schema:
        raise LoaderError("POSTGRES_TARGET_SCHEMA_OWNER_REQUIRED")
    return {"database_matches": True, "local_endpoint": True, "schema_create_privilege": can_create_schema, "existing_schema_owned_or_absent": owns_schema}


def _row_count(connection: Any, table: str) -> int:
    from psycopg import sql
    try:
        with connection.cursor() as cursor:
            cursor.execute(sql.SQL("SELECT count(*) FROM {}.{}").format(sql.Identifier(TARGET_SCHEMA), sql.Identifier(table)))
            result = cursor.fetchone()
    except Exception as error:
        raise LoaderError("POSTGRES_TARGET_COUNT_FAILED", _sqlstate(error)) from error
    if not result or isinstance(result[0], bool) or not isinstance(result[0], int):
        raise LoaderError("POSTGRES_TARGET_COUNT_INVALID")
    return result[0]


def _mysql_ident(identifier: str) -> str:
    if not isinstance(identifier, str) or not identifier or "\x00" in identifier:
        raise LoaderError("MYSQL_IDENTIFIER_INVALID")
    return "`" + identifier.replace("`", "``") + "`"


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


def normalize_mysql_value(value: Any, column: dict[str, Any], *, no_backslash_escapes: bool = False) -> Any:
    if value is None:
        return None
    data_type = str(column.get("data_type", "")).lower()
    column_type = str(column.get("column_type", ""))
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
    if data_type == "enum":
        if not isinstance(value, str):
            raise LoaderError("ENUM_VALUE_TYPE_INVALID")
        try:
            labels = parse_mysql_enum(column_type, no_backslash_escapes=no_backslash_escapes)
        except TypeMappingError as error:
            raise LoaderError("ENUM_METADATA_INVALID") from error
        if value not in labels:
            raise LoaderError("ENUM_VALUE_OUTSIDE_DECLARED_LABELS")
        return value
    if data_type in {"date", "datetime", "timestamp", "time"}:
        if isinstance(value, (bytes, bytearray, memoryview)):
            try:
                value = bytes(value).decode("ascii", errors="strict")
            except UnicodeDecodeError as error:
                raise LoaderError("SOURCE_TEMPORAL_VALUE_NOT_ASCII") from error
        try:
            return normalize_temporal_value(
                value,
                data_type,
                zero_date_to_null=False,
                source_timezone="UTC",
                target_timezone="America/Sao_Paulo",
            )
        except TemporalValueError as error:
            raise LoaderError(error.code) from error
    if isinstance(value, (bytes, bytearray, memoryview)):
        charset = column.get("character_set")
        if charset not in {"utf8mb3", "utf8mb4"}:
            raise LoaderError("NON_TEXT_BINARY_VALUE_REQUIRES_REVIEW")
        try:
            value = bytes(value).decode("utf-8", errors="strict")
        except UnicodeDecodeError as error:
            raise LoaderError("SOURCE_TEXT_INVALID_UTF8") from error
    if isinstance(value, str):
        if "\x00" in value:
            raise LoaderError("SOURCE_TEXT_NUL_POLICY_REQUIRED")
        if data_type == "json":
            try:
                decoded_json = json.loads(value)
            except (TypeError, ValueError) as error:
                raise LoaderError("SOURCE_JSON_INVALID") from error
            if _json_contains_nul(decoded_json):
                raise LoaderError("SOURCE_JSON_NUL_POLICY_REQUIRED")
    return value


def _validate_column_mappings(snapshot: dict[str, Any], report: dict[str, Any], table_names: list[str]) -> dict[str, dict[str, Any]]:
    overrides = report.get("collation_overrides")
    if not isinstance(overrides, dict):
        raise LoaderError("BUNDLE_COLLATION_OVERRIDES_INVALID")
    no_backslash_escapes = bool(snapshot.get("source_no_backslash_escapes"))
    table_map = {item["name"]: item for item in snapshot["tables"] if item.get("type") == "BASE TABLE"}
    if set(table_names) - set(table_map):
        raise LoaderError("BUNDLE_TABLE_METADATA_MISSING")
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
        for column in ordered:
            name = column.get("name")
            if not isinstance(name, str) or not name:
                raise LoaderError("BUNDLE_COLUMN_METADATA_INVALID")
            target_collation = overrides.get(f"{table_name}.{name}")
            try:
                mapped[name] = map_mysql_type(
                    str(column.get("column_type", "")),
                    target_collation=target_collation,
                    column_name=name,
                    no_backslash_escapes=no_backslash_escapes,
                )
            except TypeMappingError as error:
                raise LoaderError(error.code) from error
        table["columns"] = ordered
        mappings[table_name] = {"columns": ordered, "types": mapped}
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
) -> int:
    import pymysql
    from psycopg import sql

    table_name = str(table["name"])
    columns = mapped["columns"]
    column_names = [str(column["name"]) for column in columns]
    mysql_columns = ", ".join(_mysql_ident(name) for name in column_names)
    source_query = f"SELECT {mysql_columns} FROM {_mysql_ident(SOURCE_SCHEMA)}.{_mysql_ident(table_name)}"
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
        copy_statement = sql.SQL("COPY {}.{} ({}) FROM STDIN").format(
            sql.Identifier(TARGET_SCHEMA),
            sql.Identifier(table_name),
            sql.SQL(", ").join(sql.Identifier(name) for name in column_names),
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
                            normalized = [
                                normalize_mysql_value(value, column, no_backslash_escapes=no_backslash_escapes)
                                for value, column in zip(row, columns, strict=True)
                            ]
                            copy.write_row(normalized)
                            copied += 1
                            memory_guard.sample_if_due()
        if copied != expected_count:
            raise LoaderError("SOURCE_ROW_COUNT_CHANGED_DURING_COPY")
        return copied
    except LoaderError:
        raise
    except Exception as error:
        raise LoaderError("TABLE_COPY_FAILED", _sqlstate(error)) from error


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


def _validate_source_snapshot(current: dict[str, Any], expected: dict[str, Any]) -> None:
    if current.get("structural_fingerprint_sha256") != expected.get("structural_fingerprint_sha256"):
        raise LoaderError("SOURCE_CATALOG_DRIFT_FROM_BUNDLE")
    if current.get("source_schema") != SOURCE_SCHEMA or current.get("source_grants_read_only") is not True:
        raise LoaderError("SOURCE_CATALOG_PROOF_INVALID")


def run_preflight(bundle_name: str, defaults_file: str | None = None) -> dict[str, Any]:
    bundle, report, snapshot = validate_bundle(bundle_name, require_executable=False)
    source_defaults = _source_defaults_path(defaults_file)
    source, current_snapshot = _connect_source(source_defaults)
    try:
        _validate_source_snapshot(current_snapshot, snapshot)
        source_report: list[dict[str, Any]] = []
        try:
            for table in snapshot["tables"]:
                if table.get("type") != "BASE TABLE":
                    continue
                name = str(table["name"])
                with source.cursor() as cursor:
                    cursor.execute(f"SELECT COUNT(*) FROM {_mysql_ident(SOURCE_SCHEMA)}.{_mysql_ident(name)}")
                    row = cursor.fetchone()
                if not row or isinstance(row[0], bool) or int(row[0]) < 0:
                    raise LoaderError("SOURCE_ROW_COUNT_INVALID")
                source_report.append({"table": name, "rows": int(row[0])})
        finally:
            source.rollback()
    finally:
        source.close()

    target_config = _load_target_config()
    if report.get("application_role_sha256") != hashlib.sha256(target_config["user"].encode("utf-8")).hexdigest():
        raise LoaderError("BUNDLE_APPLICATION_ROLE_MISMATCH")
    target = _connect_target(target_config)
    try:
        target_summary = _target_preflight(target)
        with target.cursor() as cursor:
            cursor.execute("SELECT count(*) FROM pg_namespace WHERE nspname = %s", (TARGET_SCHEMA,))
            target_schema_present = bool(cursor.fetchone()[0])
        target_summary["target_schema_present"] = target_schema_present
    finally:
        target.close()

    return {
        "status": "PREFLIGHT_READ_ONLY",
        "source_schema": SOURCE_SCHEMA,
        "target_database": TARGET_DATABASE,
        "bundle_executable_complete": report.get("executable_complete") is True,
        "bundle_review_items": len(report.get("review_items", [])),
        "bundle_blocked_tables": len(report.get("blocked_tables", [])),
        "source_catalog_matches_bundle": True,
        "target_scope": target_summary,
        "source_table_counts": source_report,
    }


def run_load(bundle_name: str, defaults_file: str | None = None, report_path: Path | None = None) -> dict[str, Any]:
    if report_path is None:
        raise LoaderError("LOAD_REPORT_FILE_REQUIRED")
    _require_load_runtime()
    bundle, report, snapshot = validate_bundle(bundle_name, require_executable=True)
    source_defaults = _source_defaults_path(defaults_file)
    target_config = _load_target_config()
    if report.get("application_role_sha256") != hashlib.sha256(target_config["user"].encode("utf-8")).hexdigest():
        raise LoaderError("BUNDLE_APPLICATION_ROLE_MISMATCH")
    table_names = list(report["generated_tables"])
    mappings = _validate_column_mappings(snapshot, report, table_names)
    table_map = {item["name"]: item for item in snapshot["tables"] if item.get("type") == "BASE TABLE"}
    started_at = time.time()
    memory_guard = _MemoryGuard()
    initial_report = {
        "status": "STARTED",
        "source_schema": SOURCE_SCHEMA,
        "target_database": TARGET_DATABASE,
        "target_schema": TARGET_SCHEMA,
        "bundle_catalog_fingerprint": report.get("catalog_fingerprint_sha256"),
        "started_at_epoch": int(started_at),
        "available_memory_mib_start": memory_guard.sample(),
    }
    _write_report_exclusive(initial_report, report_path)
    memory_guard.start_monitor(None)
    source = None
    target = None
    copied_rows: list[dict[str, Any]] = []
    committed = False
    rollback_confirmed = True
    target_cleanup_confirmed: bool | None = None
    try:
        source, current_snapshot = _connect_source(source_defaults)
        _validate_source_snapshot(current_snapshot, snapshot)
        target = _connect_target(target_config)
        memory_guard.set_connection(target)
        target_preflight = _target_preflight(target)
        target.autocommit = False
        import psycopg
        from psycopg import sql
        try:
            rollback_confirmed = False
            with target.transaction():
                with target.cursor() as cursor:
                    cursor.execute("SELECT pg_advisory_xact_lock(%s, %s)", (20261117, 2026))
                    cursor.execute("SELECT current_database()")
                    if cursor.fetchone()[0] != TARGET_DATABASE:
                        raise LoaderError("POSTGRES_TARGET_DATABASE_MISMATCH")
                    cursor.execute(sql.SQL("DROP SCHEMA IF EXISTS {} CASCADE").format(sql.Identifier(TARGET_SCHEMA)))
                memory_guard.sample()
                _execute_bundle_script(
                    target,
                    bundle / "00_tables.sql",
                    report["generated_scripts_sha256"]["00_tables.sql"],
                )
                memory_guard.sample()
                no_backslash_escapes = bool(snapshot.get("source_no_backslash_escapes"))
                for table_name in table_names:
                    memory_guard.sample()
                    copied = _copy_table(
                        source,
                        target,
                        table_map[table_name],
                        mappings[table_name],
                        no_backslash_escapes,
                        memory_guard,
                    )
                    memory_guard.sample()
                    target_count = _row_count(target, table_name)
                    if target_count != copied:
                        raise LoaderError("TARGET_ROW_COUNT_MISMATCH")
                    copied_rows.append({"table": table_name, "source_rows": copied, "target_rows": target_count})
                for script_name in SCRIPT_NAMES[1:]:
                    memory_guard.sample()
                    _execute_bundle_script(
                        target,
                        bundle / script_name,
                        report["generated_scripts_sha256"][script_name],
                    )
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
            source = None

        if target is not None:
            try:
                target.close()
                target_cleanup_confirmed = True
            except Exception:
                target_cleanup_confirmed = False
            target = None

        report_result = {
            "status": "LOAD_COMMITTED_PENDING_RECONCILIATION",
            "source_schema": SOURCE_SCHEMA,
            "target_database": TARGET_DATABASE,
            "target_schema": TARGET_SCHEMA,
            "bundle_catalog_fingerprint": report.get("catalog_fingerprint_sha256"),
            "generator_version": report.get("generator_version"),
            "tables_loaded": len(copied_rows),
            "rows_loaded": sum(item["source_rows"] for item in copied_rows),
            "elapsed_seconds": round(time.time() - started_at, 3),
            "available_memory_mib_end": _available_memory_mib_or_none(),
            "source_cleanup_confirmed": source_cleanup_confirmed,
            "target_cleanup_confirmed": target_cleanup_confirmed,
            "table_counts": copied_rows,
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
            target = None
        failed = {
            **initial_report,
            "status": "LOAD_COMMITTED_REPORT_UPDATE_FAILED" if committed else (
                "FAILED_ROLLED_BACK" if rollback_confirmed else "FAILED_ROLLBACK_UNCONFIRMED"
            ),
            "failure_code": reported_error.code,
            "sqlstate": reported_error.sqlstate,
            "elapsed_seconds": round(time.time() - started_at, 3),
            "available_memory_mib_end": _available_memory_mib_or_none(),
            "target_cleanup_confirmed": target_cleanup_confirmed,
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


def _parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Loader transacional do perfil P2 de desenvolvimento")
    parser.add_argument("command", choices=("preflight", "load"))
    parser.add_argument("--bundle-name", required=True)
    parser.add_argument("--source-defaults-file")
    parser.add_argument("--report-file")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = _parse_args(argv)
    try:
        if args.command == "preflight":
            result = run_preflight(args.bundle_name, args.source_defaults_file)
        else:
            if not args.report_file:
                raise LoaderError("LOAD_REPORT_FILE_REQUIRED")
            result = run_load(args.bundle_name, args.source_defaults_file, Path(args.report_file))
        print(json.dumps(result, ensure_ascii=False, sort_keys=True))
        return 0
    except LoaderError as error:
        print(f"LOADER_ERROR={error.code}", file=sys.stderr)
        return 2
    except Exception:
        print("LOADER_ERROR=UNEXPECTED_FAILURE", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
