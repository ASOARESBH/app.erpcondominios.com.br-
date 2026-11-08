import sys
import json
import hashlib
import io
import tempfile
import unittest
from contextlib import redirect_stdout
from unittest.mock import MagicMock, patch
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2] / "tools" / "migracao_pg"
sys.path.insert(0, str(ROOT))

from common import (ambiguous_sao_paulo_wall_time, convert_temporal, mysql_type_to_pg, parse_mysql_enum, row_digest,
                    json_contains_nul, verify_v9_postgres_connection, zero_date)
from catalog import (build_sql, enum_counts_for_summary, invalid_enum_counts, time_conversion_risks, validate_on_update_snapshot,
                     expression_sql, validate_checklist_upsert_contract, validate_reader_grants, catalog_error_code,
                     verified_routine_snapshot, routine_parameter_signature, EXPECTED_BACKUP_CANDIDATES, tenant_contract,
                     text_nul_counts_for_table, validate_sensitive_column_defaults)
from reconcile import numeric_equal, sequence_status, verify_destination_fk
from run_migration import (APPROVED_RESUME_SOURCE_COMMIT, BASELINE_MAIN_COMMIT, as_postgres, run_step,
                           validate_resume_history, validate_resume_table_inventory,
                           verify_baseline_ancestry, verify_resume_code_ancestry, verify_resume_target)


class TypeMappingTests(unittest.TestCase):
    def test_json_nul_detector_checks_nested_keys_and_values(self):
        self.assertTrue(json_contains_nul(b'{"outer":[{"key\\u0000":"value"}]}'))
        self.assertFalse(json_contains_nul(b'{"outer":[{"key":"value"}]}'))

    def test_backup_candidate_snapshot_matches_approved_catalog(self):
        self.assertEqual(EXPECTED_BACKUP_CANDIDATES, {
            "avaliacoes_backup", "documentos_departamentos_migrado_bkp", "mt_os_chamados_id_zero_backup", "mt_protocolos_id_zero_backup",
            "rbac_sessoes_bkp_ids_a_partir_15_20261027", "rbac_usuario_grupos_bkp_ids_a_partir_15_20261027",
            "rbac_usuario_permissoes_bkp_ids_a_partir_15_20261027", "sessoes_usuarios_bkp_ids_a_partir_15_20261027",
            "usuarios_bkp_duplicata_admin_20261027", "usuarios_bkp_ids_a_partir_15_20261027",
            "usuario_modulos_bkp_ids_a_partir_15_20261027", "usuario_tenant_bkp_ids_a_partir_15_20261027",
        })

    def test_catalog_error_code_is_static_and_does_not_echo_unknown_messages(self):
        self.assertEqual(catalog_error_code(RuntimeError("source column collation inventory differs from approved rehearsal snapshot")),
                         "source_collation_mismatch")
        self.assertEqual(catalog_error_code(RuntimeError("sensitive unapproved value")), "unclassified")

    def test_catalog_error_codes_cover_enum_and_on_update_gates(self):
        cases = {
            "ENUM outside-domain count could not be reconciled to values": "enum_domain_count_mismatch",
            "ENUM value is not valid UTF-8": "enum_value_not_utf8",
            "NULL encountered while inspecting ENUM domain": "enum_null_member",
            "ON UPDATE CURRENT_TIMESTAMP column list differs from approved rehearsal snapshot": "on_update_columns_mismatch",
            "ON UPDATE CURRENT_TIMESTAMP manifest metadata differs from approved rehearsal snapshot": "on_update_manifest_metadata_mismatch",
            "invalid MySQL client configuration": "mysql_client_config_invalid",
        }
        for message, code in cases.items():
            with self.subTest(code=code):
                self.assertEqual(catalog_error_code(RuntimeError(message)), code)

    def test_sensitive_column_defaults_require_exact_reviewed_allowlist(self):
        approved = {
            "face_descriptors": [{"name": "token_usado", "data_type": "tinyint", "default": "0"}],
            "moradores": [{"name": "senha_temporaria", "data_type": "tinyint", "default": "0"}],
            "pwa_notificacoes_push": [{"name": "total_tokens", "data_type": "int", "default": "0"}],
            "pwa_oauth_cache": [{"name": "token_type", "data_type": "varchar", "default": "Bearer"}],
        }
        validate_sensitive_column_defaults(approved)
        changed = {table: [dict(column) for column in columns] for table, columns in approved.items()}
        changed["pwa_oauth_cache"][0]["default"] = "different"
        with self.assertRaises(RuntimeError):
            validate_sensitive_column_defaults(changed)
        extra = {table: [dict(column) for column in columns] for table, columns in approved.items()}
        extra["extra"] = [{"name": "api_token", "data_type": "varchar", "default": "unexpected"}]
        with self.assertRaises(RuntimeError):
            validate_sensitive_column_defaults(extra)
        missing = {table: columns for table, columns in approved.items() if table != "moradores"}
        with self.assertRaises(RuntimeError):
            validate_sensitive_column_defaults(missing)

    def test_routine_signature_preserves_dtd_identifier_without_numeric_coercion(self):
        routine = {"parameters": [
            {"position": 1, "name": "p_tabela", "mode": "IN", "type": "varchar", "dtd": "varchar(64)"},
            {"position": 2, "name": "p_coluna", "mode": "IN", "type": "varchar", "dtd": "varchar(64)"},
            {"position": 3, "name": "p_definicao", "mode": "IN", "type": "text", "dtd": "text"},
        ]}
        self.assertEqual(routine_parameter_signature(routine)[2], (3, "p_definicao", "IN", "text", "text"))

    def test_runner_logs_only_static_catalog_error_code(self):
        proc = MagicMock()
        proc.stdout = iter(["CATALOG_GENERATION_FAILED type=RuntimeError code=source_collation_mismatch\n"])
        proc.wait.return_value = 1
        proc.returncode = 1
        with tempfile.TemporaryDirectory() as directory:
            log = Path(directory) / "run.jsonl"
            with patch("run_migration.subprocess.Popen", return_value=proc), redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, "phase catalog failed"):
                    run_step(["catalog"], "catalog", log, whitelist_json=True)
            record = json.loads(log.read_text(encoding="utf-8"))
        self.assertEqual(record["error_code"], "source_collation_mismatch")
        self.assertEqual(record["error_type"], "RuntimeError")

    def test_nul_aggregate_counts_text_without_values(self):
        columns = [{"name": "descricao", "data_type": "text"}, {"name": "titulo", "data_type": "varchar"}]
        with patch("catalog.query", return_value=[(2, 1)]) as query_mock:
            counts = text_nul_counts_for_table(object(), "fixture", columns)
        self.assertEqual(counts, {"fixture.descricao": 2, "fixture.titulo": 1})
        query_sql = query_mock.call_args.args[1]
        self.assertIn("INSTR(BINARY `descricao`, 0x00)", query_sql)
        self.assertIn("INSTR(BINARY `titulo`, 0x00)", query_sql)
        self.assertNotIn("LOCATE(CHAR(0)", query_sql)
        self.assertIn("`titulo`", query_sql)

    def test_binary_nul_detector_ignores_collation_ignorables_without_nul_bytes(self):
        ignorable = "\u200b".encode("utf-8")
        self.assertNotIn(b"\x00", ignorable)
        with patch("catalog.query", return_value=[(0,)]) as query_mock:
            counts = text_nul_counts_for_table(
                object(), "fixture", [{"name": "descricao", "data_type": "varchar"}]
            )
        self.assertEqual(counts, {})
        self.assertIn("INSTR(BINARY `descricao`, 0x00)", query_mock.call_args.args[1])

    def test_json_nul_aggregate_parses_json_strings_without_persisting_values(self):
        conn = MagicMock()
        cursor = MagicMock()
        cursor.__iter__.return_value = iter([(b'{"a":"\\u0000"}',), (b'{"a":"ok"}',)])
        conn.cursor.return_value = cursor
        counts = text_nul_counts_for_table(conn, "fixture", [{"name": "payload", "data_type": "json"}])
        self.assertEqual(counts, {"fixture.payload": 1})
        cursor.execute.assert_called_once()

    def test_migration_commit_requires_approved_baseline_ancestor(self):
        with patch("run_migration.subprocess.run") as run:
            run.return_value.returncode = 0
            verify_baseline_ancestry(Path("/repo"), "a" * 40)
            self.assertEqual(run.call_args.args[0][3:6], ["merge-base", "--is-ancestor", BASELINE_MAIN_COMMIT])
            run.return_value.returncode = 1
            with self.assertRaisesRegex(RuntimeError, "not descended from the approved baseline"):
                verify_baseline_ancestry(Path("/repo"), "b" * 40)

    def test_postgres_python_environment_exposes_migration_modules_without_bytecode(self):
        command = as_postgres("/venv/python3", "-s")
        self.assertIn(f"PYTHONPATH={ROOT}", command)
        self.assertIn("PYTHONDONTWRITEBYTECODE=1", command)

    def test_resume_history_accepts_only_the_approved_pre_fk_boundary(self):
        records = [{"event": "pipeline_started", "code_commit": APPROVED_RESUME_SOURCE_COMMIT,
                    "batch_size": 1000},
                   {"event": "empty_target_created", "database": "erp_ensaio_v9"},
                   {"event": "loader_metrics_verified", "tables": 270, "rows": 202609}]
        records.extend({"event": "step_complete", "phase": phase} for phase in (
            "catalog", "schema", "load", "constraints_indexes", "identity_sequences",
            "on_update_triggers", "business_objects", "grants"))
        records.extend([{"event": "step_failed", "phase": "business_trigger_tests", "error_code": "unknown"},
                        {"event": "pipeline_blocked"}, {"event": "postgres_cluster_stopped"}])
        validate_resume_history(records, APPROVED_RESUME_SOURCE_COMMIT, 1000)
        records.append({"event": "step_complete", "phase": "foreign_keys_not_valid"})
        with self.assertRaisesRegex(RuntimeError, "advanced beyond"):
            validate_resume_history(records, APPROVED_RESUME_SOURCE_COMMIT, 1000)

    def test_resume_target_checks_per_table_counts_before_continuing(self):
        summary = {"legacy_tables": []}
        loader = {"table_metrics": [{"table": "fixture", "source_rows": 3}], "source_rows_loaded": 3}
        outputs = [MagicMock(returncode=0, stdout="America/Sao_Paulo|UTF8|270|0"),
                   MagicMock(returncode=0, stdout="erp.fixture|3\n")]
        with patch("run_migration.subprocess.run", side_effect=outputs) as run:
            verify_resume_target("erp_ensaio_v9", summary, loader)
        count_query = run.call_args_list[1].args[0][-1]
        self.assertIn('"erp"."fixture"', count_query)
        outputs[1].stdout = "erp.fixture|2\n"
        with patch("run_migration.subprocess.run", side_effect=outputs):
            with self.assertRaisesRegex(RuntimeError, "counts differ"):
                verify_resume_target("erp_ensaio_v9", summary, loader)

    def test_resume_table_inventory_accepts_partial_legacy_candidate_map(self):
        metrics = [{"table": f"t{index}", "source_rows": 0} for index in range(270)]
        classification = {f"t{index}": {"schema": "legado" if index < 8 else "erp"}
                          for index in range(12)}
        legacy_tables = [f"t{index}" for index in range(8)]
        self.assertEqual(len(validate_resume_table_inventory(metrics, legacy_tables, classification)), 270)
        with self.assertRaisesRegex(RuntimeError, "legacy classification is inconsistent"):
            validate_resume_table_inventory(metrics, legacy_tables[:-1], classification)

    def test_resume_code_requires_partial_run_commit_as_ancestor(self):
        with patch("run_migration.subprocess.run") as run:
            run.return_value.returncode = 0
            verify_resume_code_ancestry(Path("/repo"), "c" * 40)
            self.assertEqual(run.call_args.args[0][-2:], [APPROVED_RESUME_SOURCE_COMMIT, "c" * 40])
            run.return_value.returncode = 1
            with self.assertRaisesRegex(RuntimeError, "not descended from the approved partial-run commit"):
                verify_resume_code_ancestry(Path("/repo"), "d" * 40)

    def test_generated_consumption_expression_is_supported_without_rewriting(self):
        self.assertEqual(expression_sql("(`leitura_atual` - `leitura_anterior`)"),
                         '"leitura_atual" - "leitura_anterior"')
        with self.assertRaises(ValueError):
            expression_sql("`leitura_atual` - `leitura_anterior` + 1")

    def test_checklist_upsert_is_only_on_the_primary_key(self):
        validate_checklist_upsert_contract({"checklist_km_acumulado": {
            "PRIMARY": [{"column": "id", "non_unique": False}],
            "idx_lookup": [{"column": "tenant_id", "non_unique": True}],
        }})
        with self.assertRaises(RuntimeError):
            validate_checklist_upsert_contract({"checklist_km_acumulado": {
                "PRIMARY": [{"column": "id", "non_unique": False}],
                "uk_scope": [{"column": "tenant_id", "non_unique": False}],
            }})

    def test_numeric_sums_require_exact_equality(self):
        self.assertTrue(numeric_equal(Decimal("1.25"), Decimal("1.25"), "decimal"))
        self.assertFalse(numeric_equal(Decimal("0.1"), Decimal("0.1000000000001"), "double"))

    def test_on_update_columns_match_exact_reviewed_allowlist(self):
        manifest = json.loads((ROOT / "expected_on_update_columns.json").read_text(encoding="utf-8"))
        self.assertEqual(len(validate_on_update_snapshot(manifest["columns"])), len(hashlib.sha256().hexdigest()))
        reordered_digest = validate_on_update_snapshot(list(reversed(manifest["columns"])))
        self.assertEqual(len(reordered_digest), len(hashlib.sha256().hexdigest()))
        changed = list(manifest["columns"])
        changed.pop()
        with self.assertRaises(RuntimeError) as mismatch:
            validate_on_update_snapshot(changed)
        self.assertEqual(catalog_error_code(mismatch.exception), "on_update_columns_mismatch")
        with tempfile.TemporaryDirectory() as directory:
            altered_manifest = dict(manifest, count=manifest["count"] - 1)
            path = Path(directory) / "expected.json"
            path.write_text(json.dumps(altered_manifest), encoding="utf-8")
            with self.assertRaises(RuntimeError) as bad_metadata:
                validate_on_update_snapshot(manifest["columns"], manifest_path=path)
        self.assertEqual(catalog_error_code(bad_metadata.exception), "on_update_manifest_metadata_mismatch")

    def test_time_risk_counts_are_aggregated_and_column_scoped(self):
        connection = MagicMock()
        cursor = connection.cursor.return_value.__enter__.return_value
        cursor.fetchall.return_value = [(0, 2)]
        columns = {"items": [
            {"name": "window_ok", "data_type": "time"},
            {"name": "window_bad", "data_type": "time"},
        ]}
        self.assertEqual(time_conversion_risks(connection, columns), {"items.window_bad": 2})
        statement = cursor.execute.call_args.args[0]
        self.assertIn("TIME_TO_SEC", statement)
        self.assertIn("MICROSECOND", statement)

    def test_tenant_contract_fingerprint_is_stable_for_mapping_order(self):
        tables = {"alpha": {}, "beta": {}}
        columns = {
            "alpha": [{"name": "tenant_id", "column_type": "int(11)", "nullable": False, "default": 1}],
            "beta": [],
        }
        result = tenant_contract(tables, columns)
        reversed_result = tenant_contract(dict(reversed(list(tables.items()))), columns)
        self.assertEqual(result, reversed_result)
        self.assertEqual(result[0], ["alpha"])
        self.assertEqual(result[1], ["N|beta"])

    def test_enum_values_outside_domain_are_captured_without_rewriting(self):
        columns = {"items": [{"name": "state", "data_type": "enum", "domain_values": ["a", "b"]}]}
        connection = MagicMock()
        cursor = connection.cursor.return_value.__enter__.return_value
        cursor.fetchall.side_effect = [[(1, 1)], [("",)], [(0, 1)], [("63",)]]
        result = invalid_enum_counts(connection, columns)
        self.assertEqual(result, {"items.state": {"empty_enum_values": 1, "out_of_domain_values": 1,
                                                   "observed_values": [""]}})
        cursor.fetchall.side_effect = [[(0, 1)], [("63",)]]
        result = invalid_enum_counts(connection, columns)
        self.assertEqual(result["items.state"]["observed_values"], ["c"])

    def test_enum_summary_contains_counts_only(self):
        raw = {"items.state": {"empty_enum_values": 1, "out_of_domain_values": 1,
                               "observed_values": ["private-value"]}}
        summary = enum_counts_for_summary(raw)
        self.assertEqual(summary, {"items.state": {"empty_enum_values": 1, "out_of_domain_values": 1}})
        self.assertNotIn("private-value", json.dumps(summary))

    def test_postgres_target_guard_rejects_other_instance(self):
        connection = MagicMock()
        cursor = connection.cursor.return_value.__enter__.return_value
        cursor.fetchone.return_value = (
            "erp_ensaio_v9", "", "55432", "/var/lib/erp-pg-rehearsal/pg-data",
            "/var/lib/erp-pg-rehearsal/pg-socket", "180006", "America/Sao_Paulo", "UTF8",
        )
        verify_v9_postgres_connection(connection)
        cursor.fetchone.return_value = (
            "erp_ensaio_v9", "", "5432", "/var/lib/postgresql/18/main",
            "/var/run/postgresql", "180006", "America/Sao_Paulo", "UTF8",
        )
        with self.assertRaises(RuntimeError):
            verify_v9_postgres_connection(connection)

    def test_mysql_reader_grants_require_exact_allowlist(self):
        allowed = [
            "GRANT USAGE ON *.* TO 'pgloader'@'127.0.0.1'",
            "GRANT SELECT, SHOW VIEW, TRIGGER ON `inlaud99_erpcondor`.* TO 'pgloader'@'127.0.0.1'",
        ]
        validate_reader_grants("pgloader@127.0.0.1", allowed)
        for extra in ("PROCESS ON *.*", "FILE ON *.*", "REPLICATION CLIENT ON *.*", "GRANT OPTION"):
            with self.subTest(extra=extra), self.assertRaises(RuntimeError):
                validate_reader_grants("pgloader@127.0.0.1", allowed + [f"GRANT {extra} TO 'pgloader'@'127.0.0.1'"])
        with self.assertRaises(RuntimeError):
            validate_reader_grants("other@127.0.0.1", allowed)

    def test_enum_escaped_quote_and_backslash(self):
        self.assertEqual(parse_mysql_enum("enum('a','can\\'t','slash\\\\x','line\\nfeed')"),
                         ["a", "can't", "slash\\x", "line\nfeed"])

    def test_set_domain_uses_mysql_quoted_member_parser(self):
        self.assertEqual(parse_mysql_enum("set('entrada','saída','observação')"),
                         ["entrada", "saída", "observação"])

    def test_unsigned_and_decimal(self):
        self.assertEqual(mysql_type_to_pg("int", "int(10) unsigned"), "bigint")
        self.assertEqual(mysql_type_to_pg("bigint", "bigint(20) unsigned"), "numeric(20,0)")
        self.assertEqual(mysql_type_to_pg("decimal", "decimal(19,4)"), "numeric(19,4)")
        self.assertEqual(mysql_type_to_pg("tinyint", "tinyint(1)"), "smallint")

    def test_zero_dates(self):
        self.assertTrue(zero_date("0000-00-00"))
        self.assertTrue(zero_date("2024-00-10 00:00:00"))
        self.assertFalse(zero_date("2024-02-10 00:00:00"))

    def test_timestamp_from_utc_to_sao_paulo(self):
        value = datetime(2026, 1, 15, 12, 30, tzinfo=timezone.utc)
        self.assertEqual(convert_temporal(value, "timestamp"), datetime(2026, 1, 15, 9, 30))

    def test_timestamp_respects_historical_sao_paulo_dst(self):
        january = datetime(2018, 1, 15, 12, 0, tzinfo=timezone.utc)
        june = datetime(2018, 6, 15, 12, 0, tzinfo=timezone.utc)
        self.assertEqual(convert_temporal(january, "timestamp"), datetime(2018, 1, 15, 10, 0))
        self.assertEqual(convert_temporal(june, "timestamp"), datetime(2018, 6, 15, 9, 0))

    def test_timestamp_fall_back_fold_is_detected(self):
        first = convert_temporal(datetime(2018, 2, 18, 1, 30, tzinfo=timezone.utc), "timestamp")
        second = convert_temporal(datetime(2018, 2, 18, 2, 30, tzinfo=timezone.utc), "timestamp")
        self.assertEqual(first, second)
        self.assertTrue(ambiguous_sao_paulo_wall_time(first))
        self.assertFalse(ambiguous_sao_paulo_wall_time(datetime(2018, 2, 18, 3, 30)))

    def test_datetime_remains_civil(self):
        value = datetime(2026, 1, 15, 12, 30)
        self.assertEqual(convert_temporal(value, "datetime"), value)

    def test_fractional_datetime_fails_closed(self):
        with self.assertRaises(ValueError):
            convert_temporal(datetime(2026, 1, 1, 0, 0, 0, 1), "datetime")

    def test_mysql_duration_time_fails_closed(self):
        with self.assertRaises(ValueError):
            convert_temporal("-12:00:00", "time")
        with self.assertRaises(ValueError):
            convert_temporal("25:00:00", "time")

    def test_row_hash_is_null_and_length_delimited(self):
        self.assertNotEqual(row_digest((None, "ab"), ["text", "text"]),
                            row_digest(("", "ab"), ["text", "text"]))
        self.assertNotEqual(row_digest(("a", "bc"), ["text", "text"]),
                            row_digest(("ab", "c"), ["text", "text"]))


class DdlTests(unittest.TestCase):
    def test_sequence_must_be_exactly_max_plus_one_and_unconsumed(self):
        connection = MagicMock()
        cursor = connection.cursor.return_value.__enter__.return_value
        cursor.fetchone.side_effect = [("erp.items_id_seq",), ("erp", "items_id_seq"), (11, False)]
        self.assertTrue(sequence_status(connection, "erp", "items", "id", 10))
        cursor.fetchone.side_effect = [("erp.items_id_seq",), ("erp", "items_id_seq"), (12, False)]
        self.assertFalse(sequence_status(connection, "erp", "items", "id", 10))
        cursor.fetchone.side_effect = [("erp.items_id_seq",), ("erp", "items_id_seq"), (11, True)]
        self.assertFalse(sequence_status(connection, "erp", "items", "id", 10))

    def test_destination_fk_definition_must_match_source_signature(self):
        fk = {"table": "child", "name": "fk_child_parent", "columns": [
            {"position": 1, "column": "parent_id", "ref_table": "parent", "ref_column": "id",
             "on_update": "CASCADE", "on_delete": "RESTRICT"}
        ]}
        connection = MagicMock()
        cursor = connection.cursor.return_value.__enter__.return_value
        cursor.fetchone.return_value = ("f", False, "c", "r", ["parent_id"], ["id"], "erp", "parent")
        self.assertFalse(verify_destination_fk(connection, fk))
        cursor.fetchone.return_value = ("f", False, "c", "a", ["parent_id"], ["id"], "erp", "parent")
        with self.assertRaises(RuntimeError):
            verify_destination_fk(connection, fk)
        cursor.fetchone.return_value = None
        with self.assertRaises(RuntimeError):
            verify_destination_fk(connection, fk)

    def test_enum_values_outside_domain_are_added_only_to_generated_check(self):
        catalog = {
            "tables": {"items": {"schema": "erp"}},
            "columns": {"items": [{
                "name": "status", "pg_type": "text", "generated": False,
                "nullable": False, "default": None, "data_type": "enum",
                "column_type": "enum('a','b')", "extra": "", "unsigned": False,
                "auto_increment": False, "collate": None, "domain_values": ["a", "b"],
            }]},
            "indexes": {"items": {}}, "foreign_keys": [], "zero_value_counts": {},
            "invalid_enum_counts": {"items.status": {"empty_enum_values": 0, "out_of_domain_values": 1,
                                                        "observed_values": ["c"]}},
        }
        ddl = build_sql(catalog)["001_schema.sql"]
        self.assertIn("CHECK (\"status\" IN ('a', 'b', 'c'))", ddl)
        self.assertNotIn("invalid_enum_check_values", json.dumps(catalog))

    def test_routine_snapshot_requires_exact_approved_fingerprints(self):
        expected_path = ROOT / "expected_routines.json"
        snapshot, fingerprint = verified_routine_snapshot(expected_path)
        self.assertEqual(len(snapshot), 2)
        self.assertEqual(len(fingerprint), 64)
        changed = json.loads(expected_path.read_text(encoding="utf-8"))
        changed["routines"][0]["definition_sha256"] = "0" * 64
        with tempfile.NamedTemporaryFile("w", encoding="utf-8") as stream:
            json.dump(changed, stream)
            stream.flush()
            with self.assertRaises(RuntimeError):
                verified_routine_snapshot(stream.name)

    def test_legacy_table_keeps_primary_unique_and_normal_indexes(self):
        table = "usuarios_bkp_20261107"
        columns = [
            {"name": "id", "pg_type": "integer", "generated": False, "nullable": False,
             "default": None, "data_type": "int", "column_type": "int", "extra": "",
             "unsigned": False, "auto_increment": False, "collate": None},
            {"name": "email", "pg_type": "varchar(100)", "generated": False, "nullable": False,
             "default": None, "data_type": "varchar", "column_type": "varchar(100)", "extra": "",
             "unsigned": False, "auto_increment": False, "collate": '"C"'},
            {"name": "observacao", "pg_type": "text", "generated": False, "nullable": True,
             "default": None, "data_type": "text", "column_type": "text", "extra": "",
             "unsigned": False, "auto_increment": False, "collate": '"C"'},
        ]
        indexes = {table: {
            "PRIMARY": [{"non_unique": False, "column": "id", "sub_part": None, "direction": "A", "type": "BTREE"}],
            "uq_email": [{"non_unique": False, "column": "email", "sub_part": None, "direction": "A", "type": "BTREE"}],
            "ix_observacao": [{"non_unique": True, "column": "observacao", "sub_part": None, "direction": "A", "type": "BTREE"}],
        }}
        catalog = {"tables": {table: {"schema": "legado"}}, "columns": {table: columns},
                   "indexes": indexes, "foreign_keys": [], "zero_value_counts": {},
                   "invalid_enum_counts": {}}
        generated = build_sql(catalog)
        constraints = generated["002_constraints_indexes.sql"]
        self.assertIn('ADD CONSTRAINT "usuarios_bkp_20261107_pkey" PRIMARY KEY ("id")', constraints)
        self.assertIn('CREATE UNIQUE INDEX "ux_usuarios_bkp_20261107_uq_email" ON "legado"."usuarios_bkp_20261107"', constraints)
        self.assertIn('CREATE INDEX "ix_usuarios_bkp_20261107_ix_observacao" ON "legado"."usuarios_bkp_20261107"', constraints)

    def test_on_update_timestamp_only_changes_when_other_columns_change(self):
        catalog = {
            "tables": {"items": {"schema": "erp"}},
            "columns": {"items": [{
                "name": "updated_at", "pg_type": "timestamp(0) without time zone",
                "generated": False, "nullable": True, "default": None,
                "data_type": "datetime", "column_type": "datetime",
                "extra": "on update CURRENT_TIMESTAMP", "unsigned": False,
                "auto_increment": False, "collate": None,
            }]},
            "indexes": {"items": {}}, "foreign_keys": [],
            "zero_value_counts": {}, "invalid_enum_counts": {},
        }
        generated = build_sql(catalog)["004_on_update_timestamp.sql"]
        self.assertIn("NEW.\"updated_at\" IS NOT DISTINCT FROM OLD.\"updated_at\"", generated)
        self.assertIn("(to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at')", generated)
        self.assertIn("NEW.\"updated_at\" := LOCALTIMESTAMP(0)", generated)

    def test_fk_validation_is_separate(self):
        catalog = {
            "tables": {
                "parent": {"schema": "erp"}, "child": {"schema": "erp"},
            },
            "columns": {
                "parent": [{"name": "id", "pg_type": "bigint", "generated": False,
                            "nullable": False, "default": None, "data_type": "bigint",
                            "column_type": "bigint unsigned", "extra": "auto_increment",
                            "unsigned": True, "auto_increment": True, "collate": None}],
                "child": [{"name": "id", "pg_type": "bigint", "generated": False,
                           "nullable": False, "default": None, "data_type": "bigint",
                           "column_type": "bigint", "extra": "auto_increment",
                           "unsigned": False, "auto_increment": True, "collate": None},
                          {"name": "parent_id", "pg_type": "bigint", "generated": False,
                           "nullable": True, "default": None, "data_type": "bigint",
                           "column_type": "bigint", "extra": "", "unsigned": False,
                           "auto_increment": False, "collate": None}],
            },
            "indexes": {"parent": {"PRIMARY": [{"non_unique": False, "column": "id",
                                                    "sub_part": None, "direction": "A", "type": "BTREE"}]},
                        "child": {"PRIMARY": [{"non_unique": False, "column": "id",
                                                   "sub_part": None, "direction": "A", "type": "BTREE"}]}},
            "foreign_keys": [{"table": "child", "name": "fk_child_parent", "columns": [
                {"column": "parent_id", "ref_table": "parent", "ref_column": "id",
                 "on_update": "RESTRICT", "on_delete": "RESTRICT"}]}],
            "zero_value_counts": {}, "invalid_enum_counts": {},
        }
        sql = build_sql(catalog)
        self.assertIn("NOT VALID", sql["002_foreign_keys.sql"])
        self.assertNotIn("VALIDATE CONSTRAINT", sql["002_foreign_keys.sql"])
        self.assertNotIn("FOREIGN KEY", sql["002_constraints_indexes.sql"])
        self.assertIn("VALIDATE CONSTRAINT", sql["002_validate_constraints.sql"])
        self.assertIn("CHECK (\"id\" >= 0)", sql["001_schema.sql"])
        self.assertIn("GENERATED BY DEFAULT AS IDENTITY", sql["001_schema.sql"])


if __name__ == "__main__":
    unittest.main()
