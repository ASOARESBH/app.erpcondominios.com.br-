#!/usr/bin/env python3
"""Extract the authorized Brevo SMTP relay credential from a private SQL dump."""
from __future__ import annotations

import gzip
import os
import re
import stat
import sys
import tempfile
from pathlib import Path

COLUMNS = [
    "id", "tenant_id", "provedor", "smtp_host", "smtp_port", "smtp_usuario",
    "smtp_senha", "smtp_de_email", "smtp_de_nome", "smtp_seguranca", "timeout",
    "smtp_ativo", "email_provider", "api_key", "sender_email", "sender_name",
    "data_atualizacao",
]
SENDER = b"noreply@erpcondominios.com.br"
DEST_DIR = Path("/etc/erpcondominios/secrets")
CONFIG_PATH = DEST_DIR / "msmtprc"
PASSWORD_PATH = DEST_DIR / "smtp-password"


def _parse_values(data: bytes) -> list[list[bytes | None]]:
    index = 0
    length = len(data)
    rows: list[list[bytes | None]] = []

    def skip_space() -> None:
        nonlocal index
        while index < length and data[index] in b" \t\r\n":
            index += 1

    def parse_value() -> bytes | None:
        nonlocal index
        skip_space()
        if index >= length:
            raise ValueError("truncated SQL tuple")
        if data[index] in (ord("'"), ord('"')):
            quote = data[index]
            index += 1
            result = bytearray()
            escapes = {48: 0, 98: 8, 110: 10, 114: 13, 116: 9, 90: 26,
                       92: 92, 39: 39, 34: 34}
            while index < length:
                char = data[index]
                index += 1
                if char == quote:
                    if index < length and data[index] == quote:
                        result.append(quote)
                        index += 1
                        continue
                    return bytes(result)
                if char == ord("\\") and index < length:
                    escaped = data[index]
                    index += 1
                    result.append(escapes.get(escaped, escaped))
                else:
                    result.append(char)
            raise ValueError("unterminated SQL literal")
        start = index
        while index < length and data[index] not in b",)":
            index += 1
        token = data[start:index].strip()
        if token.upper() == b"NULL":
            return None
        if token[:2].lower() == b"0x":
            return bytes.fromhex(token[2:].decode("ascii"))
        return token

    while index < length:
        skip_space()
        if index >= length or data[index] == ord(";"):
            break
        if data[index] != ord("("):
            raise ValueError("unsupported SQL VALUES format")
        index += 1
        row: list[bytes | None] = []
        while True:
            row.append(parse_value())
            skip_space()
            if index >= length:
                raise ValueError("unterminated SQL tuple")
            if data[index] == ord(","):
                index += 1
                continue
            if data[index] == ord(")"):
                index += 1
                break
            raise ValueError("invalid SQL tuple delimiter")
        rows.append(row)
        skip_space()
        if index < length and data[index] == ord(","):
            index += 1
            continue
        if index < length and data[index] == ord(";"):
            break
    return rows


def _text(value: bytes | None) -> str:
    return (value or b"").decode("utf-8", "strict").strip()


def _statement_end(data: bytes) -> int | None:
    quote = 0
    index = 0
    while index < len(data):
        char = data[index]
        if quote:
            if char == ord("\\") and quote != ord("`"):
                index += 2
                continue
            if char == quote:
                if index + 1 < len(data) and data[index + 1] == quote:
                    index += 2
                    continue
                quote = 0
        elif char in (ord("'"), ord('"'), ord("`")):
            quote = char
        elif char == ord(";"):
            return index
        index += 1
    return None


def _insert_statements(stream):
    iterator = iter(stream)
    for line in iterator:
        if not re.match(rb"INSERT INTO `configuracao_smtp`", line, re.I):
            continue
        buffer = bytearray(line)
        end = _statement_end(buffer)
        while end is None:
            try:
                buffer.extend(next(iterator))
            except StopIteration as exc:
                raise ValueError("unterminated SQL INSERT") from exc
            end = _statement_end(buffer)
        if bytes(buffer[end + 1:]).strip():
            raise ValueError("unexpected trailing bytes after SQL INSERT")
        yield bytes(buffer[:end])


def _write_new(path: Path, content: bytes) -> None:
    fd, temporary = tempfile.mkstemp(prefix=".smtp-", dir=DEST_DIR)
    try:
        os.fchown(fd, 0, 0)
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "wb") as output:
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
        os.link(temporary, path)
        os.unlink(temporary)
    finally:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass


def _config(host: str, port: str, user: str, from_address: str) -> bytes:
    if port == "587":
        starttls = "on"
    elif port == "465":
        starttls = "off"
    else:
        raise ValueError("unsupported SMTP port")
    text = (
        "defaults\n"
        "auth on\n"
        "tls on\n"
        f"tls_starttls {starttls}\n"
        "tls_trust_file system\n"
        "tls_certcheck on\n"
        "syslog off\n"
        "allow_from_override off\n"
        "set_from_header on\n"
        "timeout 20\n"
        "account default\n"
        f"host {host}\n"
        f"port {port}\n"
        f"user {user}\n"
        f"from {from_address}\n"
        f"passwordeval /bin/cat {PASSWORD_PATH}\n"
        "\n"
    )
    return text.encode("ascii")


def main() -> int:
    if os.geteuid() != 0 or len(sys.argv) != 2:
        print("credential_extract=failed reason=usage_or_privilege")
        return 2
    source = Path(sys.argv[1])
    try:
        source_meta = source.lstat()
        if (not stat.S_ISREG(source_meta.st_mode) or source_meta.st_uid != 0
                or stat.S_IMODE(source_meta.st_mode) != 0o600 or source.is_symlink()
                or not source.name.endswith(".mysql.sql.gz")):
            print("credential_extract=failed reason=source_metadata")
            return 3
        if not DEST_DIR.is_dir() or DEST_DIR.is_symlink():
            print("credential_extract=failed reason=secret_directory")
            return 4
        dst = DEST_DIR.lstat()
        if dst.st_uid != 0 or stat.S_IMODE(dst.st_mode) != 0o700:
            print("credential_extract=failed reason=secret_directory")
            return 4
        if CONFIG_PATH.exists() or CONFIG_PATH.is_symlink() or PASSWORD_PATH.exists() or PASSWORD_PATH.is_symlink():
            print("credential_extract=failed reason=refusing_overwrite")
            return 5

        matching = []
        with gzip.open(source, "rb") as stream:
            for statement in _insert_statements(stream):
                match = re.search(rb"\bVALUES\s+(.*)\Z", statement, re.I | re.S)
                if not match:
                    raise ValueError("unsupported INSERT format")
                for values in _parse_values(match.group(1)):
                    if len(values) != len(COLUMNS):
                        raise ValueError("unexpected SMTP row shape")
                    row = dict(zip(COLUMNS, values))
                    sender_values = {
                        _text(row.get("smtp_de_email")).encode("utf-8").lower(),
                        _text(row.get("sender_email")).encode("utf-8").lower(),
                    }
                    if SENDER.lower() in sender_values:
                        matching.append(row)

        if len(matching) != 1:
            print("credential_extract=failed reason=sender_match_count")
            return 6
        row = matching[0]
        provider = (_text(row.get("provedor")) + " " + _text(row.get("email_provider"))).lower()
        host = _text(row.get("smtp_host"))
        port = _text(row.get("smtp_port"))
        user = _text(row.get("smtp_usuario"))
        password = row.get("smtp_senha") or b""
        api_key = row.get("api_key") or b""
        password_ascii = password.decode("ascii", "strict")
        active = _text(row.get("smtp_ativo")).lower() in {"1", "true", "yes", "on", "ativo", "active"}
        security = _text(row.get("smtp_seguranca")).lower()
        valid_host = bool(re.fullmatch(r"(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}", host))
        if ("brevo" not in provider or not valid_host or port not in {"587", "465"}
                or not re.fullmatch(r"[A-Za-z0-9@._+-]{1,254}", user)
                or not password_ascii or not re.fullmatch(r"[!-~]{1,512}", password_ascii)
                or password == api_key or not active
                or security not in {"tls", "ssl", "starttls", "smtps", "1", "2"}):
            print("credential_extract=failed reason=configuration_gate")
            return 7

        config = _config(host, port, user, SENDER.decode("ascii"))
        try:
            _write_new(PASSWORD_PATH, password)
            _write_new(CONFIG_PATH, config)
        except Exception:
            CONFIG_PATH.unlink(missing_ok=True)
            PASSWORD_PATH.unlink(missing_ok=True)
            raise
        print("credential_extract=success files=root_only")
        return 0
    except Exception:
        print("credential_extract=failed reason=internal")
        return 10


if __name__ == "__main__":
    raise SystemExit(main())
