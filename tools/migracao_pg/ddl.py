from __future__ import annotations

from dataclasses import dataclass
import hashlib
import json
import re
from collections import Counter
from typing import Any

from .type_mapping import TypeMappingError, map_mysql_type, parse_mysql_enum, quote_identifier, quote_literal

SOURCE_SCHEMA = "erp_pg_source"
TARGET_SCHEMA = "erp"
MAX_IDENTIFIER_BYTES = 63
GENERATOR_VERSION = "1.4.1"
AUTOINCREMENT_STATE_STORE = "_p2_autoincrement_state"
UNSIGNED_IDENTITY_FUNCTION = "_p2_next_unsigned_id"
POSTGRES_SEQUENCE_MAX = 9223372036854775807
UNSIGNED_BIGINT_OFFSET = 9223372036854775808
MYSQL_UNSIGNED_BIGINT_MAX = 18446744073709551615


class DdlGenerationError(RuntimeError):
    pass


@dataclass(frozen=True)
class DdlBundle:
    scripts: dict[str, str]
    report: dict[str, Any]


def _ident(value: str) -> str:
    if len(value.encode("utf-8")) > MAX_IDENTIFIER_BYTES:
        raise TypeMappingError("POSTGRES_IDENTIFIER_EXCEEDS_63_BYTES", value)
    return quote_identifier(value)


def _qualified(schema: str, name: str) -> str:
    return f"{_ident(schema)}.{_ident(name)}"


def _generated_name(prefix: str, *parts: str) -> str:
    value = prefix + "_" + "_".join(parts)
    raw = value.encode("utf-8")
    if len(raw) <= MAX_IDENTIFIER_BYTES:
        return value
    suffix = hashlib.sha256("\0".join((prefix, *parts)).encode("utf-8")).hexdigest()[:12]
    prefix = raw[: MAX_IDENTIFIER_BYTES - len(suffix) - 1].decode("utf-8", "ignore")
    return f"{prefix}_{suffix}"


def _textual(data_type: str) -> bool:
    return data_type.lower() in {"char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext", "enum"}


def _key_requires_collision_scan(table: dict[str, Any], columns: list[str], overrides: dict[str, str]) -> bool:
    known_ci = {"utf8mb3_unicode_ci", "utf8mb4_unicode_ci", "utf8mb4_0900_ai_ci"}
    column_by_name = {str(column["name"]): column for column in table.get("columns", [])}
    for name in columns:
        column = column_by_name.get(name)
        if not column or not _textual(str(column.get("data_type", ""))):
            continue
        key = f"{table['name']}.{name}"
        if overrides.get(key) == "ci":
            return True
        if key not in overrides and str(column.get("source_collation") or "").lower() in known_ci:
            if not re.search(r"(?:^|_)(?:token|hash|senha|password|signature|assinatura|payload|path|caminho)(?:_|$)", name, re.IGNORECASE):
                return True
    return False


def _target_collation(column: dict[str, Any], explicit: dict[str, str], table: str, report: dict[str, Any]) -> str | None:
    name = str(column["name"])
    key = f"{table}.{name}"
    if key in explicit:
        value = explicit[key]
        if value not in {"ci", "C"}:
            raise DdlGenerationError("COLLATION_OVERRIDE_INVALID")
        return value
    source = str(column.get("source_collation") or "").lower()
    known_accent_insensitive_ci = {
        "utf8mb3_unicode_ci",
        "utf8mb4_unicode_ci",
        "utf8mb4_0900_ai_ci",
    }
    if source in known_accent_insensitive_ci:
        if re.search(r"(?:^|_)(?:token|hash|senha|password|signature|assinatura|payload|path|caminho)(?:_|$)", name, re.IGNORECASE):
            report["review_items"].append({"table": table, "column": name, "code": "SENSITIVE_COLLATION_CONFIRMATION_REQUIRED", "candidate": "C"})
            return None
        return "ci"
    report["review_items"].append({"table": table, "column": name, "code": "SOURCE_COLLATION_SEMANTICS_REVIEW_REQUIRED"})
    return None


def _sql_default(column: dict[str, Any], table: str, report: dict[str, Any], *, no_backslash_escapes: bool = False) -> str | None:
    default = column.get("default")
    if default is None:
        return None
    if default.get("kind") == "sql":
        expression = str(default.get("expression", ""))
        data_type = str(column.get("data_type", "")).lower()
        if expression == "NULL":
            if not _textual(data_type) and column.get("nullable") is True:
                return expression
            report["review_items"].append({"table": table, "column": str(column["name"]), "code": "DEFAULT_NULL_SEMANTICS_REVIEW_REQUIRED"})
            return None
        if data_type in {"tinyint", "smallint", "mediumint", "int", "integer", "bigint", "decimal", "numeric", "float", "double", "real", "year"}:
            if re.fullmatch(r"[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?", expression):
                return expression
        elif data_type in {"char", "varchar", "tinytext", "text", "mediumtext", "longtext"} and expression == "''":
            return expression
        elif data_type in {"datetime", "timestamp"}:
            expected = "timezone('America/Sao_Paulo', statement_timestamp())" if data_type == "timestamp" else "LOCALTIMESTAMP(0)"
            if expression == expected:
                return expression
        elif data_type == "enum":
            try:
                labels = parse_mysql_enum(str(column.get("column_type", "")), no_backslash_escapes=no_backslash_escapes)
                if any(expression == quote_literal(label) for label in labels):
                    return expression
            except TypeMappingError:
                pass
        report["review_items"].append({"table": table, "column": str(column["name"]), "code": "DEFAULT_EXPRESSION_NOT_ALLOWLISTED"})
        return None
    report["review_items"].append({"table": table, "column": str(column["name"]), "code": str(default.get("code", "DEFAULT_REVIEW_REQUIRED"))})
    return None


def _array_text(columns: list[str]) -> str:
    return "ARRAY[" + ", ".join(quote_literal(column) for column in columns) + "]::text[]"


def _identity_sequence_script(fingerprint: str, identity_columns: list[dict[str, Any]]) -> list[str]:
    statements = [f"-- Generator version: {GENERATOR_VERSION}", f"-- Catalog SHA-256: {fingerprint}"]
    for item in identity_columns:
        table = str(item["table"])
        column = str(item["column"])
        next_value = item.get("source_next_auto_increment")
        strategy = str(item.get("strategy", "identity"))
        maximum = MYSQL_UNSIGNED_BIGINT_MAX if strategy == "unsigned_sequence_pair" else POSTGRES_SEQUENCE_MAX
        if strategy not in {"identity", "unsigned_sequence_pair"}:
            raise DdlGenerationError("IDENTITY_STRATEGY_NOT_SUPPORTED")
        if next_value is not None and (isinstance(next_value, bool) or not isinstance(next_value, int) or next_value < 1 or next_value > maximum):
            raise DdlGenerationError("AUTO_INCREMENT_NEXT_VALUE_METADATA_INVALID")
        minimum_from_source = max(0, next_value - 1) if next_value is not None else 0
        sequence_key = quote_literal(f"{table}.{column}")
        if strategy == "unsigned_sequence_pair":
            statements.append(
                "DO $erp_numeric_identity$\n"
                "DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;\n"
                "BEGIN\n"
                f"  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM {_qualified(TARGET_SCHEMA, AUTOINCREMENT_STATE_STORE)} WHERE sequence_name = {sequence_key} FOR UPDATE;\n"
                "  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;\n"
                "  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;\n"
                f"  LOCK TABLE {_qualified(TARGET_SCHEMA, table)} IN ACCESS EXCLUSIVE MODE;\n"
                "  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;\n"
                f"  SELECT GREATEST(COALESCE(MAX({_ident(column)}), 0), {minimum_from_source}) INTO v_current FROM {_qualified(TARGET_SCHEMA, table)};\n"
                f"  IF v_current <= {POSTGRES_SEQUENCE_MAX} THEN\n"
                f"    IF v_current = 0 THEN PERFORM pg_catalog.setval(v_low_sequence, 1, false); ELSE PERFORM pg_catalog.setval(v_low_sequence, v_current::bigint, true); END IF;\n"
                "    PERFORM pg_catalog.setval(v_high_sequence, 0, false);\n"
                "  ELSE\n"
                f"    PERFORM pg_catalog.setval(v_low_sequence, {POSTGRES_SEQUENCE_MAX}, true);\n"
                f"    PERFORM pg_catalog.setval(v_high_sequence, (v_current - {UNSIGNED_BIGINT_OFFSET})::bigint, true);\n"
                "  END IF;\n"
                f"  UPDATE {_qualified(TARGET_SCHEMA, AUTOINCREMENT_STATE_STORE)} SET post_load_sync_complete = TRUE WHERE sequence_name = {sequence_key};\n"
                "END\n"
                "$erp_numeric_identity$;"
            )
        else:
            qualified_text = quote_literal(f'"{TARGET_SCHEMA}"."{table}"')
            column_text = quote_literal(column)
            statements.append(
                "DO $erp_sequence$\n"
                "DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;\n"
                "BEGIN\n"
                f"  SELECT post_load_sync_complete INTO v_synced FROM {_qualified(TARGET_SCHEMA, AUTOINCREMENT_STATE_STORE)} WHERE sequence_name = {sequence_key} FOR UPDATE;\n"
                "  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;\n"
                "  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;\n"
                f"  LOCK TABLE {_qualified(TARGET_SCHEMA, table)} IN ACCESS EXCLUSIVE MODE;\n"
                f"  SELECT pg_get_serial_sequence({qualified_text}, {column_text}) INTO v_sequence;\n"
                "  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;\n"
                "  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;\n"
                f"  SELECT MAX({_ident(column)}) INTO v_max FROM {_qualified(TARGET_SCHEMA, table)};\n"
                f"  v_next := GREATEST(COALESCE(v_max, 0), {minimum_from_source});\n"
                "  IF v_next = 0 THEN PERFORM pg_catalog.setval(v_sequence::regclass, 1, false);\n"
                "  ELSE PERFORM pg_catalog.setval(v_sequence::regclass, v_next::bigint, true); END IF;\n"
                f"  UPDATE {_qualified(TARGET_SCHEMA, AUTOINCREMENT_STATE_STORE)} SET post_load_sync_complete = TRUE WHERE sequence_name = {sequence_key};\n"
                "END\n"
                "$erp_sequence$;"
            )
    return statements


def _autoincrement_support_sql(state_entries: list[dict[str, Any]], application_role: str | None) -> list[str]:
    state_table = _qualified(TARGET_SCHEMA, AUTOINCREMENT_STATE_STORE)
    function = _qualified(TARGET_SCHEMA, UNSIGNED_IDENTITY_FUNCTION)
    statements = [
        f"CREATE TABLE {state_table} (sequence_name TEXT PRIMARY KEY, low_sequence REGCLASS, high_sequence REGCLASS, post_load_sync_complete BOOLEAN NOT NULL DEFAULT FALSE);"
    ]
    has_unsigned = any(item.get("low_sequence") and item.get("high_sequence") for item in state_entries)
    for item in state_entries:
        key = quote_literal(str(item["key"]))
        low_name = item.get("low_sequence")
        high_name = item.get("high_sequence")
        if low_name and high_name:
            low_name = str(low_name)
            high_name = str(high_name)
            statements.extend([
                f"CREATE SEQUENCE {_qualified(TARGET_SCHEMA, low_name)} MINVALUE 1 MAXVALUE {POSTGRES_SEQUENCE_MAX} START WITH 1 CACHE 1;",
                f"CREATE SEQUENCE {_qualified(TARGET_SCHEMA, high_name)} MINVALUE 0 MAXVALUE {POSTGRES_SEQUENCE_MAX} START WITH 0 CACHE 1;",
                f"REVOKE ALL ON SEQUENCE {_qualified(TARGET_SCHEMA, low_name)}, {_qualified(TARGET_SCHEMA, high_name)} FROM PUBLIC;",
            ])
            low_ref = quote_literal(f'"{TARGET_SCHEMA}"."{low_name}"') + "::regclass"
            high_ref = quote_literal(f'"{TARGET_SCHEMA}"."{high_name}"') + "::regclass"
        else:
            low_ref = "NULL"
            high_ref = "NULL"
        statements.append(
            f"INSERT INTO {state_table} (sequence_name, low_sequence, high_sequence) VALUES ({key}, {low_ref}, {high_ref});"
        )
    if has_unsigned:
        statements.extend([
            f"CREATE FUNCTION {function}(p_sequence_name TEXT) RETURNS NUMERIC(20,0) LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS $$\n"
            "DECLARE v_low_sequence regclass; v_high_sequence regclass; v_value BIGINT;\n"
            "BEGIN\n"
            f"  SELECT low_sequence, high_sequence INTO v_low_sequence, v_high_sequence FROM {state_table} WHERE sequence_name = p_sequence_name;\n"
            "  IF NOT FOUND OR v_low_sequence IS NULL OR v_high_sequence IS NULL THEN RAISE EXCEPTION 'unsigned identity state missing'; END IF;\n"
            "  BEGIN\n"
            "    v_value := pg_catalog.nextval(v_low_sequence);\n"
            "    RETURN v_value::numeric;\n"
            "  EXCEPTION WHEN SQLSTATE '2200H' THEN\n"
            "    v_value := pg_catalog.nextval(v_high_sequence);\n"
            f"    RETURN {UNSIGNED_BIGINT_OFFSET}::numeric + v_value::numeric;\n"
            "  END;\n"
            "END;\n$$;",
            f"REVOKE ALL ON FUNCTION {function}(TEXT) FROM PUBLIC;",
        ])
        if application_role is not None:
            app_role = _ident(application_role)
            statements.extend([
                f"GRANT USAGE ON SCHEMA {_ident(TARGET_SCHEMA)} TO {app_role};",
                f"GRANT EXECUTE ON FUNCTION {function}(TEXT) TO {app_role};",
                f"REVOKE ALL ON TABLE {state_table} FROM {app_role};",
            ])
    statements.append(f"REVOKE ALL ON TABLE {state_table} FROM PUBLIC;")
    return statements


def generate_ddl(
    snapshot: dict[str, Any],
    *,
    collation_overrides: dict[str, str] | None = None,
    application_role: str | None = None,
) -> DdlBundle:
    if snapshot.get("catalog_version") != 2 or snapshot.get("source_schema") != SOURCE_SCHEMA:
        raise DdlGenerationError("CATALOG_VERSION_OR_SOURCE_NOT_ALLOWLISTED")
    fingerprint = snapshot.get("fingerprint_sha256")
    if not isinstance(fingerprint, str) or not re.fullmatch(r"[0-9a-f]{64}", fingerprint):
        raise DdlGenerationError("CATALOG_FINGERPRINT_INVALID")
    if canonical_fingerprint(snapshot) != fingerprint:
        raise DdlGenerationError("CATALOG_FINGERPRINT_MISMATCH")
    if application_role is not None:
        if not isinstance(application_role, str) or not application_role.strip():
            raise DdlGenerationError("APPLICATION_ROLE_INVALID")
        try:
            _ident(application_role)
        except TypeMappingError as error:
            raise DdlGenerationError("APPLICATION_ROLE_INVALID") from error

    overrides = dict(collation_overrides or {})
    textual_keys = {
        f"{table['name']}.{column['name']}"
        for table in snapshot.get("tables", []) if table.get("type") == "BASE TABLE"
        for column in table.get("columns", []) if _textual(str(column.get("data_type", "")))
    }
    if set(overrides) - textual_keys:
        raise DdlGenerationError("COLLATION_OVERRIDE_COLUMN_NOT_FOUND")
    if any(not isinstance(value, str) or value not in {"ci", "C"} for value in overrides.values()):
        raise DdlGenerationError("COLLATION_OVERRIDE_INVALID")
    overrides_json = json.dumps(overrides, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    overrides_sha256 = hashlib.sha256(overrides_json.encode("utf-8")).hexdigest()
    report: dict[str, Any] = {
        "generator_version": GENERATOR_VERSION,
        "catalog_fingerprint_sha256": fingerprint,
        "collation_overrides_sha256": overrides_sha256,
        "collation_overrides": dict(sorted(overrides.items())),
        "source_schema": SOURCE_SCHEMA,
        "target_schema": TARGET_SCHEMA,
        "application_role": application_role,
        "review_items": list(snapshot.get("review_items", [])),
        "blocked_tables": [],
        "unsupported_indexes": [],
        "deferred_indexes": [],
        "unique_indexes_pending_collision_scan": [],
        "foreign_keys": {"generated_not_valid": 0, "unsupported": [], "deferred": []},
        "generated": {"tables": 0, "primary_keys": 0, "on_update_triggers": 0, "indexes": 0, "unique_indexes": 0, "enum_checks": 0, "unsigned_sequence_pairs": 0},
        "auto_increment": {
            "post_load_sync_required": True,
            "sync_policy": "ONE_SHOT_BEFORE_APPLICATION_WRITES",
            "generated": 0,
            "columns": [],
            "script": "30_post_load_sequences.sql",
            "runtime_privileges": {
                "application_role": application_role,
                "unsigned_function_execute_granted": False,
                "state_table_access_granted": False,
                "sequence_access_granted": False,
            },
        },
    }
    table_by_name = {str(table["name"]): table for table in snapshot.get("tables", []) if table.get("type") == "BASE TABLE"}
    if len(table_by_name) != len([table for table in snapshot.get("tables", []) if table.get("type") == "BASE TABLE"]):
        raise DdlGenerationError("DUPLICATE_TABLE_METADATA")
    if snapshot.get("views"):
        report["review_items"].append({"code": "VIEW_TRANSLATION_REQUIRED", "count": len(snapshot["views"])})
    all_columns = [column for table in table_by_name.values() for column in table.get("columns", [])]
    report["catalog_summary"] = {
        "base_tables": len(table_by_name),
        "views": len(snapshot.get("views", [])),
        "columns": len(all_columns),
        "columns_by_mysql_type": dict(sorted(Counter(str(column.get("data_type", "unknown")).lower() for column in all_columns).items())),
        "columns_by_source_collation": dict(sorted(Counter(str(column.get("source_collation") or "(none)") for column in all_columns).items())),
        "tables_without_primary_key": sorted(name for name, table in table_by_name.items() if not table.get("primary_key")),
        "auto_increment_columns": sum("auto_increment" in str(column.get("extra", "")).lower() for column in all_columns),
        "triggers_for_manual_translation": len(snapshot.get("triggers", [])) if snapshot.get("metadata_visibility", {}).get("triggers") is True else None,
        "routines_for_manual_review": len(snapshot.get("routines", [])) if snapshot.get("metadata_visibility", {}).get("routines") is True else None,
        "events_for_manual_review": len(snapshot.get("events", [])) if snapshot.get("metadata_visibility", {}).get("events") is True else None,
        "metadata_visibility": dict(snapshot.get("metadata_visibility", {})),
        "source_grants": snapshot.get("grants", []),
        "unresolved_role_grants": bool(snapshot.get("unresolved_role_grants", False)),
        "source_grants_read_only": snapshot.get("source_grants_read_only") is True,
        "source_schema_wide_select": snapshot.get("source_schema_wide_select") is True,
    }
    if snapshot.get("source_grants_read_only") is not True:
        report["review_items"].append({"code": "SOURCE_ACCOUNT_READ_ONLY_NOT_PROVEN"})
    if snapshot.get("source_schema_wide_select") is not True:
        report["review_items"].append({"code": "SOURCE_SCHEMA_WIDE_SELECT_NOT_PROVEN"})
    for object_type in ("views", "triggers", "routines", "events"):
        if snapshot.get("metadata_visibility", {}).get(object_type) is not True:
            report["review_items"].append({"object_type": object_type, "code": "METADATA_VISIBILITY_NOT_CONFIRMED"})
    if snapshot.get("unresolved_role_grants"):
        report["review_items"].append({"code": "SOURCE_ROLE_GRANTS_UNRESOLVED"})
    if snapshot.get("grants"):
        report["review_items"].append({"code": "POSTGRES_ROLE_GRANT_MAPPING_REQUIRED"})
    for trigger in snapshot.get("triggers", []):
        report["review_items"].append({
            "table": str(trigger.get("table", "")),
            "trigger": str(trigger.get("name", "")),
            "body_sha256": str(trigger.get("body_sha256", "")),
            "code": "BUSINESS_TRIGGER_TRANSLATION_REQUIRED",
        })
    for routine in snapshot.get("routines", []):
        report["review_items"].append({
            "routine": str(routine.get("name", "")),
            "type": str(routine.get("type", "")),
            "body_sha256": str(routine.get("body_sha256", "")),
            "code": "ROUTINE_TRANSLATION_REQUIRED",
        })
    for event in snapshot.get("events", []):
        report["review_items"].append({
            "event": str(event.get("name", "")),
            "body_sha256": str(event.get("body_sha256", "")),
            "code": "EVENT_TRANSLATION_REQUIRED",
        })

    table_statements = [
        f"-- Generator version: {GENERATOR_VERSION}",
        f"-- Catalog SHA-256: {fingerprint}",
        f"CREATE SCHEMA {_ident(TARGET_SCHEMA)};",
        f"SET search_path TO {_ident(TARGET_SCHEMA)}, pg_catalog;",
        f"CREATE COLLATION {_qualified(TARGET_SCHEMA, 'ci')} (provider = icu, locale = 'und-u-ks-level1', deterministic = false);",
    ]
    table_header = list(table_statements)
    table_statements = []
    autoincrement_entries: list[dict[str, Any]] = []
    on_update_groups: dict[str, list[str]] = {}
    emitted_tables: set[str] = set()
    emitted_unique_keys: set[tuple[str, tuple[str, ...]]] = set()
    emitted_column_types: dict[tuple[str, str], tuple[str, str | None]] = {}
    identity_columns: list[dict[str, Any]] = []
    for table_name in sorted(table_by_name):
        table = table_by_name[table_name]
        try:
            _ident(table_name)
            if str(table.get("engine", "")).upper() != "INNODB":
                raise TypeMappingError("NON_TRANSACTIONAL_OR_UNSUPPORTED_ENGINE")
            column_defs: list[str] = []
            pk = sorted(table.get("primary_key", []), key=lambda item: int(item["ordinal"]))
            pk_columns = [str(item["column"]) for item in pk]
            columns = table.get("columns", [])
            if not columns:
                raise TypeMappingError("TABLE_HAS_NO_COLUMNS")
            table_column_types: dict[str, tuple[str, str | None]] = {}
            auto_columns = [column for column in columns if "auto_increment" in str(column.get("extra", "")).lower()]
            if len(auto_columns) > 1:
                raise TypeMappingError("MULTIPLE_AUTO_INCREMENT_COLUMNS")
            table_allocators: list[dict[str, str]] = []
            for column in columns:
                name = str(column["name"])
                _ident(name)
                if column.get("generated_expression_present"):
                    raise TypeMappingError("GENERATED_COLUMN_TRANSLATION_REQUIRED")
                data_type = str(column["data_type"])
                target_collation = _target_collation(column, overrides, table_name, report) if _textual(data_type) else None
                mapping = map_mysql_type(
                    str(column["column_type"]),
                    target_collation=target_collation,
                    column_name=name,
                    no_backslash_escapes=bool(snapshot.get("source_no_backslash_escapes", False)),
                )
                table_column_types[name] = (mapping.sql_type, mapping.collation_sql)
                declaration = f"{_ident(name)} {mapping.declaration}"
                extra = str(column.get("extra", "")).lower()
                if "auto_increment" in extra:
                    if mapping.sql_type == "NUMERIC(20,0)":
                        sequence_key = f"{table_name}.{name}"
                        table_allocators.append({
                            "key": sequence_key,
                            "low_sequence": _generated_name("p2_u64_lo", table_name, name),
                            "high_sequence": _generated_name("p2_u64_hi", table_name, name),
                        })
                        declaration += f" DEFAULT {_qualified(TARGET_SCHEMA, UNSIGNED_IDENTITY_FUNCTION)}({quote_literal(sequence_key)})"
                    else:
                        declaration += " GENERATED BY DEFAULT AS IDENTITY"
                if not bool(column.get("nullable", True)):
                    declaration += " NOT NULL"
                default_sql = _sql_default(
                    column,
                    table_name,
                    report,
                    no_backslash_escapes=bool(snapshot.get("source_no_backslash_escapes", False)),
                )
                if default_sql is not None:
                    declaration += f" DEFAULT {default_sql}"
                column_defs.append(declaration)
                for check in mapping.checks:
                    constraint_name = _generated_name("ck", table_name, name, str(len(column_defs)))
                    column_defs.append(f"CONSTRAINT {_ident(constraint_name)} {check}")
                    if data_type.lower() == "enum":
                        report["generated"]["enum_checks"] += 1
                report["review_items"].extend({"table": table_name, "column": name, "code": code} for code in mapping.review_codes)
                if "on update current_timestamp" in extra:
                    if data_type.lower() not in {"timestamp", "datetime"}:
                        report["review_items"].append({"table": table_name, "column": name, "code": "ON_UPDATE_NON_TEMPORAL_TYPE"})
                    else:
                        on_update_groups.setdefault(table_name, []).append(name)
            if pk_columns:
                for name in pk_columns:
                    if name not in {str(column["name"]) for column in columns}:
                        raise TypeMappingError("PRIMARY_KEY_COLUMN_NOT_FOUND")
                if _key_requires_collision_scan(table, pk_columns, overrides):
                    report["review_items"].append({
                        "table": table_name,
                        "columns": pk_columns,
                        "code": "PRIMARY_KEY_CI_COLLISION_SCAN_REQUIRED",
                    })
                pk_name = "PRIMARY" if len("PRIMARY") <= MAX_IDENTIFIER_BYTES else "pk_" + hashlib.sha256(table_name.encode()).hexdigest()[:16]
                column_defs.append(f"CONSTRAINT {_ident(pk_name)} PRIMARY KEY ({', '.join(_ident(name) for name in pk_columns)})")
                report["generated"]["primary_keys"] += 1
            table_statements.append(f"CREATE TABLE {_qualified(TARGET_SCHEMA, table_name)} (\n  " + ",\n  ".join(column_defs) + "\n);")
            autoincrement_entries.extend(table_allocators)
            report["generated"]["tables"] += 1
            emitted_tables.add(table_name)
            emitted_column_types.update({(table_name, column_name): value for column_name, value in table_column_types.items()})
            if pk_columns:
                emitted_unique_keys.add((table_name, tuple(pk_columns)))
            if auto_columns:
                next_value = table.get("source_next_auto_increment")
                auto_column = auto_columns[0]
                auto_name = str(auto_column["name"])
                strategy = "unsigned_sequence_pair" if table_column_types[auto_name][0] == "NUMERIC(20,0)" else "identity"
                maximum_next = MYSQL_UNSIGNED_BIGINT_MAX if strategy == "unsigned_sequence_pair" else POSTGRES_SEQUENCE_MAX
                if next_value is not None and (isinstance(next_value, bool) or not isinstance(next_value, int) or next_value < 1 or next_value > maximum_next):
                    report["review_items"].append({"table": table_name, "code": "AUTO_INCREMENT_NEXT_VALUE_METADATA_INVALID"})
                    next_value = None
                if strategy == "identity":
                    autoincrement_entries.append({"key": f"{table_name}.{auto_name}"})
                identity_columns.append({
                    "table": table_name,
                    "column": auto_name,
                    "source_next_auto_increment": next_value,
                    "strategy": strategy,
                    **(next((item for item in table_allocators if item["key"] == f"{table_name}.{auto_name}"), {}) if strategy == "unsigned_sequence_pair" else {}),
                })
                if strategy == "unsigned_sequence_pair":
                    report["generated"]["unsigned_sequence_pairs"] += 1
        except (TypeMappingError, KeyError, ValueError) as error:
            code = error.code if isinstance(error, TypeMappingError) else "TABLE_METADATA_INVALID"
            report["blocked_tables"].append({"table": table_name, "code": code})

    if autoincrement_entries:
        has_unsigned_identity = any(item.get("low_sequence") for item in autoincrement_entries)
        report["auto_increment"]["runtime_privileges"]["unsigned_function_execute_granted"] = has_unsigned_identity and application_role is not None
        if application_role is None and has_unsigned_identity:
            report["review_items"].append({"code": "AUTO_INCREMENT_APPLICATION_ROLE_REQUIRED"})
        table_header.extend(_autoincrement_support_sql(autoincrement_entries, application_role))
    table_statements = table_header + table_statements

    if on_update_groups:
        trigger_statements = [f"-- Generator version: {GENERATOR_VERSION}", f"-- Catalog SHA-256: {fingerprint}", f"SET search_path TO {_ident(TARGET_SCHEMA)}, pg_catalog;"]
        for table_name, columns in sorted(on_update_groups.items()):
            if table_name not in emitted_tables:
                continue
            digest = hashlib.sha256((table_name + "\0" + "\0".join(sorted(columns))).encode()).hexdigest()[:16]
            function_name = _ident("fn_on_update_" + digest)
            trigger_name = _ident("trg_on_update_" + digest)
            excluded = _array_text(columns)
            column_types = {
                str(column["name"]): str(column["data_type"]).lower()
                for column in table_by_name[table_name]["columns"]
            }
            assignments = "\n".join(
                f"    IF NEW.{_ident(column)} IS NOT DISTINCT FROM OLD.{_ident(column)} THEN\n"
                f"      NEW.{_ident(column)} := {('date_trunc(\'second\', timezone(\'America/Sao_Paulo\', statement_timestamp()))' if column_types[column] == 'timestamp' else 'date_trunc(\'second\', LOCALTIMESTAMP)')};\n"
                "    END IF;"
                for column in columns
            )
            trigger_statements.append(
                f"CREATE FUNCTION {_qualified(TARGET_SCHEMA, 'fn_on_update_' + digest)}() RETURNS trigger\n"
                "LANGUAGE plpgsql AS $$\nBEGIN\n"
                f"  IF to_jsonb(NEW) - {excluded} IS DISTINCT FROM to_jsonb(OLD) - {excluded} THEN\n"
                f"{assignments}\n"
                "  END IF;\n  RETURN NEW;\nEND;\n$$;"
            )
            trigger_statements.append(
                f"CREATE TRIGGER {trigger_name} BEFORE UPDATE ON {_qualified(TARGET_SCHEMA, table_name)}\n"
                f"FOR EACH ROW EXECUTE FUNCTION {_qualified(TARGET_SCHEMA, 'fn_on_update_' + digest)}();"
            )
            report["generated"]["on_update_triggers"] += 1
    else:
        trigger_statements = [f"-- Generator version: {GENERATOR_VERSION}", f"-- Catalog SHA-256: {fingerprint}"]

    index_statements = [f"-- Generator version: {GENERATOR_VERSION}", f"-- Catalog SHA-256: {fingerprint}", f"SET search_path TO {_ident(TARGET_SCHEMA)}, pg_catalog;"]
    unique_pending: list[dict[str, Any]] = []
    for index in snapshot.get("indexes", []):
        table_name = str(index.get("table", ""))
        index_name = str(index.get("name", ""))
        if index_name.upper() == "PRIMARY":
            continue
        if table_name not in emitted_tables:
            if table_name in table_by_name:
                report["deferred_indexes"].append({"table": table_name, "index": index_name, "code": "INDEX_TABLE_DDL_BLOCKED"})
            else:
                report["unsupported_indexes"].append({"table": table_name, "index": index_name, "code": "INDEX_TABLE_NOT_IN_CATALOG"})
            continue
        try:
            _ident(table_name); _ident(index_name)
            columns = index.get("columns", [])
            if not columns:
                raise TypeMappingError("INDEX_HAS_NO_COLUMNS")
            if not bool(index.get("visible", True)):
                raise TypeMappingError("INVISIBLE_INDEX_SEMANTICS_REVIEW_REQUIRED")
            if str(index.get("type", "BTREE")).upper() not in {"BTREE"}:
                raise TypeMappingError("INDEX_METHOD_REVIEW_REQUIRED")
            parts: list[str] = []
            for part in columns:
                if part.get("expression_present") or part.get("prefix_length") is not None or not part.get("name"):
                    raise TypeMappingError("FUNCTIONAL_OR_PREFIX_INDEX_REVIEW_REQUIRED")
                direction = str(part.get("direction", "ASC")).upper()
                if direction not in {"ASC", "DESC"}:
                    raise TypeMappingError("INDEX_DIRECTION_INVALID")
                parts.append(f"{_ident(str(part['name']))} {direction}")
            unique = not bool(index.get("non_unique", True))
            generated_name = _generated_name("ix", table_name, index_name)
            statement = f"CREATE {'UNIQUE ' if unique else ''}INDEX {_ident(generated_name)} ON {_qualified(TARGET_SCHEMA, table_name)} ({', '.join(parts)});"
            index_statements.append(statement)
            report["generated"]["indexes"] += 1
            if unique:
                report["generated"]["unique_indexes"] += 1
                key_columns = [str(p["name"]) for p in columns]
                emitted_unique_keys.add((table_name, tuple(key_columns)))
                if _key_requires_collision_scan(table_by_name[table_name], key_columns, overrides):
                    unique_pending.append({"table": table_name, "index": index_name, "columns": key_columns})
                    report["review_items"].append({
                        "table": table_name,
                        "index": index_name,
                        "columns": key_columns,
                        "code": "UNIQUE_COLLISION_SCAN_REQUIRED",
                    })
        except (TypeMappingError, KeyError, ValueError) as error:
            code = error.code if isinstance(error, TypeMappingError) else "INDEX_METADATA_INVALID"
            report["unsupported_indexes"].append({"table": table_name, "index": index_name, "code": code})

    fk_statements = [f"-- Generator version: {GENERATOR_VERSION}", f"-- Catalog SHA-256: {fingerprint}", f"SET search_path TO {_ident(TARGET_SCHEMA)}, pg_catalog;"]
    for fk in snapshot.get("foreign_keys", []):
        table_name = str(fk.get("table", "")); name = str(fk.get("name", ""))
        ref_schema = str(fk.get("referenced_schema", "")); ref_table = str(fk.get("referenced_table", ""))
        if table_name not in emitted_tables:
            destination = "deferred" if table_name in table_by_name else "unsupported"
            report["foreign_keys"][destination].append({"table": table_name, "name": name, "referenced_schema": ref_schema, "referenced_table": ref_table, "code": "FK_SOURCE_TABLE_DDL_BLOCKED" if destination == "deferred" else "FK_SOURCE_TABLE_NOT_IN_CATALOG"})
            continue
        if ref_schema != SOURCE_SCHEMA:
            report["foreign_keys"]["unsupported"].append({"table": table_name, "name": name, "referenced_schema": ref_schema, "referenced_table": ref_table, "code": "FK_REFERENCE_OUTSIDE_SOURCE_SCHEMA"})
            continue
        if ref_table not in emitted_tables:
            destination = "deferred" if ref_table in table_by_name else "unsupported"
            report["foreign_keys"][destination].append({"table": table_name, "name": name, "referenced_schema": ref_schema, "referenced_table": ref_table, "code": "FK_TARGET_TABLE_DDL_BLOCKED" if destination == "deferred" else "FK_TARGET_TABLE_NOT_IN_CATALOG"})
            continue
        try:
            _ident(table_name); _ident(name); _ident(ref_table)
            source_columns = [str(x) for x in fk.get("columns", [])]
            target_columns = [str(x) for x in fk.get("referenced_columns", [])]
            if not source_columns or len(source_columns) != len(target_columns):
                raise TypeMappingError("FK_COLUMN_ARITY_MISMATCH")
            for source_column, target_column in zip(source_columns, target_columns):
                source_type = emitted_column_types.get((table_name, source_column))
                target_type = emitted_column_types.get((ref_table, target_column))
                if source_type is None or target_type is None:
                    raise TypeMappingError("FK_COLUMN_NOT_EMITTED")
                if source_type != target_type:
                    raise TypeMappingError("FK_COLUMN_TYPE_OR_COLLATION_MISMATCH")
            if (ref_table, tuple(target_columns)) not in emitted_unique_keys:
                raise TypeMappingError("FK_TARGET_NOT_UNIQUE")
            update_rule = str(fk.get("update_rule", "NO ACTION")).upper()
            delete_rule = str(fk.get("delete_rule", "NO ACTION")).upper()
            allowed = {"NO ACTION", "RESTRICT", "CASCADE", "SET NULL", "SET DEFAULT"}
            if update_rule not in allowed or delete_rule not in allowed:
                raise TypeMappingError("FK_ACTION_UNSUPPORTED")
            action_sql = f" ON UPDATE {update_rule} ON DELETE {delete_rule}"
            fk_statements.append(
                f"ALTER TABLE {_qualified(TARGET_SCHEMA, table_name)} ADD CONSTRAINT {_ident(_generated_name('fk', table_name, name))} "
                f"FOREIGN KEY ({', '.join(_ident(x) for x in source_columns)}) "
                f"REFERENCES {_qualified(TARGET_SCHEMA, ref_table)} ({', '.join(_ident(x) for x in target_columns)})"
                f"{action_sql} NOT VALID;"
            )
            report["foreign_keys"]["generated_not_valid"] += 1
        except (TypeMappingError, KeyError, ValueError) as error:
            code = error.code if isinstance(error, TypeMappingError) else "FK_METADATA_INVALID"
            report["foreign_keys"]["unsupported"].append({"table": table_name, "name": name, "code": code})

    checks = snapshot.get("check_constraints", [])
    for check in checks:
        report["review_items"].append({
            "table": str(check.get("table", "")),
            "constraint": str(check.get("name", "")),
            "code": "MYSQL_CHECK_EXPRESSION_TRANSLATION_REQUIRED",
            "definition_sha256": str(check.get("definition_sha256", "")),
        })

    report["unique_indexes_pending_collision_scan"] = unique_pending
    report["auto_increment"]["generated"] = len(identity_columns)
    report["auto_increment"]["columns"] = identity_columns
    report["review_items"].sort(key=lambda item: (str(item.get("table", "")), str(item.get("column", "")), str(item.get("code", ""))))
    for script in [table_statements, trigger_statements, index_statements, fk_statements]:
        script.append("")
    scripts = {
        "00_tables.sql": "\n\n".join(table_statements),
        "05_on_update_triggers.sql": "\n\n".join(trigger_statements),
        "10_indexes.sql": "\n\n".join(index_statements),
        "20_foreign_keys_not_valid.sql": "\n\n".join(fk_statements),
        "30_post_load_sequences.sql": "\n\n".join(_identity_sequence_script(fingerprint, identity_columns)),
    }
    scripts = {name: f"-- Collation overrides SHA-256: {overrides_sha256}\n\n{content}" for name, content in scripts.items()}
    report["generated_tables"] = sorted(emitted_tables)
    report["executable_complete"] = not report["blocked_tables"] and not report["unsupported_indexes"] and not report["deferred_indexes"] and not report["foreign_keys"]["unsupported"] and not report["foreign_keys"]["deferred"] and not report["review_items"]
    return DdlBundle(scripts=scripts, report=report)


def canonical_fingerprint(snapshot: dict[str, Any]) -> str:
    copied = dict(snapshot)
    copied.pop("fingerprint_sha256", None)
    raw = json.dumps(copied, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()
