from __future__ import annotations

from decimal import Decimal, InvalidOperation
import re
from typing import Any

from .type_mapping import TypeMappingError, quote_identifier, quote_literal


class CheckTranslationError(ValueError):
    pass


_SAFE_TEXT_LABEL = re.compile(r"[A-Za-z0-9_-]{1,32}\Z")
_SAFE_NUMERIC = re.compile(r"-?\d{1,18}(?:\.\d{1,9})?\Z")
_TEXT_TYPES = {"char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext", "enum"}
_NUMERIC_TYPES = {"tinyint", "smallint", "mediumint", "int", "integer", "bigint", "decimal", "numeric", "float", "double", "real", "year"}


def _fail() -> None:
    raise CheckTranslationError("MYSQL_CHECK_EXPRESSION_TRANSLATION_REQUIRED")


def _identifier(source: str, index: int) -> tuple[str, int]:
    if source[index] == "`":
        index += 1
        chars: list[str] = []
        while index < len(source):
            char = source[index]
            if char == "`":
                if index + 1 < len(source) and source[index + 1] == "`":
                    chars.append("`")
                    index += 2
                    continue
                value = "".join(chars)
                if not value or "\x00" in value:
                    _fail()
                return value, index + 1
            if char == "\x00":
                _fail()
            chars.append(char)
            index += 1
        _fail()
    match = re.match(r"[A-Za-z_$][A-Za-z0-9_$]*", source[index:])
    if not match:
        _fail()
    return match.group(0), index + len(match.group(0))


def _string_literal(source: str, index: int, *, no_backslash_escapes: bool) -> tuple[str, int]:
    chars: list[str] = []
    escaped_delimiter = source.startswith("\\'", index)
    if escaped_delimiter:
        if no_backslash_escapes:
            _fail()
        index += 1
    if index >= len(source) or source[index] != "'":
        _fail()
    index += 1
    while index < len(source):
        char = source[index]
        if char == "\\":
            if not no_backslash_escapes and source.startswith("\\'", index):
                value = "".join(chars)
                if not value or not value.isascii() or not _SAFE_TEXT_LABEL.fullmatch(value):
                    _fail()
                return value, index + 2
            _fail()
        if char == "'":
            if index + 1 < len(source) and source[index + 1] == "'":
                chars.append("'")
                index += 2
                continue
            value = "".join(chars)
            if not value or not value.isascii() or not _SAFE_TEXT_LABEL.fullmatch(value):
                _fail()
            return value, index + 1
        if not char.isascii() or ord(char) < 0x20 or char == "\x7f":
            _fail()
        chars.append(char)
        index += 1
    _fail()


def _tokens(expression: str, *, no_backslash_escapes: bool) -> list[tuple[str, Any]]:
    if not isinstance(expression, str) or not expression or "\x00" in expression:
        _fail()
    result: list[tuple[str, Any]] = []
    index = 0
    while index < len(expression):
        char = expression[index]
        if char.isspace():
            index += 1
            continue
        if char in "(),":
            result.append((char, char))
            index += 1
            continue
        if char == "`" or re.match(r"[A-Za-z_$]", char):
            if char == "_":
                charset = re.match(r"_(utf8mb4|utf8)\b", expression[index:], re.IGNORECASE)
                if charset:
                    index += len(charset.group(0))
                    while index < len(expression) and expression[index].isspace():
                        index += 1
                    if expression.startswith("\\'", index):
                        if no_backslash_escapes:
                            _fail()
                        value, index = _string_literal(expression, index, no_backslash_escapes=no_backslash_escapes)
                        result.append(("STRING", value))
                        continue
                    if index < len(expression) and expression[index] == "'":
                        value, index = _string_literal(expression, index, no_backslash_escapes=no_backslash_escapes)
                        result.append(("STRING", value))
                        continue
                    _fail()
            if char == "'":
                value, index = _string_literal(expression, index, no_backslash_escapes=no_backslash_escapes)
                result.append(("STRING", value))
                continue
            value, index = _identifier(expression, index)
            upper = value.upper()
            result.append((upper if upper in {"IN", "BETWEEN", "AND"} else "IDENT", value))
            continue
        if char == "'":
            value, index = _string_literal(expression, index, no_backslash_escapes=no_backslash_escapes)
            result.append(("STRING", value))
            continue
        number = re.match(r"-?\d{1,18}(?:\.\d{1,9})?", expression[index:])
        if number:
            result.append(("NUMBER", number.group(0)))
            index += len(number.group(0))
            continue
        _fail()
    return result


def _unwrap(tokens: list[tuple[str, Any]]) -> list[tuple[str, Any]]:
    while len(tokens) >= 2 and tokens[0][0] == "(" and tokens[-1][0] == ")":
        depth = 0
        wraps = True
        for index, (kind, _) in enumerate(tokens):
            if kind == "(":
                depth += 1
            elif kind == ")":
                depth -= 1
                if depth < 0:
                    _fail()
                if depth == 0 and index != len(tokens) - 1:
                    wraps = False
                    break
        if depth != 0:
            _fail()
        if not wraps:
            break
        tokens = tokens[1:-1]
    return tokens


def translate_mysql_check_clause(
    expression: str,
    *,
    columns: dict[str, dict[str, Any]],
    no_backslash_escapes: bool = False,
) -> dict[str, Any]:
    """Translate only static text IN-lists and numeric BETWEEN CHECK expressions."""
    tokens = _unwrap(_tokens(expression, no_backslash_escapes=no_backslash_escapes))
    if len(tokens) >= 7 and tokens[0][0] == "IDENT" and tokens[1][0] == "IN" and tokens[2][0] == "(":
        column = str(tokens[0][1])
        metadata = columns.get(column)
        if not metadata or str(metadata.get("data_type", "")).lower() not in _TEXT_TYPES:
            _fail()
        values: list[str] = []
        index = 3
        while index < len(tokens):
            if tokens[index][0] != "STRING":
                _fail()
            values.append(str(tokens[index][1]))
            index += 1
            if index < len(tokens) and tokens[index][0] == ",":
                index += 1
                continue
            break
        if index != len(tokens) - 1 or tokens[index][0] != ")":
            _fail()
        if not 2 <= len(values) <= 16 or len(set(values)) != len(values):
            _fail()
        return {"kind": "text_in_list", "column": column, "values": values}

    if len(tokens) == 5 and tokens[0][0] == "IDENT" and tokens[1][0] == "BETWEEN" \
        and tokens[2][0] == "NUMBER" and tokens[3][0] == "AND" and tokens[4][0] == "NUMBER":
        column = str(tokens[0][1])
        metadata = columns.get(column)
        if not metadata or str(metadata.get("data_type", "")).lower() not in _NUMERIC_TYPES:
            _fail()
        lower, upper = str(tokens[2][1]), str(tokens[4][1])
        if not _SAFE_NUMERIC.fullmatch(lower) or not _SAFE_NUMERIC.fullmatch(upper):
            _fail()
        try:
            if Decimal(lower) > Decimal(upper):
                _fail()
        except InvalidOperation:
            _fail()
        return {"kind": "numeric_between", "column": column, "lower": lower, "upper": upper}

    _fail()


def render_check_translation(translation: dict[str, Any], *, columns: dict[str, dict[str, Any]]) -> str:
    """Render a validated structured CHECK translation without accepting SQL fragments."""
    if not isinstance(translation, dict):
        _fail()
    kind = translation.get("kind")
    column = translation.get("column")
    if not isinstance(column, str) or column not in columns:
        _fail()
    data_type = str(columns[column].get("data_type", "")).lower()
    try:
        quoted_column = quote_identifier(column)
    except TypeMappingError as error:
        raise CheckTranslationError("MYSQL_CHECK_EXPRESSION_TRANSLATION_REQUIRED") from error

    if kind == "text_in_list" and set(translation) == {"kind", "column", "values"}:
        values = translation.get("values")
        if data_type not in _TEXT_TYPES or not isinstance(values, list) or not 2 <= len(values) <= 16:
            _fail()
        if any(not isinstance(value, str) or not value.isascii() or not _SAFE_TEXT_LABEL.fullmatch(value) for value in values):
            _fail()
        if len(set(values)) != len(values):
            _fail()
        return f"{quoted_column} IN (" + ", ".join(quote_literal(value) for value in values) + ")"

    if kind == "numeric_between" and set(translation) == {"kind", "column", "lower", "upper"}:
        lower, upper = translation.get("lower"), translation.get("upper")
        if data_type not in _NUMERIC_TYPES or not isinstance(lower, str) or not isinstance(upper, str):
            _fail()
        if not _SAFE_NUMERIC.fullmatch(lower) or not _SAFE_NUMERIC.fullmatch(upper):
            _fail()
        try:
            if Decimal(lower) > Decimal(upper):
                _fail()
        except InvalidOperation:
            _fail()
        return f"{quoted_column} BETWEEN {lower} AND {upper}"

    _fail()
