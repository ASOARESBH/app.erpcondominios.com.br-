#!/usr/bin/env python3
"""Testes offline do catalogador de DDL; fixtures são sintéticas."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import stat
import tempfile
import unittest
from pathlib import Path
from unittest import mock

MODULE_PATH = Path(__file__).with_name("catalog_schema_only.py")
SPEC = importlib.util.spec_from_file_location("catalog_schema_only", MODULE_PATH)
assert SPEC and SPEC.loader
catalog = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(catalog)


DDL = """-- comentário inicial com ; dentro do comentário
CREATE TABLE `tenant_table` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL DEFAULT '1',
  `parent_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_parent` (`tenant_id`, `parent_id`),
  KEY `idx_parent_prefix` (`parent_id`(10)),
  CONSTRAINT `fk_parent` FOREIGN KEY (`parent_id`) REFERENCES `parent_table` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `tenant_table`
  ADD UNIQUE KEY `uq_parent_tenant` (`parent_id`, `tenant_id`),
  MODIFY `tenant_id` int(11) NOT NULL DEFAULT '1';
DELIMITER $$
CREATE TRIGGER `tr_tenant_table_insert` BEFORE INSERT ON `tenant_table` FOR EACH ROW
BEGIN
  INSERT INTO `audit_table` (`event`) VALUES ('semicolon; stays inside the literal');
  UPDATE `tenant_table` SET `parent_id` = NEW.`parent_id` WHERE `id` = NEW.`id`;
END$$
DELIMITER ;
"""


class CatalogSchemaOnlyTest(unittest.TestCase):
    def patched_catalog_contract(self):
        return mock.patch.multiple(
            catalog,
            EXPECTED_STATEMENTS=3,
            EXPECTED_TABLES=1,
            EXPECTED_ALTERS=1,
            EXPECTED_TRIGGERS=1,
            EXPECTED_FOREIGN_KEYS=1,
            EXPECTED_WITH_TENANT=1,
            EXPECTED_WITHOUT_TENANT=0,
            EXPECTED_VIEW_LIKE_TOTAL=0,
            EXPECTED_VIEW_LIKE_WITH_TENANT=0,
            EXPECTED_VIEW_LIKE_WITHOUT_TENANT=0,
            EXPECTED_OTHER_WITHOUT_TENANT=0,
        )

    def test_delimiter_lexer_respects_quotes_comments_and_trigger_body(self) -> None:
        statements = catalog.split_statements(DDL)
        self.assertEqual(len(statements), 3)
        self.assertIn("semicolon; stays inside the literal", statements[2]["raw"])

    def test_catalog_extracts_only_partial_source_facts(self) -> None:
        ledger, tables, result = catalog.parse_catalog(DDL)
        self.assertEqual(len(ledger), 3)
        self.assertEqual(len(tables), 1)
        table = tables[0]
        self.assertEqual(table["name"], "tenant_table")
        self.assertTrue(table["has_tenant_id"])
        self.assertTrue(table["tenant_id_schema_default_1"])
        self.assertEqual([item["kind"] for item in table["constraints"]], ["primary_key", "unique", "index", "foreign_key"])
        self.assertEqual(table["constraints"][2]["column_parts"][0]["prefix_length"], 10)
        self.assertEqual(result["coverage"]["explicit_foreign_keys"], 1)
        self.assertEqual(result["coverage"]["alter_suboperations"]["add_unique"], 1)
        self.assertEqual(result["coverage"]["alter_suboperations"]["modify_column"], 1)
        unique_alter = result["partial"]["alter_events_not_replayed"][0]["suboperations"][0]
        self.assertEqual(unique_alter["columns"], ["parent_id", "tenant_id"])
        self.assertEqual(unique_alter["constraint_name"], "uq_parent_tenant")
        modify_alter = result["partial"]["alter_events_not_replayed"][0]["suboperations"][1]
        self.assertEqual(modify_alter["column_name"], "tenant_id")
        self.assertEqual(modify_alter["status"], "catalogued_not_replayed")
        self.assertTrue(ledger[-1]["contains_internal_dml"])
        self.assertFalse(result["partial"]["final_state_available"])
        self.assertEqual(result["partial"]["installed_database_state"], "unknown")

    def test_rejects_top_level_row_data_and_unknown_statements(self) -> None:
        for unsafe in (
            DDL + "INSERT INTO `tenant_table` (`id`) VALUES (1);\n",
            "DROP TABLE `tenant_table`;\n",
            "SELECT * FROM `tenant_table`;\n",
        ):
            with self.subTest(statement=unsafe.splitlines()[-1]), self.assertRaises(catalog.CatalogError):
                catalog.parse_catalog(unsafe)

    def test_rejects_unknown_table_clauses_and_unclosed_constructs(self) -> None:
        with self.assertRaises(catalog.CatalogError):
            catalog.parse_catalog("CREATE TABLE `t` (`id` int, `bad` int, MYSTERY CLAUSE);\n")
        with self.assertRaises(catalog.CatalogError):
            catalog.parse_catalog("CREATE TABLE `t` (`id` int;\n")
        with self.assertRaises(catalog.CatalogError):
            catalog.split_statements("CREATE TABLE `t` (`id` varchar(20) DEFAULT 'unterminated);\n")
        with self.assertRaises(catalog.CatalogError):
            catalog.split_statements("CREATE TABLE `t` (`id` int)")

    def test_rejects_executable_comments_and_unconsumed_sql_tails(self) -> None:
        unsafe = (
            "/*! INSERT INTO `t` VALUES (1) */;\n",
            "/*M!100100 UPDATE `t` SET `id`=1 */;\n",
            "CREATE TABLE `t` (`id` int) INSERT INTO `t` VALUES (123);\n",
            "CREATE TABLE `t` (`id` int BOGUS) ENGINE=InnoDB;\n",
            "ALTER TABLE `t` ADD FOREIGN KEY;\n",
            "ALTER TABLE `t` ADD INDEX `ix_id`;\n",
            "ALTER TABLE `t` ADD INDEX `ix_id` IGNORE (`id`);\n",
            "ALTER TABLE `t` ADD FOREIGN KEY (`id`) EXTRA REFERENCES `p` (`id`);\n",
            "ALTER TABLE `t` ADD UNKNOWN THING;\n",
            "ALTER TABLE `t` ADD INDEX `ix_id` (`id`),;\n",
            "ALTER TABLE `t` RENAME TO;\n",
            "ALTER TABLE `t` ADD COLUMN `new_id` int INSERT INTO `t` VALUES (1);\n",
        )
        for statement in unsafe:
            with self.subTest(kind=statement.split(maxsplit=2)[:2]), self.assertRaises(catalog.CatalogError):
                catalog.parse_catalog(statement)

    def test_literals_cannot_forge_column_flags_foreign_keys_or_trigger_dml(self) -> None:
        source = """CREATE TABLE `t` (
  `x` int COMMENT 'UNSIGNED AUTO_INCREMENT COLLATE REFERENCES `fake` (`id`) PRIMARY KEY',
  PRIMARY KEY (`x`)
) ENGINE=InnoDB;
DELIMITER $$
CREATE TRIGGER `tr_t` BEFORE INSERT ON `t` FOR EACH ROW
BEGIN
  SET NEW.`x` = 'UPDATE DELETE REPLACE INSERT';
END$$
DELIMITER ;
"""
        ledger, tables, result = catalog.parse_catalog(source)
        self.assertFalse(tables[0]["columns"][0]["unsigned"])
        self.assertFalse(tables[0]["columns"][0]["auto_increment"])
        self.assertFalse(tables[0]["columns"][0]["collation_present"])
        self.assertFalse(any(item["kind"] == "foreign_key" for item in tables[0]["constraints"]))
        self.assertFalse(ledger[1]["contains_internal_dml"])
        self.assertEqual(result["coverage"]["explicit_foreign_keys"], 0)

    def test_quote_aware_identifier_and_incomplete_constraint_rejection(self) -> None:
        source = "CREATE TABLE `t` (`a.b` int, PRIMARY KEY (`a.b`)) ENGINE=InnoDB;\n"
        _, tables, _ = catalog.parse_catalog(source)
        self.assertEqual(tables[0]["constraints"][0]["columns"], ["a.b"])
        invalid = (
            "CREATE TABLE `t` (`id` int, FOREIGN KEY (`id`) COMMENT 'REFERENCES `fake` (`id`)');\n",
            "CREATE TABLE `t` (`id` int, PRIMARY KEY (`id` BOGUS)) ENGINE=InnoDB;\n",
        )
        for statement in invalid:
            with self.subTest(statement=statement), self.assertRaises(catalog.CatalogError):
                catalog.parse_catalog(statement)

    def test_classifies_tenant_scope_by_column_not_name(self) -> None:
        source = """CREATE TABLE `view_named_with_tenant` (
  `id` int NOT NULL,
  `tenant_id` int NOT NULL
) ENGINE=InnoDB;
CREATE TABLE `ordinary_name_without_tenant` (
  `id` int NOT NULL
) ENGINE=InnoDB;
"""
        _, tables, result = catalog.parse_catalog(source)
        self.assertEqual(result["coverage"]["view_like_total"], 1)
        self.assertEqual(result["coverage"]["view_like_with_tenant_id"], 1)
        self.assertEqual(result["coverage"]["view_like_without_tenant_id"], 0)
        self.assertEqual(result["coverage"]["other_without_tenant_id"], 1)
        self.assertTrue(tables[0]["has_tenant_id"])

    def test_run_requires_approved_hash_and_publishes_private_bundle_once(self) -> None:
        raw = DDL.encode("utf-8")
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            input_path = root / "snapshot.sql"
            input_path.write_bytes(raw)
            output_dir = root / "result"
            digest = hashlib.sha256(raw).hexdigest()
            with self.patched_catalog_contract():
                with self.assertRaises(catalog.CatalogError):
                    catalog.run(input_path, output_dir, "0" * 64)
                outputs = catalog.run(input_path, output_dir, digest)
                self.assertEqual(len(outputs), 7)
                self.assertEqual(stat.S_IMODE(output_dir.stat().st_mode), 0o700)
                self.assertFalse((output_dir / "catalog_final.json").exists())
                partial = json.loads((output_dir / "catalog_partial.json").read_text(encoding="utf-8"))
                self.assertFalse(partial["final_state_available"])
                mapping = json.loads((output_dir / "type_mapping_advisory.json").read_text(encoding="utf-8"))
                self.assertFalse(mapping["ddl_generated"])
                self.assertEqual(mapping["status"], "advisory_not_executable")
                self.assertNotIn("semicolon; stays inside the literal", (output_dir / "objects_partial.json").read_text(encoding="utf-8"))
                with self.assertRaises(catalog.CatalogError):
                    catalog.run(input_path, output_dir, digest)
            wrong_fingerprint = root / "wrong-fingerprint"
            with self.patched_catalog_contract(), mock.patch.object(catalog, "EXPECTED_STATEMENTS", 4):
                with self.assertRaises(catalog.CatalogError):
                    catalog.run(input_path, wrong_fingerprint, digest)
            self.assertFalse(wrong_fingerprint.exists())

    def test_post_rename_fsync_failure_preserves_published_bundle(self) -> None:
        if not hasattr(catalog.ctypes.CDLL(None), "renameat2"):
            self.skipTest("renameat2 não disponível neste runtime")
        raw = DDL.encode("utf-8")
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            input_path = root / "snapshot.sql"
            input_path.write_bytes(raw)
            output_dir = root / "published"
            original_fsync = catalog.os.fsync

            def fail_directory_fsync(fd):
                if stat.S_ISDIR(catalog.os.fstat(fd).st_mode):
                    raise OSError("synthetic directory fsync failure")
                return original_fsync(fd)

            with self.patched_catalog_contract(), mock.patch.object(catalog.os, "fsync", side_effect=fail_directory_fsync):
                with self.assertRaises(catalog.BundlePublishedDurabilityUncertain) as raised:
                    catalog.run(input_path, output_dir, hashlib.sha256(raw).hexdigest())
            self.assertIn(str(output_dir), str(raised.exception))
            self.assertTrue(output_dir.is_dir())
            manifest = output_dir / "manifest.json"
            checksum = (output_dir / "manifest.json.sha256").read_text(encoding="ascii")
            self.assertTrue(checksum.startswith(hashlib.sha256(manifest.read_bytes()).hexdigest() + "  "))


if __name__ == "__main__":
    unittest.main()
