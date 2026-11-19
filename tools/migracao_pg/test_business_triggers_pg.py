#!/usr/bin/env python3
"""Teste transacional das sete triggers P2 no alvo descartável erp_pg_dev."""
from __future__ import annotations

import argparse
import re
import sys
import uuid
from datetime import datetime
from pathlib import Path

import psycopg
from psycopg import ClientCursor, sql

ROOT = Path(__file__).resolve().parents[2]
DDL_PATH = ROOT / "sql" / "postgresql" / "070_business_triggers_tenant_scoped.sql"
DSN = "dbname=erp_pg_dev host=/var/run/postgresql user=postgres"
TENANT_A = 2_100_000_000
TENANT_B = 2_100_000_001
BASE_ID = 2_100_000_000
SEQUENCES = (
    "acessos_visitantes_id_seq",
    "alertas_estoque_id_seq",
    "checklist_alertas_config_id_seq",
    "checklist_km_acumulado_id_seq",
    "checklist_veicular_id_seq",
    "dependentes_id_seq",
    "dispositivos_tablets_id_seq",
    "logs_validacoes_dispositivo_id_seq",
    "produtos_estoque_id_seq",
    "qrcode_tokens_id_seq",
    "veiculos_id_seq",
)
TRIGGERS = (
    ("checklist_veicular", "trg_atualizar_km_acumulado"),
    ("dependentes", "tr_dependente_ativar_veiculos"),
    ("dependentes", "tr_dependente_deletar_veiculos"),
    ("dependentes", "tr_dependente_inativar_veiculos"),
    ("logs_validacoes_dispositivo", "after_validacao_dispositivo_insert"),
    ("produtos_estoque", "trg_alerta_estoque_baixo"),
    ("qrcode_tokens", "after_qrcode_token_insert"),
)
FIXTURE_IDS = {
    "checklist_alertas_config": (BASE_ID + 1, BASE_ID + 2, BASE_ID + 20),
    "checklist_veicular": (BASE_ID + 3,),
    "dependentes": (BASE_ID + 4, BASE_ID + 7, BASE_ID + 10),
    "veiculos": (BASE_ID + 5, BASE_ID + 6, BASE_ID + 8, BASE_ID + 9, BASE_ID + 11, BASE_ID + 12),
    "dispositivos_tablets": (BASE_ID + 13,),
    "logs_validacoes_dispositivo": (BASE_ID + 14, BASE_ID + 15),
    "produtos_estoque": (BASE_ID + 16,),
    "acessos_visitantes": (BASE_ID + 17,),
    "qrcode_tokens": (BASE_ID + 18, BASE_ID + 19),
}
FIXTURE_TABLES = tuple(FIXTURE_IDS)


class ProbeFailure(Exception):
    pass


def assert_equal(actual, expected, label: str) -> None:
    if actual != expected:
        raise ProbeFailure(label)


def scalar(conn: psycopg.Connection, query: str, params: tuple = ()):
    return conn.execute(query, params).fetchone()[0]


def assert_fixture_range_empty(conn: psycopg.Connection) -> None:
    for table, ids in FIXTURE_IDS.items():
        query = sql.SQL("SELECT count(*) FROM erp.{} WHERE id = ANY(%s)").format(sql.Identifier(table))
        count = conn.execute(query, (list(ids),)).fetchone()[0]
        assert_equal(count, 0, "fixture_id_range_not_empty")
    product_id = FIXTURE_IDS["produtos_estoque"][0]
    alerts = scalar(conn, "SELECT count(*) FROM erp.alertas_estoque WHERE produto_id=%s", (product_id,))
    assert_equal(alerts, 0, "fixture_alert_rows_not_empty")
    for table in FIXTURE_TABLES:
        query = sql.SQL("SELECT count(*) FROM erp.{} WHERE tenant_id = ANY(%s)").format(sql.Identifier(table))
        count = conn.execute(query, ([TENANT_A, TENANT_B],)).fetchone()[0]
        assert_equal(count, 0, "synthetic_tenant_range_not_empty")


def insert_dependente(conn: psycopg.Connection, row_id: int, active: int) -> None:
    cpf = ("P2-" + uuid.uuid4().hex)[:14]
    conn.execute(
        """INSERT INTO erp.dependentes
           (id, tenant_id, morador_id, nome_completo, cpf, ativo)
           VALUES (%s, %s, 0, %s, %s, %s)""",
        (row_id, TENANT_A, "P2 Trigger Fixture " + uuid.uuid4().hex[:8], cpf, active),
    )


def insert_veiculo(conn: psycopg.Connection, row_id: int, tenant: int, dependent_id: int, active: int) -> None:
    marker = uuid.uuid4().hex[:10].upper()
    conn.execute(
        """INSERT INTO erp.veiculos
           (id, tenant_id, placa, modelo, tag, morador_id, dependente_id, ativo)
           VALUES (%s, %s, %s, %s, %s, 0, %s, %s)""",
        (row_id, tenant, marker, "P2 Trigger Fixture", marker, dependent_id, active),
    )


def test_km_tenant_scope_and_transitions(conn: psycopg.Connection) -> None:
    config_a, config_b, config_inactive = FIXTURE_IDS["checklist_alertas_config"]
    checklist_id = FIXTURE_IDS["checklist_veicular"][0]
    conn.execute(
        """INSERT INTO erp.checklist_alertas_config
           (id, tenant_id, categoria, nome_alerta, km_alerta, ativo)
           VALUES (%s, %s, 'fixture-a', 'P2 fixture A', 1, 1),
                  (%s, %s, 'fixture-b', 'P2 fixture B', 1, 1),
                  (%s, %s, 'fixture-off', 'P2 fixture inactive', 1, 0)""",
        (config_a, TENANT_A, config_b, TENANT_B, config_inactive, TENANT_A),
    )
    conn.execute(
        """INSERT INTO erp.checklist_veicular
           (id, tenant_id, veiculo_id, operador_id, km_inicial, data_hora_abertura, status)
           VALUES (%s, %s, 0, 0, 100, timestamp '2026-01-01 10:00:00', 'aberto')""",
        (checklist_id, TENANT_A),
    )
    conn.execute(
        "UPDATE erp.checklist_veicular SET status='fechado', km_final=150 WHERE id=%s",
        (checklist_id,),
    )
    first = conn.execute(
        """SELECT count(*), coalesce(sum(km_acumulado),0), count(DISTINCT tenant_id)
           FROM erp.checklist_km_acumulado WHERE ultimo_checklist_id=%s""",
        (checklist_id,),
    ).fetchone()
    assert_equal(first, (1, 50, 1), "km_first_close_or_tenant_scope")
    conn.execute("UPDATE erp.checklist_veicular SET km_final=160 WHERE id=%s", (checklist_id,))
    assert_equal(scalar(conn, "SELECT count(*) FROM erp.checklist_km_acumulado WHERE ultimo_checklist_id=%s", (checklist_id,)), 1, "km_closed_to_closed")
    conn.execute("UPDATE erp.checklist_veicular SET status='aberto' WHERE id=%s", (checklist_id,))
    conn.execute("UPDATE erp.checklist_veicular SET status='fechado', km_final=200 WHERE id=%s", (checklist_id,))
    second = conn.execute(
        """SELECT count(*), coalesce(sum(km_acumulado),0), count(DISTINCT tenant_id)
           FROM erp.checklist_km_acumulado WHERE ultimo_checklist_id=%s""",
        (checklist_id,),
    ).fetchone()
    assert_equal(second, (2, 150, 1), "km_reopen_close_and_tenant_scope")


def test_dependent_activation(conn: psycopg.Connection) -> None:
    dependent_id, _, _ = FIXTURE_IDS["dependentes"]
    own_vehicle, other_tenant_vehicle = FIXTURE_IDS["veiculos"][:2]
    insert_dependente(conn, dependent_id, 0)
    insert_veiculo(conn, own_vehicle, TENANT_A, dependent_id, 0)
    insert_veiculo(conn, other_tenant_vehicle, TENANT_B, dependent_id, 0)
    conn.execute("UPDATE erp.dependentes SET ativo=1 WHERE id=%s", (dependent_id,))
    states = conn.execute(
        "SELECT id, ativo FROM erp.veiculos WHERE id = ANY(%s) ORDER BY id",
        ([own_vehicle, other_tenant_vehicle],),
    ).fetchall()
    assert_equal(states, [(own_vehicle, 1), (other_tenant_vehicle, 0)], "dependent_activation_tenant_scope")


def test_dependent_deactivation(conn: psycopg.Connection) -> None:
    _, dependent_id, _ = FIXTURE_IDS["dependentes"]
    own_vehicle, other_tenant_vehicle = FIXTURE_IDS["veiculos"][2:4]
    insert_dependente(conn, dependent_id, 1)
    insert_veiculo(conn, own_vehicle, TENANT_A, dependent_id, 1)
    insert_veiculo(conn, other_tenant_vehicle, TENANT_B, dependent_id, 1)
    conn.execute("UPDATE erp.dependentes SET ativo=0 WHERE id=%s", (dependent_id,))
    states = conn.execute(
        "SELECT id, ativo FROM erp.veiculos WHERE id = ANY(%s) ORDER BY id",
        ([own_vehicle, other_tenant_vehicle],),
    ).fetchall()
    assert_equal(states, [(own_vehicle, 0), (other_tenant_vehicle, 1)], "dependent_deactivation_tenant_scope")


def test_dependent_delete(conn: psycopg.Connection) -> None:
    _, _, dependent_id = FIXTURE_IDS["dependentes"]
    own_vehicle, other_tenant_vehicle = FIXTURE_IDS["veiculos"][4:6]
    insert_dependente(conn, dependent_id, 1)
    insert_veiculo(conn, own_vehicle, TENANT_A, dependent_id, 1)
    insert_veiculo(conn, other_tenant_vehicle, TENANT_B, dependent_id, 1)
    conn.execute("DELETE FROM erp.dependentes WHERE id=%s", (dependent_id,))
    counts = conn.execute(
        """SELECT count(*) FILTER (WHERE tenant_id=%s), count(*) FILTER (WHERE tenant_id=%s)
           FROM erp.veiculos WHERE id = ANY(%s)""",
        (TENANT_A, TENANT_B, [own_vehicle, other_tenant_vehicle]),
    ).fetchone()
    assert_equal(counts, (0, 1), "dependent_delete_tenant_scope")


def test_device_validation(conn: psycopg.Connection) -> None:
    device_id = FIXTURE_IDS["dispositivos_tablets"][0]
    log_a, log_b = FIXTURE_IDS["logs_validacoes_dispositivo"]
    nonce = uuid.uuid4().hex
    conn.execute(
        """INSERT INTO erp.dispositivos_tablets
           (id, tenant_id, nome, token, secret, total_validacoes)
           VALUES (%s, %s, %s, %s, %s, 0)""",
        (device_id, TENANT_A, "P2 Fixture", nonce[:12], nonce[:32]),
    )
    expected_time = datetime(2026, 1, 1, 12, 0, 0)
    conn.execute(
        """INSERT INTO erp.logs_validacoes_dispositivo
           (id, tenant_id, dispositivo_id, resultado, validado_em)
           VALUES (%s, %s, %s, 'sucesso', %s::timestamp)""",
        (log_a, TENANT_A, device_id, expected_time),
    )
    conn.execute(
        """INSERT INTO erp.logs_validacoes_dispositivo
           (id, tenant_id, dispositivo_id, resultado, validado_em)
           VALUES (%s, %s, %s, 'falha', timestamp '2026-01-02 12:00:00')""",
        (log_b, TENANT_B, device_id),
    )
    state = conn.execute(
        "SELECT total_validacoes, ultimo_acesso FROM erp.dispositivos_tablets WHERE id=%s",
        (device_id,),
    ).fetchone()
    assert_equal(state, (1, expected_time), "device_validation_tenant_scope")


def test_stock_alert(conn: psycopg.Connection) -> None:
    product_id = FIXTURE_IDS["produtos_estoque"][0]
    conn.execute(
        """INSERT INTO erp.produtos_estoque
           (id, tenant_id, codigo, nome, unidade_medida, quantidade_estoque, estoque_minimo, ativo)
           VALUES (%s, %s, %s, %s, 'Unidade', 5.00, 5.00, 1)""",
        (product_id, TENANT_A, "P2-" + uuid.uuid4().hex[:12], "P2 Trigger Fixture",),
    )
    conn.execute("UPDATE erp.produtos_estoque SET quantidade_estoque=4 WHERE id=%s", (product_id,))
    conn.execute("UPDATE erp.produtos_estoque SET quantidade_estoque=0 WHERE id=%s", (product_id,))
    conn.execute("UPDATE erp.produtos_estoque SET quantidade_estoque=0 WHERE id=%s", (product_id,))
    result = conn.execute(
        """SELECT count(*), count(DISTINCT tenant_id),
                  count(*) FILTER (WHERE tipo_alerta='Estoque_Minimo'),
                  count(*) FILTER (WHERE tipo_alerta='Estoque_Zerado'),
                  bool_and(tipo_alerta<>'Estoque_Zerado' OR position('ZERADO' in mensagem)>0),
                  bool_and(tipo_alerta<>'Estoque_Minimo' OR position('abaixo do estoque mínimo' in mensagem)>0)
           FROM erp.alertas_estoque WHERE produto_id=%s""",
        (product_id,),
    ).fetchone()
    assert_equal(result, (3, 1, 1, 2, True, True), "stock_alert_tenant_and_repeat_semantics")
    kinds = scalar(conn, "SELECT count(DISTINCT tipo_alerta) FROM erp.alertas_estoque WHERE produto_id=%s", (product_id,))
    assert_equal(kinds, 2, "stock_alert_minimum_and_zero_branches")
    default_tenant = scalar(conn, "SELECT count(*) FROM erp.alertas_estoque WHERE produto_id=%s AND tenant_id=1", (product_id,))
    assert_equal(default_tenant, 0, "stock_alert_default_tenant_not_used")


def test_qrcode_tenant_scope(conn: psycopg.Connection) -> None:
    access_id = FIXTURE_IDS["acessos_visitantes"][0]
    token_a, token_b = FIXTURE_IDS["qrcode_tokens"]
    first_token = uuid.uuid4().hex
    second_token = uuid.uuid4().hex
    conn.execute(
        """INSERT INTO erp.acessos_visitantes
           (id, tenant_id, visitante_id, data_inicial, data_final, dias_permanencia,
            tipo_acesso, qr_code)
           VALUES (%s, %s, 0, date '2026-01-01', date '2026-01-01', 1, 'portaria', %s)""",
        (access_id, TENANT_A, uuid.uuid4().hex),
    )
    conn.execute(
        """INSERT INTO erp.qrcode_tokens
           (id, tenant_id, acesso_id, token, expira_em)
           VALUES (%s, %s, %s, %s, timestamp '2026-01-02 00:00:00')""",
        (token_a, TENANT_A, access_id, first_token),
    )
    conn.execute(
        """INSERT INTO erp.qrcode_tokens
           (id, tenant_id, acesso_id, token, expira_em)
           VALUES (%s, %s, %s, %s, timestamp '2026-01-02 00:00:00')""",
        (token_b, TENANT_B, access_id, second_token),
    )
    result = conn.execute(
        "SELECT tenant_id, token_atual FROM erp.acessos_visitantes WHERE id=%s",
        (access_id,),
    ).fetchone()
    assert_equal(result, (TENANT_A, first_token), "qrcode_tenant_scope")


def capture_sequences(conn: psycopg.Connection) -> dict[str, tuple[int, bool]]:
    result = {}
    for seq_name in SEQUENCES:
        value = conn.execute(
            sql.SQL("SELECT last_value, is_called FROM erp.{}").format(sql.Identifier(seq_name))
        ).fetchone()
        result[seq_name] = (int(value[0]), bool(value[1]))
    return result


def restore_sequences(conn: psycopg.Connection, states: dict[str, tuple[int, bool]]) -> None:
    for seq_name, (last_value, is_called) in states.items():
        if not re.fullmatch(r"[a-z0-9_]+_id_seq", seq_name):
            raise ProbeFailure("unexpected_sequence_identifier")
        conn.execute("SELECT pg_catalog.setval(%s::regclass, %s, %s)", (f"erp.{seq_name}", last_value, is_called))


def sequence_states_equal(conn: psycopg.Connection, states: dict[str, tuple[int, bool]]) -> bool:
    return capture_sequences(conn) == states


def main() -> int:
    parser = argparse.ArgumentParser(description="Teste transacional das sete triggers P2 em erp_pg_dev")
    parser.add_argument("--installed", action="store_true", help="validar triggers existentes sem executar DDL")
    args = parser.parse_args()
    if not DDL_PATH.is_file():
        print("HARNESS=FAIL; reason=ddl_file_missing")
        return 2
    ddl = DDL_PATH.read_text(encoding="utf-8")
    if len(re.findall(r"\bCREATE\s+TRIGGER\b", ddl, flags=re.IGNORECASE)) != 7:
        print("HARNESS=FAIL; reason=trigger_count_not_seven")
        return 2

    outcomes: list[tuple[str, bool, str | None]] = []
    states: dict[str, tuple[int, bool]] = {}
    began = False
    cleanup_error: str | None = None
    conn: psycopg.Connection | None = None
    try:
        conn = psycopg.connect(DSN, autocommit=True)
        dbname, username, in_recovery = conn.execute(
                "SELECT current_database(), current_user, pg_is_in_recovery()"
        ).fetchone()
        if (dbname, username, in_recovery) != ("erp_pg_dev", "postgres", False):
            raise ProbeFailure("target_identity_mismatch")
        active = scalar(
                conn,
                "SELECT count(*) FROM pg_stat_activity WHERE datname='erp_pg_dev' AND pid<>pg_backend_pid()",
        )
        if active:
            raise ProbeFailure("concurrent_target_connections")
        installed = conn.execute(
                """SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
                   JOIN pg_namespace n ON n.oid=c.relnamespace
                   WHERE NOT t.tgisinternal AND n.nspname='erp' AND t.tgname = ANY(%s)""",
                ([name for _, name in TRIGGERS],),
        ).fetchone()[0]
        if args.installed and installed != 7:
            raise ProbeFailure("business_trigger_definition_count_mismatch")
        if not args.installed and installed:
            raise ProbeFailure("business_trigger_name_already_exists")
        assert_fixture_range_empty(conn)
        states = capture_sequences(conn)
        conn.execute("BEGIN")
        began = True
        if args.installed:
            enabled = scalar(
                conn,
                """SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
                   JOIN pg_namespace n ON n.oid=c.relnamespace
                   WHERE NOT t.tgisinternal AND n.nspname='erp' AND t.tgenabled='O'
                     AND t.tgname = ANY(%s)""",
                ([name for _, name in TRIGGERS],),
            )
            assert_equal(enabled, 7, "all_seven_installed_triggers_enabled")
        else:
            body = re.sub(r"\A\s*BEGIN\s*;", "", ddl, count=1, flags=re.IGNORECASE)
            body = re.sub(r"COMMIT\s*;\s*\Z", "", body, count=1, flags=re.IGNORECASE)
            ClientCursor(conn).execute(body)
            trigger_count = scalar(
                    conn,
                    """SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
                       JOIN pg_namespace n ON n.oid=c.relnamespace
                       WHERE NOT t.tgisinternal AND n.nspname='erp' AND t.tgname = ANY(%s)""",
                    ([name for _, name in TRIGGERS],),
            )
            assert_equal(trigger_count, 7, "all_seven_trigger_definitions_created_in_test_transaction")
        outcomes.append(("all_seven_trigger_definitions", True, None))

        cases = (
                ("km_tenant_scope_transitions", test_km_tenant_scope_and_transitions),
                ("dependent_activation_tenant_scope", test_dependent_activation),
                ("dependent_deactivation_tenant_scope", test_dependent_deactivation),
                ("dependent_delete_tenant_scope", test_dependent_delete),
                ("device_validation_tenant_scope", test_device_validation),
                ("stock_alert_tenant_and_repeat_semantics", test_stock_alert),
                ("qrcode_tenant_scope", test_qrcode_tenant_scope),
        )
        for index, (label, test) in enumerate(cases, start=1):
            savepoint = f"p2_trigger_case_{index}"
            conn.execute(sql.SQL("SAVEPOINT {}").format(sql.Identifier(savepoint)))
            try:
                test(conn)
            except Exception as exc:  # sanitized: no query/row/identifier values leave the process
                conn.execute(sql.SQL("ROLLBACK TO SAVEPOINT {}").format(sql.Identifier(savepoint)))
                conn.execute(sql.SQL("RELEASE SAVEPOINT {}").format(sql.Identifier(savepoint)))
                code = getattr(exc, "sqlstate", None)
                constraint = getattr(getattr(exc, "diag", None), "constraint_name", None)
                detail = type(exc).__name__ + (":" + str(code) if code else "")
                if constraint and re.fullmatch(r"[A-Za-z0-9_]+", constraint):
                    detail += ";constraint=" + constraint
                outcomes.append((label, False, detail))
            else:
                conn.execute(sql.SQL("RELEASE SAVEPOINT {}").format(sql.Identifier(savepoint)))
                outcomes.append((label, True, None))

        conn.execute("ROLLBACK")
        began = False
        assert_fixture_range_empty(conn)
        remaining_triggers = scalar(
                conn,
                """SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
                   JOIN pg_namespace n ON n.oid=c.relnamespace
                   WHERE NOT t.tgisinternal AND n.nspname='erp' AND t.tgname = ANY(%s)""",
                ([name for _, name in TRIGGERS],),
        )
        assert_equal(remaining_triggers, 7 if args.installed else 0, "trigger_state_preserved_after_rollback")
        restore_sequences(conn, states)
        assert_equal(sequence_states_equal(conn, states), True, "all_sequences_restored")
        outcomes.append(("rollback_no_fixture_or_trigger_state_changed", True, None))
        outcomes.append(("all_eleven_sequences_restored", True, None))
    except Exception as exc:
        if conn is not None and not conn.closed and began:
            try:
                conn.execute("ROLLBACK")
                began = False
            except Exception:
                cleanup_error = "rollback_failed"
        if conn is not None and not conn.closed and states:
            try:
                restore_sequences(conn, states)
            except Exception:
                cleanup_error = "sequence_restore_failed"
        code = getattr(exc, "sqlstate", None)
        print("HARNESS=FAIL; class=" + type(exc).__name__ + ("; sqlstate=" + str(code) if code else ""))
        if cleanup_error:
            print("CLEANUP=FAIL; reason=" + cleanup_error)
        if conn is not None and not conn.closed:
            conn.close()
        return 1

    if conn is not None and not conn.closed:
        conn.close()
    failed = 0
    for label, passed, detail in outcomes:
        print(f"CASE={label}; status={'PASS' if passed else 'FAIL'}" + (f"; detail={detail}" if detail else ""))
        failed += 0 if passed else 1
    mode = "installed" if args.installed else "transactional_ddl"
    print(f"SUMMARY=cases:{len(outcomes)};passed:{len(outcomes)-failed};failed:{failed};mode:{mode};target:erp_pg_dev")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
