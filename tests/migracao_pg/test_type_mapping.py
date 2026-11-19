from __future__ import annotations

import unittest
from datetime import datetime, timedelta

from tools.migracao_pg.type_mapping import (
    TemporalValueError,
    TypeMappingError,
    map_mysql_type,
    normalize_temporal_value,
    parse_mysql_enum,
    quote_identifier,
    quote_literal,
    require_review_codes_resolved,
)


class TypeMappingTests(unittest.TestCase):
    def test_prompt_core_type_mapping(self) -> None:
        self.assertEqual(map_mysql_type("datetime").sql_type, "TIMESTAMP(0) WITHOUT TIME ZONE")
        self.assertEqual(map_mysql_type("timestamp").sql_type, "TIMESTAMP(0) WITHOUT TIME ZONE")
        self.assertEqual(map_mysql_type("timestamp").review_codes, ())
        self.assertEqual(map_mysql_type("time").sql_type, "INTERVAL")
        self.assertEqual(map_mysql_type("tinyint", column_name="tiny_value").sql_type, "SMALLINT")
        self.assertEqual(map_mysql_type("int unsigned", column_name="int_unsigned_value").sql_type, "BIGINT")
        self.assertEqual(map_mysql_type("decimal(12,4)").sql_type, "NUMERIC(12,4)")
        self.assertEqual(map_mysql_type("json").sql_type, "JSON")
        self.assertEqual(map_mysql_type("blob", column_name="payload").sql_type, "BYTEA")

    def test_signed_and_unsigned_integer_domains_are_preserved(self) -> None:
        tiny = map_mysql_type("tinyint", column_name="n")
        self.assertEqual(tiny.sql_type, "SMALLINT")
        self.assertEqual(tiny.checks, ('CHECK ("n" BETWEEN -128 AND 127)',))
        tiny_unsigned = map_mysql_type("tinyint unsigned", column_name="n")
        self.assertEqual(tiny_unsigned.sql_type, "SMALLINT")
        self.assertEqual(tiny_unsigned.checks, ('CHECK ("n" BETWEEN 0 AND 255)',))
        medium_unsigned = map_mysql_type("mediumint unsigned", column_name="n")
        self.assertEqual(medium_unsigned.sql_type, "INTEGER")
        self.assertIn("BETWEEN 0 AND 16777215", medium_unsigned.checks[0])
        int_unsigned = map_mysql_type("int unsigned", column_name="int_unsigned_value")
        self.assertEqual(int_unsigned.sql_type, "BIGINT")
        with self.assertRaisesRegex(TypeMappingError, "UNSIGNED_BIGINT_RANGE_SCAN_REQUIRED"):
            map_mysql_type("bigint unsigned", column_name="n")
        bigint_unsigned = map_mysql_type(
            "bigint unsigned", column_name="n", unsigned_bigint_exceeds_signed_limit=False
        )
        self.assertEqual(bigint_unsigned.sql_type, "BIGINT")
        self.assertEqual(bigint_unsigned.checks, ('CHECK ("n" >= 0)',))
        bigint_unsigned_wide = map_mysql_type(
            "bigint unsigned", column_name="n", unsigned_bigint_exceeds_signed_limit=True
        )
        self.assertEqual(bigint_unsigned_wide.sql_type, "NUMERIC(20,0)")
        self.assertIn("BETWEEN 0 AND 18446744073709551615", bigint_unsigned_wide.checks[0])

    def test_text_types_require_explicit_collation(self) -> None:
        with self.assertRaisesRegex(TypeMappingError, "TEXT_COLLATION_DECISION_REQUIRED"):
            map_mysql_type("varchar(90)")
        self.assertEqual(
            map_mysql_type("varchar(90)", target_collation="ci").declaration,
            "CHARACTER VARYING(90) COLLATE ci",
        )
        self.assertEqual(
            map_mysql_type("varchar(64)", target_collation="C").declaration,
            'CHARACTER VARYING(64) COLLATE "C"',
        )

    def test_enum_parser_preserves_quoted_and_escaped_labels(self) -> None:
        parsed = parse_mysql_enum("enum('novo','d''água','linha\\n2','aspas\\'ok')")
        self.assertEqual(parsed, ("novo", "d'água", "linha\n2", "aspas'ok"))
        mapped = map_mysql_type("enum('A','b''c')", target_collation="ci", column_name="state")
        self.assertEqual(mapped.sql_type, "CHARACTER VARYING")
        self.assertEqual(mapped.checks, ())

    def test_enum_parser_rejects_malformed_or_unsafe_metadata(self) -> None:
        for value in ("varchar(3)", "enum()", "enum('a',)", "enum('a','a')", "enum('open"):
            with self.subTest(value=value), self.assertRaises(TypeMappingError):
                parse_mysql_enum(value)
        with self.assertRaisesRegex(TypeMappingError, "ENUM_LABEL_CONTAINS_NUL"):
            map_mysql_type("enum('a\\0b')", target_collation="ci", column_name="state")

    def test_enum_no_backslash_escapes_mode_is_respected(self) -> None:
        self.assertEqual(parse_mysql_enum("enum('a\\\\b')"), ("a\\b",))
        self.assertEqual(parse_mysql_enum("enum('a\\\\b')", no_backslash_escapes=True), ("a\\\\b",))

    def test_decimal_unsigned_and_precision_gates(self) -> None:
        self.assertEqual(map_mysql_type("decimal(8,2) unsigned", column_name="amount").checks,
                         ('CHECK ("amount" >= 0)',))
        with self.assertRaisesRegex(TypeMappingError, "UNSUPPORTED_DECIMAL_PRECISION"):
            map_mysql_type("decimal(66,2)")
        for incomplete in ("decimal", "decimal unsigned", "numeric"):
            with self.subTest(incomplete=incomplete), self.assertRaisesRegex(TypeMappingError, "INVALID_DECIMAL_METADATA"):
                map_mysql_type(incomplete)
        with self.assertRaisesRegex(TypeMappingError, "COLUMN_NAME_REQUIRED_FOR_CHECK"):
            map_mysql_type("decimal(8,2) unsigned")

    def test_review_codes_must_be_resolved_before_ddl(self) -> None:
        mapping = map_mysql_type("datetime(6)")
        with self.assertRaisesRegex(TypeMappingError, "MANUAL_REVIEW_REQUIRED"):
            require_review_codes_resolved(mapping, set())
        require_review_codes_resolved(mapping, set(mapping.review_codes))
        with self.assertRaisesRegex(TypeMappingError, "REVIEW_RESOLUTION_NOT_APPLICABLE"):
            require_review_codes_resolved(mapping, {"UNKNOWN_REVIEW"})

    def test_binary_limit_checks_and_large_object_mapping(self) -> None:
        self.assertEqual(map_mysql_type("varbinary(32)", column_name="token").checks,
                         ('CHECK (octet_length("token") <= 32)',))
        self.assertEqual(map_mysql_type("binary(16)", column_name="digest").checks,
                         ('CHECK (octet_length("digest") = 16)',))
        self.assertEqual(map_mysql_type("longblob", column_name="payload").sql_type, "BYTEA")
        self.assertEqual(map_mysql_type("longtext", target_collation="ci").sql_type, "TEXT")

    def test_temporal_precision_and_unlisted_types_fail_closed(self) -> None:
        self.assertIn("FRACTIONAL_SECONDS_REVIEW_REQUIRED", map_mysql_type("datetime(6)").review_codes)
        self.assertEqual(map_mysql_type("time").sql_type, "INTERVAL")
        self.assertEqual(map_mysql_type("time(6)").review_codes, ())
        with self.assertRaisesRegex(TypeMappingError, "MANUAL_TYPE_MAPPING_REQUIRED"):
            map_mysql_type("geometry")

    def test_identifier_quoting(self) -> None:
        self.assertEqual(quote_identifier('odd"name'), '"odd""name"')
        self.assertEqual(quote_literal("name's path"), "'name''s path'")
        for identifier in ("", "bad\x00name"):
            with self.subTest(identifier=identifier), self.assertRaises(TypeMappingError):
                quote_identifier(identifier)
        with self.assertRaisesRegex(TypeMappingError, "ENUM_LABEL_CONTAINS_NUL"):
            quote_literal("unsafe\x00literal")


class TemporalNormalizationTests(unittest.TestCase):
    def test_datetime_is_civil_and_zero_date_becomes_null(self) -> None:
        value = normalize_temporal_value("2025-01-02 03:04:05", "datetime")
        self.assertEqual(value, datetime(2025, 1, 2, 3, 4, 5))
        self.assertIsNone(normalize_temporal_value("0000-00-00", "date"))
        self.assertIsNone(normalize_temporal_value("0000-00-00 00:00:00", "datetime"))
        self.assertIsNone(normalize_temporal_value(None, "datetime"))
        with self.assertRaisesRegex(TemporalValueError, "MYSQL_ZERO_DATE_REQUIRES_APPROVAL"):
            normalize_temporal_value("0000-00-00 00:00:00", "datetime", zero_date_to_null=False)

    def test_timestamp_converts_from_utc_to_sao_paulo_civil_time(self) -> None:
        value = normalize_temporal_value("2025-01-02 03:04:05", "timestamp")
        self.assertEqual(value, datetime(2025, 1, 2, 0, 4, 5))

    def test_timestamp_rejects_microseconds_without_approved_mapping(self) -> None:
        with self.assertRaisesRegex(TemporalValueError, "FRACTIONAL_SECONDS_REQUIRE_APPROVAL"):
            normalize_temporal_value("2025-01-02 03:04:05.123456", "timestamp")

    def test_timestamp_rejects_ambiguous_local_dst_time(self) -> None:
        with self.assertRaisesRegex(TemporalValueError, "AMBIGUOUS_LOCAL_TIMESTAMP_REQUIRES_APPROVAL"):
            normalize_temporal_value("2018-02-18 02:30:00", "timestamp")

    def test_timestamp_rejects_unknown_timezone(self) -> None:
        with self.assertRaisesRegex(TemporalValueError, "TIMEZONE_DATABASE_UNAVAILABLE"):
            normalize_temporal_value("2025-01-02 03:04:05", "timestamp", target_timezone="Unknown/Zone")

    def test_datetime_rejects_timezone_attached_values(self) -> None:
        with self.assertRaisesRegex(TemporalValueError, "DATETIME_MUST_REMAIN_TIMEZONE_NAIVE"):
            normalize_temporal_value(datetime.fromisoformat("2025-01-02T03:04:05+00:00"), "datetime")

    def test_time_maps_to_interval_and_preserves_negative_extended_range(self) -> None:
        self.assertEqual(normalize_temporal_value("23:59:59", "time"), timedelta(hours=23, minutes=59, seconds=59))
        self.assertEqual(normalize_temporal_value("24:00:00", "time"), timedelta(hours=24))
        self.assertEqual(normalize_temporal_value("-01:00:00", "time"), -timedelta(hours=1))
        self.assertEqual(normalize_temporal_value("-838:59:59", "time"), -timedelta(hours=838, minutes=59, seconds=59))
        self.assertEqual(normalize_temporal_value("12:30:45.123456", "time(6)"), timedelta(hours=12, minutes=30, seconds=45, microseconds=123456))
        with self.assertRaisesRegex(TemporalValueError, "MYSQL_TIME_VALUE_OUT_OF_SOURCE_RANGE"):
            normalize_temporal_value("839:00:00", "time")

    def test_time_fraction_is_preserved_in_interval(self) -> None:
        self.assertEqual(normalize_temporal_value("12:00:00.000001", "time"), timedelta(hours=12, microseconds=1))


if __name__ == "__main__":
    unittest.main()
