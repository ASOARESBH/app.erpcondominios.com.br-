#!/usr/bin/env python3
"""Generate metadata-only data-quality CSVs from an authorized JSON snapshot.

This module deliberately has no database driver, network client, SQL text, or
external command execution. It accepts only a strict metadata/aggregate contract and
fails closed when row data, cell values, or unsafe/unknown fields are present.
"""

from __future__ import annotations

import argparse
import csv
import ctypes
import errno
import hashlib
import json
import os
import re
import stat
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable, Mapping, Sequence


EXPECTED_TABLES = 270
EXPECTED_WITH_TENANT = 228
EXPECTED_WITHOUT_TENANT = 42
EXPECTED_VIEW_LIKE_TOTAL = 22
EXPECTED_VIEW_LIKE_WITH_TENANT = 4
EXPECTED_VIEW_LIKE_WITHOUT_TENANT = 18
EXPECTED_OTHER_WITHOUT_TENANT = 24
MAX_INPUT_BYTES = 64 * 1024 * 1024

IDENTIFIER_RE = re.compile(r"^[A-Za-z_][A-Za-z0-9_$]*$")
SHA256_RE = re.compile(r"^[0-9a-fA-F]{64}$")
ISO_UTC_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$")

QUALITY_HEADER = [
    "table_name",
    "subject_type",
    "subject_name",
    "column_name",
    "constraint_kind",
    "parent_table_name",
    "row_count",
    "null_count",
    "duplicate_count",
    "orphan_candidate_count",
    "pk_null_count_total",
    "pk_duplicate_count_total",
    "unique_null_count_total",
    "unique_duplicate_count_total",
    "orphan_candidate_count_total",
    "min_status",
    "max_status",
    "tenant_id_present",
    "tenant_id_null_count",
    "tenant_id_schema_default_1_status",
    "tenant_id_default_1_row_count",
    "enum_status",
    "enum_invalid_count",
    "date_zero_status",
    "date_zero_count",
    "overflow_status",
    "overflow_count",
    "json_status",
    "json_invalid_count",
    "attachment_reference_status",
    "attachment_reference_count",
    "tenant_classification",
    "source_status",
]

TENANT_HEADER = [
    "table_name",
    "view_like",
    "has_tenant_id",
    "tenant_id_column",
    "tenant_id_schema_default_1_status",
    "tenant_id_null_count",
    "tenant_id_default_1_row_count",
    "tenant_classification",
    "classification_basis",
    "source_status",
]

STATUS_VALUES = {
    "min_max": {"observed", "not_observed", "not_applicable", "unknown"},
    "quality": {"checked", "candidates", "not_observed", "not_applicable", "unknown"},
    "attachment": {"checked_present", "checked_absent", "not_observed", "unknown"},
    "orphan": {"candidates", "checked_none", "not_observed", "unknown"},
    "tenant_default": {"present", "absent", "unknown", "not_applicable"},
}

TABLE_KEYS = {
    "table_name",
    "view_like",
    "row_count",
    "tenant",
    "columns",
    "constraints",
    "orphan_candidates",
    "attachment_references",
}
TENANT_KEYS = {
    "has_tenant_id",
    "tenant_id_column",
    "schema_default_1_status",
    "null_count",
    "default_1_row_count",
}
COLUMN_KEYS = {
    "column_name",
    "type_family",
    "null_count",
    "duplicate_count",
    "min_status",
    "max_status",
    "enum_status",
    "enum_invalid_count",
    "date_zero_status",
    "date_zero_count",
    "overflow_status",
    "overflow_count",
    "json_status",
    "json_invalid_count",
    "attachment_reference_status",
    "attachment_reference_count",
}
CONSTRAINT_KEYS = {"constraint_name", "kind", "null_count", "duplicate_count"}
ORPHAN_KEYS = {"relation_name", "parent_table_name", "candidate_count", "status"}
ATTACHMENT_KEYS = {"column_name", "status", "reference_count"}
SNAPSHOT_KEYS = {
    "kind",
    "captured_at",
    "source_sha256",
    "contains_row_data",
    "contains_cell_values",
    "contains_sql",
    "contains_personal_data",
}
INVENTORY_KEYS = {"tables"}

# These names are rejected at every level before the strict contract check. The
# explicit list is intentionally broader than the contract to fail closed if a
# producer tries to sneak row/cell payloads into an otherwise valid snapshot.
FORBIDDEN_KEY_FRAGMENTS = {
    "row",
    "rows",
    "record",
    "records",
    "value",
    "values",
    "sample",
    "samples",
    "payload",
    "content",
    "pii",
    "personal",
    "raw",
    "cell",
}


class BundlePublishedDurabilityUncertain(OSError):
    """O bundle foi publicado, mas o fsync do diretório pai falhou."""


def fail(message: str) -> "NoReturn":
    raise ValueError(message)


def ensure_mapping(value: Any, path: str) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        fail(f"{path} deve ser objeto JSON")
    return value


def ensure_exact_keys(value: Mapping[str, Any], allowed: set[str], path: str) -> None:
    unknown = sorted(set(value) - allowed)
    if unknown:
        fail(f"{path} contém chaves não permitidas: {', '.join(unknown)}")


def json_object_without_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            fail(f"JSON contém chave duplicada: {key}")
        result[key] = value
    return result


def reject_forbidden_keys(value: Any, path: str = "$", allow_key: bool = False) -> None:
    """Reject suspicious keys before any semantic parsing occurs."""
    if isinstance(value, Mapping):
        for key, child in value.items():
            if not isinstance(key, str):
                fail(f"{path} contém chave não textual")
            normalized = key.lower()
            if not allow_key and any(fragment in normalized for fragment in FORBIDDEN_KEY_FRAGMENTS):
                # Known safe aggregate field names are allowed explicitly below.
                safe = {
                    "row_count",
                    "null_count",
                    "duplicate_count",
                    "candidate_count",
                    "default_1_row_count",
                    "reference_count",
                    "contains_row_data",
                    "contains_cell_values",
                    "contains_personal_data",
                    "column_name",
                    "parent_table_name",
                    "table_name",
                    "constraint_name",
                    "relation_name",
                    "source_sha256",
                }
                if key not in safe:
                    fail(f"{path}.{key} é incompatível com metadata-only")
            reject_forbidden_keys(child, f"{path}.{key}")
    elif isinstance(value, list):
        for index, child in enumerate(value):
            reject_forbidden_keys(child, f"{path}[{index}]")


def require_string(obj: Mapping[str, Any], key: str, path: str, *, allow_null: bool = False) -> str | None:
    if key not in obj:
        fail(f"{path}.{key} é obrigatório")
    value = obj[key]
    if value is None and allow_null:
        return None
    if not isinstance(value, str) or not value:
        fail(f"{path}.{key} deve ser texto não vazio")
    return value


def optional_count(obj: Mapping[str, Any], key: str, path: str) -> int | None:
    if key not in obj or obj[key] is None:
        return None
    value = obj[key]
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        fail(f"{path}.{key} deve ser inteiro não negativo ou null")
    return value


def require_count(obj: Mapping[str, Any], key: str, path: str) -> int:
    value = optional_count(obj, key, path)
    if value is None:
        fail(f"{path}.{key} é obrigatório quando o status indica observação")
    return value


def require_bool(obj: Mapping[str, Any], key: str, path: str) -> bool:
    value = obj.get(key)
    if not isinstance(value, bool):
        fail(f"{path}.{key} deve ser booleano")
    return value


def validate_identifier(value: str | None, path: str, *, allow_null: bool = False) -> str | None:
    if value is None and allow_null:
        return None
    if not isinstance(value, str) or not IDENTIFIER_RE.fullmatch(value):
        fail(f"{path} não é um identificador simples permitido")
    return value


def require_status(obj: Mapping[str, Any], key: str, path: str, group: str) -> str:
    value = require_string(obj, key, path)
    if value not in STATUS_VALUES[group]:
        fail(f"{path}.{key} tem status inválido: {value}")
    return value


def validate_status_count(
    obj: Mapping[str, Any],
    status_key: str,
    count_key: str,
    path: str,
    group: str,
    *,
    count_for: set[str],
) -> tuple[str, int | None]:
    status = require_status(obj, status_key, path, group)
    count = optional_count(obj, count_key, path)
    if status in count_for and count is None:
        fail(f"{path}.{count_key} é obrigatório para {status_key}={status}")
    if status not in count_for and count is not None:
        fail(f"{path}.{count_key} deve ser null para {status_key}={status}")
    return status, count


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def iso_utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def aggregate_status(values: Iterable[str], *, family: str) -> str:
    statuses = list(values)
    if not statuses:
        return "not_observed"
    if family == "quality":
        if "candidates" in statuses:
            return "candidates"
        if "checked" in statuses:
            return "checked"
        if "unknown" in statuses:
            return "unknown"
        if "not_applicable" in statuses and all(s == "not_applicable" for s in statuses):
            return "not_applicable"
        return "not_observed"
    if family == "attachment":
        if "checked_present" in statuses:
            return "checked_present"
        if "unknown" in statuses:
            return "unknown"
        if "checked_absent" in statuses:
            return "checked_absent"
        return "not_observed"
    if family == "orphan":
        if "candidates" in statuses:
            return "candidates"
        if "unknown" in statuses:
            return "unknown"
        if "checked_none" in statuses:
            return "checked_none"
        return "not_observed"
    # min/max family
    if "observed" in statuses:
        return "observed"
    if "unknown" in statuses:
        return "unknown"
    if "not_applicable" in statuses and all(s == "not_applicable" for s in statuses):
        return "not_applicable"
    return "not_observed"


def sum_counts(values: Iterable[int | None]) -> int | None:
    present = [value for value in values if value is not None]
    return sum(present) if present else None


def csv_value(value: Any) -> Any:
    """Keep aggregate zero visible; use empty only for an unobserved null."""
    return "" if value is None else value


def source_status(values: Iterable[Any]) -> str:
    return "aggregate_observed" if any(value is not None for value in values) else "metadata_only"


def validate_snapshot(document: Any) -> list[dict[str, Any]]:
    root = ensure_mapping(document, "$")
    reject_forbidden_keys(root)
    ensure_exact_keys(root, {"schema_version", "snapshot", "inventory"}, "$")
    if type(root.get("schema_version")) is not int or root.get("schema_version") != 1:
        fail("schema_version deve ser 1")

    snapshot = ensure_mapping(root.get("snapshot"), "$.snapshot")
    ensure_exact_keys(snapshot, SNAPSHOT_KEYS, "$.snapshot")
    if snapshot.get("kind") != "metadata_only_aggregates":
        fail("snapshot.kind deve ser metadata_only_aggregates")
    captured_at = require_string(snapshot, "captured_at", "$.snapshot")
    if not ISO_UTC_RE.fullmatch(captured_at or ""):
        fail("snapshot.captured_at deve estar em UTC ISO-8601 terminado em Z")
    try:
        parsed_at = datetime.fromisoformat((captured_at or "").replace("Z", "+00:00"))
    except ValueError:
        fail("snapshot.captured_at não é uma data/hora válida")
    if parsed_at.utcoffset() is None or parsed_at.utcoffset().total_seconds() != 0:
        fail("snapshot.captured_at deve usar UTC")
    declared_sha = require_string(snapshot, "source_sha256", "$.snapshot")
    if not SHA256_RE.fullmatch(declared_sha or ""):
        fail("snapshot.source_sha256 deve ser SHA-256 hexadecimal")
    for key in ("contains_row_data", "contains_cell_values", "contains_sql", "contains_personal_data"):
        if snapshot.get(key) is not False:
            fail(f"snapshot.{key} deve ser false")

    inventory = ensure_mapping(root.get("inventory"), "$.inventory")
    ensure_exact_keys(inventory, INVENTORY_KEYS, "$.inventory")
    tables_value = inventory.get("tables")
    if not isinstance(tables_value, list):
        fail("$.inventory.tables deve ser uma lista")
    if len(tables_value) != EXPECTED_TABLES:
        fail(f"cobertura rejeitada: esperado {EXPECTED_TABLES} tabelas, recebido {len(tables_value)}")

    tables: list[dict[str, Any]] = []
    names: set[str] = set()
    for index, raw_table in enumerate(tables_value):
        path = f"$.inventory.tables[{index}]"
        table = ensure_mapping(raw_table, path)
        ensure_exact_keys(table, TABLE_KEYS, path)
        table_name = validate_identifier(require_string(table, "table_name", path), f"{path}.table_name")
        if table_name in names:
            fail(f"tabela duplicada: {table_name}")
        names.add(table_name)
        view_like = require_bool(table, "view_like", path)
        row_count = optional_count(table, "row_count", path)

        tenant = ensure_mapping(table.get("tenant"), f"{path}.tenant")
        ensure_exact_keys(tenant, TENANT_KEYS, f"{path}.tenant")
        has_tenant = require_bool(tenant, "has_tenant_id", f"{path}.tenant")
        tenant_column = validate_identifier(
            require_string(tenant, "tenant_id_column", f"{path}.tenant", allow_null=True),
            f"{path}.tenant.tenant_id_column",
            allow_null=True,
        )
        default_status = require_status(tenant, "schema_default_1_status", f"{path}.tenant", "tenant_default")
        tenant_null_count = optional_count(tenant, "null_count", f"{path}.tenant")
        tenant_default_1_count = optional_count(tenant, "default_1_row_count", f"{path}.tenant")
        if has_tenant:
            if tenant_column != "tenant_id":
                fail(f"{path}.tenant.tenant_id_column deve ser tenant_id quando presente")
            if default_status == "not_applicable":
                fail(f"{path}.tenant.schema_default_1_status não pode ser not_applicable quando presente")
        else:
            if tenant_column is not None or default_status != "not_applicable":
                fail(f"{path}.tenant sem tenant_id deve usar coluna null e status not_applicable")
            if tenant_null_count is not None or tenant_default_1_count is not None:
                fail(f"{path}.tenant sem tenant_id não pode carregar contagens de tenant")

        raw_columns = table.get("columns")
        if not isinstance(raw_columns, list):
            fail(f"{path}.columns deve ser uma lista")
        columns: list[dict[str, Any]] = []
        column_names: set[str] = set()
        for c_index, raw_column in enumerate(raw_columns):
            c_path = f"{path}.columns[{c_index}]"
            column = ensure_mapping(raw_column, c_path)
            ensure_exact_keys(column, COLUMN_KEYS, c_path)
            column_name = validate_identifier(require_string(column, "column_name", c_path), f"{c_path}.column_name")
            if column_name in column_names:
                fail(f"coluna duplicada em {table_name}: {column_name}")
            column_names.add(column_name)
            type_family = require_string(column, "type_family", c_path)
            allowed_families = {"integer", "numeric", "date", "timestamp", "json", "text", "enum", "attachment_ref", "other"}
            if type_family not in allowed_families:
                fail(f"{c_path}.type_family inválido: {type_family}")
            null_count = optional_count(column, "null_count", c_path)
            duplicate_count = optional_count(column, "duplicate_count", c_path)
            min_status = require_status(column, "min_status", c_path, "min_max")
            max_status = require_status(column, "max_status", c_path, "min_max")
            enum_status, enum_invalid = validate_status_count(
                column, "enum_status", "enum_invalid_count", c_path, "quality", count_for={"checked", "candidates"}
            )
            date_status, date_zero = validate_status_count(
                column, "date_zero_status", "date_zero_count", c_path, "quality", count_for={"checked", "candidates"}
            )
            overflow_status, overflow = validate_status_count(
                column, "overflow_status", "overflow_count", c_path, "quality", count_for={"checked", "candidates"}
            )
            json_status, json_invalid = validate_status_count(
                column, "json_status", "json_invalid_count", c_path, "quality", count_for={"checked", "candidates"}
            )
            attachment_status, attachment_count = validate_status_count(
                column,
                "attachment_reference_status",
                "attachment_reference_count",
                c_path,
                "attachment",
                count_for={"checked_present", "checked_absent"},
            )
            if attachment_status == "checked_absent" and attachment_count != 0:
                fail(f"{c_path}.attachment_reference_count deve ser 0 para checked_absent")
            columns.append(
                {
                    "column_name": column_name,
                    "type_family": type_family,
                    "null_count": null_count,
                    "duplicate_count": duplicate_count,
                    "min_status": min_status,
                    "max_status": max_status,
                    "enum_status": enum_status,
                    "enum_invalid_count": enum_invalid,
                    "date_zero_status": date_status,
                    "date_zero_count": date_zero,
                    "overflow_status": overflow_status,
                    "overflow_count": overflow,
                    "json_status": json_status,
                    "json_invalid_count": json_invalid,
                    "attachment_reference_status": attachment_status,
                    "attachment_reference_count": attachment_count,
                }
            )
        if has_tenant != ("tenant_id" in column_names):
            fail(f"{path}.tenant.has_tenant_id diverge da lista de colunas")

        raw_constraints = table.get("constraints")
        if not isinstance(raw_constraints, list):
            fail(f"{path}.constraints deve ser uma lista")
        constraints: list[dict[str, Any]] = []
        constraint_names: set[str] = set()
        for k_index, raw_constraint in enumerate(raw_constraints):
            k_path = f"{path}.constraints[{k_index}]"
            constraint = ensure_mapping(raw_constraint, k_path)
            ensure_exact_keys(constraint, CONSTRAINT_KEYS, k_path)
            constraint_name = validate_identifier(require_string(constraint, "constraint_name", k_path), f"{k_path}.constraint_name")
            if constraint_name in constraint_names:
                fail(f"constraint duplicada em {table_name}: {constraint_name}")
            constraint_names.add(constraint_name)
            kind = require_string(constraint, "kind", k_path)
            if kind not in {"primary_key", "unique"}:
                fail(f"{k_path}.kind deve ser primary_key ou unique")
            constraints.append(
                {
                    "constraint_name": constraint_name,
                    "kind": kind,
                    "null_count": optional_count(constraint, "null_count", k_path),
                    "duplicate_count": optional_count(constraint, "duplicate_count", k_path),
                }
            )

        raw_orphans = table.get("orphan_candidates")
        if not isinstance(raw_orphans, list):
            fail(f"{path}.orphan_candidates deve ser uma lista")
        orphans: list[dict[str, Any]] = []
        for o_index, raw_orphan in enumerate(raw_orphans):
            o_path = f"{path}.orphan_candidates[{o_index}]"
            orphan = ensure_mapping(raw_orphan, o_path)
            ensure_exact_keys(orphan, ORPHAN_KEYS, o_path)
            relation_name = validate_identifier(require_string(orphan, "relation_name", o_path), f"{o_path}.relation_name")
            parent_table_name = validate_identifier(
                require_string(orphan, "parent_table_name", o_path), f"{o_path}.parent_table_name"
            )
            status = require_status(orphan, "status", o_path, "orphan")
            candidate_count = optional_count(orphan, "candidate_count", o_path)
            if status == "candidates" and candidate_count is None:
                fail(f"{o_path}.candidate_count obrigatório para status candidates")
            if status == "checked_none" and candidate_count != 0:
                fail(f"{o_path}.candidate_count deve ser 0 para status checked_none")
            if status in {"not_observed", "unknown"} and candidate_count is not None:
                fail(f"{o_path}.candidate_count deve ser null para status {status}")
            orphans.append(
                {
                    "relation_name": relation_name,
                    "parent_table_name": parent_table_name,
                    "status": status,
                    "candidate_count": candidate_count,
                }
            )

        raw_attachments = table.get("attachment_references")
        if not isinstance(raw_attachments, list):
            fail(f"{path}.attachment_references deve ser uma lista")
        attachments: list[dict[str, Any]] = []
        for a_index, raw_attachment in enumerate(raw_attachments):
            a_path = f"{path}.attachment_references[{a_index}]"
            attachment = ensure_mapping(raw_attachment, a_path)
            ensure_exact_keys(attachment, ATTACHMENT_KEYS, a_path)
            column_name = validate_identifier(require_string(attachment, "column_name", a_path), f"{a_path}.column_name")
            status = require_status(attachment, "status", a_path, "attachment")
            reference_count = optional_count(attachment, "reference_count", a_path)
            if status == "checked_present" and reference_count is None:
                fail(f"{a_path}.reference_count obrigatório para checked_present")
            if status == "checked_absent" and reference_count != 0:
                fail(f"{a_path}.reference_count deve ser 0 para checked_absent")
            if status in {"not_observed", "unknown"} and reference_count is not None:
                fail(f"{a_path}.reference_count deve ser null para status {status}")
            attachments.append({"column_name": column_name, "status": status, "reference_count": reference_count})

        tables.append(
            {
                "table_name": table_name,
                "view_like": view_like,
                "row_count": row_count,
                "tenant": {
                    "has_tenant_id": has_tenant,
                    "tenant_id_column": tenant_column,
                    "schema_default_1_status": default_status,
                    "null_count": tenant_null_count,
                    "default_1_row_count": tenant_default_1_count,
                },
                "columns": columns,
                "constraints": constraints,
                "orphan_candidates": orphans,
                "attachment_references": attachments,
            }
        )

    with_tenant = sum(1 for table in tables if table["tenant"]["has_tenant_id"])
    without_tenant = len(tables) - with_tenant
    view_like_total = sum(1 for table in tables if table["view_like"])
    view_like_with_tenant = sum(1 for table in tables if table["view_like"] and table["tenant"]["has_tenant_id"])
    view_like_without = sum(1 for table in tables if not table["tenant"]["has_tenant_id"] and table["view_like"])
    if with_tenant != EXPECTED_WITH_TENANT or without_tenant != EXPECTED_WITHOUT_TENANT:
        fail(
            "cobertura tenant rejeitada: "
            f"com tenant={with_tenant}, sem tenant={without_tenant}; "
            f"esperado {EXPECTED_WITH_TENANT}/{EXPECTED_WITHOUT_TENANT}"
        )
    if view_like_total != EXPECTED_VIEW_LIKE_TOTAL or view_like_with_tenant != EXPECTED_VIEW_LIKE_WITH_TENANT:
        fail(
            "partição view-like rejeitada: "
            f"total={view_like_total}, com tenant={view_like_with_tenant}; "
            f"esperado {EXPECTED_VIEW_LIKE_TOTAL}/{EXPECTED_VIEW_LIKE_WITH_TENANT}"
        )
    if view_like_without != EXPECTED_VIEW_LIKE_WITHOUT_TENANT:
        fail(
            f"cobertura view-like sem tenant rejeitada: esperado {EXPECTED_VIEW_LIKE_WITHOUT_TENANT}, "
            f"recebido {view_like_without}"
        )
    if without_tenant - view_like_without != EXPECTED_OTHER_WITHOUT_TENANT:
        fail("partição das 42 tabelas sem tenant não fecha em 18 view-like + 24 demais")
    return tables


def table_classification(table: Mapping[str, Any]) -> tuple[str, str]:
    if table["tenant"]["has_tenant_id"]:
        return "tenant_column_present_unverified", "tenant_id column presence only; isolation not proven"
    if table["view_like"]:
        return "no_tenant_column_view_like_pending", "no tenant_id; view-like name; contract classification pending"
    return "no_tenant_column_pending", "no tenant_id; module contract classification pending"


def table_aggregate_row(table: Mapping[str, Any]) -> dict[str, Any]:
    columns = table["columns"]
    constraints = table["constraints"]
    orphans = table["orphan_candidates"]
    attachments = table["attachment_references"]
    tenant = table["tenant"]
    classification, _ = table_classification(table)
    pk = [item for item in constraints if item["kind"] == "primary_key"]
    unique = [item for item in constraints if item["kind"] == "unique"]
    row = {key: "" for key in QUALITY_HEADER}
    row.update(
        {
            "table_name": table["table_name"],
            "subject_type": "table",
            "subject_name": table["table_name"],
            "row_count": table["row_count"] if table["row_count"] is not None else "",
            "pk_null_count_total": csv_value(sum_counts(item["null_count"] for item in pk)),
            "pk_duplicate_count_total": csv_value(sum_counts(item["duplicate_count"] for item in pk)),
            "unique_null_count_total": csv_value(sum_counts(item["null_count"] for item in unique)),
            "unique_duplicate_count_total": csv_value(sum_counts(item["duplicate_count"] for item in unique)),
            "orphan_candidate_count_total": csv_value(sum_counts(item["candidate_count"] for item in orphans)),
            "min_status": aggregate_status((item["min_status"] for item in columns), family="min_max"),
            "max_status": aggregate_status((item["max_status"] for item in columns), family="min_max"),
            "tenant_id_present": "true" if tenant["has_tenant_id"] else "false",
            "tenant_id_null_count": tenant["null_count"] if tenant["null_count"] is not None else "",
            "tenant_id_schema_default_1_status": tenant["schema_default_1_status"],
            "tenant_id_default_1_row_count": tenant["default_1_row_count"] if tenant["default_1_row_count"] is not None else "",
            "enum_status": aggregate_status((item["enum_status"] for item in columns), family="quality"),
            "enum_invalid_count": csv_value(sum_counts(item["enum_invalid_count"] for item in columns)),
            "date_zero_status": aggregate_status((item["date_zero_status"] for item in columns), family="quality"),
            "date_zero_count": csv_value(sum_counts(item["date_zero_count"] for item in columns)),
            "overflow_status": aggregate_status((item["overflow_status"] for item in columns), family="quality"),
            "overflow_count": csv_value(sum_counts(item["overflow_count"] for item in columns)),
            "json_status": aggregate_status((item["json_status"] for item in columns), family="quality"),
            "json_invalid_count": csv_value(sum_counts(item["json_invalid_count"] for item in columns)),
            "attachment_reference_status": aggregate_status(
                (item["status"] for item in attachments), family="attachment"
            ),
            "attachment_reference_count": csv_value(sum_counts(item["reference_count"] for item in attachments)),
            "tenant_classification": classification,
            "source_status": source_status(
                [
                    table["row_count"],
                    tenant["null_count"],
                    tenant["default_1_row_count"],
                    *(item["null_count"] for item in columns),
                    *(item["duplicate_count"] for item in columns),
                    *(item["null_count"] for item in constraints),
                    *(item["duplicate_count"] for item in constraints),
                    *(item["candidate_count"] for item in orphans),
                    *(item["reference_count"] for item in attachments),
                ]
            ),
        }
    )
    return row


def empty_quality_row(table_name: str, subject_type: str, subject_name: str) -> dict[str, Any]:
    row = {key: "" for key in QUALITY_HEADER}
    row.update({"table_name": table_name, "subject_type": subject_type, "subject_name": subject_name})
    return row


def build_quality_rows(tables: Sequence[Mapping[str, Any]]) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for table in tables:
        rows.append(table_aggregate_row(table))
        classification, _ = table_classification(table)
        tenant = table["tenant"]
        for column in table["columns"]:
            row = empty_quality_row(table["table_name"], "column", column["column_name"])
            row.update(
                {
                    "column_name": column["column_name"],
                    "null_count": column["null_count"] if column["null_count"] is not None else "",
                    "duplicate_count": column["duplicate_count"] if column["duplicate_count"] is not None else "",
                    "min_status": column["min_status"],
                    "max_status": column["max_status"],
                    "tenant_id_present": "true" if tenant["has_tenant_id"] else "false",
                    "tenant_classification": classification,
                    "enum_status": column["enum_status"],
                    "enum_invalid_count": column["enum_invalid_count"] if column["enum_invalid_count"] is not None else "",
                    "date_zero_status": column["date_zero_status"],
                    "date_zero_count": column["date_zero_count"] if column["date_zero_count"] is not None else "",
                    "overflow_status": column["overflow_status"],
                    "overflow_count": column["overflow_count"] if column["overflow_count"] is not None else "",
                    "json_status": column["json_status"],
                    "json_invalid_count": column["json_invalid_count"] if column["json_invalid_count"] is not None else "",
                    "attachment_reference_status": column["attachment_reference_status"],
                    "attachment_reference_count": column["attachment_reference_count"]
                    if column["attachment_reference_count"] is not None
                    else "",
                    "source_status": source_status(
                        [
                            column["null_count"],
                            column["duplicate_count"],
                            column["enum_invalid_count"],
                            column["date_zero_count"],
                            column["overflow_count"],
                            column["json_invalid_count"],
                            column["attachment_reference_count"],
                        ]
                    ),
                }
            )
            rows.append(row)
        for constraint in table["constraints"]:
            row = empty_quality_row(table["table_name"], "constraint", constraint["constraint_name"])
            row.update(
                {
                    "constraint_kind": constraint["kind"],
                    "null_count": constraint["null_count"] if constraint["null_count"] is not None else "",
                    "duplicate_count": constraint["duplicate_count"] if constraint["duplicate_count"] is not None else "",
                    "tenant_id_present": "true" if tenant["has_tenant_id"] else "false",
                    "tenant_classification": classification,
                    "source_status": source_status([constraint["null_count"], constraint["duplicate_count"]]),
                }
            )
            rows.append(row)
        for orphan in table["orphan_candidates"]:
            row = empty_quality_row(table["table_name"], "orphan_candidate", orphan["relation_name"])
            row.update(
                {
                    "parent_table_name": orphan["parent_table_name"],
                    "orphan_candidate_count": orphan["candidate_count"] if orphan["candidate_count"] is not None else "",
                    "orphan_candidate_count_total": orphan["candidate_count"] if orphan["candidate_count"] is not None else "",
                    "tenant_id_present": "true" if tenant["has_tenant_id"] else "false",
                    "tenant_classification": classification,
                    "source_status": "aggregate_observed" if orphan["candidate_count"] is not None else "metadata_only",
                }
            )
            rows.append(row)
        for attachment in table["attachment_references"]:
            row = empty_quality_row(table["table_name"], "attachment_reference", attachment["column_name"])
            row.update(
                {
                    "column_name": attachment["column_name"],
                    "attachment_reference_status": attachment["status"],
                    "attachment_reference_count": attachment["reference_count"]
                    if attachment["reference_count"] is not None
                    else "",
                    "tenant_id_present": "true" if tenant["has_tenant_id"] else "false",
                    "tenant_classification": classification,
                    "source_status": "aggregate_observed" if attachment["reference_count"] is not None else "metadata_only",
                }
            )
            rows.append(row)
    return rows


def build_tenant_rows(tables: Sequence[Mapping[str, Any]]) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    for table in tables:
        tenant = table["tenant"]
        classification, basis = table_classification(table)
        has_aggregate = tenant["null_count"] is not None or tenant["default_1_row_count"] is not None
        rows.append(
            {
                "table_name": table["table_name"],
                "view_like": "true" if table["view_like"] else "false",
                "has_tenant_id": "true" if tenant["has_tenant_id"] else "false",
                "tenant_id_column": tenant["tenant_id_column"] or "",
                "tenant_id_schema_default_1_status": tenant["schema_default_1_status"],
                "tenant_id_null_count": tenant["null_count"] if tenant["null_count"] is not None else "",
                "tenant_id_default_1_row_count": tenant["default_1_row_count"]
                if tenant["default_1_row_count"] is not None
                else "",
                "tenant_classification": classification,
                "classification_basis": basis,
                "source_status": "aggregate_observed" if has_aggregate else "metadata_only",
            }
        )
    return rows


def csv_bytes(header: Sequence[str], rows: Sequence[Mapping[str, Any]]) -> bytes:
    from io import StringIO

    buffer = StringIO(newline="")
    writer = csv.DictWriter(buffer, fieldnames=list(header), extrasaction="raise", lineterminator="\n")
    writer.writeheader()
    for row in rows:
        writer.writerow({key: row.get(key, "") for key in header})
    return buffer.getvalue().encode("utf-8")


def write_bytes_atomic(path: Path, content: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temp_name: str | None = None
    try:
        with tempfile.NamedTemporaryFile(dir=path.parent, prefix=f".{path.name}.", delete=False) as handle:
            temp_name = handle.name
            os.chmod(handle.fileno(), 0o600)
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temp_name, path)
        os.chmod(path, 0o600)
    except OSError:
        if temp_name:
            try:
                os.unlink(temp_name)
            except OSError:
                pass
        raise


def publish_bundle(output_dir: Path, artifacts: Mapping[str, bytes]) -> dict[str, str]:
    output_dir = Path(os.path.abspath(output_dir))
    repository_root = Path(__file__).resolve().parents[1]
    if output_dir.resolve().is_relative_to(repository_root):
        fail("output-dir não pode ficar dentro do checkout Git")
    parent = output_dir.parent
    if not parent.exists():
        parent.mkdir(mode=0o700)
    if parent.is_symlink() or not parent.is_dir():
        fail("o diretório pai deve ser um diretório privado real")
    if parent.resolve(strict=True) != parent:
        fail("caminho do diretório pai contém symlink")
    parent_stat = parent.stat()
    if hasattr(os, "geteuid") and parent_stat.st_uid != os.geteuid():
        fail("o diretório pai deve pertencer ao usuário executor")
    if stat.S_IMODE(parent_stat.st_mode) & 0o077:
        fail("o diretório pai deve negar acesso a grupo e outros usuários")
    if os.path.lexists(output_dir):
        fail("output-dir já existe; use um destino novo para evitar sobrescrita")

    staging_dir = Path(tempfile.mkdtemp(prefix=f".{output_dir.name}.stage-", dir=parent))
    os.chmod(staging_dir, 0o700)
    try:
        for name, content in artifacts.items():
            if Path(name).name != name:
                fail("nome de artefato inválido")
            write_bytes_atomic(staging_dir / name, content)
        try:
            renameat2 = getattr(ctypes.CDLL(None, use_errno=True), "renameat2", None)
            if renameat2 is None:
                fail("renameat2 não disponível; publicação no-replace indisponível")
            renameat2.argtypes = (ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint)
            renameat2.restype = ctypes.c_int
            if renameat2(-100, os.fsencode(staging_dir), -100, os.fsencode(output_dir), 1) != 0:
                error = ctypes.get_errno()
                if error in {errno.ENOSYS, errno.EINVAL, errno.EOPNOTSUPP}:
                    fail("filesystem/kernel sem suporte a renameat2(RENAME_NOREPLACE); bundle não publicado")
                raise OSError(error, os.strerror(error), str(output_dir))
        except BundlePublishedDurabilityUncertain:
            raise
        except OSError:
            raise
        try:
            parent_fd = os.open(parent, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0))
            try:
                os.fsync(parent_fd)
            finally:
                os.close(parent_fd)
        except OSError as exc:
            raise BundlePublishedDurabilityUncertain(
                f"bundle já foi publicado em {output_dir}; fsync/abertura do diretório falhou: {type(exc).__name__}"
            ) from exc
    except Exception:
        if staging_dir.exists():
            for child in staging_dir.iterdir():
                try:
                    child.unlink()
                except OSError:
                    pass
            try:
                staging_dir.rmdir()
            except OSError:
                pass
        raise
    return {name: str(output_dir / name) for name in artifacts}


def build_manifest(
    input_path: Path,
    input_sha256: str,
    declared_source_sha256: str,
    quality_path: Path,
    quality_bytes: bytes,
    tenant_path: Path,
    tenant_bytes: bytes,
    manifest_path: Path,
    tables: Sequence[Mapping[str, Any]],
    generator_path: Path,
) -> dict[str, Any]:
    with_tenant = sum(1 for table in tables if table["tenant"]["has_tenant_id"])
    without_tenant = len(tables) - with_tenant
    view_like_total = sum(1 for table in tables if table["view_like"])
    view_like_with_tenant = sum(1 for table in tables if table["tenant"]["has_tenant_id"] and table["view_like"])
    view_like_without = sum(1 for table in tables if not table["tenant"]["has_tenant_id"] and table["view_like"])
    return {
        "manifest_version": 1,
        "generator": {
            "name": generator_path.name,
            "sha256": sha256_file(generator_path),
        },
        "generated_at": iso_utc_now(),
        "input": {
            "file_sha256": input_sha256,
            "declared_source_sha256": declared_source_sha256,
            "schema_version": 1,
        },
        "policy": {
            "read_only": True,
            "database_accessed": False,
            "sql_generated": False,
            "rows_exported": False,
            "cell_values_exported": False,
            "min_max_values_exported": False,
            "personal_data_exported": False,
        },
        "coverage": {
            "tables_expected": EXPECTED_TABLES,
            "tables_profiled": len(tables),
            "tables_with_tenant_id": with_tenant,
            "tables_without_tenant_id": without_tenant,
            "view_like_total": view_like_total,
            "view_like_with_tenant_id": view_like_with_tenant,
            "view_like_without_tenant_id": view_like_without,
            "other_without_tenant_id": without_tenant - view_like_without,
        },
        "artifacts": [
            {
                "file": quality_path.name,
                "sha256": hashlib.sha256(quality_bytes).hexdigest(),
                "bytes": len(quality_bytes),
                "rows": len(build_quality_rows(tables)),
                "mode": "0600",
            },
            {
                "file": tenant_path.name,
                "sha256": hashlib.sha256(tenant_bytes).hexdigest(),
                "bytes": len(tenant_bytes),
                "rows": len(tables),
                "mode": "0600",
            },
        ],
        "manifest": {"file": manifest_path.name, "checksum_sidecar": f"{manifest_path.name}.sha256", "mode": "0600"},
        "permissions": {
            "input": "read permission required; no privilege escalation",
            "output": "new private output directory required; directory mode 0700 and file mode 0600",
            "publication": "all four artifacts are staged privately and published together by renameat2(RENAME_NOREPLACE)",
            "failure_mode": "pre-publication failures leave no final bundle; post-rename directory fsync failure raises an error while preserving the published bundle; verify manifest/checksum before retrying",
        },
    }


def parse_args(argv: Sequence[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", required=True, type=Path, help="snapshot JSON metadata-only autorizado")
    parser.add_argument("--expected-input-sha256", required=True, help="SHA-256 recebido pelo canal de aprovação")
    parser.add_argument("--output-dir", required=True, type=Path, help="diretório de saída dos quatro artefatos")
    return parser.parse_args(argv)


def run(
    input_path: Path,
    output_dir: Path,
    generator_path: Path,
    expected_input_sha256: str,
) -> dict[str, str]:
    if not input_path.is_file():
        fail(f"input não é arquivo legível: {input_path}")
    if not SHA256_RE.fullmatch(expected_input_sha256 or ""):
        fail("expected-input-sha256 deve ser SHA-256 hexadecimal")
    try:
        input_fd = os.open(input_path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        try:
            input_stat = os.fstat(input_fd)
            if not stat.S_ISREG(input_stat.st_mode):
                fail("snapshot deve ser arquivo regular, não symlink ou dispositivo")
            if input_stat.st_size > MAX_INPUT_BYTES:
                fail(f"snapshot excede o limite de {MAX_INPUT_BYTES} bytes")
            chunks = bytearray()
            while len(chunks) <= MAX_INPUT_BYTES:
                chunk = os.read(input_fd, min(1024 * 1024, MAX_INPUT_BYTES + 1 - len(chunks)))
                if not chunk:
                    break
                chunks.extend(chunk)
            input_bytes = bytes(chunks)
        finally:
            os.close(input_fd)
        if len(input_bytes) > MAX_INPUT_BYTES:
            fail(f"snapshot excede o limite de {MAX_INPUT_BYTES} bytes")
        input_sha256 = hashlib.sha256(input_bytes).hexdigest()
        if input_sha256.lower() != expected_input_sha256.lower():
            fail("SHA-256 do snapshot não corresponde ao valor aprovado")
        document = json.loads(
            input_bytes.decode("utf-8"),
            object_pairs_hook=json_object_without_duplicates,
        )
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        fail(f"não foi possível ler o snapshot metadata-only: {exc}")
    tables = validate_snapshot(document)
    root = ensure_mapping(document, "$")
    snapshot = ensure_mapping(root["snapshot"], "$.snapshot")

    quality_path = output_dir / "data_quality_aggregates.csv"
    tenant_path = output_dir / "tenant_profile.csv"
    manifest_path = output_dir / "quality_report_manifest.json"
    sidecar_path = output_dir / "quality_report_manifest.json.sha256"
    quality_bytes = csv_bytes(QUALITY_HEADER, build_quality_rows(tables))
    tenant_bytes = csv_bytes(TENANT_HEADER, build_tenant_rows(tables))

    manifest = build_manifest(
        input_path,
        input_sha256,
        snapshot["source_sha256"],
        quality_path,
        quality_bytes,
        tenant_path,
        tenant_bytes,
        manifest_path,
        tables,
        generator_path,
    )
    manifest_bytes = (json.dumps(manifest, ensure_ascii=False, indent=2, sort_keys=True) + "\n").encode("utf-8")
    manifest_sha256 = hashlib.sha256(manifest_bytes).hexdigest()

    artifacts = {
        quality_path.name: quality_bytes,
        tenant_path.name: tenant_bytes,
        manifest_path.name: manifest_bytes,
        sidecar_path.name: f"{manifest_sha256}  {manifest_path.name}\n".encode("ascii"),
    }
    return publish_bundle(output_dir, artifacts)


def main(argv: Sequence[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    try:
        outputs = run(
            args.input.absolute(),
            args.output_dir.absolute(),
            Path(__file__).resolve(),
            args.expected_input_sha256,
        )
    except (OSError, ValueError) as exc:
        print(f"ERRO: {exc}", file=sys.stderr)
        return 2
    for name, path in outputs.items():
        print(f"{name}: {path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
