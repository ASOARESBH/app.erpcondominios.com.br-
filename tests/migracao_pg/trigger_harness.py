from __future__ import annotations

import argparse
import json
import re
import secrets
import signal
import sys
from datetime import date, datetime, time
from decimal import Decimal

import psycopg
from psycopg import sql

from catalog import (classify_legacy_tables, columns_inventory, indexes_inventory,
                     mysql_connection, table_inventory)
from common import verify_v10d_postgres_connection, require_v10d_endpoint
NUMERIC = {"tinyint", "smallint", "mediumint", "int", "integer", "bigint", "decimal", "float", "double"}
TEXT = {"char", "varchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set"}
BINARY = {"tinyblob", "blob", "mediumblob", "longblob", "binary", "varbinary"}
FIXTURE_TENANT = -(1_000_000_000 + secrets.randbelow(1_000_000_000))
FIXTURE_OTHER_TENANT = FIXTURE_TENANT + 1
FIXTURE_MORADOR = -(1_000_000_000 + secrets.randbelow(1_000_000_000))
FIXTURE_VISITANTE = -(1_000_000_000 + secrets.randbelow(1_000_000_000))
FIXTURE_VEICULO = -(1_000_000_000 + secrets.randbelow(1_000_000_000))
FIXTURE_OPERADOR = -(1_000_000_000 + secrets.randbelow(1_000_000_000))
ACTIVE_TEST = "harness_preflight"
SAFE_TEST_LABELS = {
    "harness_preflight", "on_update_timestamp_semantics", "after_qrcode_token_insert",
    "after_validacao_dispositivo_insert", "trg_alerta_estoque_baixo", "trg_atualizar_km_acumulado",
    "tr_dependente_ativar_veiculos", "tr_dependente_inativar_veiculos", "tr_dependente_deletar_veiculos",
    "corrigir_moradores_anexos_tenant", "mt_adicionar_coluna",
}


def trigger_failure_marker(test_label, exc):
    if test_label not in SAFE_TEST_LABELS:
        test_label = "harness_preflight"
    sqlstate = getattr(exc, "sqlstate", None)
    if not isinstance(sqlstate, str) or not re.fullmatch(r"[0-9A-Z]{5}", sqlstate):
        sqlstate = "none"
    return f"TRIGGER_TEST_FAILED type={type(exc).__name__} code={test_label} sqlstate={sqlstate}"


def fixture_token():
    return "v10dqa_" + secrets.token_hex(8)


def reset_fixture_sequence(cur, sequence_name, value):
    cur.execute("RESET ROLE")
    try:
        cur.execute("SELECT setval(%s::regclass,%s::bigint,false)", (sequence_name, value))
    finally:
        cur.execute("SET LOCAL ROLE erp_app")


def capture_fixture_sequences(cur):
    cur.execute("SELECT n.nspname,c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace "
                "WHERE c.relkind='S' AND n.nspname IN ('erp','legado') ORDER BY n.nspname,c.relname")
    states = []
    for schema, name in cur.fetchall():
        cur.execute(sql.SQL("SELECT last_value,is_called FROM {}.{}").format(
            sql.Identifier(schema), sql.Identifier(name)))
        row = cur.fetchone()
        if row is None or len(row) != 2:
            raise RuntimeError("identity sequence state is unavailable before synthetic trigger tests")
        states.append((schema, name, row[0], row[1]))
    return states


def prepare_fixture_sequences(cur, catalog):
    prepared = 0
    for table, columns in catalog["columns"].items():
        schema = catalog["tables"][table]["schema"]
        for column in columns:
            if not column.get("auto_increment"):
                continue
            name = column["name"]
            cur.execute("SELECT pg_get_serial_sequence(format('%%I.%%I', %s::text, %s::text), %s::text)",
                        (schema, table, name))
            row = cur.fetchone()
            sequence_name = row[0] if row else None
            if not sequence_name:
                raise RuntimeError("identity sequence is missing before synthetic trigger tests")
            cur.execute(sql.SQL("SELECT COALESCE(MAX({}),0) FROM {}.{}").format(
                sql.Identifier(name), sql.Identifier(schema), sql.Identifier(table)))
            maximum = int(cur.fetchone()[0] or 0)
            sequence_value, is_called = (maximum, True) if maximum > 0 else (1, False)
            cur.execute("SELECT setval(%s::regclass,%s::bigint,%s::boolean)",
                        (sequence_name, sequence_value, is_called))
            prepared += 1
    return prepared


def restore_fixture_sequences(cur, sequence_states):
    for schema, name, last_value, is_called in sequence_states:
        cur.execute("SELECT setval(format('%%I.%%I', %s::text, %s::text)::regclass,"
                    "%s::bigint,%s::boolean)", (schema, name, last_value, is_called))


def next_pk(cur, table, cols, indexes):
    pk = indexes.get(table, {}).get("PRIMARY", [])
    values = {}
    for part in pk:
        name = part["column"]
        col = next(c for c in cols[table] if c["name"] == name)
        cur.execute(sql.SQL("SELECT COALESCE(MAX({}),0)+1 FROM erp.{}").format(
            sql.Identifier(name), sql.Identifier(table)))
        values[name] = cur.fetchone()[0]
    return values


def make_value(col, token):
    typ = col["data_type"]
    if typ in {"enum", "set"}:
        return col["domain_values"][0] if col["domain_values"] else ""
    if typ in NUMERIC:
        if typ == "decimal":
            return Decimal("1")
        return 1
    if typ == "date":
        return date(2026, 1, 2)
    if typ in {"datetime", "timestamp"}:
        return datetime(2026, 1, 2, 3, 4, 5)
    if typ == "time":
        return time(3, 4, 5)
    if typ == "json":
        return "{}"
    if typ in BINARY:
        return b""
    if typ == "char":
        return "Q"
    if typ == "varchar":
        maxlen = col.get("char_length")
        return token[:maxlen] if maxlen else token
    if typ in TEXT:
        return token
    raise ValueError("unsupported synthetic fixture type")


def insert_fixture(cur, catalog, table, overrides):
    cols = catalog["columns"][table]
    pk_values = next_pk(cur, table, catalog["columns"], catalog["indexes"])
    values = {}
    token = fixture_token()
    for col in cols:
        name = col["name"]
        if col["generated"]:
            continue
        if name in overrides:
            values[name] = overrides[name]
        elif name in pk_values:
            values[name] = pk_values[name]
        elif col["auto_increment"]:
            continue
        elif col["default"] is not None or col["nullable"]:
            continue
        else:
            values[name] = make_value(col, token)
    missing = [c["name"] for c in cols if not c["generated"] and not c["nullable"]
               and c["default"] is None and not c["auto_increment"] and c["name"] not in values]
    if missing:
        raise ValueError("required fixture values are missing")
    names = list(values)
    statement = sql.SQL("INSERT INTO erp.{} ({}) VALUES ({}) RETURNING *").format(
        sql.Identifier(table),
        sql.SQL(", ").join(sql.Identifier(n) for n in names),
        sql.SQL(", ").join(sql.Placeholder() for _ in names),
    )
    cur.execute(statement, tuple(values[n] for n in names))
    row = cur.fetchone()
    description = [d.name for d in cur.description]
    return {name: row[i] for i, name in enumerate(description)}


def assert_equal(actual, expected):
    if actual != expected:
        raise AssertionError("synthetic assertion failed")


def test_qr(cur, catalog):
    access = insert_fixture(cur, catalog, "acessos_visitantes", {
        "tenant_id": FIXTURE_TENANT, "visitante_id": FIXTURE_VISITANTE, "morador_id": FIXTURE_MORADOR,
    })
    other_access = insert_fixture(cur, catalog, "acessos_visitantes", {
        "tenant_id": FIXTURE_OTHER_TENANT, "visitante_id": FIXTURE_VISITANTE, "morador_id": FIXTURE_MORADOR,
    })
    token = fixture_token()
    insert_fixture(cur, catalog, "qrcode_tokens", {
        "tenant_id": FIXTURE_TENANT, "acesso_id": access["id"], "token": token,
    })
    insert_fixture(cur, catalog, "qrcode_tokens", {
        "tenant_id": FIXTURE_TENANT, "acesso_id": other_access["id"], "token": fixture_token(),
    })
    cur.execute("SELECT token_atual FROM erp.acessos_visitantes WHERE id=%s AND tenant_id=%s",
                (access["id"], FIXTURE_TENANT))
    assert_equal(cur.fetchone()[0], token)
    cur.execute("SELECT token_atual FROM erp.acessos_visitantes WHERE id=%s AND tenant_id=%s",
                (other_access["id"], FIXTURE_OTHER_TENANT))
    assert_equal(cur.fetchone()[0], None)


def test_on_update_timestamp(cur, catalog):
    old_stamp = datetime(2000, 1, 1, 0, 0, 0)
    access = insert_fixture(cur, catalog, "acessos_visitantes", {
        "tenant_id": FIXTURE_TENANT, "visitante_id": FIXTURE_VISITANTE,
        "morador_id": FIXTURE_MORADOR, "data_atualizacao": old_stamp, "ativo": 0,
    })
    cur.execute("UPDATE erp.acessos_visitantes SET ativo=1 WHERE id=%s AND tenant_id=%s",
                (access["id"], FIXTURE_TENANT))
    cur.execute("SELECT data_atualizacao FROM erp.acessos_visitantes WHERE id=%s AND tenant_id=%s",
                (access["id"], FIXTURE_TENANT))
    changed_stamp = cur.fetchone()[0]
    if changed_stamp <= old_stamp:
        raise AssertionError("ON UPDATE timestamp did not advance after a real column change")
    cur.execute("UPDATE erp.acessos_visitantes SET ativo=1 WHERE id=%s AND tenant_id=%s",
                (access["id"], FIXTURE_TENANT))
    cur.execute("SELECT data_atualizacao FROM erp.acessos_visitantes WHERE id=%s AND tenant_id=%s",
                (access["id"], FIXTURE_TENANT))
    assert_equal(cur.fetchone()[0], changed_stamp)
    explicit_stamp = datetime(2024, 12, 31, 23, 59, 59)
    cur.execute("UPDATE erp.acessos_visitantes SET ativo=0,data_atualizacao=%s WHERE id=%s AND tenant_id=%s",
                (explicit_stamp, access["id"], FIXTURE_TENANT))
    cur.execute("SELECT data_atualizacao FROM erp.acessos_visitantes WHERE id=%s AND tenant_id=%s",
                (access["id"], FIXTURE_TENANT))
    assert_equal(cur.fetchone()[0], explicit_stamp)


def test_device(cur, catalog):
    device = insert_fixture(cur, catalog, "dispositivos_tablets", {"tenant_id": FIXTURE_TENANT, "total_validacoes": 0})
    other_device = insert_fixture(cur, catalog, "dispositivos_tablets", {"tenant_id": FIXTURE_OTHER_TENANT, "total_validacoes": 0})
    stamp = datetime(2026, 1, 2, 3, 4, 5)
    insert_fixture(cur, catalog, "logs_validacoes_dispositivo", {
        "tenant_id": FIXTURE_TENANT, "dispositivo_id": device["id"], "validado_em": stamp,
    })
    cur.execute("SELECT total_validacoes,ultimo_acesso FROM erp.dispositivos_tablets WHERE id=%s AND tenant_id=%s",
                (device["id"], FIXTURE_TENANT))
    total, last = cur.fetchone()
    assert_equal(int(total), 1)
    assert_equal(last, stamp)
    insert_fixture(cur, catalog, "logs_validacoes_dispositivo", {
        "tenant_id": FIXTURE_TENANT, "dispositivo_id": other_device["id"], "validado_em": stamp,
    })
    cur.execute("SELECT total_validacoes FROM erp.dispositivos_tablets WHERE id=%s AND tenant_id=%s",
                (other_device["id"], FIXTURE_OTHER_TENANT))
    assert_equal(int(cur.fetchone()[0]), 0)


def test_stock(cur, catalog):
    product = insert_fixture(cur, catalog, "produtos_estoque", {
        "tenant_id": FIXTURE_TENANT, "codigo": fixture_token(), "nome": fixture_token(),
        "unidade_medida": next(c for c in catalog["columns"]["produtos_estoque"]
                               if c["name"] == "unidade_medida")["domain_values"][0],
        "quantidade_estoque": Decimal("0"), "estoque_minimo": Decimal("1"), "ativo": 1,
    })
    cur.execute("UPDATE erp.produtos_estoque SET observacoes=%s WHERE id=%s AND tenant_id=%s",
                (fixture_token(), product["id"], FIXTURE_TENANT))
    cur.execute("SELECT COUNT(*) FILTER (WHERE tipo_alerta='Estoque_Zerado'), "
                "COUNT(*) FILTER (WHERE tipo_alerta='Estoque_Minimo'), "
                "COUNT(*) FILTER (WHERE mensagem LIKE %s AND mensagem LIKE '%%ZERADO%%') "
                "FROM erp.alertas_estoque WHERE tenant_id=%s AND produto_id=%s",
                (f"%{product['nome']}%", FIXTURE_TENANT, product["id"]))
    assert_equal(cur.fetchone(), (1, 0, 1))
    cur.execute("SELECT COUNT(*) FROM erp.alertas_estoque WHERE tenant_id=%s AND produto_id=%s",
                (FIXTURE_TENANT, product["id"]))
    assert_equal(cur.fetchone()[0], 1)
    cur.execute("UPDATE erp.produtos_estoque SET quantidade_estoque=5 WHERE id=%s AND tenant_id=%s",
                (product["id"], FIXTURE_TENANT))
    cur.execute("SELECT COUNT(*) FROM erp.alertas_estoque WHERE tenant_id=%s AND produto_id=%s",
                (FIXTURE_TENANT, product["id"]))
    assert_equal(cur.fetchone()[0], 1)
    cur.execute("UPDATE erp.produtos_estoque SET quantidade_estoque=0 WHERE id=%s AND tenant_id=%s",
                (product["id"], FIXTURE_TENANT))
    cur.execute("UPDATE erp.produtos_estoque SET quantidade_estoque=0 WHERE id=%s AND tenant_id=%s",
                (product["id"], FIXTURE_TENANT))
    cur.execute("SELECT COUNT(*) FROM erp.alertas_estoque WHERE tenant_id=%s AND produto_id=%s",
                (FIXTURE_TENANT, product["id"]))
    assert_equal(cur.fetchone()[0], 3)
    cur.execute("UPDATE erp.produtos_estoque SET ativo=0 WHERE id=%s AND tenant_id=%s",
                (product["id"], FIXTURE_TENANT))
    cur.execute("SELECT COUNT(*) FROM erp.alertas_estoque WHERE tenant_id=%s AND produto_id=%s",
                (FIXTURE_TENANT, product["id"]))
    assert_equal(cur.fetchone()[0], 3)
    minimum_product = insert_fixture(cur, catalog, "produtos_estoque", {
        "tenant_id": FIXTURE_TENANT, "codigo": fixture_token(), "nome": fixture_token(),
        "unidade_medida": product["unidade_medida"], "quantidade_estoque": Decimal("0.50"),
        "estoque_minimo": Decimal("1.00"), "ativo": 1,
    })
    cur.execute("UPDATE erp.produtos_estoque SET observacoes=%s WHERE id=%s AND tenant_id=%s",
                (fixture_token(), minimum_product["id"], FIXTURE_TENANT))
    cur.execute("SELECT COUNT(*) FILTER (WHERE tipo_alerta='Estoque_Minimo'), "
                "COUNT(*) FILTER (WHERE mensagem LIKE %s AND mensagem LIKE '%%abaixo do estoque mínimo%%') "
                "FROM erp.alertas_estoque WHERE tenant_id=%s AND produto_id=%s",
                (f"%{minimum_product['nome']}%", FIXTURE_TENANT, minimum_product["id"]))
    assert_equal(cur.fetchone(), (1, 1))


def test_checklist(cur, catalog):
    category = fixture_token()
    config = insert_fixture(cur, catalog, "checklist_alertas_config", {
        "tenant_id": FIXTURE_TENANT, "categoria": category, "ativo": 1,
    })
    insert_fixture(cur, catalog, "checklist_alertas_config", {
        "tenant_id": FIXTURE_OTHER_TENANT, "categoria": category, "ativo": 1,
    })
    checklist = insert_fixture(cur, catalog, "checklist_veicular", {
        "tenant_id": FIXTURE_TENANT, "status": "aberto", "veiculo_id": FIXTURE_VEICULO, "operador_id": FIXTURE_OPERADOR,
        "km_inicial": 100,
    })
    cur.execute("UPDATE erp.checklist_veicular SET status='fechado',km_final=120 WHERE id=%s AND tenant_id=%s",
                (checklist["id"], FIXTURE_TENANT))
    second = insert_fixture(cur, catalog, "checklist_veicular", {
        "tenant_id": FIXTURE_TENANT, "status": "aberto", "veiculo_id": FIXTURE_VEICULO,
        "operador_id": FIXTURE_OPERADOR, "km_inicial": 120,
    })
    cur.execute("UPDATE erp.checklist_veicular SET status='fechado',km_final=150 WHERE id=%s AND tenant_id=%s",
                (second["id"], FIXTURE_TENANT))
    cur.execute("SELECT COUNT(*),COALESCE(SUM(km_acumulado),0) FROM erp.checklist_km_acumulado WHERE tenant_id=%s AND veiculo_id=%s AND categoria=%s",
                (FIXTURE_TENANT, checklist["veiculo_id"], config["categoria"]))
    count, distance = cur.fetchone()
    assert_equal(count, 2)
    assert_equal(int(distance), 50)
    seed = insert_fixture(cur, catalog, "checklist_km_acumulado", {
        "tenant_id": FIXTURE_TENANT, "veiculo_id": FIXTURE_VEICULO,
        "categoria": category, "km_acumulado": 5, "ultimo_checklist_id": second["id"],
    })
    cur.execute("SELECT pg_get_serial_sequence('erp.checklist_km_acumulado','id')")
    sequence_name = cur.fetchone()[0]
    if not sequence_name:
        raise RuntimeError("checklist identity sequence is missing")
    reset_fixture_sequence(cur, sequence_name, seed["id"])
    collision = insert_fixture(cur, catalog, "checklist_veicular", {
        "tenant_id": FIXTURE_TENANT, "status": "aberto", "veiculo_id": FIXTURE_VEICULO,
        "operador_id": FIXTURE_OPERADOR, "km_inicial": 150,
    })
    cur.execute("UPDATE erp.checklist_veicular SET status='fechado',km_final=170 WHERE id=%s AND tenant_id=%s",
                (collision["id"], FIXTURE_TENANT))
    cur.execute("SELECT COUNT(*),COALESCE(SUM(km_acumulado),0),MAX(km_acumulado) "
                "FROM erp.checklist_km_acumulado WHERE tenant_id=%s AND veiculo_id=%s AND categoria=%s",
                (FIXTURE_TENANT, FIXTURE_VEICULO, category))
    assert_equal(cur.fetchone(), (3, 75, 30))
    cur.execute("SELECT km_acumulado,ultimo_checklist_id FROM erp.checklist_km_acumulado WHERE id=%s AND tenant_id=%s",
                (seed["id"], FIXTURE_TENANT))
    assert_equal(cur.fetchone(), (25, collision["id"]))
    cur.execute("SELECT COUNT(*) FROM erp.checklist_km_acumulado WHERE tenant_id=%s AND categoria=%s",
                (FIXTURE_TENANT, category))
    assert_equal(cur.fetchone()[0], 3)
    cur.execute("SELECT COUNT(*) FROM erp.checklist_km_acumulado WHERE tenant_id=%s AND categoria=%s",
                (FIXTURE_OTHER_TENANT, category))
    assert_equal(cur.fetchone()[0], 0)


def test_dependent_activation(cur, catalog):
    dependent = insert_fixture(cur, catalog, "dependentes", {"tenant_id": FIXTURE_TENANT, "morador_id": FIXTURE_MORADOR, "ativo": 0})
    vehicle = insert_fixture(cur, catalog, "veiculos", {
        "tenant_id": FIXTURE_TENANT, "dependente_id": dependent["id"], "morador_id": FIXTURE_MORADOR, "ativo": 0,
    })
    other_vehicle = insert_fixture(cur, catalog, "veiculos", {
        "tenant_id": FIXTURE_OTHER_TENANT, "dependente_id": dependent["id"], "morador_id": FIXTURE_MORADOR, "ativo": 0,
    })
    cur.execute("UPDATE erp.dependentes SET ativo=1 WHERE id=%s AND tenant_id=%s", (dependent["id"], FIXTURE_TENANT))
    cur.execute("SELECT ativo FROM erp.veiculos WHERE id=%s AND tenant_id=%s", (vehicle["id"], FIXTURE_TENANT))
    assert_equal(int(cur.fetchone()[0]), 1)
    cur.execute("SELECT ativo FROM erp.veiculos WHERE id=%s AND tenant_id=%s", (other_vehicle["id"], FIXTURE_OTHER_TENANT))
    assert_equal(int(cur.fetchone()[0]), 0)


def test_dependent_deactivation(cur, catalog):
    dependent = insert_fixture(cur, catalog, "dependentes", {"tenant_id": FIXTURE_TENANT, "morador_id": FIXTURE_MORADOR, "ativo": 1})
    vehicle = insert_fixture(cur, catalog, "veiculos", {
        "tenant_id": FIXTURE_TENANT, "dependente_id": dependent["id"], "morador_id": FIXTURE_MORADOR, "ativo": 1,
    })
    other_vehicle = insert_fixture(cur, catalog, "veiculos", {
        "tenant_id": FIXTURE_OTHER_TENANT, "dependente_id": dependent["id"], "morador_id": FIXTURE_MORADOR, "ativo": 1,
    })
    cur.execute("UPDATE erp.dependentes SET ativo=0 WHERE id=%s AND tenant_id=%s", (dependent["id"], FIXTURE_TENANT))
    cur.execute("SELECT ativo FROM erp.veiculos WHERE id=%s AND tenant_id=%s", (vehicle["id"], FIXTURE_TENANT))
    assert_equal(int(cur.fetchone()[0]), 0)
    cur.execute("SELECT ativo FROM erp.veiculos WHERE id=%s AND tenant_id=%s", (other_vehicle["id"], FIXTURE_OTHER_TENANT))
    assert_equal(int(cur.fetchone()[0]), 1)


def test_dependent_delete(cur, catalog):
    dependent = insert_fixture(cur, catalog, "dependentes", {"tenant_id": FIXTURE_TENANT, "morador_id": FIXTURE_MORADOR, "ativo": 1})
    vehicle = insert_fixture(cur, catalog, "veiculos", {
        "tenant_id": FIXTURE_TENANT, "dependente_id": dependent["id"], "morador_id": FIXTURE_MORADOR, "ativo": 1,
    })
    other_vehicle = insert_fixture(cur, catalog, "veiculos", {
        "tenant_id": FIXTURE_OTHER_TENANT, "dependente_id": dependent["id"], "morador_id": FIXTURE_MORADOR, "ativo": 1,
    })
    cur.execute("DELETE FROM erp.dependentes WHERE id=%s AND tenant_id=%s", (dependent["id"], FIXTURE_TENANT))
    cur.execute("SELECT COUNT(*) FROM erp.veiculos WHERE id=%s AND tenant_id=%s", (vehicle["id"], FIXTURE_TENANT))
    assert_equal(cur.fetchone()[0], 0)
    cur.execute("SELECT COUNT(*) FROM erp.veiculos WHERE id=%s AND tenant_id=%s", (other_vehicle["id"], FIXTURE_OTHER_TENANT))
    assert_equal(cur.fetchone()[0], 1)


def test_correct_moradores_anexos_tenant(cur, catalog):
    cur.execute("SELECT to_regclass('erp._v10d_moradores_anexos_original')")
    if cur.fetchone()[0] is not None:
        raise RuntimeError("procedure fixture relation already exists")
    cur.execute("ALTER TABLE erp.moradores_anexos RENAME TO _v10d_moradores_anexos_original")
    cur.execute("CREATE TABLE erp.moradores_anexos (id integer PRIMARY KEY)")
    cur.execute("INSERT INTO erp.moradores_anexos (id) VALUES (%s)", (FIXTURE_MORADOR,))
    cur.execute("CALL legado.corrigir_moradores_anexos_tenant()")
    cur.execute("SELECT data_type,is_nullable,column_default FROM information_schema.columns "
                "WHERE table_schema='erp' AND table_name='moradores_anexos' AND column_name='tenant_id'")
    assert_equal(cur.fetchone(), ("integer", "NO", "1"))
    cur.execute("SELECT tenant_id FROM erp.moradores_anexos WHERE id=%s", (FIXTURE_MORADOR,))
    assert_equal(cur.fetchone()[0], 1)
    cur.execute("CALL legado.corrigir_moradores_anexos_tenant()")
    cur.execute("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='erp' "
                "AND table_name='moradores_anexos' AND column_name='tenant_id'")
    assert_equal(cur.fetchone()[0], 1)


def test_mt_adicionar_coluna(cur, catalog):
    cur.execute("SELECT to_regclass('erp._v10d_proc_fixture')")
    if cur.fetchone()[0] is not None:
        raise RuntimeError("procedure fixture relation already exists")
    table = "_v10d_proc_fixture"
    cur.execute(sql.SQL("CREATE TABLE erp.{} (id integer)").format(sql.Identifier(table)))
    cur.execute("CALL legado.mt_adicionar_coluna(%s,%s,%s)",
                (table, "tenant_id", "INT(11) NOT NULL DEFAULT 1 AFTER `id`"))
    cur.execute("CALL legado.mt_adicionar_coluna(%s,%s,%s)",
                (table, "tenant_id", "INT(11) NOT NULL DEFAULT 1 AFTER `id`"))
    cur.execute("SELECT data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema='erp' AND table_name=%s AND column_name='tenant_id'", (table,))
    row = cur.fetchone()
    assert_equal(row, ("integer", "NO", "1"))
    cur.execute("SAVEPOINT proc_injection")
    try:
        cur.execute("CALL legado.mt_adicionar_coluna(%s,%s,%s)",
                    (table, "unsafe", "integer); DROP TABLE erp.moradores CASCADE; --"))
    except psycopg.Error as exc:
        if getattr(exc, "sqlstate", None) != "P0001" or getattr(exc.diag, "message_primary", None) != "unsupported column definition":
            raise AssertionError("unsafe definition failed for an unexpected reason") from exc
        cur.execute("ROLLBACK TO SAVEPOINT proc_injection")
        cur.execute("RELEASE SAVEPOINT proc_injection")
    else:
        raise AssertionError("unsafe definition accepted")
    cur.execute("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='erp' "
                "AND table_name=%s AND column_name='unsafe'", (table,))
    assert_equal(cur.fetchone()[0], 0)
    cur.execute("SELECT to_regclass('erp.moradores') IS NOT NULL")
    assert_equal(cur.fetchone()[0], True)


def verify_role_contract(cur):
    cur.execute("SELECT rolcanlogin,rolsuper,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls,rolinherit,rolconfig "
                "FROM pg_roles WHERE rolname='erp_app'")
    app_role = cur.fetchone()
    if app_role is None or app_role[:7] != (False, False, False, False, False, False, False) or app_role[7] not in (None, []):
        raise RuntimeError("erp_app role attributes are unsafe")
    cur.execute("SELECT rolcanlogin,rolsuper,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls,rolinherit,rolconfig "
                "FROM pg_roles WHERE rolname='erp_owner'")
    owner_role = cur.fetchone()
    if owner_role is None or owner_role[:7] != (False, False, False, False, False, False, False) or owner_role[7] not in (None, []):
        raise RuntimeError("erp_owner role attributes are unsafe")
    cur.execute("SELECT COUNT(*) FROM pg_auth_members m JOIN pg_roles r ON r.oid IN (m.roleid,m.member) "
                "WHERE r.rolname IN ('erp_app','erp_owner')")
    if cur.fetchone()[0]:
        raise RuntimeError("ERP role membership requires manual review")
    cur.execute("SELECT s.setconfig FROM pg_db_role_setting s JOIN pg_roles r ON r.oid=s.setrole "
                "JOIN pg_database d ON d.oid=s.setdatabase WHERE r.rolname='erp_app' AND d.datname=current_database()")
    row = cur.fetchone()
    config = row[0] if row else []
    normalized = {item.lower() for item in config}
    if "search_path=erp" not in normalized or "timezone=america/sao_paulo" not in normalized:
        raise RuntimeError("erp_app database defaults do not match search_path/timezone contract")
    cur.execute("SELECT has_schema_privilege('erp_app','erp','USAGE'),has_schema_privilege('erp_app','erp','CREATE'),has_schema_privilege('erp_app','legado','USAGE'),has_schema_privilege('erp_app','legado','CREATE'),has_schema_privilege('erp_app','public','USAGE'),has_schema_privilege('erp_app','public','CREATE'),has_database_privilege('erp_app',current_database(),'CONNECT')")
    if cur.fetchone() != (True, False, False, False, False, False, True):
        raise RuntimeError("schema privileges do not match the minimum role contract")
    cur.execute("SELECT COUNT(*) FROM pg_tables WHERE schemaname='erp' AND (NOT has_table_privilege('erp_app',format('%I.%I',schemaname,tablename),'SELECT') OR NOT has_table_privilege('erp_app',format('%I.%I',schemaname,tablename),'INSERT') OR NOT has_table_privilege('erp_app',format('%I.%I',schemaname,tablename),'UPDATE') OR NOT has_table_privilege('erp_app',format('%I.%I',schemaname,tablename),'DELETE'))")
    if cur.fetchone()[0] != 0:
        raise RuntimeError("erp_app lacks required DML on an ERP table")
    cur.execute("SELECT COUNT(*) FROM pg_sequences WHERE schemaname='erp' AND (NOT has_sequence_privilege('erp_app',format('%I.%I',schemaname,sequencename),'USAGE') OR NOT has_sequence_privilege('erp_app',format('%I.%I',schemaname,sequencename),'SELECT'))")
    if cur.fetchone()[0] != 0:
        raise RuntimeError("erp_app lacks required sequence privileges")
    cur.execute("SELECT COUNT(*) FROM pg_tables WHERE schemaname='legado' AND (has_table_privilege('erp_app',format('%I.%I',schemaname,tablename),'SELECT') OR has_table_privilege('erp_app',format('%I.%I',schemaname,tablename),'INSERT') OR has_table_privilege('erp_app',format('%I.%I',schemaname,tablename),'UPDATE') OR has_table_privilege('erp_app',format('%I.%I',schemaname,tablename),'DELETE'))")
    if cur.fetchone()[0] != 0:
        raise RuntimeError("erp_app has privileges on legado tables")


def verify_fixture_sentinels(cur):
    tenant_tables = ("acessos_visitantes", "qrcode_tokens", "dispositivos_tablets",
                     "logs_validacoes_dispositivo", "produtos_estoque", "alertas_estoque",
                     "checklist_alertas_config", "checklist_veicular", "checklist_km_acumulado",
                     "dependentes", "veiculos")
    for table in tenant_tables:
        cur.execute(sql.SQL("SELECT COUNT(*) FROM erp.{} WHERE tenant_id IN (%s,%s)").format(sql.Identifier(table)),
                    (FIXTURE_TENANT, FIXTURE_OTHER_TENANT))
        if cur.fetchone()[0]:
            raise RuntimeError("synthetic tenant sentinel collision")
    id_sentinels = (("moradores", FIXTURE_MORADOR), ("visitantes", FIXTURE_VISITANTE),
                    ("veiculos", FIXTURE_VEICULO), ("usuarios", FIXTURE_OPERADOR))
    for table, sentinel in id_sentinels:
        cur.execute(sql.SQL("SELECT COUNT(*) FROM erp.{} WHERE id=%s").format(sql.Identifier(table)),
                    (sentinel,))
        if cur.fetchone()[0]:
            raise RuntimeError("synthetic foreign-key sentinel collision")


class HarnessSignal(Exception):
    pass


def handle_signal(signum, frame):
    raise HarnessSignal(f"signal {signum}")


def main():
    global ACTIVE_TEST
    ap = argparse.ArgumentParser()
    ap.add_argument("--mysql-config", required=True)
    ap.add_argument("--repo-root", required=True)
    ap.add_argument("--pg-socket", required=True)
    ap.add_argument("--pg-port", type=int, required=True)
    ap.add_argument("--database", required=True)
    args = ap.parse_args()
    require_v10d_endpoint(args.database, args.pg_socket, args.pg_port)
    signal.signal(signal.SIGTERM, handle_signal)
    signal.signal(signal.SIGINT, handle_signal)
    mysql = None
    pg = None
    sequence_states = []
    tests = [("on_update_timestamp_semantics", test_on_update_timestamp, "erp_app"),
             ("after_qrcode_token_insert", test_qr, "erp_app"),
             ("after_validacao_dispositivo_insert", test_device, "erp_app"),
             ("trg_alerta_estoque_baixo", test_stock, "erp_app"),
             ("trg_atualizar_km_acumulado", test_checklist, "erp_app"),
             ("tr_dependente_ativar_veiculos", test_dependent_activation, "erp_app"),
             ("tr_dependente_inativar_veiculos", test_dependent_deactivation, "erp_app"),
             ("tr_dependente_deletar_veiculos", test_dependent_delete, "erp_app"),
             ("corrigir_moradores_anexos_tenant", test_correct_moradores_anexos_tenant, "postgres"),
             ("mt_adicionar_coluna", test_mt_adicionar_coluna, "postgres")]
    try:
        mysql = mysql_connection(args.mysql_config)
        tables = table_inventory(mysql)
        classify_legacy_tables(tables, args.repo_root)
        catalog = {"tables": tables, "columns": columns_inventory(mysql), "indexes": indexes_inventory(mysql)}
        pg = psycopg.connect(host=args.pg_socket, port=args.pg_port, dbname=args.database,
                             user="postgres", autocommit=True, connect_timeout=10)
        verify_v10d_postgres_connection(pg)
        with pg.cursor() as cur:
            sequence_states = capture_fixture_sequences(cur)
            prepared_sequences = prepare_fixture_sequences(cur, catalog)
            if prepared_sequences != len(sequence_states):
                raise RuntimeError("identity sequence inventory differs before synthetic trigger tests")
            cur.execute("BEGIN")
            try:
                verify_role_contract(cur)
                verify_fixture_sentinels(cur)
                print(json.dumps({"test": "erp_app_minimum_privileges", "status": "PASS"}), flush=True)
                for index, (label, fn, role) in enumerate(tests):
                    ACTIVE_TEST = label
                    savepoint = f"sp_v10d_test_{index}"
                    cur.execute(sql.SQL("SAVEPOINT {}").format(sql.Identifier(savepoint)))
                    cur.execute(sql.SQL("SET LOCAL ROLE {}").format(sql.Identifier(role)))
                    fn(cur, catalog)
                    print(json.dumps({"test": label, "status": "PASS"}), flush=True)
                    cur.execute(sql.SQL("ROLLBACK TO SAVEPOINT {}").format(sql.Identifier(savepoint)))
                    cur.execute(sql.SQL("RELEASE SAVEPOINT {}").format(sql.Identifier(savepoint)))
            finally:
                cur.execute("ROLLBACK")
    finally:
        try:
            if pg is not None and sequence_states:
                with pg.cursor() as cur:
                    restore_fixture_sequences(cur, sequence_states)
        finally:
            try:
                if mysql is not None:
                    try:
                        mysql.rollback()
                    finally:
                        mysql.close()
            finally:
                if pg is not None:
                    pg.close()

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(trigger_failure_marker(ACTIVE_TEST, exc), file=sys.stderr, flush=True)
        raise SystemExit(1)
