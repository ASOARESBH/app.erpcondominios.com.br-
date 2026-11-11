import sys
import os
import gzip
import json
import hashlib
import signal
import tempfile
import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parents[2] / "tools" / "migracao_pg"
sys.path.insert(0, str(ROOT))

from mysql_source import (
    ProvisioningError,
    READER_HOST,
    READER_USER,
    _provision_account,
    validate_reader_connection,
    validate_network_namespace,
    validate_reader_options,
    validate_config_targets,
    read_private_client_config,
    validate_root_secret_pair,
    validate_container_secret_bundle,
    validate_v10e_clone,
    V10E_MANIFEST,
    V10E_REPOSITORY_URL,
    V10E_BRANCH,
    V10E_C2_COMMIT,
    V10E_BASELINE_COMMIT,
    V10E_CONTAINER,
    V10E_ROOT,
    V10E_DUMP,
    V10E_SECRETS,
    V10E_CONTAINER_SECRETS,
    V10E_MYSQL_DATA,
    V10E_MYSQL_SOCKET,
)
import mysql_source
from catalog import validate_reader_grants


class FakeAdminCursor:
    def __init__(self, *, lowercase_accounts=0, uppercase_accounts=0, wrong_host_accounts=0,
                 fail_on=None, interrupt_on=None,
                 admin_identity="root@localhost"):
        self.lowercase_accounts = lowercase_accounts
        self.uppercase_accounts = uppercase_accounts
        self.accounts = ([ ("pgloader", "127.0.0.1") ] * lowercase_accounts
                         + [("PGLOADER", "127.0.0.1")] * uppercase_accounts
                         + [("pgloader", "localhost")] * wrong_host_accounts)
        self.fail_on = fail_on
        self.interrupt_on = interrupt_on
        self.admin_identity = admin_identity
        self.read_only = 1
        self.super_read_only = 1
        self.statements = []
        self.params = []
        self.result = None

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb):
        return False

    def execute(self, statement, params=None):
        self.statements.append(statement)
        self.params.append(params)
        if self.fail_on and self.fail_on in statement:
            raise RuntimeError("synthetic database secret that must not escape")
        if statement == "SELECT CURRENT_USER()":
            self.result = (self.admin_identity,)
        elif statement.startswith("SELECT VERSION()"):
            self.result = ("5.7.44-log", 3306, self.read_only, self.super_read_only, "OFF")
        elif "information_schema.SCHEMATA" in statement:
            self.result = (1,)
        elif "mysql.user" in statement and "BINARY User" in statement:
            wanted_user, wanted_host = params
            self.result = (sum(user == wanted_user and host == wanted_host
                               for user, host in self.accounts),)
        elif statement == "SET GLOBAL read_only=OFF":
            self.read_only = 0
            self.result = None
        elif statement == "SET GLOBAL super_read_only=OFF":
            self.super_read_only = 0
            self.result = None
        elif statement == "SET GLOBAL read_only=ON":
            self.read_only = 1
            self.result = None
        elif statement == "SET GLOBAL super_read_only=ON":
            self.super_read_only = 1
            self.result = None
        else:
            self.result = None
        if self.interrupt_on and self.interrupt_on in statement:
            mysql_source.record_interruption(signal.SIGTERM, None)

    def fetchone(self):
        result, self.result = self.result, None
        return result


class FakeAdminConnection:
    def __init__(self, cursor):
        self._cursor = cursor
        self.closed = False

    def cursor(self):
        return self._cursor

    def close(self):
        self.closed = True


class MySQLSourceProvisioningTests(unittest.TestCase):
    def make_frozen_source(self, *, lowercase_accounts=0, uppercase_accounts=0, wrong_host_accounts=0,
                           fail_on=None,
                           interrupt_on=None):
        cursor = FakeAdminCursor(lowercase_accounts=lowercase_accounts,
                                 uppercase_accounts=uppercase_accounts, wrong_host_accounts=wrong_host_accounts,
                                 fail_on=fail_on,
                                 interrupt_on=interrupt_on)
        return FakeAdminConnection(cursor), cursor

    def test_missing_lowercase_account_is_created_with_exact_host(self):
        conn, cursor = self.make_frozen_source(lowercase_accounts=0)
        _provision_account(conn, "synthetic-password")
        create = next(i for i, sql in enumerate(cursor.statements) if sql.startswith("CREATE USER"))
        self.assertEqual(cursor.statements[create],
                         "CREATE USER IF NOT EXISTS 'pgloader'@'127.0.0.1' IDENTIFIED BY %s")
        self.assertEqual(cursor.params[create], ("synthetic-password",))
        self.assertEqual(cursor.read_only, 1)
        self.assertEqual(cursor.super_read_only, 1)

    def test_uppercase_account_does_not_satisfy_lowercase_identity(self):
        conn, cursor = self.make_frozen_source(lowercase_accounts=0, uppercase_accounts=1)
        _provision_account(conn, "synthetic-password")
        query_index = next(i for i, sql in enumerate(cursor.statements)
                           if "mysql.user" in sql and "BINARY User" in sql)
        self.assertEqual(cursor.params[query_index], ("pgloader", "127.0.0.1"))
        self.assertEqual(cursor.accounts, [("PGLOADER", "127.0.0.1")])
        self.assertTrue(any(sql.startswith("CREATE USER IF NOT EXISTS 'pgloader'@'127.0.0.1'")
                            for sql in cursor.statements))

    def test_existing_lowercase_user_at_wrong_host_does_not_satisfy_reader_identity(self):
        conn, cursor = self.make_frozen_source(wrong_host_accounts=1)
        _provision_account(conn, "synthetic-password")
        self.assertEqual(cursor.accounts, [("pgloader", "localhost")])
        self.assertTrue(any(sql.startswith("CREATE USER IF NOT EXISTS 'pgloader'@'127.0.0.1'")
                            for sql in cursor.statements))

    def test_existing_lowercase_account_is_reconciled_idempotently(self):
        conn, cursor = self.make_frozen_source(lowercase_accounts=1)
        _provision_account(conn, "synthetic-password")
        self.assertTrue(any(sql.startswith("ALTER USER 'pgloader'@'127.0.0.1'")
                            for sql in cursor.statements))
        self.assertFalse(any(sql.startswith("CREATE USER") for sql in cursor.statements))
        self.assertEqual(cursor.read_only, 1)
        self.assertEqual(cursor.super_read_only, 1)

    def test_grants_are_replaced_with_only_schema_scoped_reader_grants(self):
        conn, cursor = self.make_frozen_source(lowercase_accounts=1)
        _provision_account(conn, "synthetic-password")
        self.assertIn("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'pgloader'@'127.0.0.1'",
                      cursor.statements)
        self.assertIn("GRANT SELECT, SHOW VIEW, TRIGGER ON `inlaud99_erpcondor`.* "
                      "TO 'pgloader'@'127.0.0.1'", cursor.statements)
        grants = [statement.upper() for statement in cursor.statements
                  if statement.upper().startswith("GRANT ")]
        self.assertEqual(len(grants), 1)
        self.assertFalse(any("ALL PRIVILEGES" in statement or " SUPER " in statement for statement in grants))

    def test_insufficient_effective_grants_fail_closed(self):
        with self.assertRaises(RuntimeError):
            validate_reader_grants("pgloader@127.0.0.1", [
                "GRANT USAGE ON *.* TO 'pgloader'@'127.0.0.1' IDENTIFIED BY PASSWORD '" + "*" + "a" * 40 + "'",
                "GRANT SELECT ON `inlaud99_erpcondor`.* TO 'pgloader'@'127.0.0.1'",
            ], "*" + "a" * 40)

    def test_wrong_configured_username_is_rejected(self):
        with self.assertRaisesRegex(ProvisioningError, "reader_config_user_mismatch"):
            validate_reader_options({"user": "PGLOADER", "host": READER_HOST,
                                     "port": "3306", "password": "synthetic-password"})

    def test_wrong_host_or_port_is_rejected(self):
        for options in (
            {"user": READER_USER, "host": "localhost", "port": "3306", "password": "synthetic-password"},
            {"user": READER_USER, "host": READER_HOST, "port": "3307", "password": "synthetic-password"},
        ):
            with self.subTest(options={key: value for key, value in options.items() if key != "password"}), \
                    self.assertRaisesRegex(ProvisioningError, "reader_config_endpoint_mismatch"):
                validate_reader_options(options)

    def test_wrong_protocol_is_rejected(self):
        with self.assertRaisesRegex(ProvisioningError, "reader_config_endpoint_mismatch"):
            validate_reader_options({"user": "pgloader", "host": READER_HOST,
                                     "port": "3306", "protocol": "SOCKET",
                                     "password": "synthetic-password"})

    def test_network_namespace_must_match_runner_attestation(self):
        inode = os.stat("/proc/self/ns/net").st_ino
        validate_network_namespace(inode)
        with patch("mysql_source.os.stat", return_value=MagicMock(st_ino=inode + 1)):
            with self.assertRaisesRegex(ProvisioningError, "network_namespace_mismatch"):
                validate_network_namespace(inode)
        with self.assertRaisesRegex(ProvisioningError, "network_namespace_invalid"):
            validate_network_namespace(0)

    def test_config_paths_must_be_the_private_v10e_pair(self):
        with tempfile.TemporaryDirectory() as directory:
            secrets = Path(directory) / "secrets"
            container_secrets = Path(directory) / "mysql-secrets"
            with patch("mysql_source.V10E_SECRETS", secrets), \
                    patch("mysql_source.V10E_CONTAINER_SECRETS", container_secrets):
                validate_config_targets(container_secrets / "client.cnf", secrets / "pgloader.cnf")
                with self.assertRaisesRegex(ProvisioningError, "provisioner_config_target_mismatch"):
                    validate_config_targets("/var/lib/erp-pg-rehearsal/v10d/secrets/client.cnf",
                                            secrets / "pgloader.cnf")

    def test_private_mysql_config_rejects_symlinks_and_permissive_mode(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            config = root / "client.cnf"
            config.write_text("[client]\nuser=pgloader\npassword=synthetic-secret\n", encoding="utf-8")
            config.chmod(0o400)
            self.assertEqual(read_private_client_config(config, os.getuid())["user"], "pgloader")
            link = root / "linked.cnf"
            link.symlink_to(config)
            with self.assertRaisesRegex(ProvisioningError, "client_config_unsafe"):
                read_private_client_config(link, os.getuid())
            config.chmod(0o644)
            with self.assertRaisesRegex(ProvisioningError, "client_config_unsafe"):
                read_private_client_config(config, os.getuid())

    def test_root_secret_pair_mismatch_is_rejected_without_revealing_either_value(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            admin = root / "client.cnf"
            admin.write_text("[client]\nuser=root\npassword=synthetic-admin\nhost=127.0.0.1\n",
                             encoding="utf-8")
            admin.chmod(0o400)
            rootpass = root / "root.pass"
            rootpass.write_bytes(b"different-synthetic-secret")
            rootpass.chmod(0o400)
            with patch("mysql_source.V10E_SECRETS", root), patch("mysql_source.V10E_CONTAINER_SECRETS", root):
                with self.assertRaisesRegex(ProvisioningError, "v10e_root_secret_pair_mismatch") as caught:
                    validate_root_secret_pair(os.getuid())
            self.assertNotIn("synthetic-admin", str(caught.exception))
            self.assertNotIn("different-synthetic-secret", str(caught.exception))

    def test_container_secret_bundle_rejects_extra_file_and_symlink(self):
        with tempfile.TemporaryDirectory() as directory:
            bundle = Path(directory) / "mysql-secrets"
            bundle.mkdir(mode=0o700)
            for name in ("client.cnf", "root.pass"):
                path = bundle / name
                path.write_text("synthetic", encoding="utf-8")
                path.chmod(0o400)
            with patch("mysql_source.V10E_CONTAINER_SECRETS", bundle):
                validate_container_secret_bundle(os.getuid())
                (bundle / "unapproved.cnf").write_text("synthetic", encoding="utf-8")
                with self.assertRaisesRegex(ProvisioningError, "v10e_container_secret_bundle_invalid"):
                    validate_container_secret_bundle(os.getuid())
                (bundle / "unapproved.cnf").unlink()
                (bundle / "client.cnf").rename(bundle / "client.real")
                (bundle / "client.cnf").symlink_to(bundle / "client.real")
                with self.assertRaisesRegex(ProvisioningError, "v10e_container_secret_bundle_invalid"):
                    validate_container_secret_bundle(os.getuid())

    def v10e_fixture(self):
        dump = gzip.compress(b"synthetic v10e source snapshot")
        manifest = {
            "target_database": "erp_ensaio_v10e", "code_branch": V10E_BRANCH,
            "code_commit": "b" * 40, "source_schema": "inlaud99_erpcondor",
            "dump_sha256": hashlib.sha256(dump).hexdigest(), "dump_bytes": len(dump),
            "mysql_version": "5.7.44-log",
            "container_name": V10E_CONTAINER, "container_id": "c" * 64,
            "image_name": "docker.io/library/mysql:5.7", "image_id": "sha256:" + "d" * 64,
            "restore_status": "complete", "restored_at_utc": "2026-11-11T00:00:00Z",
        }
        pid = os.getpid()
        container = {
            "Name": f"/{V10E_CONTAINER}",
            "Id": manifest["container_id"], "Image": manifest["image_id"],
            "ImageName": manifest["image_name"], "AppArmorProfile": "erp-mysql-rehearsal",
            "Args": ["--bind-address=127.0.0.1", "--event-scheduler=OFF", "--local-infile=0",
                     "--secure-file-priv=NULL", "--default-time-zone=-03:00",
                     "--innodb-buffer-pool-size=512M", "--max-allowed-packet=256M"],
            "Config": {"Env": ["MYSQL_DATABASE=inlaud99_erpcondor",
                                "MYSQL_ROOT_PASSWORD_FILE=/run/rehearsal-secrets/root.pass",
                                "MYSQL_INITDB_SKIP_TZINFO=1"]},
            "HostConfig": {"NetworkMode": "none", "PortBindings": {}, "Privileged": False,
                           "CapAdd": [], "SecurityOpt": ["no-new-privileges", "apparmor=erp-mysql-rehearsal"]},
            "State": {"Running": True, "Pid": pid},
            "Mounts": [
                {"Type": "bind", "Destination": "/var/lib/mysql", "Source": str(V10E_MYSQL_DATA), "RW": True},
                {"Type": "bind", "Destination": "/var/run/mysqld", "Source": str(V10E_MYSQL_SOCKET), "RW": True},
                {"Type": "bind", "Destination": "/run/rehearsal-secrets", "Source": str(V10E_SECRETS), "RW": False},
            ],
        }
        return manifest, container, os.stat(f"/proc/{pid}/ns/net").st_ino, dump

    def _source_fixture_paths(self, root, manifest, dump, container):
        secrets = root / "secrets"
        secrets.mkdir(mode=0o700)
        data = root / "mysql-data"
        socket = root / "mysql-socket"
        container_secrets = root / "mysql-secrets"
        data.mkdir(mode=0o700)
        socket.mkdir(mode=0o700)
        container_secrets.mkdir(mode=0o700)
        admin_config = container_secrets / "client.cnf"
        admin_config.write_text("[client]\nuser=root\npassword=synthetic-root\nhost=127.0.0.1\nport=3306\nprotocol=TCP\n",
                                encoding="utf-8")
        admin_config.chmod(0o400)
        root_password = container_secrets / "root.pass"
        root_password.write_bytes(b"synthetic-root")
        root_password.chmod(0o400)
        dump_path = root / "source-copy.sql.gz"
        dump_path.write_bytes(dump)
        dump_path.chmod(0o400)
        manifest_path = secrets / "source-manifest.json"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        manifest_path.chmod(0o600)
        container["Mounts"][0]["Source"] = str(data)
        container["Mounts"][1]["Source"] = str(socket)
        container["Mounts"][2]["Source"] = str(container_secrets)
        return secrets, container_secrets, data, socket, dump_path, manifest_path

    def _mock_source_commands(self, container, *, branch=V10E_BRANCH):
        git_values = iter([
            MagicMock(returncode=0, stdout=V10E_REPOSITORY_URL),
            MagicMock(returncode=0, stdout=branch),
            MagicMock(returncode=0, stdout="b" * 40),
            MagicMock(returncode=0, stdout=""),
            MagicMock(returncode=0), MagicMock(returncode=0),
        ])
        def fake_run(command, *args, **kwargs):
            if command[0] == "git":
                return next(git_values)
            if command[0] == "gzip":
                return MagicMock(returncode=0)
            if command[0] == "podman":
                return MagicMock(returncode=0, stdout=json.dumps([container]))
            raise AssertionError("unexpected subprocess in clone validator")
        return fake_run

    def _patch_source(self, stack, root, secrets, container_secrets, data, socket, dump_path,
                      manifest_path, container,
                      *, branch=V10E_BRANCH):
        patchers = (
            patch("mysql_source.V10E_ROOT", root), patch("mysql_source.V10E_SECRETS", secrets),
            patch("mysql_source.V10E_MANIFEST", manifest_path), patch("mysql_source.V10E_DUMP", dump_path),
            patch("mysql_source.V10E_CONTAINER_SECRETS", container_secrets),
            patch("mysql_source.V10E_MYSQL_DATA", data), patch("mysql_source.V10E_MYSQL_SOCKET", socket),
            patch("mysql_source.pwd.getpwnam", return_value=MagicMock(pw_uid=os.getuid())),
        )
        for patcher in patchers:
            stack.enter_context(patcher)
        return stack.enter_context(patch(
            "mysql_source.subprocess.run", side_effect=self._mock_source_commands(container, branch=branch)))

    def test_provisioner_validates_manifest_git_branch_sha_and_isolated_container(self):
        manifest, container, inode, dump = self.v10e_fixture()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / "v10e"
            root.mkdir(mode=0o700)
            secrets, container_secrets, data, socket, dump_path, manifest_path = self._source_fixture_paths(
                root, manifest, dump, container)
            with ExitStack() as stack:
                run = self._patch_source(stack, root, secrets, container_secrets, data, socket, dump_path,
                                         manifest_path, container)
                validate_v10e_clone("b" * 40, "5.7.44-log", inode, expected_uid=os.getuid())
            self.assertEqual(run.call_count, 8)
            self.assertEqual(run.call_args_list[-1].args[0], ["podman", "inspect", V10E_CONTAINER])

    def test_provisioner_rejects_wrong_git_state_manifest_and_container(self):
        manifest, container, inode, dump = self.v10e_fixture()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / "v10e"
            root.mkdir(mode=0o700)
            secrets, container_secrets, data, socket, dump_path, manifest_path = self._source_fixture_paths(
                root, manifest, dump, container)
            with ExitStack() as stack:
                self._patch_source(stack, root, secrets, container_secrets, data, socket, dump_path, manifest_path,
                                   container, branch="main")
                with self.assertRaisesRegex(ProvisioningError, "v10e_git_provenance_mismatch"):
                    validate_v10e_clone("b" * 40, "5.7.44-log", inode, expected_uid=os.getuid())
            bad_container = {**container, "HostConfig": {**container["HostConfig"], "NetworkMode": "bridge"}}
            with ExitStack() as stack:
                self._patch_source(stack, root, secrets, container_secrets, data, socket, dump_path,
                                   manifest_path, bad_container)
                with self.assertRaisesRegex(ProvisioningError, "v10e_container_isolation_mismatch"):
                    validate_v10e_clone("b" * 40, "5.7.44-log", inode, expected_uid=os.getuid())

    def test_missing_password_is_rejected_without_connection(self):
        with self.assertRaisesRegex(ProvisioningError, "reader_config_password_missing"):
            validate_reader_options({"user": READER_USER, "host": READER_HOST, "port": "3306"})

    def test_authentication_error_is_sanitized(self):
        def fail(_config):
            raise RuntimeError("secret-password and private SQL")

        with self.assertRaises(ProvisioningError) as caught:
            validate_reader_connection("/private/reader.cnf", connector=fail)
        self.assertEqual(str(caught.exception), "reader_authentication_or_catalog_failed")
        self.assertNotIn("secret-password", str(caught.exception))
        self.assertNotIn("private SQL", str(caught.exception))

    def test_official_helper_identity_flags_and_catalog_probe_pass(self):
        conn = MagicMock()
        cursor = conn.cursor.return_value.__enter__.return_value
        cursor.fetchone.side_effect = [
            ("pgloader@127.0.0.1", 1, 1, "OFF"),
            (270,),
        ]
        self.assertEqual(validate_reader_connection("/private/reader.cnf", connector=lambda _: conn), 270)
        self.assertEqual(cursor.execute.call_args_list[0].args[0],
                         "SELECT CURRENT_USER(),@@global.read_only,@@global.super_read_only,@@global.event_scheduler")
        self.assertIn("information_schema.TABLES", cursor.execute.call_args_list[1].args[0])
        self.assertEqual(cursor.execute.call_args_list[1].args[1], ("inlaud99_erpcondor",))
        conn.rollback.assert_called_once()
        conn.close.assert_called_once()

    def test_uppercase_effective_identity_is_rejected_before_catalog_probe(self):
        conn = MagicMock()
        cursor = conn.cursor.return_value.__enter__.return_value
        cursor.fetchone.return_value = ("PGLOADER@127.0.0.1", 1, 1, "OFF")
        with self.assertRaisesRegex(ProvisioningError, "reader_identity_or_flags_invalid"):
            validate_reader_connection("/private/reader.cnf", connector=lambda _: conn)
        self.assertEqual(cursor.execute.call_count, 1)
        conn.close.assert_called_once()

    def test_insufficient_catalog_table_count_fails_closed(self):
        conn = MagicMock()
        cursor = conn.cursor.return_value.__enter__.return_value
        cursor.fetchone.side_effect = [("pgloader@127.0.0.1", 1, 1, "OFF"), (269,)]
        with self.assertRaisesRegex(ProvisioningError, "source_catalog_probe_failed"):
            validate_reader_connection("/private/reader.cnf", connector=lambda _: conn)
        conn.close.assert_called_once()

    def test_initial_source_not_frozen_is_rejected_without_user_mutation(self):
        conn, cursor = self.make_frozen_source()
        cursor.read_only = 0
        with self.assertRaisesRegex(ProvisioningError, "source_read_only_guard_failed"):
            _provision_account(conn, "synthetic-password")
        self.assertFalse(any(sql.startswith("CREATE USER") or sql.startswith("ALTER USER")
                             for sql in cursor.statements))

    def test_nonlocal_admin_identity_is_rejected_before_user_mutation(self):
        conn, cursor = self.make_frozen_source()
        cursor.admin_identity = "root@%"
        with self.assertRaisesRegex(ProvisioningError, "admin_identity_invalid"):
            _provision_account(conn, "synthetic-password")
        self.assertFalse(any(sql.startswith("CREATE USER") or sql.startswith("ALTER USER")
                             for sql in cursor.statements))

    def test_source_version_must_match_the_versioned_restore_manifest(self):
        conn, cursor = self.make_frozen_source()
        with self.assertRaisesRegex(ProvisioningError, "source_runtime_mismatch"):
            _provision_account(conn, "synthetic-password", expected_version="5.7.43-log")
        self.assertFalse(any(sql.startswith("CREATE USER") or sql.startswith("ALTER USER")
                             for sql in cursor.statements))

    def test_provisioning_failure_restores_both_read_only_flags(self):
        conn, cursor = self.make_frozen_source(fail_on="GRANT SELECT")
        events = []
        with self.assertRaisesRegex(ProvisioningError, "reader_account_provision_failed"):
            _provision_account(conn, "synthetic-password", audit_callback=events.append)
        self.assertEqual(cursor.read_only, 1)
        self.assertEqual(cursor.super_read_only, 1)
        self.assertEqual(events, ["super_read_only_off_applied", "read_only_off_applied",
                                  "read_only_restored_verified"])

    def test_failed_read_only_restore_is_reported_and_not_hidden(self):
        conn, cursor = self.make_frozen_source(fail_on="SET GLOBAL read_only=ON")
        events = []
        with self.assertRaisesRegex(ProvisioningError, "source_freeze_restore_failed"):
            _provision_account(conn, "synthetic-password", audit_callback=events.append)
        self.assertEqual(cursor.super_read_only, 1)
        self.assertEqual(events[-1], "read_only_restore_failed")

    def test_sigterm_during_mutation_or_freeze_restore_restores_both_flags(self):
        for operation in ("SET GLOBAL super_read_only=OFF", "CREATE USER", "GRANT SELECT",
                          "SET GLOBAL read_only=ON"):
            with self.subTest(operation=operation), patch("mysql_source.INTERRUPTED", False):
                conn, cursor = self.make_frozen_source(interrupt_on=operation)
                with self.assertRaisesRegex(ProvisioningError, "interrupted"):
                    _provision_account(conn, "synthetic-password")
                self.assertEqual(cursor.read_only, 1)
                self.assertEqual(cursor.super_read_only, 1)

    def test_catalog_or_authentication_exceptions_do_not_expose_sql_or_secret(self):
        def fail(_config):
            raise RuntimeError("GRANT SQL contains synthetic-password")

        with self.assertRaises(ProvisioningError) as caught:
            validate_reader_connection("/private/reader.cnf", connector=fail)
        self.assertEqual(str(caught.exception), "reader_authentication_or_catalog_failed")
        self.assertNotIn("synthetic-password", str(caught.exception))


if __name__ == "__main__":
    unittest.main()
