"""Testes sintéticos P5; nenhum banco, backup, Nginx, VM ou symlink de produção é acessado."""
from __future__ import annotations

import datetime as dt
import gzip
import importlib.util
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
P5_DIR = ROOT / "deploy/hetzner/p5"
sys.path.insert(0, str(P5_DIR))


def load_module(name: str):
    spec = importlib.util.spec_from_file_location(name, P5_DIR / f"{name}.py")
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


common = load_module("p5_common")
catalog = load_module("catalog_cutover")
backup = load_module("mysql_final_backup")
maintenance = load_module("maintenance")
rollback = load_module("rollback")


class _FinishedProcess:
    pid = os.getpid()
    stdout = None

    def poll(self):
        return 0

    def wait(self):
        return 0

    def terminate(self):
        return None


class _RunningProcess:
    def __init__(self):
        self.returncode = None

    def poll(self):
        return self.returncode

    def terminate(self):
        self.returncode = -15

    def wait(self):
        return self.returncode


class P5CommonSyntheticTests(unittest.TestCase):
    def test_rejects_path_traversal_and_symlink(self):
        with self.assertRaises(common.P5Error) as caught:
            common.validate_no_symlink_components(Path("/tmp/../etc/passwd"))
        self.assertEqual(caught.exception.code, "PATH_NOT_ALLOWLISTED")
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            real = root / "real"
            real.mkdir()
            link = root / "link"
            link.symlink_to(real, target_is_directory=True)
            with self.assertRaises(common.P5Error) as caught:
                common.validate_no_symlink_components(link / "child")
            self.assertEqual(caught.exception.code, "SYMLINK_NOT_ALLOWED")

    def test_approval_is_exact_structured_scope(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "approval.json"
            data = {
                "approved": True,
                "action": "test",
                "target": "erp_pg_source",
                "source_schema": "erp_pg_source",
                "destination": "test-destination",
                "ticket": "CHG-12345",
                "expires_utc": (dt.datetime.now(dt.timezone.utc) + dt.timedelta(hours=1)).strftime("%Y-%m-%dT%H:%M:%SZ"),
            }
            path.write_text(json.dumps(data), encoding="utf-8")
            path.chmod(0o600)
            common.validate_approval(path, action="test", target="erp_pg_source",
                                     source_schema="erp_pg_source", destination="test-destination",
                                     owner_uid=os.geteuid())
            with self.assertRaises(common.P5Error) as caught:
                common.validate_approval(path, action="test", target="other",
                                         source_schema="erp_pg_source", destination="test-destination",
                                         owner_uid=os.geteuid())
            self.assertEqual(caught.exception.code, "APPROVAL_SCOPE_MISMATCH")


class P5CatalogSyntheticTests(unittest.TestCase):
    def test_contract_is_cutover_only_and_source_is_clone(self):
        self.assertEqual(catalog.PROFILE, "cutover")
        self.assertEqual(catalog.SOURCE_SCHEMA, "erp_pg_source")
        self.assertNotIn("erpcondominios_stage_20261114", str(catalog.EXPECTED_CONFIG))
        self.assertEqual(catalog.EXPECTED_CONFIG["source_defaults"], "/etc/erpcondominios/secrets/pg_reader.cnf")

    def test_capture_fails_closed_before_tools_when_private_config_is_missing(self):
        never = mock.Mock(side_effect=AssertionError("external process must not run"))
        with mock.patch.object(catalog, "root_required"), \
             mock.patch.object(catalog, "CONFIG", Path("/nonexistent/p5/catalog.json")):
            with self.assertRaises(common.P5Error) as caught:
                catalog.capture(process_factory=never, owner_uid=os.geteuid())
        self.assertEqual(caught.exception.code, "PRIVATE_FILE_INVALID")
        never.assert_not_called()

    def test_logged_process_uses_fixed_working_directory_and_private_log(self):
        with tempfile.TemporaryDirectory() as tmp:
            work = Path(tmp)
            log = work / "private.log"
            calls = []

            def fake_process(argv, **kwargs):
                calls.append((argv, kwargs))
                return _FinishedProcess()

            result = catalog._run_logged(
                ["synthetic-command"], log, cwd=work, process_factory=fake_process,
                memory_reader=lambda: catalog.MIN_AVAILABLE_KIB, sleep=lambda _seconds: None,
                owner_uid=os.geteuid(),
            )
            self.assertEqual(result, 0)
            self.assertEqual(calls[0][1]["cwd"], str(work))
            self.assertEqual(stat.S_IMODE(log.stat().st_mode), 0o600)

    def test_memory_guard_blocks_subprocess_before_start(self):
        with tempfile.TemporaryDirectory() as tmp:
            log = Path(tmp) / "private.log"
            never = mock.Mock(side_effect=AssertionError("process must not start"))
            with self.assertRaises(common.P5Error) as caught:
                catalog._run_logged(
                    ["synthetic-command"], log, cwd=Path(tmp), process_factory=never,
                    memory_reader=lambda: catalog.MIN_AVAILABLE_KIB - 1, sleep=lambda _seconds: None,
                    owner_uid=os.geteuid(),
                )
            self.assertEqual(caught.exception.code, "MEMORY_GUARD_BELOW_1536_MIB")
            never.assert_not_called()


class P5BackupSyntheticTests(unittest.TestCase):
    def test_dump_validation_checks_gzip_and_exact_production_schema_header(self):
        sql = (b"-- Server version 8.4.0\nCREATE DATABASE /*!32312 IF NOT EXISTS*/ "
               b"`erpcondominios_stage_20261114` /*!40100 DEFAULT CHARACTER SET utf8mb4 "
               b"COLLATE utf8mb4_unicode_ci */;\nUSE `erpcondominios_stage_20261114`;\n")
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "synthetic.sql.gz"
            path.write_bytes(gzip.compress(sql, mtime=0))
            path.chmod(0o600)
            calls = []

            def fake_runner(argv, **kwargs):
                calls.append(argv)
                return type("Result", (), {"returncode": 0})()

            digest, size = backup._verify_dump(path, runner=fake_runner, owner_uid=os.geteuid())
            self.assertEqual(len(digest), 64)
            self.assertEqual(size, path.stat().st_size)
            self.assertEqual(len(calls), 1)
            self.assertEqual(calls[0][0:4], [str(backup.NICE), "-n", "19", str(backup.IONICE)])
            self.assertIn(str(backup.GZIP), calls[0])

    def test_wrong_schema_is_rejected_without_invoking_dump_tools(self):
        sql = (b"-- Server version 8.4.0\nCREATE DATABASE `erpcondominios`;\n"
               b"USE `erpcondominios`;\n")
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "wrong.sql.gz"
            path.write_bytes(gzip.compress(sql, mtime=0))
            path.chmod(0o600)
            checked_gzip = mock.Mock(return_value=type("Result", (), {"returncode": 0})())
            with self.assertRaises(common.P5Error) as caught:
                backup._verify_dump(path, runner=checked_gzip, owner_uid=os.geteuid())
            self.assertEqual(caught.exception.code, "DUMP_HEADER_SCHEMA_INVALID")
            checked_gzip.assert_called_once()

    def test_backup_never_starts_when_configuration_is_missing(self):
        never = mock.Mock(side_effect=AssertionError("mysqldump must not run"))
        with mock.patch.object(backup, "root_required"), \
             mock.patch.object(backup, "CONFIG", Path("/nonexistent/p5/mysql-final.json")):
            with self.assertRaises(common.P5Error):
                backup.create_backup(runner=never, popen_factory=never, owner_uid=os.geteuid())
        never.assert_not_called()

    def test_dedicated_dump_credential_remains_explicitly_blocked(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "mysql.cnf"
            path.write_text("[client]\nhost=localhost\nuser=synthetic\npassword=synthetic\ndatabase=erpcondominios_stage_20261114\n", encoding="utf-8")
            path.chmod(0o600)
            with self.assertRaises(common.P5Error) as caught:
                backup._check_secret(path, owner_uid=os.geteuid())
            self.assertEqual(caught.exception.code, "MYSQL_CREDENTIAL_POLICY_UNCONFIGURED")

    def test_low_memory_terminates_synthetic_backup_processes(self):
        first, second = _RunningProcess(), _RunningProcess()
        with self.assertRaises(common.P5Error) as caught:
            backup._guarded_wait([first, second], memory_reader=lambda: backup.MIN_AVAILABLE_KIB - 1,
                                 sleep=lambda _seconds: None)
        self.assertEqual(caught.exception.code, "MEMORY_GUARD_BELOW_1536_MIB")
        self.assertEqual(first.returncode, -15)
        self.assertEqual(second.returncode, -15)


class P5MaintenanceSyntheticTests(unittest.TestCase):
    def _write_config(self, path: Path, cidr: str):
        data = {
            "vhosts": [
                {"kind": "domain", "path": "/etc/nginx/sites-enabled/app.conf", "server_name": "app.erpcondominios.com.br"},
                {"kind": "ip", "path": "/etc/nginx/sites-enabled/ip.conf", "server_name": "2.29.161.106"},
            ],
            "allowed_client_cidr": cidr,
            "nginx_main": str(maintenance.NGINX_MAIN),
            "backup_root": str(maintenance.BACKUP_ROOT),
            "approval_file": str(maintenance.APPROVAL),
        }
        path.write_text(json.dumps(data), encoding="utf-8")
        path.chmod(0o600)

    def test_public_ipv4_host_route_is_allowed_from_private_config(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "maintenance.json"
            self._write_config(path, "1.1.1.1/32")
            with mock.patch.object(maintenance, "CONFIG", path):
                data, cidr = maintenance._load_config(owner_uid=os.geteuid())
            self.assertEqual(cidr, "1.1.1.1/32")
            self.assertEqual(len(data["vhosts"]), 2)
            generated = maintenance._map_config(cidr).decode("ascii")
            self.assertIn("1.1.1.1 1;", generated)
            self.assertIn("default 0;", generated)
            self.assertIn("$p5_maintenance_block", generated)

    def test_missing_empty_or_non_host_allowlist_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "maintenance.json"
            for cidr in ("", "1.1.1.1/24", "not-an-ip/32", "0.0.0.0/32"):
                with self.subTest(cidr_state="empty" if not cidr else "invalid"):
                    self._write_config(path, cidr)
                    with mock.patch.object(maintenance, "CONFIG", path):
                        with self.assertRaises(common.P5Error) as caught:
                            maintenance._load_config(owner_uid=os.geteuid())
                    self.assertEqual(caught.exception.code, "PRIVATE_ALLOWLIST_CIDR_INVALID")

    def test_maps_default_off_and_location_returns_503_when_block_marker_is_set(self):
        generated = maintenance._map_config("1.1.1.1/32").decode("ascii")
        self.assertIn("map $remote_addr $p5_maintenance_allowlisted", generated)
        self.assertIn("map $host $p5_maintenance_activation", generated)
        self.assertIn('"1:1" 1;', generated)
        location = "if ($p5_maintenance_block != 0) { return 503; }"
        self.assertIn("return 503", location)
        self.assertNotIn("listen", generated)
        self.assertNotIn("server {", generated)

    def test_missing_configuration_blocks_before_nginx_or_file_changes(self):
        with mock.patch.object(maintenance, "CONFIG", Path("/nonexistent/p5/maintenance.json")):
            with self.assertRaises(common.P5Error):
                maintenance.check(owner_uid=os.geteuid())


class P5RollbackSyntheticTests(unittest.TestCase):
    def test_scope_is_production_current_and_no_database_operations(self):
        self.assertEqual(str(rollback.CURRENT), "/var/www/erpcondominios/current")
        self.assertEqual(str(rollback.RELEASES), "/var/www/erpcondominios/releases")
        source = (P5_DIR / "rollback.py").read_text(encoding="utf-8")
        self.assertIn("rollback-production-mysql", source)
        self.assertIn("database_operations", source)
        self.assertIn("erpcondominios_stage_20261114", source)
        self.assertIn("private_config_path", source)
        self.assertIn("maintenance_must_remain_active_until_smoke", source)
        self.assertNotIn("psql", source.lower())

    def test_missing_root_state_fails_before_mutation(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "app"
            releases = root / "releases"
            releases.mkdir(parents=True)
            root.chmod(0o755)
            releases.chmod(0o755)
            current = root / "current"
            old_target = releases / ("a" * 40)
            old_target.mkdir()
            old_target.chmod(0o755)
            current.symlink_to(old_target)
            with mock.patch.object(rollback, "APP_ROOT", root), \
                 mock.patch.object(rollback, "RELEASES", releases), \
                 mock.patch.object(rollback, "CURRENT", current), \
                 mock.patch.object(rollback, "STATE", Path(tmp) / "missing-state.json"):
                with self.assertRaises(common.P5Error):
                    rollback.plan(owner_uid=os.geteuid())
                self.assertEqual(os.readlink(current), str(old_target))

    def test_plan_requires_active_production_maintenance_without_reload_or_systemctl(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            conf_dir = root / "etc/nginx/conf.d"
            bin_dir = root / "usr/sbin"
            conf_dir.mkdir(parents=True)
            bin_dir.mkdir(parents=True)
            allow_config = conf_dir / "erp-p5-maintenance-maps.conf"
            active_entries = conf_dir / "erp-p5-maintenance-active.map"
            allow_config.write_bytes(maintenance._map_config("1.1.1.1/32"))
            names = "|".join(__import__("re").escape(name) for name in (rollback.PRODUCTION_DOMAIN, rollback.PRODUCTION_IP))
            active_entries.write_text(f"~^(?:{names})$ 1;\n", encoding="ascii")
            allow_config.chmod(0o644)
            active_entries.chmod(0o644)
            nginx = bin_dir / "nginx"
            nginx.write_text("synthetic executable\n", encoding="ascii")
            nginx.chmod(0o755)
            output = (allow_config.read_text(encoding="ascii") + active_entries.read_text(encoding="ascii")
                      + "if ($p5_maintenance_block != 0) { return 503; }\n"
                      + f"server_name {rollback.PRODUCTION_DOMAIN};\nserver_name {rollback.PRODUCTION_IP};\n")
            called = []

            def fake_runner(argv, **_kwargs):
                called.append(argv)
                return type("Result", (), {"returncode": 0, "stdout": output.encode("utf-8")})()

            with mock.patch.object(rollback, "ALLOW_CONFIG", allow_config), \
                 mock.patch.object(rollback, "ACTIVE_ENTRIES", active_entries), \
                 mock.patch.object(rollback, "NGINX", nginx):
                rollback._maintenance_active(runner=fake_runner, owner_uid=os.geteuid())
                self.assertEqual(len(called), 1)
                self.assertEqual(called[0], [str(nginx), "-T"])

                active_entries.write_text("# inactive\n", encoding="ascii")
                with self.assertRaises(common.P5Error) as caught:
                    rollback._maintenance_active(runner=fake_runner, owner_uid=os.geteuid())
                self.assertEqual(caught.exception.code, "MAINTENANCE_NOT_ACTIVE")
                self.assertEqual(len(called), 1)


if __name__ == "__main__":
    unittest.main(verbosity=2)
