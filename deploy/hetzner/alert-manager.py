#!/usr/bin/env python3
"""Local HML monitoring and email notifications for ERP Condomínios."""
from __future__ import annotations

import email.message
import email.utils
import fcntl
import glob
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

IP = "2.29.161.106"
CERT = Path("/etc/letsencrypt/live/erpcondominios-ip/fullchain.pem")
BACKUP_DIR = Path("/var/backups/erpcondominios/daily")
INTEGRITY_FILE = Path("/var/lib/erpcondominios/backup-integrity-verified-at")
STATE_DIR = Path("/var/lib/erpcondominios")
STATE_FILE = STATE_DIR / "alerts-state.json"
LOCK_FILE = STATE_DIR / ".alerts.lock"
MSMTP = Path("/usr/bin/msmtp")
MSMTP_CONFIG = Path("/etc/erpcondominios/secrets/msmtprc")
BREVO_PASSWORD = Path("/etc/erpcondominios/secrets/smtp-password")
TITAN_PASSWORD = Path("/etc/erpcondominios/secrets/titan-app-password")
SMTP_FAILURE_MARKER = STATE_DIR / "smtp-delivery-failure"
RECIPIENT = "noreply@erpcondominios.com.br"
SENDER = "noreply@erpcondominios.com.br"
ZONE = ZoneInfo("America/Sao_Paulo")
COOLDOWN_SECONDS = 60 * 60
RETRY_SECONDS = 15 * 60
MAX_BACKUP_AGE = 36 * 60 * 60
MAX_INTEGRITY_AGE = 36 * 60 * 60
MIN_FREE_BYTES = 15 * 1024**3
CERT_WARN_SECONDS = 72 * 60 * 60

ITEMS = {
    "nginx_service": "Nginx",
    "php_fpm_service": "PHP-FPM",
    "mysql_service": "MySQL",
    "mysql_ping": "Ping local do MySQL",
    "https_local": "HTTPS local",
    "cert_under_72h": "Certificado com menos de 72 horas",
    "disk_under_15gib": "Disco abaixo de 15 GiB livres",
    "backup_over_36h": "Backup com mais de 36 horas",
    "backup_permissions": "Permissões ou manifesto do backup",
    "backup_integrity_expired": "Marcador de integridade vencido",
    "certbot_timer": "Agendamento de renovação Certbot",
    "backup_job_failure": "Serviço de backup",
    "integrity_job_failure": "Serviço de integridade do backup",
    "certbot_failure": "Renovação do Certbot",
    "summary_delivery_failure": "Envio do resumo diário",
    "healthcheck_runtime": "Execução do healthcheck",
    "smtp_delivery_failure": "Entrega SMTP",
    "smtp_config": "Configuração local do alerta SMTP",
    "titan_primary_credential": "Credencial primária TITAN",
    "disk_space_simulation": "Simulação controlada de espaço em disco",
}

UNITS = {
    "nginx_service": "nginx.service",
    "php_fpm_service": "php8.3-fpm.service",
    "mysql_service": "mysql.service",
}


def _run(args: list[str], timeout: int = 8, input_text: str | None = None):
    try:
        return subprocess.run(
            args,
            input=input_text,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            timeout=timeout,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return None


def _safe_file(path: Path, expected_mode: int = 0o600) -> bool:
    try:
        st = path.lstat()
        return (
            stat.S_ISREG(st.st_mode)
            and st.st_uid == 0
            and st.st_gid == 0
            and stat.S_IMODE(st.st_mode) == expected_mode
            and not path.is_symlink()
        )
    except OSError:
        return False


def _safe_password_file(path: Path) -> bool:
    if not _safe_file(path):
        return False
    try:
        value = path.read_bytes()
        return 1 <= len(value) <= 1024 and all(33 <= byte <= 126 for byte in value)
    except OSError:
        return False


def _directives(block: str) -> dict[str, str] | None:
    result: dict[str, str] = {}
    for line in block.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split(None, 1)
        if len(parts) != 2 or parts[0] in result:
            return None
        result[parts[0]] = parts[1]
    return result


def _msmtp_config_valid(config: str) -> bool:
    headers = list(re.finditer(r"(?m)^account\s+([A-Za-z0-9_-]+)(?:\s*:\s*([A-Za-z0-9_-]+))?\s*$", config))
    if [match.group(1) for match in headers] != ["titan", "brevo", "default"]:
        return False
    if headers[-1].group(2) != "titan" or config.count("account ") != 3:
        return False

    defaults = _directives(config[:headers[0].start()].removeprefix("defaults\n"))
    titan = _directives(config[headers[0].end():headers[1].start()])
    brevo = _directives(config[headers[1].end():headers[2].start()])
    default = _directives(config[headers[2].end():])
    if defaults is None or titan is None or brevo is None or default is None:
        return False
    required_defaults = {
        "auth": "on", "tls": "on", "tls_trust_file": "system",
        "tls_certcheck": "on", "syslog": "off",
        "allow_from_override": "off", "set_from_header": "on",
        "timeout": "10",
    }
    if set(defaults) != set(required_defaults) or not all(defaults.get(key) == value for key, value in required_defaults.items()):
        return False
    titan_keys = {"host", "port", "tls_starttls", "user", "from", "passwordeval"}
    if set(default) or set(titan) != titan_keys or titan.get("host") != "smtp.titan.email" or titan.get("port") != "465":
        return False
    if (titan.get("tls_starttls") != "off" or titan.get("user") != SENDER
            or titan.get("from") != SENDER
            or titan.get("passwordeval") != f"/bin/cat {TITAN_PASSWORD}"):
        return False
    if (set(brevo) != titan_keys or brevo.get("host") != "smtp-relay.brevo.com"
            or brevo.get("port") not in {"465", "587"}
            or brevo.get("tls_starttls") != ("on" if brevo.get("port") == "587" else "off")
            or not re.fullmatch(r"[A-Za-z0-9@._+-]{1,254}", brevo.get("user", ""))
            or brevo.get("from") != SENDER
            or brevo.get("passwordeval") != f"/bin/cat {BREVO_PASSWORD}"):
        return False
    return "password " not in config and "syslog on" not in config


def _smtp_ready() -> bool:
    try:
        if os.geteuid() != 0 or not MSMTP.is_file() or not _safe_file(MSMTP_CONFIG):
            return False
        directory = MSMTP_CONFIG.parent.stat()
        if directory.st_uid != 0 or directory.st_gid != 0 or stat.S_IMODE(directory.st_mode) != 0o700:
            return False
        config = MSMTP_CONFIG.read_text(encoding="ascii")
        return (
            _msmtp_config_valid(config)
            and _safe_password_file(TITAN_PASSWORD)
        )
    except OSError:
        return False


def _atomic_write(path: Path, text: str) -> None:
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(path.parent, 0o700)
    fd, tmp_name = tempfile.mkstemp(prefix=".alerts-", dir=path.parent)
    try:
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            handle.write(text)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp_name, path)
        os.chown(path, 0, 0)
        os.chmod(path, 0o600)
        dir_fd = os.open(path.parent, os.O_DIRECTORY)
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
    finally:
        try:
            os.unlink(tmp_name)
        except FileNotFoundError:
            pass


def _read_state() -> dict:
    try:
        if not _safe_file(STATE_FILE):
            return {"items": {}, "summary_date": ""}
        data = json.loads(STATE_FILE.read_text(encoding="utf-8"))
        if not isinstance(data, dict) or not isinstance(data.get("items"), dict):
            return {"items": {}, "summary_date": ""}
        data.setdefault("summary_date", "")
        return data
    except (OSError, ValueError, TypeError):
        return {"items": {}, "summary_date": ""}


def _save_state(data: dict) -> None:
    _atomic_write(STATE_FILE, json.dumps(data, sort_keys=True, separators=(",", ":")) + "\n")


def _smtp_failure_class(diagnostic: bytes) -> str:
    diagnostic = diagnostic.lower()
    if b"525 " in diagnostic or b"unauthorized ip" in diagnostic:
        return "ip_authorization"
    if b"authentication" in diagnostic or b"auth failed" in diagnostic or b"535 " in diagnostic:
        return "authentication"
    if any(token in diagnostic for token in (b"tls", b"ssl", b"certificate", b"handshake")):
        return "tls"
    if any(token in diagnostic for token in (b"resolve", b"name or service", b"dns")):
        return "dns"
    if any(token in diagnostic for token in (b"timed out", b"timeout", b"timedout")):
        return "ambiguous_timeout"
    if any(token in diagnostic for token in (b"connection reset", b"broken pipe", b"connection closed", b"unexpected eof")):
        return "ambiguous_transport"
    if any(token in diagnostic for token in (
        b"connection refused", b"failed to connect", b"could not connect",
        b"cannot connect", b"network is unreachable", b"no route to host",
    )):
        return "connection"
    if re.search(rb"(?m)(?:^|\s)(?:[45][0-9]{2})(?:[ -]|$)", diagnostic):
        return "relay_rejected"
    return "ambiguous_unknown"


def _record_smtp_status(failing: bool) -> None:
    if failing:
        _atomic_write(SMTP_FAILURE_MARKER, "smtp_delivery_failed\n")
    else:
        try:
            SMTP_FAILURE_MARKER.unlink()
        except FileNotFoundError:
            pass


def _smtp_failure_present() -> bool:
    try:
        if not _safe_file(SMTP_FAILURE_MARKER):
            return SMTP_FAILURE_MARKER.exists() or SMTP_FAILURE_MARKER.is_symlink()
        return True
    except OSError:
        return True


def _send(category: str, item: str, body_lines: list[str]) -> bool:
    if category not in {"ALERTA", "OK", "RESUMO"} or item not in ITEMS and item not in {"teste SMTP", "status diário"}:
        return False
    if not _smtp_ready():
        _record_smtp_status(True)
        print("smtp_delivery=failed reason=config")
        return False
    message = email.message.EmailMessage()
    message["From"] = f"ERP Condomínios <{SENDER}>"
    message["To"] = RECIPIENT
    message["Date"] = email.utils.formatdate(localtime=False, usegmt=True)
    display_item = ITEMS.get(item, item)
    message["Subject"] = f"[ERP Hetzner][{category}] {display_item}"
    message.set_content("\n".join(body_lines) + "\n", subtype="plain", charset="utf-8")
    payload = message.as_bytes()
    failures: list[tuple[str, str]] = []
    titan_available = _safe_password_file(TITAN_PASSWORD)
    brevo_available = _safe_password_file(BREVO_PASSWORD)
    if not titan_available:
        _record_smtp_status(True)
        print("smtp_delivery=failed reason=titan_primary_unavailable")
        return False
    accounts = ("titan", "brevo") if brevo_available else ("titan",)
    for account in accounts:
        try:
            result = subprocess.run(
                [str(MSMTP), f"--file={MSMTP_CONFIG}", f"--account={account}", "--timeout=10", RECIPIENT],
                input=payload,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                timeout=12,
                check=False,
            )
            if result.returncode == 0:
                _record_smtp_status(False)
                print(f"smtp_delivery=accepted provider={'titan' if account == 'titan' else 'brevo_fallback'}")
                return True
            reason = _smtp_failure_class(result.stdout + result.stderr)
        except subprocess.TimeoutExpired:
            reason = "ambiguous_timeout"
        except OSError:
            reason = "ambiguous_client"
        failures.append((account, reason))
        if account == "titan" and reason.startswith("ambiguous_"):
            failures.append(("brevo", "skipped_ambiguous_acceptance"))
            break
    _record_smtp_status(True)
    print("smtp_delivery=failed " + " ".join(f"{account}={reason}" for account, reason in failures))
    return False


def _emit_transition(data: dict, item: str, failing: bool, detail: str) -> None:
    if item not in ITEMS:
        return
    now = int(time.time())
    state = data["items"].setdefault(item, {
        "state": "ok", "pending": "", "alert_notified": False,
        "last_alert": 0, "last_attempt": 0,
        "last_attempt_failed": False,
    })
    old = state.get("state", "ok")
    if failing:
        if old in {"ok", "recovery_pending"}:
            state["state"] = "active"
            state["pending"] = "ALERTA"
        elif old == "active" and not state.get("pending") and now - int(state.get("last_alert", 0)) >= COOLDOWN_SECONDS:
            state["pending"] = "ALERTA"
    else:
        if old == "active":
            if state.get("alert_notified"):
                state["state"] = "recovery_pending"
                state["pending"] = "OK"
            else:
                state["state"] = "ok"
                state["pending"] = ""

    pending = state.get("pending", "")
    last_attempt = int(state.get("last_attempt", 0))
    if not pending or (state.get("last_attempt_failed") and now - last_attempt < RETRY_SECONDS):
        return
    if pending == "ALERTA" and now - int(state.get("last_alert", 0)) < COOLDOWN_SECONDS:
        return

    state["last_attempt"] = now
    state["last_attempt_failed"] = True
    if pending == "ALERTA":
        subject_kind = "ALERTA"
        status = "FALHA"
    else:
        subject_kind = "OK"
        status = "NORMALIZADO"
    ok = _send(subject_kind, item, [
        "Monitoramento local do ERP Condomínios na VM Hetzner.",
        f"Item: {ITEMS[item]}.",
        f"Estado: {status}.",
        f"Horário BRT: {datetime.now(ZONE).isoformat(timespec='seconds')}.",
        f"Medição: {detail}.",
    ])
    if ok:
        state["pending"] = ""
        state["last_attempt_failed"] = False
        if pending == "ALERTA":
            state["last_alert"] = now
            state["alert_notified"] = True
            state["state"] = "active"
        else:
            state["alert_notified"] = False
            state["state"] = "ok"
        print(f"notification=accepted item={item} category={subject_kind}")
    else:
        print(f"notification=failed item={item} category={subject_kind}")


def _unit_active(unit: str) -> bool:
    result = _run(["/usr/bin/systemctl", "is-active", "--quiet", unit])
    return result is not None and result.returncode == 0


def _unit_failed(unit: str) -> bool:
    active = _run(["/usr/bin/systemctl", "show", "-p", "ActiveState", "--value", unit])
    if active is None or active.returncode != 0:
        return False
    if active.stdout.strip() in {"active", "activating", "reloading"}:
        return False
    result = _run(["/usr/bin/systemctl", "show", "-p", "Result", "--value", unit])
    return result is not None and result.returncode == 0 and result.stdout.strip() not in {"", "success"}


def _cert_hours() -> int | None:
    result = _run(["/usr/bin/openssl", "x509", "-in", str(CERT), "-noout", "-enddate"])
    if result is None or result.returncode != 0:
        return None
    match = re.fullmatch(r"notAfter=(.+)", result.stdout.strip())
    if not match:
        return None
    try:
        expires = datetime.strptime(match.group(1), "%b %d %H:%M:%S %Y %Z").replace(tzinfo=timezone.utc)
        return int((expires.timestamp() - time.time()) // 3600)
    except ValueError:
        return None


def _latest_backup():
    files = [Path(p) for p in glob.glob(str(BACKUP_DIR / "erpcondominios-*.sql.gz")) if not p.endswith(".sha256")]
    if not files:
        return None
    try:
        path = max(files, key=lambda p: p.stat().st_mtime)
        st = path.stat()
        return {"path": path, "mtime": int(st.st_mtime), "size": int(st.st_size), "mode": stat.S_IMODE(st.st_mode), "uid": st.st_uid, "gid": st.st_gid}
    except OSError:
        return None


def _integrity_marker():
    try:
        if not _safe_file(INTEGRITY_FILE):
            return None
        value = INTEGRITY_FILE.read_text(encoding="ascii").strip()
        if not re.fullmatch(r"[0-9]{1,12}", value):
            return None
        return int(value)
    except (OSError, UnicodeError):
        return None


def _free_bytes() -> int | None:
    try:
        return shutil.disk_usage(BACKUP_DIR).free
    except OSError:
        return None


def _mysql_ping() -> bool:
    result = _run(["/usr/bin/mysqladmin", "--protocol=socket", "--user=root", "ping"], timeout=5)
    return result is not None and result.returncode == 0


def _https_local() -> bool:
    result = _run([
        "/usr/bin/curl", "--fail", "--silent", "--show-error", "--output", "/dev/null",
        "--max-time", "8", "--resolve", f"{IP}:443:127.0.0.1", f"https://{IP}/login.html",
    ], timeout=10)
    return result is not None and result.returncode == 0


def _certbot_timer_active() -> bool:
    return _unit_active("snap.certbot.renew.timer")


def _collect() -> dict:
    now = int(time.time())
    statuses: dict[str, bool] = {}
    for item, unit in UNITS.items():
        statuses[item] = not _unit_active(unit)
    statuses["mysql_ping"] = not _mysql_ping()
    statuses["https_local"] = not _https_local()
    cert_hours = _cert_hours()
    statuses["cert_under_72h"] = cert_hours is None or cert_hours < CERT_WARN_SECONDS // 3600
    free = _free_bytes()
    statuses["disk_under_15gib"] = free is None or free < MIN_FREE_BYTES
    backup = _latest_backup()
    statuses["backup_over_36h"] = backup is None or now - backup["mtime"] > MAX_BACKUP_AGE
    backup_bad = backup is None or backup["mode"] != 0o600 or backup["uid"] != 0 or backup["gid"] != 0
    if backup is not None:
        manifest = Path(str(backup["path"]) + ".sha256")
        try:
            manifest_stat = manifest.lstat()
            backup_bad = backup_bad or not stat.S_ISREG(manifest_stat.st_mode) or manifest_stat.st_uid != 0 or stat.S_IMODE(manifest_stat.st_mode) != 0o600
        except OSError:
            backup_bad = True
    statuses["backup_permissions"] = backup_bad
    integrity = _integrity_marker()
    statuses["backup_integrity_expired"] = integrity is None or now - integrity > MAX_INTEGRITY_AGE or integrity - now > 300
    statuses["certbot_timer"] = not _certbot_timer_active()
    statuses["smtp_config"] = not _smtp_ready()
    statuses["backup_job_failure"] = _unit_failed("erpcondominios-backup.service")
    statuses["integrity_job_failure"] = _unit_failed("erpcondominios-backup-integrity.service")
    statuses["certbot_failure"] = _unit_failed("snap.certbot.renew.service")
    statuses["summary_delivery_failure"] = _unit_failed("erpcondominios-summary.service")
    statuses["healthcheck_runtime"] = _unit_failed("erpcondominios-healthcheck.service")
    statuses["smtp_delivery_failure"] = _smtp_failure_present()
    statuses["titan_primary_credential"] = not _safe_password_file(TITAN_PASSWORD)
    details = {
        "nginx_service": "unit nginx.service ativa" if statuses["nginx_service"] else "unit nginx.service inativa",
        "php_fpm_service": "unit php8.3-fpm.service ativa" if statuses["php_fpm_service"] else "unit php8.3-fpm.service inativa",
        "mysql_service": "unit mysql.service ativa" if statuses["mysql_service"] else "unit mysql.service inativa",
        "mysql_ping": "sem resposta local" if statuses["mysql_ping"] else "resposta local confirmada",
        "https_local": "rota HTTPS local falhou" if statuses["https_local"] else "rota HTTPS local respondeu",
        "cert_under_72h": "validade restante indisponível" if cert_hours is None else f"validade restante: {cert_hours} horas",
        "disk_under_15gib": "espaço livre indisponível" if free is None else f"espaço livre: {free // 1024**3} GiB",
        "backup_over_36h": "backup ausente" if backup is None else f"idade do backup: {max(0, (now - backup['mtime']) // 3600)} horas",
        "backup_permissions": "backup ausente ou permissões/manifesto inválidos" if backup_bad else "permissões e manifesto válidos",
        "backup_integrity_expired": "marcador ausente, inválido ou vencido" if statuses["backup_integrity_expired"] else f"idade do marcador: {max(0, (now - int(integrity)) // 3600)} horas",
        "certbot_timer": "timer de renovação inativo" if statuses["certbot_timer"] else "timer de renovação ativo",
        "smtp_config": "cliente/configuração SMTP indisponível ou permissões inválidas" if statuses["smtp_config"] else "cliente e configuração SMTP prontos",
        "backup_job_failure": "última execução do backup falhou" if statuses["backup_job_failure"] else "última execução do backup concluída",
        "integrity_job_failure": "última execução da integridade falhou" if statuses["integrity_job_failure"] else "última execução da integridade concluída",
        "certbot_failure": "última renovação falhou" if statuses["certbot_failure"] else "última execução de renovação concluída",
        "summary_delivery_failure": "último envio do resumo falhou" if statuses["summary_delivery_failure"] else "último envio do resumo concluído",
        "healthcheck_runtime": "última execução do healthcheck falhou" if statuses["healthcheck_runtime"] else "última execução do healthcheck concluída",
        "smtp_delivery_failure": "última submissão SMTP falhou" if statuses["smtp_delivery_failure"] else "última submissão SMTP aceita",
        "titan_primary_credential": "credencial TITAN ausente ou inválida" if statuses["titan_primary_credential"] else "credencial TITAN instalada",
    }
    return {
        "now": now,
        "statuses": statuses,
        "details": details,
        "cert_hours": cert_hours,
        "free_bytes": free,
        "backup": backup,
        "integrity": integrity,
    }


def _reconcile(data: dict, snapshot: dict) -> int:
    failures = 0
    for item in sorted(snapshot["statuses"]):
        failing = bool(snapshot["statuses"][item])
        if failing:
            failures += 1
        _emit_transition(data, item, failing, snapshot["details"][item])
    _save_state(data)
    return failures


def _summary_lines(snapshot: dict) -> list[str]:
    now = snapshot["now"]
    statuses = snapshot["statuses"]
    lines = [
        "Resumo diário do monitoramento local do ERP Condomínios.",
        f"Horário BRT: {datetime.fromtimestamp(now, timezone.utc).astimezone(ZONE).isoformat(timespec='seconds')}.",
        "Serviços: " + "; ".join(
            f"{label}={'OK' if statuses[item] is False else 'ALERTA'}"
            for item, label in (("nginx_service", "Nginx"), ("php_fpm_service", "PHP-FPM"), ("mysql_service", "MySQL"))
        ) + "; " + "; ".join((
            "backup=" + ("ALERTA" if statuses["backup_job_failure"] or statuses["backup_over_36h"] else "OK"),
            "integridade=" + ("ALERTA" if statuses["integrity_job_failure"] or statuses["backup_integrity_expired"] else "OK"),
            "Certbot timer=" + ("ALERTA" if statuses["certbot_timer"] else "OK"),
        )) + ".",
        "Email de alertas: TITAN primário=" + ("ALERTA" if statuses.get("titan_primary_credential", True) else "OK") +
        "; fallback Brevo=" + ("OK" if not statuses.get("smtp_config", True) else "ALERTA") + ".",
        f"Ping MySQL={'OK' if statuses['mysql_ping'] is False else 'ALERTA'}; HTTPS local={'OK' if statuses['https_local'] is False else 'ALERTA'}.",
    ]
    backup = snapshot["backup"]
    if backup is None:
        lines.append("Último backup: ausente.")
    else:
        stamp = datetime.fromtimestamp(backup["mtime"], timezone.utc).astimezone(ZONE).isoformat(timespec="seconds")
        lines.append(f"Último backup: {backup['size']} bytes; horário BRT {stamp}.")
    marker = snapshot["integrity"]
    if marker is None:
        lines.append("Integridade: vencida/indisponível.")
    else:
        marker_time = datetime.fromtimestamp(marker, timezone.utc).astimezone(ZONE).isoformat(timespec="seconds")
        marker_status = "vencida" if now - marker > MAX_INTEGRITY_AGE or marker - now > 300 else "válida"
        lines.append(f"Integridade: {marker_status}; última validação BRT {marker_time}.")
    lines.append("Certificado: " + ("indisponível" if snapshot["cert_hours"] is None else f"{snapshot['cert_hours']} horas restantes") + ".")
    lines.append("Disco livre: " + ("indisponível" if snapshot["free_bytes"] is None else f"{snapshot['free_bytes'] // 1024**3} GiB") + ".")
    try:
        uptime_seconds = int(float(Path("/proc/uptime").read_text(encoding="ascii").split()[0]))
        lines.append(f"Uptime: {uptime_seconds // 86400} dias, {(uptime_seconds % 86400) // 3600} horas.")
    except (OSError, ValueError, IndexError):
        lines.append("Uptime: indisponível.")
    fail2ban = _run(["/usr/bin/fail2ban-client", "status"], timeout=5)
    banned_total = None
    if fail2ban is not None and fail2ban.returncode == 0:
        match = re.search(r"Jail list:\s*(.*)", fail2ban.stdout)
        jails = [j.strip() for j in match.group(1).split(",") if j.strip()] if match else []
        total = 0
        valid = bool(jails)
        for jail in jails:
            result = _run(["/usr/bin/fail2ban-client", "status", jail], timeout=5)
            found = re.search(r"Currently banned:\s*(\d+)", result.stdout if result else "")
            if result is None or result.returncode != 0 or not found:
                valid = False
                break
            total += int(found.group(1))
        if valid:
            banned_total = total
    lines.append("IPs banidos pelo Fail2ban: " + (str(banned_total) if banned_total is not None else "indisponível") + ".")
    return lines


def action_check() -> int:
    if os.geteuid() != 0:
        print("monitor=failed reason=privilege")
        return 2
    with LOCK_FILE.open("a", encoding="ascii") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        data = _read_state()
        snapshot = _collect()
        count = _reconcile(data, snapshot)
        print(f"healthcheck=completed failed_items={count}")
    return 0


def action_event(item: str, failing: bool, detail: str = "falha observada pelo systemd") -> int:
    if os.geteuid() != 0 or item not in ITEMS:
        print("event=ignored")
        return 2
    with LOCK_FILE.open("a", encoding="ascii") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        data = _read_state()
        _emit_transition(data, item, failing, detail)
        _save_state(data)
    return 0


def action_summary() -> int:
    if os.geteuid() != 0:
        print("summary=failed reason=privilege")
        return 2
    with LOCK_FILE.open("a", encoding="ascii") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        data = _read_state()
        snapshot = _collect()
        day = datetime.fromtimestamp(snapshot["now"], timezone.utc).astimezone(ZONE).date().isoformat()
        if data.get("summary_date") == day:
            print("summary=already_sent_today")
            return 0
        if _send("RESUMO", "status diário", _summary_lines(snapshot)):
            data["summary_date"] = day
            _save_state(data)
            print("summary=accepted")
            return 0
        _save_state(data)
        print("summary=failed")
        return 1


def action_test() -> int:
    if os.geteuid() != 0:
        print("test_email=failed reason=privilege")
        return 2
    if not _safe_password_file(TITAN_PASSWORD):
        print("test_email=failed reason=titan_credential_missing")
        return 2
    ok = _send("RESUMO", "teste SMTP", [
        "Teste de transporte de alertas do ERP Condomínios.",
        "Estado: teste solicitado; transporte sem dados de negócio.",
        f"Horário BRT: {datetime.now(ZONE).isoformat(timespec='seconds')}.",
        "Esta mensagem não contém dados de negócio.",
    ])
    print("test_email=" + ("accepted" if ok else "failed"))
    return 0 if ok else 1


def action_simulate(failing: bool) -> int:
    return action_event("disk_space_simulation", failing, "SIMULAÇÃO: espaço fictício abaixo de 15 GiB; disco real inalterado" if failing else "SIMULAÇÃO encerrada; disco real permaneceu inalterado")


def action_simulate_cycle() -> int:
    if os.geteuid() != 0 or _free_bytes() is None or _free_bytes() < MIN_FREE_BYTES:
        print("simulation=refused reason=real_disk_not_healthy")
        return 2
    with LOCK_FILE.open("a", encoding="ascii") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        data = _read_state()
        previous = data["items"].get("disk_space_simulation", {})
        if previous.get("state", "ok") != "ok" or previous.get("pending"):
            print("simulation=refused reason=disk_item_already_active")
            return 2
        _emit_transition(data, "disk_space_simulation", True, "espaço fictício abaixo de 15 GiB; disco real inalterado")
        _save_state(data)
        if not data["items"].get("disk_space_simulation", {}).get("alert_notified"):
            print("simulation=failed reason=alert_not_accepted")
            return 1
        _emit_transition(data, "disk_space_simulation", False, "simulação encerrada; disco real permaneceu inalterado")
        _save_state(data)
        state = data["items"].get("disk_space_simulation", {})
        ok = state.get("state") == "ok" and not state.get("pending")
        print("simulation=" + ("completed" if ok else "notification_pending"))
        return 0 if ok else 1


def main(argv: list[str]) -> int:
    if len(argv) == 2 and argv[1] == "check":
        return action_check()
    if len(argv) == 2 and argv[1] == "summary":
        return action_summary()
    if len(argv) == 2 and argv[1] == "test-email":
        return action_test()
    if len(argv) == 3 and argv[1] == "event-failure":
        return action_event(argv[2], True)
    if len(argv) == 3 and argv[1] == "event-recovery":
        return action_event(argv[2], False)
    if len(argv) == 2 and argv[1] == "simulate-failure":
        return action_simulate(True)
    if len(argv) == 2 and argv[1] == "simulate-recovery":
        return action_simulate(False)
    if len(argv) == 2 and argv[1] == "simulate-cycle":
        return action_simulate_cycle()
    print("usage=check|summary|test-email|event-failure ITEM|event-recovery ITEM|simulate-failure|simulate-recovery|simulate-cycle")
    return 2


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv))
    except Exception:
        print("monitor=failed reason=internal")
        raise SystemExit(2)
