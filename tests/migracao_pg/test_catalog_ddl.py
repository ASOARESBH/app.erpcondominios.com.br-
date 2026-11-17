from __future__ import annotations

import unittest
import json
import hashlib
from pathlib import Path
from unittest.mock import patch
from tempfile import TemporaryDirectory

from tools.migracao_pg.catalog import CatalogError, _atomic_json, _grants_are_read_only, _has_schema_wide_select, _mysql_socket_options, _safe_default, _source_grant_inventory, collect_catalog
from tools.migracao_pg.ddl import DdlGenerationError, canonical_fingerprint, generate_ddl
from tools.migracao_pg.generate_ddl import main as generate_ddl_main


def sample_snapshot() -> dict:
    snapshot = {
        "catalog_version": 2,
        "source_schema": "erp_pg_source",
        "source_server_version": "8.4.13",
        "captured_at_utc": "2026-11-16T00:00:00+00:00",
        "tables": [
            {
                "name": "parent",
                "type": "BASE TABLE",
                "engine": "InnoDB",
                "source_collation": "utf8mb4_unicode_ci",
                "source_next_auto_increment": 10,
                "columns": [
                    {"name": "id", "ordinal": 1, "nullable": False, "data_type": "int", "column_type": "int unsigned", "character_set": None, "source_collation": None, "extra": "auto_increment"},
                    {"name": "name", "ordinal": 2, "nullable": False, "data_type": "varchar", "column_type": "varchar(80)", "character_set": "utf8mb4", "source_collation": "utf8mb4_unicode_ci", "extra": ""},
                ],
                "primary_key": [{"ordinal": 1, "column": "id"}],
            },
            {
                "name": "child",
                "type": "BASE TABLE",
                "engine": "InnoDB",
                "source_collation": "utf8mb4_0900_ai_ci",
                "source_next_auto_increment": None,
                "columns": [
                    {"name": "parent_id", "ordinal": 1, "nullable": False, "data_type": "int", "column_type": "int unsigned", "character_set": None, "source_collation": None, "extra": ""},
                    {"name": "state", "ordinal": 2, "nullable": False, "data_type": "enum", "column_type": "enum('A','B')", "character_set": "utf8mb4", "source_collation": "utf8mb4_0900_ai_ci", "extra": "", "default": {"kind": "sql", "expression": "'A'"}},
                    {"name": "updated_at", "ordinal": 3, "nullable": False, "data_type": "datetime", "column_type": "datetime", "character_set": None, "source_collation": None, "extra": "DEFAULT_GENERATED on update CURRENT_TIMESTAMP", "default": {"kind": "sql", "expression": "LOCALTIMESTAMP(0)"}},
                    {"name": "api_token", "ordinal": 4, "nullable": True, "data_type": "varchar", "column_type": "varchar(64)", "character_set": "utf8mb4", "source_collation": "utf8mb4_unicode_ci", "extra": ""},
                ],
                "primary_key": [],
            },
        ],
        "indexes": [
            {"table": "parent", "name": "PRIMARY", "non_unique": False, "type": "BTREE", "visible": True, "columns": [{"ordinal": 1, "name": "id", "prefix_length": None, "direction": "ASC", "expression_present": False}]},
            {"table": "parent", "name": "uq_parent_name", "non_unique": False, "type": "BTREE", "visible": True, "columns": [{"ordinal": 1, "name": "name", "prefix_length": None, "direction": "ASC", "expression_present": False}]},
            {"table": "child", "name": "idx_child_parent", "non_unique": True, "type": "BTREE", "visible": True, "columns": [{"ordinal": 1, "name": "parent_id", "prefix_length": None, "direction": "ASC", "expression_present": False}]},
        ],
        "foreign_keys": [
            {"table": "child", "name": "fk_child_parent", "columns": ["parent_id"], "referenced_schema": "erp_pg_source", "referenced_table": "parent", "referenced_columns": ["id"], "update_rule": "CASCADE", "delete_rule": "RESTRICT"},
        ],
        "check_constraints": [],
        "triggers": [{"name": "t", "event": "UPDATE", "table": "child", "timing": "BEFORE", "body_sha256": "0" * 64}],
        "views": [],
        "routines": [],
        "events": [],
        "metadata_visibility": {"views": True, "triggers": True, "routines": True, "events": True},
        "source_grants_read_only": True,
        "source_schema_wide_select": True,
        "review_items": [],
        "fingerprint_sha256": "",
    }
    snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
    return snapshot


class FakeCursor:
    def __init__(self) -> None:
        self.current: list[dict] = []

    def execute(self, sql: str, args=()) -> None:
        if "CURRENT_USER()" in sql:
            self.current = [{"connected_account": "pg_reader@localhost", "selected_database": "erp_pg_source", "server_version": "8.4.13"}]
        elif "SHOW GRANTS" in sql:
            self.current = [{"grants": "GRANT SELECT, SHOW VIEW ON `erp_pg_source`.* TO 'pg_reader'@'localhost'"}]
        elif "information_schema.user_privileges" in sql:
            self.current = [{"privilege_type": "ALL PRIVILEGES"}]
        elif "information_schema.tables" in sql:
            self.current = [{"table_name": "x", "table_type": "BASE TABLE", "engine": "InnoDB", "table_collation": "utf8mb4_unicode_ci", "auto_increment": None}]
        elif "information_schema.columns" in sql:
            if "generation_expression" in sql:
                self.current = [{"table_name": "x", "column_name": "id", "ordinal_position": 1, "column_default": None, "is_nullable": "NO", "data_type": "int", "column_type": "int", "character_set_name": None, "collation_name": None, "extra": "", "generation_expression": ""}]
            else:
                self.current = []
        elif "information_schema.statistics" in sql:
            self.current = []
        elif "information_schema.key_column_usage" in sql:
            self.current = [{"table_name": "x", "constraint_name": "PRIMARY", "ordinal_position": 1, "column_name": "id", "referenced_table_schema": None, "referenced_table_name": None, "referenced_column_name": None}]
        elif "information_schema.table_constraints" in sql:
            self.current = []
        elif "information_schema.referential_constraints" in sql:
            self.current = []
        elif "information_schema.triggers" in sql:
            self.current = []
        elif "information_schema.views" in sql:
            self.current = []
        else:
            self.current = []

    def fetchone(self):
        return self.current[0] if self.current else None

    def fetchall(self):
        return self.current

    def close(self):
        pass


class FakeConnection:
    def __init__(self) -> None:
        self.c = FakeCursor()
        self.rolled_back = False

    def cursor(self):
        return self.c

    def rollback(self):
        self.rolled_back = True


class CatalogTests(unittest.TestCase):
    def test_catalog_connection_requires_unix_socket(self) -> None:
        with TemporaryDirectory() as directory:
            path = Path(directory) / "client.cnf"
            path.write_text("[client]\nprotocol=SOCKET\nsocket=/run/mysqld/mysqld.sock\n", encoding="utf-8")
            self.assertEqual(_mysql_socket_options(path), {"unix_socket": "/run/mysqld/mysqld.sock"})
            path.write_text("[client]\nprotocol=TCP\nhost=localhost\n", encoding="utf-8")
            with self.assertRaisesRegex(CatalogError, "MYSQL_PROTOCOL_MUST_BE_SOCKET"):
                _mysql_socket_options(path)

    def test_grant_inventory_is_sanitized_and_unresolved_roles_fail_closed(self) -> None:
        class GrantsCursor:
            def __init__(self, rows):
                self.rows = rows
            def execute(self, sql, args=()):
                self.sql = sql
            def fetchall(self):
                return self.rows

        cursor = GrantsCursor([
            {"grant": "GRANT SELECT, SHOW VIEW, TRIGGER, SHOW ROUTINE, EVENT ON `erp_pg_source`.* TO 'pg_reader'@'localhost'"},
        ])
        inventory, visibility, unresolved = _source_grant_inventory(cursor, "erp_pg_source")
        self.assertTrue(all(visibility.values()))
        self.assertFalse(unresolved)
        self.assertNotIn("pg_reader", repr(inventory))

        cursor = GrantsCursor([{"grant": "GRANT `catalog_reader`@`%` TO 'pg_reader'@'localhost'"}])
        _, visibility, unresolved = _source_grant_inventory(cursor, "erp_pg_source")
        self.assertTrue(unresolved)
        self.assertFalse(any(visibility.values()))

        cursor = GrantsCursor([{"grant": "GRANT SELECT ON `erp_pg_source`.* TO 'pg_reader'@'localhost' WITH GRANT OPTION"}])
        inventory, _, unresolved = _source_grant_inventory(cursor, "erp_pg_source")
        self.assertTrue(unresolved)
        self.assertEqual(inventory, [])

        cursor = GrantsCursor([{"grant": "GRANT TRIGGER ON `erp_pg_source`.`one_table` TO 'pg_reader'@'localhost'"}])
        inventory, visibility, unresolved = _source_grant_inventory(cursor, "erp_pg_source")
        self.assertFalse(unresolved)
        self.assertEqual(inventory[0]["scope"], "SCHEMA_OBJECTS")
        self.assertFalse(any(visibility.values()))

    def test_source_account_must_have_read_only_privileges(self) -> None:
        self.assertTrue(_grants_are_read_only([
            {"scope": "GLOBAL", "privileges": ["USAGE"]},
            {"scope": "SCHEMA", "privileges": ["SELECT", "SHOW VIEW"]},
        ]))
        self.assertFalse(_grants_are_read_only([{"scope": "SCHEMA", "privileges": ["ALL PRIVILEGES"]}]))
        self.assertFalse(_grants_are_read_only([{"scope": "SCHEMA", "privileges": ["SELECT", "TRIGGER"]}]))
        self.assertTrue(_has_schema_wide_select([{"scope": "SCHEMA", "privileges": ["SELECT"]}]))
        self.assertFalse(_has_schema_wide_select([{"scope": "SCHEMA_OBJECTS", "privileges": ["SELECT"]}]))

    def test_catalog_default_allowlist_does_not_export_arbitrary_string_values(self) -> None:
        stamp = {"data_type": "timestamp", "column_type": "timestamp", "column_default": "CURRENT_TIMESTAMP"}
        self.assertEqual(_safe_default(stamp), {"kind": "sql", "expression": "timezone('America/Sao_Paulo', statement_timestamp())"})
        unsafe = {"data_type": "varchar", "column_type": "varchar(100)", "column_default": "token-like-string"}
        self.assertEqual(_safe_default(unsafe), {"kind": "review", "code": "UNSAFE_LITERAL_OR_FUNCTION_DEFAULT"})
        text_null = {"data_type": "varchar", "column_type": "varchar(100)", "column_default": "NULL"}
        self.assertEqual(_safe_default(text_null), {"kind": "review", "code": "AMBIGUOUS_TEXT_NULL_DEFAULT_REVIEW_REQUIRED"})
        enum = {"data_type": "enum", "column_type": "enum('A','B')", "column_default": "A"}
        self.assertEqual(_safe_default(enum), {"kind": "sql", "expression": "'A'"})
        enum_null = {"data_type": "enum", "column_type": "enum('NULL','B')", "column_default": "NULL"}
        self.assertEqual(_safe_default(enum_null), {"kind": "sql", "expression": "'NULL'"})
        enum_null_ambiguous = {"data_type": "enum", "column_type": "enum('A','B')", "column_default": "NULL"}
        self.assertEqual(_safe_default(enum_null_ambiguous), {"kind": "review", "code": "AMBIGUOUS_ENUM_NULL_DEFAULT_REVIEW_REQUIRED"})

    def test_catalog_is_metadata_only_and_rolls_back_read_transaction(self) -> None:
        connection = FakeConnection()
        result = collect_catalog(connection)
        self.assertEqual(result["source_schema"], "erp_pg_source")
        self.assertEqual(len(result["collector_code_sha256"]), 64)
        self.assertEqual(result["tables"][0]["name"], "x")
        self.assertTrue(result["source_grants_read_only"])
        self.assertTrue(result["source_schema_wide_select"])
        self.assertEqual(len(result["structural_fingerprint_sha256"]), 64)
        self.assertNotIn("view_definition", result["views"])
        self.assertTrue(connection.rolled_back)

    def test_catalog_output_is_private_and_never_overwritten(self) -> None:
        with TemporaryDirectory() as directory:
            path = Path(directory) / "snapshot.json"
            _atomic_json(path, {"source_schema": "erp_pg_source"})
            before = path.read_text(encoding="utf-8")
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            with self.assertRaisesRegex(CatalogError, "CATALOG_OUTPUT_ALREADY_EXISTS"):
                _atomic_json(path, {"source_schema": "wrong"})
            self.assertEqual(path.read_text(encoding="utf-8"), before)

    def test_catalog_refuses_other_account_or_database(self) -> None:
        class WrongIdentityCursor(FakeCursor):
            def execute(self, sql, args=()):
                super().execute(sql, args)
                if "CURRENT_USER()" in sql:
                    self.current[0]["connected_account"] = "root@localhost"
        class WrongConnection(FakeConnection):
            def __init__(self):
                self.c = WrongIdentityCursor()
                self.rolled_back = False
        with self.assertRaisesRegex(CatalogError, "MYSQL_ACCOUNT_MUST_BE_PG_READER"):
            collect_catalog(WrongConnection())

    def test_ddl_creates_identity_enum_check_on_update_and_fk_not_valid(self) -> None:
        bundle = generate_ddl(sample_snapshot(), collation_overrides={"child.api_token": "C"})
        tables = bundle.scripts["00_tables.sql"]
        triggers = bundle.scripts["05_on_update_triggers.sql"]
        fks = bundle.scripts["20_foreign_keys_not_valid.sql"]
        sequences = bundle.scripts["30_post_load_sequences.sql"]
        self.assertIn('"id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL', tables)
        self.assertIn("CHECK (\"state\" IN ('A', 'B'))", tables)
        self.assertIn("COLLATE ci", tables)
        self.assertIn('COLLATE "C"', tables)
        self.assertIn("CREATE TRIGGER", triggers)
        self.assertIn("IS DISTINCT FROM", triggers)
        self.assertIn('FOREIGN KEY ("parent_id")', fks)
        self.assertIn("NOT VALID;", fks)
        self.assertEqual(bundle.report["generated"]["on_update_triggers"], 1)
        self.assertEqual(bundle.report["foreign_keys"]["generated_not_valid"], 1)
        self.assertIn("pg_get_serial_sequence", sequences)
        self.assertIn("v_next := GREATEST(COALESCE(v_max, 0), 9)", sequences)
        self.assertIn("pg_catalog.pg_sequence_last_value(v_sequence::regclass)", sequences)
        self.assertIn("BEGIN;", sequences)
        self.assertIn("COMMIT;", sequences)
        self.assertIn("LOCK TABLE \"erp\".\"parent\" IN ACCESS EXCLUSIVE MODE", sequences)
        self.assertIn("post-load sequence sync is one-shot", sequences)
        self.assertIn("sequence sync already started; manual recovery required", sequences)
        self.assertEqual(bundle.report["auto_increment"]["generated"], 1)
        self.assertFalse(bundle.report["executable_complete"])

    def test_unsigned_bigint_auto_increment_uses_nontransactional_sequence_pair(self) -> None:
        snapshot = sample_snapshot()
        parent = snapshot["tables"][0]
        parent["columns"][0].update(data_type="bigint", column_type="bigint unsigned", extra="auto_increment")
        parent["source_next_auto_increment"] = 15
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"parent.name": "ci", "child.state": "ci", "child.api_token": "C"}, application_role="erp_app")
        tables = bundle.scripts["00_tables.sql"]
        sequences = bundle.scripts["30_post_load_sequences.sql"]
        self.assertIn('"id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"(\'parent.id\') NOT NULL', tables)
        self.assertIn('CREATE TABLE "erp"."_p2_autoincrement_state"', tables)
        self.assertIn("CREATE SEQUENCE \"erp\".\"p2_u64_lo_parent_id\"", tables)
        self.assertIn("CREATE SEQUENCE \"erp\".\"p2_u64_hi_parent_id\"", tables)
        self.assertIn('INSERT INTO "erp"."_p2_autoincrement_state" (sequence_name, low_sequence, high_sequence)', tables)
        self.assertIn("REVOKE ALL ON SEQUENCE", tables)
        self.assertIn('GRANT EXECUTE ON FUNCTION "erp"."_p2_next_unsigned_id"(TEXT) TO "erp_app"', tables)
        self.assertIn('REVOKE ALL ON TABLE "erp"."_p2_autoincrement_state" FROM "erp_app"', tables)
        self.assertIn("WHEN SQLSTATE '2200H'", tables)
        self.assertIn("pg_catalog.nextval(v_low_sequence)", tables)
        self.assertIn("pg_catalog.nextval(v_high_sequence)", tables)
        self.assertNotIn("UPDATE \"erp\".\"_p2_autoincrement_state\" SET", tables)
        self.assertLess(tables.index('CREATE TABLE "erp"."_p2_autoincrement_state"'), tables.index('INSERT INTO "erp"."_p2_autoincrement_state"'))
        self.assertIn('GREATEST(COALESCE(MAX("id"), 0), 14)', sequences)
        self.assertIn("pg_catalog.setval(v_low_sequence", sequences)
        self.assertIn("pg_catalog.setval(v_high_sequence", sequences)
        self.assertIn("post-load sequence sync is one-shot", sequences)
        self.assertIn("sequence sync already started; manual recovery required", sequences)
        self.assertIn("LOCK TABLE \"erp\".\"parent\" IN ACCESS EXCLUSIVE MODE", sequences)
        self.assertIn("BEGIN;", sequences)
        self.assertIn("COMMIT;", sequences)
        self.assertNotIn("pg_get_serial_sequence", sequences)
        self.assertEqual(bundle.report["generated"]["unsigned_sequence_pairs"], 1)
        self.assertEqual(bundle.report["auto_increment"]["columns"][0]["strategy"], "unsigned_sequence_pair")
        self.assertTrue(bundle.report["auto_increment"]["runtime_privileges"]["unsigned_function_execute_granted"])

    def test_unsigned_bigint_allocator_rollover_boundary_is_represented_exactly(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["columns"][0].update(data_type="bigint", column_type="bigint unsigned", extra="auto_increment")
        snapshot["tables"][0]["source_next_auto_increment"] = 9223372036854775808
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"parent.name": "ci", "child.state": "ci", "child.api_token": "C"})
        postload = bundle.scripts["30_post_load_sequences.sql"]
        self.assertIn("v_current <= 9223372036854775807", postload)
        self.assertIn("GREATEST(COALESCE(MAX(\"id\"), 0), 9223372036854775807)", postload)
        self.assertIn("18446744073709551615", bundle.scripts["00_tables.sql"])

    def test_unsigned_bigint_allocator_requires_explicit_application_role(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["columns"][0].update(data_type="bigint", column_type="bigint unsigned", extra="auto_increment")
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"parent.name": "ci", "child.state": "ci", "child.api_token": "C"})
        self.assertIn("AUTO_INCREMENT_APPLICATION_ROLE_REQUIRED", {item["code"] for item in bundle.report["review_items"]})
        self.assertFalse(bundle.report["auto_increment"]["runtime_privileges"]["unsigned_function_execute_granted"])
        self.assertFalse(bundle.report["executable_complete"])

    def test_prefix_index_and_unresolved_checks_are_reported_not_emitted(self) -> None:
        snapshot = sample_snapshot()
        snapshot["indexes"].append({"table": "parent", "name": "prefix_idx", "non_unique": True, "type": "BTREE", "visible": True, "columns": [{"ordinal": 1, "name": "name", "prefix_length": 10, "direction": "ASC", "expression_present": False}]})
        snapshot["check_constraints"] = [{"table": "parent", "name": "chk_business", "definition_sha256": "2" * 64, "enforced": True}]
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertEqual(bundle.report["unsupported_indexes"][0]["code"], "FUNCTIONAL_OR_PREFIX_INDEX_REVIEW_REQUIRED")
        self.assertTrue(any(item.get("code") == "MYSQL_CHECK_EXPRESSION_TRANSLATION_REQUIRED" for item in bundle.report["review_items"]))
        self.assertFalse(bundle.report["executable_complete"])

    def test_sensitive_collation_requires_explicit_confirmation(self) -> None:
        snapshot = sample_snapshot()
        bundle = generate_ddl(snapshot)
        self.assertTrue(any(item.get("code") == "SENSITIVE_COLLATION_CONFIRMATION_REQUIRED" for item in bundle.report["review_items"]))
        confirmed = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertFalse(any(item.get("code") == "SENSITIVE_COLLATION_CONFIRMATION_REQUIRED" for item in confirmed.report["review_items"]))

    def test_non_allowlisted_collation_requires_review(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["columns"][1]["source_collation"] = "utf8mb4_0900_as_ci"
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        self.assertTrue(any(item.get("code") == "SOURCE_COLLATION_SEMANTICS_REVIEW_REQUIRED" for item in bundle.report["review_items"]))
        self.assertFalse(bundle.report["executable_complete"])

    def test_indexes_and_foreign_keys_of_blocked_tables_are_deferred(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["columns"][1]["source_collation"] = "utf8mb4_unknown_semantics"
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertTrue(any(item["table"] == "parent" for item in bundle.report["blocked_tables"]))
        self.assertIn("INDEX_TABLE_DDL_BLOCKED", {item["code"] for item in bundle.report["deferred_indexes"]})
        self.assertIn("FK_TARGET_TABLE_DDL_BLOCKED", {item["code"] for item in bundle.report["foreign_keys"]["deferred"]})
        self.assertNotIn("INDEX_TABLE_NOT_IN_CATALOG", {item["code"] for item in bundle.report["unsupported_indexes"]})

    def test_non_innodb_table_is_blocked(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["engine"] = "MyISAM"
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertIn("NON_TRANSACTIONAL_OR_UNSUPPORTED_ENGINE", {item["code"] for item in bundle.report["blocked_tables"]})
        self.assertFalse(bundle.report["executable_complete"])

    def test_override_must_reference_a_real_text_column(self) -> None:
        snapshot = sample_snapshot()
        with self.assertRaisesRegex(DdlGenerationError, "COLLATION_OVERRIDE_COLUMN_NOT_FOUND"):
            generate_ddl(snapshot, collation_overrides={"child.not_a_column": "C"})

    def test_ddl_requires_source_read_only_grant_proof(self) -> None:
        snapshot = sample_snapshot()
        snapshot.pop("source_grants_read_only")
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertIn("SOURCE_ACCOUNT_READ_ONLY_NOT_PROVEN", {item["code"] for item in bundle.report["review_items"]})
        self.assertFalse(bundle.report["executable_complete"])

    def test_malicious_index_direction_is_not_emitted(self) -> None:
        snapshot = sample_snapshot()
        snapshot["indexes"][2]["columns"][0]["direction"] = "ASC); DROP TABLE erp.parent; --"
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertNotIn("DROP TABLE", bundle.scripts["10_indexes.sql"])
        self.assertEqual(bundle.report["unsupported_indexes"][0]["code"], "INDEX_DIRECTION_INVALID")
        self.assertFalse(bundle.report["executable_complete"])

    def test_malicious_default_is_not_emitted(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][1]["columns"][1]["default"]["expression"] = "'A'); DROP TABLE erp.parent; --"
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertNotIn("DROP TABLE", bundle.scripts["00_tables.sql"])
        self.assertIn("DEFAULT_EXPRESSION_NOT_ALLOWLISTED", {item["code"] for item in bundle.report["review_items"]})
        self.assertFalse(bundle.report["executable_complete"])

    def test_foreign_key_is_blocked_when_referenced_unique_index_is_not_emitted(self) -> None:
        snapshot = sample_snapshot()
        snapshot["indexes"][1]["visible"] = False
        snapshot["tables"][1]["columns"].append({"name": "name", "ordinal": 5, "nullable": True, "data_type": "varchar", "column_type": "varchar(80)", "character_set": "utf8mb4", "source_collation": "utf8mb4_unicode_ci", "extra": ""})
        snapshot["foreign_keys"][0]["columns"] = ["name"]
        snapshot["foreign_keys"][0]["referenced_columns"] = ["name"]
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertEqual(bundle.report["foreign_keys"]["generated_not_valid"], 0)
        self.assertTrue(any(item["code"] == "FK_TARGET_NOT_UNIQUE" for item in bundle.report["foreign_keys"]["unsupported"]))

    def test_external_foreign_key_reports_target_scope(self) -> None:
        snapshot = sample_snapshot()
        snapshot["foreign_keys"][0]["referenced_schema"] = "external_schema"
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        item = next(row for row in bundle.report["foreign_keys"]["unsupported"] if row["code"] == "FK_REFERENCE_OUTSIDE_SOURCE_SCHEMA")
        self.assertEqual(item["referenced_schema"], "external_schema")
        self.assertEqual(item["referenced_table"], "parent")

    def test_routines_and_events_are_never_silently_ignored(self) -> None:
        snapshot = sample_snapshot()
        snapshot["routines"] = [{"name": "maintenance", "type": "PROCEDURE", "body_sha256": "3" * 64}]
        snapshot["events"] = [{"name": "daily_event", "body_sha256": "4" * 64}]
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        codes = {item["code"] for item in bundle.report["review_items"]}
        self.assertIn("ROUTINE_TRANSLATION_REQUIRED", codes)
        self.assertIn("EVENT_TRANSLATION_REQUIRED", codes)
        self.assertFalse(bundle.report["executable_complete"])

    def test_missing_object_metadata_visibility_blocks_ddl(self) -> None:
        snapshot = sample_snapshot()
        snapshot["metadata_visibility"]["triggers"] = False
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        self.assertIn("METADATA_VISIBILITY_NOT_CONFIRMED", {item["code"] for item in bundle.report["review_items"]})
        self.assertFalse(bundle.report["executable_complete"])

    def test_invalid_auto_increment_counter_blocks_bundle_but_syncs_from_max_id(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["source_next_auto_increment"] = 0
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertIn("AUTO_INCREMENT_NEXT_VALUE_METADATA_INVALID", {item["code"] for item in bundle.report["review_items"]})
        self.assertEqual(bundle.report["auto_increment"]["generated"], 1)
        self.assertIn("GREATEST(COALESCE(v_max, 0), 0)", bundle.scripts["30_post_load_sequences.sql"])
        self.assertFalse(bundle.report["executable_complete"])

    def test_cli_writes_non_executable_bundle_once_and_refuses_overwrite(self) -> None:
        with TemporaryDirectory() as directory:
            root = Path(directory)
            snapshot_path = root / "snapshot.json"
            output_dir = root / "ddl"
            snapshot_path.write_text(json.dumps(sample_snapshot()), encoding="utf-8")
            args = ["generate_ddl", "--snapshot", str(snapshot_path), "--output-dir", str(output_dir)]
            with patch("sys.argv", args):
                self.assertEqual(generate_ddl_main(), 2)
            bundles = list(output_dir.glob("catalog-*-collation-*"))
            self.assertEqual(len(bundles), 1)
            self.assertTrue((bundles[0] / "BUNDLE_COMPLETE").is_file())
            snapshot_copy = bundles[0] / "catalog_snapshot.json"
            self.assertTrue(snapshot_copy.is_file())
            self.assertEqual(json.loads(snapshot_copy.read_text(encoding="utf-8"))["fingerprint_sha256"], sample_snapshot()["fingerprint_sha256"])
            report = json.loads((bundles[0] / "report.json").read_text(encoding="utf-8"))
            self.assertEqual(report["snapshot_file_sha256"], hashlib.sha256(snapshot_copy.read_bytes()).hexdigest())
            self.assertIn('executable_complete": false', (bundles[0] / "report.json").read_text(encoding="utf-8"))
            with patch("sys.argv", args), self.assertRaisesRegex(SystemExit, "OUTPUT_ALREADY_EXISTS"):
                generate_ddl_main()

    def test_fingerprint_tampering_is_rejected(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["name"] = "tampered"
        with self.assertRaisesRegex(DdlGenerationError, "CATALOG_FINGERPRINT_MISMATCH"):
            generate_ddl(snapshot)


if __name__ == "__main__":
    unittest.main()
