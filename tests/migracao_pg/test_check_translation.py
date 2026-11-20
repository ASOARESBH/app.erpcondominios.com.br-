from __future__ import annotations

import unittest

from tools.migracao_pg.check_translation import (
    CheckTranslationError,
    render_check_translation,
    translate_mysql_check_clause,
)


class CheckTranslationTests(unittest.TestCase):
    def test_translates_static_text_in_list_with_mysql_charset_introducer(self) -> None:
        translation = translate_mysql_check_clause(
            "((`som_tipo` IN (_utf8mb4'classico', _utf8mb4'campainha', _utf8mb4'suave'))) ",
            columns={"som_tipo": {"data_type": "varchar"}},
        )
        self.assertEqual(
            translation,
            {"kind": "text_in_list", "column": "som_tipo", "values": ["classico", "campainha", "suave"]},
        )
        self.assertEqual(
            render_check_translation(translation, columns={"som_tipo": {"data_type": "varchar"}}),
            '"som_tipo" IN (E\'classico\', E\'campainha\', E\'suave\')',
        )

    def test_translates_single_numeric_between_check(self) -> None:
        translation = translate_mysql_check_clause(
            "((`som_volume` BETWEEN 0 AND 100))",
            columns={"som_volume": {"data_type": "tinyint"}},
        )
        self.assertEqual(
            translation,
            {"kind": "numeric_between", "column": "som_volume", "lower": "0", "upper": "100"},
        )
        self.assertEqual(
            render_check_translation(translation, columns={"som_volume": {"data_type": "tinyint"}}),
            '"som_volume" BETWEEN 0 AND 100',
        )

    def test_accepts_only_escaped_outer_delimiters_when_backslash_escapes_are_enabled(self) -> None:
        expression = "((`som_tipo` IN (_utf8mb4\\'classico\\', _utf8mb4\\'campainha\\')))"
        translated = translate_mysql_check_clause(
            expression,
            columns={"som_tipo": {"data_type": "varchar"}},
            no_backslash_escapes=False,
        )
        self.assertEqual(translated["values"], ["classico", "campainha"])
        with self.assertRaises(CheckTranslationError):
            translate_mysql_check_clause(
                expression,
                columns={"som_tipo": {"data_type": "varchar"}},
                no_backslash_escapes=True,
            )

    def test_rejects_expressions_outside_the_limited_grammar(self) -> None:
        cases = (
            ("LOWER(status) IN ('a', 'b')", "status", "varchar"),
            ("status IN ('a', 'b') OR status = 'c'", "status", "varchar"),
            ("status IN ('a')", "status", "varchar"),
            ("status IN ('a', 'a')", "status", "varchar"),
            ("status IN ('a\\\\b', 'c')", "status", "varchar"),
            ("status IN (_latin1'a', _latin1'b')", "status", "varchar"),
            ("volume BETWEEN 0 AND 100", "volume", "varchar"),
            ("status BETWEEN 0 AND 100", "status", "varchar"),
            ("volume BETWEEN 100 AND 0", "volume", "int"),
            ("volume BETWEEN 1e2 AND 200", "volume", "int"),
            ("volume BETWEEN 0 AND 100; DROP TABLE x", "volume", "int"),
        )
        for case_number, (expression, column, data_type) in enumerate(cases, start=1):
            with self.subTest(case_number=case_number, expression_kind=expression.split()[0]):
                with self.assertRaises(CheckTranslationError):
                    translate_mysql_check_clause(
                        expression,
                        columns={column: {"data_type": data_type}},
                    )

    def test_rejects_tampered_translation_and_unknown_column(self) -> None:
        with self.assertRaises(CheckTranslationError):
            render_check_translation(
                {"kind": "text_in_list", "column": "status", "values": ["active", "x'); DROP TABLE y; --"]},
                columns={"status": {"data_type": "varchar"}},
            )
        with self.assertRaises(CheckTranslationError):
            render_check_translation(
                {"kind": "numeric_between", "column": "missing", "lower": "0", "upper": "1"},
                columns={},
            )
        with self.assertRaises(CheckTranslationError):
            render_check_translation(
                {"kind": "numeric_between", "column": "volume", "lower": "0); DROP TABLE x; --", "upper": "1"},
                columns={"volume": {"data_type": "int"}},
            )


if __name__ == "__main__":
    unittest.main()
