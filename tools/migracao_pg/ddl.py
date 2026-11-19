from __future__ import annotations

from dataclasses import dataclass
from datetime import date, datetime
import hashlib
import json
import re
from pathlib import Path
from collections import Counter
from typing import Any

from .generated_columns import declares_generated_column, generated_dependencies_valid, target_generated_column
from .type_mapping import TypeMappingError, map_mysql_type, parse_mysql_enum, quote_identifier, quote_literal

SOURCE_SCHEMA = "erp_pg_source"
TARGET_SCHEMA = "erp"
LEGACY_SCHEMA = "legado"
MAX_IDENTIFIER_BYTES = 63
GENERATOR_VERSION = "2.2.0"
SOURCE_GRANT_POLICY_ID = "erp_pg_source_select_show_view_trigger_v2"
AUTOINCREMENT_STATE_STORE = "_p2_autoincrement_state"
UNSIGNED_IDENTITY_FUNCTION = "_p2_next_unsigned_id"
POSTGRES_SEQUENCE_MAX = 9223372036854775807
UNSIGNED_BIGINT_OFFSET = 9223372036854775808
MYSQL_UNSIGNED_BIGINT_MAX = 18446744073709551615
BUSINESS_TRIGGER_SOURCE_SHA256 = {
    ("checklist_veicular", "trg_atualizar_km_acumulado", "AFTER", "UPDATE"): "236afd03ce87d410571b1e9bab6b461a7543a8f72308a2d3f0cfbf752ff67413",
    ("dependentes", "tr_dependente_ativar_veiculos", "BEFORE", "UPDATE"): "87c2eabb8e13760db61ea1a5d9bd01ef7a92689e546eada1b916a62a5d98e8a4",
    ("dependentes", "tr_dependente_deletar_veiculos", "BEFORE", "DELETE"): "c9c617367a42f86f625a2c04b6cf1384caa19bdf04f0df5d713e88ed3708362f",
    ("dependentes", "tr_dependente_inativar_veiculos", "BEFORE", "UPDATE"): "26d1ff52cc0fca6d6e4f7a93ea3b940788866fa6214522e4ac06126c31cf50a1",
    ("logs_validacoes_dispositivo", "after_validacao_dispositivo_insert", "AFTER", "INSERT"): "c8ed66f274c4d58f1092980c89bca871fb7871f777c8eeae250b47bb2342fd18",
    ("produtos_estoque", "trg_alerta_estoque_baixo", "AFTER", "UPDATE"): "c1fcba675ba3423c1bcbc50d65ca38e8e60f800313cfc739ac1cadf8a1235c97",
    ("qrcode_tokens", "after_qrcode_token_insert", "AFTER", "INSERT"): "bd36dac166ce871c10a393d8f959206c43bc2f4f8c22adb5926064e5c38d0ab8",
}
BUSINESS_TRIGGER_SCRIPT = Path(__file__).resolve().parents[2] / "sql" / "postgresql" / "070_business_triggers_tenant_scoped.sql"


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


def _sensitive_column_name(name: str) -> bool:
    return re.search(
        r"(?:^|_)(?:token|hash|sha|hmac|senha|password|secret|key|chave|slug|uuid|path|caminho|file|arquivo|signature|assinatura|payload)(?:_|$)",
        name,
        re.IGNORECASE,
    ) is not None


def _is_legacy_backup_table(name: str) -> bool:
    lowered = name.lower()
    return "_bkp_" in lowered or lowered.endswith("_backup") or re.search(r"(?:_|-)20\d{2}(?:[-_]?\d{2}){2}$", lowered) is not None


def _schema_for_table(name: str) -> str:
    return LEGACY_SCHEMA if _is_legacy_backup_table(name) else TARGET_SCHEMA


def _unsigned_bigint_range_flags(
    table_by_name: dict[str, dict[str, Any]], foreign_keys: list[dict[str, Any]]
) -> tuple[dict[tuple[str, str], bool], list[dict[str, str]], list[dict[str, str]]]:
    unsigned_columns: set[tuple[str, str]] = set()
    flags: dict[tuple[str, str], bool] = {}
    for table_name, table in table_by_name.items():
        for column in table.get("columns", []):
            key = (table_name, str(column.get("name", "")))
            if str(column.get("data_type", "")).lower() != "bigint" or not re.search(
                r"\b(?:unsigned|zerofill)\b", str(column.get("column_type", "")), re.IGNORECASE
            ):
                continue
            unsigned_columns.add(key)
            value = column.get("unsigned_bigint_exceeds_signed_limit")
            if not isinstance(value, bool):
                continue
            flags[key] = value

    promoted = {key for key, value in flags.items() if value}
    changed = True
    while changed:
        changed = False
        for fk in foreign_keys:
            source = [str(value) for value in fk.get("columns", [])]
            target = [str(value) for value in fk.get("referenced_columns", [])]
            if len(source) != len(target):
                continue
            left_table = str(fk.get("table", ""))
            right_table = str(fk.get("referenced_table", ""))
            for source_column, target_column in zip(source, target):
                left = (left_table, source_column)
                right = (right_table, target_column)
                if left not in unsigned_columns or right not in unsigned_columns or left not in flags or right not in flags:
                    continue
                if (left in promoted) != (right in promoted):
                    promoted.update((left, right))
                    changed = True

    effective = {key: key in promoted for key in unsigned_columns if key in flags}
    observed = [{"table": table, "column": column} for table, column in sorted(flags) if flags[(table, column)]]
    aligned = [
        {"table": table, "column": column}
        for table, column in sorted(promoted - {key for key, value in flags.items() if value})
    ]
    return effective, observed, aligned


def _key_requires_collision_scan(table: dict[str, Any], columns: list[str], overrides: dict[str, str]) -> bool:
    column_by_name = {str(column["name"]): column for column in table.get("columns", [])}
    for name in columns:
        column = column_by_name.get(name)
        if not column or not _textual(str(column.get("data_type", ""))):
            continue
        key = f"{table['name']}.{name}"
        if key in overrides:
            target = overrides[key]
        else:
            source = str(column.get("source_collation") or "").lower()
            sensitive_name = _sensitive_column_name(name)
            target = "C" if sensitive_name or source.endswith("_bin") or source == "binary" else "ci"
        if target == "ci":
            return True
    return False


def _target_collation(column: dict[str, Any], explicit: dict[str, str], table: str, report: dict[str, Any]) -> str | None:
    name = str(column["name"])
    key = f"{table}.{name}"
    if key in explicit:
        value = explicit[key]
        if value not in {"ci", "C"}:
            raise DdlGenerationError("COLLATION_OVERRIDE_INVALID")
        reason = "explicit_override"
    else:
        source = str(column.get("source_collation") or "").lower()
        sensitive_name = _sensitive_column_name(name)
        binary_source = source.endswith("_bin") or source == "binary"
        value = "C" if sensitive_name or binary_source else "ci"
        reason = "sensitive_name" if sensitive_name else ("binary_source_collation" if binary_source else "default_case_insensitive")
    decision = {"table": table, "column": name, "target": value, "source": column.get("source_collation"), "reason": reason}
    report["collation_decisions"].append(decision)
    if value == "C":
        report["collation_C_columns"].append({"table": table, "column": name, "reason": reason})
        return value
    return "ci"


def _sql_default(column: dict[str, Any], table: str, report: dict[str, Any], *, no_backslash_escapes: bool = False) -> str | None:
    default = column.get("default")
    if default is None:
        return None
    if default.get("kind") == "sql":
        expression = str(default.get("expression", ""))
        data_type = str(column.get("data_type", "")).lower()
        if expression == "NULL":
            if data_type in {"date", "datetime", "timestamp"}:
                return expression
            if not _textual(data_type) and column.get("nullable") is True:
                return expression
            report["review_items"].append({"table": table, "column": str(column["name"]), "code": "DEFAULT_NULL_SEMANTICS_REVIEW_REQUIRED"})
            return None
        if data_type in {"tinyint", "smallint", "mediumint", "int", "integer", "bigint", "decimal", "numeric", "float", "double", "real", "year"}:
            if re.fullmatch(r"[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?", expression):
                return expression
        elif data_type in {"char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext"}:
            if expression == "''" or re.fullmatch(r"'(?:[^']|'')*'", expression):
                literal = expression[1:-1].replace("''", "'")
                if "\x00" not in literal and quote_literal(literal) == expression:
                    return expression
        elif data_type in {"datetime", "timestamp"}:
            expected = "timezone('America/Sao_Paulo', statement_timestamp())" if data_type == "timestamp" else "LOCALTIMESTAMP(0)"
            if expression == expected:
                return expression
            match = re.fullmatch(r"TIMESTAMP '([^']+)'", expression)
            if match:
                try:
                    parsed = datetime.fromisoformat(match.group(1))
                except ValueError:
                    parsed = None
                if parsed is not None and parsed.tzinfo is None and not parsed.microsecond:
                    return expression
        elif data_type == "date":
            match = re.fullmatch(r"DATE '([^']+)'", expression)
            if match:
                try:
                    date.fromisoformat(match.group(1))
                except ValueError:
                    pass
                else:
                    return expression
        elif data_type == "time":
            match = re.fullmatch(r"INTERVAL '([+-]?\d{1,3}:\d{2}:\d{2}(?:\.\d{1,6})?)'", expression)
            if match:
                hours, minutes, seconds = (int(part) for part in match.group(1).lstrip("+-").split(":", 2))
                if hours <= 838 and minutes <= 59 and seconds <= 59:
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
        schema = str(item.get("target_schema", TARGET_SCHEMA))
        next_value = item.get("source_next_auto_increment")
        strategy = str(item.get("strategy", "identity"))
        maximum = MYSQL_UNSIGNED_BIGINT_MAX if strategy == "unsigned_sequence_pair" else POSTGRES_SEQUENCE_MAX
        if strategy not in {"identity", "unsigned_sequence_pair"}:
            raise DdlGenerationError("IDENTITY_STRATEGY_NOT_SUPPORTED")
        if next_value is not None and (isinstance(next_value, bool) or not isinstance(next_value, int) or next_value < 1 or next_value > maximum):
            raise DdlGenerationError("AUTO_INCREMENT_NEXT_VALUE_METADATA_INVALID")
        minimum_from_source = max(0, next_value - 1) if next_value is not None else 0
        sequence_key = quote_literal(f"{schema}.{table}.{column}")
        if strategy == "unsigned_sequence_pair":
            statements.append(
                "DO $erp_numeric_identity$\n"
                "DECLARE v_current numeric; v_low_sequence regclass; v_high_sequence regclass; v_synced boolean;\n"
                "BEGIN\n"
                f"  SELECT low_sequence, high_sequence, post_load_sync_complete INTO v_low_sequence, v_high_sequence, v_synced FROM {_qualified(TARGET_SCHEMA, AUTOINCREMENT_STATE_STORE)} WHERE sequence_name = {sequence_key} FOR UPDATE;\n"
                "  IF NOT FOUND THEN RAISE EXCEPTION 'numeric identity state missing'; END IF;\n"
                "  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;\n"
                f"  LOCK TABLE {_qualified(schema, table)} IN ACCESS EXCLUSIVE MODE;\n"
                "  IF pg_catalog.pg_sequence_last_value(v_low_sequence) IS NOT NULL OR pg_catalog.pg_sequence_last_value(v_high_sequence) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;\n"
                f"  SELECT GREATEST(COALESCE(MAX({_ident(column)}), 0), {minimum_from_source}) INTO v_current FROM {_qualified(schema, table)};\n"
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
            qualified_text = quote_literal(f'"{schema}"."{table}"')
            column_text = quote_literal(column)
            statements.append(
                "DO $erp_sequence$\n"
                "DECLARE v_sequence text; v_max numeric; v_next numeric; v_synced boolean;\n"
                "BEGIN\n"
                f"  SELECT post_load_sync_complete INTO v_synced FROM {_qualified(TARGET_SCHEMA, AUTOINCREMENT_STATE_STORE)} WHERE sequence_name = {sequence_key} FOR UPDATE;\n"
                "  IF NOT FOUND THEN RAISE EXCEPTION 'identity state missing'; END IF;\n"
                "  IF v_synced THEN RAISE EXCEPTION 'post-load sequence sync is one-shot'; END IF;\n"
                f"  LOCK TABLE {_qualified(schema, table)} IN ACCESS EXCLUSIVE MODE;\n"
                f"  SELECT pg_get_serial_sequence({qualified_text}, {column_text}) INTO v_sequence;\n"
                "  IF v_sequence IS NULL THEN RAISE EXCEPTION 'identity sequence missing'; END IF;\n"
                "  IF pg_catalog.pg_sequence_last_value(v_sequence::regclass) IS NOT NULL THEN RAISE EXCEPTION 'sequence sync already started; manual recovery required'; END IF;\n"
                f"  SELECT MAX({_ident(column)}) INTO v_max FROM {_qualified(schema, table)};\n"
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
    if snapshot.get("catalog_version") != 7 or snapshot.get("source_schema") != SOURCE_SCHEMA:
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
        "collation_decisions": [],
        "collation_C_columns": [],
        "zero_date_nullable_columns": [],
        "enum_checks_deferred": [],
        "source_schema": SOURCE_SCHEMA,
        "target_schema": TARGET_SCHEMA,
        "legacy_schema": LEGACY_SCHEMA,
        "table_schemas": {},
        "legacy_backup_tables": [],
        "nul_audit_table": {"schema": LEGACY_SCHEMA, "table": "auditoria_nul", "runtime_access_granted": False},
        "application_role": application_role,
        "review_items": list(snapshot.get("review_items", [])),
        "blocked_tables": [],
        "unsupported_indexes": [],
        "deferred_indexes": [],
        "unique_indexes_pending_collision_scan": [],
        "primary_keys_pending_collision_scan": [],
        "primary_keys_post_load": [],
        "unique_indexes_post_load": [],
        "foreign_keys_post_load": [],
        "foreign_keys": {"generated_not_valid": 0, "unsupported": [], "deferred": []},
        "generated": {"tables": 0, "primary_keys": 0, "on_update_triggers": 0, "generated_columns": 0, "indexes": 0, "unique_indexes": 0, "enum_checks": 0, "unsigned_sequence_pairs": 0},
        "generated_columns": [],
        "unsigned_bigint_range": {
            "observed_above_signed_limit": [],
            "aligned_for_foreign_keys": [],
            "numeric_columns": [],
        },
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
    unsigned_bigint_flags, unsigned_bigint_observed, unsigned_bigint_aligned = _unsigned_bigint_range_flags(
        table_by_name, snapshot.get("foreign_keys", [])
    )
    report["unsigned_bigint_range"]["observed_above_signed_limit"] = unsigned_bigint_observed
    report["unsigned_bigint_range"]["aligned_for_foreign_keys"] = unsigned_bigint_aligned
    report["unsigned_bigint_range"]["numeric_columns"] = [
        {"table": table, "column": column}
        for table, column in sorted(unsigned_bigint_flags)
        if unsigned_bigint_flags[(table, column)]
    ]
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
        "source_grant_policy_id": snapshot.get("source_grant_policy_id"),
        "source_grants_policy_accepted": snapshot.get("source_grants_policy_accepted") is True,
        "source_routines_events_zero_verified": snapshot.get("source_routines_events_zero_verified") is True,
        "source_schema_wide_select": snapshot.get("source_schema_wide_select") is True,
    }
    if snapshot.get("source_grant_policy_id") != SOURCE_GRANT_POLICY_ID \
        or snapshot.get("source_grants_policy_accepted") is not True:
        report["review_items"].append({"code": "SOURCE_GRANT_POLICY_NOT_ACCEPTED"})
    if snapshot.get("source_routines_events_zero_verified") is not True:
        report["review_items"].append({"code": "SOURCE_ROUTINES_EVENTS_ZERO_NOT_VERIFIED"})
    if snapshot.get("source_schema_wide_select") is not True:
        report["review_items"].append({"code": "SOURCE_SCHEMA_WIDE_SELECT_NOT_PROVEN"})
    visibility = snapshot.get("metadata_visibility", {})
    for object_type in ("views", "triggers"):
        if visibility.get(object_type) is not True:
            report["review_items"].append({"object_type": object_type, "code": "METADATA_VISIBILITY_NOT_CONFIRMED"})
    if any(visibility.get(object_type) is not False for object_type in ("routines", "events")):
        report["review_items"].append({"code": "ROUTINE_EVENT_GRANT_VISIBILITY_POLICY_MISMATCH"})
    if snapshot.get("source_routines_events_zero_verified") is not True \
        or snapshot.get("routines") != [] or snapshot.get("events") != []:
        report["review_items"].append({"code": "SOURCE_ROUTINES_EVENTS_ZERO_PROOF_INCONSISTENT"})
    if snapshot.get("unresolved_role_grants"):
        report["review_items"].append({"code": "SOURCE_ROLE_GRANTS_UNRESOLVED"})
    source_triggers = snapshot.get("triggers", [])
    actual_trigger_hashes = {
        (str(trigger.get("table", "")), str(trigger.get("name", "")),
         str(trigger.get("timing", "")).upper(), str(trigger.get("event", "")).upper()):
        str(trigger.get("body_sha256", ""))
        for trigger in source_triggers
    }
    business_triggers_approved = (
        len(source_triggers) == len(BUSINESS_TRIGGER_SOURCE_SHA256)
        and actual_trigger_hashes == BUSINESS_TRIGGER_SOURCE_SHA256
    )
    trigger_translation_items = []
    for trigger in (() if business_triggers_approved else source_triggers):
        trigger_translation_items.append({
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
        f"CREATE SCHEMA {_ident(LEGACY_SCHEMA)};",
        f"REVOKE ALL ON SCHEMA {_ident(LEGACY_SCHEMA)} FROM PUBLIC;",
        f"SET search_path TO {_ident(TARGET_SCHEMA)}, {_ident(LEGACY_SCHEMA)}, pg_catalog;",
        f"CREATE COLLATION {_qualified(TARGET_SCHEMA, 'ci')} (provider = icu, locale = 'und-u-ks-level1', deterministic = false);",
        f"CREATE TABLE {_qualified(LEGACY_SCHEMA, 'auditoria_nul')} (\"tabela\" TEXT NOT NULL, \"coluna\" TEXT NOT NULL, \"pk\" TEXT NOT NULL, \"valor_original\" BYTEA NOT NULL, \"migrado_em\" TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp());",
        f"REVOKE ALL ON TABLE {_qualified(LEGACY_SCHEMA, 'auditoria_nul')} FROM PUBLIC;",
    ]
    table_header = list(table_statements)
    table_statements = []
    autoincrement_entries: list[dict[str, Any]] = []
    on_update_groups: dict[str, list[str]] = {}
    emitted_tables: set[str] = set()
    emitted_unique_keys: set[tuple[str, tuple[str, ...]]] = set()
    emitted_column_types: dict[tuple[str, str], tuple[str, str | None]] = {}
    identity_columns: list[dict[str, Any]] = []
    primary_keys_post_load: list[dict[str, Any]] = []
    unique_indexes_post_load: list[dict[str, Any]] = []
    for table_name in sorted(table_by_name):
        table = table_by_name[table_name]
        target_schema = _schema_for_table(table_name)
        report["table_schemas"][table_name] = target_schema
        if target_schema == LEGACY_SCHEMA:
            report["legacy_backup_tables"].append(table_name)
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
                data_type = str(column["data_type"])
                generated_spec = target_generated_column(table_name, column)
                if (column.get("generated_expression_present") is True or declares_generated_column(column)) and generated_spec is None:
                    raise TypeMappingError("GENERATED_COLUMN_TRANSLATION_REQUIRED")
                if generated_spec is not None:
                    if not generated_dependencies_valid(table_name, column, columns):
                        raise TypeMappingError("GENERATED_COLUMN_DEPENDENCY_UNSUPPORTED")
                    if data_type.lower() != "decimal" or column.get("default") is not None:
                        raise TypeMappingError("GENERATED_COLUMN_METADATA_UNSUPPORTED")
                target_collation = _target_collation(column, overrides, table_name, report) if _textual(data_type) else None
                mapping = map_mysql_type(
                    str(column["column_type"]),
                    target_collation=target_collation,
                    column_name=name,
                    no_backslash_escapes=bool(snapshot.get("source_no_backslash_escapes", False)),
                    unsigned_bigint_exceeds_signed_limit=unsigned_bigint_flags.get((table_name, name)),
                )
                table_column_types[name] = (mapping.sql_type, mapping.collation_sql)
                declaration = f"{_ident(name)} {mapping.declaration}"
                extra = str(column.get("extra", "")).lower()
                if generated_spec is not None:
                    declaration += f" GENERATED ALWAYS AS ({generated_spec['target_expression']}) STORED"
                    report["generated_columns"].append({
                        "table": table_name,
                        "column": name,
                        "key": generated_spec["key"],
                        "dependencies": list(generated_spec["dependencies"]),
                    })
                    report["generated"]["generated_columns"] += 1
                elif "auto_increment" in extra:
                    if mapping.sql_type == "NUMERIC(20,0)":
                        sequence_key = f"{target_schema}.{table_name}.{name}"
                        table_allocators.append({
                            "key": sequence_key,
                            "low_sequence": _generated_name("p2_u64_lo", table_name, name),
                            "high_sequence": _generated_name("p2_u64_hi", table_name, name),
                        })
                        declaration += f" DEFAULT {_qualified(TARGET_SCHEMA, UNSIGNED_IDENTITY_FUNCTION)}({quote_literal(sequence_key)})"
                    else:
                        declaration += " GENERATED BY DEFAULT AS IDENTITY"
                nullable = bool(column.get("nullable", True))
                if data_type.lower() in {"date", "datetime", "timestamp"}:
                    if not nullable:
                        report["zero_date_nullable_columns"].append({"table": table_name, "column": name})
                    nullable = True
                if not nullable:
                    declaration += " NOT NULL"
                default_sql = None if generated_spec is not None else _sql_default(
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
                    labels = parse_mysql_enum(
                        str(column["column_type"]),
                        no_backslash_escapes=bool(snapshot.get("source_no_backslash_escapes", False)),
                    )
                    report["generated"]["enum_checks"] += 1
                    report["enum_checks_deferred"].append({
                        "table": table_name,
                        "column": name,
                        "declared_label_count": len(labels),
                        "empty_label_included": "" in labels,
                        "constraint_name": _generated_name("ck_enum", table_name, name),
                        "status": "CHECK_AFTER_VALUE_SCAN",
                    })
                report["review_items"].extend({"table": table_name, "column": name, "code": code} for code in mapping.review_codes)
                if "on update current_timestamp" in extra and generated_spec is None:
                    if data_type.lower() not in {"timestamp", "datetime"}:
                        report["review_items"].append({"table": table_name, "column": name, "code": "ON_UPDATE_NON_TEMPORAL_TYPE"})
                    else:
                        on_update_groups.setdefault(table_name, []).append(name)
            if pk_columns:
                for name in pk_columns:
                    if name not in {str(column["name"]) for column in columns}:
                        raise TypeMappingError("PRIMARY_KEY_COLUMN_NOT_FOUND")
                pk_name = "PRIMARY" if len("PRIMARY") <= MAX_IDENTIFIER_BYTES else "pk_" + hashlib.sha256(table_name.encode()).hexdigest()[:16]
                primary_keys_post_load.append({"table": table_name, "columns": pk_columns, "target_schema": target_schema, "constraint_name": pk_name})
                report["primary_keys_pending_collision_scan"].append({
                    "table": table_name,
                    "columns": pk_columns,
                    "target_schema": target_schema,
                    "code": "PRIMARY_KEY_CI_COLLISION_SCAN_REQUIRED" if _key_requires_collision_scan(table, pk_columns, overrides) else "PRIMARY_KEY_POST_LOAD_VALIDATION",
                })
                report["generated"]["primary_keys"] += 1
            table_statements.append(f"CREATE TABLE {_qualified(target_schema, table_name)} (\n  " + ",\n  ".join(column_defs) + "\n);")
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
                    autoincrement_entries.append({"key": f"{target_schema}.{table_name}.{auto_name}"})
                identity_columns.append({
                    "table": table_name,
                    "target_schema": target_schema,
                    "column": auto_name,
                    "source_next_auto_increment": next_value,
                    "strategy": strategy,
                    **(next((item for item in table_allocators if item["key"] == f"{target_schema}.{table_name}.{auto_name}"), {}) if strategy == "unsigned_sequence_pair" else {}),
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
    if application_role is not None:
        app_role = _ident(application_role)
        runtime_tables = [
            _qualified(TARGET_SCHEMA, name)
            for name in sorted(emitted_tables)
            if report["table_schemas"].get(name) == TARGET_SCHEMA
        ]
        table_statements.extend([
            f"REVOKE ALL ON ALL TABLES IN SCHEMA {_ident(TARGET_SCHEMA)} FROM PUBLIC;",
            f"REVOKE ALL ON ALL SEQUENCES IN SCHEMA {_ident(TARGET_SCHEMA)} FROM PUBLIC;",
            f"GRANT USAGE ON SCHEMA {_ident(TARGET_SCHEMA)} TO {app_role};",
            *([f"GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE {', '.join(runtime_tables)} TO {app_role};"] if runtime_tables else []),
            f"GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA {_ident(TARGET_SCHEMA)} TO {app_role};",
            f"REVOKE ALL ON SCHEMA {_ident(LEGACY_SCHEMA)} FROM {app_role};",
            f"REVOKE ALL ON ALL TABLES IN SCHEMA {_ident(LEGACY_SCHEMA)} FROM {app_role};",
            f"REVOKE ALL ON ALL SEQUENCES IN SCHEMA {_ident(LEGACY_SCHEMA)} FROM {app_role};",
            f"REVOKE ALL ON TABLE {_qualified(LEGACY_SCHEMA, 'auditoria_nul')} FROM {app_role};",
        ])
        if autoincrement_entries:
            table_statements.append(f"REVOKE ALL ON TABLE {_qualified(TARGET_SCHEMA, AUTOINCREMENT_STATE_STORE)} FROM {app_role};")
        unsigned_sequences = [
            _qualified(str(item.get("target_schema", TARGET_SCHEMA)), str(sequence_name))
            for item in autoincrement_entries
            if item.get("target_schema", TARGET_SCHEMA) == TARGET_SCHEMA
            for sequence_name in (item.get("low_sequence"), item.get("high_sequence"))
            if sequence_name
        ]
        if unsigned_sequences:
            table_statements.append(f"REVOKE ALL ON SEQUENCE {', '.join(unsigned_sequences)} FROM {app_role};")
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
            table_schema = _schema_for_table(table_name)
            trigger_statements.append(
                f"CREATE FUNCTION {_qualified(table_schema, 'fn_on_update_' + digest)}() RETURNS trigger\n"
                "LANGUAGE plpgsql AS $$\nBEGIN\n"
                f"  IF to_jsonb(NEW) - {excluded} IS DISTINCT FROM to_jsonb(OLD) - {excluded} THEN\n"
                f"{assignments}\n"
                "  END IF;\n  RETURN NEW;\nEND;\n$$;"
            )
            trigger_statements.append(
                f"CREATE TRIGGER {trigger_name} BEFORE UPDATE ON {_qualified(table_schema, table_name)}\n"
                f"FOR EACH ROW EXECUTE FUNCTION {_qualified(table_schema, 'fn_on_update_' + digest)}();"
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
            index_type = str(index.get("type", "BTREE")).upper()
            if index_type not in {"BTREE", "FULLTEXT"}:
                raise TypeMappingError("INDEX_METHOD_REVIEW_REQUIRED")
            parts: list[str] = []
            prefix_index = False
            for part in columns:
                if part.get("expression_present") or not part.get("name"):
                    raise TypeMappingError("FUNCTIONAL_INDEX_REVIEW_REQUIRED")
                direction = str(part.get("direction", "ASC")).upper()
                if direction not in {"ASC", "DESC"}:
                    raise TypeMappingError("INDEX_DIRECTION_INVALID")
                column_name = str(part["name"])
                if index_type == "FULLTEXT":
                    column_meta = next((column for column in table_by_name[table_name].get("columns", []) if str(column.get("name")) == column_name), None)
                    if column_meta is None or not _textual(str(column_meta.get("data_type", ""))):
                        raise TypeMappingError("FULLTEXT_COLUMN_NOT_TEXTUAL")
                    parts.append(f"COALESCE({_ident(column_name)}::text, '')")
                    continue
                prefix_length = part.get("prefix_length")
                if prefix_length is not None:
                    if isinstance(prefix_length, bool) or not isinstance(prefix_length, int) or prefix_length < 1:
                        raise TypeMappingError("INDEX_PREFIX_LENGTH_INVALID")
                    column_meta = next((column for column in table_by_name[table_name].get("columns", []) if str(column.get("name")) == column_name), None)
                    if column_meta is None:
                        raise TypeMappingError("INDEX_COLUMN_NOT_FOUND")
                    column_type = str(column_meta.get("data_type", "")).lower()
                    if _textual(column_type):
                        expression = f"left({_ident(column_name)}::text, {prefix_length})"
                    elif column_type in {"tinyblob", "blob", "mediumblob", "longblob", "binary", "varbinary"}:
                        expression = f"substring({_ident(column_name)} from 1 for {prefix_length})"
                    else:
                        raise TypeMappingError("INDEX_PREFIX_COLUMN_TYPE_UNSUPPORTED")
                    parts.append(f"({expression}) {direction}")
                    prefix_index = True
                else:
                    parts.append(f"{_ident(column_name)} {direction}")
            unique = not bool(index.get("non_unique", True))
            if index_type == "FULLTEXT" and unique:
                raise TypeMappingError("UNIQUE_FULLTEXT_UNSUPPORTED")
            generated_name = _generated_name("ix", table_name, index_name)
            if index_type == "FULLTEXT":
                search_vector = " || ' ' || ".join(parts)
                statement = f"CREATE INDEX {_ident(generated_name)} ON {_qualified(_schema_for_table(table_name), table_name)} USING GIN (to_tsvector('portuguese', {search_vector}));"
            elif unique:
                statement = ""
                unique_indexes_post_load.append({
                    "table": table_name,
                    "index": index_name,
                    "target_schema": _schema_for_table(table_name),
                    "generated_name": generated_name,
                    "columns": [dict(part) for part in columns],
                    "prefix_index": prefix_index,
                    "ci_collision_scan_required": _key_requires_collision_scan(
                        table_by_name[table_name], [str(part["name"]) for part in columns], overrides
                    ),
                })
            else:
                statement = f"CREATE INDEX {_ident(generated_name)} ON {_qualified(_schema_for_table(table_name), table_name)} ({', '.join(parts)});"
            if statement:
                index_statements.append(statement)
            report["generated"]["indexes"] += 1
            if unique:
                report["generated"]["unique_indexes"] += 1
                key_columns = [str(p["name"]) for p in columns]
                if not prefix_index:
                    emitted_unique_keys.add((table_name, tuple(key_columns)))
                unique_pending.append({
                    "table": table_name,
                    "index": index_name,
                    "columns": key_columns,
                    "target_schema": _schema_for_table(table_name),
                    "prefix_lengths": [part.get("prefix_length") for part in columns],
                    "code": "UNIQUE_COLLISION_SCAN_REQUIRED" if _key_requires_collision_scan(table_by_name[table_name], key_columns, overrides) else "UNIQUE_POST_LOAD_VALIDATION",
                })
        except (TypeMappingError, KeyError, ValueError) as error:
            code = error.code if isinstance(error, TypeMappingError) else "INDEX_METADATA_INVALID"
            report["unsupported_indexes"].append({"table": table_name, "index": index_name, "code": code})

    fk_statements = [f"-- Generator version: {GENERATOR_VERSION}", f"-- Catalog SHA-256: {fingerprint}", f"SET search_path TO {_ident(TARGET_SCHEMA)}, {_ident(LEGACY_SCHEMA)}, pg_catalog;"]
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
            report["foreign_keys_post_load"].append({
                "table": table_name,
                "name": name,
                "columns": source_columns,
                "referenced_table": ref_table,
                "referenced_columns": target_columns,
                "target_schema": _schema_for_table(table_name),
                "referenced_schema": _schema_for_table(ref_table),
                "update_rule": update_rule,
                "delete_rule": delete_rule,
                "constraint_name": _generated_name("fk", table_name, name),
            })
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
    report["primary_keys_post_load"] = primary_keys_post_load
    report["unique_indexes_post_load"] = unique_indexes_post_load
    report["auto_increment"]["generated"] = len(identity_columns)
    report["auto_increment"]["columns"] = identity_columns
    unique_reviews: dict[tuple[str, ...], dict[str, Any]] = {}
    for item in report["review_items"]:
        key = tuple(str(item.get(field, "")) for field in (
            "table", "column", "object_type", "constraint", "code", "definition_sha256"
        ))
        unique_reviews.setdefault(key, item)
    report["review_items"] = list(unique_reviews.values())
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
    if business_triggers_approved:
        if BUSINESS_TRIGGER_SCRIPT.is_symlink() or not BUSINESS_TRIGGER_SCRIPT.is_file():
            raise DdlGenerationError("APPROVED_BUSINESS_TRIGGER_SCRIPT_MISSING")
        business_trigger_sql = BUSINESS_TRIGGER_SCRIPT.read_text(encoding="utf-8")
        if len(re.findall(r"\bCREATE\s+TRIGGER\b", business_trigger_sql, flags=re.IGNORECASE)) != 7:
            raise DdlGenerationError("APPROVED_BUSINESS_TRIGGER_SCRIPT_INVALID")
        scripts["40_business_triggers.sql"] = business_trigger_sql
        report["translated_business_triggers"] = [
            {"table": table, "trigger": name, "timing": timing, "event": event,
             "source_body_sha256": body_sha256}
            for (table, name, timing, event), body_sha256 in sorted(BUSINESS_TRIGGER_SOURCE_SHA256.items())
        ]
    else:
        scripts["40_business_triggers.sql"] = "-- No approved translation for the current source trigger catalog.\n"
        report["translated_business_triggers"] = []
    scripts = {name: f"-- Collation overrides SHA-256: {overrides_sha256}\n\n{content}" for name, content in scripts.items()}
    report["generated_tables"] = sorted(emitted_tables)
    non_trigger_reviews = [item for item in report["review_items"] if item.get("code") != "BUSINESS_TRIGGER_TRANSLATION_REQUIRED"]
    report["load_executable_complete"] = (
        not report["blocked_tables"]
        and not report["unsupported_indexes"]
        and not report["deferred_indexes"]
        and not report["foreign_keys"]["unsupported"]
        and not report["foreign_keys"]["deferred"]
        and not non_trigger_reviews
    )
    report["executable_complete"] = report["load_executable_complete"] and not trigger_translation_items
    report["post_load_trigger_translation_required"] = trigger_translation_items
    return DdlBundle(scripts=scripts, report=report)


def canonical_fingerprint(snapshot: dict[str, Any]) -> str:
    copied = dict(snapshot)
    copied.pop("fingerprint_sha256", None)
    raw = json.dumps(copied, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(raw).hexdigest()
