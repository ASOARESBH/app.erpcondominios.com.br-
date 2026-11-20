from __future__ import annotations

from contextlib import contextmanager, ExitStack, redirect_stderr
from datetime import date, datetime, timedelta
import base64
import hashlib
import io
import json
from pathlib import Path
import subprocess
import sys
import stat
import tempfile
import unittest
from unittest.mock import patch

from tools.migracao_pg import loader
from tools.migracao_pg.loader import LoaderError, _MemoryGuard, _row_size_bytes, _sqlstate, _validate_column_mappings, _validate_row_size, normalize_mysql_value, validate_bundle
from tools.migracao_pg.reconcile import canonical_row_sha256


class TargetConfigTests(unittest.TestCase):
    def setUp(self) -> None:
        self.config = {
            "host": "127.0.0.1",
            "port": "5432",
            "dbname": "erp_pg_dev",
            "user": "erp_p2_loader",
            "password": "synthetic-only-secret",
            "schema": "erp",
            "timezone": "UTC",
            "application_user": "erp_app",
        }

    def test_requires_local_target_and_separate_roles(self) -> None:
        validated = loader._validate_target_config(dict(self.config))
        self.assertEqual(validated["port"], 5432)
        for key, value, code in (
            ("host", "203.0.113.5", "TARGET_HOST_MUST_BE_LOCAL"),
            ("dbname", "erpcondominios", "TARGET_CONFIG_SCOPE_MISMATCH"),
            ("user", "erp_app", "TARGET_ROLE_SEPARATION_REQUIRED"),
            ("application_user", "erp_p2_loader", "TARGET_ROLE_SEPARATION_REQUIRED"),
            ("timezone", "America/Sao_Paulo", "TARGET_TIMEZONE_MISMATCH"),
        ):
            candidate = dict(self.config)
            candidate[key] = value
            with self.subTest(key=key), self.assertRaisesRegex(LoaderError, code):
                loader._validate_target_config(candidate)

    def test_cutover_target_profile_rejects_development_and_mysql_names(self) -> None:
        candidate = dict(
            self.config,
            dbname=loader.CUTOVER_TARGET_DATABASE,
            user=loader.CUTOVER_MIGRATION_ROLE,
            application_user=loader.CUTOVER_APPLICATION_ROLE,
        )
        self.assertEqual(loader._validate_target_config(candidate, "cutover")["dbname"], loader.CUTOVER_TARGET_DATABASE)
        for name in (
            "erp_pg_dev", "erp_pg_source", "erp_hml_mysql",
            "erpcondominios", "erpcondominios_stage_20261114", "postgres", "erp_pg_cutover_synthetic",
        ):
            with self.subTest(dbname=name), self.assertRaisesRegex(LoaderError, "TARGET_CONFIG_SCOPE_MISMATCH"):
                loader._validate_target_config(dict(candidate, dbname=name), "cutover")
        for key, value in (("user", "erp_p2_loader"), ("application_user", "erp_app")):
            with self.subTest(key=key), self.assertRaisesRegex(LoaderError, "TARGET_ROLE_SEPARATION_REQUIRED"):
                loader._validate_target_config(dict(candidate, **{key: value}), "cutover")
        with self.assertRaisesRegex(LoaderError, "TARGET_PROFILE_NOT_ALLOWLISTED"):
            loader._validate_target_config(candidate, "unknown")


    def test_cutover_execution_marker_is_root_only_and_one_shot(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            marker = Path(directory) / "cutover.started"
            with patch.object(loader, "CUTOVER_TARGET_EXECUTION_MARKER", marker), \
                 patch.object(loader.os, "geteuid", return_value=0):
                loader._consume_cutover_execution_marker("erp_pg_cutover_synthetic")
                self.assertEqual(marker.read_text(encoding="ascii"), "erp_pg_cutover_synthetic\n")
                self.assertEqual(stat.S_IMODE(marker.stat().st_mode), 0o600)
                with self.assertRaisesRegex(LoaderError, "CUTOVER_EXECUTION_ALREADY_CONSUMED"):
                    loader._consume_cutover_execution_marker("erp_pg_cutover_synthetic")

    def test_cutover_execution_marker_requires_root(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            marker = Path(directory) / "cutover.started"
            with patch.object(loader, "CUTOVER_TARGET_EXECUTION_MARKER", marker), \
                 patch.object(loader.os, "geteuid", return_value=1000):
                with self.assertRaisesRegex(LoaderError, "CUTOVER_EXECUTION_MARKER_ROOT_REQUIRED"):
                    loader._consume_cutover_execution_marker("erp_pg_cutover_synthetic")
            self.assertFalse(marker.exists())


class SourceCountResultTests(unittest.TestCase):
    def test_accepts_dict_cursor_and_tuple_cursor_counts(self) -> None:
        self.assertEqual(loader._source_count_value({"row_count": 0}), 0)
        self.assertEqual(loader._source_count_value({"row_count": 17}), 17)
        self.assertEqual(loader._source_count_value((17,)), 17)

    def test_rejects_invalid_count_shapes_and_values(self) -> None:
        for row in (None, {}, {"row_count": True}, {"row_count": "1"}, {"row_count": -1}, (), (True,), (1, 2)):
            with self.subTest(row_type=type(row).__name__), self.assertRaisesRegex(LoaderError, "SOURCE_ROW_COUNT_INVALID"):
                loader._source_count_value(row)


class SourceSnapshotProofTests(unittest.TestCase):
    def setUp(self) -> None:
        self.snapshot = {
            "structural_fingerprint_sha256": "a" * 64,
            "source_schema": loader.SOURCE_SCHEMA,
            "binary_storage_required": True,
            "source_grant_policy_id": "erp_pg_source_select_show_view_trigger_v2",
            "source_grants_policy_accepted": True,
            "source_routines_events_zero_verified": True,
            "source_schema_wide_select": True,
            "metadata_visibility": {"views": True, "triggers": True, "routines": False, "events": False},
            "routines": [],
            "events": [],
            "catalog_consistency_check": "PRE_POST_SIGNATURE_MATCH",
            "timestamp_transfer_contract": {"source_session": "UTC", "target_wall_time": "America/Sao_Paulo"},
        }

    def test_accepts_live_snapshot_with_complete_proofs(self) -> None:
        loader._validate_source_snapshot(dict(self.snapshot), dict(self.snapshot))

    def test_rejects_missing_or_changed_live_proofs(self) -> None:
        cases = (
            ("source_grants_policy_accepted", False, "SOURCE_CATALOG_PROOF_INVALID"),
            ("source_grant_policy_id", "unknown", "SOURCE_CATALOG_PROOF_INVALID"),
            ("source_routines_events_zero_verified", False, "SOURCE_CATALOG_PROOF_INVALID"),
            ("source_schema_wide_select", False, "SOURCE_SCHEMA_SELECT_PROOF_INVALID"),
            ("catalog_consistency_check", None, "SOURCE_CATALOG_CONSISTENCY_UNPROVEN"),
            ("timestamp_transfer_contract", {"source_session": "local", "target_wall_time": "UTC"}, "SOURCE_TIMESTAMP_TRANSFER_CONTRACT_INVALID"),
        )
        for key, value, code in cases:
            current = dict(self.snapshot)
            current[key] = value
            with self.subTest(key=key), self.assertRaisesRegex(LoaderError, code):
                loader._validate_source_snapshot(current, self.snapshot)

    def test_rejects_incomplete_metadata_visibility(self) -> None:
        current = dict(self.snapshot)
        current["metadata_visibility"] = dict(self.snapshot["metadata_visibility"], views=False)
        with self.assertRaisesRegex(LoaderError, "SOURCE_METADATA_VISIBILITY_UNPROVEN"):
            loader._validate_source_snapshot(current, self.snapshot)


class IdentityPlanValidationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.snapshot = {"tables": [{"name": "synthetic", "columns": [
            {"name": "id", "extra": "auto_increment"}, {"name": "label", "extra": ""},
        ], "source_next_auto_increment": 2}]}
        self.mappings = {"synthetic": {"target_schema": "erp", "types": {"id": object(), "label": object()}}}
        self.valid = [{
            "table": "synthetic", "target_schema": "erp", "column": "id", "strategy": "identity",
            "source_next_auto_increment": 2,
        }]

    def test_accepts_exact_identity_plan(self) -> None:
        result = loader._validated_identity_plan(self.snapshot, ["synthetic"], self.mappings, self.valid)
        self.assertEqual(result["synthetic"][0]["column"], "id")
        self.assertEqual(result["synthetic"][0]["strategy"], "identity")

    def test_rejects_allocator_that_differs_from_snapshot(self) -> None:
        item = dict(self.valid[0], source_next_auto_increment=999)
        with self.assertRaisesRegex(LoaderError, "BUNDLE_IDENTITY_PLAN_INVALID"):
            loader._validated_identity_plan(self.snapshot, ["synthetic"], self.mappings, [item])

    def test_run_load_rejects_bad_allocator_before_report_or_connection(self) -> None:
        report = {
            "application_role_sha256": hashlib.sha256(b"erp_app").hexdigest(),
            "generated_tables": ["synthetic"],
            "auto_increment": {"columns": [dict(self.valid[0], source_next_auto_increment=999)]},
        }
        with patch.object(loader, "validate_bundle", return_value=(None, report, self.snapshot)), \
             patch.object(loader, "_require_load_runtime"), \
             patch.object(loader, "_source_defaults_path", return_value=Path("/tmp/synthetic-source.cnf")), \
             patch.object(loader, "_load_target_config", return_value={"application_user": "erp_app"}), \
             patch.object(loader, "_validate_column_mappings", return_value=self.mappings), \
             patch.object(loader, "_write_report_exclusive") as write_report, \
             patch.object(loader, "_connect_source") as connect_source:
            with self.assertRaisesRegex(LoaderError, "BUNDLE_IDENTITY_PLAN_INVALID"):
                loader.run_load("synthetic", report_path=Path("/tmp/synthetic-load-report.json"))
        write_report.assert_not_called()
        connect_source.assert_not_called()

    def test_rejects_ghost_table_and_column(self) -> None:
        for table, column in (("ghost", "id"), ("synthetic", "ghost")):
            item = dict(self.valid[0], table=table, column=column)
            with self.subTest(table=table, column=column), self.assertRaisesRegex(LoaderError, "BUNDLE_IDENTITY_PLAN_INVALID"):
                loader._validated_identity_plan(self.snapshot, ["synthetic"], self.mappings, [item])

    def test_rejects_missing_or_duplicate_identity(self) -> None:
        with self.assertRaisesRegex(LoaderError, "BUNDLE_IDENTITY_PLAN_INCOMPLETE"):
            loader._validated_identity_plan(self.snapshot, ["synthetic"], self.mappings, [])
        with self.assertRaisesRegex(LoaderError, "BUNDLE_IDENTITY_PLAN_INVALID"):
            loader._validated_identity_plan(self.snapshot, ["synthetic"], self.mappings, self.valid * 2)

    def test_rejects_invalid_strategy_schema_and_allocator(self) -> None:
        for changes in (
            {"strategy": "invalid"},
            {"target_schema": "legado"},
            {"source_next_auto_increment": True},
        ):
            item = dict(self.valid[0], **changes)
            with self.subTest(changes=changes), self.assertRaisesRegex(LoaderError, "BUNDLE_IDENTITY_PLAN_INVALID"):
                loader._validated_identity_plan(self.snapshot, ["synthetic"], self.mappings, [item])

    def test_unsigned_pair_requires_safe_sequence_identifiers(self) -> None:
        item = dict(self.valid[0], strategy="unsigned_sequence_pair", low_sequence="bad-name", high_sequence="u64_high_id")
        with self.assertRaisesRegex(LoaderError, "BUNDLE_IDENTITY_PLAN_INVALID"):
            loader._validated_identity_plan(self.snapshot, ["synthetic"], self.mappings, [item])


class NulPolicyTests(unittest.TestCase):
    def test_nul_audit_fallback_key_uses_original_source_row_without_primary_key(self) -> None:
        column = {"name": "description", "data_type": "varchar", "character_set": "utf8mb4"}
        original = "synthetic\x00text"
        raw = loader._source_value_bytes(original, column)
        digest = hashlib.sha256(len(raw).to_bytes(8, "big") + raw).hexdigest()

        key = loader._audit_pk_text({"primary_key": []}, [column], (original,))

        self.assertEqual(key, json.dumps({"source_row_sha256": digest}, sort_keys=True, separators=(",", ":")))

    def test_binary_nul_scan_compares_server_and_python_bytes_without_normalizing(self) -> None:
        class Guard:
            def sample_if_due(self):
                return None

        class Cursor:
            def __init__(self, aggregate):
                self.aggregate = aggregate
                self.rows = []

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def execute(self, query):
                if "COUNT(*)" in query:
                    self.rows = [(2, 1)]
                else:
                    self.rows = [(b"\x00\xffpayload",), (b"plain",)]

            def fetchone(self):
                return self.rows[0]

            def fetchmany(self, _size):
                rows, self.rows = self.rows, []
                return rows

        class Source:
            def cursor(self, cursor_class=None):
                return Cursor(cursor_class is not None)

        snapshot = {"tables": [{"name": "synthetic", "type": "BASE TABLE", "columns": [
            {"name": "payload", "data_type": "blob"},
        ]}]}
        result = loader._scan_source_binary_policy(Source(), snapshot, Guard())
        self.assertEqual(len(result), 1)
        self.assertEqual(result[0]["method_b_cells_with_nul"], 1)
        self.assertEqual(result[0]["python_cells_with_nul"], 1)
        self.assertEqual(result[0]["nul_bytes"], 1)
        self.assertEqual(result[0]["binary_bytes_scanned"], len(b"\x00\xffpayload") + len(b"plain"))
        self.assertEqual(result[0]["storage_encoding"], "bytea")
        self.assertEqual(result[0]["storage_policy_id"], "mysql_binary_exact_base64_ascii_text_v1")
        self.assertEqual(result[0]["nul_policy"], "preserve_exact_bytes")

    def test_binary_nul_scan_fails_closed_on_detector_disagreement(self) -> None:
        class Guard:
            def sample_if_due(self):
                return None

        class Cursor:
            def __init__(self):
                self.rows = []

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def execute(self, query):
                self.rows = [(1, 0)] if "COUNT(*)" in query else [(b"\x00",)]

            def fetchone(self):
                return self.rows[0]

            def fetchmany(self, _size):
                rows, self.rows = self.rows, []
                return rows

        class Source:
            def cursor(self, *_args):
                return Cursor()

        snapshot = {"tables": [{"name": "synthetic", "type": "BASE TABLE", "columns": [
            {"name": "payload", "data_type": "varbinary"},
        ]}]}
        result = loader._scan_source_binary_policy(Source(), snapshot, Guard())
        self.assertEqual(result[0]["scan_status"], "FAILED")
        self.assertEqual(result[0]["scan_failure_code"], "SOURCE_BINARY_NUL_DETECTOR_DISAGREEMENT")

    def test_sensitive_name_does_not_override_approved_text_nul_normalization(self) -> None:
        human_text_finding = {
            "character_set": "utf8mb4",
            "cells_with_nul": 2,
            "non_utf8_cells": 0,
            "invalid_declared_charset_cells_after_nul": 0,
            "name_suggests_sensitive": True,
        }
        self.assertFalse(loader._text_findings_require_review([human_text_finding]))
        invalid_text_finding = {**human_text_finding, "table": "residentes", "column": "observacao", "cells_scanned": 20, "non_utf8_cells": 1}
        self.assertTrue(loader._text_findings_require_review([invalid_text_finding]))
        self.assertEqual(loader._text_policy_table_failures([invalid_text_finding]), [{
            "table": "residentes", "column": "observacao", "reason": "SOURCE_TEXT_INVALID_UTF8", "affected_cells": 1,
        }])

    def test_text_nul_is_removed_and_exact_source_bytes_are_recorded(self) -> None:
        counts = {}
        records = []
        raw = b"before\x00after"
        value = normalize_mysql_value(
            raw,
            {"name": "description", "data_type": "text", "character_set": "utf8mb4"},
            nul_normalization_counts=counts,
            nul_records=records,
            table_name="synthetic",
        )
        self.assertEqual(value, "beforeafter")
        self.assertEqual(records, [("synthetic", "description", raw, "middle")])
        self.assertEqual(counts[("synthetic", "description")]["cells_with_nul"], 1)
        self.assertEqual(loader._nul_report_rows(counts)[0]["status"], "NORMALIZED_NUL")
        self.assertNotIn(raw, repr(loader._nul_report_rows(counts)).encode("utf-8"))

    def test_multibyte_charset_zero_bytes_without_u0000_are_not_nul(self) -> None:
        class Guard:
            def sample_if_due(self):
                return None

        class Cursor:
            def __init__(self, aggregate):
                self.aggregate = aggregate
                self.rows = []

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def execute(self, query):
                self.rows = [(1, 0)] if "COUNT(*)" in query else [(b"\x00A",)]

            def fetchone(self):
                return self.rows[0]

            def fetchmany(self, _size):
                rows, self.rows = self.rows, []
                return rows

        class Source:
            def cursor(self, cursor_class=None):
                return Cursor(cursor_class is not None)

        snapshot = {"tables": [{"name": "synthetic", "type": "BASE TABLE", "columns": [
            {"name": "description", "data_type": "text", "character_set": "ucs2"},
        ]}]}
        findings = loader._scan_source_text_policy(Source(), snapshot, Guard())
        self.assertEqual(findings[0]["python_cells_with_nul"], 0)
        self.assertEqual(findings[0]["method_b_cells_with_nul"], 0)
        self.assertFalse(findings[0]["detector_disagreement"])

    def test_multibyte_charset_normalizes_only_u0000_and_audits_source_bytes(self) -> None:
        counts = {}
        records = []
        raw = "A\x00B".encode("utf-16-be")
        value = normalize_mysql_value(
            raw,
            {"name": "description", "data_type": "text", "character_set": "ucs2"},
            nul_normalization_counts=counts,
            nul_records=records,
            table_name="synthetic",
        )
        self.assertEqual(value, "AB")
        self.assertEqual(records, [("synthetic", "description", raw, "middle")])
        self.assertEqual(counts[("synthetic", "description")]["nul_bytes"], 2)

    def test_json_physical_and_escaped_nul_are_removed_and_audited(self) -> None:
        counts = {}
        records = []
        column = {"name": "payload", "data_type": "json", "character_set": "utf8mb4"}
        physical = b'{"x":"\x00"}'
        normalized_physical = normalize_mysql_value(
            physical, column, nul_normalization_counts=counts, nul_records=records, table_name="synthetic"
        )
        escaped = b'{"x":"\\u0000"}'
        normalized_escaped = normalize_mysql_value(
            escaped, column, nul_normalization_counts=counts, nul_records=records, table_name="synthetic"
        )
        self.assertEqual(normalized_physical, '{"x":""}')
        self.assertEqual(normalized_escaped, '{"x":""}')
        self.assertEqual(records[0][2], physical)
        self.assertEqual(records[1][2], escaped)
        self.assertEqual(counts[("synthetic", "payload")]["cells_with_nul"], 2)
        self.assertEqual(counts[("synthetic", "payload")]["json_escaped_nul_cells"], 1)
        self.assertEqual(counts[("synthetic", "payload")]["nul_bytes"], 7)

    def test_json_key_collision_during_nul_normalization_fails_closed(self) -> None:
        with self.assertRaisesRegex(LoaderError, "JSON_NUL_NORMALIZATION_KEY_COLLISION"):
            normalize_mysql_value(
                b'{"a\\u0000":1,"a":2}',
                {"name": "payload", "data_type": "json", "character_set": "utf8mb4"},
                nul_normalization_counts={},
                nul_records=[],
                table_name="synthetic",
            )

    def test_json_physical_nul_key_collision_fails_closed(self) -> None:
        with self.assertRaisesRegex(LoaderError, "JSON_NUL_NORMALIZATION_KEY_COLLISION"):
            normalize_mysql_value(
                b'{"a\x00":1,"a":2}',
                {"name": "payload", "data_type": "json", "character_set": "utf8mb4"},
                nul_normalization_counts={},
                nul_records=[],
                table_name="synthetic",
            )

    def test_json_physical_and_escaped_nul_key_collision_fails_closed(self) -> None:
        with self.assertRaisesRegex(LoaderError, "JSON_NUL_NORMALIZATION_KEY_COLLISION"):
            normalize_mysql_value(
                b'{"a\x00":1,"a\\u0000":2}',
                {"name": "payload", "data_type": "json", "character_set": "utf8mb4"},
                nul_normalization_counts={},
                nul_records=[],
                table_name="synthetic",
            )

    def test_json_scan_detects_physical_nul_key_collision_before_dict_loss(self) -> None:
        class Guard:
            def sample_if_due(self):
                return None

        class Cursor:
            def __init__(self, aggregate):
                self.rows = [(1, 1)] if aggregate else [(b'{"a\x00":1,"a\\u0000":2}',)]

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def execute(self, _query):
                return None

            def fetchone(self):
                return self.rows[0]

            def fetchmany(self, _size):
                rows, self.rows = self.rows, []
                return rows

        class Source:
            def cursor(self, cursor_class=None):
                return Cursor(cursor_class is not None and cursor_class.__name__ == "Cursor")

        snapshot = {"tables": [{"name": "synthetic", "type": "BASE TABLE", "columns": [
            {"name": "payload", "data_type": "json", "character_set": "utf8mb4"},
        ]}]}
        result = loader._scan_source_text_policy(Source(), snapshot, Guard())
        self.assertEqual(result[0]["scan_status"], "FAILED")
        self.assertEqual(result[0]["scan_failure_code"], "JSON_NUL_NORMALIZATION_KEY_COLLISION")

    def test_unscannable_charset_reports_expected_cell_count(self) -> None:
        class Cursor:
            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def execute(self, _query):
                return None

            def fetchone(self):
                return (5,)

        class Source:
            def cursor(self, _cursor_class=None):
                return Cursor()

        for charset, expected_reason in (("binary", "SOURCE_TEXT_CHARSET_BINARY"), ("unsupported_charset", "SOURCE_TEXT_CHARSET_UNSUPPORTED")):
            snapshot = {"tables": [{"name": "synthetic", "type": "BASE TABLE", "columns": [
                {"name": "payload", "data_type": "text", "character_set": charset},
            ]}]}
            finding = loader._scan_source_text_policy(Source(), snapshot, _MemoryGuard())[0]
            self.assertEqual(finding["cells_expected"], 5)
            self.assertEqual(finding["cells_scanned"], 0)
            failure = loader._text_policy_table_failures([finding])[0]
            self.assertEqual(failure["reason"], expected_reason)
            self.assertEqual(failure["affected_cells"], 5)

    def test_scan_compares_legacy_python_binary_sql_and_python_bytes(self) -> None:
        class Cursor:
            def __init__(self, aggregate: bool):
                self.aggregate = aggregate
                self.sent = False

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def execute(self, _query):
                return None

            def fetchone(self):
                return (1, 1)

            def fetchmany(self, _size):
                if self.aggregate or self.sent:
                    return []
                self.sent = True
                return [(b"prefix\x00suffix",)]

        class Source:
            def cursor(self, cursor_class):
                return Cursor(cursor_class.__name__ == "Cursor")

        snapshot = {"tables": [{
            "name": "synthetic",
            "type": "BASE TABLE",
            "columns": [{"name": "description", "data_type": "text", "character_set": "utf8mb4", "source_collation": "utf8mb4_unicode_ci"}],
        }]}
        findings = loader._scan_source_text_policy(Source(), snapshot, _MemoryGuard())
        self.assertEqual(len(findings), 1)
        finding = findings[0]
        self.assertEqual(finding["method_a_python_string_cells_with_nul"], 1)
        self.assertEqual(finding["method_b_cells_with_nul"], 1)
        self.assertEqual(finding["python_cells_with_nul"], 1)
        self.assertEqual(finding["in_middle"], 1)
        self.assertNotIn("prefix", repr(finding))

    def test_nul_audit_copies_original_bytes_to_private_schema(self) -> None:
        class Copy:
            def __init__(self):
                self.rows = []

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def write_row(self, row):
                self.rows.append(row)

        class Cursor:
            def __init__(self, capture):
                self.capture = capture

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def copy(self, _statement):
                return self.capture

        class Target:
            def __init__(self, capture):
                self.capture = capture

            def cursor(self):
                return Cursor(self.capture)

        original = b"text\x00bytes"
        spool = io.BytesIO((json.dumps(["synthetic", "description", '{"id":1}', base64.b64encode(original).decode(), "middle"]) + "\n").encode())
        capture = Copy()
        mapping = {
            "types": {"description": object()},
            "columns": [{"name": "description", "data_type": "text"}],
        }
        count = loader._apply_nul_audit(Target(capture), spool, {"synthetic": mapping})
        self.assertEqual(count, 1)
        self.assertEqual(capture.rows, [("synthetic", "description", '{"id":1}', original)])

    def test_runtime_legacy_privilege_is_rejected(self) -> None:
        class Cursor:
            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

            def execute(self, *_args):
                return None

            def fetchone(self):
                return (True,) + (False,) * 8

        class Target:
            def cursor(self):
                return Cursor()

        with self.assertRaisesRegex(LoaderError, "TARGET_RUNTIME_ACCESS_TO_LEGACY_DETECTED"):
            loader._verify_application_legacy_isolation(Target(), "erp_app")


class NormalizeMysqlValueTests(unittest.TestCase):
    def test_unsigned_bigint_fk_pair_uses_effective_ddl_numeric_promotion(self) -> None:
        snapshot = {
            "source_no_backslash_escapes": False,
            "tables": [
                {"name": "rbac_sessoes", "type": "BASE TABLE", "columns": [
                    {"name": "id", "ordinal": 1, "data_type": "bigint", "column_type": "bigint unsigned", "nullable": False, "extra": "auto_increment", "unsigned_bigint_exceeds_signed_limit": False},
                ]},
                {"name": "rbac_auditoria", "type": "BASE TABLE", "columns": [
                    {"name": "sessao_id", "ordinal": 1, "data_type": "bigint", "column_type": "bigint unsigned", "nullable": False, "extra": "", "unsigned_bigint_exceeds_signed_limit": True},
                ]},
            ],
        }
        report = {
            "collation_overrides": {}, "collation_decisions": [],
            "table_schemas": {"rbac_sessoes": "erp", "rbac_auditoria": "erp"},
            "unsigned_bigint_range": {
                "observed_above_signed_limit": [{"table": "rbac_auditoria", "column": "sessao_id"}],
                "aligned_for_foreign_keys": [{"table": "rbac_sessoes", "column": "id"}],
                "numeric_columns": [
                    {"table": "rbac_auditoria", "column": "sessao_id"},
                    {"table": "rbac_sessoes", "column": "id"},
                ],
            },
        }
        mappings = loader._validate_column_mappings(snapshot, report, ["rbac_sessoes", "rbac_auditoria"])
        self.assertEqual(mappings["rbac_sessoes"]["types"]["id"].sql_type, "NUMERIC(20,0)")
        self.assertEqual(mappings["rbac_auditoria"]["types"]["sessao_id"].sql_type, "NUMERIC(20,0)")

    def test_unsigned_bigint_below_signed_limit_stays_bigint(self) -> None:
        snapshot = {
            "source_no_backslash_escapes": False,
            "tables": [{"name": "rbac_sessoes", "type": "BASE TABLE", "columns": [
                {"name": "id", "ordinal": 1, "data_type": "bigint", "column_type": "bigint unsigned", "nullable": False, "extra": "", "unsigned_bigint_exceeds_signed_limit": False},
            ]}],
        }
        report = {
            "collation_overrides": {}, "collation_decisions": [],
            "table_schemas": {"rbac_sessoes": "erp"},
            "unsigned_bigint_range": {
                "observed_above_signed_limit": [],
                "aligned_for_foreign_keys": [],
                "numeric_columns": [],
            },
        }
        mapping = loader._validate_column_mappings(snapshot, report, ["rbac_sessoes"])
        self.assertEqual(mapping["rbac_sessoes"]["types"]["id"].sql_type, "BIGINT")

    def test_unsigned_bigint_without_range_scan_fails_closed(self) -> None:
        snapshot = {
            "source_no_backslash_escapes": False,
            "tables": [{"name": "rbac_sessoes", "type": "BASE TABLE", "columns": [
                {"name": "id", "ordinal": 1, "data_type": "bigint", "column_type": "bigint unsigned", "nullable": False, "extra": ""},
            ]}],
        }
        report = {
            "collation_overrides": {}, "collation_decisions": [],
            "table_schemas": {"rbac_sessoes": "erp"},
            "unsigned_bigint_range": {
                "observed_above_signed_limit": [],
                "aligned_for_foreign_keys": [],
                "numeric_columns": [],
            },
        }
        with self.assertRaisesRegex(LoaderError, "BUNDLE_UNSIGNED_BIGINT_RANGE_SCAN_REQUIRED"):
            loader._validate_column_mappings(snapshot, report, ["rbac_sessoes"])

    def test_generated_water_column_is_excluded_from_copy_columns(self) -> None:
        snapshot = {
            "source_no_backslash_escapes": False,
            "tables": [{
                "name": "lancamentos_agua", "type": "BASE TABLE", "columns": [
                    {"name": "id", "ordinal": 1, "data_type": "int", "column_type": "int", "nullable": False, "extra": "auto_increment"},
                    {"name": "leitura_anterior", "ordinal": 2, "data_type": "decimal", "column_type": "decimal(10,2)", "nullable": False, "extra": ""},
                    {"name": "leitura_atual", "ordinal": 3, "data_type": "decimal", "column_type": "decimal(10,2)", "nullable": False, "extra": ""},
                    {"name": "consumo", "ordinal": 4, "data_type": "decimal", "column_type": "decimal(10,2)", "nullable": True, "extra": "STORED GENERATED", "generated_expression_present": True, "generated_expression_key": "lancamentos_agua.consumo.v1", "generated_expression_sha256": "b" * 64},
                ],
            }],
        }
        report = {
            "collation_overrides": {}, "collation_decisions": [],
            "table_schemas": {"lancamentos_agua": "erp"},
        }
        mapping = loader._validate_column_mappings(snapshot, report, ["lancamentos_agua"])["lancamentos_agua"]
        self.assertEqual([column["name"] for column in mapping["columns"]], ["id", "leitura_anterior", "leitura_atual", "consumo"])
        self.assertEqual([column["name"] for column in mapping["copy_columns"]], ["id", "leitura_anterior", "leitura_atual"])
        self.assertEqual([column["name"] for column in mapping["generated_columns"]], ["consumo"])

    def test_loader_fails_closed_on_unknown_generated_column(self) -> None:
        snapshot = {
            "source_no_backslash_escapes": False,
            "tables": [{"name": "unknown", "type": "BASE TABLE", "columns": [
                {"name": "generated", "ordinal": 1, "data_type": "decimal", "column_type": "decimal(10,2)", "nullable": True, "extra": "STORED GENERATED", "generated_expression_present": True, "generated_expression_key": "unknown.v1"},
                {"name": "base", "ordinal": 2, "data_type": "decimal", "column_type": "decimal(10,2)", "nullable": True, "extra": ""},
            ]}],
        }
        report = {"collation_overrides": {}, "collation_decisions": [], "table_schemas": {"unknown": "erp"}}
        with self.assertRaisesRegex(LoaderError, "GENERATED_COLUMN_TRANSLATION_REQUIRED"):
            loader._validate_column_mappings(snapshot, report, ["unknown"])

    def test_null_stays_null(self) -> None:
        self.assertIsNone(normalize_mysql_value(None, {"data_type": "varchar", "column_type": "varchar(20)"}))

    def test_binary_values_are_preserved_byte_for_byte(self) -> None:
        raw = b"\x00\xffpayload"
        self.assertEqual(normalize_mysql_value(raw, {"data_type": "longblob", "column_type": "longblob"}), raw)
        self.assertEqual(normalize_mysql_value(raw, {"data_type": "varbinary", "column_type": "varbinary(32)"}), raw)

    def test_approved_binary_payload_is_canonical_base64_ascii(self) -> None:
        raw = b"\x00\xffpayload\x80"
        column = {
            "name": "conteudo", "data_type": "longblob", "column_type": "longblob",
            "binary_storage_encoding": "base64",
        }
        encoded = normalize_mysql_value(raw, column, table_name="tenant_arquivos")
        self.assertEqual(encoded, "AP9wYXlsb2FkgA==")
        self.assertTrue(encoded.isascii())
        self.assertEqual(base64.b64decode(encoded, validate=True), raw)
        with self.assertRaisesRegex(LoaderError, "BINARY_STORAGE_POLICY_METADATA_MISMATCH"):
            normalize_mysql_value(raw, column, table_name="other_table")
        with self.assertRaisesRegex(LoaderError, "BINARY_STORAGE_POLICY_METADATA_MISMATCH"):
            normalize_mysql_value(raw, {"name": "conteudo", "data_type": "longblob", "column_type": "longblob"}, table_name="tenant_arquivos")

    def test_approved_binary_snapshot_mapping_copy_and_reconciliation_round_trip(self) -> None:
        snapshot = {
            "binary_storage_policy_id": loader.BINARY_STORAGE_POLICY_ID,
            "source_no_backslash_escapes": False,
            "tables": [{
                "name": "tenant_arquivos", "type": "BASE TABLE", "columns": [{
                    "name": "conteudo", "ordinal": 1, "nullable": True,
                    "data_type": "longblob", "column_type": "longblob", "extra": "",
                    "binary_storage_encoding": "base64",
                }],
            }],
        }
        report = {
            "collation_overrides": {}, "collation_decisions": [],
            "table_schemas": {"tenant_arquivos": "erp"},
        }
        mapping = _validate_column_mappings(snapshot, report, ["tenant_arquivos"])["tenant_arquivos"]
        raw = bytes(range(256)) + b"\x00\xffpayload\x80"
        column = mapping["copy_columns"][0]
        encoded = normalize_mysql_value(raw, column, table_name="tenant_arquivos")
        self.assertEqual(mapping["storage_encodings"], {"conteudo": "base64"})
        self.assertEqual(mapping["types"]["conteudo"].sql_type, "TEXT")
        self.assertTrue(encoded.isascii())
        self.assertEqual(
            canonical_row_sha256((raw,), [column], is_source=True),
            canonical_row_sha256((encoded,), [column], is_source=False),
        )

    def test_text_nul_requires_audit_collector_when_called_directly(self) -> None:
        with self.assertRaisesRegex(LoaderError, "SOURCE_TEXT_NUL_REPORT_REQUIRED"):
            normalize_mysql_value("prefix\x00suffix", {"data_type": "varchar", "column_type": "varchar(30)"})

    def test_column_mapping_requires_complete_collation_decisions(self) -> None:
        snapshot = {
            "source_no_backslash_escapes": False,
            "tables": [{"name": "t", "type": "BASE TABLE", "columns": [
                {"name": "label", "ordinal": 1, "data_type": "varchar", "column_type": "varchar(20)", "nullable": True}
            ]}],
        }
        report = {
            "collation_overrides": {},
            "collation_decisions": [{"table": "t", "column": "label", "target": "ci"}],
            "table_schemas": {"t": "erp"},
        }
        mappings = _validate_column_mappings(snapshot, report, ["t"])
        self.assertEqual(mappings["t"]["types"]["label"].collation_sql, "ci")
        with self.assertRaisesRegex(LoaderError, "BUNDLE_COLLATION_DECISIONS_MISMATCH"):
            _validate_column_mappings(snapshot, {"collation_overrides": {}, "collation_decisions": []}, ["t"])

    def test_backup_table_schema_routing_is_fail_closed(self) -> None:
        snapshot = {"tables": [{"name": "archive_backup", "type": "BASE TABLE", "columns": [
            {"name": "id", "ordinal": 1, "data_type": "int", "column_type": "int", "nullable": False}
        ]}]}
        report = {"collation_overrides": {}, "collation_decisions": [], "table_schemas": {"archive_backup": "erp"}}
        with self.assertRaisesRegex(LoaderError, "BUNDLE_TABLE_SCHEMA_ROUTING_MISMATCH"):
            _validate_column_mappings(snapshot, report, ["archive_backup"])

    def test_json_nul_requires_audit_collector_when_called_directly(self) -> None:
        with self.assertRaisesRegex(LoaderError, "SOURCE_TEXT_NUL_REPORT_REQUIRED"):
            normalize_mysql_value('{"label":"\\u0000"}', {"data_type": "json", "column_type": "json"})

    def test_json_must_parse(self) -> None:
        with self.assertRaisesRegex(LoaderError, "SOURCE_JSON_INVALID"):
            normalize_mysql_value("{invalid", {"data_type": "json", "column_type": "json"})

    def test_zero_date_normalizes_to_null_and_reports_only_aggregate_count(self) -> None:
        counts: dict[tuple[str, str], int] = {}
        value = normalize_mysql_value(
            b"0000-00-00",
            {"name": "created_on", "data_type": "date", "column_type": "date"},
            normalization_counts=counts,
            table_name="example_table",
        )
        self.assertIsNone(value)
        self.assertEqual(
            loader._zero_date_report_rows(counts),
            [{"table": "example_table", "column": "created_on", "count": 1, "status": "NORMALIZED_ZERO_DATE"}],
        )

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

    def test_time_negative_and_extended_range_are_preserved_as_interval(self) -> None:
        self.assertEqual(
            normalize_mysql_value(b"-01:00:00", {"data_type": "time", "column_type": "time", "time_uses_interval": True}),
            -timedelta(hours=1),
        )
        self.assertEqual(
            normalize_mysql_value(b"838:00:00", {"data_type": "time", "column_type": "time", "time_uses_interval": True}),
            timedelta(hours=838),
        )

    def test_clock_time_values_are_loaded_as_time_and_require_catalog_flag(self) -> None:
        from datetime import time

        self.assertEqual(
            normalize_mysql_value(b"09:30:00", {"data_type": "time", "column_type": "time", "time_uses_interval": False}),
            time(9, 30),
        )
        with self.assertRaisesRegex(LoaderError, "MYSQL_TIME_RANGE_SCAN_REQUIRED"):
            normalize_mysql_value(b"09:30:00", {"data_type": "time", "column_type": "time"})

    def test_bit_width_is_preserved(self) -> None:
        self.assertEqual(normalize_mysql_value(b"\x05", {"data_type": "bit", "column_type": "bit(3)"}), "101")

    def test_binary_nul_is_preserved_exactly_for_bytea(self) -> None:
        original = b"\x00\x00\xffbinary\x00"
        for data_type in ("blob", "binary", "varbinary"):
            value = normalize_mysql_value(original, {"data_type": data_type, "column_type": data_type})
            self.assertEqual(value, original)

    def test_enum_must_match_declared_label(self) -> None:
        meta = {"data_type": "enum", "column_type": "enum('A','B')"}
        self.assertEqual(normalize_mysql_value("A", meta), "A")
        counts: dict[tuple[str, str, str], int] = {}
        self.assertEqual(
            normalize_mysql_value("C", {**meta, "name": "state"}, enum_value_counts=counts, table_name="example_table"),
            "C",
        )
        self.assertEqual(
            normalize_mysql_value("", {**meta, "name": "state"}, enum_value_counts=counts, table_name="example_table"),
            "",
        )
        self.assertEqual(loader._enum_extension_report_rows(counts), [{
            "table": "example_table",
            "column": "state",
            "cells_outside_declared_labels": 2,
            "distinct_extra_values": 2,
            "counts_by_distinct_extra_value": [1, 1],
            "empty_label_cells": 1,
            "status": "ENUM_EXTENDED_FROM_EXISTING_VALUES",
        }])
        with self.assertRaisesRegex(LoaderError, "ENUM_EXTENSION_REPORT_REQUIRED"):
            normalize_mysql_value("C", meta)

    def test_text_bytes_require_declared_charset(self) -> None:
        meta = {"data_type": "varchar", "column_type": "varchar(20)", "character_set": "utf8mb4"}
        self.assertEqual(normalize_mysql_value("olá".encode(), meta), "olá")
        with self.assertRaisesRegex(LoaderError, "SOURCE_TEXT_INVALID_UTF8"):
            normalize_mysql_value(b"\xff", meta)
        self.assertEqual(
            normalize_mysql_value(b"\xff", {"data_type": "varchar", "column_type": "varchar(20)", "character_set": "latin1"}),
            "ÿ",
        )


class BundleValidationTests(unittest.TestCase):
    def test_executable_bundle_rejects_trigger_placeholder_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            bundle_name = "catalog-" + "a" * 12 + "-collation-" + "b" * 8 + "-role-" + "c" * 8 + "-code-" + "d" * 8
            bundle = root / bundle_name
            bundle.mkdir()
            (bundle / "BUNDLE_COMPLETE").write_text("bundle_complete=true\n", encoding="utf-8")
            (bundle / "report.json").write_text(json.dumps({
                "load_executable_complete": True,
                "executable_complete": False,
            }), encoding="utf-8")
            (bundle / "catalog_snapshot.json").write_text(json.dumps({}), encoding="utf-8")
            with patch.object(loader, "BUNDLE_ROOT", root), \
                 patch.object(loader, "_validate_executable_bundle_ownership"):
                with self.assertRaisesRegex(LoaderError, "BUNDLE_EXECUTION_INCOMPLETE"):
                    validate_bundle(bundle_name)

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
                self.server_status = 0

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
        def keep_consistent_snapshot(active_connection, *, keep_snapshot):
            self.assertTrue(keep_snapshot)
            active_connection.server_status = pymysql.constants.SERVER_STATUS.SERVER_STATUS_IN_TRANS
            return snapshot

        with patch.object(pymysql, "connect", side_effect=connect), \
             patch.object(loader, "_mysql_socket_options", return_value={"unix_socket": "/run/mysqld/mysqld.sock"}), \
             patch.object(loader, "collect_catalog", side_effect=keep_consistent_snapshot) as collect:
            actual_connection, actual_snapshot = loader._connect_source(Path("/root-only/source.cnf"))

        self.assertIs(actual_connection, connection)
        self.assertEqual(actual_snapshot, snapshot)
        self.assertIs(captured["cursorclass"], pymysql.cursors.DictCursor)
        self.assertTrue(captured["autocommit"])
        self.assertEqual(connection.cursor_classes, [pymysql.cursors.Cursor])
        self.assertTrue(connection.server_status & pymysql.constants.SERVER_STATUS.SERVER_STATUS_IN_TRANS)
        collect.assert_called_once_with(connection, keep_snapshot=True)

    def test_connect_source_rejects_catalog_snapshot_that_is_not_left_active(self):
        import pymysql

        class Cursor:
            def execute(self, statement):
                return None

            def fetchone(self):
                return ("pg_reader@localhost", "erp_pg_source", "8.0.synthetic")

            def close(self):
                return None

            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                self.close()

        class Connection:
            server_status = 0

            def cursor(self, cursor_class=None):
                return Cursor()

            def close(self):
                self.closed = True

        connection = Connection()
        with patch.object(pymysql, "connect", return_value=connection), \
             patch.object(loader, "_mysql_socket_options", return_value={"unix_socket": "/run/mysqld/mysqld.sock"}), \
             patch.object(loader, "collect_catalog", return_value={"source_schema": "erp_pg_source"}):
            with self.assertRaises(loader.LoaderError) as error:
                loader._connect_source(Path("/root-only/source.cnf"))

        self.assertEqual(error.exception.code, "MYSQL_SOURCE_CONSISTENT_SNAPSHOT_NOT_ACTIVE")
        self.assertTrue(connection.closed)


class RebuildRunnerSecurityTests(unittest.TestCase):
    def test_read_only_reconcile_retry_is_release_bound_and_report_private(self) -> None:
        script = Path("tools/migracao_pg/rebuild.sh").read_text(encoding="utf-8")
        self.assertIn("development reconcile", script)
        self.assertIn("reconciliation-report-validation", script)
        self.assertIn("REPORT_FILE_MODE_INVALID", script)
        self.assertIn("reconciliation-read-only-retry", script)
        self.assertIn('"$python_bin" -E -B -m tools.migracao_pg.loader reconcile', script)
        self.assertIn("--retry-read-only", script)
        self.assertIn("emit_summary preflight", script)
        self.assertIn("emit_summary load", script)
        self.assertIn("emit_summary reconcile", script)
        self.assertNotIn('printf \'%s\\n\' "$reconciliation_json"', script)
        self.assertNotIn('printf \'%s\\n\' "$reconciliation_result"', script)
        self.assertIn("RECONCILIATION_COMPLETE", script)

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


class TargetPreflightTests(unittest.TestCase):
    def _connection(self, row):
        class Cursor:
            def execute(self, query, params=None):
                self.row = row

            def fetchone(self):
                return self.row

            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

        class Connection:
            def cursor(self):
                return Cursor()

        return Connection()

    def test_accepts_dedicated_cutover_roles_with_only_required_privileges(self) -> None:
        row = (
            "erpcondominios_pg", "erp_p2_cutover_loader", False, False, False, False, False, True, True,
            None, None, True, False, False, False, False, False, 0, 0,
        )
        result = loader._target_preflight(
            self._connection(row), "erp_p2_cutover_loader", "erpcondominios_app", "erpcondominios_pg"
        )
        self.assertTrue(result["migrator_login_non_elevated"])
        self.assertTrue(result["application_role_non_elevated"])
        self.assertEqual(result["migrator_role_memberships"], 0)
        self.assertEqual(result["application_role_memberships"], 0)

    def test_rejects_elevated_or_inherited_cutover_roles(self) -> None:
        valid = [
            "erpcondominios_pg", "erp_p2_cutover_loader", False, False, False, False, False, True, True,
            None, None, True, False, False, False, False, False, 0, 0,
        ]
        cases = (
            (2, True, "TARGET_MIGRATOR_PRIVILEGE_INVALID"),
            (3, True, "TARGET_MIGRATOR_PRIVILEGE_INVALID"),
            (4, True, "TARGET_MIGRATOR_PRIVILEGE_INVALID"),
            (5, True, "TARGET_MIGRATOR_PRIVILEGE_INVALID"),
            (6, True, "TARGET_MIGRATOR_PRIVILEGE_INVALID"),
            (7, False, "TARGET_MIGRATOR_PRIVILEGE_INVALID"),
            (12, True, "TARGET_APPLICATION_ROLE_PRIVILEGE_INVALID"),
            (13, True, "TARGET_APPLICATION_ROLE_PRIVILEGE_INVALID"),
            (14, True, "TARGET_APPLICATION_ROLE_PRIVILEGE_INVALID"),
            (15, True, "TARGET_APPLICATION_ROLE_PRIVILEGE_INVALID"),
            (16, True, "TARGET_APPLICATION_ROLE_PRIVILEGE_INVALID"),
            (17, 1, "TARGET_APPLICATION_ROLE_MEMBERSHIP_FORBIDDEN"),
            (18, 1, "TARGET_MIGRATOR_ROLE_MEMBERSHIP_FORBIDDEN"),
        )
        for index, value, code in cases:
            candidate = list(valid)
            candidate[index] = value
            with self.subTest(index=index), self.assertRaisesRegex(LoaderError, code):
                loader._target_preflight(
                    self._connection(tuple(candidate)),
                    "erp_p2_cutover_loader", "erpcondominios_app", "erpcondominios_pg",
                )


class _FakeCursor:
    def __init__(self, connection) -> None:
        self.connection = connection
        self.row = None

    def execute(self, query, params=None) -> None:
        text = query.as_string(None) if hasattr(query, "as_string") else str(query)
        self.connection.statements.append((text, self.connection.transaction_active))
        if "current_database()" in text:
            self.row = ("erp_pg_dev",)
        elif "has_schema_privilege" in text:
            self.row = (False,) * 9
        elif "count(*)" in text.lower():
            self.row = (self.connection.count_results.pop(0) if self.connection.count_results else 0,)

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
        self.count_results = []
        self.transaction_depth = 0

    def cursor(self):
        return _FakeCursor(self)

    @contextmanager
    def transaction(self):
        outermost = self.transaction_depth == 0
        self.transaction_depth += 1
        if outermost:
            self.transaction_active = True
        try:
            yield
        except Exception:
            self.rollbacks += 1
            raise
        else:
            if outermost:
                self.commits += 1
        finally:
            self.transaction_depth -= 1
            if outermost:
                self.transaction_active = False

    def rollback(self) -> None:
        self.rollbacks += 1
        if self.fail_rollback:
            raise RuntimeError("synthetic rollback failure")

    def close(self) -> None:
        self.closed = True
        if self.fail_close:
            raise RuntimeError("synthetic target close failure")


class EnumCheckTests(unittest.TestCase):
    def test_check_includes_only_declared_or_observed_empty_label(self) -> None:
        target = _FakeTarget()
        report = {"enum_checks_deferred": [{"table": "sample", "column": "state", "constraint_name": "ck_state"}]}
        mappings = {"sample": {"enum_labels": {"state": ("A", "B")}}}
        counts = {("sample", "state", "legacy-value"): 2, ("sample", "state", ""): 3}
        loader._apply_enum_checks(target, report, mappings, counts)
        self.assertEqual(len(target.statements), 1)
        self.assertIn("ALTER TABLE", target.statements[0][0])
        self.assertIn("CHECK", target.statements[0][0])
        self.assertIn("''", target.statements[0][0])
        self.assertEqual(report["enum_empty_label_counts"], [{"table": "sample", "column": "state", "count": 3}])

    def test_check_does_not_invent_unobserved_empty_label(self) -> None:
        target = _FakeTarget()
        report = {"enum_checks_deferred": [{"table": "sample", "column": "state", "constraint_name": "ck_state"}]}
        mappings = {"sample": {"enum_labels": {"state": ("A", "B")}}}
        loader._apply_enum_checks(target, report, mappings, {})
        self.assertNotIn("''", target.statements[0][0])
        self.assertEqual(report["enum_empty_label_counts"], [])

    def test_check_metadata_must_cover_every_enum_column(self) -> None:
        target = _FakeTarget()
        with self.assertRaisesRegex(LoaderError, "BUNDLE_ENUM_CHECK_COVERAGE_MISMATCH"):
            loader._apply_enum_checks(
                target,
                {"enum_checks_deferred": []},
                {"sample": {"enum_labels": {"state": ("A",)}}},
                {},
            )
        self.assertEqual(target.statements, [])


class UniqueForeignKeyTests(unittest.TestCase):
    def test_unique_prefix_index_on_base64_binary_column_is_rejected(self) -> None:
        mapping = {
            "target_schema": "erp",
            "columns": [{"name": "conteudo", "data_type": "longblob"}],
            "types": {
                "conteudo": loader.map_mysql_type(
                    "longblob", table_name="tenant_arquivos", column_name="conteudo",
                    binary_storage_encoding="base64",
                ),
            },
        }
        item = {
            "table": "tenant_arquivos",
            "columns": [{"name": "conteudo", "prefix_length": 16, "direction": "ASC"}],
        }
        with self.assertRaisesRegex(LoaderError, "BUNDLE_UNIQUE_BASE64_PREFIX_INDEX_UNSUPPORTED"):
            loader._unique_index_parts(item, mapping)

    def test_unique_collision_under_ci_converts_only_colliding_column_to_c(self) -> None:
        target = _FakeTarget()
        target.count_results = [0, 1, 1, 0, 0, 0]
        mapping = {
            "target_schema": "erp",
            "columns": [
                {"name": "id", "data_type": "int"},
                {"name": "name", "data_type": "varchar"},
            ],
            "types": {
                "id": loader.map_mysql_type("int"),
                "name": loader.map_mysql_type("varchar(40)", target_collation="ci"),
            },
        }
        report = {
            "primary_keys_post_load": [{"table": "sample", "columns": ["id"], "target_schema": "erp", "constraint_name": "pk_sample"}],
            "unique_indexes_post_load": [{
                "table": "sample", "index": "uq_name", "target_schema": "erp", "generated_name": "ix_name",
                "columns": [{"name": "name", "prefix_length": None, "direction": "ASC"}], "prefix_index": False,
            }],
        }
        results = loader._apply_unique_constraints(target, report, {"sample": mapping})
        unique_result = next(item for item in results if item["kind"] == "UNIQUE_INDEX")
        self.assertEqual(unique_result["status"], "CREATED")
        self.assertEqual(unique_result["collision_groups_under_ci"], 1)
        self.assertEqual(unique_result["remaining_duplicate_groups"], 0)
        self.assertEqual(unique_result["columns_converted_to_C"], ["name"])
        statements = [statement for statement, _ in target.statements]
        self.assertTrue(any("ALTER TABLE" in statement and "COLLATE" in statement for statement in statements))
        self.assertTrue(any("CREATE UNIQUE INDEX" in statement for statement in statements))
        converted_at = next(index for index, statement in enumerate(statements) if "ALTER TABLE" in statement and "COLLATE" in statement)
        primary_key_at = next(index for index, statement in enumerate(statements) if "ADD CONSTRAINT" in statement and "PRIMARY KEY" in statement)
        unique_index_at = next(index for index, statement in enumerate(statements) if "CREATE UNIQUE INDEX" in statement)
        self.assertLess(converted_at, primary_key_at)
        self.assertLess(converted_at, unique_index_at)

    def test_literal_unique_collision_is_reported_without_dropping_rows(self) -> None:
        target = _FakeTarget()
        target.count_results = [1, 0, 1]
        mapping = {
            "target_schema": "erp",
            "columns": [{"name": "name", "data_type": "varchar"}],
            "types": {"name": loader.map_mysql_type("varchar(40)", target_collation="ci")},
        }
        report = {
            "primary_keys_post_load": [],
            "unique_indexes_post_load": [{
                "table": "sample", "index": "uq_name", "target_schema": "erp", "generated_name": "ix_name",
                "columns": [{"name": "name", "prefix_length": None, "direction": "ASC"}], "prefix_index": False,
            }],
        }
        results = loader._apply_unique_constraints(target, report, {"sample": mapping})
        self.assertEqual(results[0]["status"], "BLOCKED_LITERAL_UNIQUE_COLLISION")
        self.assertEqual(results[0]["collision_groups_under_ci"], 1)
        self.assertEqual(results[0]["remaining_duplicate_groups"], 1)
        self.assertFalse(any("CREATE UNIQUE INDEX" in statement for statement, _ in target.statements))

    def test_foreign_key_with_orphans_remains_not_valid_and_reports_count(self) -> None:
        target = _FakeTarget()
        target.count_results = [2]
        mappings = {
            "child": {"target_schema": "erp", "types": {"parent_id": loader.map_mysql_type("int")}},
            "parent": {"target_schema": "erp", "types": {"id": loader.map_mysql_type("int")}},
        }
        report = {"foreign_keys_post_load": [{
            "table": "child", "name": "fk_child_parent", "columns": ["parent_id"],
            "referenced_table": "parent", "referenced_columns": ["id"], "target_schema": "erp",
            "referenced_schema": "erp", "update_rule": "CASCADE", "delete_rule": "RESTRICT", "constraint_name": "fk_child_parent",
        }]}
        unique_results = [{"kind": "PRIMARY_KEY", "table": "parent", "columns": ["id"], "status": "CREATED", "supports_foreign_key": True}]
        loader._apply_foreign_keys(target, report, mappings, unique_results)
        self.assertEqual(report["foreign_key_results"][0]["orphan_rows"], 2)
        self.assertEqual(report["foreign_key_results"][0]["status"], "NOT_VALID_ORPHANS_REPORTED")
        statements = [statement for statement, _ in target.statements]
        self.assertTrue(any("NOT VALID" in statement for statement in statements))
        self.assertFalse(any("VALIDATE CONSTRAINT" in statement for statement in statements))


class ForeignKeyFailClosedTests(unittest.TestCase):
    def test_missing_target_unique_constraint_blocks_foreign_key(self) -> None:
        target = _FakeTarget()
        mappings = {
            "child": {"target_schema": "erp", "types": {"parent_id": loader.map_mysql_type("int")}},
            "parent": {"target_schema": "erp", "types": {"id": loader.map_mysql_type("int")}},
        }
        report = {"foreign_keys_post_load": [{
            "table": "child", "name": "fk_child_parent", "columns": ["parent_id"],
            "referenced_table": "parent", "referenced_columns": ["id"], "target_schema": "erp",
            "referenced_schema": "erp", "update_rule": "CASCADE", "delete_rule": "RESTRICT",
            "constraint_name": "fk_child_parent",
        }]}
        with self.assertRaisesRegex(LoaderError, "FK_TARGET_UNIQUE_CONSTRAINT_UNAVAILABLE"):
            loader._apply_foreign_keys(target, report, mappings, [])
        self.assertEqual(target.statements, [])


class TemporalNullabilityTests(unittest.TestCase):
    def test_restores_not_null_only_when_no_zero_date_was_normalized(self) -> None:
        target = _FakeTarget()
        report = {"zero_date_nullable_columns": [
            {"table": "kept_not_null", "column": "created_at"},
            {"table": "zero_date_column", "column": "created_at"},
        ]}
        mappings = {
            "kept_not_null": {"target_schema": "erp", "types": {"created_at": loader.map_mysql_type("datetime")}},
            "zero_date_column": {"target_schema": loader.LEGACY_SCHEMA, "types": {"created_at": loader.map_mysql_type("datetime")}},
        }
        counts = {("zero_date_column", "created_at"): 2}
        results = loader._restore_temporal_not_null(target, report, mappings, counts)
        self.assertEqual([item["status"] for item in results], ["RESTORED_NOT_NULL", "LEFT_NULLABLE_FOR_ZERO_DATE"])
        self.assertEqual([item["zero_date_count"] for item in results], [0, 2])
        statements = [statement for statement, _ in target.statements]
        self.assertEqual(len(statements), 1)
        self.assertIn("ALTER COLUMN", statements[0])
        self.assertIn("kept_not_null", statements[0])
        self.assertNotIn("zero_date_column", statements[0])


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
    def _call_load(self, copy_side_effect=None, table_names=None):
        source = _FakeSource()
        target = _FakeTarget()
        captured_reports = []
        role = "erp_app"
        config = {
            "user": "erp_p2_loader", "application_user": role,
            "dbname": "erp_pg_dev", "schema": "erp",
        }
        names = table_names or ["synthetic_table"]
        report = {
            "application_role_sha256": hashlib.sha256(role.encode()).hexdigest(),
            "generated_tables": names,
            "generated_scripts_sha256": {name: "0" * 64 for name in loader.SCRIPT_NAMES},
            "catalog_fingerprint_sha256": "f" * 64,
            "generator_version": "test",
            "primary_keys_post_load": [],
            "unique_indexes_post_load": [],
            "foreign_keys_post_load": [],
            "zero_date_nullable_columns": [],
            "auto_increment": {"columns": []},
        }
        snapshot = {
            "source_schema": "erp_pg_source",
            "binary_storage_required": True,
            "source_grant_policy_id": "erp_pg_source_select_show_view_trigger_v2",
            "source_grants_policy_accepted": True,
            "source_routines_events_zero_verified": True,
            "source_schema_wide_select": True,
            "metadata_visibility": {"views": True, "triggers": True, "routines": False, "events": False},
            "routines": [],
            "events": [],
            "catalog_consistency_check": "PRE_POST_SIGNATURE_MATCH",
            "timestamp_transfer_contract": {"source_session": "UTC", "target_wall_time": "America/Sao_Paulo"},
            "structural_fingerprint_sha256": "a" * 64,
            "tables": [{"name": name, "type": "BASE TABLE", "columns": []} for name in names],
            "source_no_backslash_escapes": False,
        }
        patches = ExitStack()
        patches.enter_context(patch.object(loader, "_require_load_runtime"))
        patches.enter_context(patch.object(loader, "validate_bundle", return_value=(Path("/tmp/bundle"), report, snapshot)))
        patches.enter_context(patch.object(loader, "_source_defaults_path", return_value=Path("/tmp/source.cnf")))
        patches.enter_context(patch.object(loader, "_load_target_config", return_value=config))
        patches.enter_context(patch.object(loader, "_validate_column_mappings", return_value={name: {} for name in names}))
        patches.enter_context(patch.object(loader, "_connect_source", return_value=(source, snapshot)))
        patches.enter_context(patch.object(loader, "_connect_target", return_value=target))
        private_tmp = tempfile.TemporaryDirectory(prefix="p2-loader-test-")
        patches.callback(private_tmp.cleanup)
        patches.enter_context(patch.object(loader, "REPORT_ROOT", Path(private_tmp.name)))
        patches.enter_context(patch.object(loader, "_scan_source_text_policy", return_value=[]))
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
            copied = (copy_side_effect or (lambda *call_args: 2))(*args)
            args[1].statements.append((f"COPY:{args[2]['name']}", True))
            return copied

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
        self.assertEqual(result["zero_date_nullability_results"], [])
        self.assertTrue(result["target_scope"]["legacy_audit_access_revoked"])
        self.assertEqual(target.commits, 1)
        dropped_schemas = [item for item, active in target.statements if "DROP SCHEMA" in item and active]
        self.assertEqual(len(dropped_schemas), 2)
        self.assertTrue(any('DROP SCHEMA IF EXISTS "erp"' in item for item in dropped_schemas))
        self.assertTrue(any('DROP SCHEMA IF EXISTS "legado"' in item for item in dropped_schemas))
        self.assertTrue(all("DROP DATABASE" not in item.upper() for item, _ in target.statements))
        self.assertTrue(all(active for item, active in target.statements if "DROP SCHEMA" in item or item.startswith("SCRIPT:")))
        self.assertEqual(sum(item.startswith("SCRIPT:") for item, _ in target.statements), len(loader.SCRIPT_NAMES))
        statement_names = [item for item, _active in target.statements]
        self.assertLess(statement_names.index("COPY:synthetic_table"), statement_names.index("SCRIPT:05_on_update_triggers.sql"))
        self.assertLess(statement_names.index("COPY:synthetic_table"), statement_names.index("SCRIPT:40_business_triggers.sql"))
        self.assertLess(statement_names.index("SCRIPT:30_post_load_sequences.sql"), statement_names.index("SCRIPT:40_business_triggers.sql"))
        self.assertEqual(reports[0]["status"], "STARTED")
        self.assertEqual(reports[-1]["status"], "LOAD_COMMITTED_PENDING_RECONCILIATION")
        self.assertTrue(source.closed)
        self.assertGreaterEqual(source.rollbacks, 1)
        self.assertFalse(source.snapshot_active)
        self.assertTrue(target.closed)

    def test_copy_error_rolls_back_only_table_and_records_failure(self) -> None:
        def fail_copy(*args):
            error = LoaderError("SYNTHETIC_COPY_FAILURE")
            error.column = "synthetic_column"
            error.source_rows = 2
            raise error

        patches, source, target, reports = self._call_load(copy_side_effect=fail_copy)
        patches.enter_context(patch.object(loader, "_row_count", return_value=0))
        try:
            result = loader.run_load("synthetic-bundle", report_path=Path("/tmp/report.json"))
        finally:
            patches.close()
        self.assertEqual(result["status"], "LOAD_PARTIAL_PENDING_RECONCILIATION")
        self.assertEqual(result["table_failures"], [{
            "table": "synthetic_table", "column": "synthetic_column", "reason": "SYNTHETIC_COPY_FAILURE",
            "source_rows": 2, "target_rows": 0,
        }])
        self.assertEqual(target.commits, 1)
        self.assertGreaterEqual(target.rollbacks, 1)
        self.assertEqual(reports[-1]["status"], "LOAD_PARTIAL_PENDING_RECONCILIATION")
        self.assertTrue(source.closed)
        self.assertGreaterEqual(source.rollbacks, 1)
        self.assertFalse(source.snapshot_active)
        self.assertTrue(target.closed)

    def test_copy_failure_does_not_prevent_later_table_from_loading(self) -> None:
        calls = []

        def fail_first_table(source, target, table, *args):
            calls.append(table["name"])
            if table["name"] == "first_table":
                error = LoaderError("SYNTHETIC_COPY_FAILURE")
                error.column = "synthetic_column"
                error.source_rows = 2
                raise error
            return 3

        patches, source, target, _reports = self._call_load(
            copy_side_effect=fail_first_table,
            table_names=["first_table", "second_table"],
        )
        patches.enter_context(patch.object(loader, "_row_count", side_effect=lambda _target, table_name, *_args: 0 if table_name == "first_table" else 3))
        try:
            result = loader.run_load("synthetic-bundle", report_path=Path("/tmp/report.json"))
        finally:
            patches.close()
        self.assertEqual(calls, ["first_table", "second_table"])
        self.assertEqual(result["status"], "LOAD_PARTIAL_PENDING_RECONCILIATION")
        self.assertEqual(result["tables_loaded"], 1)
        self.assertEqual(result["table_failures"][0]["table"], "first_table")
        self.assertEqual(result["table_counts"][1]["table"], "second_table")
        self.assertEqual(result["table_counts"][1]["status"], "COPIED")
        self.assertGreaterEqual(target.rollbacks, 1)
        self.assertEqual(target.commits, 1)
        self.assertTrue(source.closed)
        self.assertTrue(target.closed)

    def test_rollback_failure_is_written_as_unconfirmed(self) -> None:
        patches, source, target, reports = self._call_load()
        patches.enter_context(patch.object(loader, "_apply_enum_checks", side_effect=LoaderError("SYNTHETIC_ENUM_CHECK_FAILURE")))
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

    def test_failure_report_preserves_temporal_nullability_results(self) -> None:
        patches, source, target, reports = self._call_load()
        temporal_result = [{"table": "synthetic_table", "column": "created_at", "status": "RESTORED_NOT_NULL", "zero_date_count": 0}]
        patches.enter_context(patch.object(loader, "_restore_temporal_not_null", return_value=temporal_result))
        patches.enter_context(patch.object(loader, "_apply_enum_checks", side_effect=LoaderError("SYNTHETIC_ENUM_CHECK_FAILURE")))
        try:
            with self.assertRaisesRegex(LoaderError, "SYNTHETIC_ENUM_CHECK_FAILURE"):
                loader.run_load("synthetic-bundle", report_path=Path("/tmp/report.json"))
        finally:
            patches.close()
        self.assertEqual(reports[-1]["zero_date_nullability_results"], temporal_result)
        self.assertEqual(reports[-1]["status"], "FAILED_ROLLED_BACK")
        self.assertTrue(source.closed)
        self.assertTrue(target.closed)

    def test_source_close_failure_after_commit_blocks_reconciliation(self) -> None:
        patches, source, target, reports = self._call_load()
        source.fail_close = True
        try:
            with self.assertRaisesRegex(LoaderError, "POST_LOAD_CLEANUP_UNCONFIRMED"):
                loader.run_load("synthetic-bundle", report_path=Path("/tmp/report.json"))
        finally:
            patches.close()
        self.assertEqual(reports[-1]["status"], "LOAD_COMMITTED_CLEANUP_UNCONFIRMED")
        self.assertEqual(reports[-1]["failure_code"], "POST_LOAD_CLEANUP_UNCONFIRMED")
        self.assertFalse(reports[-1]["source_cleanup_confirmed"])
        self.assertTrue(target.closed)

    def test_target_close_failure_after_commit_blocks_reconciliation(self) -> None:
        patches, source, target, reports = self._call_load()
        target.fail_close = True
        try:
            with self.assertRaisesRegex(LoaderError, "POST_LOAD_CLEANUP_UNCONFIRMED"):
                loader.run_load("synthetic-bundle", report_path=Path("/tmp/report.json"))
        finally:
            patches.close()
        self.assertEqual(reports[-1]["status"], "LOAD_COMMITTED_CLEANUP_UNCONFIRMED")
        self.assertEqual(reports[-1]["failure_code"], "POST_LOAD_CLEANUP_UNCONFIRMED")
        self.assertFalse(reports[-1]["target_cleanup_confirmed"])
        self.assertTrue(source.closed)


class ReconciliationCleanupTests(unittest.TestCase):
    def _run_with_cleanup_failure(
        self,
        *,
        fail_source_close: bool = False,
        fail_target_close: bool = False,
        failed_load_flag: str | None = None,
        partial_load: bool = False,
        partial_without_failures: bool = False,
    ):
        source = _FakeSource()
        target = _FakeTarget()
        source.fail_close = fail_source_close
        target.fail_close = fail_target_close
        role = "erp_app"
        snapshot = {
            "source_schema": loader.SOURCE_SCHEMA,
            "binary_storage_required": True,
            "tables": [],
            "structural_fingerprint_sha256": "a" * 64,
            "source_grant_policy_id": "erp_pg_source_select_show_view_trigger_v2",
            "source_grants_policy_accepted": True,
            "source_routines_events_zero_verified": True,
            "source_schema_wide_select": True,
            "metadata_visibility": {"views": True, "triggers": True, "routines": False, "events": False},
            "routines": [],
            "events": [],
            "catalog_consistency_check": "PRE_POST_SIGNATURE_MATCH",
            "timestamp_transfer_contract": {"source_session": "UTC", "target_wall_time": "America/Sao_Paulo"},
        }
        bundle_report = {
            "catalog_fingerprint_sha256": "f" * 64,
            "application_role_sha256": hashlib.sha256(role.encode()).hexdigest(),
            "generated_tables": [],
            "auto_increment": {"columns": []},
        }
        load_report = {
            "status": "LOAD_PARTIAL_PENDING_RECONCILIATION" if partial_load else "LOAD_COMMITTED_PENDING_RECONCILIATION",
            "bundle_catalog_fingerprint": "f" * 64,
            "table_failures": ([{"table": "synthetic", "reason": "SYNTHETIC_COPY_FAILURE", "source_rows": 1, "target_rows": 0}] if partial_load and not partial_without_failures else []),
            "source_cleanup_confirmed": failed_load_flag != "source_cleanup_confirmed",
            "target_cleanup_confirmed": failed_load_flag != "target_cleanup_confirmed",
            "nul_spool_cleanup_confirmed": failed_load_flag != "nul_spool_cleanup_confirmed",
        }
        reports = []

        class Guard:
            def start_monitor(self, connection): pass
            def sample(self): pass
            def set_connection(self, connection): pass
            def sample_if_due(self): pass
            def _raise_if_stopped(self): pass
            def stop_monitor(self): pass

        with patch.object(loader, "validate_bundle", return_value=(Path("/tmp/bundle"), bundle_report, snapshot)), \
             patch.object(loader, "_read_reconciliation_report", return_value=load_report), \
             patch.object(loader, "_source_defaults_path", return_value=Path("/tmp/source.cnf")), \
             patch.object(loader, "_load_target_config", return_value={"application_user": role, "dbname": "erp_pg_dev"}), \
             patch.object(loader, "_validate_column_mappings", return_value={}), \
             patch.object(loader, "_validated_identity_plan", return_value={}), \
             patch.object(loader, "_connect_source", return_value=(source, snapshot)), \
             patch.object(loader, "_validate_source_snapshot"), \
             patch.object(loader, "_connect_target", return_value=target), \
             patch.object(loader, "_MemoryGuard", Guard), \
             patch("tools.migracao_pg.reconcile.reconcile", return_value={"status": "DIVERGED" if partial_load and not partial_without_failures else "RECONCILED"}), \
             patch.object(loader, "_replace_report", side_effect=lambda payload, path: reports.append(dict(payload))):
            result = loader.run_reconcile("synthetic-bundle", None, Path("/tmp/report.json"))
        return result, reports, source, target

    def test_reconcile_rejects_load_without_confirmed_cleanup_before_connecting(self) -> None:
        for failed_flag in (
            "source_cleanup_confirmed",
            "target_cleanup_confirmed",
            "nul_spool_cleanup_confirmed",
        ):
            with self.subTest(failed_flag=failed_flag):
                result, reports, source, target = self._run_with_cleanup_failure(failed_load_flag=failed_flag)
                self.assertEqual(result["status"], "BLOCKED")
                self.assertEqual(result["failure_code"], "LOAD_CLEANUP_UNCONFIRMED")
                self.assertEqual(reports[-1]["status"], "LOAD_COMMITTED_RECONCILIATION_BLOCKED")
                self.assertFalse(source.closed)
                self.assertFalse(target.closed)

    def test_partial_load_reconciliation_remains_partial_and_diverged(self) -> None:
        result, reports, source, target = self._run_with_cleanup_failure(partial_load=True)
        self.assertEqual(result["status"], "DIVERGED")
        self.assertEqual(reports[-1]["status"], "LOAD_PARTIAL_RECONCILIATION_DIVERGED")
        self.assertEqual(reports[-1]["reconciliation_previous_status"], "LOAD_PARTIAL_PENDING_RECONCILIATION")
        self.assertEqual(reports[-1]["table_failures"][0]["reason"], "SYNTHETIC_COPY_FAILURE")
        self.assertTrue(source.closed)
        self.assertTrue(target.closed)

    def test_wrapper_never_accepts_partial_status_without_failure_evidence(self) -> None:
        result, reports, source, target = self._run_with_cleanup_failure(
            partial_load=True,
            partial_without_failures=True,
        )
        self.assertEqual(result["status"], "BLOCKED")
        self.assertEqual(result["failure_code"], "PARTIAL_LOAD_CANNOT_BE_ACCEPTED")
        self.assertEqual(reports[-1]["status"], "LOAD_PARTIAL_RECONCILIATION_BLOCKED")
        self.assertTrue(source.closed)
        self.assertTrue(target.closed)

    def test_source_close_failure_blocks_reconciled_result(self) -> None:
        result, reports, source, target = self._run_with_cleanup_failure(fail_source_close=True)
        self.assertEqual(result["status"], "BLOCKED")
        self.assertEqual(result["failure_code"], "RECONCILIATION_CLEANUP_FAILED")
        self.assertIn("SOURCE_CLOSE", result["cleanup_failures"])
        self.assertEqual(reports[-1]["status"], "LOAD_COMMITTED_RECONCILIATION_BLOCKED")
        self.assertTrue(source.closed)
        self.assertTrue(target.closed)

    def test_target_close_failure_blocks_reconciled_result(self) -> None:
        result, reports, source, target = self._run_with_cleanup_failure(fail_target_close=True)
        self.assertEqual(result["status"], "BLOCKED")
        self.assertEqual(result["failure_code"], "RECONCILIATION_CLEANUP_FAILED")
        self.assertIn("TARGET_CLOSE", result["cleanup_failures"])
        self.assertEqual(reports[-1]["status"], "LOAD_COMMITTED_RECONCILIATION_BLOCKED")
        self.assertTrue(source.closed)
        self.assertTrue(target.closed)


class ReconciliationRetryGateTests(unittest.TestCase):
    def _eligible_report(self):
        return {
            "profile": "development",
            "source_schema": "erp_pg_source",
            "target_database": "erp_pg_dev",
            "target_schema": "erp",
            "legacy_schema": "legado",
            "status": "LOAD_COMMITTED_RECONCILIATION_BLOCKED",
            "reconciliation_previous_status": "LOAD_COMMITTED_PENDING_RECONCILIATION",
            "reconciliation": {"status": "BLOCKED", "failure_code": "DATABASE_QUERY_FAILED"},
            "table_failures": [],
            "tables_loaded": 2,
            "rows_loaded": 17,
            "table_counts": [
                {"table": "one", "source_rows": 8, "target_rows": 8, "status": "COPIED"},
                {"table": "two", "source_rows": 9, "target_rows": 9, "status": "COPIED"},
            ],
            "source_cleanup_confirmed": True,
            "target_cleanup_confirmed": True,
            "nul_spool_cleanup_confirmed": True,
        }

    def test_only_complete_committed_query_failure_is_retryable_once(self) -> None:
        report = self._eligible_report()
        bundle_report = {"generated_tables": ["one", "two"]}
        self.assertTrue(loader._retryable_committed_reconciliation_query_failure(report, bundle_report, "development"))
        report["reconciliation_retry_used"] = True
        self.assertFalse(loader._retryable_committed_reconciliation_query_failure(report, bundle_report, "development"))

    def test_legacy_report_without_legacy_schema_requires_historical_generator_version(self) -> None:
        report = self._eligible_report()
        report.pop("legacy_schema")
        self.assertFalse(loader._retryable_committed_reconciliation_query_failure(report, {"generated_tables": ["one", "two"]}, "development"))
        report["generator_version"] = loader.PREVIOUS_LOAD_GENERATOR_VERSION
        self.assertTrue(loader._retryable_committed_reconciliation_query_failure(report, {"generated_tables": ["one", "two"]}, "development"))

    def test_partial_load_wrong_failure_profile_or_cleanup_are_not_retryable(self) -> None:
        bundle_report = {"generated_tables": ["one", "two"]}
        mutations = (
            ("table_failures", [{"table": "one"}]),
            ("tables_loaded", 1),
            ("rows_loaded", "17"),
            ("target_cleanup_confirmed", False),
            ("source_schema", "erpcondominios_stage_20261114"),
            ("target_database", "erpcondominios_pg"),
            ("target_schema", "public"),
            ("legacy_schema", "public"),
            ("reconciliation_previous_status", "LOAD_PARTIAL_PENDING_RECONCILIATION"),
            ("reconciliation_retry_used", True),
            ("status", "LOAD_COMMITTED_RECONCILED"),
            ("profile", "cutover"),
        )
        for key, value in mutations:
            with self.subTest(key=key):
                report = self._eligible_report()
                if key == "profile":
                    profile = value
                else:
                    report[key] = value
                    profile = "development"
                self.assertFalse(loader._retryable_committed_reconciliation_query_failure(report, bundle_report, profile))

    def test_only_database_query_failure_code_is_retryable(self) -> None:
        report = self._eligible_report()
        report["reconciliation"]["failure_code"] = "IDENTITY_SEQUENCE_EXHAUSTED"
        self.assertFalse(loader._retryable_committed_reconciliation_query_failure(report, {"generated_tables": ["one", "two"]}, "development"))

    def test_retry_evidence_validation_is_local_and_aggregate_only(self) -> None:
        report = self._eligible_report()
        report["bundle_catalog_fingerprint"] = "a" * 64
        bundle_report = {"generated_tables": ["one", "two"], "catalog_fingerprint_sha256": "a" * 64}
        with patch.object(loader, "validate_bundle", return_value=(None, bundle_report, {})), \
            patch.object(loader, "_read_reconciliation_report", return_value=report), \
            patch.object(loader, "_read_retry_selection", return_value={"verified": True}) as read_selection, \
            patch.object(loader, "_connect_source") as connect_source, \
            patch.object(loader, "_connect_target") as connect_target:
            result = loader.validate_reconcile_retry("synthetic", Path("/unused/report.json"))
        self.assertEqual(
            result,
            {
                "status": "RECONCILIATION_RETRY_READY", "profile": "development",
                "source_schema": "erp_pg_source", "target_database": "erp_pg_dev",
                "tables": 2, "rows": 17, "retry_unused": True, "cleanup_confirmed": True,
            },
        )
        connect_source.assert_not_called()
        connect_target.assert_not_called()
        read_selection.assert_called_once()

    def test_terminal_and_partial_reports_cannot_reenter_normal_reconciliation(self) -> None:
        bundle_report = {"generated_tables": ["one", "two"]}
        terminal_statuses = (
            "LOAD_COMMITTED_RECONCILED",
            "LOAD_COMMITTED_RECONCILIATION_DIVERGED",
            "LOAD_COMMITTED_RECONCILIATION_BLOCKED",
            "LOAD_PARTIAL_RECONCILIATION_DIVERGED",
            "LOAD_PARTIAL_RECONCILIATION_BLOCKED",
        )
        for status in terminal_statuses:
            with self.subTest(status=status):
                report = self._eligible_report()
                report["status"] = status
                with patch.object(loader, "validate_bundle", return_value=(None, bundle_report, {})), \
                    patch.object(loader, "_read_reconciliation_report", return_value=report), \
                    patch.object(loader, "_connect_source") as connect_source, \
                    patch.object(loader, "_connect_target") as connect_target:
                    with self.assertRaises(loader.LoaderError) as error:
                        loader.run_reconcile("synthetic-bundle", None, Path("/unused/report.json"), "development")
                self.assertEqual(error.exception.code, "LOAD_COMMIT_EVIDENCE_INVALID")
                connect_source.assert_not_called()
                connect_target.assert_not_called()

    def test_read_only_retry_flag_rejects_completed_or_diverged_report(self) -> None:
        bundle_report = {"generated_tables": ["one", "two"]}
        for status in ("LOAD_COMMITTED_RECONCILED", "LOAD_COMMITTED_RECONCILIATION_DIVERGED"):
            with self.subTest(status=status):
                report = self._eligible_report()
                report["status"] = status
                with patch.object(loader, "validate_bundle", return_value=(None, bundle_report, {})), \
                    patch.object(loader, "_read_reconciliation_report", return_value=report), \
                    patch.object(loader, "_connect_source") as connect_source, \
                    patch.object(loader, "_connect_target") as connect_target:
                    with self.assertRaises(loader.LoaderError) as error:
                        loader.run_reconcile(
                            "synthetic-bundle", None, Path("/unused/report.json"), "development",
                            retry_read_only=True,
                        )
                self.assertEqual(error.exception.code, "RECONCILIATION_RETRY_NOT_ALLOWED")
                connect_source.assert_not_called()
                connect_target.assert_not_called()

    def test_retry_flag_is_rejected_for_non_reconcile_command(self) -> None:
        with redirect_stderr(io.StringIO()):
            status = loader.main(["preflight", "--bundle-name", "synthetic", "--retry-read-only"])
        self.assertEqual(status, 2)


class PreflightPolicyGateTests(unittest.TestCase):
    def _inputs(self):
        snapshot = {
            "tables": [
                {"name": "fixture_text", "type": "BASE TABLE", "columns": [
                    {"name": "descricao", "data_type": "varchar"},
                ]},
                {"name": "tenant_arquivos", "type": "BASE TABLE", "columns": [
                    {"name": "conteudo", "data_type": "longblob"},
                ]},
            ] + [
                {"name": f"fixture_{index}", "type": "BASE TABLE", "columns": []}
                for index in range(loader.EXPECTED_SOURCE_TABLE_COUNT - 2)
            ],
        }
        text = [{
            "table": "fixture_text", "column": "descricao", "scan_status": "SCANNED",
            "detector_disagreement": False,
            "cells_expected": 1, "cells_scanned": 1,
            "method_a_python_string_cells_with_nul": 0, "method_a_decode_failures": 0,
            "method_b_cells_with_nul": 0, "python_cells_with_nul": 0, "cells_with_nul": 0,
            "invalid_charset_cells_with_nul": 0, "non_utf8_cells_with_nul": 0, "invalid_utf8_cells_after_nul": 0,
            "invalid_declared_charset_cells_after_nul": 0,
        }]
        binary = [{
            "table": "tenant_arquivos", "column": "conteudo", "scan_status": "SCANNED",
            "cells_expected": 1, "cells_scanned": 1,
            "method_b_cells_with_nul": 1, "python_cells_with_nul": 1,
            "nul_bytes": 1, "binary_bytes_scanned": 3,
            "storage_policy_id": loader.BINARY_STORAGE_POLICY_ID,
            "storage_encoding": "base64", "nul_policy": "preserve_exact_bytes",
        }]
        counts = [
            {"table": "fixture_text", "rows": 0},
            {"table": "tenant_arquivos", "rows": 1},
        ] + [
            {"table": f"fixture_{index}", "rows": 0}
            for index in range(loader.EXPECTED_SOURCE_TABLE_COUNT - 2)
        ]
        target = {
            "database_matches": True, "local_endpoint": True, "migrator_login_non_elevated": True,
            "schema_create_privilege": True, "existing_schemas_owned_or_absent": True,
            "application_role_separate": True, "application_role_non_elevated": True,
            "migrator_role_memberships": 0, "application_role_memberships": 0,
            "target_schema_present": True,
        }
        return text, binary, counts, snapshot, target

    def test_complete_preflight_evidence_accepts_allowlisted_base64_and_nul(self) -> None:
        self.assertIsNone(loader._preflight_policy_gate(*self._inputs()))

    def test_missing_text_finding_fails_closed(self) -> None:
        text, binary, counts, snapshot, target = self._inputs()
        with self.assertRaisesRegex(LoaderError, "PREFLIGHT_TEXT_POLICY_MANIFEST_MISMATCH"):
            loader._preflight_policy_gate([], binary, counts, snapshot, target)

    def test_source_counts_with_same_size_but_wrong_table_name_fail_closed(self) -> None:
        text, binary, counts, snapshot, target = self._inputs()
        counts[0] = {"table": "fixture_wrong", "rows": 0}
        with self.assertRaisesRegex(LoaderError, "PREFLIGHT_SOURCE_TABLE_MANIFEST_MISMATCH"):
            loader._preflight_policy_gate(text, binary, counts, snapshot, target)

    def test_binary_detector_disagreement_fails_closed(self) -> None:
        text, binary, counts, snapshot, target = self._inputs()
        binary[0]["method_b_cells_with_nul"] = 0
        with self.assertRaisesRegex(LoaderError, "PREFLIGHT_BINARY_SCAN_INVALID"):
            loader._preflight_policy_gate(text, binary, counts, snapshot, target)

    def test_target_scope_invalid_fails_closed(self) -> None:
        text, binary, counts, snapshot, target = self._inputs()
        target["application_role_non_elevated"] = False
        with self.assertRaisesRegex(LoaderError, "PREFLIGHT_TARGET_SCOPE_INVALID"):
            loader._preflight_policy_gate(text, binary, counts, snapshot, target)


if __name__ == "__main__":
    unittest.main()
