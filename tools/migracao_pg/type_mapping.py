"""Mapeamento conservador de tipos MySQL para PostgreSQL 18.

Não conecta a bancos, não executa SQL e não escolhe collations textuais. O
caller fornece essa decisão a partir de um catálogo aprovado por coluna.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date, datetime, time, timedelta
import re
from typing import Any
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError


class TypeMappingError(ValueError):
    """Tipo MySQL sem tradução segura ou metadado/valor temporal inválido."""

    def __init__(self, code: str, detail: str = "") -> None:
        self.code = code
        super().__init__(f"{code}: {detail}" if detail else code)


class TemporalValueError(ValueError):
    """Valor temporal que não pode ser normalizado sem perda/decisão manual."""

    def __init__(self, code: str) -> None:
        self.code = code
        super().__init__(code)


BINARY_STORAGE_POLICY_ID = "mysql_binary_exact_base64_ascii_text_v1"
BASE64_TEXT_COLUMNS = frozenset({("tenant_arquivos", "conteudo")})


def expected_binary_storage_encoding(table_name: str, column_name: str) -> str | None:
    return "base64" if (table_name, column_name) in BASE64_TEXT_COLUMNS else None


def expected_binary_storage_manifest(snapshot: dict[str, Any]) -> list[dict[str, str]]:
    if snapshot.get("binary_storage_required") is not True:
        raise TypeMappingError("BINARY_STORAGE_POLICY_REQUIRED")
    tables = snapshot.get("tables")
    if not isinstance(tables, list):
        raise TypeMappingError("BINARY_STORAGE_SNAPSHOT_INVALID")
    matching_tables = [table for table in tables if isinstance(table, dict) and table.get("name") == "tenant_arquivos"]
    if not matching_tables:
        raise TypeMappingError("BINARY_STORAGE_TABLE_REQUIRED")
    if len(matching_tables) != 1:
        raise TypeMappingError("BINARY_STORAGE_TABLE_METADATA_AMBIGUOUS")
    if matching_tables[0].get("type") != "BASE TABLE":
        raise TypeMappingError("BINARY_STORAGE_TABLE_METADATA_MISMATCH")
    columns = matching_tables[0].get("columns")
    if not isinstance(columns, list):
        raise TypeMappingError("BINARY_STORAGE_COLUMN_METADATA_MISSING")
    matching_columns = [column for column in columns if isinstance(column, dict) and column.get("name") == "conteudo"]
    if len(matching_columns) != 1 or matching_columns[0].get("data_type") != "longblob" \
        or str(matching_columns[0].get("column_type", "")).strip().lower() != "longblob" \
        or matching_columns[0].get("binary_storage_encoding") != "base64":
        raise TypeMappingError("BINARY_STORAGE_COLUMN_METADATA_MISMATCH")
    return [{
        "table": "tenant_arquivos",
        "column": "conteudo",
        "encoding": "base64",
        "target_type": "TEXT",
        "target_collation": '\"C\"',
    }]


@dataclass(frozen=True)
class TypeMapping:
    sql_type: str
    collation_sql: str | None = None
    checks: tuple[str, ...] = ()
    review_codes: tuple[str, ...] = ()
    storage_encoding: str | None = None

    @property
    def declaration(self) -> str:
        if self.collation_sql is None:
            return self.sql_type
        return f"{self.sql_type} COLLATE {self.collation_sql}"


def require_review_codes_resolved(mapping: TypeMapping, resolved_codes: set[str] | frozenset[str]) -> None:
    """Fail closed unless every mapping review gate was explicitly resolved."""
    unresolved = set(mapping.review_codes) - set(resolved_codes)
    unknown = set(resolved_codes) - set(mapping.review_codes)
    if unknown:
        raise TypeMappingError("REVIEW_RESOLUTION_NOT_APPLICABLE", ",".join(sorted(unknown)))
    if unresolved:
        raise TypeMappingError("MANUAL_REVIEW_REQUIRED", ",".join(sorted(unresolved)))


def quote_identifier(identifier: str) -> str:
    """Quote um identificador PostgreSQL, rejeitando bytes que SQL não suporta."""
    if not isinstance(identifier, str) or not identifier or "\x00" in identifier:
        raise TypeMappingError("INVALID_IDENTIFIER")
    return '"' + identifier.replace('"', '""') + '"'


def quote_literal(value: str) -> str:
    """Quote literal PostgreSQL sem depender de standard_conforming_strings."""
    if not isinstance(value, str):
        raise TypeMappingError("INVALID_LITERAL")
    if "\x00" in value:
        raise TypeMappingError("ENUM_LABEL_CONTAINS_NUL")
    escaped = []
    control_escapes = {"\b": r"\b", "\f": r"\f", "\n": r"\n", "\r": r"\r", "\t": r"\t", "\v": r"\v"}
    for character in value:
        if character == "\\":
            escaped.append(r"\\")
        elif character == "'":
            escaped.append("''")
        elif character in control_escapes:
            escaped.append(control_escapes[character])
        elif ord(character) < 0x20 or ord(character) == 0x7f:
            escaped.append(f"\\{ord(character):03o}")
        else:
            escaped.append(character)
    return "E'" + "".join(escaped) + "'"


def parse_mysql_enum(column_type: str, *, no_backslash_escapes: bool = False) -> tuple[str, ...]:
    """Parse COLUMN_TYPE `enum(...)` sem avaliar SQL ou perder labels escapados."""
    match = re.match(r"^\s*enum\s*\((.*)\)\s*$", column_type, re.IGNORECASE | re.DOTALL)
    if not match:
        raise TypeMappingError("INVALID_ENUM_METADATA")
    source = match.group(1)
    values: list[str] = []
    i = 0
    escapes = {
        "0": "\x00", "b": "\b", "n": "\n", "r": "\r", "t": "\t",
        "Z": "\x1a", "\\": "\\", "'": "'", '"': '"',
    }

    def skip_space(pos: int) -> int:
        while pos < len(source) and source[pos].isspace():
            pos += 1
        return pos

    i = skip_space(i)
    if i == len(source):
        raise TypeMappingError("EMPTY_ENUM_METADATA")

    while i < len(source):
        if source[i] != "'":
            raise TypeMappingError("INVALID_ENUM_METADATA")
        i += 1
        chars: list[str] = []
        closed = False
        while i < len(source):
            char = source[i]
            if char == "\\" and not no_backslash_escapes:
                i += 1
                if i >= len(source):
                    raise TypeMappingError("INVALID_ENUM_ESCAPE")
                escaped = source[i]
                chars.append(escapes.get(escaped, escaped))
                i += 1
                continue
            if char == "'":
                if i + 1 < len(source) and source[i + 1] == "'":
                    chars.append("'")
                    i += 2
                    continue
                i += 1
                closed = True
                break
            chars.append(char)
            i += 1
        if not closed:
            raise TypeMappingError("UNTERMINATED_ENUM_LABEL")
        values.append("".join(chars))
        i = skip_space(i)
        if i == len(source):
            break
        if source[i] != ",":
            raise TypeMappingError("INVALID_ENUM_SEPARATOR")
        i = skip_space(i + 1)
        if i == len(source):
            raise TypeMappingError("TRAILING_ENUM_SEPARATOR")

    if not values or len(set(values)) != len(values):
        raise TypeMappingError("EMPTY_OR_DUPLICATE_ENUM_LABEL")
    return tuple(values)


def _column_check(column_name: str | None, expression: str) -> str:
    if column_name is None:
        raise TypeMappingError("COLUMN_NAME_REQUIRED_FOR_CHECK")
    return f"CHECK ({quote_identifier(column_name)} {expression})"


def _check(expression: str) -> str:
    return f"CHECK ({expression})"


def _collation_clause(target_collation: str | None) -> str:
    if target_collation == "ci":
        return "ci"
    if target_collation == "C":
        return '"C"'
    raise TypeMappingError("TEXT_COLLATION_DECISION_REQUIRED")


def map_mysql_type(
    column_type: str,
    *,
    target_collation: str | None = None,
    table_name: str | None = None,
    column_name: str | None = None,
    binary_storage_encoding: str | None = None,
    no_backslash_escapes: bool = False,
    unsigned_bigint_exceeds_signed_limit: bool | None = None,
    time_uses_interval: bool | None = None,
) -> TypeMapping:
    """Map MySQL INFORMATION_SCHEMA.COLUMN_TYPE to a PostgreSQL declaration.

    `target_collation` must be explicitly selected as `ci` or `C` for textual
    types. Checks retain known MySQL value ranges. `review_codes` are blockers
    for executable DDL/data load until the caller resolves them explicitly.
    """
    if not isinstance(column_type, str) or "\x00" in column_type:
        raise TypeMappingError("INVALID_TYPE_METADATA")
    match = re.match(r"^([A-Za-z]+)\s*(.*)$", column_type.strip(), re.DOTALL)
    if not match:
        raise TypeMappingError("INVALID_TYPE_METADATA")
    base = match.group(1).lower()
    rest = match.group(2).strip()
    unsigned = bool(re.search(r"\bunsigned\b", rest, re.IGNORECASE))
    zerofill = bool(re.search(r"\bzerofill\b", rest, re.IGNORECASE))
    unsigned = unsigned or zerofill
    clean_rest = re.sub(r"\b(?:unsigned|zerofill)\b", "", rest, flags=re.IGNORECASE).strip()
    checks: list[str] = []
    reviews: list[str] = []

    integer_ranges = {
        "tinyint": (8, "SMALLINT", (-32768, 32767)),
        "smallint": (16, "SMALLINT", (-32768, 32767)),
        "mediumint": (24, "INTEGER", (-2147483648, 2147483647)),
        "int": (32, "INTEGER", (-2147483648, 2147483647)),
        "integer": (32, "INTEGER", (-2147483648, 2147483647)),
        "bigint": (64, "BIGINT", (-9223372036854775808, 9223372036854775807)),
    }
    if base in integer_ranges:
        if clean_rest and not re.fullmatch(r"(?:\(\s*\d+\s*\))?", clean_rest):
            raise TypeMappingError("UNSUPPORTED_INTEGER_METADATA")
        bits, signed_target, _ = integer_ranges[base]
        mysql_min = 0 if unsigned else -(1 << (bits - 1))
        mysql_max = (1 << bits) - 1 if unsigned else (1 << (bits - 1)) - 1
        target = signed_target
        target_bounds = integer_ranges[base][2]
        if unsigned and base == "bigint":
            if not isinstance(unsigned_bigint_exceeds_signed_limit, bool):
                raise TypeMappingError("UNSIGNED_BIGINT_RANGE_SCAN_REQUIRED")
            target = "NUMERIC(20,0)" if unsigned_bigint_exceeds_signed_limit else "BIGINT"
            if not unsigned_bigint_exceeds_signed_limit:
                checks.append(_column_check(column_name, ">= 0"))
                return TypeMapping(target, checks=tuple(checks))
            target_bounds = (0, 99999999999999999999)
        elif unsigned:
            target = {
                "tinyint": "SMALLINT", "smallint": "INTEGER", "mediumint": "INTEGER",
                "int": "BIGINT", "integer": "BIGINT",
            }[base]
            target_bounds = {
                "SMALLINT": (-32768, 32767), "INTEGER": (-2147483648, 2147483647),
                "BIGINT": (-9223372036854775808, 9223372036854775807),
                "NUMERIC(20,0)": (0, 99999999999999999999),
            }[target]
        if mysql_min < target_bounds[0] or mysql_max > target_bounds[1]:
            raise TypeMappingError("MYSQL_RANGE_EXCEEDS_POSTGRES_TARGET", base)
        if (mysql_min, mysql_max) != target_bounds:
            checks.append(_column_check(column_name, f"BETWEEN {mysql_min} AND {mysql_max}"))
        return TypeMapping(target, checks=tuple(checks))

    if base in {"decimal", "numeric", "dec", "fixed"}:
        precision_scale = re.fullmatch(r"\(\s*(\d+)\s*(?:,\s*(\d+)\s*)?\)", clean_rest)
        if not precision_scale:
            raise TypeMappingError("INVALID_DECIMAL_METADATA")
        precision = int(precision_scale.group(1))
        scale = int(precision_scale.group(2) or 0)
        if precision < 1 or precision > 65 or scale < 0 or scale > 30 or scale > precision:
            raise TypeMappingError("UNSUPPORTED_DECIMAL_PRECISION")
        if unsigned:
            checks.append(_column_check(column_name, ">= 0"))
        return TypeMapping(f"NUMERIC({precision},{scale})", checks=tuple(checks))

    if base in {"datetime", "timestamp", "time"}:
        precision_match = re.fullmatch(r"(?:\(\s*(\d+)\s*\))?", clean_rest)
        if not precision_match:
            raise TypeMappingError("INVALID_TEMPORAL_METADATA")
        precision = int(precision_match.group(1) or 0)
        if precision > 6:
            raise TypeMappingError("UNSUPPORTED_TEMPORAL_PRECISION")
        if base == "time":
            if not isinstance(time_uses_interval, bool):
                raise TypeMappingError("MYSQL_TIME_RANGE_SCAN_REQUIRED")
            temporal = "INTERVAL" if time_uses_interval else "TIME(0)"
            return TypeMapping(temporal)
        if precision > 0 and base != "time":
            reviews.append("FRACTIONAL_SECONDS_REVIEW_REQUIRED")
        temporal = {
            "datetime": "TIMESTAMP(0) WITHOUT TIME ZONE",
            "timestamp": "TIMESTAMP(0) WITHOUT TIME ZONE",
        }[base]
        return TypeMapping(temporal, review_codes=tuple(reviews))

    if base == "date":
        if clean_rest:
            raise TypeMappingError("INVALID_DATE_METADATA")
        return TypeMapping("DATE")

    if base in {"char", "varchar", "nchar", "nvarchar"}:
        length_match = re.fullmatch(r"\(\s*(\d+)\s*\)", clean_rest)
        if not length_match:
            raise TypeMappingError("INVALID_CHARACTER_LENGTH")
        length = int(length_match.group(1))
        if length < 1 or length > 65535:
            raise TypeMappingError("UNSUPPORTED_CHARACTER_LENGTH")
        collation = _collation_clause(target_collation)
        target = f"CHARACTER({length})" if base in {"char", "nchar"} else f"CHARACTER VARYING({length})"
        return TypeMapping(target, collation_sql=collation)

    if base in {"tinytext", "text", "mediumtext", "longtext"}:
        if clean_rest:
            raise TypeMappingError("INVALID_TEXT_METADATA")
        return TypeMapping("TEXT", collation_sql=_collation_clause(target_collation))

    if base == "enum":
        if unsigned or zerofill:
            raise TypeMappingError("INVALID_ENUM_METADATA")
        labels = parse_mysql_enum(column_type, no_backslash_escapes=no_backslash_escapes)
        if any("\x00" in label for label in labels):
            raise TypeMappingError("ENUM_LABEL_CONTAINS_NUL")
        return TypeMapping("CHARACTER VARYING", collation_sql=_collation_clause(target_collation), checks=tuple(checks))

    if base in {"tinyblob", "blob", "mediumblob", "longblob", "binary", "varbinary"}:
        expected_encoding = expected_binary_storage_encoding(table_name or "", column_name or "")
        if binary_storage_encoding is not None and binary_storage_encoding != expected_encoding:
            raise TypeMappingError("BINARY_STORAGE_POLICY_MISMATCH")
        if expected_encoding is not None and binary_storage_encoding != expected_encoding:
            raise TypeMappingError("BINARY_STORAGE_POLICY_REQUIRED")
        limits = {"tinyblob": 255, "blob": 65535, "mediumblob": 16777215, "longblob": 4294967295}
        if base in {"binary", "varbinary"}:
            length_match = re.fullmatch(r"\(\s*(\d+)\s*\)", clean_rest)
            if not length_match:
                raise TypeMappingError("INVALID_BINARY_LENGTH")
            length = int(length_match.group(1))
            if length < 1 or length > 65535:
                raise TypeMappingError("UNSUPPORTED_BINARY_LENGTH")
            column = quote_identifier(column_name) if column_name is not None else None
            if column is None:
                raise TypeMappingError("COLUMN_NAME_REQUIRED_FOR_CHECK")
            expression = f"= {length}" if base == "binary" else f"<= {length}"
            checks.append(_check(f"octet_length({column}) {expression}"))
        else:
            if clean_rest:
                raise TypeMappingError("INVALID_BLOB_METADATA")
            column = quote_identifier(column_name) if column_name is not None else None
            if column is None:
                raise TypeMappingError("COLUMN_NAME_REQUIRED_FOR_CHECK")
            if expected_encoding == "base64":
                if base != "longblob":
                    raise TypeMappingError("BINARY_STORAGE_SOURCE_TYPE_MISMATCH")
                return TypeMapping("TEXT", collation_sql='"C"', storage_encoding="base64")
            checks.append(_check(f"octet_length({column}) <= {limits[base]}"))
        return TypeMapping("BYTEA", checks=tuple(checks))

    if base == "json":
        if clean_rest or unsigned or zerofill:
            raise TypeMappingError("INVALID_JSON_METADATA")
        return TypeMapping("JSON")

    if base in {"float", "double", "real"}:
        if clean_rest not in {"", "precision"}:
            raise TypeMappingError("FLOAT_PRECISION_REVIEW_REQUIRED")
        if base == "real":
            reviews.append("MYSQL_REAL_AS_FLOAT_SQL_MODE_REVIEW_REQUIRED")
        if unsigned:
            checks.append(_column_check(column_name, ">= 0"))
        return TypeMapping("REAL" if base == "float" else "DOUBLE PRECISION", checks=tuple(checks), review_codes=tuple(reviews))

    if base == "bit":
        bit_match = re.fullmatch(r"\(\s*(\d+)\s*\)", clean_rest)
        if not bit_match:
            raise TypeMappingError("INVALID_BIT_METADATA")
        width = int(bit_match.group(1))
        if width < 1 or width > 64:
            raise TypeMappingError("UNSUPPORTED_BIT_WIDTH")
        return TypeMapping(f"BIT({width})")

    if base == "year":
        if clean_rest not in {"", "(4)"}:
            raise TypeMappingError("INVALID_YEAR_METADATA")
        column = quote_identifier(column_name) if column_name is not None else None
        if column is None:
            raise TypeMappingError("COLUMN_NAME_REQUIRED_FOR_CHECK")
        return TypeMapping("SMALLINT", checks=(_check(f"{column} = 0 OR {column} BETWEEN 1901 AND 2155"),))

    raise TypeMappingError("MANUAL_TYPE_MAPPING_REQUIRED", base)


def normalize_temporal_value(
    value: object,
    mysql_type: str,
    *,
    zero_date_to_null: bool = True,
    source_timezone: str = "UTC",
    target_timezone: str = "America/Sao_Paulo",
    time_as_interval: bool | None = None,
) -> date | datetime | time | timedelta | None:
    """Normalize temporal data without silently discarding precision/range.

    MySQL TIMESTAMP input must come from a connection whose session timezone is
    UTC; it is converted to the requested local civil time as specified by P2.
    """
    kind = mysql_type.strip().lower().split("(", 1)[0]
    if kind not in {"date", "datetime", "timestamp", "time"}:
        raise TemporalValueError("UNSUPPORTED_TEMPORAL_TYPE")
    if kind == "time" and not isinstance(time_as_interval, bool):
        raise TemporalValueError("MYSQL_TIME_RANGE_SCAN_REQUIRED")
    if value is None:
        return None

    raw = value.strip() if isinstance(value, str) else None
    if raw is not None and kind in {"date", "datetime", "timestamp"} and re.match(r"^0000-00-00(?:\s+00:00:00(?:\.0+)?)?$", raw):
        if zero_date_to_null:
            return None
        raise TemporalValueError("MYSQL_ZERO_DATE_REQUIRES_APPROVAL")

    try:
        if kind == "date":
            parsed = date.fromisoformat(raw) if raw is not None else value
            if not isinstance(parsed, date) or isinstance(parsed, datetime):
                raise TypeError
            return parsed

        if kind in {"datetime", "timestamp"}:
            parsed = datetime.fromisoformat(raw.replace(" ", "T", 1)) if raw is not None else value
            if not isinstance(parsed, datetime):
                raise TypeError
            if parsed.microsecond:
                raise TemporalValueError("FRACTIONAL_SECONDS_REQUIRE_APPROVAL")
            if kind == "datetime":
                if parsed.tzinfo is not None:
                    raise TemporalValueError("DATETIME_MUST_REMAIN_TIMEZONE_NAIVE")
                return parsed
            try:
                source_tz = ZoneInfo(source_timezone)
                target_tz = ZoneInfo(target_timezone)
            except ZoneInfoNotFoundError as error:
                raise TemporalValueError("TIMEZONE_DATABASE_UNAVAILABLE") from error
            instant = parsed.replace(tzinfo=source_tz) if parsed.tzinfo is None else parsed
            local = instant.astimezone(target_tz)
            wall = local.replace(tzinfo=None)
            first_fold = wall.replace(tzinfo=target_tz, fold=0).utcoffset()
            second_fold = wall.replace(tzinfo=target_tz, fold=1).utcoffset()
            if first_fold != second_fold:
                raise TemporalValueError("AMBIGUOUS_LOCAL_TIMESTAMP_REQUIRES_APPROVAL")
            return wall

        if isinstance(value, timedelta):
            if time_as_interval:
                return value
            if value.microseconds:
                raise TemporalValueError("MYSQL_TIME_FRACTIONAL_PRECISION_REVIEW_REQUIRED")
            total_microseconds = (value.days * 86400 + value.seconds) * 1_000_000 + value.microseconds
            if not 0 <= total_microseconds <= 86399 * 1_000_000:
                raise TemporalValueError("MYSQL_TIME_VALUE_OUTSIDE_TIME_OF_DAY_RANGE")
            hours, remainder = divmod(total_microseconds, 3_600_000_000)
            minutes, remainder = divmod(remainder, 60_000_000)
            seconds, microseconds = divmod(remainder, 1_000_000)
            return time(int(hours), int(minutes), int(seconds), int(microseconds))
        if raw is not None:
            time_match = re.fullmatch(r"([+-]?)(\d{1,3}):(\d{2}):(\d{2})(?:\.(\d+))?", raw)
            if not time_match:
                raise TemporalValueError("INVALID_MYSQL_TIME_VALUE")
            sign, hours, minutes, seconds, fraction = time_match.groups()
            hour, minute, second = int(hours), int(minutes), int(seconds)
            if hour > 838 or minute > 59 or second > 59 or (fraction and len(fraction) > 6):
                raise TemporalValueError("MYSQL_TIME_VALUE_OUT_OF_SOURCE_RANGE")
            microsecond = int((fraction or "").ljust(6, "0"))
            total = timedelta(hours=hour, minutes=minute, seconds=second, microseconds=microsecond)
            total = -total if sign == "-" else total
            if time_as_interval:
                return total
            if microsecond:
                raise TemporalValueError("MYSQL_TIME_FRACTIONAL_PRECISION_REVIEW_REQUIRED")
            if total < timedelta(0) or total > timedelta(seconds=86399):
                raise TemporalValueError("MYSQL_TIME_VALUE_OUTSIDE_TIME_OF_DAY_RANGE")
            return time(hour, minute, second, microsecond)
        if not isinstance(value, time):
            raise TypeError
        if value.tzinfo is not None:
            raise TemporalValueError("MYSQL_TIME_MUST_REMAIN_TIMEZONE_NAIVE")
        if not time_as_interval:
            if value.microsecond:
                raise TemporalValueError("MYSQL_TIME_FRACTIONAL_PRECISION_REVIEW_REQUIRED")
            if (value.hour, value.minute, value.second) > (23, 59, 59):
                raise TemporalValueError("MYSQL_TIME_VALUE_OUTSIDE_TIME_OF_DAY_RANGE")
            return value
        return timedelta(hours=value.hour, minutes=value.minute, seconds=value.second, microseconds=value.microsecond)
    except TemporalValueError:
        raise
    except (TypeError, ValueError, OverflowError) as error:
        raise TemporalValueError("INVALID_TEMPORAL_VALUE") from error
