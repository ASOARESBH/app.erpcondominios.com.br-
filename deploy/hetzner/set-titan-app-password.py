#!/usr/bin/env python3
"""Install a Titan application password via hidden TTY input."""
from __future__ import annotations

import getpass
import os
import re
import stat
import sys
import tempfile
from pathlib import Path

DEST_DIR = Path("/etc/erpcondominios/secrets")
DEST = DEST_DIR / "titan-app-password"


def _secure_directory() -> bool:
    try:
        st = DEST_DIR.lstat()
        return stat.S_ISDIR(st.st_mode) and st.st_uid == 0 and st.st_gid == 0 and stat.S_IMODE(st.st_mode) == 0o700 and not DEST_DIR.is_symlink()
    except OSError:
        return False


def _write_secret(secret: bytes) -> None:
    fd, temporary = tempfile.mkstemp(prefix=".titan-app-", dir=DEST_DIR)
    try:
        os.fchown(fd, 0, 0)
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "wb") as handle:
            handle.write(secret)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, DEST)
        os.chown(DEST, 0, 0)
        os.chmod(DEST, 0o600)
        dir_fd = os.open(DEST_DIR, os.O_DIRECTORY)
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
    finally:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass


def main() -> int:
    if os.geteuid() != 0:
        print("titan_secret=failed reason=privilege")
        return 2
    if not sys.stdin.isatty() or not sys.stderr.isatty():
        print("titan_secret=failed reason=interactive_tty_required")
        return 3
    if not _secure_directory():
        print("titan_secret=failed reason=directory_metadata")
        return 4

    replace = len(sys.argv) == 2 and sys.argv[1] == "--replace"
    if len(sys.argv) > 2 or (len(sys.argv) == 2 and not replace):
        print("titan_secret=failed reason=usage")
        return 5
    if DEST.exists() or DEST.is_symlink():
        if not replace:
            print("titan_secret=failed reason=already_exists")
            return 6
        if getpass.getpass("Replacement requested. Type REPLACE to continue: ") != "REPLACE":
            print("titan_secret=unchanged")
            return 0

    first = getpass.getpass("Titan application password (input hidden): ")
    second = getpass.getpass("Repeat Titan application password: ")
    if first != second:
        print("titan_secret=failed reason=mismatch")
        return 7
    try:
        secret = first.encode("ascii", "strict")
    except UnicodeEncodeError:
        print("titan_secret=failed reason=unsupported_characters")
        return 8
    if not re.fullmatch(rb"[!-~]{1,1024}", secret):
        print("titan_secret=failed reason=unsupported_characters")
        return 8

    try:
        _write_secret(secret)
    except OSError:
        print("titan_secret=failed reason=write")
        return 9
    print("titan_secret=installed root_only=0600")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
