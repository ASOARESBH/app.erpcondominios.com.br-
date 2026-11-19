"""Reconciliação local e read-only da migração P2-C.

O módulo não abre conexões, importa o loader, escreve no banco ou publica hashes
por linha. O chamador injeta conexões DB-API, snapshot, relatório de carga e
mapeamentos. Somente erp_pg_source como origem e erp_pg_dev/{erp,legado} como
alvo são aceitos. Evidência ausente deixa o resultado BLOCKED.
"""
from __future__ import annotations

from contextlib import contextmanager
from datetime import date, datetime, time, timedelta
from decimal import Decimal, InvalidOperation, localcontext
import hashlib
import hmac
import json
import math
import os
import re
import secrets
import sqlite3
import shutil
import stat
import struct
import tempfile
import time as _time
import base64
from pathlib import Path
from typing import Any, Callable, Iterator

from .catalog import CATALOG_VERSION, TIME_RANGE_POLICY_ID, _collector_fingerprint, _structural_fingerprint
from .ddl import POSTGRES_SEQUENCE_MAX, UNSIGNED_BIGINT_OFFSET, MYSQL_UNSIGNED_BIGINT_MAX, canonical_fingerprint
from .generated_columns import declares_generated_column, generated_dependencies_valid
from .type_mapping import BINARY_STORAGE_POLICY_ID, TemporalValueError, TypeMappingError, expected_binary_storage_encoding, expected_binary_storage_manifest, normalize_temporal_value, parse_mysql_enum

SOURCE_SCHEMA = "erp_pg_source"
TARGET_DATABASE = "erp_pg_dev"
TARGET_SCHEMAS = frozenset({"erp", "legado"})
LOAD_PENDING_STATUS = "LOAD_COMMITTED_PENDING_RECONCILIATION"
LOAD_PARTIAL_STATUS = "LOAD_PARTIAL_PENDING_RECONCILIATION"
TEXT_TYPES = frozenset({"char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set", "json"})
BINARY_TYPES = frozenset({"binary", "varbinary", "tinyblob", "blob", "mediumblob", "longblob"})
INTEGER_TYPES = frozenset({"tinyint", "smallint", "mediumint", "int", "integer", "bigint", "year"})
NUMERIC_TYPES = frozenset({"decimal", "numeric", "dec", "fixed"})
FLOAT_TYPES = frozenset({"float", "double", "real"})
MAX_RECONCILE_TEMP_BYTES = 1024 * 1024 * 1024
MIN_RECONCILE_TEMP_FREE_BYTES = 512 * 1024 * 1024
MAX_RECONCILE_ROW_BYTES = 128 * 1024 * 1024
MAX_RECONCILE_BASE64_ROW_BYTES = ((MAX_RECONCILE_ROW_BYTES + 2) // 3) * 4
RECONCILE_COMMIT_ROWS = 512


class _DiskCounterStore:
    """Spill only HMAC counters to a private, size-limited SQLite file."""

    def __init__(self, temp_root: str | os.PathLike[str] | None = None, *,
                 max_bytes: int = MAX_RECONCILE_TEMP_BYTES,
                 minimum_free_bytes: int = MIN_RECONCILE_TEMP_FREE_BYTES) -> None:
        requested_root = Path(temp_root if temp_root is not None else tempfile.gettempdir())
        if requested_root.is_symlink():
            _fail("RECONCILE_TEMP_ROOT_UNSAFE")
        try:
            root = requested_root.resolve(strict=True)
            root_info = root.stat()
            if not stat.S_ISDIR(root_info.st_mode) or (temp_root is not None and (
                root != requested_root or root_info.st_uid != 0 or stat.S_IMODE(root_info.st_mode) != 0o700
            )):
                _fail("RECONCILE_TEMP_ROOT_UNSAFE")
            if max_bytes < 16 * 1024 or minimum_free_bytes < 0:
                _fail("RECONCILE_TEMP_QUOTA_INVALID")
            if shutil.disk_usage(root).free < max_bytes + minimum_free_bytes:
                _fail("RECONCILE_TEMP_SPACE_PRECHECK_FAILED")
        except ReconciliationError:
            raise
        except Exception as error:
            raise ReconciliationError("RECONCILE_TEMP_ROOT_UNSAFE") from error

        self._directory = tempfile.TemporaryDirectory(prefix="erp-p2-reconcile-", dir=str(root))
        self.connection: sqlite3.Connection | None = None
        self._key = secrets.token_bytes(32)
        try:
            os.chmod(self._directory.name, 0o700)
            path = os.path.join(self._directory.name, "counters.sqlite3")
            self.connection = sqlite3.connect(path)
            os.chmod(path, 0o600)
            self.connection.execute("PRAGMA page_size = 4096")
            max_pages = max_bytes // 4096
            actual_pages = int(self.connection.execute(f"PRAGMA max_page_count = {max_pages}").fetchone()[0])
            if actual_pages > max_pages:
                _fail("RECONCILE_TEMP_QUOTA_NOT_ENFORCED")
            self.connection.execute("PRAGMA cache_size = -4096")
            self.connection.execute("PRAGMA temp_store = MEMORY")
            self.connection.execute(
                "CREATE TABLE p2_counter (bucket TEXT NOT NULL, key BLOB NOT NULL, n INTEGER NOT NULL, "
                "PRIMARY KEY (bucket, key)) WITHOUT ROWID"
            )
        except ReconciliationError:
            self.close()
            raise
        except sqlite3.Error as error:
            self.close()
            raise ReconciliationError("RECONCILE_TEMP_STORAGE_LIMIT") from error
        except Exception as error:
            self.close()
            raise ReconciliationError("RECONCILE_TEMP_STORAGE_UNAVAILABLE") from error

    def protect(self, value: bytes) -> bytes:
        return hmac.new(self._key, value, hashlib.sha256).digest()

    def counter(self, bucket: str) -> "_DiskCounter":
        return _DiskCounter(self, bucket)

    def execute(self, statement: str, parameters: tuple[Any, ...] = ()):
        if self.connection is None:
            _fail("RECONCILE_TEMP_STORAGE_UNAVAILABLE")
        try:
            return self.connection.execute(statement, parameters)
        except sqlite3.Error as error:
            message = str(error).lower()
            code = "RECONCILE_TEMP_STORAGE_LIMIT" if "full" in message or "disk" in message else "RECONCILE_TEMP_STORAGE_UNAVAILABLE"
            raise ReconciliationError(code) from error

    def commit(self) -> None:
        if self.connection is None:
            _fail("RECONCILE_TEMP_STORAGE_UNAVAILABLE")
        try:
            self.connection.commit()
        except sqlite3.Error as error:
            message = str(error).lower()
            code = "RECONCILE_TEMP_STORAGE_LIMIT" if "full" in message or "disk" in message else "RECONCILE_TEMP_STORAGE_UNAVAILABLE"
            raise ReconciliationError(code) from error

    def check_budget(self) -> None:
        try:
            if self._directory is None:
                _fail("RECONCILE_TEMP_STORAGE_UNAVAILABLE")
            directory = Path(self._directory.name)
            allocated = 0
            for child in directory.iterdir():
                info = child.lstat()
                if not stat.S_ISREG(info.st_mode):
                    _fail("RECONCILE_TEMP_STORAGE_UNSAFE")
                allocated += info.st_size
            if allocated > MAX_RECONCILE_TEMP_BYTES:
                _fail("RECONCILE_TEMP_STORAGE_LIMIT")
            if shutil.disk_usage(directory).free < MIN_RECONCILE_TEMP_FREE_BYTES:
                _fail("RECONCILE_TEMP_SPACE_BUDGET_EXCEEDED")
        except ReconciliationError:
            raise
        except Exception as error:
            raise ReconciliationError("RECONCILE_TEMP_STORAGE_UNAVAILABLE") from error

    def close(self) -> None:
        close_error = False
        try:
            if self.connection is not None:
                self.connection.close()
        except Exception:
            close_error = True
        finally:
            self._key = b"\x00" * 32
        try:
            directory = Path(self._directory.name)
            self._directory.cleanup()
            removed = not directory.exists()
        except Exception:
            removed = False
        if close_error or not removed:
            _fail("RECONCILE_TEMP_CLEANUP_FAILED")


class _DiskCounter:
    def __init__(self, store: _DiskCounterStore, bucket: str) -> None:
        self.store = store
        self.bucket = bucket

    def add(self, key: bytes, amount: int = 1) -> None:
        protected_key = self.store.protect(key)
        self.store.execute(
            "INSERT INTO p2_counter(bucket,key,n) VALUES(?,?,?) "
            "ON CONFLICT(bucket,key) DO UPDATE SET n=p2_counter.n+excluded.n",
            (self.bucket, sqlite3.Binary(protected_key), amount),
        )

    def get(self, key: bytes, default: int = 0) -> int:
        row = self.store.execute(
            "SELECT n FROM p2_counter WHERE bucket=? AND key=?",
            (self.bucket, sqlite3.Binary(self.store.protect(key))),
        ).fetchone()
        return default if row is None else int(row[0])

    def items(self) -> Iterator[tuple[bytes, int]]:
        cursor = self.store.execute(
            "SELECT key,n FROM p2_counter WHERE bucket=? ORDER BY key", (self.bucket,)
        )
        while True:
            rows = cursor.fetchmany(1024)
            if not rows:
                break
            for key, count in rows:
                yield bytes(key), int(count)

    def values(self) -> Iterator[int]:
        cursor = self.store.execute(
            "SELECT n FROM p2_counter WHERE bucket=? ORDER BY key", (self.bucket,)
        )
        while True:
            rows = cursor.fetchmany(1024)
            if not rows:
                break
            for (count,) in rows:
                yield int(count)

    def total(self) -> int:
        row = self.store.execute(
            "SELECT COALESCE(SUM(n),0) FROM p2_counter WHERE bucket=?", (self.bucket,)
        ).fetchone()
        return int(row[0])

    def unique_count(self) -> int:
        row = self.store.execute(
            "SELECT COUNT(*) FROM p2_counter WHERE bucket=?", (self.bucket,)
        ).fetchone()
        return int(row[0])

    def equals(self, other: "_DiskCounter") -> bool:
        if self.store is not other.store:
            _fail("COUNTER_STORE_MISMATCH")
        return self.difference_count(other) == 0

    def difference_count(self, other: "_DiskCounter") -> int:
        if self.store is not other.store:
            _fail("COUNTER_STORE_MISMATCH")
        left = iter(self.items())
        right = iter(other.items())
        left_item = next(left, None)
        right_item = next(right, None)
        difference = 0
        while left_item is not None or right_item is not None:
            if right_item is None or (left_item is not None and left_item[0] < right_item[0]):
                difference += left_item[1]
                left_item = next(left, None)
            elif left_item is None or right_item[0] < left_item[0]:
                difference += right_item[1]
                right_item = next(right, None)
            else:
                difference += abs(left_item[1] - right_item[1])
                left_item = next(left, None)
                right_item = next(right, None)
        return difference


class ReconciliationError(RuntimeError):
    """Erro sanitizado: a mensagem contém apenas um código estável."""

    def __init__(self, code: str) -> None:
        self.code = code
        super().__init__(code)


def _fail(code: str) -> None:
    raise ReconciliationError(code)


def _ident_mysql(value: str) -> str:
    if not isinstance(value, str) or not value or "\x00" in value:
        _fail("IDENTIFIER_INVALID")
    return "`" + value.replace("`", "``") + "`"


def _ident_pg(value: str) -> str:
    if not isinstance(value, str) or not value or "\x00" in value:
        _fail("IDENTIFIER_INVALID")
    return '"' + value.replace('"', '""') + '"'


def _qualified(schema: str, table: str, *, mysql: bool) -> str:
    if mysql:
        if schema != SOURCE_SCHEMA:
            _fail("SOURCE_SCHEMA_NOT_ALLOWLISTED")
        quote = _ident_mysql
    else:
        if schema not in TARGET_SCHEMAS:
            _fail("TARGET_SCHEMA_NOT_ALLOWLISTED")
        quote = _ident_pg
    return f"{quote(schema)}.{quote(table)}"


@contextmanager
def _cursor(connection: Any) -> Iterator[Any]:
    try:
        cursor = connection.cursor()
    except Exception:
        _fail("DATABASE_CURSOR_UNAVAILABLE")
    try:
        enter = getattr(cursor, "__enter__", None)
        if callable(enter):
            with cursor as managed:
                yield managed
        else:
            try:
                yield cursor
            finally:
                close = getattr(cursor, "close", None)
                if callable(close):
                    close()
    except ReconciliationError:
        raise
    except Exception:
        _fail("DATABASE_QUERY_FAILED")


def _execute(cursor: Any, sql: str, params: tuple[Any, ...] = ()) -> None:
    try:
        cursor.execute(sql, params)
    except TypeError:
        # Alguns adaptadores/fakes DB-API não aceitam params vazio.
        if params:
            _fail("DATABASE_QUERY_FAILED")
        try:
            cursor.execute(sql)
        except Exception:
            _fail("DATABASE_QUERY_FAILED")
    except Exception:
        _fail("DATABASE_QUERY_FAILED")


@contextmanager
def _stream_cursor(connection: Any, *, mysql: bool, cursor_name: str) -> Iterator[Any]:
    try:
        if mysql:
            try:
                import pymysql
                cursor = connection.cursor(pymysql.cursors.SSCursor)
            except (ImportError, TypeError):
                cursor = connection.cursor()
        else:
            try:
                cursor = connection.cursor(name=cursor_name)
            except TypeError:
                cursor = connection.cursor()
    except Exception:
        _fail("DATABASE_CURSOR_UNAVAILABLE")
    try:
        enter = getattr(cursor, "__enter__", None)
        if callable(enter):
            with cursor as managed:
                yield managed
        else:
            try:
                yield cursor
            finally:
                close = getattr(cursor, "close", None)
                if callable(close):
                    close()
    except ReconciliationError:
        raise
    except Exception:
        _fail("DATABASE_QUERY_FAILED")


def _row_value(row: Any, key: str, index: int = 0) -> Any:
    if isinstance(row, dict):
        lowered = {str(k).lower(): v for k, v in row.items()}
        return lowered.get(key.lower())
    try:
        return row[index]
    except Exception:
        _fail("DATABASE_RESULT_INVALID")


def _identity_value(connection: Any, *, source: bool) -> tuple[str, str | None]:
    statement = (
        "SELECT DATABASE() AS database_name, CURRENT_USER() AS current_user"
        if source
        else "SELECT current_database() AS database_name, current_user AS current_user"
    )
    with _cursor(connection) as cursor:
        _execute(cursor, statement)
        try:
            row = cursor.fetchone()
        except Exception:
            _fail("DATABASE_IDENTITY_UNAVAILABLE")
    if row is None:
        _fail("DATABASE_IDENTITY_UNAVAILABLE")
    database = _row_value(row, "database_name", 0)
    account = _row_value(row, "current_user", 1)
    if not isinstance(database, str):
        _fail("DATABASE_IDENTITY_UNAVAILABLE")
    return database, account if isinstance(account, str) else None


def _validate_snapshot_provenance(snapshot: dict[str, Any], live_source_snapshot: dict[str, Any] | None) -> None:
    if not isinstance(live_source_snapshot, dict):
        _fail("LIVE_SOURCE_CATALOG_REQUIRED")
    try:
        expected_manifest = expected_binary_storage_manifest(snapshot)
        live_manifest = expected_binary_storage_manifest(live_source_snapshot)
    except TypeMappingError as error:
        _fail(error.code)
    if not expected_manifest or expected_manifest != live_manifest:
        _fail("BINARY_STORAGE_MANIFEST_MISMATCH")

    for candidate in (snapshot, live_source_snapshot):
        if candidate.get("source_schema") != SOURCE_SCHEMA \
            or candidate.get("catalog_version") != CATALOG_VERSION \
            or candidate.get("time_range_policy_id") != TIME_RANGE_POLICY_ID \
            or candidate.get("binary_storage_policy_id") != BINARY_STORAGE_POLICY_ID \
            or candidate.get("binary_storage_required") is not True:
            _fail("CATALOG_PROVENANCE_POLICY_INVALID")
        if candidate.get("collector_code_sha256") != _collector_fingerprint():
            _fail("CATALOG_COLLECTOR_PROVENANCE_INVALID")
        structural = candidate.get("structural_fingerprint_sha256")
        fingerprint = candidate.get("fingerprint_sha256")
        try:
            structural_valid = isinstance(structural, str) and hmac.compare_digest(
                structural, _structural_fingerprint(candidate)
            )
            fingerprint_valid = isinstance(fingerprint, str) and hmac.compare_digest(
                fingerprint, canonical_fingerprint(candidate)
            )
        except Exception:
            _fail("CATALOG_FINGERPRINT_INVALID")
        if not structural_valid or not fingerprint_valid:
            _fail("CATALOG_FINGERPRINT_INVALID")

    expected_structural = snapshot.get("structural_fingerprint_sha256")
    live_structural = live_source_snapshot.get("structural_fingerprint_sha256")
    if not isinstance(expected_structural, str) or not isinstance(live_structural, str) \
        or not hmac.compare_digest(expected_structural, live_structural):
        _fail("SOURCE_CATALOG_DRIFT_FROM_BUNDLE")


def _validate_contract(
    source: Any,
    target: Any,
    snapshot: dict[str, Any],
    report: dict[str, Any],
    mappings: dict[str, Any],
    expected_target_database: str = TARGET_DATABASE,
) -> tuple[dict[str, dict[str, Any]], dict[str, dict[str, Any]]]:
    if not all(isinstance(x, dict) for x in (snapshot, report, mappings)):
        _fail("RECONCILIATION_INPUT_INVALID")
    source_db, source_user = _identity_value(source, source=True)
    if source_db != SOURCE_SCHEMA:
        _fail("SOURCE_DATABASE_NOT_ALLOWLISTED")
    if source_user is not None and source_user.split("@", 1)[0] != "pg_reader":
        _fail("SOURCE_ACCOUNT_NOT_ALLOWLISTED")
    target_db, _target_user = _identity_value(target, source=False)
    if target_db != expected_target_database:
        _fail("TARGET_DATABASE_NOT_ALLOWLISTED")

    if snapshot.get("source_schema") != SOURCE_SCHEMA:
        _fail("SNAPSHOT_SOURCE_SCHEMA_INVALID")
    if snapshot.get("catalog_version") != CATALOG_VERSION \
        or snapshot.get("time_range_policy_id") != TIME_RANGE_POLICY_ID \
        or snapshot.get("binary_storage_policy_id") != BINARY_STORAGE_POLICY_ID:
        _fail("CATALOG_POLICY_EVIDENCE_MISSING")
    if snapshot.get("source_grant_policy_id") != "erp_pg_source_select_show_view_trigger_v2" \
        or snapshot.get("source_grants_policy_accepted") is not True \
        or snapshot.get("source_routines_events_zero_verified") is not True \
        or snapshot.get("source_schema_wide_select") is not True:
        _fail("SOURCE_GRANT_POLICY_EVIDENCE_MISSING")
    visibility = snapshot.get("metadata_visibility")
    if not isinstance(visibility, dict) \
        or any(visibility.get(k) is not True for k in ("views", "triggers")) \
        or any(visibility.get(k) is not False for k in ("routines", "events")) \
        or snapshot.get("routines") != [] or snapshot.get("events") != []:
        _fail("SNAPSHOT_METADATA_VISIBILITY_UNPROVEN")
    if snapshot.get("catalog_consistency_check") != "PRE_POST_SIGNATURE_MATCH":
        _fail("SNAPSHOT_CONSISTENCY_EVIDENCE_MISSING")
    transfer = snapshot.get("timestamp_transfer_contract")
    if not isinstance(transfer, dict) or transfer.get("source_session") != "UTC" or transfer.get("target_wall_time") != "America/Sao_Paulo":
        _fail("TEMPORAL_CONTRACT_UNPROVEN")

    if report.get("status") not in {LOAD_PENDING_STATUS, LOAD_PARTIAL_STATUS}:
        _fail("LOAD_COMMIT_EVIDENCE_INVALID")
    if report.get("source_schema") != SOURCE_SCHEMA or report.get("target_database") != expected_target_database:
        _fail("LOAD_SCOPE_MISMATCH")
    try:
        expected_binary_storage = {
            "policy_id": BINARY_STORAGE_POLICY_ID,
            "columns": expected_binary_storage_manifest(snapshot),
        }
    except TypeMappingError as error:
        _fail(error.code)
    if report.get("binary_storage") != expected_binary_storage:
        _fail("BINARY_STORAGE_MANIFEST_MISMATCH")
    if report.get("target_schema") != "erp" or report.get("bundle_catalog_fingerprint") != snapshot.get("fingerprint_sha256"):
        _fail("LOAD_SNAPSHOT_MISMATCH")

    tables_value = snapshot.get("tables")
    if not isinstance(tables_value, list):
        _fail("SNAPSHOT_TABLE_MANIFEST_INVALID")
    tables: dict[str, dict[str, Any]] = {}
    for table in tables_value:
        if not isinstance(table, dict) or not isinstance(table.get("name"), str):
            _fail("SNAPSHOT_TABLE_MANIFEST_INVALID")
        name = table["name"]
        if table.get("type") != "BASE TABLE":
            _fail("METRIC_NOT_IMPLEMENTED")
        if not isinstance(table.get("engine"), str) or table["engine"].lower() != "innodb":
            _fail("SOURCE_ENGINE_NOT_SNAPSHOT_CAPABLE")
        if name in tables:
            _fail("SNAPSHOT_TABLE_MANIFEST_DUPLICATE")
        if not isinstance(table.get("columns"), list) or not table["columns"]:
            _fail("SNAPSHOT_COLUMN_MANIFEST_INVALID")
        tables[name] = table
    if not tables or set(mappings) != set(tables):
        _fail("TABLE_MANIFEST_MISMATCH")

    normalized_mappings: dict[str, dict[str, Any]] = {}
    for name, table in tables.items():
        mapping = mappings.get(name)
        if not isinstance(mapping, dict):
            _fail("COLUMN_MAPPING_INVALID")
        schema = mapping.get("target_schema")
        if schema not in TARGET_SCHEMAS:
            _fail("TARGET_SCHEMA_NOT_ALLOWLISTED")
        columns = mapping.get("columns")
        types = mapping.get("types")
        if not isinstance(columns, list) or not isinstance(types, dict):
            _fail("COLUMN_MAPPING_INVALID")
        source_columns = sorted(table["columns"], key=lambda c: int(c.get("ordinal", 0)) if isinstance(c, dict) else -1)
        if any(not isinstance(c, dict) or not isinstance(c.get("name"), str) for c in source_columns):
            _fail("SNAPSHOT_COLUMN_MANIFEST_INVALID")
        expected_names = [c["name"] for c in source_columns]
        mapped_names = [c.get("name") if isinstance(c, dict) else None for c in columns]
        if mapped_names != expected_names or len(set(expected_names)) != len(expected_names):
            _fail("COLUMN_ORDER_OR_MAPPING_MISMATCH")
        for column in source_columns:
            if declares_generated_column(column) or column.get("generated_expression_present") is True:
                if column.get("generated_expression_present") is not True or not generated_dependencies_valid(name, column, source_columns):
                    _fail("GENERATED_COLUMN_MAPPING_UNAPPROVED")
        if set(types) != set(expected_names):
            _fail("COLUMN_TYPE_MAPPING_INCOMPLETE")
        expected_encodings = {
            column["name"]: encoding
            for column in source_columns
            if (encoding := expected_binary_storage_encoding(name, column["name"])) is not None
        }
        if mapping.get("storage_encodings") != expected_encodings:
            _fail("BINARY_STORAGE_COLUMN_MAPPING_MISMATCH")
        if table.get("source_next_auto_increment") is not None and not any("auto_increment" in str(c.get("extra", "")).lower() for c in source_columns):
            _fail("IDENTITY_COLUMN_METADATA_MISSING")
        table["columns"] = source_columns
        normalized_mappings[name] = {**mapping, "columns": source_columns, "target_schema": schema}

    table_counts = report.get("table_counts")
    if not isinstance(table_counts, list):
        _fail("LOAD_TABLE_COUNTS_EVIDENCE_MISSING")
    failure_evidence = _load_table_failures(report)
    failed_tables = {item["table"] for item in failure_evidence}
    load_counts: dict[str, dict[str, int | None]] = {}
    for entry in table_counts:
        if not isinstance(entry, dict) or not isinstance(entry.get("table"), str):
            _fail("LOAD_TABLE_COUNTS_EVIDENCE_INVALID")
        name = entry["table"]
        if name in load_counts or name not in tables:
            _fail("LOAD_TABLE_COUNTS_EVIDENCE_INVALID")
        values: dict[str, int | None] = {}
        for key in ("source_rows", "target_rows"):
            value = entry.get(key)
            if value is None and name in failed_tables:
                values[key] = None
                continue
            if isinstance(value, bool) or not isinstance(value, int) or value < 0:
                _fail("LOAD_TABLE_COUNTS_EVIDENCE_INVALID")
            values[key] = value
        load_counts[name] = values
    if set(load_counts) != set(tables):
        _fail("LOAD_TABLE_COUNTS_EVIDENCE_INCOMPLETE")
    return tables, normalized_mappings


def _frame(tag: bytes, payload: bytes) -> bytes:
    return tag + len(payload).to_bytes(8, "big", signed=False) + payload


def _text_codec(column: dict[str, Any]) -> str:
    name = str(column.get("character_set") or "utf8mb4").lower()
    codecs = {
        "utf8": "utf-8", "utf8mb3": "utf-8", "utf8mb4": "utf-8",
        "latin1": "cp1252", "ascii": "ascii", "binary": "latin-1",
        "ucs2": "utf-16-be", "utf16": "utf-16-be", "utf16le": "utf-16-le",
        "utf32": "utf-32-be", "sjis": "cp932", "cp932": "cp932",
        "ujis": "euc_jp", "eucjpms": "euc_jp", "gbk": "gbk",
        "gb2312": "gb2312", "big5": "big5", "euckr": "euc_kr",
    }
    if name not in codecs:
        _fail("METRIC_NOT_IMPLEMENTED")
    return codecs[name]


def _decode_text(value: Any, column: dict[str, Any]) -> tuple[str, bytes]:
    codec = _text_codec(column)
    try:
        if isinstance(value, str):
            return value, value.encode(codec, errors="strict")
        if isinstance(value, (bytes, bytearray, memoryview)):
            raw = bytes(value)
            return raw.decode(codec, errors="strict"), raw
    except (UnicodeError, LookupError):
        _fail("SOURCE_TEXT_ENCODING_INVALID")
    _fail("TEXT_COLUMN_VALUE_INVALID")


def _json_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            _fail("JSON_DUPLICATE_KEY")
        result[key] = value
    return result


def _reject_json_constant(_value: str) -> Any:
    _fail("JSON_NONFINITE_NUMBER")


def _parse_json(value: Any, *, source_column: dict[str, Any], is_source: bool) -> Any:
    try:
        if isinstance(value, (str, bytes, bytearray, memoryview)):
            text, _raw = _decode_text(value, source_column)
            if is_source and "\x00" in text:
                text = text.replace("\x00", "\\u0000")
            return json.loads(
                text,
                object_pairs_hook=_json_pairs,
                parse_float=Decimal,
                parse_int=int,
                parse_constant=_reject_json_constant,
            )
        if isinstance(value, (dict, list)):
            return value
    except ReconciliationError:
        raise
    except (ValueError, TypeError, UnicodeError):
        _fail("JSON_VALUE_INVALID")
    _fail("JSON_VALUE_INVALID")


def _json_remove_nul(value: Any) -> Any:
    if isinstance(value, str):
        return value.replace("\x00", "")
    if isinstance(value, list):
        return [_json_remove_nul(item) for item in value]
    if isinstance(value, dict):
        result: dict[str, Any] = {}
        for key, item in value.items():
            if not isinstance(key, str):
                _fail("JSON_OBJECT_KEY_INVALID")
            normalized_key = key.replace("\x00", "")
            if normalized_key in result:
                _fail("JSON_NUL_NORMALIZATION_COLLISION")
            result[normalized_key] = _json_remove_nul(item)
        return result
    if isinstance(value, float):
        _fail("JSON_FLOAT_VALUE_UNSAFE")
    return value


def _decimal_json(value: Decimal) -> str:
    if not value.is_finite():
        _fail("JSON_NONFINITE_NUMBER")
    if value == 0:
        return "0"
    text = format(value, "f")
    if "." in text:
        text = text.rstrip("0").rstrip(".")
    return text


def _json_canonical(value: Any) -> str:
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, str):
        return json.dumps(value, ensure_ascii=False, separators=(",", ":"))
    if isinstance(value, int) and not isinstance(value, bool):
        return str(value)
    if isinstance(value, Decimal):
        return _decimal_json(value)
    if isinstance(value, float):
        _fail("JSON_FLOAT_VALUE_UNSAFE")
    if isinstance(value, list):
        return "[" + ",".join(_json_canonical(item) for item in value) + "]"
    if isinstance(value, dict):
        if any(not isinstance(key, str) for key in value):
            _fail("JSON_OBJECT_KEY_INVALID")
        return "{" + ",".join(
            json.dumps(key, ensure_ascii=False) + ":" + _json_canonical(value[key])
            for key in sorted(value)
        ) + "}"
    _fail("JSON_VALUE_INVALID")


def _decimal_value(value: Any) -> Decimal:
    if isinstance(value, bool) or isinstance(value, float):
        _fail("NUMERIC_FLOAT_OR_BOOLEAN_UNSAFE")
    if isinstance(value, Decimal):
        number = value
    elif isinstance(value, int):
        number = Decimal(value)
    elif isinstance(value, str):
        try:
            number = Decimal(value)
        except InvalidOperation:
            _fail("NUMERIC_VALUE_INVALID")
    else:
        _fail("NUMERIC_VALUE_INVALID")
    if not number.is_finite():
        _fail("NUMERIC_VALUE_NONFINITE")
    return number


def _normalized_float(value: Any, column: dict[str, Any]) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        _fail("FLOAT_VALUE_INVALID")
    number = float(value)
    if not math.isfinite(number):
        _fail("FLOAT_VALUE_NONFINITE")
    if str(column.get("data_type", "")).lower() == "float":
        try:
            number = struct.unpack(">f", struct.pack(">f", number))[0]
        except (OverflowError, struct.error):
            _fail("FLOAT_VALUE_OUT_OF_RANGE")
    if not math.isfinite(number):
        _fail("FLOAT_VALUE_NONFINITE")
    return number


def _numeric_scale(column: dict[str, Any]) -> tuple[int, int]:
    match = re.fullmatch(r"\s*(?:decimal|numeric|dec|fixed)\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)(?:\s+unsigned)?\s*", str(column.get("column_type", "")), re.I)
    if not match:
        _fail("DECIMAL_METADATA_INVALID")
    precision, scale = int(match.group(1)), int(match.group(2))
    if precision < 1 or precision > 65 or scale < 0 or scale > 30 or scale > precision:
        _fail("DECIMAL_METADATA_INVALID")
    return precision, scale


def _fixed_decimal(value: Any, column: dict[str, Any]) -> tuple[Decimal, bytes]:
    precision, scale = _numeric_scale(column)
    number = _decimal_value(value)
    try:
        with localcontext() as ctx:
            ctx.prec = max(128, precision + scale + 32)
            quantum = Decimal(1).scaleb(-scale)
            fixed = number.quantize(quantum)
    except InvalidOperation:
        _fail("NUMERIC_SCALE_MISMATCH")
    if fixed != number:
        _fail("NUMERIC_SCALE_MISMATCH")
    integer_digits = max(0, len(fixed.copy_abs().as_tuple().digits) - scale)
    if integer_digits > precision - scale:
        _fail("NUMERIC_PRECISION_OVERFLOW")
    return fixed, format(fixed, f".{scale}f").encode("ascii")


def _integer(value: Any) -> int:
    if isinstance(value, bool):
        _fail("INTEGER_VALUE_INVALID")
    if isinstance(value, int):
        return value
    if isinstance(value, Decimal) and value.is_finite() and value == value.to_integral_value():
        return int(value)
    if isinstance(value, str) and re.fullmatch(r"[+-]?[0-9]+", value):
        return int(value, 10)
    _fail("INTEGER_VALUE_INVALID")


def _temporal_payload(value: Any, kind: str, *, time_as_interval: bool | None = None) -> bytes:
    try:
        if kind == "date" and isinstance(value, date) and not isinstance(value, datetime):
            text = value.isoformat()
        elif kind in {"datetime", "timestamp"} and isinstance(value, datetime):
            if value.tzinfo is not None:
                _fail("TARGET_TEMPORAL_TIMEZONE_INVALID")
            text = value.isoformat(sep="T", timespec="microseconds" if value.microsecond else "seconds")
        elif kind == "time" and isinstance(value, timedelta) and time_as_interval is True:
            micros = (value.days * 86400 + value.seconds) * 1_000_000 + value.microseconds
            text = str(micros)
        elif kind == "time" and isinstance(value, time) and value.tzinfo is None and time_as_interval is False:
            micros = ((value.hour * 60 + value.minute) * 60 + value.second) * 1_000_000 + value.microsecond
            text = str(micros)
        elif isinstance(value, str):
            # Alguns adaptadores entregam temporais ISO; a validação tipada segue abaixo.
            if kind == "date":
                text = date.fromisoformat(value).isoformat()
            elif kind in {"datetime", "timestamp"}:
                parsed = datetime.fromisoformat(value.replace(" ", "T", 1))
                if parsed.tzinfo is not None:
                    _fail("TARGET_TEMPORAL_TIMEZONE_INVALID")
                text = parsed.isoformat(sep="T", timespec="microseconds" if parsed.microsecond else "seconds")
            elif kind == "time":
                normalized = normalize_temporal_value(value, "time", time_as_interval=time_as_interval)
                if not isinstance(normalized, (time, timedelta)):
                    _fail("TEMPORAL_VALUE_INVALID")
                return _temporal_payload(normalized, "time", time_as_interval=time_as_interval)
            else:
                _fail("TEMPORAL_TYPE_UNSUPPORTED")
        else:
            _fail("TEMPORAL_VALUE_INVALID")
    except ReconciliationError:
        raise
    except (TypeError, ValueError, OverflowError, TemporalValueError):
        _fail("TEMPORAL_VALUE_INVALID")
    return text.encode("ascii")


def _binary_payload(value: Any, *, is_source: bool, storage_encoding: str | None) -> bytes:
    if storage_encoding == "base64":
        if is_source:
            if not isinstance(value, (bytes, bytearray, memoryview)):
                _fail("BINARY_VALUE_NOT_BYTES")
            return bytes(value)
        if not isinstance(value, str):
            _fail("BINARY_BASE64_TARGET_VALUE_NOT_TEXT")
        try:
            payload = base64.b64decode(value.encode("ascii", errors="strict"), validate=True)
        except (UnicodeEncodeError, ValueError):
            _fail("BINARY_BASE64_TARGET_VALUE_INVALID")
        if base64.b64encode(payload).decode("ascii") != value:
            _fail("BINARY_BASE64_TARGET_VALUE_NONCANONICAL")
        return payload
    if storage_encoding is not None or not isinstance(value, (bytes, bytearray, memoryview)):
        _fail("BINARY_VALUE_NOT_BYTES")
    return bytes(value)


def _canonical_value(
    value: Any, column: dict[str, Any], *, is_source: bool, storage_encoding: str | None = None
) -> tuple[bytes, dict[str, int]]:
    """Return typed bytes plus private per-cell NUL observations."""
    nul = {"physical": 0, "semantic": 0, "normalized": 0, "escaped": 0, "bytes": 0, "scanned": 0}
    kind = str(column.get("data_type", "")).lower()
    if value is None:
        return b"N", nul
    if kind in INTEGER_TYPES:
        canonical = str(_integer(value)).encode("ascii")
    elif kind in NUMERIC_TYPES:
        _number, canonical = _fixed_decimal(value, column)
    elif kind in {"date", "datetime", "timestamp", "time"}:
        time_as_interval = column.get("time_uses_interval") if kind == "time" else None
        if kind == "time" and not isinstance(time_as_interval, bool):
            _fail("MYSQL_TIME_RANGE_SCAN_REQUIRED")
        if is_source:
            raw = value
            if isinstance(raw, (bytes, bytearray, memoryview)):
                try:
                    raw = bytes(raw).decode("ascii", errors="strict")
                except UnicodeDecodeError:
                    _fail("TEMPORAL_VALUE_INVALID")
            try:
                value = normalize_temporal_value(
                    raw,
                    kind,
                    zero_date_to_null=True,
                    source_timezone="UTC",
                    target_timezone="America/Sao_Paulo",
                    time_as_interval=time_as_interval,
                )
            except TemporalValueError:
                _fail("TEMPORAL_VALUE_INVALID")
            if value is None:
                return b"N", nul
        canonical = _temporal_payload(value, kind, time_as_interval=time_as_interval)
    elif kind in BINARY_TYPES:
        canonical = _binary_payload(value, is_source=is_source, storage_encoding=storage_encoding)
        nul = {"physical": int(b"\x00" in canonical), "semantic": 0, "normalized": 0, "escaped": 0, "bytes": canonical.count(b"\x00"), "scanned": 1}
    elif kind == "bit":
        width_match = re.search(r"\((\d+)\)", str(column.get("column_type", "")))
        if not width_match:
            _fail("BIT_METADATA_INVALID")
        width = int(width_match.group(1))
        if isinstance(value, (bytes, bytearray, memoryview)):
            number = int.from_bytes(bytes(value), "big")
        elif isinstance(value, str) and re.fullmatch(r"[01]+", value):
            number = int(value, 2)
        elif isinstance(value, int) and not isinstance(value, bool):
            number = value
        else:
            _fail("BIT_VALUE_INVALID")
        if not 0 <= number < (1 << width):
            _fail("BIT_VALUE_OUT_OF_RANGE")
        canonical = format(number, f"0{width}b").encode("ascii")
    elif kind in TEXT_TYPES:
        text, raw_bytes = _decode_text(value, column)
        nul["scanned"] = 1
        physical_count = text.count("\x00")
        semantic_count = 0
        semantic_bytes = 0
        if kind == "json":
            parsed = _parse_json(value, source_column=column, is_source=is_source)
            semantic_count = _json_nul_count(parsed)
            if is_source:
                removed = _json_remove_nul(parsed)
                escaped_nul_count = max(0, semantic_count - physical_count)
                if escaped_nul_count:
                    semantic_bytes = escaped_nul_count * 6 * len("\\".encode(_text_codec(column)))
                parsed = removed
            canonical = _json_canonical(parsed).encode("utf-8")
        else:
            if is_source:
                canonical = text.replace("\x00", "").encode("utf-8")
            else:
                canonical = text.encode("utf-8")
        if is_source:
            nul["physical"] = int(physical_count > 0)
            nul["semantic"] = semantic_count
            nul["normalized"] = int(physical_count > 0 or (kind == "json" and semantic_count > 0))
            nul["escaped"] = int(kind == "json" and semantic_count > 0 and physical_count == 0)
            codec = _text_codec(column)
            if kind == "json":
                nul["bytes"] = physical_count * len("\x00".encode(codec)) + semantic_bytes
            else:
                nul["bytes"] = physical_count * len("\x00".encode(codec))
    elif kind in {"float", "double", "real"}:
        number = _normalized_float(value, column)
        canonical = number.hex().encode("ascii")
    else:
        _fail("METRIC_NOT_IMPLEMENTED")
    type_tag = kind.encode("ascii", errors="strict")
    return _frame(b"T", type_tag) + _frame(b"D", canonical), nul


def _json_contains_nul(value: Any) -> bool:
    if isinstance(value, str):
        return "\x00" in value
    if isinstance(value, list):
        return any(_json_contains_nul(item) for item in value)
    if isinstance(value, dict):
        return any(_json_contains_nul(k) or _json_contains_nul(v) for k, v in value.items())
    return False


def _json_nul_count(value: Any) -> int:
    if isinstance(value, str):
        return value.count("\x00")
    if isinstance(value, list):
        return sum(_json_nul_count(item) for item in value)
    if isinstance(value, dict):
        return sum(_json_nul_count(k) + _json_nul_count(v) for k, v in value.items())
    return 0


def canonical_row_sha256(row: Any, columns: list[dict[str, Any]], *, is_source: bool = True) -> str:
    """Hash de uma linha sintética/DB-API com framing explícito e ordem de colunas."""
    values = list(row.values()) if isinstance(row, dict) else list(row)
    if len(values) != len(columns):
        _fail("ROW_SHAPE_INVALID")
    digest = hashlib.sha256()
    digest.update(b"P2-ROW\x00\x01")
    digest.update(len(columns).to_bytes(8, "big"))
    for column, value in zip(columns, values, strict=True):
        name = str(column["name"]).encode("utf-8", errors="strict")
        storage_encoding = column.get("binary_storage_encoding") if not is_source else None
        data, _nul = _canonical_value(value, column, is_source=is_source, storage_encoding=storage_encoding)
        digest.update(_frame(b"C", name))
        digest.update(_frame(b"V", data))
    return digest.hexdigest()


def _row_hashes_digest(counts: _DiskCounter, total: int) -> bytes:
    digest = hashlib.sha256()
    digest.update(b"P2-MULTISET\x00\x01")
    digest.update(total.to_bytes(8, "big"))
    for row_hash, multiplicity in counts.items():
        digest.update(_frame(b"H", row_hash))
        digest.update(multiplicity.to_bytes(8, "big"))
    return digest.digest()


def _new_accumulator(table: dict[str, Any], mapping: dict[str, Any], store: _DiskCounterStore,
                     *, side: str, table_name: str) -> dict[str, Any]:
    columns = table["columns"]
    prefix = f"{side}:{table_name}"
    return {
        "count": 0,
        "hashes": store.counter(prefix + ":rows"),
        "maxima": {},
        "sums": {c["name"]: Decimal(0) for c in columns if str(c.get("data_type", "")).lower() in INTEGER_TYPES | NUMERIC_TYPES | FLOAT_TYPES},
        "binary": {c["name"]: {"nonnull": 0, "octets": 0, "nul_bytes": 0,
                                "hashes": store.counter(f"{prefix}:binary:{c['name']}")}
                   for c in columns if str(c.get("data_type", "")).lower() in BINARY_TYPES},
        "tenant": store.counter(prefix + ":tenants"),
        "tenant_hashes": store.counter(prefix + ":tenant_rows"),
        "enum": {c["name"]: store.counter(f"{prefix}:enum:{c['name']}")
                 for c in columns if str(c.get("data_type", "")).lower() == "enum"},
        "generated": {c["name"]: store.counter(f"{prefix}:generated:{c['name']}")
                      for c in columns if c.get("generated_expression_present") is True},
        "nul_audit_values": {
            c["name"]: store.counter(f"{side}:nul_audit") for c in columns
            if str(c.get("data_type", "")).lower() in TEXT_TYPES
        },
        "nul": {c["name"]: {"scanned": 0, "physical": 0, "semantic": 0, "normalized": 0, "escaped": 0, "bytes": 0}
                for c in columns if str(c.get("data_type", "")).lower() in TEXT_TYPES | BINARY_TYPES},
        "counter_store": store,
    }


def _audit_source_value_bytes(value: Any, column: dict[str, Any]) -> bytes:
    if value is None:
        return b"N"
    if str(column.get("data_type", "")).lower() in TEXT_TYPES:
        _text, raw = _decode_text(value, column)
        return b"bytes:" + raw
    if isinstance(value, (bytes, bytearray, memoryview)):
        raw = bytes(value)
    elif isinstance(value, str):
        raw = value.encode(_text_codec(column), errors="strict")
    elif hasattr(value, "isoformat") and callable(value.isoformat):
        raw = value.isoformat().encode("utf-8")
    else:
        raw = str(value).encode("utf-8")
    return type(value).__name__.encode("ascii", errors="replace") + b":" + raw


def _audit_pk_text(table: dict[str, Any], values: list[Any]) -> str:
    columns = sorted(table["columns"], key=lambda item: int(item.get("ordinal", 0)))
    primary_key = table.get("primary_key")
    if not isinstance(primary_key, list):
        _fail("SNAPSHOT_PRIMARY_KEY_INVALID")
    positions = {str(column["name"]): index for index, column in enumerate(columns)}
    if not primary_key:
        digest = hashlib.sha256()
        for value, column in zip(values, columns, strict=True):
            raw = _audit_source_value_bytes(value, column)
            digest.update(len(raw).to_bytes(8, "big"))
            digest.update(raw)
        return json.dumps({"source_row_sha256": digest.hexdigest()}, sort_keys=True, separators=(",", ":"))
    result: dict[str, Any] = {}
    for item in sorted(primary_key, key=lambda entry: int(entry.get("ordinal", 0))):
        name = item.get("column")
        if not isinstance(name, str) or name not in positions:
            _fail("SNAPSHOT_PRIMARY_KEY_INVALID")
        pos = positions[name]
        raw = _audit_source_value_bytes(values[pos], columns[pos])
        result[name] = {"source_value_base64": base64.b64encode(raw).decode("ascii")}
    return json.dumps(result, ensure_ascii=True, sort_keys=True, separators=(",", ":"))


def _nul_audit_counter_key(pk_text: str, original_digest: bytes) -> bytes:
    return _frame(b"P", pk_text.encode("utf-8", errors="strict")) + _frame(b"H", original_digest)


def _cell_canonical_bytes(value: Any, column: dict[str, Any], *, is_source: bool) -> bytes:
    storage_encoding = column.get("binary_storage_encoding") if not is_source else None
    data, _ = _canonical_value(value, column, is_source=is_source, storage_encoding=storage_encoding)
    return data


def _row_values(row: Any, columns: list[dict[str, Any]]) -> list[Any]:
    if isinstance(row, dict):
        lowered = {str(k): v for k, v in row.items()}
        try:
            return [lowered[c["name"]] for c in columns]
        except KeyError:
            _fail("ROW_SHAPE_INVALID")
    try:
        values = list(row)
    except Exception:
        _fail("ROW_SHAPE_INVALID")
    if len(values) != len(columns):
        _fail("ROW_SHAPE_INVALID")
    return values


def _accumulate_row(acc: dict[str, Any], values: list[Any], table: dict[str, Any], mapping: dict[str, Any], *, is_source: bool) -> None:
    columns = table["columns"]
    acc["count"] += 1
    row_digest = hashlib.sha256()
    row_digest.update(b"P2-ROW\x00\x01")
    row_digest.update(len(columns).to_bytes(8, "big"))
    tenant_key: bytes | None = None
    for column, value in zip(columns, values, strict=True):
        name = str(column["name"])
        kind = str(column.get("data_type", "")).lower()
        storage_encoding = mapping.get("storage_encodings", {}).get(name) if not is_source else None
        raw, nul = _canonical_value(value, column, is_source=is_source, storage_encoding=storage_encoding)
        if column.get("generated_expression_present") is True:
            generated_counter = acc["generated"].get(name)
            if generated_counter is None:
                _fail("GENERATED_COLUMN_MAPPING_MISSING")
            generated_counter.add(raw)
        name_bytes = name.encode("utf-8")
        row_digest.update(_frame(b"C", name_bytes))
        row_digest.update(_frame(b"V", raw))
        is_identity = "auto_increment" in str(column.get("extra", "")).lower()
        if value is not None and (is_identity or (name.lower() == "id" and kind in INTEGER_TYPES | NUMERIC_TYPES)):
            if kind not in INTEGER_TYPES | NUMERIC_TYPES:
                _fail("MAX_ID_TYPE_UNSUPPORTED")
            candidate = _decimal_value(value)
            previous = acc["maxima"].get(name)
            if previous is None or candidate > previous:
                acc["maxima"][name] = candidate
        if value is not None and name in acc["sums"]:
            if kind in INTEGER_TYPES:
                number = Decimal(_integer(value))
            elif kind in FLOAT_TYPES:
                number = Decimal.from_float(_normalized_float(value, column))
            else:
                number, _canonical = _fixed_decimal(value, column)
            with localcontext() as ctx:
                ctx.prec = 2048
                acc["sums"][name] = acc["sums"][name] + number
        if kind in BINARY_TYPES:
            entry = acc["binary"][name]
            item = acc["nul"][name]
            if value is not None:
                payload = _binary_payload(value, is_source=is_source, storage_encoding=storage_encoding)
                entry["nonnull"] += 1
                entry["octets"] += len(payload)
                entry["nul_bytes"] += payload.count(b"\x00")
                entry["hashes"].add(hashlib.sha256(payload).digest())
                item["scanned"] += 1
                item["physical"] += int(b"\x00" in payload)
                item["bytes"] += payload.count(b"\x00")
        elif kind in TEXT_TYPES:
            item = acc["nul"][name]
            if is_source and value is not None:
                item["scanned"] += 1
                item["physical"] += nul["physical"]
                item["semantic"] += nul["semantic"]
                item["normalized"] += nul["normalized"]
                item["escaped"] += nul["escaped"]
                item["bytes"] += nul["bytes"]
                if nul["normalized"]:
                    _text, original = _decode_text(value, column)
                    pk_text = _audit_pk_text(table, values)
                    audit_key = _frame(b"T", table["name"].encode("utf-8")) \
                        + _frame(b"C", name.encode("utf-8")) \
                        + _nul_audit_counter_key(pk_text, hashlib.sha256(original).digest())
                    acc["nul_audit_values"][name].add(audit_key)
            if kind == "enum" and value is not None:
                labels = mapping.get("enum_labels", {}).get(name)
                if not isinstance(labels, (tuple, list)) or not labels:
                    try:
                        labels = parse_mysql_enum(str(column.get("column_type", "")))
                    except Exception:
                        _fail("ENUM_METADATA_INVALID")
                text, _raw_bytes = _decode_text(value, column)
                if is_source and "\x00" in text:
                    text = text.replace("\x00", "")
                if text not in labels:
                    acc["enum"][name].add(text.encode("utf-8", errors="strict"))
        if name.lower() == "tenant_id":
            tenant_key = _cell_canonical_bytes(value, column, is_source=is_source)
            acc["tenant"].add(tenant_key)
    row_hash = row_digest.digest()
    acc["hashes"].add(row_hash)
    if tenant_key is not None:
        acc["tenant_hashes"].add(_frame(b"T", tenant_key) + _frame(b"H", row_hash))


def _stream_table(connection: Any, *, schema: str, table_name: str, columns: list[dict[str, Any]], mysql: bool,
                  table: dict[str, Any], mapping: dict[str, Any], is_source: bool,
                  store: _DiskCounterStore, checkpoint: Callable[[Any], None] | None = None) -> dict[str, Any]:
    columns = sorted(columns, key=lambda item: int(item.get("ordinal", 0)))
    table = {**table, "columns": columns}
    if mysql:
        selected = []
        for column in columns:
            identifier = _ident_mysql(column["name"])
            if str(column.get("data_type", "")).lower() in TEXT_TYPES:
                selected.append(f"CAST({identifier} AS BINARY) AS {identifier}")
            else:
                selected.append(identifier)
        select_list = ", ".join(selected)
    else:
        select_list = ", ".join(
            (f"{_ident_pg(c['name'])}::text AS {_ident_pg(c['name'])}"
             if str(c.get("data_type", "")).lower() == "json" else _ident_pg(c["name"]))
            for c in columns
        )
    statement = f"SELECT {select_list} FROM {_qualified(schema, table_name, mysql=mysql)}"
    side = "source" if is_source else "target"
    row_size_limit = _stream_row_size_limit(columns, is_source=is_source)
    accumulator = _new_accumulator(table, mapping, store, side=side, table_name=table_name)
    try:
        with _stream_cursor(
            connection,
            mysql=mysql,
            cursor_name="p2_reconcile_" + hashlib.sha256(table_name.encode()).hexdigest()[:16],
        ) as cursor:
            if checkpoint is not None:
                checkpoint(connection)
            _execute(cursor, statement)
            variable_width = any(str(c.get("data_type", "")).lower() in TEXT_TYPES | BINARY_TYPES for c in columns)
            batch_size = 1 if variable_width else 256
            processed = 0
            while True:
                if checkpoint is not None:
                    checkpoint(connection)
                rows = cursor.fetchmany(batch_size)
                if not rows:
                    break
                for row in rows:
                    values = _row_values(row, columns)
                    row_size = 0
                    for value in values:
                        if value is None:
                            continue
                        if isinstance(value, (bytes, bytearray, memoryview)):
                            row_size += len(value)
                        elif isinstance(value, str):
                            row_size += len(value.encode("utf-8", errors="strict"))
                        elif hasattr(value, "isoformat") and callable(value.isoformat):
                            row_size += len(value.isoformat().encode("utf-8"))
                        else:
                            row_size += len(str(value).encode("utf-8"))
                    if row_size > row_size_limit:
                        _fail("RECONCILE_ROW_EXCEEDS_ROW_LIMIT")
                    _accumulate_row(accumulator, values, table, mapping, is_source=is_source)
                    processed += 1
                    if processed % RECONCILE_COMMIT_ROWS == 0:
                        store.commit()
                        store.check_budget()
            if processed % RECONCILE_COMMIT_ROWS:
                store.commit()
                store.check_budget()
    except ReconciliationError:
        raise
    except Exception:
        _fail("TABLE_SCAN_FAILED")
    return accumulator


def _stream_row_size_limit(columns: list[dict[str, Any]], *, is_source: bool) -> int:
    if not is_source and any(column.get("binary_storage_encoding") == "base64" for column in columns):
        return MAX_RECONCILE_BASE64_ROW_BYTES
    return MAX_RECONCILE_ROW_BYTES


def _report_list_index(report: dict[str, Any], key: str, identity: tuple[str, str] | None = None) -> dict[tuple[str, str], dict[str, Any]]:
    value = report.get(key)
    if not isinstance(value, list):
        _fail("LOAD_POLICY_EVIDENCE_MISSING")
    result: dict[tuple[str, str], dict[str, Any]] = {}
    for item in value:
        if not isinstance(item, dict) or not isinstance(item.get("table"), str) or not isinstance(item.get("column"), str):
            _fail("LOAD_POLICY_EVIDENCE_INVALID")
        k = (item["table"], item["column"])
        if k in result:
            _fail("LOAD_POLICY_EVIDENCE_DUPLICATE")
        result[k] = item
    return result


def _int_metric(value: Any) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        _fail("LOAD_POLICY_EVIDENCE_INVALID")
    return value


def _validate_load_counts(report: dict[str, Any], table_names: list[str], source_acc: dict[str, dict[str, Any]], target_acc: dict[str, dict[str, Any]]) -> bool:
    entries = {item["table"]: item for item in report["table_counts"]}
    return all(
        entries[name]["source_rows"] == source_acc[name]["count"]
        and entries[name]["target_rows"] == target_acc[name]["count"]
        for name in table_names
    )


def _load_table_failures(report: dict[str, Any]) -> list[dict[str, Any]]:
    failures = report.get("table_failures", [])
    if not isinstance(failures, list):
        _fail("LOAD_TABLE_FAILURE_EVIDENCE_INVALID")
    sanitized: list[dict[str, Any]] = []
    for item in failures:
        if not isinstance(item, dict) or not isinstance(item.get("table"), str) or not isinstance(item.get("reason"), str):
            _fail("LOAD_TABLE_FAILURE_EVIDENCE_INVALID")
        if not re.fullmatch(r"[A-Z0-9_]{1,96}", item["reason"]):
            _fail("LOAD_TABLE_FAILURE_EVIDENCE_INVALID")
        row: dict[str, Any] = {"table": item["table"], "reason": item["reason"]}
        column = item.get("column")
        if column is not None:
            if not isinstance(column, str):
                _fail("LOAD_TABLE_FAILURE_EVIDENCE_INVALID")
            row["column"] = column
        for key in ("source_rows", "target_rows", "affected_cells"):
            value = item.get(key)
            if value is not None:
                row[key] = _int_metric(value)
        sanitized.append(row)
    partial_status = report.get("status") == LOAD_PARTIAL_STATUS
    if partial_status != bool(sanitized):
        _fail("LOAD_PARTIAL_FAILURE_EVIDENCE_INCONSISTENT")
    return sanitized


def _validate_policy_evidence(
    snapshot: dict[str, Any], report: dict[str, Any], tables: dict[str, dict[str, Any]],
    source_acc: dict[str, dict[str, Any]], target_acc: dict[str, dict[str, Any]],
) -> tuple[bool, bool, list[str]]:
    blockers: list[str] = []
    text_findings = _report_list_index(report, "text_policy_findings")
    binary_findings = _report_list_index(report, "binary_policy_findings")
    nul_rows_value = report.get("nul_values_normalized")
    if not isinstance(nul_rows_value, list):
        _fail("LOAD_NUL_EVIDENCE_MISSING")
    nul_rows: dict[tuple[str, str], dict[str, Any]] = {}
    for item in nul_rows_value:
        if not isinstance(item, dict) or not isinstance(item.get("table"), str) or not isinstance(item.get("column"), str):
            _fail("LOAD_NUL_EVIDENCE_INVALID")
        key = (item["table"], item["column"])
        if key in nul_rows or item.get("status") != "NORMALIZED_NUL":
            _fail("LOAD_NUL_EVIDENCE_INVALID")
        nul_rows[key] = item
    audit_rows = report.get("nul_audit_rows")
    audit_rows = _int_metric(audit_rows)

    expected_text: set[tuple[str, str]] = set()
    expected_binary: set[tuple[str, str]] = set()
    source_nul_cells = 0
    evidence_ok = True
    policy_equal = True
    for table_name, table in tables.items():
        for column in table["columns"]:
            kind, name = str(column.get("data_type", "")).lower(), str(column["name"])
            key = (table_name, name)
            source_metrics = source_acc[table_name]["nul"].get(name, {})
            target_metrics = target_acc[table_name]["nul"].get(name, {})
            if kind in TEXT_TYPES:
                expected_text.add(key)
                finding = text_findings.get(key)
                if finding is None or finding.get("scan_status") != "SCANNED" or finding.get("detector_disagreement") is not False:
                    evidence_ok = False
                    continue
                required_pairs = {
                    "cells_scanned": source_metrics.get("scanned"),
                    "method_a_python_string_cells_with_nul": source_metrics.get("physical"),
                    "method_b_cells_with_nul": source_metrics.get("physical"),
                    "python_cells_with_nul": source_metrics.get("physical"),
                    "cells_with_nul": source_metrics.get("physical"),
                    "json_escaped_nul_cells": (
                        source_metrics.get("normalized", 0)
                        if str(column.get("data_type", "")).lower() == "json" else 0
                    ),
                }
                if any(finding.get(field) != expected for field, expected in required_pairs.items()):
                    evidence_ok = False
                if finding.get("method_a_decode_failures", 0) != 0 or finding.get("invalid_declared_charset_cells_after_nul", 0) != 0:
                    evidence_ok = False
                expected_nul = source_metrics.get("normalized", 0)
                source_nul_cells += expected_nul
                row = nul_rows.get(key)
                if expected_nul:
                    if row is None or row.get("cells_with_nul") != expected_nul or row.get("nul_bytes") != source_metrics.get("bytes") or row.get("json_escaped_nul_cells") != source_metrics.get("escaped"):
                        evidence_ok = False
                elif row is not None:
                    evidence_ok = False
                if target_metrics.get("normalized", 0):
                    evidence_ok = False
            elif kind in BINARY_TYPES:
                expected_binary.add(key)
                finding = binary_findings.get(key)
                if finding is None:
                    evidence_ok = False
                    continue
                expected_pairs = {
                    "cells_scanned": source_metrics.get("scanned"),
                    "method_b_cells_with_nul": source_metrics.get("physical"),
                    "python_cells_with_nul": source_metrics.get("physical"),
                    "nul_bytes": source_metrics.get("bytes"),
                    "binary_bytes_scanned": source_acc[table_name]["binary"][name]["octets"],
                }
                if any(finding.get(field) != expected for field, expected in expected_pairs.items()):
                    evidence_ok = False
                expected_encoding = expected_binary_storage_encoding(table_name, name) or "bytea"
                if finding.get("storage_policy_id") != BINARY_STORAGE_POLICY_ID \
                    or finding.get("storage_encoding") != expected_encoding \
                    or finding.get("nul_policy") != "preserve_exact_bytes":
                    evidence_ok = False
                if source_metrics.get("physical") != target_metrics.get("physical") or source_metrics.get("bytes") != target_metrics.get("bytes"):
                    policy_equal = False
    if set(text_findings) != expected_text or set(binary_findings) != expected_binary:
        evidence_ok = False
    if set(nul_rows) - expected_text:
        evidence_ok = False
    if audit_rows != source_nul_cells or audit_rows != sum(int(item.get("cells_with_nul", 0)) for item in nul_rows.values()):
        evidence_ok = False
    if not evidence_ok:
        blockers.append("NUL_EVIDENCE_INCOMPLETE_OR_MISMATCHED")
    return evidence_ok, policy_equal, blockers


def _binary_storage_schema_metric(
    target: Any, tables: dict[str, dict[str, Any]], mappings: dict[str, dict[str, Any]]
) -> tuple[bool, dict[str, Any]]:
    checked = 0
    mismatches: list[dict[str, str]] = []
    encoding_counts = {"base64": 0, "bytea": 0}
    for table_name, table in tables.items():
        schema = mappings[table_name]["target_schema"]
        for column in table["columns"]:
            if str(column.get("data_type", "")).lower() not in BINARY_TYPES:
                continue
            name = str(column["name"])
            encoding = expected_binary_storage_encoding(table_name, name) or "bytea"
            checked += 1
            encoding_counts[encoding] += 1
            try:
                with _cursor(target) as cursor:
                    _execute(
                        cursor,
                        "SELECT data_type, collation_name FROM information_schema.columns "
                        "WHERE table_schema=%s AND table_name=%s AND column_name=%s",
                        (schema, table_name, name),
                    )
                    row = cursor.fetchone()
            except Exception:
                _fail("BINARY_STORAGE_SCHEMA_QUERY_FAILED")
            if row is None:
                mismatches.append({"table": table_name, "column": name, "reason": "COLUMN_MISSING"})
                continue
            target_type = _row_value(row, "data_type", 0)
            collation = _row_value(row, "collation_name", 1)
            if encoding == "base64":
                matched = isinstance(target_type, str) and target_type.lower() == "text" \
                    and isinstance(collation, str) and collation == "C"
            else:
                matched = isinstance(target_type, str) and target_type.lower() == "bytea"
            if not matched:
                mismatches.append({"table": table_name, "column": name, "reason": "TARGET_TYPE_OR_COLLATION_MISMATCH"})
    return not mismatches, {
        "columns_checked": checked,
        "columns_mismatched": len(mismatches),
        "columns_by_storage_encoding": encoding_counts,
        "mismatches": mismatches,
    }


def _enum_metric(report: dict[str, Any], tables: dict[str, dict[str, Any]],
                 source_acc: dict[str, dict[str, Any]], target_acc: dict[str, dict[str, Any]]) -> tuple[bool, bool]:
    evidence = report.get("enum_values_outside_declared")
    if not isinstance(evidence, list):
        _fail("LOAD_ENUM_EVIDENCE_MISSING")
    expected_keys: set[tuple[str, str]] = set()
    evidence_by_key: dict[tuple[str, str], dict[str, Any]] = {}
    enum_match = True
    enum_equal = True
    for table_name, table in tables.items():
        for column in table["columns"]:
            if str(column.get("data_type", "")).lower() != "enum":
                continue
            name = str(column["name"])
            key = (table_name, name)
            expected_keys.add(key)
            s_counts = source_acc[table_name]["enum"].get(name)
            t_counts = target_acc[table_name]["enum"].get(name)
            if s_counts is None or t_counts is None or not s_counts.equals(t_counts):
                enum_equal = False
    for item in evidence:
        if not isinstance(item, dict) or not isinstance(item.get("table"), str) or not isinstance(item.get("column"), str):
            _fail("LOAD_ENUM_EVIDENCE_INVALID")
        key = (item["table"], item["column"])
        if key in evidence_by_key or key not in expected_keys or item.get("status") != "ENUM_EXTENDED_FROM_EXISTING_VALUES":
            _fail("LOAD_ENUM_EVIDENCE_INVALID")
        evidence_by_key[key] = item
    for key in expected_keys:
        table, column = key
        counts = source_acc[table]["enum"].get(column)
        if counts is None:
            _fail("ENUM_METRIC_STORE_MISSING")
        expected_total = counts.total()
        item = evidence_by_key.get(key)
        if expected_total:
            if item is None:
                enum_match = False
                continue
            metric_counts = item.get("counts_by_distinct_extra_value")
            if not isinstance(metric_counts, list):
                _fail("LOAD_ENUM_EVIDENCE_INVALID")
            try:
                safe_counts = sorted(_int_metric(value) for value in metric_counts)
            except ReconciliationError:
                _fail("LOAD_ENUM_EVIDENCE_INVALID")
            if (
                item.get("cells_outside_declared_labels") != expected_total
                or item.get("distinct_extra_values") != counts.unique_count()
                or safe_counts != sorted(counts.values())
                or item.get("empty_label_cells") != counts.get(b"", 0)
            ):
                enum_match = False
        elif item is not None:
            enum_match = False
    if set(evidence_by_key) != {
        key for key in expected_keys
        if source_acc[key[0]]["enum"][key[1]].unique_count() > 0
    }:
        enum_match = False
    return enum_match, enum_equal


def _nul_audit_metric(target: Any, tables: dict[str, dict[str, Any]],
                      source_acc: dict[str, dict[str, Any]],
                      checkpoint: Callable[[Any], None] | None = None) -> tuple[bool, dict[str, int]]:
    store = next(iter(source_acc.values()))["counter_store"]
    expected = store.counter("source:nul_audit")
    observed = store.counter("target:nul_audit")
    expected_rows = expected.total()
    invalid_timestamp_rows = 0
    column_names_by_table = {
        table_name: {str(column["name"]) for column in table["columns"]}
        for table_name, table in tables.items()
    }
    statement = (
        f"SELECT {_ident_pg('tabela')}, {_ident_pg('coluna')}, {_ident_pg('pk')}, "
        f"{_ident_pg('valor_original')}, {_ident_pg('migrado_em')} "
        f"FROM {_qualified('legado', 'auditoria_nul', mysql=False)}"
    )
    processed = 0
    try:
        with _stream_cursor(target, mysql=False, cursor_name="p2_nul_audit_" + hashlib.sha256(b"audit").hexdigest()[:16]) as cursor:
            if checkpoint is not None:
                checkpoint(target)
            _execute(cursor, statement)
            while True:
                if checkpoint is not None:
                    checkpoint(target)
                rows = cursor.fetchmany(1)
                if not rows:
                    break
                for row in rows:
                    row_values = list(row.values()) if isinstance(row, dict) else list(row)
                    row_size = 0
                    for value in row_values:
                        if value is None:
                            continue
                        if isinstance(value, (bytes, bytearray, memoryview)):
                            row_size += len(value)
                        elif isinstance(value, str):
                            row_size += len(value.encode("utf-8", errors="strict"))
                        else:
                            row_size += len(str(value).encode("utf-8"))
                    if row_size > MAX_RECONCILE_ROW_BYTES:
                        _fail("RECONCILE_ROW_EXCEEDS_128_MIB")
                    table_name = _row_value(row, "tabela", 0)
                    column_name = _row_value(row, "coluna", 1)
                    pk_text = _row_value(row, "pk", 2)
                    original = _row_value(row, "valor_original", 3)
                    migrated_at = _row_value(row, "migrado_em", 4)
                    if not isinstance(table_name, str) or table_name not in tables or not isinstance(column_name, str) \
                        or column_name not in column_names_by_table[table_name] \
                        or not isinstance(pk_text, str) or not pk_text \
                        or not isinstance(original, (bytes, bytearray, memoryview)):
                        _fail("NUL_AUDIT_ROW_INVALID")
                    if migrated_at is None:
                        invalid_timestamp_rows += 1
                    audit_key = _frame(b"T", table_name.encode("utf-8")) \
                        + _frame(b"C", column_name.encode("utf-8")) \
                        + _nul_audit_counter_key(pk_text, hashlib.sha256(bytes(original)).digest())
                    observed.add(audit_key)
                    processed += 1
                    if processed % RECONCILE_COMMIT_ROWS == 0:
                        store.commit()
                        store.check_budget()
            if processed % RECONCILE_COMMIT_ROWS:
                store.commit()
                store.check_budget()
    except ReconciliationError:
        raise
    except Exception:
        _fail("NUL_AUDIT_SCAN_FAILED")
    observed_rows = observed.total()
    equal = expected.equals(observed) and invalid_timestamp_rows == 0
    return equal, {
        "expected_rows": expected_rows,
        "observed_rows": observed_rows,
        "rows_missing_or_extra": abs(expected_rows - observed_rows),
        "rows_with_null_migrated_at": invalid_timestamp_rows,
    }


def _fetchone(connection: Any, statement: str, params: tuple[Any, ...] = (),
              checkpoint: Callable[[Any], None] | None = None) -> Any:
    with _cursor(connection) as cursor:
        if checkpoint is not None:
            checkpoint(connection)
        _execute(cursor, statement, params)
        try:
            return cursor.fetchone()
        except Exception:
            _fail("DATABASE_QUERY_FAILED")


def _fetch_count(connection: Any, statement: str, params: tuple[Any, ...] = (),
                 checkpoint: Callable[[Any], None] | None = None) -> int:
    row = _fetchone(connection, statement, params, checkpoint)
    if row is None:
        _fail("DATABASE_RESULT_INVALID")
    value = _row_value(row, "count", 0)
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        _fail("DATABASE_RESULT_INVALID")
    return value


def _fk_orphan_count(connection: Any, *, source: bool, schema: str, table: str,
                     columns: list[str], ref_schema: str, ref_table: str,
                     ref_columns: list[str], checkpoint: Callable[[Any], None] | None = None) -> int:
    if not columns or len(columns) != len(ref_columns):
        _fail("FOREIGN_KEY_METADATA_INVALID")
    left = _qualified(schema, table, mysql=source)
    right = _qualified(ref_schema, ref_table, mysql=source)
    if source:
        quote = _ident_mysql
    else:
        quote = _ident_pg
    joins = " AND ".join(f"r.{quote(dst)} = s.{quote(src)}" for src, dst in zip(columns, ref_columns, strict=True))
    nonnull = " AND ".join(f"s.{quote(col)} IS NOT NULL" for col in columns)
    statement = f"SELECT COUNT(*) AS count FROM {left} AS s WHERE {nonnull} AND NOT EXISTS (SELECT 1 FROM {right} AS r WHERE {joins})"
    return _fetch_count(connection, statement, checkpoint=checkpoint)


def _target_foreign_key_definitions(target: Any, schema: str, table: str | None = None) -> list[Any]:
    table_filter = " AND child.relname = %s" if table is not None else ""
    statement = f"""
        SELECT child_ns.nspname AS child_schema, child.relname AS table_name,
               ARRAY_AGG(child_att.attname ORDER BY key_col.ordinality) AS columns,
               parent_ns.nspname AS referenced_schema, parent.relname AS referenced_table,
               ARRAY_AGG(parent_att.attname ORDER BY key_col.ordinality) AS referenced_columns,
               constraint_row.convalidated AS validated,
               constraint_row.confupdtype AS update_action,
               constraint_row.confdeltype AS delete_action,
               constraint_row.conname AS constraint_name
        FROM pg_catalog.pg_constraint AS constraint_row
        JOIN pg_catalog.pg_class AS child ON child.oid = constraint_row.conrelid
        JOIN pg_catalog.pg_namespace AS child_ns ON child_ns.oid = child.relnamespace
        JOIN pg_catalog.pg_class AS parent ON parent.oid = constraint_row.confrelid
        JOIN pg_catalog.pg_namespace AS parent_ns ON parent_ns.oid = parent.relnamespace
        JOIN LATERAL unnest(constraint_row.conkey, constraint_row.confkey)
            WITH ORDINALITY AS key_col(child_attnum, parent_attnum, ordinality) ON TRUE
        JOIN pg_catalog.pg_attribute AS child_att
            ON child_att.attrelid = child.oid AND child_att.attnum = key_col.child_attnum
        JOIN pg_catalog.pg_attribute AS parent_att
            ON parent_att.attrelid = parent.oid AND parent_att.attnum = key_col.parent_attnum
        WHERE constraint_row.contype = 'f' AND child_ns.nspname = %s{table_filter}
        GROUP BY child_ns.nspname, child.relname, parent_ns.nspname, parent.relname,
                 constraint_row.convalidated, constraint_row.confupdtype, constraint_row.confdeltype,
                 constraint_row.conname
    """
    try:
        with _cursor(target) as cursor:
            _execute(cursor, statement, (schema, table) if table is not None else (schema,))
            return list(cursor.fetchall())
    except ReconciliationError:
        raise
    except Exception:
        _fail("TARGET_FOREIGN_KEY_METADATA_UNAVAILABLE")


def _foreign_key_metric(source: Any, target: Any, snapshot: dict[str, Any], report: dict[str, Any],
                        mappings: dict[str, dict[str, Any]],
                        checkpoint: Callable[[Any], None] | None = None) -> tuple[bool, list[dict[str, Any]]]:
    foreign_keys = snapshot.get("foreign_keys")
    results = report.get("foreign_key_results")
    if not isinstance(foreign_keys, list) or not isinstance(results, list):
        _fail("FOREIGN_KEY_EVIDENCE_MISSING")
    result_index: dict[tuple[str, str, tuple[str, ...], str, tuple[str, ...], str, str, str], dict[str, Any]] = {}
    for item in results:
        if not isinstance(item, dict):
            _fail("FOREIGN_KEY_EVIDENCE_INVALID")
        table, ref_table = item.get("table"), item.get("referenced_table")
        ref_schema, constraint = item.get("referenced_schema"), item.get("constraint_name")
        columns, ref_columns = item.get("columns"), item.get("referenced_columns")
        update_rule, delete_rule = item.get("update_rule"), item.get("delete_rule")
        allowed_rules = {"NO ACTION", "RESTRICT", "CASCADE", "SET NULL", "SET DEFAULT"}
        if not isinstance(table, str) or not isinstance(ref_table, str) or not isinstance(ref_schema, str) \
            or not isinstance(constraint, str) or not constraint \
            or not isinstance(columns, list) or not columns or not isinstance(ref_columns, list) \
            or len(columns) != len(ref_columns) or any(not isinstance(c, str) for c in columns + ref_columns) \
            or update_rule not in allowed_rules or delete_rule not in allowed_rules:
            _fail("FOREIGN_KEY_EVIDENCE_INVALID")
        key = (table, ref_table, tuple(columns), ref_schema, tuple(ref_columns), constraint, update_rule, delete_rule)
        if key in result_index:
            _fail("FOREIGN_KEY_EVIDENCE_AMBIGUOUS")
        result_index[key] = item
    comparison: list[dict[str, Any]] = []
    ok = True
    expected_result_keys: set[tuple[str, str, tuple[str, ...], str, tuple[str, ...], str, str, str]] = set()
    target_fk_cache: dict[str, list[Any]] = {table: [] for table in mappings}
    target_schema_by_table = {table: mapping["target_schema"] for table, mapping in mappings.items()}
    for target_schema in sorted(set(target_schema_by_table.values())):
        for state_row in _target_foreign_key_definitions(target, target_schema):
            child_schema = _row_value(state_row, "child_schema", 0)
            child_table = _row_value(state_row, "table_name", 1)
            if child_table not in target_fk_cache or target_schema_by_table[child_table] != child_schema:
                _fail("TARGET_FOREIGN_KEY_SCOPE_UNEXPECTED")
            target_fk_cache[child_table].append(state_row)
    expected_target_signatures: set[tuple[str, str, tuple[str, ...], str, str, tuple[str, ...], str, str, str]] = set()
    action_codes = {"NO ACTION": "a", "RESTRICT": "r", "CASCADE": "c", "SET NULL": "n", "SET DEFAULT": "d"}
    for fk in foreign_keys:
        if not isinstance(fk, dict):
            _fail("FOREIGN_KEY_METADATA_INVALID")
        table = fk.get("table")
        ref_table = fk.get("referenced_table")
        columns, ref_columns = fk.get("columns"), fk.get("referenced_columns")
        ref_schema = fk.get("referenced_schema")
        constraint = fk.get("name")
        update_rule = str(fk.get("update_rule", "NO ACTION")).upper()
        delete_rule = str(fk.get("delete_rule", "NO ACTION")).upper()
        if not isinstance(table, str) or not isinstance(ref_table, str) or not isinstance(constraint, str) \
            or not constraint or not isinstance(columns, list) or not columns or not isinstance(ref_columns, list) \
            or len(columns) != len(ref_columns) or any(not isinstance(c, str) for c in columns + ref_columns):
            _fail("FOREIGN_KEY_METADATA_INVALID")
        if update_rule not in {"NO ACTION", "RESTRICT", "CASCADE", "SET NULL", "SET DEFAULT"} \
            or delete_rule not in {"NO ACTION", "RESTRICT", "CASCADE", "SET NULL", "SET DEFAULT"}:
            _fail("FOREIGN_KEY_METADATA_INVALID")
        if table not in mappings or ref_table not in mappings or ref_schema != SOURCE_SCHEMA:
            _fail("FOREIGN_KEY_SCOPE_INVALID")
        if any(not isinstance(c, str) for c in columns + ref_columns):
            _fail("FOREIGN_KEY_METADATA_INVALID")
        target_schema = mappings[table]["target_schema"]
        target_ref_schema = mappings[ref_table]["target_schema"]
        target_matches = []
        for state_row in target_fk_cache[table]:
            state = (
                _row_value(state_row, "child_schema", 0),
                _row_value(state_row, "table_name", 1),
                _row_value(state_row, "columns", 2),
                _row_value(state_row, "referenced_schema", 3),
                _row_value(state_row, "referenced_table", 4),
                _row_value(state_row, "referenced_columns", 5),
                _row_value(state_row, "validated", 6),
                _row_value(state_row, "update_action", 7),
                _row_value(state_row, "delete_action", 8),
                _row_value(state_row, "constraint_name", 9),
            )
            state_columns = list(state[2]) if state[2] is not None else []
            state_ref_columns = list(state[5]) if state[5] is not None else []
            if state[:2] == (target_schema, table) and state[3:5] == (target_ref_schema, ref_table) \
                and state_columns == columns and state_ref_columns == ref_columns and state[9] == constraint:
                target_matches.append(state)
        constraint_present = len(target_matches) == 1
        constraint_validated = bool(target_matches[0][6]) if constraint_present else False
        action_codes = {"NO ACTION": "a", "RESTRICT": "r", "CASCADE": "c", "SET NULL": "n", "SET DEFAULT": "d"}
        constraint_actions_match = constraint_present and (
            target_matches[0][7] == action_codes.get(str(fk.get("update_rule", "NO ACTION")).upper())
            and target_matches[0][8] == action_codes.get(str(fk.get("delete_rule", "NO ACTION")).upper())
        )
        expected_signature = (
            target_schema, table, tuple(columns), target_ref_schema, ref_table, tuple(ref_columns), constraint,
            action_codes[str(fk.get("update_rule", "NO ACTION")).upper()],
            action_codes[str(fk.get("delete_rule", "NO ACTION")).upper()],
        )
        if expected_signature in expected_target_signatures:
            _fail("FOREIGN_KEY_EVIDENCE_AMBIGUOUS")
        expected_target_signatures.add(expected_signature)
        source_orphans = _fk_orphan_count(
            source, source=True, schema=SOURCE_SCHEMA, table=table, columns=columns,
            ref_schema=SOURCE_SCHEMA, ref_table=ref_table, ref_columns=ref_columns, checkpoint=checkpoint,
        )
        target_orphans = _fk_orphan_count(
            target, source=False, schema=target_schema, table=table, columns=columns,
            ref_schema=target_ref_schema, ref_table=ref_table, ref_columns=ref_columns, checkpoint=checkpoint,
        )
        key = (table, ref_table, tuple(columns), target_ref_schema, tuple(ref_columns), constraint, update_rule, delete_rule)
        if key in expected_result_keys:
            _fail("FOREIGN_KEY_EVIDENCE_AMBIGUOUS")
        expected_result_keys.add(key)
        load_item = result_index.get(key)
        load_status = load_item.get("status") if isinstance(load_item, dict) else None
        if load_item is None or load_item.get("orphan_rows") != target_orphans:
            ok = False
        elif (target_orphans == 0 and load_item.get("status") != "VALIDATED") or (
            target_orphans > 0 and load_item.get("status") != "NOT_VALID_ORPHANS_REPORTED"
        ):
            ok = False
        constraint_state_acceptable = (
            (target_orphans == 0 and constraint_validated)
            or (target_orphans > 0 and not constraint_validated and load_status == "NOT_VALID_ORPHANS_REPORTED")
        )
        if source_orphans != target_orphans or not constraint_present or not constraint_state_acceptable or not constraint_actions_match:
            ok = False
        comparison.append({
            "table": table,
            "referenced_table": ref_table,
            "source_orphans": source_orphans,
            "target_orphans": target_orphans,
            "constraint_present": constraint_present,
            "constraint_validated": constraint_validated,
            "constraint_state_acceptable": constraint_state_acceptable,
            "constraint_actions_match": constraint_actions_match,
            "equal": source_orphans == target_orphans and constraint_present and constraint_state_acceptable and constraint_actions_match,
        })
    if set(result_index) != expected_result_keys:
        ok = False
    actual_target_signatures: set[tuple[str, str, tuple[str, ...], str, str, tuple[str, ...], str, str, str]] = set()
    for table, definitions in target_fk_cache.items():
        for state_row in definitions:
            signature = (
                _row_value(state_row, "child_schema", 0),
                _row_value(state_row, "table_name", 1),
                tuple(_row_value(state_row, "columns", 2) or ()),
                _row_value(state_row, "referenced_schema", 3),
                _row_value(state_row, "referenced_table", 4),
                tuple(_row_value(state_row, "referenced_columns", 5) or ()),
                _row_value(state_row, "constraint_name", 9),
                _row_value(state_row, "update_action", 7),
                _row_value(state_row, "delete_action", 8),
            )
            if signature[0] != target_schema_by_table[table] or signature[1] != table:
                _fail("TARGET_FOREIGN_KEY_METADATA_INVALID")
            if not all(isinstance(value, str) and value for value in signature[:2] + signature[3:5] + (signature[6],)) \
                or not signature[2] or not signature[5] or len(signature[2]) != len(signature[5]) \
                or signature[7] not in action_codes.values() or signature[8] not in action_codes.values():
                _fail("TARGET_FOREIGN_KEY_METADATA_INVALID")
            if signature in actual_target_signatures:
                _fail("TARGET_FOREIGN_KEY_METADATA_AMBIGUOUS")
            actual_target_signatures.add(signature)
    if actual_target_signatures != expected_target_signatures:
        ok = False
    return ok, comparison


def _identity_columns(tables: dict[str, dict[str, Any]], mappings: dict[str, dict[str, Any]]) -> list[dict[str, Any]]:
    result: list[dict[str, Any]] = []
    for name, table in tables.items():
        discovered = [c for c in table["columns"] if "auto_increment" in str(c.get("extra", "")).lower()]
        declared = mappings[name].get("identity_columns", [])
        if not isinstance(declared, list):
            _fail("IDENTITY_METADATA_INVALID")
        if len(discovered) != len(declared):
            _fail("METRIC_NOT_IMPLEMENTED")
        by_name: dict[str, dict[str, Any]] = {}
        for item in declared:
            if not isinstance(item, dict) or not isinstance(item.get("column"), str) or item["column"] in by_name:
                _fail("IDENTITY_METADATA_INVALID")
            by_name[item["column"]] = item
        for column in discovered:
            name_col = column["name"]
            identity = by_name.get(name_col)
            if identity is None or identity.get("strategy") not in {"identity", "unsigned_sequence_pair"}:
                _fail("METRIC_NOT_IMPLEMENTED")
            result.append({
                "table": name,
                "schema": mappings[name]["target_schema"],
                "column": name_col,
                "source_next_auto_increment": table.get("source_next_auto_increment"),
                "strategy": identity["strategy"],
                "low_sequence": identity.get("low_sequence"),
                "high_sequence": identity.get("high_sequence"),
            })
    return result


def _sequence_state(target: Any, *, schema: str, table: str, column: str) -> tuple[int, int, int, bool]:
    relation = f'{_ident_pg(schema)}.{_ident_pg(table)}'
    row = _fetchone(target, "SELECT pg_catalog.pg_get_serial_sequence(%s, %s) AS sequence_name", (relation, column))
    if row is None:
        _fail("IDENTITY_SEQUENCE_UNAVAILABLE")
    seq = _row_value(row, "sequence_name", 0)
    if not isinstance(seq, str) or not seq:
        _fail("IDENTITY_SEQUENCE_UNAVAILABLE")
    row2 = _fetchone(target, """
        SELECT n.nspname AS schema_name, c.relname AS sequence_name,
               p.start_value, p.increment_by, p.max_value, p.last_value
        FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_catalog.pg_sequences p ON p.schemaname = n.nspname AND p.sequencename = c.relname
        WHERE c.oid = %s::regclass
    """, (seq,))
    if row2 is None:
        _fail("IDENTITY_SEQUENCE_UNAVAILABLE")
    seq_schema = _row_value(row2, "schema_name", 0)
    seq_name = _row_value(row2, "sequence_name", 1)
    start = _row_value(row2, "start_value", 2)
    increment = _row_value(row2, "increment_by", 3)
    maximum = _row_value(row2, "max_value", 4)
    last = _row_value(row2, "last_value", 5)
    if seq_schema != schema or not isinstance(seq_name, str) or not isinstance(start, int) \
        or not isinstance(increment, int) or increment <= 0 or not isinstance(maximum, int) or maximum < start:
        _fail("IDENTITY_SEQUENCE_SCOPE_INVALID")
    if last is not None and (isinstance(last, bool) or not isinstance(last, int) or last < start or last > maximum):
        _fail("IDENTITY_SEQUENCE_STATE_INVALID")
    if last is None:
        next_value = start
    else:
        # pg_sequences.last_value is the latest reserved value; reading is permitted only if not NULL.
        next_value = last + increment
    qsequence = f'{_ident_pg(seq_schema)}.{_ident_pg(seq_name)}'
    called_row = _fetchone(target, f"SELECT is_called FROM {qsequence}")
    if called_row is None:
        _fail("IDENTITY_SEQUENCE_UNAVAILABLE")
    is_called = _row_value(called_row, "is_called", 0)
    if not isinstance(is_called, bool):
        _fail("IDENTITY_SEQUENCE_STATE_INVALID")
    # An uncalled sequence will hand out last_value (usually start); otherwise nextval advances by increment.
    if not is_called:
        next_value = start if last is None else last
    elif next_value > maximum:
        _fail("IDENTITY_SEQUENCE_EXHAUSTED")
    return start, increment, next_value, is_called


def _identity_sync_complete(target: Any, *, schema: str, table: str, column: str) -> bool:
    expected_key = f"{schema}.{table}.{column}"
    state_table = f'{_ident_pg("erp")}.' + _ident_pg("_p2_autoincrement_state")
    row = _fetchone(
        target,
        f"SELECT post_load_sync_complete FROM {state_table} WHERE sequence_name = %s",
        (expected_key,),
    )
    if row is None:
        _fail("IDENTITY_SYNC_STATE_INCOMPLETE")
    completed = _row_value(row, "post_load_sync_complete", 0)
    if completed is not True:
        _fail("IDENTITY_SYNC_STATE_INCOMPLETE")
    return True


def _unsigned_pair_sequence_state(
    target: Any, *, schema: str, table: str, column: str, expected_low: str, expected_high: str
) -> tuple[int, int, int]:
    if schema not in TARGET_SCHEMAS:
        _fail("TARGET_SCHEMA_NOT_ALLOWLISTED")
    key = f"{schema}.{table}.{column}"
    row = _fetchone(
        target,
        f"SELECT low_sequence::text, high_sequence::text FROM {_ident_pg('erp')}.{_ident_pg('_p2_autoincrement_state')} WHERE sequence_name = %s",
        (key,),
    )
    if row is None:
        _fail("IDENTITY_SYNC_STATE_INCOMPLETE")
    low_ref = _row_value(row, "low_sequence", 0)
    high_ref = _row_value(row, "high_sequence", 1)
    if not isinstance(low_ref, str) or not isinstance(high_ref, str):
        _fail("IDENTITY_SEQUENCE_STATE_INVALID")
    parsed: list[tuple[str, str]] = []
    for ref, expected_name in ((low_ref, expected_low), (high_ref, expected_high)):
        match = re.fullmatch(r'(?:"?([a-z_][a-z0-9_]*)"?\.)"?([a-z_][a-z0-9_]*)"?', ref, re.I)
        if not match or match.group(1) != "erp" or match.group(2) != expected_name:
            _fail("IDENTITY_SEQUENCE_SCOPE_INVALID")
        parsed.append((match.group(1), match.group(2)))
    sequence_states: list[tuple[int, int, int, int | None, bool]] = []
    for schema, name in parsed:
        state = _fetchone(
            target,
            "SELECT start_value, increment_by, max_value, last_value FROM pg_catalog.pg_sequences WHERE schemaname = %s AND sequencename = %s",
            (schema, name),
        )
        if state is None:
            _fail("IDENTITY_SEQUENCE_UNAVAILABLE")
        start = _row_value(state, "start_value", 0)
        increment = _row_value(state, "increment_by", 1)
        maximum = _row_value(state, "max_value", 2)
        last = _row_value(state, "last_value", 3)
        if not all(isinstance(v, int) and not isinstance(v, bool) for v in (start, increment, maximum)) or increment != 1:
            _fail("IDENTITY_SEQUENCE_STATE_INVALID")
        called_row = _fetchone(target, f"SELECT is_called FROM {_ident_pg(schema)}.{_ident_pg(name)}")
        if called_row is None:
            _fail("IDENTITY_SEQUENCE_UNAVAILABLE")
        called = _row_value(called_row, "is_called", 0)
        if not isinstance(called, bool):
            _fail("IDENTITY_SEQUENCE_STATE_INVALID")
        if last is not None and (isinstance(last, bool) or not isinstance(last, int) or last < start or last > maximum):
            _fail("IDENTITY_SEQUENCE_STATE_INVALID")
        if called and last is None:
            _fail("IDENTITY_SEQUENCE_STATE_INVALID")
        sequence_states.append((start, increment, maximum, last, called))
    if sequence_states[0][0:3] != (1, 1, POSTGRES_SEQUENCE_MAX) or sequence_states[1][0:3] != (0, 1, POSTGRES_SEQUENCE_MAX):
        _fail("IDENTITY_SEQUENCE_STATE_INVALID")
    low_start, low_increment, low_max, low_last, low_called = sequence_states[0]
    high_start, high_increment, high_max, high_last, high_called = sequence_states[1]
    low_next = (low_last + low_increment) if low_called and low_last is not None else (low_last if low_last is not None else low_start)
    high_next = (high_last + high_increment) if high_called and high_last is not None else (high_last if high_last is not None else high_start)
    if high_next > high_max:
        _fail("IDENTITY_SEQUENCE_EXHAUSTED")
    effective_next = low_next if low_next <= low_max else UNSIGNED_BIGINT_OFFSET + high_next
    if effective_next > MYSQL_UNSIGNED_BIGINT_MAX:
        _fail("IDENTITY_SEQUENCE_EXHAUSTED")
    return effective_next, low_next, high_next


def _identity_metric(target: Any, tables: dict[str, dict[str, Any]], mappings: dict[str, dict[str, Any]],
                     source_acc: dict[str, dict[str, Any]], target_acc: dict[str, dict[str, Any]]) -> tuple[bool, list[dict[str, Any]]]:
    identities = _identity_columns(tables, mappings)
    comparisons: list[dict[str, Any]] = []
    ok = True
    for item in identities:
        table, column = item["table"], item["column"]
        _identity_sync_complete(target, schema=item["schema"], table=table, column=column)
        source_max = source_acc[table]["maxima"].get(column)
        target_max = target_acc[table]["maxima"].get(column)
        if source_max != target_max:
            ok = False
        strategy = item["strategy"]
        if strategy == "identity":
            _start, _increment, next_value, _called = _sequence_state(
                target, schema=item["schema"], table=table, column=column
            )
        else:
            low_name, high_name = item.get("low_sequence"), item.get("high_sequence")
            if not isinstance(low_name, str) or not isinstance(high_name, str):
                _fail("IDENTITY_METADATA_INVALID")
            next_value, _low_next, _high_next = _unsigned_pair_sequence_state(
                target, schema=item["schema"], table=table, column=column,
                expected_low=low_name, expected_high=high_name
            )
        required_next = (target_max + 1) if target_max is not None else Decimal(1)
        source_next = item.get("source_next_auto_increment")
        if source_next is not None:
            if isinstance(source_next, bool) or not isinstance(source_next, int) or source_next < 1:
                _fail("IDENTITY_METADATA_INVALID")
            required_next = max(required_next, Decimal(source_next))
        if Decimal(required_next) > MYSQL_UNSIGNED_BIGINT_MAX or Decimal(next_value) < required_next:
            ok = False
        comparisons.append({
            "table": table,
            "column": column,
            "strategy": strategy,
            "source_max_id": _decimal_report(source_max),
            "target_max_id": _decimal_report(target_max),
            "next_sequence_value": next_value,
            "required_next_value": _decimal_report(required_next),
            "equal_max_id": source_max == target_max,
            "sequence_above_max": Decimal(next_value) > (target_max if target_max is not None else Decimal(0)),
        })
    # Ordinary id columns are max-checked even when the source does not declare AUTO_INCREMENT.
    for name, table in tables.items():
        id_columns = [c for c in table["columns"] if str(c["name"]).lower() == "id"]
        for column in id_columns:
            col = column["name"]
            if source_acc[name]["maxima"].get(col) != target_acc[name]["maxima"].get(col):
                ok = False
    return ok, comparisons


def _decimal_report(value: Decimal | None) -> str | None:
    if value is None:
        return None
    return format(value, "f")


def _aggregate_metric_results(tables: dict[str, dict[str, Any]], source_acc: dict[str, dict[str, Any]],
                              target_acc: dict[str, dict[str, Any]]) -> dict[str, dict[str, Any]]:
    result: dict[str, dict[str, Any]] = {}
    for table, definition in tables.items():
        source, target = source_acc[table], target_acc[table]
        # Hashes and canonical cell bytes are intentionally not included in this report.
        row_equal = source["count"] == target["count"] and _row_hashes_digest(source["hashes"], source["count"]) == _row_hashes_digest(target["hashes"], target["count"])
        numeric: dict[str, Any] = {}
        for column in source["sums"]:
            left, right = source["sums"][column], target["sums"].get(column)
            numeric[column] = {"source_sum": _decimal_report(left), "target_sum": _decimal_report(right), "equal": left == right}
        binary: dict[str, Any] = {}
        for column, left in source["binary"].items():
            right = target["binary"].get(column)
            if right is None:
                _fail("BINARY_COLUMN_MAPPING_MISSING")
            binary[column] = {
                "source_nonnull_count": left["nonnull"], "target_nonnull_count": right["nonnull"],
                "source_octet_length_sum": left["octets"], "target_octet_length_sum": right["octets"],
                "octet_lengths_equal": (left["nonnull"], left["octets"]) == (right["nonnull"], right["octets"]),
                "bytewise_hashes_equal": left["hashes"].equals(right["hashes"]),
            }
        if set(source["generated"]) != set(target["generated"]):
            _fail("GENERATED_COLUMN_MAPPING_MISSING")
        generated: dict[str, Any] = {}
        for column, left in source["generated"].items():
            right = target["generated"].get(column)
            if right is None:
                _fail("GENERATED_COLUMN_MAPPING_MISSING")
            generated[column] = {"calculated_values_equal": left.equals(right)}
        tenant_left, tenant_right = source["tenant"], target["tenant"]
        tenant_equal = tenant_left.equals(tenant_right)
        tenant_hashes_equal = source["tenant_hashes"].equals(target["tenant_hashes"])
        maxima: dict[str, Any] = {}
        for column in set(source["maxima"]) | set(target["maxima"]):
            left, right = source["maxima"].get(column), target["maxima"].get(column)
            maxima[column] = {"source_max": _decimal_report(left), "target_max": _decimal_report(right), "equal": left == right}
        row_count_equal = source["count"] == target["count"]
        result[table] = {
            "source_count": source["count"],
            "target_count": target["count"],
            "row_count_equal": row_count_equal,
            "row_multiset_hashes_equal": row_equal,
            "max_ids": maxima,
            "numeric_sums": numeric,
            "binary_lengths": binary,
            "generated_columns": generated,
            "tenant_distribution": {
                "source_tenant_count": tenant_left.unique_count(),
                "target_tenant_count": tenant_right.unique_count(),
                "equal": tenant_equal,
                "row_hashes_equal": tenant_hashes_equal,
            },
        }
    return result


def reconcile(
    source: Any,
    target: Any,
    snapshot: dict[str, Any],
    report: dict[str, Any],
    mappings: dict[str, Any],
    *,
    checkpoint: Callable[[Any], None] | None = None,
    temp_root: Path | str | None = None,
    expected_target_database: str = TARGET_DATABASE,
    live_source_snapshot: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Compare origem read-only e destino allowlisted usando entradas injetadas.

    No máximo um status ``RECONCILED`` é emitido, e apenas quando todas as
    categorias requeridas têm evidência completa e igualdade. Falhas são
    sanitizadas e retornadas como ``BLOCKED``/``DIVERGED`` sem valores de linha.
    """
    started = _time.monotonic()
    counter_store: _DiskCounterStore | None = None
    metric_status: dict[str, str] = {}
    tables_manifest: dict[str, dict[str, Any]] = {}
    result: dict[str, Any] = {
        "reconciler_version": 4,
        "source_schema": SOURCE_SCHEMA,
        "target_database": expected_target_database,
        "allowed_target_schemas": sorted(TARGET_SCHEMAS),
        "status": "BLOCKED",
        "failure_code": None,
        "metrics": {},
        "tables": {},
        "elapsed_seconds": None,
        "temporary_store_cleanup": "PENDING",
        "table_failures": [],
    }
    try:
        counter_store = _DiskCounterStore(temp_root=temp_root)
        _validate_snapshot_provenance(snapshot, live_source_snapshot)
        tables_manifest, normalized_mappings = _validate_contract(
            source, target, snapshot, report, mappings, expected_target_database,
        )
        result["table_failures"] = _load_table_failures(report)
        names = sorted(tables_manifest)
        source_acc: dict[str, dict[str, Any]] = {}
        target_acc: dict[str, dict[str, Any]] = {}
        for name in names:
            table, mapping = tables_manifest[name], normalized_mappings[name]
            cols = table["columns"]
            source_acc[name] = _stream_table(
                source, schema=SOURCE_SCHEMA, table_name=name, columns=cols, mysql=True,
                table=table, mapping=mapping, is_source=True, store=counter_store, checkpoint=checkpoint,
            )
            target_acc[name] = _stream_table(
                target, schema=mapping["target_schema"], table_name=name, columns=cols, mysql=False,
                table=table, mapping=mapping, is_source=False, store=counter_store, checkpoint=checkpoint,
            )

        counter_store.commit()
        table_results = _aggregate_metric_results(tables_manifest, source_acc, target_acc)
        result["tables"] = table_results
        load_counts_equal = _validate_load_counts(report, names, source_acc, target_acc)
        row_count_equal = load_counts_equal and all(v["row_count_equal"] for v in table_results.values())
        row_hash_equal = all(v["row_multiset_hashes_equal"] for v in table_results.values())
        tenant_equal = all(v["tenant_distribution"]["equal"] for v in table_results.values())
        tenant_hashes_equal = all(v["tenant_distribution"]["row_hashes_equal"] for v in table_results.values())
        numeric_equal = all(all(metric["equal"] for metric in v["numeric_sums"].values()) for v in table_results.values())
        binary_length_equal = all(all(metric["octet_lengths_equal"] for metric in v["binary_lengths"].values()) for v in table_results.values())
        binary_hash_equal = all(all(metric["bytewise_hashes_equal"] for metric in v["binary_lengths"].values()) for v in table_results.values())
        metric_status["row_count"] = "PASS" if row_count_equal else "DIVERGED"
        metric_status["load_table_failures"] = "DIVERGED" if result["table_failures"] else "PASS"
        metric_status["row_hash_multiset"] = "PASS" if row_hash_equal else "DIVERGED"
        metric_status["max_id"] = "PASS" if all(all(v["equal"] for v in table["max_ids"].values()) for table in table_results.values()) else "DIVERGED"
        metric_status["numeric_sums"] = "PASS" if numeric_equal else "DIVERGED"
        metric_status["binary_octet_length"] = "PASS" if binary_length_equal else "DIVERGED"
        metric_status["binary_bytewise_hash"] = "PASS" if binary_hash_equal else "DIVERGED"
        binary_schema_ok, binary_schema_result = _binary_storage_schema_metric(
            target, tables_manifest, normalized_mappings
        )
        result["binary_storage_schema"] = binary_schema_result
        metric_status["binary_storage_schema"] = "PASS" if binary_schema_ok else "DIVERGED"
        metric_status["tenant_distribution"] = "PASS" if tenant_equal else "DIVERGED"
        metric_status["tenant_row_hashes"] = "PASS" if tenant_hashes_equal else "DIVERGED"

        enum_evidence_equal, enum_values_equal = _enum_metric(report, tables_manifest, source_acc, target_acc)
        metric_status["enum_values"] = "PASS" if enum_evidence_equal and enum_values_equal else "DIVERGED"
        generated_values_equal = all(
            all(column["calculated_values_equal"] for column in table_result["generated_columns"].values())
            for table_result in table_results.values()
        )
        metric_status["generated_columns"] = "PASS" if generated_values_equal else "DIVERGED"

        nul_ok, nul_equal, nul_blockers = _validate_policy_evidence(snapshot, report, tables_manifest, source_acc, target_acc)
        metric_status["nul_normalization"] = "BLOCKED" if not nul_ok else ("PASS" if nul_equal else "DIVERGED")
        nul_audit_equal, nul_audit_counts = _nul_audit_metric(target, tables_manifest, source_acc, checkpoint)
        result["nul_audit_preservation"] = nul_audit_counts
        metric_status["nul_audit_preservation"] = "PASS" if nul_audit_equal else "DIVERGED"

        fk_ok, fk_result = _foreign_key_metric(source, target, snapshot, report, normalized_mappings, checkpoint)
        result["foreign_keys"] = fk_result
        metric_status["foreign_keys_orphans"] = "PASS" if fk_ok else "DIVERGED"

        identity_ok, identity_results = _identity_metric(target, tables_manifest, normalized_mappings, source_acc, target_acc)
        result["identity_sequences"] = identity_results
        metric_status["identity_sequences"] = "PASS" if identity_ok else "DIVERGED"
        result["metrics"] = metric_status
        blockers = nul_blockers
        if metric_status["nul_normalization"] == "BLOCKED":
            result["blockers"] = blockers or ["NUL_EVIDENCE_INCOMPLETE_OR_MISMATCHED"]
        statuses = set(metric_status.values())
        if "BLOCKED" in statuses:
            result["status"] = "BLOCKED"
        elif "DIVERGED" in statuses:
            result["status"] = "DIVERGED"
        elif all(value == "PASS" for value in metric_status.values()):
            result["status"] = "RECONCILED"
        else:
            result["status"] = "BLOCKED"
            result["failure_code"] = "METRIC_NOT_IMPLEMENTED"
    except ReconciliationError as error:
        result["status"] = "BLOCKED"
        result["failure_code"] = error.code
        if error.code == "METRIC_NOT_IMPLEMENTED":
            result["blockers"] = ["METRIC_NOT_IMPLEMENTED"]
    except Exception:
        # Fail closed without exception text, SQL, driver details, or values.
        result["status"] = "BLOCKED"
        result["failure_code"] = "RECONCILIATION_INTERNAL_ERROR"
    finally:
        if counter_store is not None:
            try:
                counter_store.close()
                result["temporary_store_cleanup"] = "PASS"
            except ReconciliationError as error:
                result["temporary_store_cleanup"] = "FAILED"
                result["status"] = "BLOCKED"
                result["failure_code"] = error.code
            except Exception:
                result["temporary_store_cleanup"] = "FAILED"
                result["status"] = "BLOCKED"
                result["failure_code"] = "RECONCILE_TEMP_CLEANUP_FAILED"
        else:
            result["temporary_store_cleanup"] = "NOT_CREATED"
        result["elapsed_seconds"] = round(max(0.0, _time.monotonic() - started), 6)
    return result


__all__ = ["ReconciliationError", "canonical_row_sha256", "reconcile"]
