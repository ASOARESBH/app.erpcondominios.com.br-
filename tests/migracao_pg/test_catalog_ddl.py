from __future__ import annotations

import unittest
import json
import hashlib
from pathlib import Path
from unittest.mock import patch
from tempfile import TemporaryDirectory

from tools.migracao_pg.catalog import CatalogError, _atomic_json, _foreign_key_action_rules, _grants_are_read_only, _has_schema_wide_select, _mysql_socket_options, _safe_default, _source_grant_inventory, collect_catalog
from tools.migracao_pg.ddl import BUSINESS_TRIGGER_SOURCE_SHA256, DdlGenerationError, _sql_default, canonical_fingerprint, generate_ddl
from tools.migracao_pg.generate_ddl import main as generate_ddl_main
from tools.migracao_pg.generated_columns import identify_generated_column


def sample_snapshot() -> dict:
    snapshot = {
        "catalog_version": 3,
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
        self.statements: list[str] = []

    def execute(self, sql: str, args=()) -> None:
        self.statements.append(sql)
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
        self.statements = self.c.statements
        self.rolled_back = False

    def cursor(self):
        return self.c

    def rollback(self):
        self.rolled_back = True


class CatalogTests(unittest.TestCase):
    def test_generated_column_allowlist_accepts_only_the_approved_water_expression(self) -> None:
        approved = identify_generated_column(
            "lancamentos_agua", "consumo", "decimal", "decimal(10,2)", "STORED GENERATED",
            "((`leitura_atual` - `leitura_anterior`))",
        )
        self.assertEqual(approved["key"], "lancamentos_agua.consumo.v1")
        self.assertEqual(approved["target_expression"], '"leitura_atual" - "leitura_anterior"')
        self.assertIsNone(identify_generated_column(
            "lancamentos_agua", "consumo", "decimal", "decimal(10,2)", "VIRTUAL GENERATED",
            "(`leitura_atual` - `leitura_anterior`)",
        ))
        self.assertIsNone(identify_generated_column(
            "lancamentos_agua", "consumo", "decimal", "decimal(10,2)", "STORED GENERATED",
            "`leitura_atual` + `leitura_anterior`",
        ))

    def test_catalog_connection_requires_unix_socket(self) -> None:
        with TemporaryDirectory() as directory:
            path = Path(directory) / "client.cnf"
            path.write_text("[client]\nuser=pg_reader\nprotocol=SOCKET\nsocket=/run/mysqld/mysqld.sock\n", encoding="utf-8")
            self.assertEqual(_mysql_socket_options(path), {"unix_socket": "/run/mysqld/mysqld.sock"})
            path.write_text("[client]\nuser=pg_reader\nprotocol=TCP\nhost=localhost\n", encoding="utf-8")
            with self.assertRaisesRegex(CatalogError, "MYSQL_PROTOCOL_MUST_BE_SOCKET"):
                _mysql_socket_options(path)
            path.write_text("[client]\nuser=erp_app\nprotocol=SOCKET\n", encoding="utf-8")
            with self.assertRaisesRegex(CatalogError, "MYSQL_ACCOUNT_MUST_BE_PG_READER"):
                _mysql_socket_options(path)
            path.write_text("[client]\nuser=pg_reader\nprotocol=SOCKET\nsocket=/tmp/other-mysql.sock\n", encoding="utf-8")
            with self.assertRaisesRegex(CatalogError, "MYSQL_SOCKET_PATH_NOT_ALLOWLISTED"):
                _mysql_socket_options(path)

    def test_foreign_key_action_rules_require_exactly_one_valid_metadata_row(self) -> None:
        valid = {("child", "fk_parent"): [{"update_rule": "CASCADE", "delete_rule": "RESTRICT"}]}
        self.assertEqual(_foreign_key_action_rules("child", "fk_parent", valid), ("CASCADE", "RESTRICT"))
        with self.assertRaisesRegex(CatalogError, "FOREIGN_KEY_RULES_MISSING_OR_AMBIGUOUS"):
            _foreign_key_action_rules("child", "fk_parent", {})
        ambiguous = {("child", "fk_parent"): valid[("child", "fk_parent")] * 2}
        with self.assertRaisesRegex(CatalogError, "FOREIGN_KEY_RULES_MISSING_OR_AMBIGUOUS"):
            _foreign_key_action_rules("child", "fk_parent", ambiguous)

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

    def test_catalog_default_allowlist_handles_text_literals_and_sensitive_names(self) -> None:
        stamp = {"data_type": "timestamp", "column_type": "timestamp", "column_default": "CURRENT_TIMESTAMP"}
        self.assertEqual(_safe_default(stamp), {"kind": "sql", "expression": "timezone('America/Sao_Paulo', statement_timestamp())"})
        safe = {"name": "status", "data_type": "varchar", "column_type": "varchar(100)", "column_default": "active"}
        safe_default = _safe_default(safe)
        self.assertEqual(safe_default, {"kind": "sql", "expression": "'active'"})
        report = {"review_items": []}
        self.assertEqual(_sql_default({**safe, "nullable": True, "default": safe_default}, "synthetic", report), "'active'")
        self.assertEqual(report["review_items"], [])
        for data_type in ("char", "varchar", "nchar", "nvarchar", "text"):
            column = {"name": "status", "data_type": data_type, "column_type": f"{data_type}(40)", "nullable": True,
                      "default": {"kind": "sql", "expression": "'active'"}}
            self.assertEqual(_sql_default(column, "synthetic", report), "'active'")
        escaped = {"name": "status", "data_type": "varchar", "column_type": "varchar(40)", "nullable": True,
                   "default": {"kind": "sql", "expression": "'O''Brien'"}}
        self.assertEqual(_sql_default(escaped, "synthetic", report), "'O''Brien'")
        sensitive = {"name": "api_token", "data_type": "varchar", "column_type": "varchar(100)", "column_default": "active"}
        self.assertEqual(_safe_default(sensitive), {"kind": "review", "code": "TEXT_DEFAULT_REVIEW_REQUIRED"})
        expression = {"name": "status", "data_type": "varchar", "column_type": "varchar(100)", "column_default": "default_value()", "extra": "DEFAULT_GENERATED"}
        self.assertEqual(_safe_default(expression), {"kind": "review", "code": "TEXT_EXPRESSION_DEFAULT_REVIEW_REQUIRED"})
        text_null = {"data_type": "varchar", "column_type": "varchar(100)", "column_default": "NULL"}
        self.assertEqual(_safe_default(text_null), {"kind": "review", "code": "AMBIGUOUS_TEXT_NULL_DEFAULT_REVIEW_REQUIRED"})

    def test_catalog_text_default_requires_positive_safe_column_and_value_classification(self) -> None:
        self.assertEqual(
            _safe_default({"name": "salt", "data_type": "varchar", "column_type": "varchar(64)", "column_default": "active"}),
            {"kind": "review", "code": "TEXT_DEFAULT_REVIEW_REQUIRED"},
        )
        self.assertEqual(
            _safe_default({"name": "status", "data_type": "varchar", "column_type": "varchar(64)", "column_default": "secret-default"}),
            {"kind": "review", "code": "TEXT_DEFAULT_REVIEW_REQUIRED"},
        )
        enum = {"data_type": "enum", "column_type": "enum('A','B')", "column_default": "A"}
        self.assertEqual(_safe_default(enum), {"kind": "sql", "expression": "'A'"})
        enum_null = {"data_type": "enum", "column_type": "enum('NULL','B')", "column_default": "NULL"}
        self.assertEqual(_safe_default(enum_null), {"kind": "sql", "expression": "'NULL'"})
        enum_null_ambiguous = {"data_type": "enum", "column_type": "enum('A','B')", "column_default": "NULL"}
        self.assertEqual(_safe_default(enum_null_ambiguous), {"kind": "review", "code": "AMBIGUOUS_ENUM_NULL_DEFAULT_REVIEW_REQUIRED"})

    def test_temporal_defaults_are_canonicalized_without_source_timezone_guessing(self) -> None:
        date_default = {"data_type": "date", "column_type": "date", "column_default": "0000-00-00"}
        time_default = {"data_type": "time", "column_type": "time", "column_default": "-25:00:00"}
        timestamp_default = {"data_type": "timestamp", "column_type": "timestamp", "column_default": "2026-11-17 00:00:00"}
        zero_timestamp_default = {"data_type": "timestamp", "column_type": "timestamp", "column_default": "0000-00-00 00:00:00"}
        self.assertEqual(_safe_default(date_default), {"kind": "sql", "expression": "NULL"})
        self.assertEqual(_safe_default(time_default), {"kind": "sql", "expression": "INTERVAL '-25:00:00'"})
        self.assertEqual(_safe_default(timestamp_default), {"kind": "sql", "expression": "TIMESTAMP '2026-11-17 00:00:00'"})
        self.assertEqual(_safe_default(zero_timestamp_default), {"kind": "sql", "expression": "NULL"})
        fractional_timestamp_default = dict(timestamp_default, column_default="2026-11-17 00:00:00.123456")
        self.assertEqual(_safe_default(fractional_timestamp_default), {"kind": "review", "code": "STATIC_TEMPORAL_DEFAULT_REVIEW_REQUIRED"})

        snapshot = sample_snapshot()
        snapshot["tables"][0]["columns"].extend([
            {"name": "zero_date", "ordinal": 3, "nullable": False, "data_type": "date", "column_type": "date", "character_set": None, "source_collation": None, "extra": "", "default": _safe_default(date_default)},
            {"name": "elapsed", "ordinal": 4, "nullable": False, "data_type": "time", "column_type": "time", "character_set": None, "source_collation": None, "extra": "", "default": _safe_default(time_default)},
            {"name": "stamp_default", "ordinal": 5, "nullable": False, "data_type": "timestamp", "column_type": "timestamp", "character_set": None, "source_collation": None, "extra": "", "default": _safe_default(timestamp_default)},
            {"name": "zero_timestamp", "ordinal": 6, "nullable": False, "data_type": "timestamp", "column_type": "timestamp", "character_set": None, "source_collation": None, "extra": "", "default": _safe_default(zero_timestamp_default)},
        ])
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        tables = bundle.scripts["00_tables.sql"]
        self.assertIn('"zero_date" DATE DEFAULT NULL', tables)
        self.assertIn('"elapsed" INTERVAL NOT NULL DEFAULT INTERVAL \'-25:00:00\'', tables)
        self.assertIn('"stamp_default" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT TIMESTAMP \'2026-11-17 00:00:00\'', tables)
        self.assertIn('"zero_timestamp" TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL', tables)

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
        utc_index = connection.statements.index("SET SESSION time_zone = '+00:00'")
        snapshot_index = connection.statements.index("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY")
        self.assertLess(utc_index, snapshot_index)
        self.assertLess(connection.statements.index("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY"), next(
            index for index, statement in enumerate(connection.statements) if "SHOW GRANTS" in statement
        ))
        self.assertTrue(connection.rolled_back)

    def test_catalog_can_leave_the_read_only_snapshot_open_for_the_loader(self) -> None:
        connection = FakeConnection()
        result = collect_catalog(connection, keep_snapshot=True)
        self.assertEqual(result["catalog_consistency_check"], "PRE_POST_SIGNATURE_MATCH")
        self.assertIn("SET SESSION time_zone = '+00:00'", connection.statements)
        self.assertIn("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY", connection.statements)
        self.assertFalse(connection.rolled_back)

    def test_catalog_marks_generated_extra_without_expression_as_review(self) -> None:
        class MissingExpressionCursor(FakeCursor):
            def execute(self, sql: str, args=()) -> None:
                super().execute(sql, args)
                if "generation_expression" in sql:
                    self.current[0]["extra"] = "STORED GENERATED"
                    self.current[0]["generation_expression"] = ""

        class MissingExpressionConnection(FakeConnection):
            def __init__(self) -> None:
                self.c = MissingExpressionCursor()
                self.statements = self.c.statements
                self.rolled_back = False

        result = collect_catalog(MissingExpressionConnection())
        column = result["tables"][0]["columns"][0]
        self.assertTrue(column["generated_expression_present"])
        self.assertIn("GENERATED_COLUMN_EXPRESSION_MISSING", {item["code"] for item in result["review_items"]})

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
        self.assertNotIn('CONSTRAINT "PRIMARY" PRIMARY KEY', tables)
        self.assertNotIn("CHECK (\"state\" IN ('A', 'B'))", tables)
        self.assertEqual(bundle.report["enum_checks_deferred"][0]["column"], "state")
        self.assertEqual(bundle.report["primary_keys_post_load"][0]["table"], "parent")
        self.assertEqual(bundle.report["unique_indexes_post_load"][0]["index"], "uq_parent_name")
        self.assertNotIn("CREATE UNIQUE INDEX", bundle.scripts["10_indexes.sql"])
        self.assertIn("COLLATE ci", tables)
        self.assertIn('COLLATE "C"', tables)
        self.assertIn("CREATE TRIGGER", triggers)
        self.assertIn("IS DISTINCT FROM", triggers)
        self.assertIn("IF to_jsonb(NEW) -", triggers)
        self.assertNotIn("IF (to_jsonb(NEW)", triggers)
        self.assertNotIn("FOREIGN KEY", fks)
        self.assertEqual(bundle.report["foreign_keys_post_load"][0]["columns"], ["parent_id"])
        self.assertEqual(bundle.report["generated"]["on_update_triggers"], 1)
        self.assertEqual(bundle.report["foreign_keys"]["generated_not_valid"], 1)
        self.assertIn("pg_get_serial_sequence", sequences)
        self.assertIn("v_next := GREATEST(COALESCE(v_max, 0), 9)", sequences)
        self.assertIn("pg_catalog.pg_sequence_last_value(v_sequence::regclass)", sequences)
        self.assertNotIn("BEGIN;", sequences)
        self.assertNotIn("COMMIT;", sequences)
        self.assertIn('LOCK TABLE "erp"."parent" IN ACCESS EXCLUSIVE MODE', sequences)
        self.assertIn("post-load sequence sync is one-shot", sequences)
        self.assertIn("sequence sync already started; manual recovery required", sequences)
        self.assertEqual(bundle.report["auto_increment"]["generated"], 1)
        self.assertFalse(bundle.report["executable_complete"])

    def test_ddl_maps_approved_generated_water_consumption_and_defers_business_triggers(self) -> None:
        snapshot = sample_snapshot()
        snapshot["triggers"] = [{"name": "water_business", "table": "lancamentos_agua", "event": "INSERT", "body_sha256": "a" * 64}]
        snapshot["tables"].append({
            "name": "lancamentos_agua", "type": "BASE TABLE", "engine": "InnoDB",
            "source_collation": "utf8mb4_unicode_ci", "source_next_auto_increment": 2,
            "columns": [
                {"name": "id", "ordinal": 1, "nullable": False, "data_type": "int", "column_type": "int", "extra": "auto_increment"},
                {"name": "leitura_anterior", "ordinal": 2, "nullable": False, "data_type": "decimal", "column_type": "decimal(10,2)", "extra": ""},
                {"name": "leitura_atual", "ordinal": 3, "nullable": False, "data_type": "decimal", "column_type": "decimal(10,2)", "extra": ""},
                {"name": "consumo", "ordinal": 4, "nullable": True, "data_type": "decimal", "column_type": "decimal(10,2)", "extra": "STORED GENERATED", "generated_expression_present": True, "generated_expression_key": "lancamentos_agua.consumo.v1", "generated_expression_sha256": "b" * 64},
            ],
            "primary_key": [{"ordinal": 1, "column": "id"}],
        })
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        table_ddl = bundle.scripts["00_tables.sql"]
        self.assertIn('"consumo" NUMERIC(10,2) GENERATED ALWAYS AS ("leitura_atual" - "leitura_anterior") STORED', table_ddl)
        self.assertEqual(bundle.report["generated"]["generated_columns"], 1)
        self.assertTrue(bundle.report["load_executable_complete"])
        self.assertFalse(bundle.report["executable_complete"])
        self.assertEqual(len(bundle.report["post_load_trigger_translation_required"]), 1)

    def test_ddl_blocks_unknown_generated_column_expression_key(self) -> None:
        snapshot = sample_snapshot()
        column = snapshot["tables"][0]["columns"][0]
        column.update({"generated_expression_present": True, "generated_expression_key": "unknown.v1", "extra": "STORED GENERATED"})
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        self.assertFalse(bundle.report["load_executable_complete"])
        self.assertIn("GENERATED_COLUMN_TRANSLATION_REQUIRED", {item["code"] for item in bundle.report["blocked_tables"]})

    def test_approved_business_triggers_are_scheduled_after_copy(self) -> None:
        snapshot = sample_snapshot()
        snapshot["triggers"] = [
            {"table": table, "name": name, "timing": timing, "event": event, "body_sha256": body_sha256}
            for (table, name, timing, event), body_sha256 in sorted(BUSINESS_TRIGGER_SOURCE_SHA256.items())
        ]
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)

        bundle = generate_ddl(snapshot)

        script = bundle.scripts["40_business_triggers.sql"]
        self.assertEqual(script.upper().count("CREATE TRIGGER"), 7)
        self.assertEqual(len(bundle.report["translated_business_triggers"]), 7)
        self.assertEqual(bundle.report["post_load_trigger_translation_required"], [])
        self.assertTrue(bundle.report["executable_complete"])
        self.assertNotRegex(script, r"(?im)^\s*(BEGIN|COMMIT)\s*;")

    def test_unknown_business_trigger_is_not_installed(self) -> None:
        snapshot = sample_snapshot()
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)

        bundle = generate_ddl(snapshot)

        self.assertNotRegex(bundle.scripts["40_business_triggers.sql"], r"(?i)CREATE\s+TRIGGER")
        self.assertEqual(len(bundle.report["post_load_trigger_translation_required"]), 1)
        self.assertFalse(bundle.report["executable_complete"])

    def test_ddl_blocks_generated_water_with_text_dependency(self) -> None:
        snapshot = sample_snapshot()
        table = {
            "name": "lancamentos_agua", "type": "BASE TABLE", "engine": "InnoDB",
            "source_collation": "utf8mb4_unicode_ci", "columns": [
                {"name": "leitura_anterior", "ordinal": 1, "nullable": False, "data_type": "varchar", "column_type": "varchar(20)", "extra": ""},
                {"name": "leitura_atual", "ordinal": 2, "nullable": False, "data_type": "decimal", "column_type": "decimal(10,2)", "extra": ""},
                {"name": "consumo", "ordinal": 3, "nullable": True, "data_type": "decimal", "column_type": "decimal(10,2)", "extra": "STORED GENERATED", "generated_expression_present": True, "generated_expression_key": "lancamentos_agua.consumo.v1"},
            ], "primary_key": [],
        }
        snapshot["tables"].append(table)
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        self.assertFalse(bundle.report["load_executable_complete"])
        self.assertIn("GENERATED_COLUMN_DEPENDENCY_UNSUPPORTED", {item["code"] for item in bundle.report["blocked_tables"]})
        self.assertNotIn('"consumo" NUMERIC(10,2) GENERATED ALWAYS', bundle.scripts["00_tables.sql"])

    def test_ddl_blocks_generated_dependency_that_is_itself_generated(self) -> None:
        snapshot = sample_snapshot()
        table = {
            "name": "lancamentos_agua", "type": "BASE TABLE", "engine": "InnoDB",
            "source_collation": "utf8mb4_unicode_ci", "columns": [
                {"name": "leitura_anterior", "ordinal": 1, "nullable": False, "data_type": "decimal", "column_type": "decimal(10,2)", "extra": "STORED GENERATED", "generated_expression_present": True},
                {"name": "leitura_atual", "ordinal": 2, "nullable": False, "data_type": "decimal", "column_type": "decimal(10,2)", "extra": ""},
                {"name": "consumo", "ordinal": 3, "nullable": True, "data_type": "decimal", "column_type": "decimal(10,2)", "extra": "STORED GENERATED", "generated_expression_present": True, "generated_expression_key": "lancamentos_agua.consumo.v1"},
            ], "primary_key": [],
        }
        snapshot["tables"].append(table)
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        self.assertFalse(bundle.report["load_executable_complete"])
        blocked_codes = {item["code"] for item in bundle.report["blocked_tables"]}
        self.assertTrue(blocked_codes & {"GENERATED_COLUMN_DEPENDENCY_UNSUPPORTED", "GENERATED_COLUMN_TRANSLATION_REQUIRED"})

    def test_backup_tables_are_created_in_legacy_schema(self) -> None:
        snapshot = sample_snapshot()
        backup = {
            "name": "archive_backup",
            "type": "BASE TABLE",
            "engine": "InnoDB",
            "source_collation": "utf8mb4_unicode_ci",
            "columns": [
                {"name": "id", "ordinal": 1, "nullable": False, "data_type": "int", "column_type": "int", "character_set": None, "source_collation": None, "extra": ""},
            ],
            "primary_key": [{"ordinal": 1, "column": "id"}],
        }
        dated = dict(backup, name="archive_2026_11_17")
        snapshot["tables"].extend([backup, dated])
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, application_role="erp_app")
        tables = bundle.scripts["00_tables.sql"]
        self.assertIn('CREATE SCHEMA "legado"', tables)
        self.assertIn('CREATE TABLE "legado"."archive_backup"', tables)
        self.assertIn('CREATE TABLE "legado"."archive_2026_11_17"', tables)
        self.assertEqual(bundle.report["table_schemas"]["archive_backup"], "legado")
        self.assertEqual(bundle.report["table_schemas"]["archive_2026_11_17"], "legado")
        self.assertIn('REVOKE ALL ON SCHEMA "legado" FROM "erp_app"', tables)
        self.assertIn('REVOKE ALL ON TABLE "legado"."auditoria_nul" FROM "erp_app"', tables)
        self.assertIn('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE "erp"."child", "erp"."parent" TO "erp_app"', tables)
        self.assertNotIn('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA "erp"', tables)
        self.assertNotIn('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE "legado".', tables)

    def test_unsigned_bigint_auto_increment_uses_nontransactional_sequence_pair(self) -> None:
        snapshot = sample_snapshot()
        parent = snapshot["tables"][0]
        parent["columns"][0].update(data_type="bigint", column_type="bigint unsigned", extra="auto_increment")
        parent["source_next_auto_increment"] = 15
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"parent.name": "ci", "child.state": "ci", "child.api_token": "C"}, application_role="erp_app")
        tables = bundle.scripts["00_tables.sql"]
        sequences = bundle.scripts["30_post_load_sequences.sql"]
        self.assertIn('"id" NUMERIC(20,0) DEFAULT "erp"."_p2_next_unsigned_id"(\'erp.parent.id\') NOT NULL', tables)
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
        self.assertNotIn("BEGIN;", sequences)
        self.assertNotIn("COMMIT;", sequences)
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

    def test_prefix_index_is_emitted_and_unresolved_checks_remain_reported(self) -> None:
        snapshot = sample_snapshot()
        snapshot["indexes"].append({"table": "parent", "name": "prefix_idx", "non_unique": True, "type": "BTREE", "visible": True, "columns": [{"ordinal": 1, "name": "name", "prefix_length": 10, "direction": "ASC", "expression_present": False}]})
        snapshot["check_constraints"] = [{"table": "parent", "name": "chk_business", "definition_sha256": "2" * 64, "enforced": True}]
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertEqual(bundle.report["unsupported_indexes"], [])
        self.assertIn('left("name"::text, 10)', bundle.scripts["10_indexes.sql"])
        self.assertTrue(any(item.get("code") == "MYSQL_CHECK_EXPRESSION_TRANSLATION_REQUIRED" for item in bundle.report["review_items"]))
        self.assertFalse(bundle.report["executable_complete"])

    def test_fulltext_index_is_emitted_as_portuguese_gin(self) -> None:
        snapshot = sample_snapshot()
        snapshot["indexes"].append({"table": "parent", "name": "fts_name", "non_unique": True, "type": "FULLTEXT", "visible": True, "columns": [{"ordinal": 1, "name": "name", "prefix_length": None, "direction": "ASC", "expression_present": False}]})
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertIn("USING GIN (to_tsvector('portuguese', COALESCE(\"name\"::text, '')))", bundle.scripts["10_indexes.sql"])
        self.assertNotIn("INDEX_METHOD_REVIEW_REQUIRED", {item["code"] for item in bundle.report["unsupported_indexes"]})

    def test_sensitive_collation_defaults_to_c_and_is_reported(self) -> None:
        snapshot = sample_snapshot()
        bundle = generate_ddl(snapshot)
        self.assertFalse(any(item.get("code") == "SENSITIVE_COLLATION_CONFIRMATION_REQUIRED" for item in bundle.report["review_items"]))
        self.assertIn({"table": "child", "column": "api_token", "reason": "sensitive_name"}, bundle.report["collation_C_columns"])
        self.assertIn('"api_token" CHARACTER VARYING(64) COLLATE "C"', bundle.scripts["00_tables.sql"])

    def test_sensitive_names_include_chave_sha_and_secret(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["columns"].extend([
            {"name": "chave_publica", "ordinal": 3, "nullable": True, "data_type": "varchar", "column_type": "varchar(128)", "character_set": "utf8mb4", "source_collation": "utf8mb4_unicode_ci", "extra": ""},
            {"name": "sha256_hash", "ordinal": 4, "nullable": True, "data_type": "varchar", "column_type": "varchar(64)", "character_set": "utf8mb4", "source_collation": "utf8mb4_unicode_ci", "extra": ""},
            {"name": "api_secret", "ordinal": 5, "nullable": True, "data_type": "varchar", "column_type": "varchar(64)", "character_set": "utf8mb4", "source_collation": "utf8mb4_unicode_ci", "extra": ""},
        ])
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        sensitive = {(item["table"], item["column"]) for item in bundle.report["collation_C_columns"]}
        self.assertTrue({("parent", "chave_publica"), ("parent", "sha256_hash"), ("parent", "api_secret")}.issubset(sensitive))

    def test_enum_check_is_deferred_and_report_excludes_labels(self) -> None:
        snapshot = sample_snapshot()
        bundle = generate_ddl(snapshot)
        enums = bundle.report["enum_checks_deferred"]
        self.assertEqual(len(enums), 1)
        self.assertEqual(enums[0]["declared_label_count"], 2)
        self.assertFalse(enums[0]["empty_label_included"])
        self.assertEqual(enums[0]["status"], "CHECK_AFTER_VALUE_SCAN")
        self.assertNotIn("labels", enums[0])

    def test_other_source_collations_follow_approved_ci_default(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["columns"][1]["source_collation"] = "utf8mb4_0900_as_ci"
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        self.assertFalse(any(item.get("code") == "SOURCE_COLLATION_SEMANTICS_REVIEW_REQUIRED" for item in bundle.report["review_items"]))
        self.assertIn('"name" CHARACTER VARYING(80) COLLATE ci', bundle.scripts["00_tables.sql"])
        self.assertTrue(any(item["table"] == "parent" and item["column"] == "name" and item["target"] == "ci" for item in bundle.report["collation_decisions"]))

    def test_binary_source_collation_defaults_to_c(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["columns"][1]["source_collation"] = "utf8mb4_bin"
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        self.assertIn('"name" CHARACTER VARYING(80) COLLATE "C"', bundle.scripts["00_tables.sql"])
        self.assertIn({"table": "parent", "column": "name", "reason": "binary_source_collation"}, bundle.report["collation_C_columns"])

    def test_not_null_date_column_is_nullable_for_zero_date_normalization(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["columns"].append({"name": "created_on", "ordinal": 3, "nullable": False, "data_type": "date", "column_type": "date", "character_set": None, "source_collation": None, "extra": ""})
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot)
        tables_sql = bundle.scripts["00_tables.sql"]
        self.assertIn('"created_on" DATE', tables_sql)
        self.assertNotIn('"created_on" DATE NOT NULL', tables_sql)
        self.assertIn({"table": "parent", "column": "created_on"}, bundle.report["zero_date_nullable_columns"])

    def test_unknown_source_collation_uses_default_and_generates_indexes_and_foreign_keys(self) -> None:
        snapshot = sample_snapshot()
        snapshot["tables"][0]["columns"][1]["source_collation"] = "utf8mb4_unknown_semantics"
        snapshot["fingerprint_sha256"] = canonical_fingerprint(snapshot)
        bundle = generate_ddl(snapshot, collation_overrides={"child.api_token": "C"})
        self.assertFalse(any(item["table"] == "parent" for item in bundle.report["blocked_tables"]))
        self.assertEqual(bundle.report["deferred_indexes"], [])
        self.assertEqual(bundle.report["foreign_keys"]["deferred"], [])
        self.assertEqual(bundle.report["foreign_keys"]["generated_not_valid"], 1)
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

    def test_cli_writes_load_executable_bundle_once_and_refuses_overwrite(self) -> None:
        with TemporaryDirectory() as directory:
            root = Path(directory)
            snapshot_path = root / "snapshot.json"
            output_dir = root / "ddl"
            snapshot_path.write_text(json.dumps(sample_snapshot()), encoding="utf-8")
            args = ["generate_ddl", "--snapshot", str(snapshot_path), "--output-dir", str(output_dir)]
            with patch("sys.argv", args):
                self.assertEqual(generate_ddl_main(), 0)
            bundles = list(output_dir.glob("catalog-*-collation-*"))
            self.assertEqual(len(bundles), 1)
            self.assertTrue((bundles[0] / "BUNDLE_COMPLETE").is_file())
            snapshot_copy = bundles[0] / "catalog_snapshot.json"
            self.assertTrue(snapshot_copy.is_file())
            self.assertEqual(json.loads(snapshot_copy.read_text(encoding="utf-8"))["fingerprint_sha256"], sample_snapshot()["fingerprint_sha256"])
            report = json.loads((bundles[0] / "report.json").read_text(encoding="utf-8"))
            self.assertTrue(report["load_executable_complete"])
            self.assertFalse(report["executable_complete"])
            self.assertEqual(report["snapshot_file_sha256"], hashlib.sha256(snapshot_copy.read_bytes()).hexdigest())
            self.assertEqual(len(report["generated_tables"]), report["generated"]["tables"])
            scripts = report["generated_scripts_sha256"]
            self.assertTrue(scripts)
            for filename, expected_hash in scripts.items():
                self.assertEqual(hashlib.sha256((bundles[0] / filename).read_bytes()).hexdigest(), expected_hash)
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
