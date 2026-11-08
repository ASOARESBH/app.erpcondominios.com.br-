import sys
import unittest
from datetime import datetime
from pathlib import Path
from unittest.mock import MagicMock, call

ROOT = Path(__file__).resolve().parents[2] / "tools" / "migracao_pg"
sys.path.insert(0, str(ROOT))

from loader import TextualNulError, check_text_nul, convert_value
from reconcile import fk_metrics_for_table, hash_divergence
from trigger_harness import reset_fixture_sequence, trigger_failure_marker


class LoaderValueTests(unittest.TestCase):
    def test_trigger_failure_marker_contains_only_sanitized_fields(self):
        class SyntheticDatabaseError(Exception):
            sqlstate = "42601"

        marker = trigger_failure_marker("trg_alerta_estoque_baixo", SyntheticDatabaseError("SQL includes a value"))
        self.assertEqual(marker, "TRIGGER_TEST_FAILED type=SyntheticDatabaseError code=trg_alerta_estoque_baixo sqlstate=42601")
        self.assertNotIn("value", marker)
        self.assertEqual(trigger_failure_marker("unexpected", Exception("secret")),
                         "TRIGGER_TEST_FAILED type=Exception code=harness_preflight sqlstate=none")

    def test_stock_harness_escapes_literal_like_wildcards(self):
        source = Path(__file__).with_name("trigger_harness.py").read_text(encoding="utf-8")
        self.assertIn("LIKE '%%ZERADO%%'", source)
        self.assertIn("LIKE '%%abaixo do estoque mínimo%%'", source)

    def test_fixture_sequence_reset_runs_as_postgres_then_restores_app_role(self):
        cursor = MagicMock()
        reset_fixture_sequence(cursor, "erp.fixture_id_seq", -17)
        self.assertEqual(cursor.execute.call_args_list, [
            call("RESET ROLE"),
            call("SELECT setval(%s::regclass,%s,false)", ("erp.fixture_id_seq", -17)),
            call("SET LOCAL ROLE erp_app"),
        ])
        failing_cursor = MagicMock()
        failing_cursor.execute.side_effect = [None, RuntimeError("synthetic"), None]
        with self.assertRaisesRegex(RuntimeError, "synthetic"):
            reset_fixture_sequence(failing_cursor, "erp.fixture_id_seq", -17)
        self.assertEqual(failing_cursor.execute.call_args_list[-1], call("SET LOCAL ROLE erp_app"))

    def test_business_trigger_uses_verified_primary_key_conflict_target(self):
        business_sql = (ROOT / "business_objects.sql").read_text(encoding="utf-8")
        self.assertIn("ON CONFLICT (id) DO UPDATE", business_sql)
        self.assertIn("AND tenant_id = NEW.tenant_id", business_sql)

    def test_zero_date_maps_to_null_and_counts_only_column_key(self):
        zero_counts = {}
        col = {"data_type": "date", "name": "data", "generated": False}
        self.assertIsNone(convert_value("t", col, b"0000-00-00", zero_counts, {}))
        self.assertEqual(zero_counts, {"t.data": 1})

    def test_datetime_keeps_wall_clock_value(self):
        col = {"data_type": "datetime", "name": "data", "generated": False}
        value = convert_value("t", col, b"2026-02-03 14:15:16", {}, {})
        self.assertEqual(value, datetime(2026, 2, 3, 14, 15, 16))

    def test_text_nul_fails_closed(self):
        col = {"data_type": "text", "name": "campo"}
        with self.assertRaises(TextualNulError) as caught:
            check_text_nul("t", [col], (b"a\x00b",))
        self.assertEqual((caught.exception.table, caught.exception.column), ("t", "campo"))

    def test_json_nul_fails_closed_without_retaining_payload(self):
        col = {"data_type": "json", "name": "payload"}
        with self.assertRaises(TextualNulError) as caught:
            check_text_nul("t", [col], (b'{"x":"\\u0000"}',))
        self.assertEqual(str(caught.exception), "NUL byte in textual column t.payload")

    def test_binary_nul_is_preserved(self):
        col = {"data_type": "longblob", "name": "conteudo"}
        check_text_nul("t", [col], (b"a\x00b",))
        value = convert_value("t", col, b"a\x00b", {}, {})
        self.assertEqual(value, b"a\x00b")

    def test_json_is_transferred_as_text_for_native_json_parser(self):
        col = {"data_type": "json", "name": "documento", "generated": False}
        value = convert_value("t", col, b'{"x":1}', {}, {})
        self.assertEqual(value, '{"x":1}')

    def test_empty_enum_value_is_not_rewritten(self):
        col = {"data_type": "enum", "name": "status", "generated": False}
        self.assertEqual(convert_value("t", col, b"", {}, {}), "")

    def test_set_value_is_preserved_as_mysql_text(self):
        col = {"data_type": "set", "name": "flags", "generated": False}
        self.assertEqual(convert_value("t", col, b"", {}, {}), "")
        self.assertEqual(convert_value("t", col, b"read,edit", {}, {}), "read,edit")


class ReconciliationTests(unittest.TestCase):
    def test_foreign_key_metrics_are_aggregated_per_source_table(self):
        fks = [
            {"table": "child", "orphans": 0, "valid": True, "tenant_mismatches": 0},
            {"table": "child", "orphans": 3, "valid": False, "tenant_mismatches": 1},
            {"table": "other", "orphans": 0, "valid": True, "tenant_mismatches": 0},
        ]
        self.assertEqual(fk_metrics_for_table("child", fks), {
            "foreign_keys": 2, "foreign_keys_valid": 1, "foreign_keys_with_orphans": 1,
            "fk_orphan_count": 3, "fk_tenant_mismatches": 1,
        })
        self.assertEqual(fk_metrics_for_table("no_fk", fks), {
            "foreign_keys": 0, "foreign_keys_valid": 0, "foreign_keys_with_orphans": 0,
            "fk_orphan_count": 0, "fk_tenant_mismatches": 0,
        })

    def test_primary_key_hash_diff_counts_changed_and_missing_keys(self):
        src = {"by_pk": {b"1": b"a", b"2": b"b"}, "multiset": {}}
        dst = {"by_pk": {b"1": b"a", b"3": b"c"}, "multiset": {}}
        self.assertEqual(hash_divergence(src, dst, ["id"]), (2, 2, 2))

    def test_tables_without_pk_compare_multisets_with_duplicates(self):
        src = {"by_pk": {}, "multiset": {b"a": 2, b"b": 1}}
        dst = {"by_pk": {}, "multiset": {b"a": 1, b"c": 1}}
        self.assertEqual(hash_divergence(src, dst, []), (2, 3, 2))


if __name__ == "__main__":
    unittest.main()
