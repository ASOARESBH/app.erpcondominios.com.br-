#!/usr/bin/env python3
import copy
import importlib.util
import io
import unittest
from pathlib import Path

SCRIPT = Path(__file__).with_name("alert-manager.py")
SPEC = importlib.util.spec_from_file_location("erp_alert_manager", SCRIPT)
manager = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(manager)
EXTRACTOR_SPEC = importlib.util.spec_from_file_location("erp_smtp_extractor", Path(__file__).with_name("extract-brevo-smtp.py"))
extractor = importlib.util.module_from_spec(EXTRACTOR_SPEC)
EXTRACTOR_SPEC.loader.exec_module(extractor)


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
        self.assertIn("tls on\n", implicit)
        self.assertIn("tls_starttls off\n", implicit)
        self.assertNotIn("password ", starttls)

    def test_smtp_error_diagnostics_are_reduced_to_safe_classes(self):
        self.assertEqual(manager._smtp_failure_class(b"535 authentication failed secret=value"), "authentication")
        self.assertEqual(manager._smtp_failure_class(b"525 unauthorized IP secret=value"), "ip_authorization")
        self.assertEqual(manager._smtp_failure_class(b"TLS certificate verification failed"), "tls")
        self.assertEqual(manager._smtp_failure_class(b"unknown raw diagnostic secret=value"), "relay_rejected")

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
