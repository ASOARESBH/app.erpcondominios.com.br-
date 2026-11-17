from __future__ import annotations

from contextlib import contextmanager, ExitStack
from datetime import date, datetime
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

from tools.migracao_pg import loader
from tools.migracao_pg.loader import LoaderError, _MemoryGuard, _row_size_bytes, _sqlstate, _validate_row_size, normalize_mysql_value, validate_bundle


class NormalizeMysqlValueTests(unittest.TestCase):
    def test_null_stays_null(self) -> None:
        self.assertIsNone(normalize_mysql_value(None, {"data_type": "varchar", "column_type": "varchar(20)"}))

    def test_binary_values_are_preserved_byte_for_byte(self) -> None:
        raw = b"\x00\xffpayload"
        self.assertEqual(normalize_mysql_value(raw, {"data_type": "longblob", "column_type": "longblob"}), raw)
        self.assertEqual(normalize_mysql_value(raw, {"data_type": "varbinary", "column_type": "varbinary(32)"}), raw)

    def test_text_nul_fails_closed(self) -> None:
        with self.assertRaisesRegex(LoaderError, "SOURCE_TEXT_NUL_POLICY_REQUIRED"):
            normalize_mysql_value("prefix\x00suffix", {"data_type": "varchar", "column_type": "varchar(30)"})

    def test_json_nul_fails_closed_even_when_escaped(self) -> None:
        with self.assertRaisesRegex(LoaderError, "SOURCE_JSON_NUL_POLICY_REQUIRED"):
            normalize_mysql_value('{"label":"\\u0000"}', {"data_type": "json", "column_type": "json"})

    def test_json_must_parse(self) -> None:
        with self.assertRaisesRegex(LoaderError, "SOURCE_JSON_INVALID"):
            normalize_mysql_value("{invalid", {"data_type": "json", "column_type": "json"})

    def test_zero_date_fails_instead_of_silent_null(self) -> None:
        with self.assertRaisesRegex(LoaderError, "MYSQL_ZERO_DATE_REQUIRES_APPROVAL"):
            normalize_mysql_value(b"0000-00-00", {"data_type": "date", "column_type": "date"})

    def test_date_bytes_are_parsed_after_raw_fetch(self) -> None:
        self.assertEqual(normalize_mysql_value(b"2026-11-16", {"data_type": "date", "column_type": "date"}), date(2026, 11, 16))

    def test_datetime_stays_civil_time(self) -> None:
        value = normalize_mysql_value(b"2026-11-16 09:25:10", {"data_type": "datetime", "column_type": "datetime"})
        self.assertEqual(value, datetime(2026, 11, 16, 9, 25, 10))
        self.assertIsNone(value.tzinfo)

    def test_timestamp_uses_approved_zone_conversion(self) -> None:
        value = normalize_mysql_value(b"2026-11-16 12:00:00", {"data_type": "timestamp", "column_type": "timestamp"})
        self.assertEqual(value, datetime(2026, 11, 16, 9, 0, 0))

    def test_fractional_temporal_value_requires_review(self) -> None:
        with self.assertRaisesRegex(LoaderError, "FRACTIONAL_SECONDS_REQUIRE_APPROVAL"):
            normalize_mysql_value(b"2026-11-16 12:00:00.125000", {"data_type": "datetime", "column_type": "datetime(6)"})

    def test_time_range_requires_review(self) -> None:
        with self.assertRaisesRegex(LoaderError, "MYSQL_TIME_OUT_OF_RANGE_FOR_POSTGRES_TIME"):
            normalize_mysql_value(b"-01:00:00", {"data_type": "time", "column_type": "time"})

    def test_bit_width_is_preserved(self) -> None:
        self.assertEqual(normalize_mysql_value(b"\x05", {"data_type": "bit", "column_type": "bit(3)"}), "101")

    def test_enum_must_match_declared_label(self) -> None:
        meta = {"data_type": "enum", "column_type": "enum('A','B')"}
        self.assertEqual(normalize_mysql_value("A", meta), "A")
        with self.assertRaisesRegex(LoaderError, "ENUM_VALUE_OUTSIDE_DECLARED_LABELS"):
            normalize_mysql_value("C", meta)

    def test_text_bytes_require_valid_utf8_charset(self) -> None:
        meta = {"data_type": "varchar", "column_type": "varchar(20)", "character_set": "utf8mb4"}
        self.assertEqual(normalize_mysql_value("olá".encode(), meta), "olá")
        with self.assertRaisesRegex(LoaderError, "SOURCE_TEXT_INVALID_UTF8"):
            normalize_mysql_value(b"\xff", meta)
        with self.assertRaisesRegex(LoaderError, "NON_TEXT_BINARY_VALUE_REQUIRES_REVIEW"):
            normalize_mysql_value(b"\xff", {"data_type": "varchar", "column_type": "varchar(20)", "character_set": "latin1"})


class BundleValidationTests(unittest.TestCase):
    def test_real_versioned_bundle_is_blocked_when_collector_code_is_stale(self) -> None:
        candidate = next(loader.BUNDLE_ROOT.glob("catalog-*-code-*/report.json"))
        bundle_name = candidate.parent.name
        with self.assertRaisesRegex(LoaderError, "BUNDLE_COLLECTOR_CODE_MISMATCH"):
            validate_bundle(bundle_name, require_executable=False)

    def test_bundle_name_is_allowlisted(self) -> None:
        for invalid in ("../catalog-x", "erp_pg_dev", "catalog-../../outside"):
            with self.subTest(invalid=invalid), self.assertRaises(LoaderError):
                validate_bundle(invalid, require_executable=False)


class SourceConnectionSetupTests(unittest.TestCase):
    def test_catalog_connection_uses_dict_rows_and_validates_identity_with_tuple_cursor(self) -> None:
        import pymysql

        class Cursor:
            def __init__(self) -> None:
                self.statement = None

            def execute(self, statement) -> None:
                self.statement = statement

            def fetchone(self):
                return ("pg_reader@localhost", "erp_pg_source", "8.4.13")

            def close(self) -> None:
                pass

            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                self.close()

        class Connection:
            def __init__(self) -> None:
                self.cursor_classes = []
                self.statements = []
                self.closed = False

            def cursor(self, cursor_class=None):
                self.cursor_classes.append(cursor_class)
                return Cursor()

            def close(self) -> None:
                self.closed = True

        connection = Connection()
        captured = {}

        def connect(**kwargs):
            captured.update(kwargs)
            return connection

        snapshot = {"source_schema": "erp_pg_source"}
        with patch.object(pymysql, "connect", side_effect=connect), \
             patch.object(loader, "_mysql_socket_options", return_value={"unix_socket": "/run/mysqld/mysqld.sock"}), \
             patch.object(loader, "collect_catalog", return_value=snapshot) as collect:
            actual_connection, actual_snapshot = loader._connect_source(Path("/root-only/source.cnf"))

        self.assertIs(actual_connection, connection)
        self.assertEqual(actual_snapshot, snapshot)
        self.assertIs(captured["cursorclass"], pymysql.cursors.DictCursor)
        self.assertTrue(captured["autocommit"])
        self.assertEqual(connection.cursor_classes, [pymysql.cursors.Cursor])
        collect.assert_called_once_with(connection, keep_snapshot=True)


class RebuildRunnerSecurityTests(unittest.TestCase):
    def test_root_runner_is_confined_to_immutable_revisioned_release(self) -> None:
        script = Path("tools/migracao_pg/rebuild.sh").read_text(encoding="utf-8")
        self.assertIn("ROOT_EXECUTION_REQUIRES_IMMUTABLE_RELEASE", script)
        self.assertIn("RELEASE_PATH_NOT_ROOT_CONTROLLED", script)
        self.assertIn('revision_file="$repo_root/REVISION"', script)
        self.assertIn("PYTHON_SOURCE_NOT_ROOT_CONTROLLED", script)
        self.assertNotIn("ERP_P2_PYTHON_BIN", script)
        self.assertIn('"$python_bin" -E -B -m tools.migracao_pg.loader', script)
        self.assertIn("#!/usr/bin/env -S -i PATH=", script)
        self.assertIn("/usr/bin/env -i", script)
        self.assertIn("RELEASE-MANIFEST.sha256", script)
        self.assertIn("sha256sum --check --strict --status", script)
        self.assertIn("RELEASE_MANIFEST_COVERAGE_MISMATCH", script)
        self.assertIn("RELEASE_MANIFEST_FORMAT_INVALID", script)
        self.assertIn("RELEASE_MANIFEST_DUPLICATE_PATH", script)
        self.assertIn("RELEASE_PATH_CONTROL_CHARACTER", script)
        self.assertIn("sort -z", script)
        self.assertIn("RELEASE_ENUMERATION_FAILED", script)
        self.assertIn("PINNED_PYTHON_ENUMERATION_FAILED", script)
        self.assertIn('if ! /usr/bin/find "$repo_root" -type f ! -path "$manifest_file" -printf \'%P\\0\' | /usr/bin/sort -z', script)
        self.assertIn("RELEASE_SOURCE_SYMLINK_FOUND", script)
        self.assertIn("PINNED_PYTHON_SYMLINK_FOUND", script)

    def test_direct_root_import_is_blocked_before_local_pipeline_imports(self) -> None:
        package_init = Path("tools/migracao_pg/__init__.py").read_text(encoding="utf-8")
        loader_source = Path("tools/migracao_pg/loader.py").read_text(encoding="utf-8")
        self.assertIn("P2_PACKAGE_ROOT_IMPORT_REQUIRES_REBUILD_RUNNER", package_init)
        self.assertIn("LOADER_ROOT_EXECUTION_REQUIRES_REBUILD_RUNNER", loader_source)
        guard = loader_source.index("LOADER_ROOT_EXECUTION_REQUIRES_REBUILD_RUNNER")
        local_import = loader_source.index("from .catalog import")
        self.assertLess(guard, local_import)

    def test_launcher_discards_bash_env_before_script_execution(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            marker = root / "bash-env-was-sourced"
            bash_env = root / "bash_env"
            bash_env.write_text(f"/usr/bin/touch {marker}\n", encoding="utf-8")
            result = subprocess.run(
                ["tools/migracao_pg/rebuild.sh", "development", "preflight", "invalid", "/tmp/source.cnf"],
                cwd=Path.cwd(),
                env={"BASH_ENV": str(bash_env), "PATH": "/tmp/untrusted"},
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(marker.exists())

    def test_direct_root_package_import_fails_closed(self) -> None:
        result = subprocess.run(
            [sys.executable, "-c", "import os; os.geteuid = lambda: 0; import tools.migracao_pg"],
            cwd=Path.cwd(),
            env={"PATH": "/usr/bin:/bin"},
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("P2_PACKAGE_ROOT_IMPORT_REQUIRES_REBUILD_RUNNER", result.stderr)


class MemoryGuardTests(unittest.TestCase):
    def test_memory_recovers_before_timeout(self) -> None:
        guard = _MemoryGuard()
        with patch.object(loader, "_read_available_memory_mib", side_effect=[1400, 1450, 1700, 1400]):
            with patch.object(loader.time, "monotonic", side_effect=[10.0, 200.0, 301.0, 400.0]):
                self.assertEqual(guard.sample(), 1400)
                self.assertEqual(guard.sample(), 1450)
                self.assertEqual(guard.sample(), 1700)
                self.assertEqual(guard.sample(), 1400)

    def test_memory_low_for_five_minutes_fails_closed(self) -> None:
        guard = _MemoryGuard()
        with patch.object(loader, "_read_available_memory_mib", return_value=1400):
            with patch.object(loader.time, "monotonic", side_effect=[1.0, 300.0, 301.1]):
                guard.sample()
                guard.sample()
                with self.assertRaisesRegex(LoaderError, "MEMORY_BELOW_1536_MIB_FOR_5_MINUTES"):
                    guard.sample()

    def test_memory_is_sampled_when_time_interval_elapses(self) -> None:
        guard = _MemoryGuard()
        with patch.object(loader, "_read_available_memory_mib", side_effect=[1400, 1400]):
            with patch.object(loader.time, "monotonic", side_effect=[1.0, 5.0, 12.0, 12.1]):
                self.assertEqual(guard.sample(), 1400)
                self.assertIsNone(guard.sample_if_due())
                self.assertEqual(guard.sample_if_due(), 1400)

    def test_row_size_calculation_counts_raw_bytes(self) -> None:
        self.assertEqual(_row_size_bytes(["abc", b"\x00\xff", None]), 5)

    def test_row_size_limit_rejects_only_oversized_synthetic_rows(self) -> None:
        with patch.object(loader, "MAX_ROW_BYTES", 4):
            _validate_row_size([b"1234"])
            with self.assertRaisesRegex(LoaderError, "SOURCE_ROW_EXCEEDS_128_MIB"):
                _validate_row_size([b"12345"])

    def test_background_monitor_cancels_long_running_postgres_operation(self) -> None:
        class CancelableConnection:
            def __init__(self) -> None:
                self.cancelled = False

            def cancel_safe(self, *, timeout):
                self.cancelled = timeout == 5

        guard = _MemoryGuard()
        connection = CancelableConnection()
        with patch.object(loader, "MEMORY_SAMPLE_INTERVAL_SECONDS", 0.01), \
             patch.object(loader, "_read_available_memory_mib", return_value=1400), \
             patch.object(loader.time, "monotonic", side_effect=[1.0, 302.0]):
            guard.start_monitor(connection)
            guard._monitor_thread.join(timeout=2)
            self.assertFalse(guard._monitor_thread.is_alive())
            self.assertTrue(connection.cancelled)
            with self.assertRaisesRegex(LoaderError, "MEMORY_BELOW_1536_MIB_FOR_5_MINUTES"):
                guard._raise_if_stopped()
            guard.stop_monitor()


class SanitizedDiagnosticsTests(unittest.TestCase):
    def test_only_valid_sqlstate_is_retained(self) -> None:
        self.assertEqual(_sqlstate(type("DbError", (), {"sqlstate": "23505"})()), "23505")
        self.assertIsNone(_sqlstate(type("DbError", (), {"sqlstate": "password=secret"})()))

    def test_loader_error_never_contains_sqlstate_or_database_text(self) -> None:
        error = LoaderError("POSTGRES_REBUILD_TRANSACTION_FAILED", "23505")
        self.assertEqual(str(error), "POSTGRES_REBUILD_TRANSACTION_FAILED")
        self.assertEqual(error.sqlstate, "23505")
        self.assertIsNone(LoaderError("SAFE_CODE", "raw database error").sqlstate)


class _FakeCursor:
    def __init__(self, connection) -> None:
        self.connection = connection
        self.row = None

    def execute(self, query, params=None) -> None:
        text = str(query)
        self.connection.statements.append((text, self.connection.transaction_active))
        if "current_database()" in text:
            self.row = ("erp_pg_dev",)

    def fetchone(self):
        return self.row

    def close(self) -> None:
        pass

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb):
        self.close()


class _FakeTarget:
    def __init__(self) -> None:
        self.autocommit = True
        self.statements = []
        self.transaction_active = False
        self.commits = 0
        self.rollbacks = 0
        self.closed = False
        self.fail_rollback = False
        self.fail_close = False

    def cursor(self):
        return _FakeCursor(self)

    @contextmanager
    def transaction(self):
        self.transaction_active = True
        try:
            yield
        except Exception:
            self.rollbacks += 1
            raise
        else:
            self.commits += 1
        finally:
            self.transaction_active = False

    def rollback(self) -> None:
        self.rollbacks += 1
        if self.fail_rollback:
            raise RuntimeError("synthetic rollback failure")

    def close(self) -> None:
        self.closed = True
        if self.fail_close:
            raise RuntimeError("synthetic target close failure")


class _FakeSource:
    def __init__(self) -> None:
        self.rollbacks = 0
        self.closed = False
        self.snapshot_active = True
        self.fail_close = False

    def rollback(self) -> None:
        self.rollbacks += 1
        self.snapshot_active = False

    def close(self) -> None:
        self.closed = True
        if self.fail_close:
            raise RuntimeError("synthetic close failure")


class LoadTransactionTests(unittest.TestCase):
    def _call_load(self, copy_side_effect=None):
        source = _FakeSource()
        target = _FakeTarget()
        captured_reports = []
        role = "erp_pg_dev_app"
        config = {"user": role}
        report = {
            "application_role_sha256": hashlib.sha256(role.encode()).hexdigest(),
            "generated_tables": ["synthetic_table"],
            "generated_scripts_sha256": {name: "0" * 64 for name in loader.SCRIPT_NAMES},
            "catalog_fingerprint_sha256": "f" * 64,
            "generator_version": "test",
        }
        snapshot = {
            "source_schema": "erp_pg_source",
            "source_grants_read_only": True,
            "structural_fingerprint_sha256": "a" * 64,
            "tables": [{"name": "synthetic_table", "type": "BASE TABLE"}],
            "source_no_backslash_escapes": False,
        }
        patches = ExitStack()
        patches.enter_context(patch.object(loader, "_require_load_runtime"))
        patches.enter_context(patch.object(loader, "validate_bundle", return_value=(Path("/tmp/bundle"), report, snapshot)))
        patches.enter_context(patch.object(loader, "_source_defaults_path", return_value=Path("/tmp/source.cnf")))
        patches.enter_context(patch.object(loader, "_load_target_config", return_value=config))
        patches.enter_context(patch.object(loader, "_validate_column_mappings", return_value={"synthetic_table": {}}))
        patches.enter_context(patch.object(loader, "_connect_source", return_value=(source, snapshot)))
        patches.enter_context(patch.object(loader, "_connect_target", return_value=target))
        patches.enter_context(patch.object(loader, "_target_preflight", return_value={"database_matches": True}))
        def execute_script(connection, script_path, expected_sha256):
            if not connection.transaction_active:
                raise AssertionError("bundle SQL executed outside transaction")
            connection.statements.append((f"SCRIPT:{script_path.name}", True))

        def copy_table(*args):
            if not args[0].snapshot_active:
                raise AssertionError("COPY executed without the source snapshot")
            if not args[1].transaction_active:
                raise AssertionError("COPY executed outside transaction")
            return (copy_side_effect or (lambda *call_args: 2))(*args)

        patches.enter_context(patch.object(loader, "_execute_bundle_script", side_effect=execute_script))
        patches.enter_context(patch.object(loader, "_copy_table", side_effect=copy_table))
        patches.enter_context(patch.object(loader, "_row_count", side_effect=lambda *args: 2))
        patches.enter_context(patch.object(loader, "_read_available_memory_mib", return_value=4096))
        patches.enter_context(patch.object(loader, "_write_report_exclusive", side_effect=lambda payload, path: captured_reports.append(dict(payload))))
        patches.enter_context(patch.object(loader, "_replace_report", side_effect=lambda payload, path: captured_reports.append(dict(payload))))
        return patches, source, target, captured_reports

    def test_success_commits_only_allowlisted_schema_and_waits_for_reconciliation(self) -> None:
        patches, source, target, reports = self._call_load()
        try:
            result = loader.run_load("synthetic-bundle", report_path=Path("/tmp/report.json"))
        finally:
            patches.close()
        self.assertEqual(result["status"], "LOAD_COMMITTED_PENDING_RECONCILIATION")
        self.assertEqual(result["tables_loaded"], 1)
        self.assertEqual(result["rows_loaded"], 2)
        self.assertEqual(target.commits, 1)
        self.assertTrue(any("Identifier('erp')" in item and active for item, active in target.statements))
        self.assertTrue(all("DROP DATABASE" not in item.upper() for item, _ in target.statements))
        self.assertTrue(all(active for item, active in target.statements if "DROP SCHEMA" in item or item.startswith("SCRIPT:")))
        self.assertEqual(sum(item.startswith("SCRIPT:") for item, _ in target.statements), len(loader.SCRIPT_NAMES))
        self.assertEqual(reports[0]["status"], "STARTED")
        self.assertEqual(reports[-1]["status"], "LOAD_COMMITTED_PENDING_RECONCILIATION")
        self.assertTrue(source.closed)
        self.assertGreaterEqual(source.rollbacks, 1)
        self.assertFalse(source.snapshot_active)
        self.assertTrue(target.closed)

    def test_copy_error_rolls_back_entire_target_transaction(self) -> None:
        def fail_copy(*args):
            raise LoaderError("SYNTHETIC_COPY_FAILURE")

        patches, source, target, reports = self._call_load(copy_side_effect=fail_copy)
        try:
            with self.assertRaisesRegex(LoaderError, "SYNTHETIC_COPY_FAILURE"):
                loader.run_load("synthetic-bundle", report_path=Path("/tmp/report.json"))
        finally:
            patches.close()
        self.assertEqual(target.commits, 0)
        self.assertGreaterEqual(target.rollbacks, 1)
        self.assertEqual(reports[-1]["status"], "FAILED_ROLLED_BACK")
        self.assertEqual(reports[-1]["failure_code"], "SYNTHETIC_COPY_FAILURE")
        self.assertTrue(source.closed)
        self.assertGreaterEqual(source.rollbacks, 1)
        self.assertFalse(source.snapshot_active)
        self.assertTrue(target.closed)

    def test_rollback_failure_is_written_as_unconfirmed(self) -> None:
        def fail_copy(*args):
            raise LoaderError("SYNTHETIC_COPY_FAILURE")

        patches, source, target, reports = self._call_load(copy_side_effect=fail_copy)
        target.fail_rollback = True
        try:
            with self.assertRaisesRegex(LoaderError, "POSTGRES_ROLLBACK_UNCONFIRMED"):
                loader.run_load("synthetic-bundle", report_path=Path("/tmp/report.json"))
        finally:
            patches.close()
        self.assertEqual(reports[-1]["status"], "FAILED_ROLLBACK_UNCONFIRMED")
        self.assertEqual(reports[-1]["failure_code"], "POSTGRES_ROLLBACK_UNCONFIRMED")
        self.assertTrue(source.closed)
        self.assertTrue(target.closed)

    def test_source_close_failure_after_commit_does_not_mask_pending_reconciliation(self) -> None:
        patches, source, target, reports = self._call_load()
        source.fail_close = True
        try:
            result = loader.run_load("synthetic-bundle", report_path=Path("/tmp/report.json"))
        finally:
            patches.close()
        self.assertEqual(result["status"], "LOAD_COMMITTED_PENDING_RECONCILIATION")
        self.assertFalse(result["source_cleanup_confirmed"])
        self.assertEqual(reports[-1]["status"], "LOAD_COMMITTED_PENDING_RECONCILIATION")
        self.assertTrue(target.closed)

    def test_target_close_failure_is_reported_without_masking_commit(self) -> None:
        patches, source, target, reports = self._call_load()
        target.fail_close = True
        try:
            result = loader.run_load("synthetic-bundle", report_path=Path("/tmp/report.json"))
        finally:
            patches.close()
        self.assertEqual(result["status"], "LOAD_COMMITTED_PENDING_RECONCILIATION")
        self.assertFalse(result["target_cleanup_confirmed"])
        self.assertEqual(reports[-1]["status"], "LOAD_COMMITTED_PENDING_RECONCILIATION")


if __name__ == "__main__":
    unittest.main()
