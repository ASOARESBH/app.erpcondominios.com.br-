import sys
import os
import json
import hashlib
import io
import tempfile
import unittest
from contextlib import redirect_stdout, redirect_stderr
from unittest.mock import MagicMock, patch
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2] / "tools" / "migracao_pg"
sys.path.insert(0, str(ROOT))

from common import (ambiguous_sao_paulo_wall_time, convert_temporal, generated_name, mysql_type_to_pg, parse_mysql_enum, row_digest,
                    json_contains_nul, verify_v9_postgres_connection, verify_v10d_postgres_connection,
                    require_v10d_database, require_v10d_endpoint, V10D_DATABASE,
                    V10D_PGDATA, V10D_PGSOCKET, V10D_PGPORT, zero_date)
from catalog import (build_sql, enum_counts_for_summary, invalid_enum_counts, time_conversion_risks, validate_on_update_snapshot,
                     expression_sql, validate_checklist_upsert_contract, validate_reader_grants, catalog_error_code,
                     verified_routine_snapshot, routine_parameter_signature, EXPECTED_BACKUP_CANDIDATES,
                     EXPECTED_FK_TYPE_NORMALIZATIONS, normalize_fk_types, tenant_contract,
                     text_nul_counts_for_table, validate_sensitive_column_defaults)
from reconcile import (numeric_equal, sequence_status, verify_destination_fk, verify_destination_fk_types,
                       verify_signed_integer_range_checks, normalize_postgres_type)
from run_migration import (APPROVED_POST_FK_RUN_ID, APPROVED_POST_FK_SOURCE_COMMIT,
                           APPROVED_C2_COMMIT, APPROVED_RESUME_RUN_ID, APPROVED_RESUME_SOURCE_COMMIT, BASELINE_MAIN_COMMIT,
                           apply_sql, as_postgres, run_reconciliation_step, run_step,
                           quote_pg_literal, validate_post_fk_resume_history, validate_resume_history,
                           validate_resume_table_inventory, validate_resume_catalog_transition,
                           validate_catalog_artifact_snapshot, verify_post_fk_run_manifest, safe_summary_scalars,
                           validate_v10d_acceptance, create_target_database,
                           verify_approved_c2_ancestry, verify_baseline_ancestry, verify_resume_code_ancestry,
                           verify_resume_target, parse_arguments,
                           verify_post_fk_target)


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
            "approved foreign key type normalization signature is missing": "fk_type_normalization_missing",
            "duplicate foreign key column pair requires explicit review": "fk_type_pair_duplicate",
            "foreign key column metadata is missing": "fk_column_metadata_missing",
            "unlisted foreign key type mismatch requires explicit review": "fk_type_unlisted_mismatch",
            "foreign key type normalization metadata differs from the reviewed contract": "fk_type_metadata_mismatch",
            "unsigned foreign key values exceed the approved signed bigint range": "fk_unsigned_bigint_range_mismatch",
            "non-null foreign key source contains null values": "fk_type_nullability_mismatch",
            "foreign key PostgreSQL types remain incompatible after normalization": "fk_type_incompatible",
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

    def test_runner_records_only_reconciliation_stage_code_and_sqlstate(self):
        proc = MagicMock()
        proc.stdout = iter(["RECONCILIATION_FAILED type=RuntimeError stage=table_destination_hash "
                            "code=reconciliation_table_destination_hash sqlstate=none\n"])
        proc.wait.return_value = 1
        proc.returncode = 1
        with tempfile.TemporaryDirectory() as directory:
            log = Path(directory) / "run.jsonl"
            with patch("run_migration.subprocess.Popen", return_value=proc), redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, "phase reconciliation failed"):
                    run_step(["reconcile"], "reconciliation", log, whitelist_json=True)
            content = log.read_text(encoding="utf-8")
            record = json.loads(content)
        self.assertEqual(record["error_type"], "RuntimeError")
        self.assertEqual(record["error_code"], "reconciliation_table_destination_hash")
        self.assertEqual(record["error_stage"], "table_destination_hash")
        self.assertEqual(record["sqlstate"], "none")
        self.assertNotIn("ERROR:", content)

    def test_runner_records_sanitized_reconciliation_metrics_block(self):
        proc = MagicMock()
        proc.stdout = iter(["RECONCILIATION_BLOCKED stage=metrics_gate code=reconciliation_metrics_mismatch\n"])
        proc.wait.return_value = 2
        proc.returncode = 2
        with tempfile.TemporaryDirectory() as directory:
            log = Path(directory) / "run.jsonl"
            with patch("run_migration.subprocess.Popen", return_value=proc), redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, "phase reconciliation failed"):
                    run_step(["reconcile"], "reconciliation", log, whitelist_json=True)
            record = json.loads(log.read_text(encoding="utf-8"))
        self.assertEqual(record["error_type"], "ReconciliationBlocked")
        self.assertEqual(record["error_stage"], "metrics_gate")
        self.assertEqual(record["error_code"], "reconciliation_metrics_mismatch")
        self.assertEqual(record["sqlstate"], "none")

    def test_runner_extracts_only_postgres_sqlstate(self):
        proc = MagicMock()
        proc.stdout = iter(["psql:/private.sql: ERROR: 42501: permission denied for synthetic object\n"])
        proc.wait.return_value = 1
        proc.returncode = 1
        with tempfile.TemporaryDirectory() as directory:
            log = Path(directory) / "run.jsonl"
            with patch("run_migration.subprocess.Popen", return_value=proc), redirect_stdout(io.StringIO()):
                with self.assertRaisesRegex(RuntimeError, "phase foreign_keys_not_valid failed"):
                    run_step(["psql"], "foreign_keys_not_valid", log)
            content = log.read_text(encoding="utf-8")
            record = json.loads(content)
        self.assertEqual(record["error_type"], "PostgreSQLError")
        self.assertEqual(record["error_code"], "postgresql_error")
        self.assertEqual(record["sqlstate"], "42501")
        self.assertNotIn("permission denied", content)

    def test_foreign_key_sql_is_atomic_and_sqlstate_only(self):
        with patch("run_migration.as_postgres", side_effect=lambda *args: [str(arg) for arg in args]):
            with patch("run_migration.run_step") as runner:
                apply_sql(V10D_DATABASE, Path("/tmp/fks.sql"), "foreign_keys_not_valid", Path("/tmp/run.jsonl"))
        command = runner.call_args.args[0]
        self.assertIn("-1", command)
        self.assertIn("VERBOSITY=verbose", command)
        self.assertEqual(command[-2:], ["-f", Path("/tmp/fks.sql")])
        with patch("run_migration.run_step") as runner:
            with self.assertRaisesRegex(RuntimeError, "except the exclusive v10d target"):
                apply_sql("erp_ensaio_v9", Path("/tmp/fks.sql"), "foreign_keys_not_valid", Path("/tmp/run.jsonl"))
            runner.assert_not_called()

    def test_post_fk_execution_helper_invokes_only_reconciliation(self):
        with patch("run_migration.run_step") as runner:
            run_reconciliation_step(["nsenter", "python"], V10D_DATABASE, Path("/tmp/client.cnf"),
                                    Path("/tmp/run"), Path("/tmp/run.jsonl"))
        self.assertEqual(runner.call_count, 1)
        command, phase, _log = runner.call_args.args
        self.assertEqual(phase, "reconciliation")
        self.assertTrue(any(str(item).endswith("reconcile.py") for item in command))
        self.assertFalse(any(token in str(item) for item in command
                             for token in ("loader.py", "trigger_harness.py", "002_foreign_keys.sql", "ANALYZE")))
        self.assertTrue(any(str(item).endswith("reconciliacao_v10d.csv") for item in command))
        with patch("run_migration.run_step") as runner:
            with self.assertRaisesRegex(RuntimeError, "except the exclusive v10d target"):
                run_reconciliation_step(["nsenter", "python"], "erp_ensaio_v9", Path("/tmp/client.cnf"),
                                        Path("/tmp/run"), Path("/tmp/run.jsonl"))
            runner.assert_not_called()

    def test_post_fk_target_requires_exact_initial_fk_state_and_counts(self):
        names = [f"fixture_{index:03d}" for index in range(270)]
        metrics = [{"table": name, "source_rows": 202609 if index == 0 else 0}
                   for index, name in enumerate(names)]
        summary = {"legacy_tables": [], "legacy_classification": {}}
        loader = {"tables_loaded": 270, "source_rows_loaded": 202609, "table_metrics": metrics}
        fields = "America/Sao_Paulo|UTF8|270|11|11|bigint|bigint|bigint"
        rows = "\n".join(f"erp.{name}|{metric['source_rows']}" for name, metric in zip(names, metrics))
        with patch("run_migration.subprocess.run", side_effect=[
                MagicMock(returncode=0, stdout=fields), MagicMock(returncode=0, stdout=rows)]) as run:
            self.assertEqual(verify_post_fk_target("erp_ensaio_v9", summary, loader), 11)
        self.assertEqual(run.call_count, 2)
        with patch("run_migration.subprocess.run", return_value=MagicMock(
                returncode=0, stdout="America/Sao_Paulo|UTF8|270|11|10|bigint|bigint|bigint")) as run:
            with self.assertRaisesRegex(RuntimeError, "post-FK rehearsal target"):
                verify_post_fk_target("erp_ensaio_v9", summary, loader)
        self.assertEqual(run.call_count, 1)

    def test_signed_integer_checks_match_exact_tables_and_ranges(self):
        normalizations = [
            {"table": "aplicativos_versoes", "column": "aplicativo_id",
             "rule": "widen_signed_integer_foreign_key"},
            {"table": "moradores_anexos", "column": "morador_id",
             "rule": "widen_signed_integer_foreign_key"},
            {"table": "rbac_auditoria", "column": "sessao_id",
             "rule": "narrow_unsigned_bigint_with_verified_range"},
        ]
        rows = []
        for item in normalizations[:2]:
            name = generated_name("ck", item["table"], item["column"], "mysql_signed_int_range")
            definition = (f"CHECK (({item['column']} >= -2147483648) AND "
                          f"({item['column']} <= 2147483647))")
            if item["table"] == "aplicativos_versoes":
                definition = (f"CHECK (({item['column']} >= '-2147483648'::bigint) AND "
                              f"({item['column']} <= '2147483647'::int8))")
            rows.append(("erp", item["table"], name, definition, True))
        pg = MagicMock()
        pg.cursor.return_value.__enter__.return_value.fetchall.return_value = rows
        verify_signed_integer_range_checks(pg, normalizations)
        rows[0] = (*rows[0][:3], "CHECK ((aplicativo_id >= -2147483647) AND "
                   "(aplicativo_id <= 2147483647))", True)
        pg.cursor.return_value.__enter__.return_value.fetchall.return_value = rows
        with self.assertRaisesRegex(RuntimeError, "range-check definitions"):
            verify_signed_integer_range_checks(pg, normalizations)

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

    def test_v10d_commit_requires_c2_harness_fix_as_ancestor(self):
        with patch("run_migration.subprocess.run") as run:
            run.return_value.returncode = 0
            verify_approved_c2_ancestry(Path("/repo"), "a" * 40)
            self.assertEqual(run.call_args.args[0][3:6],
                             ["merge-base", "--is-ancestor", APPROVED_C2_COMMIT])
            run.return_value.returncode = 1
            with self.assertRaisesRegex(RuntimeError, "not descended from the C.2 harness fix"):
                verify_approved_c2_ancestry(Path("/repo"), "b" * 40)

    def test_v10d_cli_accepts_only_its_database_and_rejects_resume(self):
        valid = parse_arguments(["--database", V10D_DATABASE, "--expected-commit", "a" * 40])
        self.assertEqual(valid.database, "erp_ensaio_v10d")
        for argv in (
            ["--database", "erp_ensaio_v9", "--expected-commit", "a" * 40],
            ["--database", "erp_ensaio_v10c", "--expected-commit", "a" * 40],
            ["--database", V10D_DATABASE, "--expected-commit", "a" * 40, "--resume-partial-target"],
            ["--database", V10D_DATABASE, "--expected-commit", "a" * 40, "--resume-post-fk-reconciliation"],
            ["--database", V10D_DATABASE, "--expected-commit", "a" * 40,
             "--source-run-id", "20261108T203651Z"],
        ):
            with self.subTest(argv=argv), redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
                parse_arguments(argv)

    def test_v10d_database_creation_refuses_wrong_or_nonempty_cluster(self):
        with patch("run_migration.subprocess.run") as run:
            with self.assertRaisesRegex(RuntimeError, "except the exclusive v10d target"):
                create_target_database("erp_ensaio_v9")
            run.assert_not_called()
        with patch("run_migration.subprocess.run") as run:
            run.return_value.returncode = 0
            run.return_value.stdout = "1\n"
            with self.assertRaisesRegex(RuntimeError, "contains a user database"):
                create_target_database(V10D_DATABASE)
            self.assertEqual(run.call_count, 1)
            query = run.call_args.args[0][-1]
            self.assertIn("datistemplate", query)
            self.assertIn("erp_ensaio_v10d", query)
        with patch("run_migration.subprocess.run") as run:
            run.side_effect = [
                MagicMock(returncode=0, stdout="0\n"),
                MagicMock(returncode=0, stdout=""),
                MagicMock(returncode=0, stdout=""),
                MagicMock(returncode=0, stdout="America/Sao_Paulo|UTF8\n"),
            ]
            create_target_database(V10D_DATABASE)
            self.assertEqual(run.call_count, 4)
            create_args = run.call_args_list[1].args[0]
            self.assertEqual(create_args[-1], V10D_DATABASE)

    def test_v10d_runtime_paths_and_port_are_separate_from_v9(self):
        self.assertEqual((V10D_DATABASE, V10D_PGDATA, V10D_PGSOCKET, V10D_PGPORT),
                         ("erp_ensaio_v10d", "/var/lib/erp-pg-rehearsal/v10d/pg-data",
                          "/var/lib/erp-pg-rehearsal/v10d/pg-socket", 55433))
        self.assertNotEqual(V10D_PGPORT, 55432)
        self.assertNotIn("/v9/", V10D_PGDATA)

    def test_pipeline_components_reject_non_v10d_database_or_endpoint(self):
        require_v10d_database(V10D_DATABASE)
        require_v10d_endpoint(V10D_DATABASE, V10D_PGSOCKET, V10D_PGPORT)
        for database in ("erp_ensaio_v9", "erp_ensaio_v10", "erp_ensaio_v10b", "erp_ensaio_v10c"):
            with self.subTest(database=database), self.assertRaises(SystemExit):
                require_v10d_database(database)
        for socket, port in ((V10D_PGSOCKET, 55432), ("/var/lib/erp-pg-rehearsal/pg-socket", V10D_PGPORT)):
            with self.subTest(socket=socket, port=port), self.assertRaises(SystemExit):
                require_v10d_endpoint(V10D_DATABASE, socket, port)

    def test_v10d_acceptance_requires_exact_catalog_rows_and_zero_fk_orphans(self):
        catalog = {"tables": 270, "columns": 3339, "primary_keys": 240, "foreign_keys": 11,
                   "triggers": 7, "routines": 2, "events": 0, "auto_increment_columns": 232,
                   "zero_date_counts": {}, "fk_type_normalizations": [{"coordinate": "reviewed"}],
                   "fk_type_normalizations_sha256": "reviewed"}
        metrics = [{"table": f"table_{index}", "source_rows": 202609 if index == 0 else 0}
                   for index in range(270)]
        loader = {"tables_loaded": 270, "source_rows_loaded": 202609,
                  "table_metrics": metrics, "zero_date_conversions": {},
                  "dst_ambiguous_local_times": {}}
        summary = {"status": "ACCEPTED", "tables": 270, "columns": 3339,
                   "tables_with_primary_key": 240, "source_rows": 202609,
                   "destination_rows": 202609, "identity_columns": 232,
                   "foreign_keys": 11, "foreign_keys_valid": 11,
                   "foreign_keys_with_orphans": 0, "fk_orphan_count_total": 0,
                   "fk_type_normalizations": catalog["fk_type_normalizations"],
                   "fk_type_normalizations_sha256": "reviewed"}
        for key in ("row_count_mismatches", "tables_with_row_hash_divergence", "row_hash_divergent_total",
                    "pk_max_mismatches", "identity_max_mismatches", "sequences_not_ready",
                    "numeric_sum_mismatches", "binary_mismatches", "tenant_bucket_mismatches",
                    "timestamp_mismatches", "fk_tenant_mismatch_count"):
            summary[key] = 0
        validate_v10d_acceptance(catalog, loader, summary)
        failures = (
            (catalog, loader, {**summary, "columns": 3338}),
            (catalog, {**loader, "source_rows_loaded": 202608}, summary),
            (catalog, loader, {**summary, "foreign_keys_valid": 10}),
            (catalog, loader, {**summary, "foreign_keys_with_orphans": 1,
                               "fk_orphan_count_total": 1, "status": "ACCEPTED_WITH_ORPHANED_FKS"}),
            (catalog, loader, {**summary, "row_hash_divergent_total": 1}),
        )
        for bad_catalog, bad_loader, bad_summary in failures:
            with self.subTest(summary=bad_summary), self.assertRaises(RuntimeError):
                validate_v10d_acceptance(bad_catalog, bad_loader, bad_summary)

    def test_postgres_python_environment_exposes_migration_modules_without_bytecode(self):
        command = as_postgres("/venv/python3", "-s")
        self.assertIn(f"PYTHONPATH={ROOT}", command)
        self.assertIn("PYTHONDONTWRITEBYTECODE=1", command)

    def test_runner_disables_bytecode_before_importing_migration_modules(self):
        source = (ROOT / "run_migration.py").read_text(encoding="utf-8")
        self.assertLess(source.index("sys.dont_write_bytecode = True"), source.index("from common import"))

    def post_fk_history_fixture(self):
        tests = {
            "erp_app_minimum_privileges", "on_update_timestamp_semantics", "after_qrcode_token_insert",
            "after_validacao_dispositivo_insert", "trg_alerta_estoque_baixo", "trg_atualizar_km_acumulado",
            "tr_dependente_ativar_veiculos", "tr_dependente_inativar_veiculos", "tr_dependente_deletar_veiculos",
            "corrigir_moradores_anexos_tenant", "mt_adicionar_coluna",
        }
        records = [{"event": "pipeline_started", "database": "erp_ensaio_v9",
                    "code_commit": APPROVED_POST_FK_SOURCE_COMMIT, "batch_size": 1000,
                    "resume_source_run": APPROVED_RESUME_RUN_ID},
                   {"event": "resume_artifacts_verified", "source_run": APPROVED_RESUME_RUN_ID,
                    "tables": 270, "rows": 202609},
                   {"event": "resume_catalog_delta_verified", "source_run": APPROVED_RESUME_RUN_ID,
                    "fk_type_normalizations": 3, "source_rows": 202609},
                   {"event": "resume_target_counts_verified", "tables": 270,
                    "rows": 202609, "existing_foreign_keys": 0}]
        records.extend({"event": "step_complete", "phase": phase} for phase in (
            "resume_catalog_regeneration", "business_trigger_tests", "foreign_keys_not_valid", "analyze"))
        records.extend({"event": "synthetic_test_pass", "test": name} for name in sorted(tests))
        records.extend([{"event": "step_failed", "phase": "reconciliation", "error_type": "RuntimeError",
                         "error_code": "unknown", "sqlstate": "none"},
                        {"event": "pipeline_blocked", "stop_error": None, "cleanup_error": None},
                        {"event": "postgres_cluster_stopped", "reason": "pipeline_failure"}])
        return records

    def test_post_fk_resume_history_accepts_only_the_exact_reviewed_boundary(self):
        records = self.post_fk_history_fixture()
        validate_post_fk_resume_history(records, APPROVED_POST_FK_RUN_ID)
        for mutate in (
                lambda items: items.pop(next(i for i, row in enumerate(items)
                                             if row.get("event") == "synthetic_test_pass")),
                lambda items: items.append({"event": "pipeline_accepted"}),
                lambda items: items.append({"event": "table_loaded"}),
                lambda items: items.append({"event": "table_reconciled"}),
                lambda items: items.__setitem__(0, {**items[0], "resume_source_run": "other"}),
                lambda items: items.__setitem__(next(i for i, row in enumerate(items)
                                                      if row.get("event") == "step_failed"),
                                                {"event": "step_failed", "phase": "analyze",
                                                 "error_type": "RuntimeError", "error_code": "unknown",
                                                 "sqlstate": "none"}),
        ):
            altered = self.post_fk_history_fixture()
            mutate(altered)
            with self.assertRaises(RuntimeError):
                validate_post_fk_resume_history(altered, APPROVED_POST_FK_RUN_ID)

    def test_post_fk_resume_rejects_any_source_run_other_than_explicit_id(self):
        with self.assertRaisesRegex(RuntimeError, "source run identifier"):
            validate_post_fk_resume_history(self.post_fk_history_fixture(), "20261108T999999Z")

    def test_post_fk_catalog_snapshot_requires_identical_private_ddl(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "source"
            current = root / "current"
            source.mkdir()
            current.mkdir()
            body = "CREATE TABLE fixture (id bigint);\n"
            digest = hashlib.sha256(body.encode()).hexdigest()
            for folder in (source, current):
                path = folder / "001_schema.sql"
                path.write_text(body, encoding="utf-8")
                path.chmod(0o600)
            summary = {"ddl_sha256": {"001_schema.sql": digest}, "tables": 270}
            validate_catalog_artifact_snapshot(summary, dict(summary), source, current, os.geteuid())
            (current / "001_schema.sql").write_text(body + "-- drift\n", encoding="utf-8")
            (current / "001_schema.sql").chmod(0o600)
            with self.assertRaisesRegex(RuntimeError, "DDL changed"):
                validate_catalog_artifact_snapshot(summary, dict(summary), source, current, os.geteuid())

    def test_post_fk_manifest_binds_exact_runs_and_private_artifact_hashes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            runs = root / "runs"
            post = runs / ("run_" + APPROVED_POST_FK_RUN_ID)
            resume = runs / ("run_" + APPROVED_RESUME_RUN_ID)
            required_sql = {
                "001_schema.sql", "002_constraints_indexes.sql", "002_foreign_keys.sql",
                "002_validate_constraints.sql", "003_identity_sequences.sql",
                "004_on_update_timestamp.sql", "005_business_triggers_routines.sql", "006_grants.sql",
            }
            paths = [runs / (APPROVED_POST_FK_RUN_ID + ".jsonl"), post / "catalog.json",
                     post / "routine_snapshot.json", runs / (APPROVED_RESUME_RUN_ID + ".jsonl"),
                     resume / "catalog.json", resume / "loader_summary.json", resume / "routine_snapshot.json"]
            paths.extend(post / "sql" / name for name in required_sql)
            paths.extend(resume / "sql" / name for name in required_sql)
            hashes = {}
            for path in paths:
                path.parent.mkdir(parents=True, exist_ok=True)
                content = f"private fixture {path.name}\n"
                path.write_text(content, encoding="utf-8")
                path.chmod(0o600)
                hashes[str(path.relative_to(runs))] = hashlib.sha256(content.encode()).hexdigest()
            manifest_path = root / "manifest.json"
            manifest_path.write_text(json.dumps({
                "schema_version": 1, "post_fk_run_id": APPROVED_POST_FK_RUN_ID,
                "post_fk_code_commit": APPROVED_POST_FK_SOURCE_COMMIT,
                "resume_run_id": APPROVED_RESUME_RUN_ID,
                "resume_code_commit": APPROVED_RESUME_SOURCE_COMMIT, "files": hashes,
            }), encoding="utf-8")
            manifest_path.chmod(0o600)
            with patch("run_migration.RUN_ROOT", runs):
                verify_post_fk_run_manifest(APPROVED_POST_FK_RUN_ID, os.geteuid(),
                                             manifest_path=manifest_path, root_uid=os.geteuid())
                (post / "catalog.json").write_text("changed\n", encoding="utf-8")
                (post / "catalog.json").chmod(0o600)
                with self.assertRaisesRegex(RuntimeError, "root-owned manifest"):
                    verify_post_fk_run_manifest(APPROVED_POST_FK_RUN_ID, os.geteuid(),
                                                manifest_path=manifest_path, root_uid=os.geteuid())

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
        outputs = [MagicMock(returncode=0, stdout="America/Sao_Paulo|UTF8|270|0|integer|integer|numeric(20,0)"),
                   MagicMock(returncode=0, stdout="erp.fixture|3\n")]
        with patch("run_migration.subprocess.run", side_effect=outputs) as run:
            self.assertEqual(verify_resume_target("erp_ensaio_v9", summary, loader), 0)
        count_query = run.call_args_list[1].args[0][-1]
        self.assertIn('"erp"."fixture"', count_query)
        outputs[1].stdout = "erp.fixture|2\n"
        with patch("run_migration.subprocess.run", side_effect=outputs):
            with self.assertRaisesRegex(RuntimeError, "counts differ"):
                verify_resume_target("erp_ensaio_v9", summary, loader)

    def test_resume_target_reports_existing_foreign_key_count(self):
        summary = {"legacy_tables": []}
        loader = {"table_metrics": [{"table": "fixture", "source_rows": 3}], "source_rows_loaded": 3}
        outputs = [MagicMock(returncode=0, stdout="America/Sao_Paulo|UTF8|270|2|integer|integer|numeric(20,0)"),
                   MagicMock(returncode=0, stdout="erp.fixture|3\n")]
        with patch("run_migration.subprocess.run", side_effect=outputs):
            self.assertEqual(verify_resume_target("erp_ensaio_v9", summary, loader), 2)

    def test_resume_target_rejects_partially_promoted_fk_types(self):
        summary = {"legacy_tables": []}
        loader = {"table_metrics": [{"table": "fixture", "source_rows": 3}], "source_rows_loaded": 3}
        outputs = [MagicMock(returncode=0, stdout="America/Sao_Paulo|UTF8|270|0|bigint|integer|numeric(20,0)")]
        with patch("run_migration.subprocess.run", side_effect=outputs):
            with self.assertRaisesRegex(RuntimeError, "pre-harness state"):
                verify_resume_target("erp_ensaio_v9", summary, loader)

    def resume_catalog_fixture(self, root):
        previous = Path(root) / "previous"
        regenerated = Path(root) / "regenerated"
        previous.mkdir()
        regenerated.mkdir()
        required = {
            "001_schema.sql", "002_constraints_indexes.sql", "002_foreign_keys.sql",
            "002_validate_constraints.sql", "003_identity_sequences.sql", "004_on_update_timestamp.sql",
            "005_business_triggers_routines.sql", "006_grants.sql",
        }
        old_schema = ('CREATE TABLE "erp"."aplicativos_versoes" (\n  "aplicativo_id" integer NOT NULL\n);\n'
                      'CREATE TABLE "erp"."moradores_anexos" (\n  "morador_id" integer NOT NULL\n);\n'
                      'CREATE TABLE "erp"."rbac_auditoria" (\n  "sessao_id" numeric(20,0)\n);\n')
        new_schema = old_schema.replace('"aplicativo_id" integer', '"aplicativo_id" bigint')
        new_schema = new_schema.replace('"morador_id" integer', '"morador_id" bigint')
        new_schema = new_schema.replace('"sessao_id" numeric(20,0)', '"sessao_id" bigint')
        foreign_key_statements = [
            f'ALTER TABLE "erp"."child_{index}" ADD CONSTRAINT "fk_{index}" FOREIGN KEY ("id") '
            f'REFERENCES "erp"."parent_{index}" ("id") NOT VALID;'
            for index in range(11)
        ]
        old_foreign_keys = "\n".join(foreign_key_statements) + "\n"
        new_non_fk = [
            "DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace "
            "WHERE c.contype='f' AND n.nspname='erp') THEN "
            "RAISE EXCEPTION 'foreign key type normalization requires zero existing foreign keys'; END IF; END $$;"
        ]
        expected_normalizations = [
            ("aplicativos_versoes", "aplicativo_id", "aplicativos_catalogo", "id", "integer", "bigint",
             "widen_signed_integer_foreign_key"),
            ("moradores_anexos", "morador_id", "moradores", "id", "integer", "bigint",
             "widen_signed_integer_foreign_key"),
            ("rbac_auditoria", "sessao_id", "rbac_sessoes", "id", "numeric(20,0)", "bigint",
             "narrow_unsigned_bigint_with_verified_range"),
        ]
        for table, column, ref_table, ref_column, source_type, target_type, rule in expected_normalizations:
            qtable = f'"erp"."{table}"'
            ref_qtable = f"erp.{ref_table}"
            allowed = ", ".join(quote_pg_literal(value) for value in sorted({source_type, target_type}))
            new_non_fk.extend([
                "DO $$ DECLARE actual_type text; BEGIN "
                f"SELECT format_type(a.atttypid,a.atttypmod) INTO actual_type FROM pg_attribute a "
                f"WHERE a.attrelid={quote_pg_literal(qtable)}::regclass AND a.attname={quote_pg_literal(column)} "
                "AND a.attnum>0 AND NOT a.attisdropped; "
                f"IF actual_type IS NULL OR actual_type NOT IN ({allowed}) THEN "
                "RAISE EXCEPTION 'foreign key source type differs from the reviewed normalization'; END IF; END $$;",
                "DO $$ DECLARE actual_type text; BEGIN "
                f"SELECT format_type(a.atttypid,a.atttypmod) INTO actual_type FROM pg_attribute a "
                f"WHERE a.attrelid={quote_pg_literal(ref_qtable)}::regclass "
                f"AND a.attname={quote_pg_literal(ref_column)} AND a.attnum>0 AND NOT a.attisdropped; "
                f"IF actual_type IS DISTINCT FROM {quote_pg_literal(target_type)} THEN "
                "RAISE EXCEPTION 'foreign key referenced type differs from the reviewed normalization'; END IF; END $$;",
            ])
            if rule == "narrow_unsigned_bigint_with_verified_range":
                new_non_fk.append(
                    f"DO $$ BEGIN IF (SELECT COUNT(*) FROM {qtable}) <> 791 OR EXISTS "
                    f"(SELECT 1 FROM {qtable} WHERE \"{column}\" IS NULL OR "
                    f"(\"{column}\"::numeric <> trunc(\"{column}\"::numeric,0) OR \"{column}\" < 0 "
                    f"OR \"{column}\" > 9223372036854775807)) THEN "
                    "RAISE EXCEPTION 'unsigned foreign key values exceed the approved signed bigint range'; END IF; END $$;"
                )
            new_non_fk.append(
                f"ALTER TABLE {qtable} ALTER COLUMN \"{column}\" TYPE bigint USING \"{column}\"::bigint;"
            )
            if rule == "widen_signed_integer_foreign_key":
                check_name = generated_name("ck", table, column, "mysql_signed_int_range")
                new_non_fk.append(
                    f"ALTER TABLE {qtable} ADD CONSTRAINT \"{check_name}\" "
                    f"CHECK (\"{column}\" BETWEEN -2147483648 AND 2147483647);"
                )
        new_foreign_keys = "\n".join([*new_non_fk, *foreign_key_statements]) + "\n"
        old_contents = {name: "-- stable\n" for name in required}
        new_contents = dict(old_contents)
        old_contents["001_schema.sql"] = old_schema
        new_contents["001_schema.sql"] = new_schema
        old_contents["002_foreign_keys.sql"] = "BEGIN;\n" + old_foreign_keys + "COMMIT;\n"
        new_contents["002_foreign_keys.sql"] = new_foreign_keys
        for directory, contents in ((previous, old_contents), (regenerated, new_contents)):
            for name, body in contents.items():
                path = directory / name
                path.write_text(body, encoding="utf-8")
                path.chmod(0o600)
        normalizations = [
            {"table": "aplicativos_versoes", "column": "aplicativo_id", "referenced_table": "aplicativos_catalogo",
             "referenced_column": "id", "source_pg_type": "integer", "referenced_pg_type": "bigint",
             "target_pg_type": "bigint", "rule": "widen_signed_integer_foreign_key"},
            {"table": "moradores_anexos", "column": "morador_id", "referenced_table": "moradores",
             "referenced_column": "id", "source_pg_type": "integer", "referenced_pg_type": "bigint",
             "target_pg_type": "bigint", "rule": "widen_signed_integer_foreign_key"},
            {"table": "rbac_auditoria", "column": "sessao_id", "referenced_table": "rbac_sessoes",
             "referenced_column": "id", "source_pg_type": "numeric(20,0)", "referenced_pg_type": "bigint",
             "target_pg_type": "bigint", "rule": "narrow_unsigned_bigint_with_verified_range",
             "source_counts": {"rows": 791, "nulls": 0, "fractional": 0, "negative": 0,
                               "outside_signed_bigint": 0}},
        ]
        common_summary = {"tables": 270, "columns": 3339, "foreign_keys": 11, "triggers": 7,
                          "routines": 2, "events": 0, "auto_increment_columns": 232,
                          "zero_date_counts": {}, "source_estimated_bytes": 100}
        old_summary = {**common_summary,
                       "ddl_sha256": {name: hashlib.sha256((previous / name).read_bytes()).hexdigest()
                                      for name in required}}
        manifest = json.dumps(normalizations, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
        new_summary = {**common_summary,
                       "fk_type_normalizations": normalizations,
                       "fk_type_normalizations_sha256": hashlib.sha256(manifest.encode()).hexdigest(),
                       "ddl_sha256": {name: hashlib.sha256((regenerated / name).read_bytes()).hexdigest()
                                      for name in required}}
        return old_summary, new_summary, previous, regenerated

    def test_resume_catalog_transition_accepts_only_reviewed_fk_delta(self):
        with tempfile.TemporaryDirectory() as directory:
            previous, regenerated, old_sql, new_sql = self.resume_catalog_fixture(directory)
            validate_resume_catalog_transition(previous, regenerated, old_sql, new_sql)

    def test_resume_catalog_transition_rejects_unrelated_ddl_change(self):
        with tempfile.TemporaryDirectory() as directory:
            previous, regenerated, old_sql, new_sql = self.resume_catalog_fixture(directory)
            (new_sql / "006_grants.sql").write_text("-- changed\n", encoding="utf-8")
            (new_sql / "006_grants.sql").chmod(0o600)
            regenerated["ddl_sha256"]["006_grants.sql"] = hashlib.sha256(
                (new_sql / "006_grants.sql").read_bytes()).hexdigest()
            with self.assertRaisesRegex(RuntimeError, "outside the reviewed FK normalization"):
                validate_resume_catalog_transition(previous, regenerated, old_sql, new_sql)

    def test_resume_catalog_transition_rejects_range_drift_and_manifest_changes(self):
        with tempfile.TemporaryDirectory() as directory:
            previous, regenerated, old_sql, new_sql = self.resume_catalog_fixture(directory)
            path = new_sql / "002_foreign_keys.sql"
            path.write_text(path.read_text(encoding="utf-8").replace("<> 791", "<> 792"), encoding="utf-8")
            path.chmod(0o600)
            regenerated["ddl_sha256"][path.name] = hashlib.sha256(path.read_bytes()).hexdigest()
            with self.assertRaisesRegex(RuntimeError, "atomic bundle"):
                validate_resume_catalog_transition(previous, regenerated, old_sql, new_sql)

    def test_safe_summary_excludes_fingerprints_but_keeps_reconciliation_counters(self):
        self.assertEqual(safe_summary_scalars({"row_hash_divergent_total": 0, "fk_type_normalizations_sha256": "abc",
                                               "catalog_fingerprint": "def", "tables": 270}),
                         {"row_hash_divergent_total": 0, "tables": 270})

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

    def test_v10d_postgres_target_guard_checks_database_port_data_and_socket(self):
        connection = MagicMock()
        cursor = connection.cursor.return_value.__enter__.return_value
        cursor.fetchone.return_value = (
            V10D_DATABASE, "", str(V10D_PGPORT), V10D_PGDATA, V10D_PGSOCKET,
            "180006", "America/Sao_Paulo", "UTF8",
        )
        verify_v10d_postgres_connection(connection)
        for changed in (
            ("erp_ensaio_v9", "", str(V10D_PGPORT), V10D_PGDATA, V10D_PGSOCKET,
             "180006", "America/Sao_Paulo", "UTF8"),
            (V10D_DATABASE, "*", str(V10D_PGPORT), V10D_PGDATA, V10D_PGSOCKET,
             "180006", "America/Sao_Paulo", "UTF8"),
            (V10D_DATABASE, "", "55432", V10D_PGDATA, V10D_PGSOCKET,
             "180006", "America/Sao_Paulo", "UTF8"),
            (V10D_DATABASE, "", str(V10D_PGPORT), "/var/lib/erp-pg-rehearsal/pg-data", V10D_PGSOCKET,
             "180006", "America/Sao_Paulo", "UTF8"),
            (V10D_DATABASE, "", str(V10D_PGPORT), V10D_PGDATA, "/var/lib/erp-pg-rehearsal/pg-socket",
             "180006", "America/Sao_Paulo", "UTF8"),
        ):
            with self.subTest(row=changed):
                cursor.fetchone.return_value = changed
                with self.assertRaises(RuntimeError):
                    verify_v10d_postgres_connection(connection)

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

    def fk_type_fixture(self):
        def column(name, data_type, column_type, precision, unsigned, auto_increment, nullable, pg_type):
            return {"name": name, "data_type": data_type, "column_type": column_type,
                    "precision": precision, "scale": 0, "unsigned": unsigned,
                    "auto_increment": auto_increment, "nullable": nullable, "pg_type": pg_type,
                    "default": None, "extra": "auto_increment" if auto_increment else "", "generated": False}

        columns = {
            "aplicativos_versoes": [column("aplicativo_id", "int", "int(11)", 10, False, False, False, "integer")],
            "aplicativos_catalogo": [column("id", "int", "int(11)", 10, False, True, False, "bigint")],
            "moradores_anexos": [column("morador_id", "int", "int(11)", 10, False, False, False, "integer")],
            "moradores": [column("id", "int", "int(11)", 10, False, True, False, "bigint")],
            "rbac_auditoria": [column("sessao_id", "bigint", "bigint(20) unsigned", 20, True, False, True, "numeric(20,0)")],
            "rbac_sessoes": [column("id", "bigint", "bigint(20) unsigned", 20, True, True, False, "bigint")],
        }
        fks = [
            {"table": "aplicativos_versoes", "columns": [{"column": "aplicativo_id", "ref_table": "aplicativos_catalogo", "ref_column": "id", "position": 1}]},
            {"table": "moradores_anexos", "columns": [{"column": "morador_id", "ref_table": "moradores", "ref_column": "id", "position": 1}]},
            {"table": "rbac_auditoria", "columns": [{"column": "sessao_id", "ref_table": "rbac_sessoes", "ref_column": "id", "position": 1}]},
        ]
        return columns, fks

    def test_fk_type_normalizations_are_exact_and_value_preserving(self):
        columns, fks = self.fk_type_fixture()
        with patch("catalog.query", return_value=[(791, 0, 0, 0, 0)]) as query_mock:
            records = normalize_fk_types(object(), columns, fks)
        self.assertEqual(len(records), 3)
        self.assertEqual({item["rule"] for item in records}, {
            "widen_signed_integer_foreign_key", "narrow_unsigned_bigint_with_verified_range",
        })
        self.assertTrue(all(item["target_pg_type"] == "bigint" for item in records))
        self.assertEqual(columns["aplicativos_versoes"][0]["pg_type"], "bigint")
        self.assertEqual(columns["moradores_anexos"][0]["pg_type"], "bigint")
        self.assertEqual(columns["rbac_auditoria"][0]["pg_type"], "bigint")
        self.assertEqual(query_mock.call_count, 1)
        self.assertIn("TRUNCATE", query_mock.call_args.args[1])

    def test_fk_type_normalization_rejects_metadata_drift(self):
        columns, fks = self.fk_type_fixture()
        columns["aplicativos_versoes"][0]["column_type"] = "int(10)"
        with patch("catalog.query") as query_mock:
            with self.assertRaisesRegex(RuntimeError, "metadata differs"):
                normalize_fk_types(object(), columns, fks)
        query_mock.assert_not_called()

    def test_fk_type_normalization_rejects_unsigned_bigint_out_of_range(self):
        columns, fks = self.fk_type_fixture()
        with patch("catalog.query", return_value=[(791, 0, 0, 0, 1)]):
            with self.assertRaisesRegex(RuntimeError, "exceed the approved signed bigint range"):
                normalize_fk_types(object(), columns, fks)

    def test_fk_type_normalization_rejects_unlisted_incompatibility(self):
        columns, fks = self.fk_type_fixture()
        columns["unlisted_child"] = [{"name": "parent_id", "data_type": "int", "column_type": "int(11)",
                                      "precision": 10, "scale": 0, "unsigned": False,
                                      "auto_increment": False, "nullable": False, "pg_type": "integer",
                                      "default": None, "extra": "", "generated": False}]
        columns["unlisted_parent"] = [{"name": "id", "data_type": "int", "column_type": "int(11)",
                                       "precision": 10, "scale": 0, "unsigned": False,
                                       "auto_increment": True, "nullable": False, "pg_type": "bigint",
                                       "default": None, "extra": "auto_increment", "generated": False}]
        fks.append({"table": "unlisted_child", "columns": [{"column": "parent_id", "ref_table": "unlisted_parent",
                                                               "ref_column": "id"}]})
        with patch("catalog.query", return_value=[(791, 0, 0, 0, 0)]):
            with self.assertRaisesRegex(RuntimeError, "unlisted foreign key type mismatch"):
                normalize_fk_types(object(), columns, fks)

    def test_reconciliation_checks_actual_postgres_fk_types(self):
        columns, fks = self.fk_type_fixture()
        with patch("catalog.query", return_value=[(791, 0, 0, 0, 0)]):
            normalize_fk_types(object(), columns, fks)
        pg = MagicMock()
        cursor = pg.cursor.return_value.__enter__.return_value
        cursor.fetchone.return_value = ("bigint", "bigint")
        verify_destination_fk_types(pg, fks[0], columns)
        self.assertEqual(cursor.execute.call_count, 1)

    def test_reconciliation_rejects_actual_postgres_fk_type_mismatch(self):
        columns, fks = self.fk_type_fixture()
        with patch("catalog.query", return_value=[(791, 0, 0, 0, 0)]):
            normalize_fk_types(object(), columns, fks)
        pg = MagicMock()
        pg.cursor.return_value.__enter__.return_value.fetchone.return_value = ("integer", "bigint")
        with self.assertRaisesRegex(RuntimeError, "column types differ"):
            verify_destination_fk_types(pg, fks[0], columns)

    def test_postgres_type_aliases_normalize_without_losing_typmod(self):
        self.assertEqual(normalize_postgres_type("character varying(128)"), "varchar(128)")
        self.assertEqual(normalize_postgres_type("character(12)"), "char(12)")
        self.assertEqual(normalize_postgres_type("character varying"), "varchar")
        self.assertIsNone(normalize_postgres_type(None))

    def test_fk_type_verification_accepts_equivalent_postgres_varchar_aliases(self):
        columns = {
            "child": [{"name": "external_key", "pg_type": "varchar(128)"}],
            "parent": [{"name": "external_key", "pg_type": "varchar(128)"}],
        }
        fk = {"table": "child", "columns": [{"column": "external_key", "ref_table": "parent",
                                               "ref_column": "external_key", "position": 1}]}
        pg = MagicMock()
        pg.cursor.return_value.__enter__.return_value.fetchone.return_value = (
            "character varying(128)", "character varying(128)")
        verify_destination_fk_types(pg, fk, columns)
        pg.cursor.return_value.__enter__.return_value.fetchone.return_value = (
            "character varying(129)", "character varying(128)")
        with self.assertRaisesRegex(RuntimeError, "column types differ"):
            verify_destination_fk_types(pg, fk, columns)

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
        grants = generated["006_grants.sql"]
        self.assertIn('ADD CONSTRAINT "usuarios_bkp_20261107_pkey" PRIMARY KEY ("id")', constraints)
        self.assertIn('CREATE UNIQUE INDEX "ux_usuarios_bkp_20261107_uq_email" ON "legado"."usuarios_bkp_20261107"', constraints)
        self.assertIn('CREATE INDEX "ix_usuarios_bkp_20261107_ix_observacao" ON "legado"."usuarios_bkp_20261107"', constraints)
        self.assertIn('GRANT CONNECT ON DATABASE "erp_ensaio_v10d" TO erp_app', grants)
        self.assertNotIn("erp_ensaio_v9", grants)
        with self.assertRaisesRegex(ValueError, "restricted to the exclusive v10d database"):
            build_sql(catalog, "erp_ensaio_v9")

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
                          {"name": "parent_id", "pg_type": "integer", "generated": False,
                           "nullable": True, "default": None, "data_type": "int",
                           "column_type": "int(11)", "extra": "", "unsigned": False,
                           "auto_increment": False, "collate": None}],
            },
            "indexes": {"parent": {"PRIMARY": [{"non_unique": False, "column": "id",
                                                    "sub_part": None, "direction": "A", "type": "BTREE"}]},
                        "child": {"PRIMARY": [{"non_unique": False, "column": "id",
                                                   "sub_part": None, "direction": "A", "type": "BTREE"}]}},
            "foreign_keys": [{"table": "child", "name": "fk_child_parent", "columns": [
                {"column": "parent_id", "ref_table": "parent", "ref_column": "id",
                 "on_update": "RESTRICT", "on_delete": "RESTRICT"}]}],
            "fk_type_normalizations": [{"table": "child", "column": "parent_id",
                                         "referenced_table": "parent", "referenced_column": "id",
                                         "source_pg_type": "integer", "referenced_pg_type": "bigint",
                                         "target_pg_type": "bigint", "rule": "widen_signed_integer_foreign_key"}],
            "zero_value_counts": {}, "invalid_enum_counts": {},
        }
        sql = build_sql(catalog)
        self.assertIn("NOT VALID", sql["002_foreign_keys.sql"])
        self.assertIn('ALTER TABLE "erp"."child" ALTER COLUMN "parent_id" TYPE bigint', sql["002_foreign_keys.sql"])
        self.assertIn('CHECK ("parent_id" BETWEEN -2147483648 AND 2147483647)', sql["002_foreign_keys.sql"])
        self.assertEqual(sql["002_foreign_keys.sql"].count("ALTER COLUMN"), 1)
        self.assertNotIn("BEGIN;", sql["002_foreign_keys.sql"])
        self.assertNotIn("COMMIT;", sql["002_foreign_keys.sql"])
        self.assertNotIn("VALIDATE CONSTRAINT", sql["002_foreign_keys.sql"])
        self.assertNotIn("FOREIGN KEY", sql["002_constraints_indexes.sql"])
        self.assertIn("VALIDATE CONSTRAINT", sql["002_validate_constraints.sql"])
        self.assertIn("CHECK (\"id\" >= 0)", sql["001_schema.sql"])
        self.assertIn("GENERATED BY DEFAULT AS IDENTITY", sql["001_schema.sql"])


if __name__ == "__main__":
    unittest.main()
