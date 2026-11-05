#!/usr/bin/env python3
"""Offline unit tests; fixtures are synthetic and never used as report data."""

from __future__ import annotations

import csv
import copy
import hashlib
import importlib.util
import json
import stat
import tempfile
import unittest
from pathlib import Path
from unittest import mock


MODULE_PATH = Path(__file__).with_name("generate_read_only_quality_reports.py")
SPEC = importlib.util.spec_from_file_location("quality_generator", MODULE_PATH)
assert SPEC and SPEC.loader
quality = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(quality)


class ReadOnlyQualityReportsTest(unittest.TestCase):
    def minimal_snapshot(self) -> dict:
        """A tiny synthetic fixture; constants are patched in each test."""
        tables = []
        for name, has_tenant, view_like in (
            ("synthetic_tenant_table", True, False),
            ("synthetic_global_table", False, False),
            ("synthetic_view_like", False, True),
        ):
            tables.append(
                {
                    "table_name": name,
                    "view_like": view_like,
                    "row_count": 0,
                    "tenant": {
                        "has_tenant_id": has_tenant,
                        "tenant_id_column": "tenant_id" if has_tenant else None,
                        "schema_default_1_status": "absent" if has_tenant else "not_applicable",
                        "null_count": 0 if has_tenant else None,
                        "default_1_row_count": 0 if has_tenant else None,
                    },
                    "columns": [
                        {
                            "column_name": "tenant_id" if has_tenant else "synthetic_id",
                            "type_family": "integer",
                            "null_count": 0,
                            "duplicate_count": 0,
                            "min_status": "not_observed",
                            "max_status": "not_observed",
                            "enum_status": "not_applicable",
                            "enum_invalid_count": None,
                            "date_zero_status": "not_applicable",
                            "date_zero_count": None,
                            "overflow_status": "not_applicable",
                            "overflow_count": None,
                            "json_status": "not_applicable",
                            "json_invalid_count": None,
                            "attachment_reference_status": "not_observed",
                            "attachment_reference_count": None,
                        }
                    ],
                    "constraints": [
                        {
                            "constraint_name": f"{name}_pkey",
                            "kind": "primary_key",
                            "null_count": 0,
                            "duplicate_count": 0,
                        }
                    ],
                    "orphan_candidates": [
                        {
                            "relation_name": "synthetic_parent_id",
                            "parent_table_name": "synthetic_parent",
                            "status": "checked_none",
                            "candidate_count": 0,
                        }
                    ],
                    "attachment_references": [
                        {
                            "column_name": "synthetic_attachment",
                            "status": "checked_absent",
                            "reference_count": 0,
                        }
                    ],
                }
            )
        return {
            "schema_version": 1,
            "snapshot": {
                "kind": "metadata_only_aggregates",
                "captured_at": "2026-11-05T18:00:00Z",
                "source_sha256": "a" * 64,
                "contains_row_data": False,
                "contains_cell_values": False,
                "contains_sql": False,
                "contains_personal_data": False,
            },
            "inventory": {"tables": tables},
        }

    def full_coverage_fixture(self) -> dict:
        base = self.minimal_snapshot()
        template = base["inventory"]["tables"][0]
        tables = []
        for index in range(270):
            has_tenant = index < 228
            view_like = index < 4 or 228 <= index < 246
            table = copy.deepcopy(template)
            table["table_name"] = f"synthetic_table_{index:03d}"
            table["view_like"] = view_like
            table["tenant"].update(
                {
                    "has_tenant_id": has_tenant,
                    "tenant_id_column": "tenant_id" if has_tenant else None,
                    "schema_default_1_status": "absent" if has_tenant else "not_applicable",
                    "null_count": 0 if has_tenant else None,
                    "default_1_row_count": 0 if has_tenant else None,
                }
            )
            table["columns"][0]["column_name"] = "tenant_id" if has_tenant else "synthetic_id"
            table["constraints"][0]["constraint_name"] = f"synthetic_table_{index:03d}_pkey"
            tables.append(table)
        base["inventory"]["tables"] = tables
        return base

    def patched_contract(self):
        return mock.patch.multiple(
            quality,
            EXPECTED_TABLES=3,
            EXPECTED_WITH_TENANT=1,
            EXPECTED_WITHOUT_TENANT=2,
            EXPECTED_VIEW_LIKE_TOTAL=1,
            EXPECTED_VIEW_LIKE_WITH_TENANT=0,
            EXPECTED_VIEW_LIKE_WITHOUT_TENANT=1,
            EXPECTED_OTHER_WITHOUT_TENANT=1,
        )

    def test_rejects_row_payload_even_when_flag_is_false(self):
        document = self.minimal_snapshot()
        document["inventory"]["tables"][0]["records"] = []
        with self.assertRaises(ValueError):
            with self.patched_contract():
                quality.validate_snapshot(document)

    def test_rejects_cell_values_and_sql_flags(self):
        document = self.minimal_snapshot()
        document["snapshot"]["contains_cell_values"] = True
        with self.assertRaises(ValueError):
            with self.patched_contract():
                quality.validate_snapshot(document)

        document = self.minimal_snapshot()
        document["snapshot"]["contains_sql"] = True
        with self.assertRaises(ValueError):
            with self.patched_contract():
                quality.validate_snapshot(document)

    def test_rejects_unsafe_identifier(self):
        document = self.minimal_snapshot()
        document["inventory"]["tables"][0]["table_name"] = 'safe_name"; DROP TABLE users; --'
        with self.assertRaises(ValueError):
            with self.patched_contract():
                quality.validate_snapshot(document)

    def test_rejects_invalid_utc_timestamp_and_boolean_schema_version(self):
        document = self.minimal_snapshot()
        document["snapshot"]["captured_at"] = "2026-99-99T18:00:00Z"
        with self.assertRaises(ValueError):
            with self.patched_contract():
                quality.validate_snapshot(document)

        document = self.minimal_snapshot()
        document["schema_version"] = True
        with self.assertRaises(ValueError):
            with self.patched_contract():
                quality.validate_snapshot(document)

    def test_rejects_duplicate_json_keys(self):
        raw = b'{"schema_version":1,"schema_version":1}'
        with tempfile.TemporaryDirectory() as temp_dir:
            input_path = Path(temp_dir) / "duplicate.json"
            input_path.write_bytes(raw)
            with self.assertRaises(ValueError):
                quality.run(input_path, Path(temp_dir) / "out", MODULE_PATH, hashlib.sha256(raw).hexdigest())

    def test_accepts_metadata_fixture_and_keeps_zero_aggregates(self):
        document = self.minimal_snapshot()
        with self.patched_contract():
            tables = quality.validate_snapshot(document)
            rows = quality.build_quality_rows(tables)
            tenant_rows = quality.build_tenant_rows(tables)
        self.assertEqual(len(tables), 3)
        table_row = next(row for row in rows if row["subject_type"] == "table" and row["table_name"] == "synthetic_tenant_table")
        self.assertEqual(table_row["row_count"], 0)
        self.assertEqual(table_row["pk_null_count_total"], 0)
        self.assertEqual(table_row["pk_duplicate_count_total"], 0)
        self.assertEqual(table_row["orphan_candidate_count_total"], 0)
        self.assertEqual(next(row for row in tenant_rows if row["table_name"] == "synthetic_tenant_table")["tenant_id_null_count"], 0)

    def test_accepts_full_synthetic_schema_coverage_270_228_42_22_4_18_24(self):
        tables = quality.validate_snapshot(self.full_coverage_fixture())
        self.assertEqual(len(tables), 270)
        self.assertEqual(sum(1 for table in tables if table["tenant"]["has_tenant_id"]), 228)
        self.assertEqual(sum(1 for table in tables if table["view_like"]), 22)
        self.assertEqual(sum(1 for table in tables if table["view_like"] and table["tenant"]["has_tenant_id"]), 4)
        wrong_partition = self.full_coverage_fixture()
        wrong_partition["inventory"]["tables"][0]["view_like"] = False
        with self.assertRaises(ValueError):
            quality.validate_snapshot(wrong_partition)

    def test_rejects_incomplete_view_like_partition_and_nonzero_absent_attachment_count(self):
        wrong_view_partition = self.minimal_snapshot()
        wrong_view_partition["inventory"]["tables"][0]["view_like"] = True
        with self.patched_contract(), self.assertRaises(ValueError):
            quality.validate_snapshot(wrong_view_partition)

        wrong_attachment_count = self.minimal_snapshot()
        column = wrong_attachment_count["inventory"]["tables"][0]["columns"][0]
        column["attachment_reference_status"] = "checked_absent"
        column["attachment_reference_count"] = 7
        with self.patched_contract(), self.assertRaises(ValueError):
            quality.validate_snapshot(wrong_attachment_count)

        absent_attachment = self.minimal_snapshot()
        column = absent_attachment["inventory"]["tables"][0]["columns"][0]
        column["attachment_reference_status"] = "checked_absent"
        column["attachment_reference_count"] = 0
        with self.patched_contract():
            self.assertEqual(len(quality.validate_snapshot(absent_attachment)), 3)

    def test_run_writes_only_four_expected_artifacts(self):
        document = self.minimal_snapshot()
        with tempfile.TemporaryDirectory() as temp_dir:
            input_path = Path(temp_dir) / "input.json"
            output_dir = Path(temp_dir) / "out"
            input_bytes = json.dumps(document).encode("utf-8")
            input_path.write_bytes(input_bytes)
            with self.patched_contract():
                outputs = quality.run(input_path, output_dir, MODULE_PATH, hashlib.sha256(input_bytes).hexdigest())
            self.assertEqual(
                set(outputs),
                {
                    "data_quality_aggregates.csv",
                    "tenant_profile.csv",
                    "quality_report_manifest.json",
                    "quality_report_manifest.json.sha256",
                },
            )
            quality_csv = output_dir / "data_quality_aggregates.csv"
            with quality_csv.open(newline="", encoding="utf-8") as handle:
                rows = list(csv.DictReader(handle))
            self.assertTrue(rows)
            self.assertNotIn("value", "".join(quality_csv.read_text(encoding="utf-8")).lower())
            self.assertEqual(stat.S_IMODE(output_dir.stat().st_mode), 0o700)
            for artifact in output_dir.iterdir():
                self.assertEqual(stat.S_IMODE(artifact.stat().st_mode), 0o600)
            manifest = json.loads((output_dir / "quality_report_manifest.json").read_text(encoding="utf-8"))
            self.assertTrue(manifest["policy"]["read_only"])
            self.assertFalse(manifest["policy"]["rows_exported"])
            sidecar = (output_dir / "quality_report_manifest.json.sha256").read_text(encoding="ascii")
            expected = hashlib.sha256((output_dir / "quality_report_manifest.json").read_bytes()).hexdigest()
            self.assertTrue(sidecar.startswith(expected + "  "))

    def test_wrong_input_sha_and_existing_output_directory_fail_closed(self):
        document = self.minimal_snapshot()
        input_bytes = json.dumps(document).encode("utf-8")
        with tempfile.TemporaryDirectory() as temp_dir:
            input_path = Path(temp_dir) / "input.json"
            input_path.write_bytes(input_bytes)
            output_dir = Path(temp_dir) / "out"
            with self.assertRaises(ValueError):
                quality.run(input_path, output_dir, MODULE_PATH, "0" * 64)
            self.assertFalse(output_dir.exists())

            with self.patched_contract():
                output_dir.mkdir()
                sentinel = output_dir / "keep.txt"
                sentinel.write_text("preservar", encoding="utf-8")
                with self.assertRaises(ValueError):
                    quality.run(
                        input_path,
                        output_dir,
                        MODULE_PATH,
                        hashlib.sha256(input_bytes).hexdigest(),
                    )
                self.assertEqual(sentinel.read_text(encoding="utf-8"), "preservar")

    def test_rejects_symlink_input_and_non_private_output_parent(self):
        document = self.minimal_snapshot()
        input_bytes = json.dumps(document).encode("utf-8")
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            source = root / "input.json"
            source.write_bytes(input_bytes)
            symlink = root / "input-link.json"
            symlink.symlink_to(source)
            digest = hashlib.sha256(input_bytes).hexdigest()
            with self.assertRaises(ValueError):
                quality.run(symlink, root / "link-out", MODULE_PATH, digest)

            public_parent = root / "public-parent"
            public_parent.mkdir(mode=0o755)
            public_parent.chmod(0o755)
            with self.patched_contract(), self.assertRaises(ValueError):
                quality.run(source, public_parent / "out", MODULE_PATH, digest)

    def test_post_rename_fsync_failure_reports_and_preserves_published_bundle(self):
        if not hasattr(quality.ctypes.CDLL(None), "renameat2"):
            self.skipTest("renameat2 não disponível neste runtime")
        document = self.minimal_snapshot()
        input_bytes = json.dumps(document).encode("utf-8")
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            input_path = root / "input.json"
            input_path.write_bytes(input_bytes)
            output_dir = root / "published"
            digest = hashlib.sha256(input_bytes).hexdigest()
            original_fsync = quality.os.fsync

            def fail_directory_fsync(fd):
                if stat.S_ISDIR(quality.os.fstat(fd).st_mode):
                    raise OSError("synthetic directory fsync failure")
                return original_fsync(fd)

            with self.patched_contract(), mock.patch.object(quality.os, "fsync", side_effect=fail_directory_fsync):
                with self.assertRaises(quality.BundlePublishedDurabilityUncertain) as raised:
                    quality.run(input_path, output_dir, MODULE_PATH, digest)

            self.assertIn(str(output_dir), str(raised.exception))
            self.assertTrue(output_dir.is_dir())
            manifest_path = output_dir / "quality_report_manifest.json"
            sidecar = (output_dir / "quality_report_manifest.json.sha256").read_text(encoding="ascii")
            expected = hashlib.sha256(manifest_path.read_bytes()).hexdigest()
            self.assertTrue(sidecar.startswith(expected + "  "))


if __name__ == "__main__":
    unittest.main()
