#!/usr/bin/env python3
"""Cataloga um arquivo schema-only MySQL/MariaDB sem executar ou copiar SQL.

O resultado é uma projeção parcial da fonte. ALTER TABLE é inventariado, mas
não aplicado; por isso o programa não produz catalog_final.json nem DDL PostgreSQL.
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
from collections import Counter
from pathlib import Path
from typing import Any, Iterable, Sequence

MAX_INPUT_BYTES = 16 * 1024 * 1024
EXPECTED_STATEMENTS = 766
EXPECTED_TABLES = 270
EXPECTED_ALTERS = 489
EXPECTED_TRIGGERS = 7
EXPECTED_FOREIGN_KEYS = 11
EXPECTED_WITH_TENANT = 228
EXPECTED_WITHOUT_TENANT = 42
EXPECTED_VIEW_LIKE_TOTAL = 22
EXPECTED_VIEW_LIKE_WITH_TENANT = 4
EXPECTED_VIEW_LIKE_WITHOUT_TENANT = 18
EXPECTED_OTHER_WITHOUT_TENANT = 24
IDENTIFIER = r"(?:`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)(?:\.(?:`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*))?"
OBJECT_RE = re.compile(
    rf"^CREATE\s+(?:(?:DEFINER\s*=\s*\S+)\s+)?(?P<kind>TABLE|VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT)\s+(?:IF\s+NOT\s+EXISTS\s+)?(?P<name>{IDENTIFIER})(?=\s|\()",
    re.IGNORECASE | re.DOTALL,
)
ALTER_RE = re.compile(rf"^ALTER\s+TABLE\s+(?P<name>{IDENTIFIER})\s+(?P<rest>.*)$", re.IGNORECASE | re.DOTALL)
COLUMN_RE = re.compile(r"^\s*`((?:``|[^`])+)`\s+([A-Za-z]+)", re.DOTALL)


class CatalogError(ValueError):
    pass


class BundlePublishedDurabilityUncertain(OSError):
    """O bundle foi publicado atomicamente, mas o fsync do diretório falhou."""


def fail(message: str) -> None:
    raise CatalogError(message)


def sha256_bytes(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def _is_dash_comment(text: str, index: int) -> bool:
    return text.startswith("--", index) and (index + 2 == len(text) or text[index + 2].isspace())


def _is_special_comment_start(text: str, index: int) -> bool:
    return text.startswith("/*!", index) or text[index:index + 4].upper() == "/*M!" or text.startswith("/*+", index)


def split_statements(sql: str) -> list[dict[str, Any]]:
    """Split by MySQL DELIMITER while respecting strings, identifiers and comments."""
    statements: list[dict[str, Any]] = []
    delimiter = ";"
    buffer: list[str] = []
    line = 1
    start_line = 1
    line_start = 0
    quote: str | None = None
    escaped = False
    line_comment = False
    block_comment = False
    buffer_has_nonspace = False
    index = 0

    def append_char(char: str) -> None:
        nonlocal start_line, buffer_has_nonspace
        if not buffer_has_nonspace and not char.isspace():
            start_line = line
            buffer_has_nonspace = True
        buffer.append(char)

    def flush() -> None:
        nonlocal buffer_has_nonspace, start_line
        raw = "".join(buffer).strip()
        clean = strip_comments(raw).strip()
        if clean:
            statements.append({"raw": raw, "line_start": start_line, "line_end": line})
        buffer.clear()
        buffer_has_nonspace = False
        start_line = line

    while index < len(sql):
        if index == line_start and quote is None and not line_comment and not block_comment:
            line_end = sql.find("\n", index)
            if line_end < 0:
                line_end = len(sql)
            current_line = sql[index:line_end]
            directive = re.fullmatch(r"\s*DELIMITER\s+(\S+)\s*(?:--.*)?", current_line, re.IGNORECASE)
            if directive and not strip_comments("".join(buffer)).strip():
                flush()
                delimiter = directive.group(1)
                if not delimiter or any(ch.isspace() for ch in delimiter):
                    fail(f"DELIMITER inválido na linha {line}")
                consumed = sql[index:line_end]
                line += consumed.count("\n")
                if line_end < len(sql):
                    line += 1
                    line_start = line_end + 1
                    index = line_end + 1
                else:
                    index = line_end
                buffer.clear()
                continue

        char = sql[index]
        if line_comment:
            append_char(char)
            index += 1
            if char == "\n":
                line_comment = False
                line += 1
                line_start = index
            continue
        if block_comment:
            append_char(char)
            if sql.startswith("*/", index):
                append_char("/")
                index += 2
                block_comment = False
                continue
            if char == "\n":
                line += 1
                line_start = index + 1
            index += 1
            continue
        if quote is not None:
            append_char(char)
            if escaped:
                escaped = False
            elif char == "\\" and quote != "`":
                escaped = True
            elif char == quote:
                if index + 1 < len(sql) and sql[index + 1] == quote:
                    append_char(sql[index + 1])
                    index += 1
                else:
                    quote = None
            if char == "\n":
                line += 1
                line_start = index + 1
            index += 1
            continue
        if sql.startswith("/*", index):
            if _is_special_comment_start(sql, index):
                fail(f"comentário executável/hint especial não suportado na linha {line}")
            append_char("/")
            append_char("*")
            block_comment = True
            index += 2
            continue
        if char == "#" or _is_dash_comment(sql, index):
            line_comment = True
            append_char(char)
            index += 1
            continue
        if char in ("'", '"', "`"):
            quote = char
            append_char(char)
            index += 1
            continue
        if delimiter and sql.startswith(delimiter, index):
            flush()
            segment = sql[index:index + len(delimiter)]
            line += segment.count("\n")
            index += len(delimiter)
            if index > 0 and sql[index - 1:index] == "\n":
                line_start = index
            continue
        append_char(char)
        if char == "\n":
            line += 1
            line_start = index + 1
        index += 1

    if quote is not None:
        fail(f"aspas não fechadas após a linha {start_line}")
    if block_comment:
        fail(f"comentário de bloco não fechado após a linha {start_line}")
    if line_comment:
        line_comment = False
    if strip_comments("".join(buffer)).strip():
        fail(f"statement sem delimitador final próximo à linha {start_line}")
    return statements


def strip_comments(sql: str) -> str:
    out: list[str] = []
    quote: str | None = None
    escaped = False
    line_comment = False
    block_comment = False
    i = 0
    while i < len(sql):
        char = sql[i]
        if line_comment:
            if char == "\n":
                out.append("\n")
                line_comment = False
            else:
                out.append(" ")
            i += 1
            continue
        if block_comment:
            if sql.startswith("*/", i):
                out.extend((" ", " "))
                i += 2
                block_comment = False
            else:
                out.append("\n" if char == "\n" else " ")
                i += 1
            continue
        if quote is not None:
            out.append(char)
            if escaped:
                escaped = False
            elif char == "\\" and quote != "`":
                escaped = True
            elif char == quote:
                if i + 1 < len(sql) and sql[i + 1] == quote:
                    out.append(sql[i + 1])
                    i += 1
                else:
                    quote = None
            i += 1
            continue
        if sql.startswith("/*", i):
            if _is_special_comment_start(sql, i):
                fail("comentário executável/hint especial não suportado")
            out.extend((" ", " "))
            i += 2
            block_comment = True
            continue
        if char == "#" or _is_dash_comment(sql, i):
            out.append(" ")
            i += 1
            line_comment = True
            continue
        if char in ("'", '"', "`"):
            quote = char
        out.append(char)
        i += 1
    return "".join(out)


def unquote_identifier(value: str) -> str:
    value = value.strip()
    parts: list[str] = []
    start = 0
    quoted = False
    index = 0
    while index < len(value):
        if value[index] == "`":
            if quoted and index + 1 < len(value) and value[index + 1] == "`":
                index += 2
                continue
            quoted = not quoted
        elif value[index] == "." and not quoted:
            parts.append(value[start:index].strip())
            start = index + 1
        index += 1
    if quoted:
        fail("identificador quoted não fechado")
    parts.append(value[start:].strip())
    decoded: list[str] = []
    for part in parts:
        if part.startswith("`") and part.endswith("`"):
            decoded.append(part[1:-1].replace("``", "`"))
        elif "`" in part:
            fail("identificador quoted malformado")
        else:
            decoded.append(part)
    return ".".join(decoded)


def split_top_level(body: str) -> list[str]:
    parts: list[str] = []
    current: list[str] = []
    depth = 0
    quote: str | None = None
    escaped = False
    i = 0
    while i < len(body):
        char = body[i]
        if quote is not None:
            current.append(char)
            if escaped:
                escaped = False
            elif char == "\\" and quote != "`":
                escaped = True
            elif char == quote:
                if i + 1 < len(body) and body[i + 1] == quote:
                    current.append(body[i + 1])
                    i += 1
                else:
                    quote = None
        else:
            if char in ("'", '"', "`"):
                quote = char
                current.append(char)
            elif char == "(":
                depth += 1
                current.append(char)
            elif char == ")":
                depth -= 1
                if depth < 0:
                    fail("parênteses desbalanceados em definição de tabela")
                current.append(char)
            elif char == "," and depth == 0:
                part = "".join(current).strip()
                if not part:
                    fail("cláusula vazia em lista separada por vírgula")
                parts.append(part)
                current.clear()
            else:
                current.append(char)
        i += 1
    if quote is not None or depth != 0:
        fail("aspas ou parênteses desbalanceados em definição de tabela")
    part = "".join(current).strip()
    if not part:
        fail("cláusula vazia ou vírgula final em lista")
    parts.append(part)
    return parts


def find_matching_paren(text: str, open_index: int) -> int:
    if open_index >= len(text) or text[open_index] != "(":
        fail("parêntese inicial não encontrado")
    depth = 0
    quote: str | None = None
    escaped = False
    i = open_index
    while i < len(text):
        char = text[i]
        if quote is not None:
            if escaped:
                escaped = False
            elif char == "\\" and quote != "`":
                escaped = True
            elif char == quote:
                if i + 1 < len(text) and text[i + 1] == quote:
                    i += 1
                else:
                    quote = None
        else:
            if char in ("'", '"', "`"):
                quote = char
            elif char == "(":
                depth += 1
            elif char == ")":
                depth -= 1
                if depth == 0:
                    return i
        i += 1
    fail("corpo de tabela não fechado")


def tokenize_sql(sql: str) -> list[tuple[str, str]]:
    """Return typed tokens; string values are used only transiently, never emitted."""
    tokens: list[tuple[str, str]] = []
    i = 0
    while i < len(sql):
        char = sql[i]
        if char.isspace():
            i += 1
            continue
        if char == "`":
            i += 1
            value: list[str] = []
            while i < len(sql):
                if sql[i] == "`":
                    if i + 1 < len(sql) and sql[i + 1] == "`":
                        value.append("`")
                        i += 2
                        continue
                    i += 1
                    break
                value.append(sql[i])
                i += 1
            else:
                fail("identificador quoted não fechado")
            tokens.append(("identifier", "".join(value)))
            continue
        if char in ("'", '"'):
            quote = char
            i += 1
            value = []
            while i < len(sql):
                if sql[i] == "\\" and i + 1 < len(sql):
                    value.append(sql[i + 1])
                    i += 2
                    continue
                if sql[i] == quote:
                    if i + 1 < len(sql) and sql[i + 1] == quote:
                        value.append(quote)
                        i += 2
                        continue
                    i += 1
                    break
                value.append(sql[i])
                i += 1
            else:
                fail("string literal não fechada")
            tokens.append(("string", "".join(value)))
            continue
        word = re.match(r"[A-Za-z_][A-Za-z0-9_$]*", sql[i:])
        if word:
            tokens.append(("word", word.group(0).upper()))
            i += len(word.group(0))
            continue
        number = re.match(r"\d+(?:\.\d+)?", sql[i:])
        if number:
            tokens.append(("number", number.group(0)))
            i += len(number.group(0))
            continue
        if char in "(),.=+-*/%<>!|&:;[]@?^~$":
            tokens.append(("symbol", char))
            i += 1
            continue
        fail("token SQL não suportado")
    return tokens


def mask_string_literals(sql: str) -> str:
    masked: list[str] = []
    quote: str | None = None
    escaped = False
    index = 0
    while index < len(sql):
        char = sql[index]
        if quote is not None:
            masked.append("\n" if char == "\n" else " ")
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                if index + 1 < len(sql) and sql[index + 1] == quote:
                    index += 1
                    masked.append(" ")
                else:
                    quote = None
        elif char in ("'", '"'):
            quote = char
            masked.append(" ")
        else:
            masked.append(char)
        index += 1
    if quote is not None:
        fail("string literal não fechada")
    return "".join(masked)


def unquoted_words(sql: str) -> list[str]:
    words: list[str] = []
    index = 0
    while index < len(sql):
        char = sql[index]
        if char in ("'", '"', "`"):
            quote = char
            index += 1
            while index < len(sql):
                if quote != "`" and sql[index] == "\\" and index + 1 < len(sql):
                    index += 2
                    continue
                if sql[index] == quote:
                    if index + 1 < len(sql) and sql[index + 1] == quote:
                        index += 2
                        continue
                    index += 1
                    break
                index += 1
            else:
                fail("string/identifier quoted não fechado")
            continue
        match = re.match(r"[A-Za-z_][A-Za-z0-9_$]*", sql[index:])
        if match:
            words.append(match.group(0).upper())
            index += len(match.group(0))
        else:
            index += 1
    return words


def validate_table_options(tail: str) -> None:
    tokens = tokenize_sql(strip_comments(tail))
    index = 0

    def consume_identifier(option: str) -> None:
        nonlocal index
        if index >= len(tokens) or tokens[index][0] not in {"word", "identifier"}:
            fail(f"opção {option} sem identificador")
        index += 1

    def consume_assignment(option: str, expected_types: set[str]) -> None:
        nonlocal index
        if index >= len(tokens) or tokens[index] != ("symbol", "="):
            fail(f"opção {option} sem '='")
        index += 1
        if index >= len(tokens) or tokens[index][0] not in expected_types:
            fail(f"valor de opção {option} não reconhecido")
        index += 1

    while index < len(tokens):
        if tokens[index][0] != "word":
            fail("cauda CREATE TABLE contém token não permitido")
        option = tokens[index][1]
        index += 1
        if option == "ENGINE":
            consume_assignment(option, {"word"})
        elif option == "AUTO_INCREMENT":
            consume_assignment(option, {"number"})
        elif option == "DEFAULT":
            if index < len(tokens) and tokens[index] == ("word", "CHARSET"):
                index += 1
                consume_assignment("DEFAULT CHARSET", {"word"})
            elif index < len(tokens) and tokens[index] == ("word", "CHARACTER"):
                index += 1
                if index >= len(tokens) or tokens[index] != ("word", "SET"):
                    fail("opção DEFAULT CHARACTER SET incompleta")
                index += 1
                consume_assignment("DEFAULT CHARACTER SET", {"word"})
            else:
                fail("opção DEFAULT de tabela não reconhecida")
        elif option in {"CHARSET", "COLLATE"}:
            consume_assignment(option, {"word"})
        elif option == "CHARACTER":
            if index >= len(tokens) or tokens[index] != ("word", "SET"):
                fail("opção CHARACTER de tabela sem SET")
            index += 1
            consume_assignment("CHARACTER SET", {"word"})
        elif option == "COMMENT":
            consume_assignment(option, {"string"})
        else:
            fail("opção CREATE TABLE não reconhecida; cauda não será ignorada")


def parse_column_attributes(suffix: str) -> dict[str, Any]:
    tokens = tokenize_sql(strip_comments(suffix))
    result = {
        "unsigned": False,
        "auto_increment": False,
        "nullability": "implicit_nullable",
        "default_present": False,
        "default_is_one": False,
        "collation_present": False,
        "generated_expression_present": False,
        "check_present": False,
        "inline_primary_key": False,
        "inline_unique": False,
    }
    index = 0

    def word_at(offset: int, value: str) -> bool:
        return offset < len(tokens) and tokens[offset] == ("word", value)

    def require_identifier(position: int, path: str) -> int:
        if position >= len(tokens) or tokens[position][0] not in {"word", "identifier"}:
            fail(f"{path} deve conter identificador")
        return position + 1

    while index < len(tokens):
        if word_at(index, "UNSIGNED") or word_at(index, "ZEROFILL"):
            result["unsigned"] = True
            index += 1
        elif word_at(index, "NOT") and word_at(index + 1, "NULL"):
            result["nullability"] = "not_null"
            index += 2
        elif word_at(index, "NULL"):
            result["nullability"] = "explicit_nullable"
            index += 1
        elif word_at(index, "DEFAULT"):
            if index + 1 >= len(tokens):
                fail("DEFAULT sem expressão")
            result["default_present"] = True
            value_token = tokens[index + 1]
            result["default_is_one"] = value_token in {("number", "1"), ("string", "1")}
            index += 2
            if value_token == ("word", "CURRENT_TIMESTAMP") and index < len(tokens) and tokens[index] == ("symbol", "("):
                depth = 0
                while index < len(tokens):
                    token = tokens[index]
                    if token == ("symbol", "("):
                        depth += 1
                    elif token == ("symbol", ")"):
                        depth -= 1
                    index += 1
                    if depth == 0:
                        break
                if depth != 0:
                    fail("DEFAULT CURRENT_TIMESTAMP com precisão inválida")
            elif value_token == ("symbol", "("):
                depth = 1
                while index < len(tokens) and depth:
                    token = tokens[index]
                    if token == ("symbol", "("):
                        depth += 1
                    elif token == ("symbol", ")"):
                        depth -= 1
                    index += 1
                if depth:
                    fail("DEFAULT expression não fechada")
        elif word_at(index, "AUTO_INCREMENT"):
            result["auto_increment"] = True
            index += 1
        elif word_at(index, "COLLATE"):
            result["collation_present"] = True
            index = require_identifier(index + 1, "COLLATE")
        elif word_at(index, "CHARACTER"):
            if not word_at(index + 1, "SET"):
                fail("atributo CHARACTER sem SET")
            index = require_identifier(index + 2, "CHARACTER SET")
        elif word_at(index, "COMMENT"):
            if index + 1 >= len(tokens) or tokens[index + 1][0] != "string":
                fail("COMMENT de coluna deve ter literal")
            index += 2
        elif word_at(index, "ON"):
            if not word_at(index + 1, "UPDATE") or not word_at(index + 2, "CURRENT_TIMESTAMP"):
                fail("atributo ON UPDATE não suportado")
            index += 3
            if index < len(tokens) and tokens[index] == ("symbol", "("):
                depth = 0
                while index < len(tokens):
                    token = tokens[index]
                    if token == ("symbol", "("):
                        depth += 1
                    elif token == ("symbol", ")"):
                        depth -= 1
                    index += 1
                    if depth == 0:
                        break
                if depth:
                    fail("ON UPDATE CURRENT_TIMESTAMP com precisão inválida")
        elif word_at(index, "GENERATED"):
            if not word_at(index + 1, "ALWAYS") or not word_at(index + 2, "AS") or index + 3 >= len(tokens) or tokens[index + 3] != ("symbol", "("):
                fail("coluna generated não suportada")
            depth = 0
            index += 3
            while index < len(tokens):
                token = tokens[index]
                if token == ("symbol", "("):
                    depth += 1
                elif token == ("symbol", ")"):
                    depth -= 1
                index += 1
                if depth == 0:
                    break
            if depth:
                fail("expressão generated não fechada")
            result["generated_expression_present"] = True
            if word_at(index, "STORED") or word_at(index, "VIRTUAL"):
                index += 1
        elif word_at(index, "CHECK"):
            if index + 1 >= len(tokens) or tokens[index + 1] != ("symbol", "("):
                fail("CHECK de coluna sem expressão")
            depth = 0
            index += 1
            while index < len(tokens):
                token = tokens[index]
                if token == ("symbol", "("):
                    depth += 1
                elif token == ("symbol", ")"):
                    depth -= 1
                index += 1
                if depth == 0:
                    break
            if depth:
                fail("expressão CHECK não fechada")
            result["check_present"] = True
        elif word_at(index, "PRIMARY") and word_at(index + 1, "KEY"):
            result["inline_primary_key"] = True
            index += 2
        elif word_at(index, "UNIQUE"):
            result["inline_unique"] = True
            index += 1
        else:
            fail("atributo de coluna não reconhecido; catálogo interrompido")
    return result


def parse_identifier_list(text: str) -> list[str]:
    values: list[str] = []
    for item in split_top_level(text):
        match = re.fullmatch(r"\s*(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)\s*", item)
        if not match:
            fail("identificador de chave não reconhecido")
        values.append(unquote_identifier(match.group(1)))
    return values


def parse_index_parts(text: str) -> list[dict[str, Any]]:
    parts: list[dict[str, Any]] = []
    for item in split_top_level(text):
        match = re.fullmatch(
            r"\s*(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)\s*(?:\(\s*(\d+)\s*\))?\s*(?:(ASC|DESC)\s*)?",
            item,
            re.IGNORECASE,
        )
        if not match:
            fail("parte de índice não reconhecida; índice não será presumido equivalente")
        parts.append({
            "column_name": unquote_identifier(match.group(1)),
            "prefix_length": int(match.group(2)) if match.group(2) else None,
            "direction": match.group(3).upper() if match.group(3) else None,
        })
    if not parts:
        fail("índice sem colunas")
    return parts


def validate_index_tail(tail: str) -> None:
    tokens = tokenize_sql(strip_comments(tail))
    index = 0
    while index < len(tokens):
        if tokens[index] == ("word", "USING"):
            if index + 1 >= len(tokens) or tokens[index + 1] not in {("word", "BTREE"), ("word", "HASH")}:
                fail("cláusula USING de índice não reconhecida")
            index += 2
        elif tokens[index] == ("word", "COMMENT"):
            if index + 1 >= len(tokens) or tokens[index + 1][0] != "string":
                fail("COMMENT de índice deve ser literal")
            index += 2
        elif tokens[index] == ("word", "KEY_BLOCK_SIZE"):
            if index + 2 >= len(tokens) or tokens[index + 1] != ("symbol", "=") or tokens[index + 2][0] != "number":
                fail("KEY_BLOCK_SIZE de índice inválido")
            index += 3
        elif tokens[index] == ("word", "WITH"):
            if index + 2 >= len(tokens) or tokens[index + 1] != ("word", "PARSER") or tokens[index + 2][0] not in {"word", "identifier"}:
                fail("WITH PARSER de índice inválido")
            index += 3
        elif tokens[index] in {("word", "VISIBLE"), ("word", "INVISIBLE")}:
            index += 1
        else:
            fail("cauda de índice não reconhecida; não será ignorada")


def first_paren_contents(text: str, start: int = 0) -> tuple[str, int, int]:
    opening = text.find("(", start)
    if opening < 0:
        fail("lista de colunas não encontrada")
    closing = find_matching_paren(text, opening)
    return text[opening + 1:closing], opening, closing


def classify_type(segment: str) -> dict[str, Any]:
    match = COLUMN_RE.match(segment)
    if not match:
        fail("coluna não reconhecida")
    raw_type = match.group(2).lower()
    family_map = {
        "tinyint": "integer", "smallint": "integer", "mediumint": "integer", "int": "integer", "integer": "integer", "bigint": "integer",
        "decimal": "numeric", "numeric": "numeric", "float": "numeric", "double": "numeric", "real": "numeric",
        "date": "date", "datetime": "timestamp", "timestamp": "timestamp", "time": "time",
        "json": "json", "enum": "enum", "set": "enum", "char": "text", "varchar": "text", "text": "text",
        "tinytext": "text", "mediumtext": "text", "longtext": "text", "blob": "binary", "tinyblob": "binary",
        "mediumblob": "binary", "longblob": "binary", "binary": "binary", "varbinary": "binary", "year": "integer",
    }
    args: list[int] = []
    suffix_start = match.end(2)
    if suffix_start < len(segment) and segment[suffix_start:].lstrip().startswith("("):
        opening = segment.find("(", suffix_start)
        closing = find_matching_paren(segment, opening)
        type_arguments = segment[opening + 1:closing]
        if re.fullmatch(r"\s*\d+(?:\s*,\s*\d+)*\s*", type_arguments):
            args = [int(value.strip()) for value in type_arguments.split(",")]
        suffix_start = closing + 1
    attributes = parse_column_attributes(segment[suffix_start:])
    return {
        "type_family": family_map.get(raw_type, "other"),
        "source_type": raw_type,
        "numeric_type_arguments": args,
        **attributes,
        "schema_default_1": attributes["default_is_one"],
    }


def type_mapping_advisory(column: dict[str, Any]) -> dict[str, Any]:
    source_type = column["source_type"]
    args = column["numeric_type_arguments"]
    candidates = {
        "tinyint": "smallint",
        "smallint": "smallint",
        "mediumint": "integer",
        "int": "integer",
        "integer": "integer",
        "bigint": "bigint",
        "decimal": f"numeric({args[0]},{args[1]})" if len(args) == 2 else "numeric",
        "numeric": f"numeric({args[0]},{args[1]})" if len(args) == 2 else "numeric",
        "float": "real",
        "double": "double precision",
        "real": "real",
        "date": "date",
        "datetime": "timestamp without time zone",
        "timestamp": "timestamp with time zone",
        "time": "time without time zone",
        "json": "jsonb (candidate)",
        "enum": "text + CHECK (candidate)",
        "set": "undecided text/array",
        "char": f"character({args[0]})" if args else "character",
        "varchar": f"character varying({args[0]})" if args else "text",
        "text": "text",
        "tinytext": "text",
        "mediumtext": "text",
        "longtext": "text",
        "blob": "bytea (candidate)",
        "tinyblob": "bytea (candidate)",
        "mediumblob": "bytea (candidate)",
        "longblob": "bytea (candidate)",
        "binary": "bytea (candidate)",
        "varbinary": "bytea (candidate)",
        "year": "smallint (candidate)",
    }
    candidate = candidates.get(source_type, "unmapped")
    blockers: list[str] = []
    if candidate == "unmapped":
        blockers.append("source type requires explicit mapping")
    if source_type == "tinyint":
        blockers.append("confirm boolean semantics versus numeric range")
    if source_type in {"datetime", "timestamp", "time"}:
        blockers.append("timezone, precision, defaults and zero-date policy require approval")
    if source_type in {"enum", "set"}:
        blockers.append("allowed labels and application semantics require approved metadata")
    if source_type == "json":
        blockers.append("operators, equality, indexes and validation require review")
    if source_type in {"blob", "tinyblob", "mediumblob", "longblob", "binary", "varbinary"}:
        blockers.append("binary limits and attachment-storage contract require review")
    if column["unsigned"]:
        blockers.append("range validation and target bounds require approval")
    if column["auto_increment"]:
        blockers.append("identity versus sequence, ownership and collision checks are unresolved")
    if column["name"].lower() == "tenant_id" and column["schema_default_1"]:
        blockers.append("do not carry tenant_id default 1 without an approved tenant-context decision")
    if source_type in {"int", "integer", "smallint", "mediumint", "bigint", "tinyint"} and args:
        blockers.append("integer display width does not define a PostgreSQL range")
    return {
        "column_name": column["name"],
        "source_type": source_type,
        "source_type_arguments": args,
        "unsigned": column["unsigned"],
        "auto_increment": column["auto_increment"],
        "nullability": column["nullability"],
        "default_present": column["default_present"],
        "candidate_target_type": candidate,
        "confidence": "low" if blockers else "medium",
        "blockers": blockers,
        "executable": False,
    }


def parse_table(statement: str, object_name: str, statement_id: str) -> dict[str, Any]:
    clean = strip_comments(statement).strip()
    header = OBJECT_RE.match(clean)
    if not header or header.group("kind").upper() != "TABLE":
        fail("CREATE TABLE header inválido")
    opening = clean.find("(", header.end())
    closing = find_matching_paren(clean, opening)
    validate_table_options(clean[closing + 1:])
    segments = split_top_level(clean[opening + 1:closing])
    columns: list[dict[str, Any]] = []
    constraints: list[dict[str, Any]] = []
    for position, segment in enumerate(segments, 1):
        named = re.match(r"\s*CONSTRAINT\s+(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)\s+(.*)$", segment, re.IGNORECASE | re.DOTALL)
        constraint_name = unquote_identifier(named.group(1)) if named else None
        if named:
            segment = named.group(2)
        col = COLUMN_RE.match(segment)
        if col:
            name = col.group(1).replace("``", "`")
            details = classify_type(segment)
            details.update({"name": name, "ordinal": position})
            columns.append(details)
            if details["inline_primary_key"]:
                constraints.append({"kind": "primary_key", "columns": [name], "source": "inline"})
            if details["inline_unique"]:
                constraints.append({"kind": "unique", "columns": [name], "source": "inline"})
            continue
        structural = mask_string_literals(segment)
        special_index = re.match(r"\s*(?:FULLTEXT|SPATIAL)\s+(?:KEY|INDEX)\b", structural, re.IGNORECASE)
        if special_index:
            fail(f"índice FULLTEXT/SPATIAL exige revisão semântica: {object_name}")
        primary = re.match(r"\s*PRIMARY\s+KEY\s*\(", structural, re.IGNORECASE)
        if primary:
            content, _, close = first_paren_contents(structural, primary.end() - 1)
            validate_index_tail(structural[close + 1:])
            column_parts = parse_index_parts(content)
            constraints.append({"kind": "primary_key", "name": constraint_name, "columns": [part["column_name"] for part in column_parts], "column_parts": column_parts, "source": "table", "prefix_requires_review": any(part["prefix_length"] is not None for part in column_parts)})
            continue
        foreign = re.match(r"\s*FOREIGN\s+KEY\s*(?:(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)\s*)?\(", structural, re.IGNORECASE)
        if foreign:
            local, _, local_close = first_paren_contents(structural, foreign.end() - 1)
            ref = re.match(rf"\s+REFERENCES\s+(?P<table>{IDENTIFIER})\s*\(", structural[local_close + 1:], re.IGNORECASE)
            if not ref:
                fail("FOREIGN KEY sem REFERENCES reconhecido")
            ref_open = local_close + 1 + ref.end() - 1
            remote, _, remote_end = first_paren_contents(structural, ref_open)
            tail = structural[remote_end + 1:]
            if tail.strip() and not re.fullmatch(r"\s*(?:ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|RESTRICT|NO\s+ACTION|SET\s+NULL|SET\s+DEFAULT)\s*){0,2}", tail, re.IGNORECASE):
                fail("cauda FOREIGN KEY não reconhecida")
            rules = re.findall(r"ON\s+(DELETE|UPDATE)\s+(CASCADE|RESTRICT|NO\s+ACTION|SET\s+NULL|SET\s+DEFAULT)", tail, re.IGNORECASE)
            if len({rule[0].upper() for rule in rules}) != len(rules):
                fail("regra ON DELETE/UPDATE duplicada em FOREIGN KEY")
            constraints.append({
                "kind": "foreign_key",
                "name": constraint_name or (unquote_identifier(foreign.group(1)) if foreign.group(1) else None),
                "columns": parse_identifier_list(local),
                "referenced_table": unquote_identifier(ref.group("table")),
                "referenced_columns": parse_identifier_list(remote),
                "rules": rules,
            })
            continue
        unique = re.match(r"\s*UNIQUE\s+(?:KEY|INDEX)\s+(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)?\s*\(", structural, re.IGNORECASE)
        if unique:
            content, _, close = first_paren_contents(structural, unique.end() - 1)
            validate_index_tail(structural[close + 1:])
            column_parts = parse_index_parts(content)
            constraints.append({"kind": "unique", "name": constraint_name or (unquote_identifier(unique.group(1)) if unique.group(1) else None), "columns": [part["column_name"] for part in column_parts], "column_parts": column_parts, "source": "table", "prefix_requires_review": any(part["prefix_length"] is not None for part in column_parts)})
            continue
        index = re.match(r"\s*(?:KEY|INDEX)\s+(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)?\s*\(", structural, re.IGNORECASE)
        if index:
            content, _, close = first_paren_contents(structural, index.end() - 1)
            validate_index_tail(structural[close + 1:])
            column_parts = parse_index_parts(content)
            constraints.append({"kind": "index", "name": constraint_name or (unquote_identifier(index.group(1)) if index.group(1) else None), "columns": [part["column_name"] for part in column_parts], "column_parts": column_parts, "source": "table", "prefix_requires_review": any(part["prefix_length"] is not None for part in column_parts)})
            continue
        check = re.match(r"\s*CHECK\s*\(", structural, re.IGNORECASE)
        if check:
            _, _, close = first_paren_contents(structural, check.end() - 1)
            if structural[close + 1:].strip():
                fail("cauda CHECK não reconhecida")
            constraints.append({"kind": "check", "name": constraint_name, "definition_present": True})
            continue
        fail(f"cláusula de tabela não reconhecida em CREATE TABLE: {object_name}")
    if not columns:
        fail(f"CREATE TABLE sem colunas reconhecidas: {object_name}")
    tenant = next((column for column in columns if column["name"].lower() == "tenant_id"), None)
    return {
        "name": object_name,
        "source_statement_id": statement_id,
        "source_kind": "CREATE TABLE",
        "columns": columns,
        "constraints": constraints,
        "has_tenant_id": tenant is not None,
        "tenant_id_schema_default_1": tenant["schema_default_1"] if tenant else None,
        "view_like_name": object_name.lower().startswith(("view_", "vw_", "v_")),
    }


def classify_statement(statement: str) -> dict[str, Any]:
    clean = strip_comments(statement).strip()
    match = OBJECT_RE.match(clean)
    if match:
        kind = match.group("kind").upper()
        name = unquote_identifier(match.group("name"))
        return {"kind": f"CREATE {kind}", "object_name": name, "clean": clean}
    alter = ALTER_RE.match(clean)
    if alter:
        operation = re.match(r"\s*([A-Za-z]+)", alter.group("rest"))
        return {"kind": "ALTER TABLE", "object_name": unquote_identifier(alter.group("name")), "operation": operation.group(1).upper() if operation else "UNKNOWN", "clean": clean}
    leading = re.match(r"\s*([A-Za-z]+)", clean)
    token = leading.group(1).upper() if leading else "EMPTY"
    fail(f"statement top-level não permitido (tipo={token})")


def classify_alter_suboperations(rest: str) -> list[dict[str, Any]]:
    operations: list[dict[str, Any]] = []
    for ordinal, clause in enumerate(split_top_level(strip_comments(rest)), 1):
        normalized = strip_comments(clause).strip()
        named_constraint = re.match(
            r"ADD\s+CONSTRAINT\s+(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)\s+(.*)$",
            normalized,
            re.IGNORECASE | re.DOTALL,
        )
        if named_constraint:
            constraint_name = unquote_identifier(named_constraint.group(1))
            normalized = "ADD " + named_constraint.group(2).strip()
        else:
            constraint_name = None
        structural = mask_string_literals(normalized)
        if re.match(r"\s*ADD\s+(?:FULLTEXT|SPATIAL)\b", structural, re.IGNORECASE):
            fail("índice FULLTEXT/SPATIAL em ALTER exige revisão semântica")
        rules = (
            (r"ADD\s+PRIMARY\s+KEY\s*\(", "add_primary_key"),
            (r"ADD\s+FOREIGN\s+KEY\b", "add_foreign_key"),
            (r"ADD\s+UNIQUE(?:\s+(?:KEY|INDEX))?\b", "add_unique"),
            (r"ADD\s+(?:KEY|INDEX)\b", "add_index"),
            (r"ADD\s+(?:COLUMN\s+)?`(?:``|[^`])+`\s+[A-Za-z]+", "add_column"),
            (r"MODIFY\s+(?:COLUMN\s+)?`(?:``|[^`])+`\s+[A-Za-z]+", "modify_column"),
            (r"CHANGE\s+(?:COLUMN\s+)?`(?:``|[^`])+`\s+`(?:``|[^`])+`\s+[A-Za-z]+", "change_column"),
            (r"DROP\s+(?:COLUMN|KEY|INDEX|PRIMARY\s+KEY|FOREIGN\s+KEY|CONSTRAINT)\b", "drop_object"),
            (r"RENAME\s+(?:TO|COLUMN)\b", "rename_object"),
            (r"AUTO_INCREMENT\s*=\s*\d+\s*$", "set_auto_increment"),
        )
        kind = next((label for pattern, label in rules if re.match(pattern, normalized, re.IGNORECASE)), "unsupported")
        if kind == "unsupported":
            fail(f"suboperação ALTER não suportada; catalogação abortada no item {ordinal}")
        item: dict[str, Any] = {
            "ordinal": ordinal,
            "kind": kind,
            "constraint_name": constraint_name,
            "status": "catalogued_not_replayed" if kind != "unsupported" else "unsupported_not_replayed",
        }
        if kind in {"add_primary_key", "add_unique", "add_index"}:
            paren = structural.find("(")
            if paren < 0:
                fail(f"{kind} sem lista de colunas")
            prefix = structural[:paren]
            if kind == "add_primary_key":
                valid_prefix = re.fullmatch(r"\s*ADD\s+PRIMARY\s+KEY\s*", prefix, re.IGNORECASE)
            elif kind == "add_unique":
                valid_prefix = re.fullmatch(r"\s*ADD\s+UNIQUE(?:\s+(?:KEY|INDEX))?(?:\s+(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*))?\s*", prefix, re.IGNORECASE)
            else:
                valid_prefix = re.fullmatch(r"\s*ADD\s+(?:KEY|INDEX)(?:\s+(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*))?\s*", prefix, re.IGNORECASE)
            if not valid_prefix:
                fail(f"prefixo {kind} contém texto não reconhecido")
            column_body, _, close = first_paren_contents(structural, paren)
            validate_index_tail(structural[close + 1:])
            column_parts = parse_index_parts(column_body)
            item["columns"] = [part["column_name"] for part in column_parts]
            item["column_parts"] = column_parts
            item["prefix_requires_review"] = any(part["prefix_length"] is not None for part in column_parts)
            name_match = re.match(
                r"ADD\s+(?:UNIQUE(?:\s+(?:KEY|INDEX))?|(?:(?:FULLTEXT|SPATIAL)\s+)?(?:KEY|INDEX))\s+(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)",
                structural,
                re.IGNORECASE,
            )
            if name_match:
                item["constraint_name"] = constraint_name or unquote_identifier(name_match.group(1))
        elif kind == "add_foreign_key":
            local_match = re.match(r"\s*ADD\s+FOREIGN\s+KEY\s*(?:(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)\s*)?\(", structural, re.IGNORECASE)
            if not local_match:
                fail("ALTER FOREIGN KEY sem lista local de colunas")
            local_body, _, local_close = first_paren_contents(structural, local_match.end() - 1)
            ref = re.match(rf"\s+REFERENCES\s+(?P<table>{IDENTIFIER})\s*\(", structural[local_close + 1:], re.IGNORECASE)
            if not ref:
                fail("ALTER FOREIGN KEY possui conteúdo extra ou REFERENCES inválido")
            remote_open = local_close + 1 + ref.end() - 1
            remote_body, _, remote_end = first_paren_contents(structural, remote_open)
            tail = structural[remote_end + 1:]
            if tail.strip() and not re.fullmatch(r"\s*(?:ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|RESTRICT|NO\s+ACTION|SET\s+NULL|SET\s+DEFAULT)\s*){0,2}", tail, re.IGNORECASE):
                fail("cauda ALTER FOREIGN KEY não reconhecida")
            parsed_rules = re.findall(r"ON\s+(DELETE|UPDATE)\s+(CASCADE|RESTRICT|NO\s+ACTION|SET\s+NULL|SET\s+DEFAULT)", tail, re.IGNORECASE)
            if len({rule[0].upper() for rule in parsed_rules}) != len(parsed_rules):
                fail("regra ON DELETE/UPDATE duplicada em ALTER FOREIGN KEY")
            item.update({
                "constraint_name": constraint_name or (unquote_identifier(local_match.group(1)) if local_match.group(1) else None),
                "columns": parse_identifier_list(local_body),
                "referenced_table": unquote_identifier(ref.group("table")),
                "referenced_columns": parse_identifier_list(remote_body),
                "rules": parsed_rules,
            })
        elif kind in {"add_column", "modify_column"}:
            column_def = re.sub(r"^\s*(?:ADD|MODIFY)\s+(?:COLUMN\s+)?", "", normalized, count=1, flags=re.IGNORECASE)
            match = COLUMN_RE.match(column_def)
            if not match:
                fail(f"{kind} sem definição de coluna reconhecida")
            item.update(classify_type(column_def))
            item["column_name"] = match.group(1).replace("``", "`")
        elif kind == "change_column":
            change = re.fullmatch(r"\s*CHANGE\s+(?:COLUMN\s+)?(`(?:``|[^`])+`)\s+(`(?:``|[^`])+`\s+[A-Za-z]+.*)\s*", normalized, re.IGNORECASE | re.DOTALL)
            if not change:
                fail("CHANGE COLUMN incompleto ou não reconhecido")
            column_def = change.group(2)
            item.update(classify_type(column_def))
            item["previous_column_name"] = unquote_identifier(change.group(1))
            item["column_name"] = COLUMN_RE.match(column_def).group(1).replace("``", "`")
        elif kind == "drop_object":
            primary = re.fullmatch(r"\s*DROP\s+PRIMARY\s+KEY\s*", structural, re.IGNORECASE)
            drop = re.fullmatch(r"\s*DROP\s+(?:COLUMN|KEY|INDEX|FOREIGN\s+KEY|CONSTRAINT)\s+(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)\s*", structural, re.IGNORECASE)
            if not primary and not drop:
                fail("DROP incompleto ou com cauda não reconhecida")
            item["dropped_name"] = "PRIMARY KEY" if primary else unquote_identifier(drop.group(1))
        elif kind == "rename_object":
            table_rename = re.fullmatch(r"\s*RENAME\s+TO\s+(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)\s*", structural, re.IGNORECASE)
            column_rename = re.fullmatch(r"\s*RENAME\s+COLUMN\s+(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)\s+TO\s+(`(?:``|[^`])+`|[A-Za-z_][A-Za-z0-9_$]*)\s*", structural, re.IGNORECASE)
            if not table_rename and not column_rename:
                fail("RENAME incompleto ou não reconhecido")
            if table_rename:
                item["new_name"] = unquote_identifier(table_rename.group(1))
            else:
                item["old_name"] = unquote_identifier(column_rename.group(1))
                item["new_name"] = unquote_identifier(column_rename.group(2))
        elif kind == "set_auto_increment":
            if not re.fullmatch(r"\s*AUTO_INCREMENT\s*=\s*\d+\s*", structural, re.IGNORECASE):
                fail("AUTO_INCREMENT incompleto ou com cauda não reconhecida")
            item["sequence_value_present"] = True
        operations.append(item)
    return operations


def parse_catalog(sql: str) -> tuple[list[dict[str, Any]], list[dict[str, Any]], dict[str, Any]]:
    raw_statements = split_statements(sql)
    ledger: list[dict[str, Any]] = []
    tables: list[dict[str, Any]] = []
    seen_tables: set[str] = set()
    counts: Counter[str] = Counter()
    foreign_keys = 0
    trigger_internal_dml = 0
    alter_events: list[dict[str, Any]] = []
    alter_suboperation_counts: Counter[str] = Counter()
    other_programmable: list[dict[str, Any]] = []

    for index, source in enumerate(raw_statements, 1):
        statement_id = f"S{index:04d}"
        classified = classify_statement(source["raw"])
        kind = classified["kind"]
        name = classified["object_name"]
        raw_hash = sha256_bytes(source["raw"].encode("utf-8"))
        row: dict[str, Any] = {
            "statement_id": statement_id,
            "ordinal": index,
            "kind": kind,
            "object_name": name,
            "line_start": source["line_start"],
            "line_end": source["line_end"],
            "sha256": raw_hash,
            "status": "catalogued_source_only",
        }
        counts[kind] += 1
        if kind == "CREATE TABLE":
            if name.lower() in seen_tables:
                fail(f"CREATE TABLE duplicado no snapshot: {name}")
            seen_tables.add(name.lower())
            table = parse_table(source["raw"], name, statement_id)
            tables.append(table)
        elif kind == "ALTER TABLE":
            op = classified["operation"]
            alter = ALTER_RE.match(classified["clean"])
            if not alter:
                fail(f"ALTER TABLE inválido: {name}")
            suboperations = classify_alter_suboperations(alter.group("rest"))
            if not suboperations:
                fail(f"ALTER TABLE sem operação reconhecida: {name}")
            for suboperation in suboperations:
                alter_suboperation_counts[suboperation["kind"]] += 1
            foreign_keys += sum(1 for suboperation in suboperations if suboperation["kind"] == "add_foreign_key")
            alter_events.append({"statement_id": statement_id, "table": name, "operation": op, "suboperations": suboperations, "status": "not_replayed"})
            row["status"] = "inventoried_not_replayed"
            row["operation"] = op
            row["suboperations"] = ",".join(item["kind"] for item in suboperations)
        elif kind == "CREATE TRIGGER":
            trigger = re.match(
                rf"^CREATE\s+(?:(?:DEFINER\s*=\s*\S+)\s+)?TRIGGER\s+(?:IF\s+NOT\s+EXISTS\s+)?{IDENTIFIER}\s+(?P<timing>BEFORE|AFTER)\s+(?P<event>INSERT|UPDATE|DELETE)\s+ON\s+(?P<table>{IDENTIFIER})\s+FOR\s+EACH\s+ROW\b",
                classified["clean"],
                re.IGNORECASE | re.DOTALL,
            )
            if not trigger:
                fail(f"header CREATE TRIGGER não reconhecido: {name}")
            body = classified["clean"][trigger.end():]
            body_words = unquoted_words(strip_comments(body))
            internal_dml = any(value in {"INSERT", "UPDATE", "DELETE", "REPLACE"} for value in body_words)
            row.update({"target_table": unquote_identifier(trigger.group("table")), "timing": trigger.group("timing").upper(), "event": trigger.group("event").upper(), "contains_internal_dml": internal_dml})
            trigger_internal_dml += int(internal_dml)
        elif kind in {"CREATE VIEW", "CREATE PROCEDURE", "CREATE FUNCTION", "CREATE EVENT"}:
            other_programmable.append({"kind": kind, "name": name, "status": "not_in_schema_only_source"})
        if kind == "CREATE TABLE":
            foreign_keys += sum(1 for constraint in tables[-1]["constraints"] if constraint["kind"] == "foreign_key")
        ledger.append(row)

    counts_report = {
        "statement_count": len(ledger),
        "create_table": counts["CREATE TABLE"],
        "alter_table": counts["ALTER TABLE"],
        "alter_suboperations": dict(sorted(alter_suboperation_counts.items())),
        "alter_unsupported_suboperations": alter_suboperation_counts["unsupported"],
        "create_trigger": counts["CREATE TRIGGER"],
        "create_view": counts["CREATE VIEW"],
        "create_procedure": counts["CREATE PROCEDURE"],
        "create_function": counts["CREATE FUNCTION"],
        "create_event": counts["CREATE EVENT"],
        "explicit_foreign_keys": foreign_keys,
        "triggers_with_internal_dml": trigger_internal_dml,
    }
    with_tenant = sum(1 for table in tables if table["has_tenant_id"])
    without_tenant = len(tables) - with_tenant
    view_like_total = sum(1 for table in tables if table["view_like_name"])
    view_like_with_tenant = sum(1 for table in tables if table["view_like_name"] and table["has_tenant_id"])
    view_like_without_tenant = view_like_total - view_like_with_tenant
    coverage = {
        **counts_report,
        "tables_with_tenant_id": with_tenant,
        "tables_without_tenant_id": without_tenant,
        "view_like_total": view_like_total,
        "view_like_with_tenant_id": view_like_with_tenant,
        "view_like_without_tenant_id": view_like_without_tenant,
        "other_without_tenant_id": without_tenant - view_like_without_tenant,
        "expected_counts_match": {
            "statement_count": len(ledger) == EXPECTED_STATEMENTS,
            "create_table": counts["CREATE TABLE"] == EXPECTED_TABLES,
            "alter_table": counts["ALTER TABLE"] == EXPECTED_ALTERS,
            "create_trigger": counts["CREATE TRIGGER"] == EXPECTED_TRIGGERS,
            "explicit_foreign_keys": foreign_keys == EXPECTED_FOREIGN_KEYS,
            "tables_with_tenant_id": with_tenant == EXPECTED_WITH_TENANT,
            "tables_without_tenant_id": without_tenant == EXPECTED_WITHOUT_TENANT,
            "view_like_total": view_like_total == EXPECTED_VIEW_LIKE_TOTAL,
            "view_like_with_tenant_id": view_like_with_tenant == EXPECTED_VIEW_LIKE_WITH_TENANT,
            "view_like_without_tenant_id": view_like_without_tenant == EXPECTED_VIEW_LIKE_WITHOUT_TENANT,
            "other_without_tenant_id": without_tenant - view_like_without_tenant == EXPECTED_OTHER_WITHOUT_TENANT,
        },
    }
    partial = {
        "projection": "partial_source_catalog",
        "installed_database_state": "unknown",
        "final_state_available": False,
        "final_state_blockers": ["ALTER TABLE events are inventoried but not semantically replayed"],
        "counts": coverage,
        "tables_from_create_statements": tables,
        "alter_events_not_replayed": alter_events,
        "programmatic_objects_not_present_in_this_source": other_programmable,
    }
    mapping = [
        {"table_name": table["name"], **type_mapping_advisory(column)}
        for table in tables
        for column in table["columns"]
    ]
    partial["type_mapping_advisory_not_executable"] = mapping
    return ledger, tables, {"coverage": coverage, "partial": partial}


def json_bytes(document: Any) -> bytes:
    return (json.dumps(document, ensure_ascii=False, indent=2, sort_keys=True) + "\n").encode("utf-8")


def csv_bytes(rows: Iterable[dict[str, Any]], fields: Sequence[str]) -> bytes:
    from io import StringIO
    buffer = StringIO(newline="")
    writer = csv.DictWriter(buffer, fieldnames=list(fields), extrasaction="ignore", lineterminator="\n")
    writer.writeheader()
    for row in rows:
        writer.writerow(row)
    return buffer.getvalue().encode("utf-8")


def write_bytes_atomic(path: Path, content: bytes) -> None:
    temporary: str | None = None
    try:
        with tempfile.NamedTemporaryFile(dir=path.parent, prefix=f".{path.name}.", delete=False) as handle:
            temporary = handle.name
            os.chmod(handle.fileno(), 0o600)
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
        os.chmod(path, 0o600)
    except OSError:
        if temporary:
            try:
                os.unlink(temporary)
            except OSError:
                pass
        raise


def publish_bundle(output_dir: Path, artifacts: dict[str, bytes]) -> dict[str, str]:
    output_dir = Path(os.path.abspath(output_dir))
    repo_root = Path(__file__).resolve().parents[2]
    if output_dir.resolve().is_relative_to(repo_root):
        fail("output-dir não pode estar dentro do checkout Git")
    parent = output_dir.parent
    if not parent.exists():
        parent.mkdir(mode=0o700)
    if parent.is_symlink() or not parent.is_dir():
        fail("diretório pai inválido")
    if parent.resolve(strict=True) != parent:
        fail("caminho do diretório pai contém symlink")
    parent_stat = parent.stat()
    if hasattr(os, "geteuid") and parent_stat.st_uid != os.geteuid():
        fail("diretório pai deve pertencer ao usuário executor")
    if stat.S_IMODE(parent_stat.st_mode) & 0o077:
        fail("diretório pai deve negar acesso a grupo e outros usuários")
    if os.path.lexists(output_dir):
        fail("destino já existe; use diretório novo para impedir sobrescrita")
    stage = Path(tempfile.mkdtemp(prefix=f".{output_dir.name}.stage-", dir=parent))
    os.chmod(stage, 0o700)
    try:
        for name, content in artifacts.items():
            if Path(name).name != name:
                fail("nome de artefato inválido")
            write_bytes_atomic(stage / name, content)
        renameat2 = getattr(ctypes.CDLL(None, use_errno=True), "renameat2", None)
        if renameat2 is None:
            fail("renameat2 não disponível; publicação no-replace indisponível")
        renameat2.argtypes = (ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint)
        renameat2.restype = ctypes.c_int
        if renameat2(-100, os.fsencode(stage), -100, os.fsencode(output_dir), 1) != 0:
            error = ctypes.get_errno()
            if error in {errno.ENOSYS, errno.EINVAL, errno.EOPNOTSUPP}:
                fail("filesystem/kernel sem suporte a renameat2(RENAME_NOREPLACE); bundle não publicado")
            raise OSError(error, os.strerror(error), str(output_dir))
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
        if stage.exists():
            for child in stage.iterdir():
                try:
                    child.unlink()
                except OSError:
                    pass
            try:
                stage.rmdir()
            except OSError:
                pass
        raise
    return {name: str(output_dir / name) for name in artifacts}


def run(input_path: Path, output_dir: Path, expected_sha256: str) -> dict[str, str]:
    if not re.fullmatch(r"[0-9a-fA-F]{64}", expected_sha256 or ""):
        fail("expected-input-sha256 deve ser hexadecimal SHA-256")
    if not input_path.is_file():
        fail("input deve ser um arquivo regular legível")
    try:
        fd = os.open(input_path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
        try:
            info = os.fstat(fd)
            if not stat.S_ISREG(info.st_mode):
                fail("input deve ser arquivo regular, sem symlink")
            if info.st_size > MAX_INPUT_BYTES:
                fail(f"input excede {MAX_INPUT_BYTES} bytes")
            chunks = bytearray()
            while len(chunks) <= MAX_INPUT_BYTES:
                block = os.read(fd, min(1024 * 1024, MAX_INPUT_BYTES + 1 - len(chunks)))
                if not block:
                    break
                chunks.extend(block)
        finally:
            os.close(fd)
    except OSError as exc:
        fail(f"falha de leitura do input: {type(exc).__name__}")
    raw = bytes(chunks)
    if len(raw) > MAX_INPUT_BYTES:
        fail(f"input excede {MAX_INPUT_BYTES} bytes")
    actual_hash = sha256_bytes(raw)
    if actual_hash.lower() != expected_sha256.lower():
        fail("SHA-256 do input diverge do valor aprovado")
    try:
        sql = raw.decode("utf-8")
    except UnicodeDecodeError:
        fail("input não está codificado em UTF-8")
    ledger, tables, result = parse_catalog(sql)
    coverage = result["coverage"]
    partial = result["partial"]
    if not all(coverage["expected_counts_match"].values()):
        fail("schema-only diverge do fingerprint estrutural aprovado; bundle não publicado")

    statement_rows = [
        {key: row.get(key, "") for key in ("statement_id", "ordinal", "kind", "object_name", "line_start", "line_end", "sha256", "status", "operation", "target_table", "timing", "event", "contains_internal_dml")}
        for row in ledger
    ]
    statement_csv = csv_bytes(statement_rows, ("statement_id", "ordinal", "kind", "object_name", "line_start", "line_end", "sha256", "status", "operation", "target_table", "timing", "event", "contains_internal_dml"))
    objects_json = json_bytes({"tables": tables, "note": "DDL bruto não é copiado para este catálogo."})
    partial_json = json_bytes(partial)
    mapping_json = json_bytes({
        "mapping_version": 1,
        "status": "advisory_not_executable",
        "source_sha256": actual_hash,
        "items": partial["type_mapping_advisory_not_executable"],
        "ddl_generated": False,
    })
    coverage_json = json_bytes({"coverage": coverage, "source_sha256": actual_hash, "ddl_body_exported": False})
    artifacts = {
        "statements.csv": statement_csv,
        "objects_partial.json": objects_json,
        "catalog_partial.json": partial_json,
        "type_mapping_advisory.json": mapping_json,
        "source_coverage.json": coverage_json,
    }
    manifest = {
        "manifest_version": 1,
        "tool": "catalog_schema_only.py",
        "tool_sha256": sha256_file(Path(__file__)),
        "input_sha256": actual_hash,
        "input_bytes": len(raw),
        "statement_count": len(ledger),
        "policy": {"network_used": False, "database_connected": False, "sql_executed": False, "row_data_exported": False, "raw_ddl_written_to_outputs": False},
        "projection": "partial_source_catalog",
        "final_state_available": False,
        "artifacts": [{"file": name, "sha256": sha256_bytes(content), "bytes": len(content)} for name, content in artifacts.items()],
    }
    manifest_raw = json_bytes(manifest)
    artifacts["manifest.json"] = manifest_raw
    artifacts["manifest.json.sha256"] = f"{sha256_bytes(manifest_raw)}  manifest.json\n".encode("ascii")
    return publish_bundle(output_dir, artifacts)


def parse_args(argv: Sequence[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", type=Path, required=True, help="DDL schema-only, nunca dump com linhas")
    parser.add_argument("--expected-input-sha256", required=True, help="hash conferido por canal confiável")
    parser.add_argument("--output-dir", type=Path, required=True, help="diretório novo, privado e fora do checkout")
    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    try:
        outputs = run(args.input.absolute(), args.output_dir.absolute(), args.expected_input_sha256)
    except (CatalogError, OSError) as exc:
        print(f"ERRO: {exc}", file=sys.stderr)
        return 2
    for name, path in outputs.items():
        print(f"{name}: {path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
