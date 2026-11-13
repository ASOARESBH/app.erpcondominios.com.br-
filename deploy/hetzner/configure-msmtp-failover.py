#!/usr/bin/env python3
"""Configure TITAN as primary and the existing Brevo account as fallback."""
from __future__ import annotations

import os
import re
import stat
import sys
import tempfile
from pathlib import Path

DEST_DIR = Path("/etc/erpcondominios/secrets")
CONFIG_PATH = DEST_DIR / "msmtprc"
BREVO_PASSWORD = DEST_DIR / "smtp-password"
TITAN_PASSWORD = DEST_DIR / "titan-app-password"
SENDER = "noreply@erpcondominios.com.br"
TITAN_HOST = "smtp.titan.email"
BREVO_HOST = "smtp-relay.brevo.com"
PASSWORD_EVAL_BREVO = f"/bin/cat {BREVO_PASSWORD}"
PASSWORD_EVAL_TITAN = f"/bin/cat {TITAN_PASSWORD}"


def _parse_config(text: str) -> tuple[dict[str, str], dict[str, dict[str, str]], dict[str, list[str]]]:
    defaults: dict[str, str] = {}
    accounts: dict[str, dict[str, str]] = {}
    inherits: dict[str, list[str]] = {}
    current: dict[str, str] | None = None
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if line == "defaults":
            current = defaults
            continue
        match = re.fullmatch(r"account\s+([A-Za-z0-9_-]+)(?:\s*:\s*([A-Za-z0-9_,-]+))?", line)
        if match:
            name, inherited = match.groups()
            if name in accounts:
                raise ValueError("duplicate account")
            accounts[name] = {}
            inherits[name] = inherited.split(",") if inherited else []
            current = accounts[name]
            continue
        if current is None:
            raise ValueError("directive outside account/defaults")
        parts = line.split(None, 1)
        if len(parts) != 2 or parts[0] in current:
            raise ValueError("invalid or duplicate directive")
        current[parts[0]] = parts[1]
    return defaults, accounts, inherits


def _value(defaults: dict[str, str], account: dict[str, str], name: str) -> str:
    return account.get(name, defaults.get(name, ""))


def _security_valid(defaults: dict[str, str], account: dict[str, str]) -> bool:
    expected = {
        "auth": "on",
        "tls": "on",
        "tls_trust_file": "system",
        "tls_certcheck": "on",
        "syslog": "off",
        "allow_from_override": "off",
        "set_from_header": "on",
    }
    return all(_value(defaults, account, key) == value for key, value in expected.items())


def _render_config(brevo: dict[str, str]) -> str:
    port = brevo["port"]
    starttls = "on" if port == "587" else "off"
    return (
        "defaults\n"
        "auth on\n"
        "tls on\n"
        "tls_trust_file system\n"
        "tls_certcheck on\n"
        "syslog off\n"
        "allow_from_override off\n"
        "set_from_header on\n"
        "timeout 10\n"
        "\n"
        "account titan\n"
        f"host {TITAN_HOST}\n"
        "port 465\n"
        "tls_starttls off\n"
        f"user {SENDER}\n"
        f"from {SENDER}\n"
        f"passwordeval {PASSWORD_EVAL_TITAN}\n"
        "\n"
        "account brevo\n"
        f"host {BREVO_HOST}\n"
        f"port {port}\n"
        f"tls_starttls {starttls}\n"
        f"user {brevo['user']}\n"
        f"from {SENDER}\n"
        f"passwordeval {PASSWORD_EVAL_BREVO}\n"
        "\n"
        "account default : titan\n"
    )


def _secure_regular(path: Path, allow_empty: bool = False) -> bool:
    try:
        st = path.lstat()
        return (
            stat.S_ISREG(st.st_mode)
            and st.st_uid == 0
            and st.st_gid == 0
            and stat.S_IMODE(st.st_mode) == 0o600
            and not path.is_symlink()
            and (allow_empty or st.st_size > 0)
        )
    except OSError:
        return False


def _secure_password(path: Path) -> bool:
    if not _secure_regular(path):
        return False
    try:
        value = path.read_bytes()
        return 1 <= len(value) <= 1024 and all(33 <= byte <= 126 for byte in value)
    except OSError:
        return False


def _atomic_replace(content: str) -> None:
    fd, temporary = tempfile.mkstemp(prefix=".msmtprc-", dir=DEST_DIR)
    try:
        os.fchown(fd, 0, 0)
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="ascii") as handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, CONFIG_PATH)
        os.chown(CONFIG_PATH, 0, 0)
        os.chmod(CONFIG_PATH, 0o600)
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


def configure(text: str) -> str:
    defaults, accounts, inherits = _parse_config(text)
    if set(accounts) == {"titan", "brevo", "default"} and inherits.get("default") == ["titan"]:
        brevo = accounts["brevo"]
        port = brevo.get("port", "")
        user = brevo.get("user", "")
        if (_value(defaults, brevo, "host") == BREVO_HOST
                and port in {"465", "587"}
                and re.fullmatch(r"[A-Za-z0-9@._+-]{1,254}", user)
                and _value(defaults, brevo, "from").lower() == SENDER.lower()
                and _value(defaults, brevo, "passwordeval") == PASSWORD_EVAL_BREVO
                and _value(defaults, brevo, "tls_starttls") == ("on" if port == "587" else "off")
                and _security_valid(defaults, brevo)
                and _render_config({"port": port, "user": user}) == text):
            return text
        raise ValueError("existing dual-account config failed validation")

    if set(accounts) != {"default"} or inherits.get("default"):
        raise ValueError("unexpected account layout")
    account = accounts["default"]
    port = _value(defaults, account, "port")
    host = _value(defaults, account, "host")
    user = _value(defaults, account, "user")
    from_address = _value(defaults, account, "from")
    passwordeval = _value(defaults, account, "passwordeval")
    starttls = _value(defaults, account, "tls_starttls")
    if (host != BREVO_HOST or port not in {"465", "587"}
            or from_address.lower() != SENDER.lower()
            or passwordeval != PASSWORD_EVAL_BREVO
            or not re.fullmatch(r"[A-Za-z0-9@._+-]{1,254}", user)
            or starttls != ("on" if port == "587" else "off")
            or not _security_valid(defaults, account)):
        raise ValueError("existing Brevo account failed validation")
    account_values = {
        "host": host,
        "port": port,
        "user": user,
        "from": from_address,
        "passwordeval": passwordeval,
    }
    return _render_config(account_values)


def main() -> int:
    if os.geteuid() != 0:
        print("msmtp_config=failed reason=privilege")
        return 2
    try:
        dst = DEST_DIR.lstat()
        if (not stat.S_ISDIR(dst.st_mode) or dst.st_uid != 0
                or stat.S_IMODE(dst.st_mode) != 0o700 or DEST_DIR.is_symlink()
                or dst.st_gid != 0 or not _secure_password(BREVO_PASSWORD)):
            print("msmtp_config=failed reason=secret_metadata")
            return 3
        if not _secure_password(TITAN_PASSWORD):
            print("msmtp_config=failed reason=titan_secret_missing_or_invalid")
            return 4
        if not _secure_regular(CONFIG_PATH):
            print("msmtp_config=failed reason=config_metadata")
            return 5
        current = CONFIG_PATH.read_text(encoding="ascii")
        updated = configure(current)
        if updated != current:
            _atomic_replace(updated)
        print("msmtp_config=dual_account_ready")
        return 0
    except (OSError, UnicodeError, ValueError):
        print("msmtp_config=failed reason=validation")
        return 6


if __name__ == "__main__":
    raise SystemExit(main())
