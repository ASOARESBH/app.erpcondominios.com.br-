#!/usr/bin/env python3
import copy
import importlib.util
import io
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock
from pathlib import Path

SCRIPT = Path(__file__).with_name("alert-manager.py")
SPEC = importlib.util.spec_from_file_location("erp_alert_manager", SCRIPT)
manager = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(manager)
EXTRACTOR_SPEC = importlib.util.spec_from_file_location("erp_smtp_extractor", Path(__file__).with_name("extract-brevo-smtp.py"))
extractor = importlib.util.module_from_spec(EXTRACTOR_SPEC)
EXTRACTOR_SPEC.loader.exec_module(extractor)
FAILOVER_SPEC = importlib.util.spec_from_file_location("erp_smtp_failover", Path(__file__).with_name("configure-msmtp-failover.py"))
failover = importlib.util.module_from_spec(FAILOVER_SPEC)
FAILOVER_SPEC.loader.exec_module(failover)


class AlertManagerTests(unittest.TestCase):
    def setUp(self):
        self.now = 2_000_000_000
        self.old_time = manager.time.time
        self.old_send = manager._send
        manager.time.time = lambda: self.now
        self.sent = []
        manager._send = lambda category, item, lines: self.sent.append((category, item, list(lines))) or True
        self.data = {"items": {}, "summary_date": ""}

    def tearDown(self):
        manager.time.time = self.old_time
        manager._send = self.old_send

    def test_first_failure_sends_once(self):
        manager._emit_transition(self.data, "disk_space_simulation", True, "limiar sintético")
        self.assertEqual(len(self.sent), 1)
        self.assertEqual(self.sent[0][0:2], ("ALERTA", "disk_space_simulation"))
        self.now += 5 * 60
        manager._emit_transition(self.data, "disk_space_simulation", True, "limiar sintético")
        self.assertEqual(len(self.sent), 1)

    def test_recovery_is_sent_once_when_item_normalizes(self):
        manager._emit_transition(self.data, "disk_space_simulation", True, "limiar sintético")
        self.now += 10 * 60
        manager._emit_transition(self.data, "disk_space_simulation", False, "limiar normal")
        self.assertEqual([message[0] for message in self.sent], ["ALERTA", "OK"])
        self.assertEqual(self.data["items"]["disk_space_simulation"]["state"], "ok")
        manager._emit_transition(self.data, "disk_space_simulation", False, "limiar normal")
        self.assertEqual(len(self.sent), 2)

    def test_persistently_failing_item_has_hourly_reminder(self):
        manager._emit_transition(self.data, "disk_space_simulation", True, "limiar sintético")
        self.now += manager.COOLDOWN_SECONDS - 1
        manager._emit_transition(self.data, "disk_space_simulation", True, "limiar sintético")
        self.assertEqual(len(self.sent), 1)
        self.now += 1
        manager._emit_transition(self.data, "disk_space_simulation", True, "limiar sintético")
        self.assertEqual(len(self.sent), 2)

    def test_failed_notification_retries_after_fifteen_minutes(self):
        results = [False, True]
        calls = []
        old_send = manager._send
        manager._send = lambda category, item, lines: calls.append(category) or results.pop(0)
        try:
            manager._emit_transition(self.data, "disk_space_simulation", True, "limiar sintético")
            self.assertEqual(len(calls), 1)
            self.now += manager.RETRY_SECONDS - 1
            manager._emit_transition(self.data, "disk_space_simulation", True, "limiar sintético")
            self.assertEqual(len(calls), 1)
            self.now += 1
            manager._emit_transition(self.data, "disk_space_simulation", True, "limiar sintético")
        finally:
            manager._send = old_send
        self.assertEqual(len(calls), 2)
        self.assertTrue(self.data["items"]["disk_space_simulation"]["alert_notified"])

    def test_summary_has_required_aggregate_fields_only(self):
        snap = {
            "now": self.now,
            "statuses": {"nginx_service": False, "php_fpm_service": False, "mysql_service": False,
                         "mysql_ping": False, "https_local": False, "backup_job_failure": False,
                         "backup_over_36h": False, "integrity_job_failure": False,
                         "backup_integrity_expired": False, "certbot_timer": False},
            "backup": {"mtime": self.now - 600, "size": 12345},
            "integrity": self.now - 60,
            "cert_hours": 100,
            "free_bytes": 20 * 1024**3,
        }
        lines = manager._summary_lines(snap)
        text = "\n".join(lines)
        for required in ("Serviços:", "Último backup:", "Integridade:", "Certificado:",
                         "Disco livre:", "Uptime:", "IPs banidos pelo Fail2ban:"):
            self.assertIn(required, text)
        self.assertNotIn("sha256", text.lower())
        self.assertNotIn("morador", text.lower())

    def test_send_rejects_non_whitelisted_subject(self):
        patched_send = manager._send
        manager._send = self.old_send
        try:
            self.assertFalse(manager._send("ALERTA", "arbitrary-value", []))
        finally:
            manager._send = patched_send

    def test_sql_values_parser_handles_escapes_null_and_binary(self):
        payload = b"('relay,host','line\\nfeed',NULL,0x4142),('next','quote\\'ok','x',0x00)"
        rows = extractor._parse_values(payload)
        self.assertEqual(rows[0], [b"relay,host", b"line\nfeed", None, b"AB"])
        self.assertEqual(rows[1], [b"next", b"quote'ok", b"x", b"\x00"])

    def test_insert_reader_collects_multiline_statement(self):
        stream = io.BytesIO(b"INSERT INTO `configuracao_smtp` VALUES\n('a;b',\n'c');\n")
        statements = list(extractor._insert_statements(stream))
        self.assertEqual(len(statements), 1)
        self.assertTrue(statements[0].endswith(b"'c')"))

    def test_msmtp_config_enforces_tls_for_both_allowed_ports(self):
        starttls = extractor._config("relay.example.org", "587", "user@example.org", "noreply@example.org").decode()
        implicit = extractor._config("relay.example.org", "465", "user@example.org", "noreply@example.org").decode()
        self.assertIn("tls on\n", starttls)
        self.assertIn("tls_starttls on\n", starttls)
        self.assertIn("account titan\n", starttls)
        self.assertIn("host smtp.titan.email\n", starttls)
        self.assertIn("port 465\n", starttls)
        self.assertIn("tls_starttls off\n", starttls)
        self.assertIn("account brevo\n", starttls)
        self.assertIn("account default : titan\n", starttls)
        self.assertIn("tls on\n", implicit)
        self.assertIn("tls_starttls off\n", implicit)
        self.assertNotIn("password ", starttls)

    def test_titan_is_primary_and_brevo_fallback_config_is_idempotent(self):
        initial = (
            "defaults\n"
            "auth on\n"
            "tls on\n"
            "tls_starttls on\n"
            "tls_trust_file system\n"
            "tls_certcheck on\n"
            "syslog off\n"
            "allow_from_override off\n"
            "set_from_header on\n"
            "timeout 20\n"
            "account default\n"
            "host smtp-relay.brevo.com\n"
            "port 587\n"
            "user relay-user@example.org\n"
            "from noreply@erpcondominios.com.br\n"
            "passwordeval /bin/cat /etc/erpcondominios/secrets/smtp-password\n"
        )
        dual = failover.configure(initial)
        self.assertIn("account titan\n", dual)
        self.assertIn("host smtp.titan.email\n", dual)
        self.assertIn("port 465\n", dual)
        self.assertIn("account brevo\n", dual)
        self.assertIn("account default : titan\n", dual)
        self.assertEqual(failover.configure(dual), dual)
        self.assertTrue(manager._msmtp_config_valid(dual))
        self.assertFalse(manager._msmtp_config_valid(dual.replace("tls_certcheck on", "tls_certcheck off")))
        with self.assertRaises(ValueError):
            failover.configure(dual.replace("port 587", "port 25"))

    def test_smtp_failure_marker_is_not_inverted(self):
        old_marker = manager.SMTP_FAILURE_MARKER
        old_safe_file = manager._safe_file
        with tempfile.TemporaryDirectory() as directory:
            marker = Path(directory) / "failure-marker"
            manager.SMTP_FAILURE_MARKER = marker
            manager._safe_file = lambda path: path.exists()
            try:
                self.assertFalse(manager._smtp_failure_present())
                marker.write_text("smtp_delivery_failed\n", encoding="ascii")
                self.assertTrue(manager._smtp_failure_present())
                marker.write_text("unexpected\n", encoding="ascii")
                self.assertTrue(manager._smtp_failure_present())
                marker.unlink()
                self.assertFalse(manager._smtp_failure_present())
            finally:
                manager.SMTP_FAILURE_MARKER = old_marker
                manager._safe_file = old_safe_file

    def test_unready_smtp_records_delivery_failure(self):
        old_send = manager._send
        old_ready = manager._smtp_ready
        old_record = manager._record_smtp_status
        recorded = []
        manager._send = self.old_send
        manager._smtp_ready = lambda: False
        manager._record_smtp_status = lambda failing: recorded.append(failing)
        try:
            with mock.patch.object(manager.subprocess, "run") as run:
                self.assertFalse(manager._send("RESUMO", "teste SMTP", ["teste"]))
                run.assert_not_called()
        finally:
            manager._send = old_send
            manager._smtp_ready = old_ready
            manager._record_smtp_status = old_record
        self.assertEqual(recorded, [True])

    def test_titan_success_does_not_attempt_brevo(self):
        calls = []
        old_send = manager._send
        old_ready = manager._smtp_ready
        old_record = manager._record_smtp_status
        old_safe_password = manager._safe_password_file
        manager._send = self.old_send
        manager._smtp_ready = lambda: True
        manager._safe_password_file = lambda path: True
        manager._record_smtp_status = lambda failing: None

        def run(args, **kwargs):
            calls.append((args, kwargs))
            return SimpleNamespace(returncode=0, stdout=b"", stderr=b"")

        try:
            with mock.patch.object(manager.subprocess, "run", side_effect=run):
                self.assertTrue(manager._send("RESUMO", "teste SMTP", ["teste"]))
        finally:
            manager._send = old_send
            manager._smtp_ready = old_ready
            manager._safe_password_file = old_safe_password
            manager._record_smtp_status = old_record
        self.assertEqual(len(calls), 1)
        self.assertIn("--account=titan", calls[0][0])

    def test_brevo_is_attempted_only_after_titan_failure(self):
        calls = []
        old_send = manager._send
        old_ready = manager._smtp_ready
        old_record = manager._record_smtp_status
        old_safe_password = manager._safe_password_file
        manager._send = self.old_send
        manager._smtp_ready = lambda: True
        manager._safe_password_file = lambda path: True
        manager._record_smtp_status = lambda failing: None

        def run(args, **kwargs):
            calls.append((args, kwargs))
            if "--account=titan" in args:
                return SimpleNamespace(returncode=1, stdout=b"535 authentication rejected", stderr=b"")
            return SimpleNamespace(returncode=0, stdout=b"", stderr=b"")

        try:
            with mock.patch.object(manager.subprocess, "run", side_effect=run):
                self.assertTrue(manager._send("ALERTA", "disk_space_simulation", ["limiar sintético"]))
        finally:
            manager._send = old_send
            manager._smtp_ready = old_ready
            manager._safe_password_file = old_safe_password
            manager._record_smtp_status = old_record
        self.assertEqual(len(calls), 2)
        self.assertIn("--account=titan", calls[0][0])
        self.assertIn("--account=brevo", calls[1][0])

    def test_timeout_on_titan_skips_fallback_to_prevent_possible_duplicate(self):
        calls = []
        old_send = manager._send
        old_ready = manager._smtp_ready
        old_record = manager._record_smtp_status
        old_safe_password = manager._safe_password_file
        manager._send = self.old_send
        manager._smtp_ready = lambda: True
        manager._safe_password_file = lambda path: True
        manager._record_smtp_status = lambda failing: None

        def run(args, **kwargs):
            calls.append((args, kwargs))
            raise manager.subprocess.TimeoutExpired(args, 12)

        try:
            with mock.patch.object(manager.subprocess, "run", side_effect=run):
                self.assertFalse(manager._send("ALERTA", "disk_space_simulation", ["limiar sintético"]))
        finally:
            manager._send = old_send
            manager._smtp_ready = old_ready
            manager._safe_password_file = old_safe_password
            manager._record_smtp_status = old_record
        self.assertEqual(len(calls), 1)
        self.assertIn("--account=titan", calls[0][0])
        self.assertEqual(manager._smtp_failure_class(b"SMTP connection timed out after DATA"), "ambiguous_timeout")
        self.assertEqual(manager._smtp_failure_class(b"SMTP connection reset after DATA"), "ambiguous_transport")

    def test_msmtp_returned_timeout_and_unknown_error_skip_brevo(self):
        old_send = manager._send
        old_ready = manager._smtp_ready
        old_record = manager._record_smtp_status
        old_safe_password = manager._safe_password_file
        manager._send = self.old_send
        manager._smtp_ready = lambda: True
        manager._safe_password_file = lambda path: True
        manager._record_smtp_status = lambda failing: None

        try:
            for diagnostic in (b"connection timed out after DATA", b"unknown relay diagnostic"):
                calls = []

                def run(args, **kwargs):
                    calls.append(args)
                    return SimpleNamespace(returncode=1, stdout=diagnostic, stderr=b"")

                with mock.patch.object(manager.subprocess, "run", side_effect=run):
                    self.assertFalse(manager._send("ALERTA", "disk_space_simulation", ["limiar sintético"]))
                self.assertEqual(len(calls), 1)
        finally:
            manager._send = old_send
            manager._smtp_ready = old_ready
            manager._safe_password_file = old_safe_password
            manager._record_smtp_status = old_record

    def test_missing_brevo_credential_does_not_call_fallback_account(self):
        old_send = manager._send
        old_ready = manager._smtp_ready
        old_record = manager._record_smtp_status
        old_safe_password = manager._safe_password_file
        calls = []
        manager._send = self.old_send
        manager._smtp_ready = lambda: True
        manager._safe_password_file = lambda path: path == manager.TITAN_PASSWORD
        manager._record_smtp_status = lambda failing: None

        def run(args, **kwargs):
            calls.append(args)
            return SimpleNamespace(returncode=1, stdout=b"535 authentication rejected", stderr=b"")

        try:
            with mock.patch.object(manager.subprocess, "run", side_effect=run):
                self.assertFalse(manager._send("ALERTA", "disk_space_simulation", ["limiar sintético"]))
        finally:
            manager._send = old_send
            manager._smtp_ready = old_ready
            manager._safe_password_file = old_safe_password
            manager._record_smtp_status = old_record
        self.assertEqual(len(calls), 1)
        self.assertIn("--account=titan", calls[0])

    def test_missing_titan_primary_blocks_brevo_only_delivery(self):
        old_send = manager._send
        old_ready = manager._smtp_ready
        old_record = manager._record_smtp_status
        old_safe_password = manager._safe_password_file
        recorded = []
        manager._send = self.old_send
        manager._smtp_ready = lambda: True
        manager._safe_password_file = lambda path: path == manager.BREVO_PASSWORD
        manager._record_smtp_status = lambda failing: recorded.append(failing)
        try:
            with mock.patch.object(manager.subprocess, "run") as run:
                self.assertFalse(manager._send("RESUMO", "teste SMTP", ["teste"]))
                run.assert_not_called()
        finally:
            manager._send = old_send
            manager._smtp_ready = old_ready
            manager._safe_password_file = old_safe_password
            manager._record_smtp_status = old_record
        self.assertEqual(recorded, [True])

    def test_msmtp_client_start_failure_does_not_call_fallback(self):
        old_send = manager._send
        old_ready = manager._smtp_ready
        old_record = manager._record_smtp_status
        old_safe_password = manager._safe_password_file
        calls = []
        manager._send = self.old_send
        manager._smtp_ready = lambda: True
        manager._safe_password_file = lambda path: True
        manager._record_smtp_status = lambda failing: None

        def run(args, **kwargs):
            calls.append(args)
            raise FileNotFoundError("synthetic client missing")

        try:
            with mock.patch.object(manager.subprocess, "run", side_effect=run):
                self.assertFalse(manager._send("ALERTA", "disk_space_simulation", ["limiar sintético"]))
        finally:
            manager._send = old_send
            manager._smtp_ready = old_ready
            manager._safe_password_file = old_safe_password
            manager._record_smtp_status = old_record
        self.assertEqual(len(calls), 1)
        self.assertIn("--account=titan", calls[0])

    def test_smtp_error_diagnostics_are_reduced_to_safe_classes(self):
        self.assertEqual(manager._smtp_failure_class(b"535 authentication failed secret=value"), "authentication")
        self.assertEqual(manager._smtp_failure_class(b"525 unauthorized IP secret=value"), "ip_authorization")
        self.assertEqual(manager._smtp_failure_class(b"TLS certificate verification failed"), "tls")
        self.assertEqual(manager._smtp_failure_class(b"unknown raw diagnostic secret=value"), "ambiguous_unknown")

    def test_future_integrity_marker_is_not_reported_valid(self):
        snap = {
            "now": self.now,
            "statuses": {"nginx_service": False, "php_fpm_service": False, "mysql_service": False,
                         "mysql_ping": False, "https_local": False, "backup_job_failure": False,
                         "backup_over_36h": False, "integrity_job_failure": False,
                         "backup_integrity_expired": False, "certbot_timer": False},
            "backup": None,
            "integrity": self.now + 301,
            "cert_hours": 100,
            "free_bytes": 20 * 1024**3,
        }
        integrity = next(line for line in manager._summary_lines(snap) if line.startswith("Integridade:"))
        self.assertIn("vencida", integrity)


if __name__ == "__main__":
    unittest.main(verbosity=2)
