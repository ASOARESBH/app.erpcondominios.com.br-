#!/usr/bin/env python3
"""Shared fail-closed primitives for the P5 operational runners."""
from __future__ import annotations

import fcntl
import json
import os
from pathlib import Path
import re
import stat
from typing import Any, Iterable

ROOT_UID = 0
TICKET_RE = re.compile(r"^[A-Z0-9][A-Z0-9._-]{5,63}$")


class P5Error(RuntimeError):
    def __init__(self, code: str):
        self.code = code
        super().__init__(code)


def _no_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise P5Error("JSON_DUPLICATE_KEY")
        result[key] = value
    return result


def read_json_private(path: Path, *, expected_keys: Iterable[str], owner_uid: int = ROOT_UID) -> dict[str, Any]:
    validate_no_symlink_components(path)
    try:
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != owner_uid or stat.S_IMODE(info.st_mode) != 0o600:
            raise P5Error("PRIVATE_FILE_MODE_OR_OWNER_INVALID")
        raw = path.read_bytes()
        data = json.loads(raw.decode("utf-8", errors="strict"), object_pairs_hook=_no_duplicate_keys)
    except P5Error:
        raise
    except Exception as error:
        raise P5Error("PRIVATE_FILE_INVALID") from error
    if not isinstance(data, dict) or set(data) != set(expected_keys):
        raise P5Error("PRIVATE_FILE_SCHEMA_INVALID")
    return data


def validate_no_symlink_components(path: Path) -> None:
    """Reject symlinks and non-directories along an absolute, already-allowlisted path."""
    if not path.is_absolute() or ".." in path.parts:
        raise P5Error("PATH_NOT_ALLOWLISTED")
    cursor = Path(path.anchor)
    for part in path.parts[1:]:
        cursor = cursor / part
        try:
            info = cursor.lstat()
        except FileNotFoundError:
            # A missing suffix is allowed for staged outputs only.
            continue
        except OSError as error:
            raise P5Error("PATH_COMPONENT_INVALID") from error
        if stat.S_ISLNK(info.st_mode):
            raise P5Error("SYMLINK_NOT_ALLOWED")
        if cursor != path and not stat.S_ISDIR(info.st_mode):
            raise P5Error("PATH_COMPONENT_INVALID")


def validate_root_file(path: Path, *, mode: int = 0o600, owner_uid: int = ROOT_UID) -> None:
    validate_no_symlink_components(path)
    try:
        info = path.lstat()
    except OSError as error:
        raise P5Error("REQUIRED_FILE_MISSING") from error
    if not stat.S_ISREG(info.st_mode) or info.st_uid != owner_uid or stat.S_IMODE(info.st_mode) != mode:
        raise P5Error("FILE_MODE_OR_OWNER_INVALID")


def validate_root_directory(path: Path, *, mode: int = 0o700, owner_uid: int = ROOT_UID) -> None:
    validate_no_symlink_components(path)
    try:
        info = path.lstat()
    except OSError as error:
        raise P5Error("REQUIRED_DIRECTORY_MISSING") from error
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != owner_uid or stat.S_IMODE(info.st_mode) != mode:
        raise P5Error("DIRECTORY_MODE_OR_OWNER_INVALID")


def validate_approval(
    path: Path,
    *,
    action: str,
    target: str,
    source_schema: str | None,
    destination: str,
    owner_uid: int = ROOT_UID,
) -> None:
    fields = {"approved", "action", "target", "source_schema", "destination", "ticket", "expires_utc"}
    data = read_json_private(path, expected_keys=fields, owner_uid=owner_uid)
    import datetime as _dt

    if data.get("approved") is not True or data.get("action") != action or data.get("target") != target \
       or data.get("source_schema") != source_schema or data.get("destination") != destination \
       or not isinstance(data.get("ticket"), str) or not TICKET_RE.fullmatch(data["ticket"]):
        raise P5Error("APPROVAL_SCOPE_MISMATCH")
    expiry = data.get("expires_utc")
    if not isinstance(expiry, str):
        raise P5Error("APPROVAL_EXPIRED_OR_INVALID")
    try:
        parsed = _dt.datetime.strptime(expiry, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=_dt.timezone.utc)
    except ValueError as error:
        raise P5Error("APPROVAL_EXPIRED_OR_INVALID") from error
    now = _dt.datetime.now(_dt.timezone.utc)
    if parsed <= now or parsed > now + _dt.timedelta(hours=24):
        raise P5Error("APPROVAL_EXPIRED_OR_INVALID")


def exclusive_lock(path: Path, *, owner_uid: int = ROOT_UID):
    """Return a locked 0600 regular file descriptor; never follow a symlink."""
    validate_no_symlink_components(path)
    flags = os.O_CREAT | os.O_RDWR | getattr(os, "O_NOFOLLOW", 0) | getattr(os, "O_CLOEXEC", 0)
    try:
        fd = os.open(path, flags, 0o600)
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != owner_uid or stat.S_IMODE(info.st_mode) != 0o600:
            os.close(fd)
            raise P5Error("LOCK_MODE_OR_OWNER_INVALID")
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return fd
    except BlockingIOError as error:
        raise P5Error("LOCK_BUSY") from error
    except P5Error:
        raise
    except OSError as error:
        raise P5Error("LOCK_UNAVAILABLE") from error


def root_required() -> None:
    if os.geteuid() != 0:
        raise P5Error("ROOT_REQUIRED")


def emit_error(error: P5Error) -> int:
    # Codes only: never print input, command output, hashes, secrets, or dump content.
    import sys
    print(f"P5_ERROR={error.code}", file=sys.stderr)
    return 2
