from __future__ import annotations

from copy import deepcopy
from decimal import Decimal
from datetime import datetime, timedelta, timezone
import hashlib
import os
import stat
import unittest
from unittest.mock import patch

from tools.migracao_pg.loader import _audit_pk_text as loader_audit_pk_text
from tools.migracao_pg.loader import _MemoryGuard
from tools.migracao_pg.ddl import MYSQL_UNSIGNED_BIGINT_MAX, POSTGRES_SEQUENCE_MAX
from tools.migracao_pg.reconcile import _DiskCounterStore, _accumulate_row, _aggregate_metric_results, _foreign_key_metric, _new_accumulator
from tools.migracao_pg.reconcile import _audit_pk_text as reconcile_audit_pk_text
from tools.migracao_pg.reconcile import canonical_row_sha256, reconcile


TABLE = {
    "name": "synthetic",
    "type": "BASE TABLE",
    "engine": "InnoDB",
    "source_next_auto_increment": 2,
    "columns": [
        {"name": "id", "ordinal": 1, "data_type": "int", "column_type": "int", "extra": "auto_increment"},
        {"name": "tenant_id", "ordinal": 2, "data_type": "int", "column_type": "int", "extra": ""},
        {"name": "amount", "ordinal": 3, "data_type": "decimal", "column_type": "decimal(8,2)", "extra": ""},
        {"name": "payload", "ordinal": 4, "data_type": "blob", "column_type": "blob", "extra": ""},
        {"name": "description", "ordinal": 5, "data_type": "text", "column_type": "text", "character_set": "utf8mb4", "extra": ""},
        {"name": "state", "ordinal": 6, "data_type": "enum", "column_type": "enum('a','b')", "character_set": "utf8mb4", "extra": ""},
    ],
}
SOURCE_ROW = (1, 7, Decimal("2.30"), b"\x00x", "hi\x00there", "a")
TARGET_ROW = (1, 7, Decimal("2.30"), b"\x00x", "hithere", "a")


class FakeCursor:
    def __init__(self, connection: "FakeConnection") -> None:
        self.connection = connection
        self.sql = ""
        self.params = ()
        self.rows: list[object] = []
        self.pos = 0

    def execute(self, sql: str, params: tuple = ()) -> None:
        self.sql = str(sql)
        self.params = params
        self.connection.queries.append(self.sql)
        self.pos = 0
        if "SELECT DATABASE() AS database_name" in self.sql:
            self.rows = [(self.connection.database, self.connection.user)]
        elif "SELECT current_database() AS database_name" in self.sql:
            self.rows = [(self.connection.database, self.connection.user)]
        elif "pg_get_serial_sequence" in self.sql:
            self.rows = [(self.connection.sequence_name,)]
        elif "low_sequence::text" in self.sql:
            self.connection.sequence_lookup_keys.append(self.params[0])
            self.rows = [(self.connection.low_sequence_ref, self.connection.high_sequence_ref)]
        elif "FROM pg_catalog.pg_sequences WHERE schemaname" in self.sql:
            name = self.params[1]
            state = self.connection.unsigned_sequences.get(name)
            self.rows = [state] if state is not None else []
        elif self.sql.startswith('SELECT is_called FROM "erp".') and self.sql.rsplit(".", 1)[-1].strip('"') in self.connection.unsigned_called:
            name = self.sql.rsplit(".", 1)[-1].strip('"')
            self.rows = [(self.connection.unsigned_called[name],)]
        elif "FROM pg_catalog.pg_class c" in self.sql:
            self.rows = [(
                self.connection.sequence_schema,
                self.connection.sequence_relname,
                self.connection.sequence_start,
                self.connection.sequence_increment,
                self.connection.sequence_max,
                self.connection.sequence_last,
            )]
        elif self.sql.startswith("SELECT is_called FROM"):
            self.rows = [(self.connection.sequence_called,)]
        elif "_p2_autoincrement_state" in self.sql:
            self.rows = [(self.connection.identity_sync_complete,)]
        elif 'FROM "legado"."auditoria_nul"' in self.sql:
            self.rows = list(self.connection.audit_rows)
        elif "NOT EXISTS (SELECT 1 FROM" in self.sql:
            self.rows = [(self.connection.orphan_count,)]
        elif "FROM pg_catalog.pg_constraint AS constraint_row" in self.sql:
            self.rows = list(self.connection.foreign_key_definitions)
        elif self.sql.startswith("SELECT ") and " FROM " in self.sql:
            self.rows = list(self.connection.table_rows)
        else:
            raise AssertionError(f"Unexpected synthetic SQL shape: {self.sql}")

    def fetchone(self):
        if self.pos >= len(self.rows):
            return None
        result = self.rows[self.pos]
        self.pos += 1
        return result

    def fetchmany(self, size: int):
        self.connection.fetchmany_sizes.append(size)
        result = self.rows[self.pos:self.pos + size]
        self.pos += len(result)
        return result

    def fetchall(self):
        result = self.rows[self.pos:]
        self.pos = len(self.rows)
        return result

    def close(self) -> None:
        return None


class FakeConnection:
    def __init__(self, *, database: str, user: str, table_rows=(), orphan_count=0, audit_rows=(), foreign_key_definitions=()) -> None:
        self.database = database
        self.user = user
        self.table_rows = list(table_rows)
        self.orphan_count = orphan_count
        self.audit_rows = list(audit_rows)
        self.foreign_key_definitions = list(foreign_key_definitions)
        self.fetchmany_sizes: list[int] = []
        self.queries: list[str] = []
        self.sequence_lookup_keys: list[str] = []
        self.sequence_name = '"erp"."synthetic_id_seq"'
        self.sequence_schema = "erp"
        self.sequence_relname = "synthetic_id_seq"
        self.sequence_start = 1
        self.sequence_increment = 1
        self.sequence_max = POSTGRES_SEQUENCE_MAX
        self.sequence_last = 1
        self.sequence_called = True
        self.identity_sync_complete = True
        self.low_sequence_ref = '"erp"."u64_low_synthetic_id"'
        self.high_sequence_ref = '"erp"."u64_high_synthetic_id"'
        self.unsigned_sequences = {
            "u64_low_synthetic_id": (1, 1, 9223372036854775807, 1),
            "u64_high_synthetic_id": (0, 1, 9223372036854775807, 0),
        }
        self.unsigned_called = {"u64_low_synthetic_id": True, "u64_high_synthetic_id": False}

    def cursor(self):
        return FakeCursor(self)


def base_contract(*, source_rows=None, target_rows=None, snapshot=None, report=None, mappings=None,
                  source_db="erp_pg_source", target_db="erp_pg_dev", source_orphans=0, target_orphans=0):
    table = deepcopy(TABLE)
    snap = {
        "source_schema": "erp_pg_source",
        "source_grant_policy_id": "erp_pg_source_select_show_view_trigger_v2",
        "source_grants_policy_accepted": True,
        "source_routines_events_zero_verified": True,
        "source_schema_wide_select": True,
        "metadata_visibility": {"views": True, "triggers": True, "routines": False, "events": False},
        "routines": [],
        "events": [],
        "catalog_consistency_check": "PRE_POST_SIGNATURE_MATCH",
        "timestamp_transfer_contract": {"source_session": "UTC", "target_wall_time": "America/Sao_Paulo"},
        "fingerprint_sha256": "f" * 64,
        "foreign_keys": [],
        "tables": [table],
    }
    table["primary_key"] = [{"ordinal": 1, "column": "id"}]
    map_data = {
        "synthetic": {
            "columns": deepcopy(table["columns"]),
            "types": {column["name"]: object() for column in table["columns"]},
            "enum_labels": {"state": ("a", "b")},
            "target_schema": "erp",
            "identity_columns": [{"column": "id", "strategy": "identity"}],
        }
    }
    src = [SOURCE_ROW] if source_rows is None else list(source_rows)
    dst = [TARGET_ROW] if target_rows is None else list(target_rows)
    load_report = {
        "status": "LOAD_COMMITTED_PENDING_RECONCILIATION",
        "source_schema": "erp_pg_source",
        "target_database": target_db,
        "target_schema": "erp",
        "bundle_catalog_fingerprint": snap["fingerprint_sha256"],
        "table_counts": [{"table": "synthetic", "source_rows": len(src), "target_rows": len(dst)}],
        "text_policy_findings": [
            {"table": "synthetic", "column": "description", "scan_status": "SCANNED", "detector_disagreement": False,
             "cells_scanned": len(src), "method_a_python_string_cells_with_nul": 1, "method_a_decode_failures": 0,
             "method_b_cells_with_nul": 1, "python_cells_with_nul": 1, "cells_with_nul": 1,
             "invalid_declared_charset_cells_after_nul": 0, "json_escaped_nul_cells": 0},
            {"table": "synthetic", "column": "state", "scan_status": "SCANNED", "detector_disagreement": False,
             "cells_scanned": len(src), "method_a_python_string_cells_with_nul": 0, "method_a_decode_failures": 0,
             "method_b_cells_with_nul": 0, "python_cells_with_nul": 0, "cells_with_nul": 0,
             "invalid_declared_charset_cells_after_nul": 0, "json_escaped_nul_cells": 0},
        ],
        "binary_policy_findings": [
            {"table": "synthetic", "column": "payload", "cells_scanned": len(src),
             "method_b_cells_with_nul": 1, "python_cells_with_nul": 1, "nul_bytes": 1,
             "binary_bytes_scanned": 2 * len(src),
             "bytea_policy": "raw_bytes_without_text_decode_or_nul_normalization"}
        ],
        "nul_values_normalized": [
            {"table": "synthetic", "column": "description", "cells_with_nul": 1, "nul_bytes": 1,
             "json_escaped_nul_cells": 0, "status": "NORMALIZED_NUL"}
        ],
        "nul_audit_rows": 1,
        "enum_values_outside_declared": [],
        "foreign_key_results": [],
    }
    if snapshot is not None:
        snap = snapshot
    if report is not None:
        load_report = report
    if mappings is not None:
        map_data = mappings
    audit_rows = []
    for row in src:
        value = row[4]
        if isinstance(value, str) and "\x00" in value:
            loader_row = tuple(
                item.encode("utf-8") if isinstance(item, str) and str(column.get("data_type", "")).lower() in
                {"char", "varchar", "nchar", "nvarchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set", "json"}
                else item
                for item, column in zip(row, table["columns"], strict=True)
            )
            pk_text = loader_audit_pk_text(table, table["columns"], loader_row)
            audit_rows.append(("synthetic", "description", pk_text, value.encode("utf-8"), datetime.now(timezone.utc)))
    source = FakeConnection(database=source_db, user="pg_reader@localhost", table_rows=src, orphan_count=source_orphans)
    target = FakeConnection(database=target_db, user="synthetic_target", table_rows=dst, orphan_count=target_orphans, audit_rows=audit_rows)
    return source, target, snap, load_report, map_data


def run_contract(**kwargs):
    source, target, snapshot, report, mappings = base_contract(**kwargs)
    result = reconcile(source, target, snapshot, report, mappings)
    return result, source, target


class TargetProfileContractTests(unittest.TestCase):
    def test_cutover_reconciliation_requires_explicit_target_identity(self):
        source, target, snapshot, report, mappings = base_contract(target_db="erp_pg_cutover_synthetic")
        result = reconcile(
            source, target, snapshot, report, mappings,
            expected_target_database="erp_pg_cutover_synthetic",
        )
        self.assertEqual(result["status"], "RECONCILED")

        source, target, snapshot, report, mappings = base_contract(target_db="erp_pg_cutover_synthetic")
        blocked = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(blocked["status"], "BLOCKED")
        self.assertEqual(blocked["failure_code"], "TARGET_DATABASE_NOT_ALLOWLISTED")


class CanonicalRowHashTests(unittest.TestCase):
    def test_disk_counter_store_is_private_hmac_and_cleans_up(self):
        store = _DiskCounterStore()
        directory = store._directory.name
        path = os.path.join(directory, "counters.sqlite3")
        counter = store.counter("synthetic")
        counter.add(b"private-row-value")
        store.commit()
        self.assertEqual(stat.S_IMODE(os.stat(directory).st_mode), 0o700)
        self.assertEqual(stat.S_IMODE(os.stat(path).st_mode), 0o600)
        with open(path, "rb") as handle:
            self.assertNotIn(b"private-row-value", handle.read())
        self.assertEqual(counter.total(), 1)
        store.close()
        self.assertFalse(os.path.exists(directory))

    def test_hash_is_stable_framed_and_null_distinct(self):
        columns = [
            {"name": "left", "data_type": "text", "character_set": "utf8mb4"},
            {"name": "right", "data_type": "text", "character_set": "utf8mb4"},
        ]
        first = canonical_row_sha256(("a", "bc"), columns)
        second = canonical_row_sha256(("ab", "c"), columns)
        self.assertEqual(first, canonical_row_sha256(("a", "bc"), columns))
        self.assertNotEqual(first, second)
        text_col = [{"name": "v", "data_type": "text", "character_set": "utf8mb4"}]
        self.assertNotEqual(canonical_row_sha256((None,), text_col), canonical_row_sha256(("",), text_col))
        int_col = [{"name": "v", "data_type": "int"}]
        self.assertNotEqual(canonical_row_sha256((None,), int_col), canonical_row_sha256((0,), int_col))
        self.assertEqual(canonical_row_sha256(("007",), int_col), canonical_row_sha256((7,), int_col))

    def test_exact_numeric_json_text_and_raw_binary_canonicalization(self):
        numeric = [{"name": "n", "data_type": "decimal", "column_type": "decimal(8,2)"}]
        self.assertEqual(canonical_row_sha256((Decimal("1.20"),), numeric), canonical_row_sha256(("1.20",), numeric))
        json_column = [{"name": "j", "data_type": "json", "column_type": "json", "character_set": "utf8mb4"}]
        self.assertEqual(canonical_row_sha256((' {"b":2,"a":1} ',), json_column), canonical_row_sha256((' {"a":1,"b":2} ',), json_column))
        self.assertNotEqual(canonical_row_sha256((b'{"a":[1,2]}',), json_column), canonical_row_sha256((b'{"a":[2,1]}',), json_column))
        nul_json = b'{"a":"\\u0000"}'
        clean_json = b'{"a":""}'
        self.assertEqual(canonical_row_sha256((nul_json,), json_column), canonical_row_sha256((clean_json,), json_column))
        binary = [{"name": "b", "data_type": "blob", "column_type": "blob"}]
        self.assertNotEqual(canonical_row_sha256((b"A\x00B",), binary), canonical_row_sha256((b"AB",), binary))
        text = [{"name": "t", "data_type": "text", "character_set": "utf8mb4"}]
        self.assertNotEqual(canonical_row_sha256(("É",), text), canonical_row_sha256(("é",), text))

    def test_temporal_contract_normalizes_timestamp_and_interval(self):
        timestamp = [{"name": "t", "data_type": "timestamp", "column_type": "timestamp"}]
        self.assertEqual(
            canonical_row_sha256(("2025-01-02 03:04:05",), timestamp, is_source=True),
            canonical_row_sha256(("2025-01-02T00:04:05",), timestamp, is_source=False),
        )
        time_col = [{"name": "duration", "data_type": "time", "column_type": "time"}]
        self.assertEqual(canonical_row_sha256(("-24:00:00",), time_col), canonical_row_sha256((-timedelta(hours=24),), time_col, is_source=False))


class GeneratedColumnMetricTests(unittest.TestCase):
    def test_calculated_values_are_compared_explicitly_source_vs_target(self):
        table = {
            "name": "lancamentos_agua",
            "columns": [
                {"name": "id", "ordinal": 1, "data_type": "int", "column_type": "int", "extra": "auto_increment"},
                {"name": "leitura_anterior", "ordinal": 2, "data_type": "decimal", "column_type": "decimal(10,2)", "extra": ""},
                {"name": "leitura_atual", "ordinal": 3, "data_type": "decimal", "column_type": "decimal(10,2)", "extra": ""},
                {"name": "consumo", "ordinal": 4, "data_type": "decimal", "column_type": "decimal(10,2)", "extra": "STORED GENERATED", "generated_expression_present": True, "generated_expression_key": "lancamentos_agua.consumo.v1"},
            ],
        }
        store = _DiskCounterStore()
        try:
            source = _new_accumulator(table, {}, store, side="source", table_name=table["name"])
            target = _new_accumulator(table, {}, store, side="target", table_name=table["name"])
            _accumulate_row(source, [1, Decimal("10.00"), Decimal("12.50"), Decimal("2.50")], table, {}, is_source=True)
            _accumulate_row(target, [1, Decimal("10.00"), Decimal("12.50"), Decimal("2.50")], table, {}, is_source=False)
            store.commit()
            result = _aggregate_metric_results({table["name"]: table}, {table["name"]: source}, {table["name"]: target})
            self.assertTrue(result[table["name"]]["generated_columns"]["consumo"]["calculated_values_equal"])

            mismatched = _new_accumulator(table, {}, store, side="target-mismatch", table_name=table["name"])
            _accumulate_row(mismatched, [1, Decimal("10.00"), Decimal("12.50"), Decimal("2.49")], table, {}, is_source=False)
            store.commit()
            result = _aggregate_metric_results({table["name"]: table}, {table["name"]: source}, {table["name"]: mismatched})
            self.assertFalse(result[table["name"]]["generated_columns"]["consumo"]["calculated_values_equal"])
        finally:
            store.close()


class MemoryGuardCancellationTests(unittest.TestCase):
    def test_memory_guard_closes_source_connection_when_cancel_safe_is_unavailable(self):
        class SourceConnection:
            closed = False

            def close(self):
                self.closed = True

        connection = SourceConnection()
        guard = _MemoryGuard()
        guard.set_connection(connection)
        guard._cancel_active_query()
        self.assertTrue(connection.closed)

    def test_memory_guard_uses_driver_cancel_safe_when_available(self):
        class TargetConnection:
            called = False

            def cancel_safe(self, timeout):
                self.called = timeout == 5

        connection = TargetConnection()
        guard = _MemoryGuard()
        guard.set_connection(connection)
        guard._cancel_active_query()
        self.assertTrue(connection.called)


class ReconciliationMetricsTests(unittest.TestCase):
    def assertMetric(self, result, name, status):
        self.assertEqual(result["metrics"].get(name), status, result)

    def test_synthetic_success_all_metrics_and_sanitized_report(self):
        result, source, target = run_contract()
        self.assertEqual(result["status"], "RECONCILED", result)
        self.assertEqual(result["metrics"], {
            "row_count": "PASS",
            "load_table_failures": "PASS",
            "row_hash_multiset": "PASS",
            "max_id": "PASS",
            "numeric_sums": "PASS",
            "binary_octet_length": "PASS",
            "binary_bytewise_hash": "PASS",
            "tenant_distribution": "PASS",
            "tenant_row_hashes": "PASS",
            "enum_values": "PASS",
            "generated_columns": "PASS",
            "nul_normalization": "PASS",
            "nul_audit_preservation": "PASS",
            "foreign_keys_orphans": "PASS",
            "identity_sequences": "PASS",
        })
        self.assertGreaterEqual(result["elapsed_seconds"], 0)
        self.assertNotIn("hi", repr(result))
        self.assertNotIn("hithere", repr(result))
        self.assertNotIn(hashlib.sha256(b"private row data").hexdigest(), repr(result))

    def test_partial_load_failure_is_reported_and_never_accepted(self):
        source, target, snapshot, report, mappings = base_contract()
        report["status"] = "LOAD_PARTIAL_PENDING_RECONCILIATION"
        report["table_counts"][0]["source_rows"] = None
        report["table_failures"] = [{
            "table": "synthetic", "column": "description", "reason": "SOURCE_TEXT_INVALID_UTF8",
            "source_rows": 1, "target_rows": 0, "affected_cells": 1,
        }]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "DIVERGED")
        self.assertEqual(result["metrics"]["load_table_failures"], "DIVERGED")
        self.assertEqual(result["table_failures"], report["table_failures"])

    def test_partial_load_without_failure_evidence_is_blocked(self):
        source, target, snapshot, report, mappings = base_contract()
        report["status"] = "LOAD_PARTIAL_PENDING_RECONCILIATION"
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertNotEqual(result["status"], "RECONCILED")
        self.assertEqual(result["failure_code"], "LOAD_PARTIAL_FAILURE_EVIDENCE_INCONSISTENT")

    def test_checkpoint_runs_for_source_and_target_streams(self):
        source, target, snapshot, report, mappings = base_contract()
        checked = []
        result = reconcile(
            source, target, snapshot, report, mappings,
            checkpoint=lambda connection: checked.append(connection.database),
        )
        self.assertEqual(result["status"], "RECONCILED")
        self.assertIn("erp_pg_source", checked)
        self.assertIn("erp_pg_dev", checked)
        self.assertTrue(any("CAST(`description` AS BINARY)" in query for query in source.queries))
        self.assertTrue(any(sql.startswith("SELECT `id`, `tenant_id`") for sql in source.queries))
        self.assertTrue(any('SELECT "id", "tenant_id"' in sql for sql in target.queries))

    def test_row_count_divergence(self):
        result, _, _ = run_contract(target_rows=[])
        self.assertEqual(result["status"], "DIVERGED")
        self.assertMetric(result, "row_count", "DIVERGED")

    def test_multiset_row_hash_detects_same_count_value_divergence_without_leaking(self):
        changed = (1, 7, Decimal("2.30"), b"\x00x", "other-secret-description", "a")
        result, _, _ = run_contract(target_rows=[changed])
        self.assertEqual(result["status"], "DIVERGED")
        self.assertMetric(result, "row_hash_multiset", "DIVERGED")
        self.assertNotIn("other-secret-description", repr(result))
        self.assertNotIn(hashlib.sha256(b"other-secret-description").hexdigest(), repr(result))

    def test_max_id_divergence(self):
        changed = (2, 7, Decimal("2.30"), b"\x00x", "hithere", "a")
        result, _, _ = run_contract(target_rows=[changed])
        self.assertMetric(result, "max_id", "DIVERGED")

    def test_exact_numeric_sum_divergence(self):
        changed = (1, 7, Decimal("2.31"), b"\x00x", "hithere", "a")
        result, _, _ = run_contract(target_rows=[changed])
        self.assertMetric(result, "numeric_sums", "DIVERGED")
        self.assertEqual(result["tables"]["synthetic"]["numeric_sums"]["amount"]["source_sum"], "2.30")

    def test_float_sums_are_exactly_canonicalized_from_driver_values(self):
        source, target, snapshot, report, mappings = base_contract()
        table = snapshot["tables"][0]
        table["columns"].append({"name": "ratio", "ordinal": 7, "data_type": "float", "column_type": "float", "extra": ""})
        source.table_rows = [(*SOURCE_ROW, 1.2)]
        target.table_rows = [(*TARGET_ROW, 1.2000000476837158)]
        mappings["synthetic"]["columns"] = deepcopy(table["columns"])
        mappings["synthetic"]["types"]["ratio"] = object()
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["metrics"]["numeric_sums"], "PASS")
        self.assertEqual(result["metrics"]["row_hash_multiset"], "PASS")

    def test_audit_pk_text_matches_loader_for_text_pk_and_pkless_table(self):
        for primary_key in ([{"ordinal": 1, "column": "natural_key"}], []):
            columns = [
                {"name": "natural_key", "ordinal": 1, "data_type": "varchar", "column_type": "varchar(32)", "character_set": "utf8mb4"},
                {"name": "description", "ordinal": 2, "data_type": "text", "column_type": "text", "character_set": "utf8mb4"},
            ]
            table = {"name": "synthetic", "columns": columns, "primary_key": primary_key}
            source_row = ("ключ", "nul\x00text")
            loader_row = tuple(value.encode("utf-8") for value in source_row)
            self.assertEqual(
                loader_audit_pk_text(table, columns, loader_row),
                reconcile_audit_pk_text(table, source_row),
            )

    def test_manifest_columns_are_normalized_before_nul_audit_pk_hashing(self):
        source, target, snapshot, report, mappings = base_contract()
        snapshot["tables"][0]["columns"] = list(reversed(snapshot["tables"][0]["columns"]))
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "RECONCILED")
        self.assertEqual(result["metrics"]["nul_audit_preservation"], "PASS")
        self.assertTrue(source.fetchmany_sizes)
        self.assertEqual(set(source.fetchmany_sizes), {1})
        self.assertEqual(set(target.fetchmany_sizes), {1})

    def test_row_size_limit_fails_closed_before_accumulating(self):
        source, target, snapshot, report, mappings = base_contract()
        with patch("tools.migracao_pg.reconcile.MAX_RECONCILE_ROW_BYTES", 1):
            result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "BLOCKED")
        self.assertEqual(result["failure_code"], "RECONCILE_ROW_EXCEEDS_128_MIB")

    def test_sqlite_counter_store_enforces_size_limit_and_cleans_up(self):
        store = _DiskCounterStore(max_bytes=16 * 1024, minimum_free_bytes=0)
        directory = store._directory.name
        counter = store.counter("synthetic")
        try:
            with self.assertRaises(Exception) as error:
                for index in range(2000):
                    counter.add(str(index).encode("ascii"))
                    if index % 25 == 0:
                        store.commit()
            self.assertEqual(getattr(error.exception, "code", None), "RECONCILE_TEMP_STORAGE_LIMIT")
        finally:
            store.close()
        self.assertFalse(os.path.exists(directory))

    def test_non_transactional_source_engine_fails_closed(self):
        source, target, snapshot, report, mappings = base_contract()
        snapshot["tables"][0]["engine"] = "MyISAM"
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "BLOCKED")
        self.assertEqual(result["failure_code"], "SOURCE_ENGINE_NOT_SNAPSHOT_CAPABLE")


    def test_binary_count_and_octet_length_divergence(self):
        changed = (1, 7, Decimal("2.30"), b"\x00xy", "hithere", "a")
        result, _, _ = run_contract(target_rows=[changed])
        self.assertMetric(result, "binary_octet_length", "DIVERGED")

    def test_binary_same_length_different_bytes_diverge_bytewise(self):
        changed = (1, 7, Decimal("2.30"), b"\x00y", "hithere", "a")
        result, _, _ = run_contract(target_rows=[changed])
        self.assertMetric(result, "binary_octet_length", "PASS")
        self.assertMetric(result, "binary_bytewise_hash", "DIVERGED")

    def test_nul_audit_missing_original_value_diverges(self):
        source, target, snapshot, report, mappings = base_contract()
        target.audit_rows = []
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertMetric(result, "nul_audit_preservation", "DIVERGED")
        self.assertEqual(result["nul_audit_preservation"]["expected_rows"], 1)
        self.assertEqual(result["nul_audit_preservation"]["observed_rows"], 0)

    def test_nul_audit_wrong_original_bytes_diverge_without_leaking(self):
        source, target, snapshot, report, mappings = base_contract()
        entry = target.audit_rows[0]
        target.audit_rows = [(*entry[:3], b"different\x00bytes", entry[4])]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertMetric(result, "nul_audit_preservation", "DIVERGED")
        self.assertNotIn("different", repr(result))

    def test_tenant_distribution_divergence(self):
        changed = (1, 8, Decimal("2.30"), b"\x00x", "hithere", "a")
        result, _, _ = run_contract(target_rows=[changed])
        self.assertMetric(result, "tenant_distribution", "DIVERGED")
        self.assertMetric(result, "tenant_row_hashes", "DIVERGED")

    def test_enum_extension_evidence_matches_counts_but_target_difference_diverges(self):
        source_row = (1, 7, Decimal("2.30"), b"\x00x", "hi\x00there", "legacy")
        target_row = (1, 7, Decimal("2.30"), b"\x00x", "hithere", "legacy")
        source, target, snapshot, report, mappings = base_contract(source_rows=[source_row], target_rows=[target_row])
        report["enum_values_outside_declared"] = [{
            "table": "synthetic", "column": "state", "cells_outside_declared_labels": 1,
            "distinct_extra_values": 1, "counts_by_distinct_extra_value": [1], "empty_label_cells": 0,
            "status": "ENUM_EXTENDED_FROM_EXISTING_VALUES",
        }]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["metrics"]["enum_values"], "PASS")
        target.table_rows = [(1, 7, Decimal("2.30"), b"\x00x", "hithere", "a")]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertMetric(result, "enum_values", "DIVERGED")
        self.assertNotIn("legacy", repr(result))

    def test_nul_evidence_mismatch_blocks_instead_of_passing(self):
        source, target, snapshot, report, mappings = base_contract()
        report["nul_values_normalized"][0]["nul_bytes"] = 9
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "BLOCKED")
        self.assertEqual(result["metrics"]["nul_normalization"], "BLOCKED")
        self.assertIn("NUL_EVIDENCE_INCOMPLETE_OR_MISMATCHED", result["blockers"])

    def test_binary_nul_policy_divergence_is_not_reported_as_pass(self):
        source, target, snapshot, report, mappings = base_contract()
        target.table_rows = [(1, 7, Decimal("2.30"), b"xy", "hithere", "a")]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "DIVERGED")
        self.assertMetric(result, "nul_normalization", "DIVERGED")

    def test_fk_orphan_counts_are_compared_and_reported_only_as_counts(self):
        source, target, snapshot, report, mappings = base_contract(source_orphans=0, target_orphans=0)
        snapshot["foreign_keys"] = [{
            "table": "synthetic", "name": "fk_self", "columns": ["tenant_id"],
            "referenced_schema": "erp_pg_source", "referenced_table": "synthetic",
            "referenced_columns": ["tenant_id"],
        }]
        report["foreign_key_results"] = [{
            "table": "synthetic", "constraint_name": "fk_self", "referenced_schema": "erp",
            "referenced_table": "synthetic", "columns": ["tenant_id"], "referenced_columns": ["tenant_id"],
            "update_rule": "NO ACTION", "delete_rule": "NO ACTION",
            "orphan_rows": 0, "status": "VALIDATED",
        }]
        target.foreign_key_definitions = [("erp", "synthetic", ["tenant_id"], "erp", "synthetic", ["tenant_id"], True, "a", "a", "fk_self")]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertMetric(result, "foreign_keys_orphans", "PASS")
        self.assertTrue(result["foreign_keys"][0]["constraint_present"])
        self.assertTrue(result["foreign_keys"][0]["constraint_validated"])
        target.orphan_count = 1
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertMetric(result, "foreign_keys_orphans", "DIVERGED")
        self.assertEqual(result["foreign_keys"][0]["target_orphans"], 1)

    def test_matching_orphans_allow_only_reported_not_valid_fk(self):
        source, target, snapshot, report, mappings = base_contract(source_orphans=1, target_orphans=1)
        snapshot["foreign_keys"] = [{
            "table": "synthetic", "name": "fk_self", "columns": ["tenant_id"],
            "referenced_schema": "erp_pg_source", "referenced_table": "synthetic",
            "referenced_columns": ["tenant_id"], "update_rule": "NO ACTION", "delete_rule": "NO ACTION",
        }]
        report["foreign_key_results"] = [{
            "table": "synthetic", "constraint_name": "fk_self", "referenced_schema": "erp",
            "referenced_table": "synthetic", "columns": ["tenant_id"], "referenced_columns": ["tenant_id"],
            "update_rule": "NO ACTION", "delete_rule": "NO ACTION",
            "orphan_rows": 1, "status": "NOT_VALID_ORPHANS_REPORTED",
        }]
        target.foreign_key_definitions = [(
            "erp", "synthetic", ["tenant_id"], "erp", "synthetic", ["tenant_id"], False, "a", "a", "fk_self",
        )]

        result = reconcile(source, target, snapshot, report, mappings)

        self.assertMetric(result, "foreign_keys_orphans", "PASS")
        self.assertEqual(result["foreign_keys"][0]["source_orphans"], 1)
        self.assertEqual(result["foreign_keys"][0]["target_orphans"], 1)
        self.assertFalse(result["foreign_keys"][0]["constraint_validated"])
        self.assertTrue(result["foreign_keys"][0]["constraint_state_acceptable"])
        target.foreign_key_definitions[0] = (
            "erp", "synthetic", ["tenant_id"], "erp", "synthetic", ["tenant_id"], True, "a", "a", "fk_self",
        )
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertMetric(result, "foreign_keys_orphans", "DIVERGED")

    def test_source_target_orphan_difference_diverges(self):
        source, target, snapshot, report, mappings = base_contract(source_orphans=1, target_orphans=0)
        snapshot["foreign_keys"] = [{
            "table": "synthetic", "name": "fk_self", "columns": ["tenant_id"],
            "referenced_schema": "erp_pg_source", "referenced_table": "synthetic",
            "referenced_columns": ["tenant_id"],
        }]
        report["foreign_key_results"] = [{
            "table": "synthetic", "constraint_name": "fk_self", "referenced_schema": "erp",
            "referenced_table": "synthetic", "columns": ["tenant_id"], "referenced_columns": ["tenant_id"],
            "update_rule": "NO ACTION", "delete_rule": "NO ACTION",
            "orphan_rows": 0, "status": "VALIDATED",
        }]
        target.foreign_key_definitions = [("erp", "synthetic", ["tenant_id"], "erp", "synthetic", ["tenant_id"], True, "a", "a", "fk_self")]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertMetric(result, "foreign_keys_orphans", "DIVERGED")

    def test_target_fk_must_exist_and_be_validated(self):
        source, target, snapshot, report, mappings = base_contract()
        snapshot["foreign_keys"] = [{
            "table": "synthetic", "name": "fk_self", "columns": ["tenant_id"],
            "referenced_schema": "erp_pg_source", "referenced_table": "synthetic",
            "referenced_columns": ["tenant_id"], "update_rule": "NO ACTION", "delete_rule": "NO ACTION",
        }]
        report["foreign_key_results"] = [{
            "table": "synthetic", "constraint_name": "fk_self", "referenced_schema": "erp",
            "referenced_table": "synthetic", "columns": ["tenant_id"], "referenced_columns": ["tenant_id"],
            "update_rule": "NO ACTION", "delete_rule": "NO ACTION",
            "orphan_rows": 0, "status": "VALIDATED",
        }]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertMetric(result, "foreign_keys_orphans", "DIVERGED")
        self.assertFalse(result["foreign_keys"][0]["constraint_present"])
        target.foreign_key_definitions = [("erp", "synthetic", ["tenant_id"], "erp", "synthetic", ["tenant_id"], False, "a", "a", "fk_self")]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertMetric(result, "foreign_keys_orphans", "DIVERGED")
        self.assertTrue(result["foreign_keys"][0]["constraint_present"])
        self.assertFalse(result["foreign_keys"][0]["constraint_validated"])

    def test_fk_signature_distinguishes_same_child_columns_with_different_targets(self):
        source, target, snapshot, report, mappings = base_contract()
        for parent in ("parent_a", "parent_b"):
            mappings[parent] = {"target_schema": "erp", "types": {"tenant_id": object()}}
        snapshot["foreign_keys"] = [
            {"table": "synthetic", "name": "fk_a", "columns": ["tenant_id"], "referenced_schema": "erp_pg_source",
             "referenced_table": "parent_a", "referenced_columns": ["tenant_id"], "update_rule": "NO ACTION", "delete_rule": "NO ACTION"},
            {"table": "synthetic", "name": "fk_b", "columns": ["tenant_id"], "referenced_schema": "erp_pg_source",
             "referenced_table": "parent_b", "referenced_columns": ["tenant_id"], "update_rule": "CASCADE", "delete_rule": "RESTRICT"},
        ]
        report["foreign_key_results"] = [
            {"table": "synthetic", "constraint_name": "fk_a", "referenced_schema": "erp", "referenced_table": "parent_a",
             "columns": ["tenant_id"], "referenced_columns": ["tenant_id"], "update_rule": "NO ACTION", "delete_rule": "NO ACTION",
             "orphan_rows": 0, "status": "VALIDATED"},
            {"table": "synthetic", "constraint_name": "fk_b", "referenced_schema": "erp", "referenced_table": "parent_b",
             "columns": ["tenant_id"], "referenced_columns": ["tenant_id"], "update_rule": "CASCADE", "delete_rule": "RESTRICT",
             "orphan_rows": 0, "status": "VALIDATED"},
        ]
        target.foreign_key_definitions = [
            ("erp", "synthetic", ["tenant_id"], "erp", "parent_a", ["tenant_id"], True, "a", "a", "fk_a"),
            ("erp", "synthetic", ["tenant_id"], "erp", "parent_b", ["tenant_id"], True, "c", "r", "fk_b"),
        ]
        ok, comparison = _foreign_key_metric(source, target, snapshot, report, mappings)
        self.assertTrue(ok)
        self.assertEqual(len(comparison), 2)
        self.assertTrue(all(item["constraint_present"] and item["constraint_actions_match"] for item in comparison))

    def test_unexpected_target_foreign_key_diverges_when_source_has_none(self):
        source, target, snapshot, report, mappings = base_contract()
        target.foreign_key_definitions = [
            ("erp", "synthetic", ["tenant_id"], "erp", "external_parent", ["tenant_id"], True, "a", "a", "fk_unexpected")
        ]
        ok, comparison = _foreign_key_metric(source, target, snapshot, report, mappings)
        self.assertFalse(ok)
        self.assertEqual(comparison, [])

    def test_identity_sequence_state_checked_against_max_id(self):
        result, _, target = run_contract()
        self.assertMetric(result, "identity_sequences", "PASS")
        source, target, snapshot, report, mappings = base_contract()
        target.sequence_last = 1
        target.sequence_called = False
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertMetric(result, "identity_sequences", "DIVERGED")

    def test_identity_sequence_exhaustion_blocks(self):
        source, target, snapshot, report, mappings = base_contract()
        target.sequence_last = POSTGRES_SEQUENCE_MAX
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "BLOCKED")
        self.assertEqual(result["failure_code"], "IDENTITY_SEQUENCE_EXHAUSTED")

    def test_identity_sync_state_missing_blocks(self):
        source, target, snapshot, report, mappings = base_contract()
        target.identity_sync_complete = False
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "BLOCKED")
        self.assertEqual(result["failure_code"], "IDENTITY_SYNC_STATE_INCOMPLETE")

    def test_enum_evidence_mismatch_diverges(self):
        source, target, snapshot, report, mappings = base_contract()
        report["enum_values_outside_declared"] = [{
            "table": "synthetic", "column": "state", "cells_outside_declared_labels": 1,
            "distinct_extra_values": 1, "counts_by_distinct_extra_value": [1], "empty_label_cells": 0,
            "status": "ENUM_EXTENDED_FROM_EXISTING_VALUES",
        }]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertMetric(result, "enum_values", "DIVERGED")

    def test_missing_policy_evidence_is_blocked(self):
        source, target, snapshot, report, mappings = base_contract()
        del report["text_policy_findings"]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "BLOCKED")
        self.assertEqual(result["failure_code"], "LOAD_POLICY_EVIDENCE_MISSING")

    def test_target_database_outside_allowlist_fails_before_table_scan(self):
        result, _, target = run_contract(target_db="erp_pg_real")
        self.assertEqual(result["status"], "BLOCKED")
        self.assertEqual(result["failure_code"], "TARGET_DATABASE_NOT_ALLOWLISTED")
        self.assertFalse(any(" FROM \"erp\"." in sql for sql in target.queries))

    def test_unsigned_identity_sequence_pair_is_reconciled(self):
        source, target, snapshot, report, mappings = base_contract()
        mappings["synthetic"]["identity_columns"] = [{
            "column": "id", "strategy": "unsigned_sequence_pair",
            "low_sequence": "u64_low_synthetic_id", "high_sequence": "u64_high_synthetic_id",
        }]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "RECONCILED", result)
        self.assertEqual(result["metrics"]["identity_sequences"], "PASS")

    def test_unsigned_sequence_state_key_uses_legacy_table_schema(self):
        source, target, snapshot, report, mappings = base_contract()
        mappings["synthetic"]["target_schema"] = "legado"
        mappings["synthetic"]["identity_columns"] = [{
            "column": "id", "strategy": "unsigned_sequence_pair",
            "low_sequence": "u64_low_synthetic_id", "high_sequence": "u64_high_synthetic_id",
        }]
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "RECONCILED", result)
        self.assertEqual(target.sequence_lookup_keys, ["legado.synthetic.id"])

    def test_unsigned_sequence_pair_exhaustion_blocks(self):
        source, target, snapshot, report, mappings = base_contract()
        mappings["synthetic"]["identity_columns"] = [{
            "column": "id", "strategy": "unsigned_sequence_pair",
            "low_sequence": "u64_low_synthetic_id", "high_sequence": "u64_high_synthetic_id",
        }]
        target.unsigned_sequences = {
            "u64_low_synthetic_id": (1, 1, POSTGRES_SEQUENCE_MAX, POSTGRES_SEQUENCE_MAX),
            "u64_high_synthetic_id": (0, 1, POSTGRES_SEQUENCE_MAX, POSTGRES_SEQUENCE_MAX),
        }
        target.unsigned_called = {"u64_low_synthetic_id": True, "u64_high_synthetic_id": True}
        result = reconcile(source, target, snapshot, report, mappings)
        self.assertEqual(result["status"], "BLOCKED")
        self.assertEqual(result["failure_code"], "IDENTITY_SEQUENCE_EXHAUSTED")


if __name__ == "__main__":
    unittest.main()
