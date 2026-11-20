"""Testes sintéticos P5; nenhum banco, backup, Nginx, VM ou symlink de produção é acessado."""
from __future__ import annotations

from contextlib import ExitStack
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
    def _environment(self, stack: ExitStack, root: Path):
        owner_uid = os.geteuid()
        root.chmod(0o755)
        vhost_dir = root / "sites-enabled"
        conf_dir = root / "conf.d"
        snippets_dir = root / "snippets"
        backup_root = root / "backups"
        lock_dir = root / "run-lock"
        for directory in (vhost_dir, conf_dir, snippets_dir):
            directory.mkdir(mode=0o755)
        backup_root.mkdir(mode=0o700)
        lock_dir.mkdir(mode=0o700)
        main_path = root / "nginx.conf"
        main_path.write_text(maintenance.MAIN_MARKER + "\n", encoding="utf-8")
        main_path.chmod(0o644)
        vhosts = [
            {"kind": "domain", "path": str(vhost_dir / "app.conf"), "server_name": "app.erpcondominios.com.br"},
            {"kind": "ip", "path": str(vhost_dir / "ip.conf"), "server_name": "2.29.161.106"},
        ]
        for item in vhosts:
            path = Path(item["path"])
            path.write_text(
                "server {\n"
                "    listen 443 ssl;\n"
                f"    server_name {item['server_name']};\n"
                f"    root {maintenance.PRODUCTION_ROOT};\n"
                f"    {maintenance.VHOST_MARKER}\n"
                "}\n",
                encoding="utf-8",
            )
            path.chmod(0o644)
        config_path = root / "maintenance.json"
        approval_path = root / "maintenance-approval.json"
        allow_path = conf_dir / "erp-p5-maintenance-maps.conf"
        active_path = conf_dir / "erp-p5-maintenance-active.map"
        location_path = snippets_dir / "erp-p5-maintenance-503.conf"
        config_path.write_text(json.dumps({
            "vhosts": vhosts,
            "allowed_client_cidr": "1.1.1.1/32",
            "nginx_main": str(main_path),
            "backup_root": str(backup_root),
            "approval_file": str(approval_path),
        }), encoding="utf-8")
        config_path.chmod(0o600)
        for name, value in {
            "CONFIG": config_path,
            "APPROVAL": approval_path,
            "BACKUP_ROOT": backup_root,
            "LOCK": lock_dir / "maintenance.lock",
            "NGINX_MAIN": main_path,
            "ALLOW_CONFIG": allow_path,
            "ACTIVE_ENTRIES": active_path,
            "LOCATION": location_path,
            "VHOST_DIR": vhost_dir,
            "NGINX": Path("/usr/bin/true"),
        }.items():
            stack.enter_context(mock.patch.object(maintenance, name, value))
        stack.enter_context(mock.patch.object(maintenance, "root_required"))
        stack.enter_context(mock.patch.object(maintenance, "validate_approval"))
        return owner_uid, main_path, [Path(item["path"]) for item in vhosts], active_path

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

    def test_prepare_activate_deactivate_state_machine_is_consistent(self):
        class Result:
            returncode = 0

        with tempfile.TemporaryDirectory() as tmp, ExitStack() as stack:
            owner_uid, _main_path, _vhosts, active_path = self._environment(stack, Path(tmp))
            runner = mock.Mock(return_value=Result())
            prepared = maintenance.prepare(runner=runner, owner_uid=owner_uid)
            self.assertEqual(prepared["status"], "PREPARED_NOT_RELOADED")
            self.assertEqual(maintenance.check(owner_uid=owner_uid)[2], "PREPARED")
            activated = maintenance.activate(runner=runner, owner_uid=owner_uid)
            self.assertEqual(activated["status"], "ACTIVATION_MARKER_PREPARED_NOT_RELOADED")
            self.assertEqual(maintenance.check(owner_uid=owner_uid)[2], "ACTIVE")
            self.assertIn("1;", active_path.read_text(encoding="ascii"))
            deactivated = maintenance.deactivate(runner=runner, owner_uid=owner_uid)
            self.assertEqual(deactivated["status"], "DEACTIVATION_MARKER_CLEARED_NOT_RELOADED")
            self.assertEqual(maintenance.check(owner_uid=owner_uid)[2], "PREPARED")
            self.assertIn("Inactive by default", active_path.read_text(encoding="ascii"))
            self.assertEqual(runner.call_count, 3)

    def test_prepare_restores_original_configs_when_nginx_test_fails(self):
        class Result:
            returncode = 1

        with tempfile.TemporaryDirectory() as tmp, ExitStack() as stack:
            owner_uid, main_path, vhosts, active_path = self._environment(stack, Path(tmp))
            originals = {path: path.read_bytes() for path in [main_path, *vhosts]}
            with self.assertRaises(common.P5Error) as caught:
                maintenance.prepare(runner=mock.Mock(return_value=Result()), owner_uid=owner_uid)
            self.assertEqual(caught.exception.code, "NGINX_TEST_FAILED")
            for path, content in originals.items():
                self.assertEqual(path.read_bytes(), content)
            self.assertFalse(active_path.exists())
            self.assertEqual(maintenance.check(owner_uid=owner_uid)[2], "UNPREPARED")

    def test_prepare_rejects_allowlist_change_while_waiting_for_lock(self):
        with tempfile.TemporaryDirectory() as tmp, ExitStack() as stack:
            owner_uid, main_path, vhosts, active_path = self._environment(stack, Path(tmp))
            original_check = maintenance.check

            def changed_config(*, owner_uid=0):
                data, _cidr, state = original_check(owner_uid=owner_uid)
                data = dict(data)
                data["allowed_client_cidr"] = "8.8.8.8/32"
                return data, "8.8.8.8/32", state

            with mock.patch.object(
                maintenance,
                "check",
                side_effect=[original_check(owner_uid=owner_uid), changed_config(owner_uid=owner_uid)],
            ):
                with self.assertRaises(common.P5Error) as caught:
                    maintenance.prepare(owner_uid=owner_uid)
            self.assertEqual(caught.exception.code, "MAINTENANCE_STATE_CHANGED")
            self.assertEqual(main_path.read_text(encoding="utf-8"), maintenance.MAIN_MARKER + "\n")
            self.assertTrue(all(path.exists() for path in vhosts))
            self.assertFalse(active_path.exists())
            self.assertEqual(list(maintenance.BACKUP_ROOT.iterdir()), [])

    def test_write_removes_temporary_file_when_atomic_write_fails(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            target = root / "nginx.conf"
            target.write_bytes(b"original\n")
            target.chmod(0o644)
            with mock.patch.object(maintenance.os, "chown", side_effect=OSError("synthetic failure")):
                with self.assertRaises(OSError):
                    maintenance._write(target, b"replacement\n", owner_uid=os.geteuid())
            self.assertEqual(target.read_bytes(), b"original\n")
            self.assertEqual(list(root.glob(".nginx.conf.*.tmp")), [])

    def test_prepare_rolls_back_earlier_files_after_partial_write_failure(self):
        with tempfile.TemporaryDirectory() as tmp, ExitStack() as stack:
            owner_uid, main_path, vhosts, active_path = self._environment(stack, Path(tmp))
            originals = {path: path.read_bytes() for path in [main_path, *vhosts]}
            original_write = maintenance._write
            failed = False

            def fail_once(path, payload, owner_uid=0):
                nonlocal failed
                if path == vhosts[0] and not failed:
                    failed = True
                    raise OSError("synthetic partial write failure")
                return original_write(path, payload, owner_uid)

            with mock.patch.object(maintenance, "_write", side_effect=fail_once):
                with self.assertRaises(OSError):
                    maintenance.prepare(owner_uid=owner_uid)
            self.assertTrue(failed)
            for path, content in originals.items():
                self.assertEqual(path.read_bytes(), content)
            self.assertFalse(active_path.exists())
            self.assertEqual(list(Path(tmp).rglob(".*.tmp")), [])
            self.assertEqual(maintenance.check(owner_uid=owner_uid)[2], "UNPREPARED")

    def test_prepare_rolls_back_before_propagating_keyboard_interrupt(self):
        with tempfile.TemporaryDirectory() as tmp, ExitStack() as stack:
            owner_uid, main_path, vhosts, active_path = self._environment(stack, Path(tmp))
            originals = {path: path.read_bytes() for path in [main_path, *vhosts]}
            original_write = maintenance._write
            interrupted = False

            def interrupt_once(path, payload, owner_uid=0):
                nonlocal interrupted
                if path == vhosts[0] and not interrupted:
                    interrupted = True
                    raise KeyboardInterrupt
                return original_write(path, payload, owner_uid)

            with mock.patch.object(maintenance, "_write", side_effect=interrupt_once):
                with self.assertRaises(KeyboardInterrupt):
                    maintenance.prepare(owner_uid=owner_uid)
            self.assertTrue(interrupted)
            for path, content in originals.items():
                self.assertEqual(path.read_bytes(), content)
            self.assertFalse(active_path.exists())
            self.assertEqual(list(Path(tmp).rglob(".*.tmp")), [])
            self.assertEqual(maintenance.check(owner_uid=owner_uid)[2], "UNPREPARED")

    def test_activate_restores_marker_when_nginx_test_fails(self):
        class Ok:
            returncode = 0

        class Failed:
            returncode = 1

        with tempfile.TemporaryDirectory() as tmp, ExitStack() as stack:
            owner_uid, _main_path, _vhosts, active_path = self._environment(stack, Path(tmp))
            maintenance.prepare(runner=mock.Mock(return_value=Ok()), owner_uid=owner_uid)
            original = active_path.read_bytes()
            with self.assertRaises(common.P5Error) as caught:
                maintenance.activate(runner=mock.Mock(return_value=Failed()), owner_uid=owner_uid)
            self.assertEqual(caught.exception.code, "NGINX_TEST_FAILED")
            self.assertEqual(active_path.read_bytes(), original)
            self.assertEqual(maintenance.check(owner_uid=owner_uid)[2], "PREPARED")

    def test_deactivate_restores_active_marker_when_nginx_test_fails(self):
        class Ok:
            returncode = 0

        class Failed:
            returncode = 1

        with tempfile.TemporaryDirectory() as tmp, ExitStack() as stack:
            owner_uid, _main_path, _vhosts, active_path = self._environment(stack, Path(tmp))
            maintenance.prepare(runner=mock.Mock(return_value=Ok()), owner_uid=owner_uid)
            maintenance.activate(runner=mock.Mock(return_value=Ok()), owner_uid=owner_uid)
            active = active_path.read_bytes()
            with self.assertRaises(common.P5Error) as caught:
                maintenance.deactivate(runner=mock.Mock(return_value=Failed()), owner_uid=owner_uid)
            self.assertEqual(caught.exception.code, "NGINX_TEST_FAILED")
            self.assertEqual(active_path.read_bytes(), active)
            self.assertEqual(maintenance.check(owner_uid=owner_uid)[2], "ACTIVE")
            backups = list(maintenance.BACKUP_ROOT.iterdir())
            self.assertEqual(len(backups), 5)
            self.assertEqual(len({path.name for path in backups}), len(backups))


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
