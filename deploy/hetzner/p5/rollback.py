#!/usr/bin/env python3
"""Root-only one-command rollback of the production release and private MySQL config."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import pwd
import re
import stat
import subprocess
import time
from pathlib import Path

from p5_common import (
    P5Error,
    emit_error,
    exclusive_lock,
    read_json_private,
    root_required,
    validate_approval,
    validate_no_symlink_components,
    validate_root_directory,
    validate_root_file,
)

APP_ROOT = Path("/var/www/erpcondominios")
RELEASES = APP_ROOT / "releases"
CURRENT = APP_ROOT / "current"
CONFIG_ROOTS = (
    Path("/etc/erpcondominios/secrets"),
    APP_ROOT / "erpcondominios-private",
)
BACKUP_ROOT = Path("/var/backups/erpcondominios/p5-rollback")
STATE = Path("/etc/erpcondominios/p5/rollback-state.json")
APPROVAL = Path("/etc/erpcondominios/p5/rollback-approval.json")
LOCK = Path("/run/lock/erpcondominios-p5-rollback.lock")
NGINX = Path("/usr/sbin/nginx")
SYSTEMCTL = Path("/usr/bin/systemctl")
ALLOW_CONFIG = Path("/etc/nginx/conf.d/erp-p5-maintenance-maps.conf")
ACTIVE_ENTRIES = Path("/etc/nginx/conf.d/erp-p5-maintenance-active.map")
PRODUCTION_DOMAIN = "app.erpcondominios.com.br"
PRODUCTION_IP = "2.29.161.106"
PRODUCTION_SCHEMA = "erpcondominios_stage_20261114"
STATE_KEYS = {
    "approved_state",
    "action",
    "current",
    "expected_current_target",
    "previous_release",
    "private_config_path",
    "mysql_config_backup",
    "mysql_config_sha256",
    "previous_driver",
    "previous_schema",
    "ticket",
}
SHA_RE = re.compile(r"^[a-f0-9]{40}$")
SHA256_RE = re.compile(r"^[a-f0-9]{64}$")
TICKET_RE = re.compile(r"^[A-Z0-9][A-Z0-9._-]{5,63}$")


def _safe_release(sha: str, *, owner_uid: int = 0) -> Path:
    if not isinstance(sha, str) or not SHA_RE.fullmatch(sha):
        raise P5Error("RELEASE_ID_NOT_ALLOWLISTED")
    validate_root_directory(APP_ROOT, mode=0o755, owner_uid=owner_uid)
    validate_root_directory(RELEASES, mode=0o755, owner_uid=owner_uid)
    path = RELEASES / sha
    validate_no_symlink_components(path)
    try:
        info = path.lstat()
    except OSError as error:
        raise P5Error("RELEASE_MISSING") from error
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != owner_uid or stat.S_IMODE(info.st_mode) & 0o022:
        raise P5Error("RELEASE_MODE_OR_OWNER_INVALID")
    return path


def _private_path(value: object, *, roots: tuple[Path, ...], code: str) -> Path:
    if not isinstance(value, str):
        raise P5Error(code)
    path = Path(value)
    if not path.is_absolute() or ".." in path.parts:
        raise P5Error(code)
    if not any(path.is_relative_to(root) and path != root for root in roots):
        raise P5Error(code)
    validate_no_symlink_components(path)
    return path


def _validated_state(*, owner_uid: int = 0) -> tuple[dict[str, object], Path, Path, Path]:
    data = read_json_private(STATE, expected_keys=STATE_KEYS, owner_uid=owner_uid)
    if data.get("approved_state") is not True or data.get("action") != "rollback-production-mysql" \
       or data.get("current") != str(CURRENT) or data.get("previous_driver") != "mysql" \
       or data.get("previous_schema") != PRODUCTION_SCHEMA \
       or not isinstance(data.get("ticket"), str) or not TICKET_RE.fullmatch(data["ticket"]):
        raise P5Error("ROLLBACK_STATE_INVALID")

    expected = data.get("expected_current_target")
    previous_sha = data.get("previous_release")
    if not isinstance(expected, str) or not expected.startswith(str(RELEASES) + "/"):
        raise P5Error("ROLLBACK_STATE_INVALID")
    expected_sha = expected[len(str(RELEASES) + "/"):]
    if not SHA_RE.fullmatch(expected_sha) or not isinstance(previous_sha, str) \
       or not SHA_RE.fullmatch(previous_sha) or previous_sha == expected_sha:
        raise P5Error("ROLLBACK_STATE_INVALID")

    previous = _safe_release(previous_sha, owner_uid=owner_uid)
    config_path = _private_path(data.get("private_config_path"), roots=CONFIG_ROOTS, code="PRIVATE_CONFIG_PATH_NOT_ALLOWLISTED")
    backup_path = _private_path(data.get("mysql_config_backup"), roots=(BACKUP_ROOT,), code="MYSQL_CONFIG_BACKUP_NOT_ALLOWLISTED")
    validate_root_directory(BACKUP_ROOT, mode=0o700, owner_uid=owner_uid)
    validate_root_file(backup_path, mode=0o600, owner_uid=owner_uid)
    if config_path.parent not in CONFIG_ROOTS:
        raise P5Error("PRIVATE_CONFIG_PATH_NOT_ALLOWLISTED")
    validate_root_directory(config_path.parent, mode=0o700, owner_uid=owner_uid)
    try:
        config_info = config_path.lstat()
        app_uid = pwd.getpwnam("www-data").pw_uid
    except (OSError, KeyError) as error:
        raise P5Error("PRIVATE_CONFIG_UNAVAILABLE") from error
    if not stat.S_ISREG(config_info.st_mode) or stat.S_IMODE(config_info.st_mode) != 0o600 \
       or config_info.st_uid not in {owner_uid, app_uid}:
        raise P5Error("PRIVATE_CONFIG_MODE_OR_OWNER_INVALID")

    digest = data.get("mysql_config_sha256")
    if not isinstance(digest, str) or not SHA256_RE.fullmatch(digest):
        raise P5Error("ROLLBACK_STATE_INVALID")
    try:
        raw = backup_path.read_bytes()
    except OSError as error:
        raise P5Error("MYSQL_CONFIG_BACKUP_UNAVAILABLE") from error
    if hashlib.sha256(raw).hexdigest() != digest:
        raise P5Error("MYSQL_CONFIG_PROVENANCE_MISMATCH")
    lowered = raw.lower()
    if b"erp_db_driver" not in lowered or b"mysql" not in lowered or PRODUCTION_SCHEMA.encode() not in raw:
        raise P5Error("MYSQL_CONFIG_CONTENT_NOT_ALLOWLISTED")
    return data, previous, config_path, backup_path


def _current_target(*, owner_uid: int = 0) -> str:
    validate_no_symlink_components(APP_ROOT)
    validate_root_directory(APP_ROOT, mode=0o755, owner_uid=owner_uid)
    try:
        info = CURRENT.lstat()
        target = os.readlink(CURRENT)
    except OSError as error:
        raise P5Error("CURRENT_SYMLINK_INVALID") from error
    prefix = str(RELEASES) + "/"
    if not stat.S_ISLNK(info.st_mode) or not target.startswith(prefix):
        raise P5Error("CURRENT_TARGET_NOT_ALLOWLISTED")
    sha = target[len(prefix):]
    _safe_release(sha, owner_uid=owner_uid)
    if target != prefix + sha:
        raise P5Error("CURRENT_TARGET_NOT_ALLOWLISTED")
    return target


def _maintenance_active(*, runner=subprocess.run, owner_uid: int = 0) -> None:
    validate_root_file(ALLOW_CONFIG, mode=0o644, owner_uid=owner_uid)
    validate_root_file(ACTIVE_ENTRIES, mode=0o644, owner_uid=owner_uid)
    try:
        allow_config = ALLOW_CONFIG.read_text(encoding="utf-8")
        active_entries = ACTIVE_ENTRIES.read_text(encoding="utf-8")
    except OSError as error:
        raise P5Error("MAINTENANCE_STATE_UNAVAILABLE") from error
    if "map $remote_addr $p5_maintenance_allowlisted" not in allow_config \
       or "default 0;" not in allow_config \
       or not re.search(r";\s*\d{1,3}(?:\.\d{1,3}){3}\s+1;", allow_config):
        raise P5Error("MAINTENANCE_ALLOWLIST_MAP_INVALID")
    expected_names = "|".join(re.escape(name) for name in (PRODUCTION_DOMAIN, PRODUCTION_IP))
    if active_entries != f"~^(?:{expected_names})$ 1;\n":
        raise P5Error("MAINTENANCE_NOT_ACTIVE")
    if NGINX.is_symlink() or not NGINX.is_file() or not os.access(NGINX, os.X_OK):
        raise P5Error("NGINX_BINARY_UNAVAILABLE")
    try:
        result = runner(
            [str(NGINX), "-T"], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL, env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"},
            close_fds=True, check=False,
        )
    except OSError as error:
        raise P5Error("MAINTENANCE_STATE_UNAVAILABLE") from error
    if result.returncode != 0 or not isinstance(result.stdout, (bytes, str)):
        raise P5Error("MAINTENANCE_STATE_UNAVAILABLE")
    text = result.stdout.decode("utf-8", errors="replace") if isinstance(result.stdout, bytes) else result.stdout
    required = (
        "map $remote_addr $p5_maintenance_allowlisted",
        'map "$p5_maintenance_activation:$p5_maintenance_not_allowlisted" $p5_maintenance_block',
        "if ($p5_maintenance_block != 0) { return 503; }",
        "include /etc/nginx/conf.d/erp-p5-maintenance-active.map;",
        PRODUCTION_DOMAIN,
        PRODUCTION_IP,
    )
    if any(item not in text for item in required):
        raise P5Error("MAINTENANCE_NOT_ACTIVE")


def _fsync_directory(path: Path) -> None:
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_NOFOLLOW", 0))
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def _atomic_private_config(config_path: Path, backup_path: Path, *, owner_uid: int = 0) -> None:
    if config_path.parent not in CONFIG_ROOTS:
        raise P5Error("PRIVATE_CONFIG_PATH_NOT_ALLOWLISTED")
    validate_root_directory(config_path.parent, mode=0o700, owner_uid=owner_uid)
    validate_root_file(backup_path, mode=0o600, owner_uid=owner_uid)
    try:
        config_info = config_path.lstat()
    except OSError as error:
        raise P5Error("PRIVATE_CONFIG_UNAVAILABLE") from error
    if not stat.S_ISREG(config_info.st_mode) or stat.S_IMODE(config_info.st_mode) != 0o600:
        raise P5Error("PRIVATE_CONFIG_MODE_OR_OWNER_INVALID")
    data = backup_path.read_bytes()
    temp = config_path.with_name(f".{config_path.name}.rollback-{os.getpid()}")
    if temp.exists() or temp.is_symlink():
        raise P5Error("ROLLBACK_CONFIG_TEMP_EXISTS")
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0)
    try:
        fd = os.open(temp, flags, 0o600)
        os.fchown(fd, config_info.st_uid, config_info.st_gid)
        os.fchmod(fd, stat.S_IMODE(config_info.st_mode))
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        if hashlib.sha256(temp.read_bytes()).digest() != hashlib.sha256(data).digest():
            raise P5Error("ROLLBACK_CONFIG_STAGE_MISMATCH")
        os.replace(temp, config_path)
        _fsync_directory(config_path.parent)
    except P5Error:
        raise
    except OSError as error:
        raise P5Error("ROLLBACK_CONFIG_RESTORE_FAILED") from error
    finally:
        temp.unlink(missing_ok=True)


def plan(*, owner_uid: int = 0, runner=subprocess.run) -> dict[str, object]:
    data, previous, _config_path, _backup_path = _validated_state(owner_uid=owner_uid)
    current = _current_target(owner_uid=owner_uid)
    expected = data["expected_current_target"]
    previous_target = str(previous)
    if current not in {expected, previous_target}:
        raise P5Error("CURRENT_STATE_MISMATCH")
    _maintenance_active(runner=runner, owner_uid=owner_uid)
    return {
        "status": "ROLLBACK_PLAN_READY",
        "current": str(CURRENT),
        "previous_release": previous.name,
        "database_operations": 0,
        "maintenance_required": True,
    }


def apply(*, owner_uid: int = 0, runner=subprocess.run, system_runner=subprocess.run) -> dict[str, object]:
    root_required()
    started = time.monotonic()
    lock = exclusive_lock(LOCK, owner_uid=owner_uid)
    try:
        validate_approval(
            APPROVAL, action="rollback-production-mysql", target=str(CURRENT), source_schema=None,
            destination="production-mysql-release-and-private-config", owner_uid=owner_uid,
        )
        preview = plan(owner_uid=owner_uid, runner=runner)
        data, previous, config_path, backup_path = _validated_state(owner_uid=owner_uid)
        current_target = _current_target(owner_uid=owner_uid)
        previous_target = str(previous)
        if current_target != previous_target:
            _atomic_private_config(config_path, backup_path, owner_uid=owner_uid)
            temp_link = APP_ROOT / f".current.p5-rollback-{os.getpid()}"
            if temp_link.exists() or temp_link.is_symlink():
                raise P5Error("ROLLBACK_TEMP_EXISTS")
            try:
                os.symlink(previous_target, temp_link)
                os.replace(temp_link, CURRENT)
                _fsync_directory(APP_ROOT)
            except OSError as error:
                raise P5Error("ROLLBACK_SYMLINK_REPLACE_FAILED") from error
        else:
            # Idempotent retry after a completed link switch, without rewriting state.
            _atomic_private_config(config_path, backup_path, owner_uid=owner_uid)

        try:
            result = system_runner(
                [str(SYSTEMCTL), "reload", "php8.3-fpm"], stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"},
                close_fds=True, check=False,
            )
        except OSError as error:
            raise P5Error("PHP_FPM_RELOAD_UNAVAILABLE") from error
        if result.returncode != 0:
            raise P5Error("PHP_FPM_RELOAD_FAILED")
        return {
            "status": "ROLLBACK_APPLIED",
            "private_config_restored": True,
            "database_operations": 0,
            "maintenance_must_remain_active_until_smoke": True,
            "elapsed_seconds": round(time.monotonic() - started, 3),
        }
    finally:
        os.close(lock)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--check", action="store_true")
    modes.add_argument("--plan", action="store_true")
    modes.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    try:
        root_required()
        if args.check:
            _validated_state()
            result = {"status": "CHECK_READY", "state_valid": True, "database_operations": 0}
        elif args.plan:
            result = plan()
        else:
            result = apply()
    except P5Error as error:
        return emit_error(error)
    except Exception:
        return emit_error(P5Error("INTERNAL_FAILURE"))
    print(json.dumps(result, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
