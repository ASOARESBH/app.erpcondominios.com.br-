from __future__ import annotations

import re
from typing import Any

from .type_mapping import quote_identifier


_GENERATED_COLUMN_SPECS: dict[tuple[str, str], dict[str, Any]] = {
    ("lancamentos_agua", "consumo"): {
        "key": "lancamentos_agua.consumo.v1",
        "data_type": "decimal",
        "column_type": "decimal(10,2)",
        "extra": "stored generated",
        "source_expression": "leitura_atual-leitura_anterior",
        "target_expression": f"{quote_identifier('leitura_atual')} - {quote_identifier('leitura_anterior')}",
        "dependencies": ("leitura_atual", "leitura_anterior"),
    },
}


def _normalize_expression(expression: str) -> str:
    normalized = expression.strip().casefold().replace("`", "").replace('"', "")
    normalized = re.sub(r"\s+", "", normalized)
    while normalized.startswith("(") and normalized.endswith(")"):
        depth = 0
        encloses_all = True
        for index, character in enumerate(normalized):
            if character == "(":
                depth += 1
            elif character == ")":
                depth -= 1
                if depth == 0 and index != len(normalized) - 1:
                    encloses_all = False
                    break
                if depth < 0:
                    encloses_all = False
                    break
        if depth != 0 or not encloses_all:
            break
        normalized = normalized[1:-1]
    return normalized


def identify_generated_column(
    table: str,
    column: str,
    data_type: str,
    column_type: str,
    extra: str,
    source_expression: str,
) -> dict[str, Any] | None:
    """Return an approved, sanitized generated-column mapping or None."""
    spec = _GENERATED_COLUMN_SPECS.get((table, column))
    if spec is None:
        return None
    if data_type.casefold() != spec["data_type"] or column_type.casefold() != spec["column_type"]:
        return None
    if "stored generated" not in extra.casefold():
        return None
    if _normalize_expression(source_expression) != spec["source_expression"]:
        return None
    return {key: value for key, value in spec.items() if key != "source_expression"}


def target_generated_column(table: str, column: dict[str, Any]) -> dict[str, Any] | None:
    """Validate the snapshot's approved generated-column key and return its target rule."""
    if column.get("generated_expression_present") is not True:
        return None
    spec = _GENERATED_COLUMN_SPECS.get((table, str(column.get("name", ""))))
    if spec is None or column.get("generated_expression_key") != spec["key"]:
        return None
    if str(column.get("data_type", "")).casefold() != spec["data_type"]:
        return None
    if str(column.get("column_type", "")).casefold() != spec["column_type"]:
        return None
    if "stored generated" not in str(column.get("extra", "")).casefold():
        return None
    return {key: value for key, value in spec.items() if key != "source_expression"}


def declares_generated_column(column: dict[str, Any]) -> bool:
    extra = str(column.get("extra", "")).casefold()
    return bool(re.search(r"\bgenerated\b", extra))


def generated_dependencies_valid(
    table: str,
    generated_column: dict[str, Any],
    columns: list[dict[str, Any]],
) -> bool:
    spec = target_generated_column(table, generated_column)
    if spec is None:
        return False
    by_name = {str(column.get("name", "")): column for column in columns}
    for dependency in spec["dependencies"]:
        column = by_name.get(dependency)
        if not isinstance(column, dict) or declares_generated_column(column):
            return False
        if column.get("generated_expression_present") is True:
            return False
        if str(column.get("data_type", "")).casefold() != "decimal":
            return False
        if str(column.get("column_type", "")).casefold() != "decimal(10,2)":
            return False
    return True


def is_generated_column(column: dict[str, Any]) -> bool:
    return column.get("generated_expression_present") is True or declares_generated_column(column)
