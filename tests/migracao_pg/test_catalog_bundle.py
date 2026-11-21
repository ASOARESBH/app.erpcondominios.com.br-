from __future__ import annotations

import json
import copy
import inspect
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from tools.migracao_pg import catalog_bundle
from tools.migracao_pg.catalog import (
    CATALOG_VERSION,
    SOURCE_GRANT_POLICY_ID,
    SOURCE_SCHEMA,
    TIME_RANGE_POLICY_ID,
    _collector_fingerprint,
    _structural_fingerprint,
)
from tools.migracao_pg.ddl import canonical_fingerprint
from tools.migracao_pg.generate_ddl import GENERATOR_COMPONENTS
from tools.migracao_pg.type_mapping import BINARY_STORAGE_POLICY_ID


def synthetic_snapshot() -> dict:
    tables = [
        {"name": f"fixture_{index:03d}", "type": "BASE TABLE", "columns": []}
        for index in range(catalog_bundle.EXPECTED_TABLES - 1)
    ]
    tables.append({
        "name": "tenant_arquivos",
        "type": "BASE TABLE",
        "columns": [{
            "name": "conteudo",
            "data_type": "longblob",
            "column_type": "longblob",
            "binary_storage_encoding": "base64",
        }],
    })
    snapshot = {
        "catalog_version": CATALOG_VERSION,
        "binary_storage_policy_id": BINARY_STORAGE_POLICY_ID,
        "binary_storage_required": True,
        "time_range_policy_id": TIME_RANGE_POLICY_ID,
        "collector_code_sha256": _collector_fingerprint(),
        "source_schema": SOURCE_SCHEMA,
        "catalog_consistency_check": "PRE_POST_SIGNATURE_MATCH",
        "source_grant_policy_id": SOURCE_GRANT_POLICY_ID,
        "source_grants_policy_accepted": True,
        "source_schema_wide_select": True,
        "source_routines_events_zero_verified": True,
        "unresolved_role_grants": False,
        "metadata_visibility": {
            "views": True,
            "triggers": True,
            "routines": False,
            "events": False,
        },
        "tables": tables,
        "foreign_keys": [{} for _ in range(catalog_bundle.EXPECTED_FOREIGN_KEYS)],
        "triggers": [{} for _ in range(catalog_bundle.EXPECTED_TRIGGERS)],
        "views": [],
        "routines": [],
        "events": [],
        "review_items": [],
    }
    snapshot["structural_fingerprint_sha256"] = _structural_fingerprint(snapshot)
    snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
    return snapshot


def synthetic_bundle_report() -> dict:
    return {
        "source_schema": SOURCE_SCHEMA,
        "application_role": "erp_app",
        "binary_storage": copy.deepcopy(catalog_bundle.EXPECTED_BINARY_REPORT),
        "load_executable_complete": True,
        "executable_complete": True,
        "review_items": [],
        "blocked_tables": [],
        "unsupported_indexes": [],
        "deferred_indexes": [],
        "translated_business_triggers": [{} for _ in range(catalog_bundle.EXPECTED_TRIGGERS)],
        "foreign_keys": {
            "generated_not_valid": catalog_bundle.EXPECTED_FOREIGN_KEYS,
            "unsupported": [],
            "deferred": [],
        },
    }


class CatalogBundleValidationTests(unittest.TestCase):
    def test_loader_and_reconciler_are_generator_fingerprint_components(self) -> None:
        self.assertIn("loader.py", GENERATOR_COMPONENTS)
        self.assertIn("reconcile.py", GENERATOR_COMPONENTS)
        self.assertIn("catalog_bundle.py", GENERATOR_COMPONENTS)

    def test_committed_load_rebind_is_bound_to_exact_b15_release(self) -> None:
        from tools.migracao_pg import loader

        self.assertEqual(catalog_bundle.PREVIOUS_LOAD_RELEASE_SHA, "b15ecd55cbf8dcfddd63d7a0d4e576b9620ed991")
        self.assertEqual(loader.PREVIOUS_LOAD_RELEASE_SHA, catalog_bundle.PREVIOUS_LOAD_RELEASE_SHA)
        self.assertEqual(catalog_bundle.PREVIOUS_LOAD_GENERATOR_VERSION, "2.7.0")
        self.assertEqual(catalog_bundle.LATEST_LOAD_REPORT_NAME, "rebuild-20261121T103605Z-586388.json")
        rebound_source = inspect.getsource(catalog_bundle.rebind_committed_load_bundle)
        capture_source = inspect.getsource(catalog_bundle.capture_catalog_bundle)
        self.assertIn("_mark_reconciliation_only(bundle_path)", rebound_source)
        self.assertNotIn("_mark_reconciliation_only(bundle_path)", capture_source)

    def test_rebind_report_path_is_restricted_to_latest_load_report(self) -> None:
        accepted = Path("/var/backups/erpcondominios/p2-runs/rebuild-20261121T103605Z-586388.json")
        rejected = (
            Path("/var/backups/erpcondominios/p2-runs/rebuild-20261120T025127Z-520805.json"),
            Path("/tmp/rebuild-20261121T103605Z-586388.json"),
        )
        self.assertTrue(catalog_bundle._committed_load_report_path_allowed(accepted))
        for path in rejected:
            with self.subTest(path=path.name):
                self.assertFalse(catalog_bundle._committed_load_report_path_allowed(path))

    def test_committed_load_evidence_requires_exact_complete_development_load(self) -> None:
        from tools.migracao_pg.catalog_bundle import _validate_committed_load_evidence

        report = {
            "profile": "development",
            "source_schema": "erp_pg_source",
            "target_database": "erp_pg_dev",
            "target_schema": "erp",
            "legacy_schema": "legado",
            "status": "LOAD_COMMITTED_RECONCILIATION_DIVERGED",
            "generator_version": "2.7.0",
            "bundle_catalog_fingerprint": "a" * 64,
            "bundle_provenance": {
                "bundle_name": "catalog-aaaaaaaaaaaa-collation-bbbbbbbb-role-cccccccc-code-dddddddd",
                "generator_version": "2.7.0",
                "generator_code_sha256": "d" * 64,
                "catalog_fingerprint_sha256": "a" * 64,
                "collation_overrides_sha256": "e" * 64,
                "application_role_sha256": "f" * 64,
                "generated_scripts_sha256": {"00_tables.sql": "1" * 64},
            },
            "reconciliation_previous_status": "LOAD_COMMITTED_PENDING_RECONCILIATION",
            "reconciliation": {"status": "DIVERGED", "failure_code": None},
            "table_failures": [],
            "tables_loaded": 276,
            "rows_loaded": 276,
            "source_cleanup_confirmed": True,
            "target_cleanup_confirmed": True,
            "nul_spool_cleanup_confirmed": True,
            "table_counts": [
                {"table": f"table_{index}", "status": "COPIED", "source_rows": 1, "target_rows": 1}
                for index in range(276)
            ],
        }
        self.assertEqual(len(_validate_committed_load_evidence(report)), 276)
        legacy_report = copy.deepcopy(report)
        legacy_report.pop("legacy_schema")
        legacy_report["generator_version"] = "2.7.0"
        self.assertEqual(len(_validate_committed_load_evidence(legacy_report)), 276)
        legacy_report["generator_version"] = "2.8.0"
        with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "COMMITTED_LOAD_EVIDENCE_NOT_ALLOWLISTED"):
            _validate_committed_load_evidence(legacy_report)
        for key, value in (
            ("status", "LOAD_COMMITTED_RECONCILED"),
            ("target_database", "erpcondominios_pg"),
            ("reconciliation_retry_used", True),
            ("status", "LOAD_COMMITTED_RECONCILIATION_BLOCKED"),
        ):
            changed = copy.deepcopy(report)
            changed[key] = value
            with self.subTest(key=key):
                with self.assertRaises(catalog_bundle.CatalogBundleError):
                    _validate_committed_load_evidence(changed)

    def test_committed_load_evidence_rejects_inconsistent_per_table_counts(self) -> None:
        from tools.migracao_pg.catalog_bundle import _validate_committed_load_evidence

        report = {
            "profile": "development", "source_schema": "erp_pg_source", "target_database": "erp_pg_dev",
            "target_schema": "erp", "legacy_schema": "legado", "status": "LOAD_COMMITTED_RECONCILIATION_DIVERGED",
            "generator_version": "2.7.0", "bundle_catalog_fingerprint": "a" * 64,
            "bundle_provenance": {
                "bundle_name": "catalog-aaaaaaaaaaaa-collation-bbbbbbbb-role-cccccccc-code-dddddddd",
                "generator_version": "2.7.0", "generator_code_sha256": "d" * 64,
                "catalog_fingerprint_sha256": "a" * 64, "collation_overrides_sha256": "e" * 64,
                "application_role_sha256": "f" * 64,
                "generated_scripts_sha256": {"00_tables.sql": "1" * 64},
            },
            "reconciliation_previous_status": "LOAD_COMMITTED_PENDING_RECONCILIATION",
            "reconciliation": {"status": "DIVERGED", "failure_code": None},
            "table_failures": [], "tables_loaded": 276, "rows_loaded": 276,
            "source_cleanup_confirmed": True, "target_cleanup_confirmed": True, "nul_spool_cleanup_confirmed": True,
            "table_counts": [
                {"table": f"table_{index}", "status": "COPIED", "source_rows": 1, "target_rows": 1}
                for index in range(276)
            ],
        }
        report["table_counts"][0]["target_rows"] = 0
        with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "COMMITTED_LOAD_COUNTS_INVALID"):
            _validate_committed_load_evidence(report)

    def test_committed_load_counts_require_exact_unique_bundle_table_set(self) -> None:
        counts = [{"table": f"table_{index}"} for index in range(catalog_bundle.EXPECTED_TABLES)]
        generated = [f"table_{index}" for index in range(catalog_bundle.EXPECTED_TABLES)]
        self.assertTrue(catalog_bundle._load_counts_match_bundle(counts, generated))
        counts[0] = {"table": "different_table"}
        self.assertFalse(catalog_bundle._load_counts_match_bundle(counts, generated))

    def test_capture_does_not_temporarily_mutate_global_bundle_root(self) -> None:
        source = inspect.getsource(catalog_bundle.capture_catalog_bundle)
        self.assertNotIn("loader_module.BUNDLE_ROOT =", source)

    def test_rebind_accepts_same_semantics_with_new_provenance_header_only(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            previous = Path(directory) / "previous"
            rebound = Path(directory) / "rebound"
            previous.mkdir()
            rebound.mkdir()
            script_name = "00_tables.sql"
            header = "-- LOAD ARTIFACT: DO NOT APPLY unless report.json has executable_complete=true and all migration reviews are approved.\n"
            previous_body = "CREATE TABLE erp.synthetic (id BIGINT);\n"
            (previous / script_name).write_text(
                header + "-- Generator code SHA-256: " + "a" * 64 + "\n" + previous_body,
                encoding="utf-8",
            )
            (rebound / script_name).write_text(
                header + "-- Generator code SHA-256: " + "b" * 64 + "\n" + previous_body,
                encoding="utf-8",
            )
            common = {"generated_tables": ["synthetic"], "generator_version": "2.7.0"}
            (previous / "report.json").write_text(json.dumps({
                **common, "generator_code_sha256": "a" * 64, "snapshot_file_sha256": "c" * 64,
                "generated_scripts_sha256": {script_name: "d" * 64},
            }), encoding="utf-8")
            (rebound / "report.json").write_text(json.dumps({
                **common, "generator_code_sha256": "b" * 64, "snapshot_file_sha256": "e" * 64,
                "generated_scripts_sha256": {script_name: "f" * 64},
            }), encoding="utf-8")
            self.assertTrue(catalog_bundle._bundle_semantics_match(previous, rebound))

    def test_rebind_rejects_sql_body_or_semantic_report_drift(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            previous = Path(directory) / "previous"
            rebound = Path(directory) / "rebound"
            previous.mkdir()
            rebound.mkdir()
            script_name = "00_tables.sql"
            header = "-- LOAD ARTIFACT: DO NOT APPLY unless report.json has executable_complete=true and all migration reviews are approved.\n"
            (previous / script_name).write_text(header + "-- Generator code SHA-256: " + "a" * 64 + "\nCREATE TABLE erp.synthetic (id BIGINT);\n", encoding="utf-8")
            (rebound / script_name).write_text(header + "-- Generator code SHA-256: " + "b" * 64 + "\nCREATE TABLE erp.synthetic (id NUMERIC);\n", encoding="utf-8")
            common = {"generated_tables": ["synthetic"], "generator_version": "2.7.0"}
            (previous / "report.json").write_text(json.dumps({
                **common, "generator_code_sha256": "a" * 64, "snapshot_file_sha256": "c" * 64,
                "generated_scripts_sha256": {script_name: "d" * 64},
            }), encoding="utf-8")
            (rebound / "report.json").write_text(json.dumps({
                **common, "generator_code_sha256": "b" * 64, "snapshot_file_sha256": "e" * 64,
                "generated_scripts_sha256": {script_name: "f" * 64},
            }), encoding="utf-8")
            self.assertFalse(catalog_bundle._bundle_semantics_match(previous, rebound))
            rebound_report = json.loads((rebound / "report.json").read_text(encoding="utf-8"))
            rebound_report["generator_version"] = "2.8.0"
            (rebound / "report.json").write_text(json.dumps(rebound_report), encoding="utf-8")
            (rebound / script_name).write_text(header + "-- Generator code SHA-256: " + "b" * 64 + "\nCREATE TABLE erp.synthetic (id BIGINT);\n", encoding="utf-8")
            self.assertFalse(catalog_bundle._bundle_semantics_match(previous, rebound))

    def test_exact_v12_source_manifest_and_catalog_counts_are_accepted(self) -> None:
        self.assertEqual(
            catalog_bundle._validate_catalog_snapshot(synthetic_snapshot()),
            {
                "tables": 276,
                "foreign_keys": 11,
                "triggers": 7,
                "views": 0,
                "routines": 0,
                "events": 0,
                "reviews": 0,
            },
        )

    def test_catalog_rejects_missing_binary_storage_marker(self) -> None:
        snapshot = synthetic_snapshot()
        snapshot["binary_storage_required"] = False
        snapshot["structural_fingerprint_sha256"] = _structural_fingerprint(snapshot)
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "BINARY_STORAGE_POLICY_REQUIRED"):
            catalog_bundle._validate_catalog_snapshot(snapshot)

    def test_catalog_rejects_non_boolean_metadata_visibility(self) -> None:
        snapshot = synthetic_snapshot()
        snapshot["metadata_visibility"]["views"] = 1
        snapshot["structural_fingerprint_sha256"] = _structural_fingerprint(snapshot)
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "METADATA_VISIBILITY_MISMATCH"):
            catalog_bundle._validate_catalog_snapshot(snapshot)

    def test_catalog_rejects_nonallowlisted_payload_encoding(self) -> None:
        snapshot = synthetic_snapshot()
        snapshot["tables"][-1]["columns"][0]["binary_storage_encoding"] = "bytea"
        snapshot["structural_fingerprint_sha256"] = _structural_fingerprint(snapshot)
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "BINARY_STORAGE_SOURCE_MANIFEST_INVALID"):
            catalog_bundle._validate_catalog_snapshot(snapshot)

    def test_catalog_rejects_invalid_structural_fingerprint(self) -> None:
        snapshot = synthetic_snapshot()
        snapshot["structural_fingerprint_sha256"] = "0" * 64
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "CATALOG_STRUCTURAL_FINGERPRINT_MISMATCH"):
            catalog_bundle._validate_catalog_snapshot(snapshot)

    def test_catalog_rejects_nonzero_review_count(self) -> None:
        snapshot = synthetic_snapshot()
        snapshot["review_items"] = [{"code": "SYNTHETIC_REVIEW"}]
        snapshot["structural_fingerprint_sha256"] = _structural_fingerprint(snapshot)
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "SOURCE_CATALOG_COUNTS_NOT_ALLOWLISTED"):
            catalog_bundle._validate_catalog_snapshot(snapshot)

    def test_bundle_requires_exact_base64_text_c_manifest(self) -> None:
        self.assertEqual(catalog_bundle._validate_bundle_report(synthetic_bundle_report())["reviews"], 0)
        report = synthetic_bundle_report()
        report["binary_storage"]["columns"][0]["encoding"] = "bytea"
        with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "BUNDLE_BINARY_STORAGE_MANIFEST_MISMATCH"):
            catalog_bundle._validate_bundle_report(report)

    def test_bundle_with_review_cannot_pass_gate(self) -> None:
        report = synthetic_bundle_report()
        report["review_items"] = [{"code": "SYNTHETIC_REVIEW"}]
        with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "BUNDLE_REQUIRES_REVIEW"):
            catalog_bundle._validate_bundle_report(report)

    def test_bundle_with_unsupported_foreign_key_is_rejected(self) -> None:
        report = synthetic_bundle_report()
        report["foreign_keys"]["unsupported"] = [{"code": "SYNTHETIC_UNSUPPORTED"}]
        with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "BUNDLE_FOREIGN_KEY_UNSUPPORTED"):
            catalog_bundle._validate_bundle_report(report)

    def test_generator_summary_requires_matching_catalog_and_role_fingerprints(self) -> None:
        snapshot = {"fingerprint_sha256": "a" * 64}
        summary = {
            "catalog_fingerprint_sha256": "a" * 64,
            "application_role_sha256": catalog_bundle.hashlib.sha256(b"erp_app").hexdigest(),
        }
        catalog_bundle._validate_generator_summary(summary, snapshot)
        summary["application_role_sha256"] = "0" * 64
        with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "DDL_GENERATION_PROVENANCE_MISMATCH"):
            catalog_bundle._validate_generator_summary(summary, snapshot)

    def test_memory_guard_refuses_to_start_child_below_1536_mib(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            log = Path(directory) / "stage.log"
            with patch.object(catalog_bundle, "_available_memory_kib", return_value=1_572_863), \
                patch.object(catalog_bundle.subprocess, "Popen") as popen:
                with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "MEMORY_GUARD_BELOW_1536_MIB"):
                    catalog_bundle._run_stage(["/bin/true"], log, "catalog")
            popen.assert_not_called()
            self.assertFalse(log.exists())

    def test_rebuild_exposes_only_development_capture_and_preserves_guards(self) -> None:
        script = Path("tools/migracao_pg/rebuild.sh").read_text(encoding="utf-8")
        self.assertIn("development catalog-bundle", script)
        self.assertIn("SOURCE_DEFAULTS_FILE='/etc/erpcondominios/secrets/pg_reader.cnf'", script)
        self.assertIn("ERP_P2_ROOT_LAUNCHER_VERIFIED=1", script)
        self.assertIn("/usr/bin/nice -n 19 /usr/bin/ionice -c 3", script)
        self.assertIn("tools.migracao_pg.catalog_bundle", script)
        self.assertIn("BUNDLE_REQUIRES_REVIEW", script)
        self.assertIn("CATALOG_BUNDLE_SELECTION_REQUIRED", script)
        self.assertIn("BUNDLE_SELECTION_MISMATCH", script)
        self.assertIn("development catalog-rebind", script)
        self.assertIn("CATALOG_BUNDLE_REBIND_FAILED", script)
        self.assertIn("1_572_864", Path("tools/migracao_pg/catalog_bundle.py").read_text(encoding="utf-8"))
        self.assertIn("development", script)
        self.assertIn("cutover", script)

    def test_capture_cli_does_not_accept_caller_supplied_paths(self) -> None:
        with patch.object(catalog_bundle.sys, "argv", ["catalog_bundle.py", "--defaults-file", "/tmp/elsewhere"]):
            with patch("builtins.print") as output:
                self.assertEqual(catalog_bundle.main(), 64)
        output.assert_called_once()
        self.assertIn("CATALOG_BUNDLE_ERROR=ARGUMENTS_NOT_ALLOWED", output.call_args.args[0])

    def test_rebind_cli_returns_only_its_sanitized_summary(self) -> None:
        result = {
            "status": "CATALOG_BUNDLE_REBOUND", "source_schema": "erp_pg_source", "catalog_version": 12,
            "bundle_executable_complete": True, "bundle_load_executable_complete": True,
            "selection_private": True, "database_connections": 0,
        }
        with patch.object(catalog_bundle.sys, "argv", ["catalog_bundle.py", "/private/report.json"]), \
            patch.object(catalog_bundle, "rebind_committed_load_bundle", return_value=result), \
            patch("builtins.print") as output:
            self.assertEqual(catalog_bundle.main(), 0)
        self.assertEqual(json.loads(output.call_args.args[0]), result)

    def test_selection_marker_cannot_be_overwritten(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            marker = Path(directory) / "selection.json"
            marker.write_text("existing\n", encoding="ascii")
            with self.assertRaisesRegex(catalog_bundle.CatalogBundleError, "BUNDLE_SELECTION_ALREADY_EXISTS"):
                catalog_bundle._atomic_selection(marker, "a" * 40, "catalog-" + "a" * 12 + "-collation-" + "b" * 8 + "-role-" + "c" * 8 + "-code-" + "d" * 8)
            self.assertEqual(marker.read_text(encoding="ascii"), "existing\n")

    def test_rebuild_rejects_cutover_capture_before_environment_checks(self) -> None:
        result = subprocess.run(
            ["tools/migracao_pg/rebuild.sh", "cutover", "catalog-bundle"],
            cwd=Path.cwd(),
            env={"PATH": "/usr/bin:/bin"},
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertEqual(result.returncode, 64)
        self.assertIn("Uso:", result.stderr)

    def test_rebuild_rejects_cutover_rebind_and_missing_report_before_environment_checks(self) -> None:
        for args in (
            ["tools/migracao_pg/rebuild.sh", "cutover", "catalog-rebind", "/tmp/report.json"],
            ["tools/migracao_pg/rebuild.sh", "development", "catalog-rebind"],
        ):
            with self.subTest(args=args[:3]):
                result = subprocess.run(
                    args,
                    cwd=Path.cwd(),
                    env={"PATH": "/usr/bin:/bin"},
                    capture_output=True,
                    text=True,
                    check=False,
                )
                self.assertEqual(result.returncode, 64)
                self.assertIn("Uso:", result.stderr)

    def test_rebuild_accepts_rebind_arity_and_rejects_before_database_work_when_non_root(self) -> None:
        result = subprocess.run(
            [
                "tools/migracao_pg/rebuild.sh", "development", "catalog-rebind",
                "/var/backups/erpcondominios/p2-runs/rebuild-20261120T000000Z-123.json",
            ],
            cwd=Path.cwd(),
            env={"PATH": "/usr/bin:/bin"},
            capture_output=True,
            text=True,
            check=False,
        )
        self.assertNotEqual(result.returncode, 64)
        self.assertNotIn("Uso:", result.stderr)


if __name__ == "__main__":
    unittest.main()
