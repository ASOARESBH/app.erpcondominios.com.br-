from __future__ import annotations

import argparse
from configparser import RawConfigParser
from datetime import date, datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tempfile
from typing import Any

from .generated_columns import declares_generated_column, identify_generated_column
from .type_mapping import BINARY_STORAGE_POLICY_ID, TypeMappingError, expected_binary_storage_encoding, parse_mysql_enum, quote_literal
from .check_translation import CheckTranslationError, translate_mysql_check_clause


SOURCE_SCHEMA = "erp_pg_source"
CATALOG_VERSION = 12
SOURCE_GRANT_POLICY_ID = "erp_pg_source_select_show_view_trigger_v2"
TIME_RANGE_POLICY_ID = "mysql_time_clock_time0_or_extended_interval_v3"
POSTGRES_SIGNED_BIGINT_MAX = 9223372036854775807
class CatalogError(RuntimeError):
    pass


def _collector_fingerprint() -> str:
    digest = hashlib.sha256()
    for name in ("catalog.py", "generated_columns.py", "check_translation.py", "type_mapping.py"):
        digest.update(name.encode("utf-8") + b"\0")
        digest.update(Path(__file__).with_name(name).read_bytes())
        digest.update(b"\0")
    return digest.hexdigest()


def _structural_fingerprint(snapshot: dict[str, Any]) -> str:
    structural_state = {
        key: value for key, value in snapshot.items()
        if key not in {"captured_at_utc", "catalog_consistency_check", "structural_fingerprint_sha256", "fingerprint_sha256"}
    }
    structural = json.dumps(structural_state, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(structural).hexdigest()


def _validate_defaults_file(path: Path) -> None:
    try:
        info = path.stat()
    except OSError as error:
        raise CatalogError("MYSQL_DEFAULTS_FILE_UNAVAILABLE") from error
    if not stat.S_ISREG(info.st_mode) or info.st_uid != 0:
        raise CatalogError("MYSQL_DEFAULTS_FILE_MUST_BE_ROOT_OWNED_REGULAR_FILE")
    if stat.S_IMODE(info.st_mode) not in {0o400, 0o600}:
        raise CatalogError("MYSQL_DEFAULTS_FILE_MODE_MUST_BE_0400_OR_0600")


def _mysql_socket_options(path: Path) -> dict[str, str]:
    options = RawConfigParser()
    try:
        loaded = options.read(path, encoding="utf-8")
    except OSError as error:
        raise CatalogError("MYSQL_DEFAULTS_FILE_UNREADABLE") from error
    if not loaded or not options.has_section("client"):
        raise CatalogError("MYSQL_CLIENT_GROUP_REQUIRED")
    if options.get("client", "user", fallback="").strip() != "pg_reader":
        raise CatalogError("MYSQL_ACCOUNT_MUST_BE_PG_READER")
    protocol = options.get("client", "protocol", fallback="").strip().lower()
    if protocol not in {"socket", "unix_socket"}:
        raise CatalogError("MYSQL_PROTOCOL_MUST_BE_SOCKET")
    socket_path = options.get("client", "socket", fallback="/run/mysqld/mysqld.sock").strip()
    if socket_path not in {"/run/mysqld/mysqld.sock", "/var/run/mysqld/mysqld.sock"}:
        raise CatalogError("MYSQL_SOCKET_PATH_NOT_ALLOWLISTED")
    return {"unix_socket": socket_path}


def _foreign_key_action_rules(
    table: str, name: str, rules_by_key: dict[tuple[str, str], list[dict[str, Any]]]
) -> tuple[str, str]:
    rows = rules_by_key.get((table, name), [])
    if len(rows) != 1:
        raise CatalogError("FOREIGN_KEY_RULES_MISSING_OR_AMBIGUOUS")
    update_rule = str(rows[0].get("update_rule", "")).upper()
    delete_rule = str(rows[0].get("delete_rule", "")).upper()
    allowed = {"NO ACTION", "RESTRICT", "CASCADE", "SET NULL", "SET DEFAULT"}
    if update_rule not in allowed or delete_rule not in allowed:
        raise CatalogError("FOREIGN_KEY_RULES_INVALID")
    return update_rule, delete_rule


def _safe_default(
    column: dict[str, Any],
    *,
    no_backslash_escapes: bool = False,
    time_has_extended_values: bool | None = None,
    time_has_fractional_seconds: bool = False,
) -> dict[str, str] | None:
    value = column.get("column_default")
    if value is None:
        return None
    raw = str(value)
    data_type = str(column["data_type"]).lower()
    upper = raw.upper().strip()
    if data_type in {"char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext", "enum"}:
        if "default_generated" in str(column.get("extra", "")).lower():
            return {"kind": "review", "code": "TEXT_EXPRESSION_DEFAULT_REVIEW_REQUIRED"}
        try:
            return {"kind": "sql", "expression": quote_literal(raw)}
        except TypeMappingError:
            return {"kind": "review", "code": "TEXT_LITERAL_DEFAULT_UNSUPPORTED"}
    if upper == "NULL":
        return {"kind": "sql", "expression": "NULL"}

    current = re.fullmatch(r"CURRENT_TIMESTAMP(?:\((\d+)\))?", upper)
    if current:
        precision = int(current.group(1) or 0)
        if precision:
            return {"kind": "review", "code": "FRACTIONAL_DEFAULT_PRECISION_REVIEW_REQUIRED"}
        if data_type == "timestamp":
            return {"kind": "sql", "expression": f"timezone({quote_literal('America/Sao_Paulo')}, statement_timestamp())"}
        if data_type == "datetime":
            return {"kind": "sql", "expression": "LOCALTIMESTAMP(0)"}
        return {"kind": "review", "code": "CURRENT_TIMESTAMP_DEFAULT_TYPE_REVIEW_REQUIRED"}

    if data_type in {"tinyint", "smallint", "mediumint", "int", "integer", "bigint", "decimal", "numeric", "float", "double", "real", "year"}:
        if re.fullmatch(r"[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?", raw.strip()):
            return {"kind": "sql", "expression": raw.strip()}
        return {"kind": "review", "code": "NON_NUMERIC_DEFAULT_REVIEW_REQUIRED"}

    if data_type == "date":
        if re.fullmatch(r"0000-00-00", raw):
            return {"kind": "sql", "expression": "NULL"}
        try:
            parsed_date = date.fromisoformat(raw)
        except ValueError:
            return {"kind": "review", "code": "STATIC_DATE_DEFAULT_REVIEW_REQUIRED"}
        return {"kind": "sql", "expression": f"DATE {quote_literal(parsed_date.isoformat())}"}
    if data_type == "datetime":
        if re.fullmatch(r"0000-00-00(?: 00:00:00(?:\.0+)?)?", raw):
            return {"kind": "sql", "expression": "NULL"}
        try:
            parsed_datetime = datetime.fromisoformat(raw.replace(" ", "T", 1))
        except ValueError:
            return {"kind": "review", "code": "STATIC_DATETIME_DEFAULT_REVIEW_REQUIRED"}
        if parsed_datetime.tzinfo is not None or parsed_datetime.microsecond:
            return {"kind": "review", "code": "STATIC_DATETIME_DEFAULT_REVIEW_REQUIRED"}
        return {"kind": "sql", "expression": f"TIMESTAMP {quote_literal(parsed_datetime.isoformat(sep=' ', timespec='seconds'))}"}
    if data_type == "time":
        if not isinstance(time_has_extended_values, bool):
            return {"kind": "review", "code": "TIME_DEFAULT_RANGE_SCAN_REQUIRED"}
        time_match = re.fullmatch(r"([+-]?\d{1,3}:\d{2}:\d{2})(?:\.(\d{1,6}))?", raw)
        if not time_match:
            return {"kind": "review", "code": "STATIC_TIME_DEFAULT_REVIEW_REQUIRED"}
        base_value, fraction = time_match.groups()
        hours, minutes, seconds = (int(part) for part in base_value.lstrip("+-").split(":"))
        if hours > 838 or minutes > 59 or seconds > 59:
            return {"kind": "review", "code": "STATIC_TIME_DEFAULT_REVIEW_REQUIRED"}
        canonical = base_value + (("." + fraction.ljust(6, "0")) if fraction else "")
        if time_has_extended_values:
            return {"kind": "sql", "expression": f"INTERVAL {quote_literal(canonical)}"}
        if _time_default_has_extended_range(raw):
            return {"kind": "review", "code": "STATIC_TIME_DEFAULT_OUTSIDE_TIME_OF_DAY_RANGE"}
        if time_has_fractional_seconds:
            return {"kind": "review", "code": "MYSQL_TIME_FRACTIONAL_PRECISION_REVIEW_REQUIRED"}
        clock_value = canonical.lstrip("+")
        if clock_value.startswith("-"):
            clock_value = clock_value[1:]
        return {"kind": "sql", "expression": f"TIME {quote_literal(clock_value)}"}
    if data_type == "timestamp":
        if re.fullmatch(r"0000-00-00(?: 00:00:00(?:\.0+)?)?", raw):
            return {"kind": "sql", "expression": "NULL"}
        try:
            parsed_timestamp = datetime.fromisoformat(raw.replace(" ", "T", 1))
        except ValueError:
            return {"kind": "review", "code": "STATIC_TEMPORAL_DEFAULT_REVIEW_REQUIRED"}
        if parsed_timestamp.tzinfo is not None or parsed_timestamp.microsecond:
            return {"kind": "review", "code": "STATIC_TEMPORAL_DEFAULT_REVIEW_REQUIRED"}
        canonical = parsed_timestamp.isoformat(sep=" ", timespec="seconds")
        return {"kind": "sql", "expression": f"TIMESTAMP {quote_literal(canonical)}"}

    # Function defaults not explicitly mapped above remain fail-closed.
    return {"kind": "review", "code": "UNSAFE_LITERAL_OR_FUNCTION_DEFAULT"}


def _rows(cursor: Any, sql: str, args: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
    cursor.execute(sql, args)
    return [{str(key).lower(): value for key, value in row.items()} for row in cursor.fetchall()]


def _mysql_identifier(identifier: str) -> str:
    if not isinstance(identifier, str) or not identifier or "\x00" in identifier:
        raise CatalogError("MYSQL_IDENTIFIER_INVALID")
    return "`" + identifier.replace("`", "``") + "`"


def _unsigned_bigint_range_scan(cursor: Any, table: str, columns: list[str]) -> dict[str, bool]:
    if not columns:
        return {}
    aliases = [f"exceeds_signed_limit_{index}" for index in range(len(columns))]
    projections = [
        f"COALESCE(MAX({_mysql_identifier(column)}) > %s, 0) AS {_mysql_identifier(alias)}"
        for column, alias in zip(columns, aliases)
    ]
    query = (
        f"SELECT {', '.join(projections)} FROM "
        f"{_mysql_identifier(SOURCE_SCHEMA)}.{_mysql_identifier(table)}"
    )
    try:
        cursor.execute(query, tuple(POSTGRES_SIGNED_BIGINT_MAX for _ in columns))
        row = cursor.fetchone()
    except Exception as error:
        raise CatalogError("UNSIGNED_BIGINT_RANGE_SCAN_FAILED") from error
    if not isinstance(row, dict):
        raise CatalogError("UNSIGNED_BIGINT_RANGE_SCAN_RESULT_INVALID")
    result: dict[str, bool] = {}
    for column, alias in zip(columns, aliases):
        value = next((item for key, item in row.items() if str(key).lower() == alias), None)
        if isinstance(value, bool):
            result[column] = value
        elif value in (0, 1):
            result[column] = bool(value)
        else:
            raise CatalogError("UNSIGNED_BIGINT_RANGE_SCAN_RESULT_INVALID")
    return result


def _time_range_scan(cursor: Any, table: str, columns: list[str]) -> dict[str, dict[str, bool]]:
    if not columns:
        return {}
    aliases = [(f"has_extended_time_{index}", f"has_fractional_time_{index}") for index in range(len(columns))]
    projections: list[str] = []
    for column, (extended_alias, fractional_alias) in zip(columns, aliases):
        identifier = _mysql_identifier(column)
        projections.extend((
            "COALESCE((MIN(TIME_TO_SEC({column})) < 0 OR "
            "MAX(TIME_TO_SEC({column})) > 86399), 0) AS {alias}".format(
                column=identifier, alias=_mysql_identifier(extended_alias)
            ),
            "COALESCE(MAX(CASE WHEN {column} IS NOT NULL AND "
            "MOD(TIME_TO_SEC({column}), 1) <> 0 THEN 1 ELSE 0 END), 0) AS {alias}".format(
                column=identifier, alias=_mysql_identifier(fractional_alias)
            ),
        ))
    query = (
        f"SELECT {', '.join(projections)} FROM "
        f"{_mysql_identifier(SOURCE_SCHEMA)}.{_mysql_identifier(table)}"
    )
    try:
        cursor.execute(query)
        row = cursor.fetchone()
    except Exception as error:
        raise CatalogError("TIME_RANGE_SCAN_FAILED") from error
    if not isinstance(row, dict):
        raise CatalogError("TIME_RANGE_SCAN_RESULT_INVALID")
    result: dict[str, dict[str, bool]] = {}
    for column, (extended_alias, fractional_alias) in zip(columns, aliases):
        values: dict[str, bool] = {}
        for key_name, alias in (("extended", extended_alias), ("fractional", fractional_alias)):
            value = next((item for key, item in row.items() if str(key).lower() == alias), None)
            if isinstance(value, bool):
                values[key_name] = value
            elif value in (0, 1):
                values[key_name] = bool(value)
            else:
                raise CatalogError("TIME_RANGE_SCAN_RESULT_INVALID")
        result[column] = values
    return result


def _time_default_has_extended_range(value: object, extra: object = "") -> bool:
    if value is None or "default_generated" in str(extra).lower():
        return False
    match = re.fullmatch(r"([+-]?)(\d{1,3}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?", str(value))
    if not match:
        return False
    sign, hours, minutes, seconds, fraction = match.groups()
    microseconds = int((fraction or "").ljust(6, "0"))
    total = ((int(hours) * 60 + int(minutes)) * 60 + int(seconds)) * 1_000_000 + microseconds
    if sign == "-":
        total = -total
    return total < 0 or total > 86399 * 1_000_000


def _time_default_has_fractional_seconds(value: object, extra: object = "") -> bool:
    if value is None or "default_generated" in str(extra).lower():
        return False
    match = re.fullmatch(r"[+-]?\d{1,3}:\d{2}:\d{2}(?:\.(\d{1,6}))?", str(value))
    return bool(match and match.group(1) and any(digit != "0" for digit in match.group(1)))


def _index_columns(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    grouped: dict[tuple[str, str], dict[str, Any]] = {}
    for row in rows:
        key = (str(row["table_name"]), str(row["index_name"]))
        index = grouped.setdefault(key, {
            "name": key[1],
            "non_unique": bool(row["non_unique"]),
            "type": str(row["index_type"]).upper(),
            "visible": str(row.get("is_visible", "YES")).upper() == "YES",
            "columns": [],
        })
        expression = row.get("expression")
        index["columns"].append({
            "ordinal": int(row["seq_in_index"]),
            "name": row.get("column_name"),
            "prefix_length": int(row["sub_part"]) if row.get("sub_part") is not None else None,
            "direction": "DESC" if str(row.get("collation") or "A").upper() == "D" else "ASC",
            "expression_present": bool(expression),
        })
    result: list[dict[str, Any]] = []
    for (table, _), index in sorted(grouped.items()):
        index["columns"].sort(key=lambda item: item["ordinal"])
        index["table"] = table
        result.append(index)
    return result


def _source_grant_inventory(cursor: Any, schema: str) -> tuple[list[dict[str, Any]], dict[str, bool], bool]:
    cursor.execute("SHOW GRANTS")
    grant_rows = cursor.fetchall()
    inventory: list[dict[str, Any]] = []
    full_scope_privileges: set[str] = set()
    unresolved_role_grants = False
    for row in grant_rows:
        for value in row.values():
            line = str(value).strip()
            if re.search(r"\bWITH\s+GRANT\s+OPTION\b", line, re.IGNORECASE):
                unresolved_role_grants = True
                continue
            match = re.match(r"^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+.+$", line, re.IGNORECASE)
            if not match:
                unresolved_role_grants = True
                continue
            scope_text = re.sub(r"[`\"']", "", match.group(2)).strip()
            if scope_text == "*.*":
                scope = "GLOBAL"
            elif scope_text.lower() == (schema + ".*").lower():
                scope = "SCHEMA"
            elif scope_text.lower().startswith((schema + ".").lower()):
                scope = "SCHEMA_OBJECTS"
            else:
                scope = "OTHER"
                unresolved_role_grants = True
            privileges = sorted({part.strip().upper() for part in match.group(1).split(",") if part.strip()})
            # MySQL emits implicit global USAGE for an account with no global privileges.
            # It is not an effective privilege and is omitted; any other global grant is rejected below.
            if not (scope == "GLOBAL" and privileges == ["USAGE"]):
                inventory.append({"scope": scope, "privileges": privileges})
            if scope in {"GLOBAL", "SCHEMA"}:
                full_scope_privileges.update(privileges)
    inventory = sorted({json.dumps(item, sort_keys=True): item for item in inventory}.values(), key=lambda item: item["scope"])
    all_privileges = "ALL PRIVILEGES" in full_scope_privileges
    visibility = {
        "views": not unresolved_role_grants and (all_privileges or "SHOW VIEW" in full_scope_privileges),
        "triggers": not unresolved_role_grants and (all_privileges or "TRIGGER" in full_scope_privileges),
        "routines": not unresolved_role_grants and (all_privileges or "SHOW ROUTINE" in full_scope_privileges),
        "events": not unresolved_role_grants and (all_privileges or "EVENT" in full_scope_privileges),
    }
    return inventory, visibility, unresolved_role_grants


def _grants_match_source_clone_allowlist(inventory: list[dict[str, Any]]) -> bool:
    privileges_by_scope: dict[str, set[str]] = {}
    for item in inventory:
        scope = item.get("scope")
        privileges = item.get("privileges")
        if scope not in {"GLOBAL", "SCHEMA"} or not isinstance(privileges, list):
            return False
        privileges_by_scope.setdefault(scope, set()).update(str(value).upper() for value in privileges)
    return privileges_by_scope == {"SCHEMA": {"SELECT", "SHOW VIEW", "TRIGGER"}}


def _has_schema_wide_select(inventory: list[dict[str, Any]]) -> bool:
    return any(item["scope"] == "SCHEMA" and "SELECT" in item["privileges"] for item in inventory)


def _admin_verifies_source_has_no_routines_or_events() -> bool:
    if os.geteuid() != 0:
        return False
    query = (
        "SELECT (SELECT COUNT(*) FROM information_schema.routines "
        "WHERE routine_schema='erp_pg_source'), "
        "(SELECT COUNT(*) FROM information_schema.events "
        "WHERE event_schema='erp_pg_source')"
    )
    try:
        result = subprocess.run(
            ["/usr/bin/mysql", "--no-defaults", "--protocol=socket", "--user=root",
             "--batch", "--skip-column-names", "--execute", query],
            check=False,
            capture_output=True,
            text=True,
            timeout=10,
        )
    except (OSError, subprocess.TimeoutExpired):
        return False
    return result.returncode == 0 and result.stdout.strip() == "0\t0"


def _catalog_signature(cursor: Any, schema: str, visibility: dict[str, bool]) -> str:
    queries = [
        ("tables", """
            SELECT table_name, table_type, engine, table_collation, auto_increment
            FROM information_schema.tables WHERE table_schema=%s ORDER BY table_name
        """),
        ("columns", """
            SELECT table_name, column_name, ordinal_position, data_type, column_type,
                   is_nullable, extra, character_set_name, collation_name,
                   generation_expression, column_default
            FROM information_schema.columns WHERE table_schema=%s ORDER BY table_name, ordinal_position
        """),
        ("indexes", """
            SELECT table_name, index_name, non_unique, seq_in_index, column_name,
                   collation, sub_part, index_type, is_visible, expression
            FROM information_schema.statistics WHERE table_schema=%s ORDER BY table_name, index_name, seq_in_index
        """),
        ("keys", """
            SELECT table_name, constraint_name, ordinal_position, column_name,
                   referenced_table_schema, referenced_table_name, referenced_column_name
            FROM information_schema.key_column_usage WHERE table_schema=%s ORDER BY table_name, constraint_name, ordinal_position
        """),
        ("referential_rules", """
            SELECT table_name, constraint_name, update_rule, delete_rule
            FROM information_schema.referential_constraints
            WHERE constraint_schema=%s ORDER BY table_name, constraint_name
        """),
        ("checks", """
            SELECT tc.table_name, tc.constraint_name, cc.check_clause, tc.enforced
            FROM information_schema.table_constraints tc
            JOIN information_schema.check_constraints cc
              ON cc.constraint_schema=tc.constraint_schema AND cc.constraint_name=tc.constraint_name
            WHERE tc.table_schema=%s AND tc.constraint_type='CHECK' ORDER BY tc.table_name, tc.constraint_name
        """),
        ("triggers", """
            SELECT trigger_name, event_manipulation, event_object_table, action_timing, action_statement
            FROM information_schema.triggers WHERE trigger_schema=%s ORDER BY event_object_table, trigger_name
        """),
        ("views", """
            SELECT table_name, view_definition FROM information_schema.views
            WHERE table_schema=%s ORDER BY table_name
        """),
        ("routines", """
            SELECT routine_name, routine_type, dtd_identifier, sql_data_access,
                   security_type, routine_definition FROM information_schema.routines
            WHERE routine_schema=%s ORDER BY routine_name
        """),
        ("events", """
            SELECT event_name, status, event_type, interval_value, interval_field,
                   time_zone, event_definition FROM information_schema.events
            WHERE event_schema=%s ORDER BY event_name
        """),
    ]
    state: dict[str, list[dict[str, Any]]] = {}
    for name, sql in queries:
        if name in visibility and not visibility[name]:
            continue
        rows = _rows(cursor, sql, (schema,))
        safe_rows: list[dict[str, Any]] = []
        for row in rows:
            safe: dict[str, Any] = {}
            for key, value in row.items():
                if key in {"column_default", "check_clause", "action_statement", "view_definition", "routine_definition", "event_definition", "interval_value", "generation_expression", "expression"}:
                    safe[key + "_sha256"] = hashlib.sha256(str(value).encode("utf-8")).hexdigest() if value is not None else None
                else:
                    safe[key] = value
            safe_rows.append(safe)
        state[name] = safe_rows
    raw = json.dumps(state, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


def collect_catalog(connection: Any, *, schema: str = SOURCE_SCHEMA, keep_snapshot: bool = False) -> dict[str, Any]:
    if schema != SOURCE_SCHEMA:
        raise CatalogError("SOURCE_SCHEMA_NOT_ALLOWLISTED")

    cursor = connection.cursor()
    cursor.execute("SELECT CURRENT_USER() AS connected_account, DATABASE() AS selected_database, VERSION() AS server_version")
    identity_row = cursor.fetchone() or {}
    identity = {str(key).lower(): value for key, value in identity_row.items()}
    if str(identity["connected_account"]).split("@", 1)[0] != "pg_reader":
        cursor.close()
        raise CatalogError("MYSQL_ACCOUNT_MUST_BE_PG_READER")
    if identity["selected_database"] != SOURCE_SCHEMA:
        cursor.close()
        raise CatalogError("MYSQL_DATABASE_MUST_BE_ERP_PG_SOURCE")

    cursor.execute("SET SESSION time_zone = '+00:00'")
    cursor.execute("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY")
    completed = False
    try:
        grant_inventory, metadata_visibility, unresolved_role_grants = _source_grant_inventory(cursor, SOURCE_SCHEMA)
        if unresolved_role_grants:
            raise CatalogError("SOURCE_ROLE_GRANTS_UNRESOLVED")
        if not _grants_match_source_clone_allowlist(grant_inventory):
            raise CatalogError("SOURCE_ACCOUNT_GRANTS_NOT_ALLOWLISTED")
        if not _has_schema_wide_select(grant_inventory):
            raise CatalogError("SOURCE_SCHEMA_WIDE_SELECT_REQUIRED")
        routines_events_empty_before = _admin_verifies_source_has_no_routines_or_events()
        signature_before = _catalog_signature(cursor, SOURCE_SCHEMA, metadata_visibility)
        cursor.execute("SELECT @@SESSION.sql_mode AS session_sql_mode, @@SESSION.time_zone AS session_time_zone, @@SYSTEM_TIME_ZONE AS system_time_zone")
        session_row = cursor.fetchone() or {}
        sql_mode_row = {str(key).lower(): value for key, value in session_row.items()}
        no_backslash_escapes = "NO_BACKSLASH_ESCAPES" in str(sql_mode_row.get("session_sql_mode") or "").upper().split(",")
        tables = _rows(cursor, """
            SELECT table_name, table_type, engine, table_collation, auto_increment
            FROM information_schema.tables
            WHERE table_schema=%s
            ORDER BY table_name
        """, (SOURCE_SCHEMA,))
        raw_columns = _rows(cursor, """
            SELECT table_name, column_name, ordinal_position, column_default, is_nullable,
                   data_type, column_type, character_set_name, collation_name, extra,
                   generation_expression
            FROM information_schema.columns
            WHERE table_schema=%s
            ORDER BY table_name, ordinal_position
        """, (SOURCE_SCHEMA,))
        base_tables = {
            str(item["table_name"])
            for item in tables
            if str(item.get("table_type", "")).upper() == "BASE TABLE"
        }
        unsigned_bigint_columns: dict[str, list[str]] = {}
        time_columns: dict[str, list[str]] = {}
        for row in raw_columns:
            column_type = str(row.get("column_type", ""))
            table_name = str(row.get("table_name", ""))
            data_type = str(row.get("data_type", "")).lower()
            if table_name in base_tables and data_type == "bigint" and re.search(
                r"\b(?:unsigned|zerofill)\b", column_type, re.IGNORECASE
            ):
                unsigned_bigint_columns.setdefault(table_name, []).append(str(row["column_name"]))
            if table_name in base_tables and data_type == "time":
                time_columns.setdefault(table_name, []).append(str(row["column_name"]))
        unsigned_bigint_ranges = {
            table: _unsigned_bigint_range_scan(cursor, table, columns)
            for table, columns in sorted(unsigned_bigint_columns.items())
        }
        time_ranges = {
            table: _time_range_scan(cursor, table, columns)
            for table, columns in sorted(time_columns.items())
        }
        columns: dict[str, list[dict[str, Any]]] = {}
        review_items: list[dict[str, str]] = []
        for row in raw_columns:
            table = str(row["table_name"])
            column = {
                "name": str(row["column_name"]),
                "ordinal": int(row["ordinal_position"]),
                "nullable": str(row["is_nullable"]).upper() == "YES",
                "data_type": str(row["data_type"]).lower(),
                "column_type": str(row["column_type"]),
                "character_set": row.get("character_set_name"),
                "source_collation": row.get("collation_name"),
                "extra": str(row.get("extra") or ""),
            }
            binary_encoding = expected_binary_storage_encoding(table, column["name"])
            if binary_encoding is not None:
                if column["data_type"] != "longblob":
                    raise CatalogError("BINARY_STORAGE_SOURCE_TYPE_MISMATCH")
                column["binary_storage_encoding"] = binary_encoding
            if str(row.get("data_type", "")).lower() == "bigint" and column["name"] in unsigned_bigint_ranges.get(table, {}):
                column["unsigned_bigint_exceeds_signed_limit"] = unsigned_bigint_ranges[table][column["name"]]
            if str(row.get("data_type", "")).lower() == "time" and table in base_tables:
                if column["name"] not in time_ranges.get(table, {}):
                    raise CatalogError("TIME_RANGE_SCAN_RESULT_INVALID")
                range_metrics = time_ranges[table][column["name"]]
                time_extended = range_metrics["extended"]
                time_fractional = range_metrics["fractional"]
                time_extended_default = _time_default_has_extended_range(
                    row.get("column_default"), row.get("extra")
                )
                time_fractional_default = _time_default_has_fractional_seconds(
                    row.get("column_default"), row.get("extra")
                )
                column["time_has_extended_values"] = time_extended
                column["time_has_fractional_seconds"] = time_fractional
                column["time_default_has_extended_value"] = time_extended_default
                column["time_default_has_fractional_seconds"] = time_fractional_default
                time_uses_interval = time_extended or time_extended_default
                column["time_uses_interval"] = time_uses_interval
                if (time_fractional or time_fractional_default) and not time_uses_interval:
                    review = {"table": table, "column": column["name"], "code": "MYSQL_TIME_FRACTIONAL_PRECISION_REVIEW_REQUIRED"}
                    if review not in review_items:
                        review_items.append(review)
            else:
                time_uses_interval = None
            default = _safe_default(
                row,
                no_backslash_escapes=no_backslash_escapes,
                time_has_extended_values=time_uses_interval,
                time_has_fractional_seconds=(
                    _time_default_has_fractional_seconds(row.get("column_default"), row.get("extra"))
                    if str(row.get("data_type", "")).lower() == "time" else False
                ),
            )
            if default is not None:
                column["default"] = default
                if default["kind"] == "review" and {"table": table, "column": column["name"], "code": default["code"]} not in review_items:
                    review_items.append({"table": table, "column": column["name"], "code": default["code"]})
            expression = str(row.get("generation_expression") or "")
            if expression or declares_generated_column(column):
                column["generated_expression_present"] = True
                column["generated_expression_sha256"] = hashlib.sha256(expression.encode("utf-8")).hexdigest()
                if not expression:
                    review_items.append({"table": table, "column": column["name"], "code": "GENERATED_COLUMN_EXPRESSION_MISSING"})
                else:
                    generated_spec = identify_generated_column(
                        table,
                        column["name"],
                        column["data_type"],
                        column["column_type"],
                        column["extra"],
                        expression,
                    )
                    if generated_spec is None:
                        review_items.append({"table": table, "column": column["name"], "code": "GENERATED_COLUMN_REVIEW_REQUIRED"})
                    else:
                        column["generated_expression_key"] = generated_spec["key"]
            columns.setdefault(table, []).append(column)

        index_rows = _rows(cursor, """
            SELECT table_name, index_name, non_unique, seq_in_index, column_name,
                   collation, sub_part, index_type, is_visible, expression
            FROM information_schema.statistics
            WHERE table_schema=%s
            ORDER BY table_name, index_name, seq_in_index
        """, (SOURCE_SCHEMA,))
        indexes = _index_columns(index_rows)

        key_rows = _rows(cursor, """
            SELECT table_name, constraint_name, ordinal_position, column_name,
                   referenced_table_schema, referenced_table_name, referenced_column_name
            FROM information_schema.key_column_usage
            WHERE table_schema=%s
            ORDER BY table_name, constraint_name, ordinal_position
        """, (SOURCE_SCHEMA,))
        constraints = _rows(cursor, """
            SELECT table_name, constraint_name, constraint_type
            FROM information_schema.table_constraints
            WHERE table_schema=%s
            ORDER BY table_name, constraint_name
        """, (SOURCE_SCHEMA,))
        referential = _rows(cursor, """
            SELECT constraint_name, table_name, update_rule, delete_rule
            FROM information_schema.referential_constraints
            WHERE constraint_schema=%s
            ORDER BY table_name, constraint_name
        """, (SOURCE_SCHEMA,))
        fk_rules: dict[tuple[str, str], list[dict[str, Any]]] = {}
        for rule in referential:
            key = (str(rule["table_name"]), str(rule["constraint_name"]))
            fk_rules.setdefault(key, []).append(rule)
        fks: list[dict[str, Any]] = []
        pk_map: dict[str, list[dict[str, Any]]] = {}
        fk_groups: dict[tuple[str, str], list[dict[str, Any]]] = {}
        for row in key_rows:
            if str(row["constraint_name"]).upper() == "PRIMARY":
                pk_map.setdefault(str(row["table_name"]), []).append({
                    "ordinal": int(row["ordinal_position"]), "column": str(row["column_name"])
                })
            if row.get("referenced_table_name"):
                fk_groups.setdefault((str(row["table_name"]), str(row["constraint_name"])), []).append(row)
        for (table, name), rows in sorted(fk_groups.items()):
            rows.sort(key=lambda item: int(item["ordinal_position"]))
            update_rule, delete_rule = _foreign_key_action_rules(table, name, fk_rules)
            fks.append({
                "table": table,
                "name": name,
                "columns": [str(row["column_name"]) for row in rows],
                "referenced_schema": str(rows[0]["referenced_table_schema"]),
                "referenced_table": str(rows[0]["referenced_table_name"]),
                "referenced_columns": [str(row["referenced_column_name"]) for row in rows],
                "update_rule": update_rule,
                "delete_rule": delete_rule,
            })

        checks = _rows(cursor, """
            SELECT tc.table_name, tc.constraint_name, cc.check_clause, tc.enforced
            FROM information_schema.table_constraints tc
            JOIN information_schema.check_constraints cc
              ON cc.constraint_schema=tc.constraint_schema
             AND cc.constraint_name=tc.constraint_name
            WHERE tc.table_schema=%s AND tc.constraint_type='CHECK'
            ORDER BY tc.table_name, tc.constraint_name
        """, (SOURCE_SCHEMA,))
        check_docs: list[dict[str, Any]] = []
        for row in checks:
            table_name = str(row["table_name"])
            constraint_name = str(row["constraint_name"])
            definition_sha256 = hashlib.sha256(str(row["check_clause"]).encode("utf-8")).hexdigest()
            if str(row.get("enforced", "YES")).upper() != "YES":
                review_items.append({"table": table_name, "constraint": constraint_name, "code": "MYSQL_CHECK_NOT_ENFORCED_REVIEW_REQUIRED"})
                check_docs.append({"table": table_name, "name": constraint_name, "enforced": False, "definition_sha256": definition_sha256})
                continue
            column_metadata = {str(column["name"]): column for column in columns.get(table_name, [])}
            try:
                translation = translate_mysql_check_clause(
                    str(row["check_clause"]),
                    columns=column_metadata,
                    no_backslash_escapes=no_backslash_escapes,
                )
            except CheckTranslationError:
                review_items.append({"table": table_name, "constraint": constraint_name, "code": "MYSQL_CHECK_EXPRESSION_TRANSLATION_REQUIRED"})
                check_docs.append({"table": table_name, "name": constraint_name, "enforced": True, "definition_sha256": definition_sha256})
                continue
            check_docs.append({"table": table_name, "name": constraint_name, "enforced": True, "definition_sha256": definition_sha256, "translation": translation})

        trigger_rows = _rows(cursor, """
            SELECT trigger_name, event_manipulation, event_object_table, action_timing,
                   action_statement
            FROM information_schema.triggers
            WHERE trigger_schema=%s
            ORDER BY event_object_table, trigger_name
        """, (SOURCE_SCHEMA,)) if metadata_visibility["triggers"] else []
        triggers = [{
            "name": str(row["trigger_name"]),
            "event": str(row["event_manipulation"]).upper(),
            "table": str(row["event_object_table"]),
            "timing": str(row["action_timing"]).upper(),
            "body_sha256": hashlib.sha256(str(row["action_statement"]).encode("utf-8")).hexdigest(),
        } for row in trigger_rows]

        view_rows = _rows(cursor, """
            SELECT table_name, view_definition
            FROM information_schema.views
            WHERE table_schema=%s
            ORDER BY table_name
        """, (SOURCE_SCHEMA,)) if metadata_visibility["views"] else []
        views = [{
            "name": str(row["table_name"]),
            "definition_sha256": hashlib.sha256(str(row.get("view_definition") or "").encode("utf-8")).hexdigest(),
        } for row in view_rows]

        routine_rows = _rows(cursor, """
            SELECT routine_name, routine_type, dtd_identifier, sql_data_access,
                   security_type, routine_definition
            FROM information_schema.routines
            WHERE routine_schema=%s
            ORDER BY routine_name
        """, (SOURCE_SCHEMA,)) if metadata_visibility["routines"] else []
        routines = [{
            "name": str(row["routine_name"]),
            "type": str(row["routine_type"]).upper(),
            "return_or_parameters": str(row.get("dtd_identifier") or ""),
            "sql_data_access": str(row.get("sql_data_access") or ""),
            "security_type": str(row.get("security_type") or ""),
            "body_sha256": hashlib.sha256(str(row.get("routine_definition") or "").encode("utf-8")).hexdigest(),
        } for row in routine_rows]

        event_rows = _rows(cursor, """
            SELECT event_name, status, event_type, interval_value, interval_field,
                   time_zone, event_definition
            FROM information_schema.events
            WHERE event_schema=%s
            ORDER BY event_name
        """, (SOURCE_SCHEMA,)) if metadata_visibility["events"] else []
        events = [{
            "name": str(row["event_name"]),
            "status": str(row["status"]).upper(),
            "event_type": str(row["event_type"]).upper(),
            "interval_value_present": row.get("interval_value") is not None,
            "interval_field": str(row.get("interval_field") or ""),
            "time_zone": str(row.get("time_zone") or ""),
            "body_sha256": hashlib.sha256(str(row.get("event_definition") or "").encode("utf-8")).hexdigest(),
        } for row in event_rows]

        signature_after = _catalog_signature(cursor, SOURCE_SCHEMA, metadata_visibility)
        grants_after, visibility_after, unresolved_after = _source_grant_inventory(cursor, SOURCE_SCHEMA)
        routines_events_empty_after = _admin_verifies_source_has_no_routines_or_events()
        if routines_events_empty_before != routines_events_empty_after:
            raise CatalogError("SOURCE_ROUTINES_EVENTS_CHANGED_DURING_CAPTURE")
        if signature_before != signature_after or grant_inventory != grants_after or metadata_visibility != visibility_after or unresolved_role_grants != unresolved_after:
            raise CatalogError("MYSQL_CATALOG_CHANGED_DURING_CAPTURE")

        table_docs = []
        for row in tables:
            name = str(row["table_name"])
            table_docs.append({
                "name": name,
                "type": str(row["table_type"]).upper(),
                "engine": row.get("engine"),
                "source_collation": row.get("table_collation"),
                "source_next_auto_increment": int(row["auto_increment"]) if row.get("auto_increment") is not None else None,
                "columns": columns.get(name, []),
                "primary_key": sorted(pk_map.get(name, []), key=lambda item: item["ordinal"]),
            })
        snapshot: dict[str, Any] = {
            "catalog_version": CATALOG_VERSION,
            "binary_storage_policy_id": BINARY_STORAGE_POLICY_ID,
            "binary_storage_required": True,
            "time_range_policy_id": TIME_RANGE_POLICY_ID,
            "collector_code_sha256": _collector_fingerprint(),
            "source_schema": SOURCE_SCHEMA,
            "source_server_version": str(identity["server_version"]).split("-", 1)[0],
            "source_no_backslash_escapes": no_backslash_escapes,
            "catalog_connection_time_zone": str(sql_mode_row.get("session_time_zone") or ""),
            "server_system_time_zone": str(sql_mode_row.get("system_time_zone") or ""),
            "timestamp_transfer_contract": {"source_session": "UTC", "target_wall_time": "America/Sao_Paulo"},
            "catalog_consistency_check": "PRE_POST_SIGNATURE_MATCH",
            "metadata_visibility": metadata_visibility,
            "source_routines_events_zero_verified": routines_events_empty_before and routines_events_empty_after,
            "grants": grant_inventory,
            "source_grant_policy_id": SOURCE_GRANT_POLICY_ID,
            "source_grants_policy_accepted": _grants_match_source_clone_allowlist(grant_inventory),
            "source_schema_wide_select": _has_schema_wide_select(grant_inventory),
            "unresolved_role_grants": unresolved_role_grants,
            "captured_at_utc": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "tables": table_docs,
            "indexes": indexes,
            "foreign_keys": fks,
            "check_constraints": check_docs,
            "triggers": triggers,
            "views": views,
            "routines": routines,
            "events": events,
            "review_items": sorted(review_items, key=lambda item: (
                str(item.get("table", "")),
                str(item.get("column", "")),
                str(item.get("constraint", "")),
                str(item.get("code", "")),
            )),
        }
        snapshot["structural_fingerprint_sha256"] = _structural_fingerprint(snapshot)
        canonical = json.dumps(snapshot, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        snapshot["fingerprint_sha256"] = hashlib.sha256(canonical).hexdigest()
        completed = True
        return snapshot
    finally:
        try:
            if not keep_snapshot or not completed:
                connection.rollback()
        finally:
            cursor.close()


def _atomic_json(path: Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    raw = json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    fd, temp_name = tempfile.mkstemp(prefix="." + path.name + ".", dir=path.parent)
    temp = Path(temp_name)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        os.link(temp, path)
    except FileExistsError as error:
        raise CatalogError("CATALOG_OUTPUT_ALREADY_EXISTS") from error
    finally:
        temp.unlink(missing_ok=True)


def main() -> int:
    parser = argparse.ArgumentParser(description="Exporta catálogo sanitizado de erp_pg_source")
    parser.add_argument("--defaults-file", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    _validate_defaults_file(args.defaults_file)
    try:
        import pymysql
    except ImportError:
        raise SystemExit("PYMYSQL_REQUIRED")
    try:
        connection = pymysql.connect(
            read_default_file=str(args.defaults_file),
            read_default_group="client",
            database=SOURCE_SCHEMA,
            charset="utf8mb4",
            autocommit=True,
            cursorclass=pymysql.cursors.DictCursor,
            connect_timeout=10,
            read_timeout=60,
            write_timeout=10,
            **_mysql_socket_options(args.defaults_file),
        )
    except Exception:
        raise SystemExit("MYSQL_SOURCE_CONNECTION_FAILED")
    try:
        snapshot = collect_catalog(connection)
    except CatalogError as error:
        raise SystemExit(str(error))
    finally:
        connection.close()
    _atomic_json(args.output, snapshot)
    summary = {
        "catalog_version": snapshot["catalog_version"],
        "collector_code_sha256": snapshot["collector_code_sha256"],
        "fingerprint_sha256": snapshot["fingerprint_sha256"],
        "structural_fingerprint_sha256": snapshot["structural_fingerprint_sha256"],
        "tables": sum(item["type"] == "BASE TABLE" for item in snapshot["tables"]),
        "views": len(snapshot["views"]) if snapshot["metadata_visibility"]["views"] else None,
        "indexes": len(snapshot["indexes"]),
        "foreign_keys": len(snapshot["foreign_keys"]),
        "triggers": len(snapshot["triggers"]) if snapshot["metadata_visibility"]["triggers"] else None,
        "routines": len(snapshot["routines"]) if snapshot["metadata_visibility"]["routines"] else None,
        "events": len(snapshot["events"]) if snapshot["metadata_visibility"]["events"] else None,
        "metadata_visibility": snapshot["metadata_visibility"],
        "grant_scopes": len(snapshot["grants"]),
        "source_grant_policy_id": snapshot["source_grant_policy_id"],
        "source_grants_policy_accepted": snapshot["source_grants_policy_accepted"],
        "source_routines_events_zero_verified": snapshot["source_routines_events_zero_verified"],
        "source_schema_wide_select": snapshot["source_schema_wide_select"],
        "review_items": len(snapshot["review_items"]),
    }
    print(json.dumps(summary, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
