#!/usr/bin/env python3
"""Verifica offline que o checklist de coleta continua SELECT-only."""

from __future__ import annotations

import re
import unittest
from pathlib import Path


SQL_PATH = Path(__file__).with_name("mysql_metadata_inventory.sql")


class MySQLMetadataInventoryTest(unittest.TestCase):
    def test_inventory_contains_only_eight_select_statements(self) -> None:
        source = SQL_PATH.read_text(encoding="utf-8")
        without_line_comments = "\n".join(line.split("--", 1)[0] for line in source.splitlines())
        statements = [part.strip() for part in without_line_comments.split(";") if part.strip()]
        self.assertEqual(len(statements), 8)
        self.assertTrue(all(re.match(r"(?is)^SELECT\b", statement) for statement in statements))
        for forbidden in ("INSERT", "UPDATE", "DELETE", "REPLACE", "CALL", "SHOW", "CREATE", "DROP", "ALTER", "TRUNCATE"):
            self.assertFalse(any(re.search(rf"(?i)\b{forbidden}\b", statement) for statement in statements), forbidden)

    def test_sensitive_defaults_and_generated_expressions_are_only_boolean_flags(self) -> None:
        source = SQL_PATH.read_text(encoding="utf-8")
        self.assertRegex(source, r"(?is)CASE\s+WHEN\s+COLUMN_DEFAULT\s+IS\s+NULL\s+THEN\s+0\s+ELSE\s+1\s+END\s+AS\s+HAS_SCHEMA_DEFAULT")
        self.assertRegex(source, r"(?is)CASE\s+WHEN\s+GENERATION_EXPRESSION\s+IS\s+NULL\s+OR\s+GENERATION_EXPRESSION\s*=\s*''\s+THEN\s+0\s+ELSE\s+1\s+END\s+AS\s+HAS_GENERATED_EXPRESSION")
        self.assertRegex(source, r"(?is)CASE\s+WHEN\s+AUTO_INCREMENT\s+IS\s+NULL\s+THEN\s+0\s+ELSE\s+1\s+END\s+AS\s+HAS_AUTO_INCREMENT")
        self.assertRegex(source, r"(?is)SELECT\s+TABLE_SCHEMA\s+AS\s+OBJECT_SCHEMA")
        self.assertRegex(source, r"(?is)EVENT_TYPE\s+AS\s+OBJECT_EVENT")
        self.assertRegex(source, r"(?is)EVENT_MANIPULATION\s+AS\s+OBJECT_EVENT")
        self.assertNotRegex(source, r"(?im)^\s*COLUMN_DEFAULT\s*,")
        self.assertNotRegex(source, r"(?im)^\s*GENERATION_EXPRESSION\s*(?:,|FROM)")

    def test_attachment_candidates_read_only_information_schema(self) -> None:
        source = SQL_PATH.read_text(encoding="utf-8")
        without_line_comments = "\n".join(line.split("--", 1)[0] for line in source.splitlines())
        statements = [part.strip() for part in without_line_comments.split(";") if part.strip()]
        attachment_query = statements[7]
        self.assertRegex(attachment_query, r"(?is)FROM\s+information_schema\.COLUMNS\b")
        self.assertRegex(attachment_query, r"(?is)LOWER\(COLUMN_NAME\)\s+REGEXP")
        self.assertRegex(attachment_query, r"(?is)DATA_TYPE\s+IN\s*\('tinyblob',\s*'blob',\s*'mediumblob',\s*'longblob'\)")
        self.assertNotRegex(attachment_query, r"(?is)FROM\s+(?!information_schema\.)[A-Za-z_][A-Za-z0-9_$]*")
        self.assertNotRegex(attachment_query, r"(?is)SELECT\s+\*|COLUMN_DEFAULT|GENERATION_EXPRESSION")


if __name__ == "__main__":
    unittest.main()
