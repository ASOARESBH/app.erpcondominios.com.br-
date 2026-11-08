from __future__ import annotations

import argparse
import csv
import hashlib
import json
import sys
import time
from collections import Counter
from decimal import Decimal
from pathlib import Path

import psycopg
import pymysql
from psycopg import sql

from catalog import (SCHEMA, classify_legacy_tables, columns_inventory, foreign_keys_inventory, indexes_inventory,
                     mysql_connection, normalize_fk_types, query, table_inventory,
                     validate_foreign_key_snapshot)
from common import canonical, convert_temporal, generated_name, mysql_qi, qi, row_digest, verify_v9_postgres_connection

NUMERIC_TYPES = {"tinyint", "smallint", "mediumint", "int", "integer", "bigint", "decimal", "float", "double"}
BINARY_TYPES = {"tinyblob", "blob", "mediumblob", "longblob", "binary", "varbinary"}
ACTIVE_RECONCILIATION_STAGE = "startup"


def set_reconciliation_stage(stage):
    global ACTIVE_RECONCILIATION_STAGE
    ACTIVE_RECONCILIATION_STAGE = stage


def source_fetch(mysql, statement, params=None):
    cur = mysql.cursor()
    try:
        cur.execute(statement, params)
        return cur.fetchall()
    finally:
        cur.close()


def source_scalar(mysql, table, expression, where="", params=None):
    statement = f"SELECT {expression} FROM {mysql_qi(table)} {where}"
    rows = source_fetch(mysql, statement, params)
    return rows[0][0] if rows else None


def pg_scalar(pg, statement, params=None):
    with pg.cursor() as cur:
        cur.execute(statement, params)
        row = cur.fetchone()
    return row[0] if row else None


def pg_qualified(schema, table):
    return sql.SQL("{}.{}").format(sql.Identifier(schema), sql.Identifier(table))


def source_table_count(mysql, table):
    return int(source_scalar(mysql, table, "COUNT(*)") or 0)


def pg_table_count(pg, schema, table):
    with pg.cursor() as cur:
        cur.execute(sql.SQL("SELECT COUNT(*) FROM {}").format(pg_qualified(schema, table)))
        return int(cur.fetchone()[0])


def primary_columns(indexes, table):
    parts = indexes.get(table, {}).get("PRIMARY")
    if not parts:
        return []
    return [p["column"] for p in parts]


def row_hashes_source(mysql, table, cols, pk_cols):
    fields = ", ".join(mysql_qi(c["name"]) for c in cols)
    order = ""
    if pk_cols:
        order = " ORDER BY " + ", ".join(mysql_qi(n) for n in pk_cols)
    cur = mysql.cursor(pymysql.cursors.SSCursor)
    by_pk = {}
    multiset = Counter()
    try:
        cur.execute(f"SELECT {fields} FROM {mysql_qi(table)}{order}")
        index = {c["name"]: i for i, c in enumerate(cols)}
        types = [c["data_type"] for c in cols]
        pk_types = [next(c["data_type"] for c in cols if c["name"] == n) for n in pk_cols]
        while True:
            batch = cur.fetchmany(1000)
            if not batch:
                break
            for row in batch:
                values = tuple(row)
                digest = row_digest(values, types, side="source")
                if pk_cols:
                    key = row_digest(tuple(values[index[n]] for n in pk_cols), pk_types, side="source")
                    if key in by_pk:
                        raise RuntimeError("duplicate source primary key during reconciliation")
                    by_pk[key] = digest
                else:
                    multiset[digest] += 1
    finally:
        cur.close()
    return {"by_pk": by_pk, "multiset": multiset}


def row_hashes_pg(pg, schema, table, cols, pk_cols):
    fields = sql.SQL(", ").join(
        sql.SQL("{}::text AS {}").format(sql.Identifier(c["name"]), sql.Identifier(c["name"]))
        if c["data_type"] == "json" else sql.Identifier(c["name"])
        for c in cols
    )
    statement = sql.SQL("SELECT {} FROM {}{}").format(fields, pg_qualified(schema, table), sql.SQL(""))
    if pk_cols:
        order = sql.SQL(", ").join(sql.Identifier(n) for n in pk_cols)
        statement = sql.SQL("SELECT {} FROM {} ORDER BY {}").format(fields, pg_qualified(schema, table), order)
    cur = pg.cursor(name=f"recon_{hashlib.sha1((schema + table).encode()).hexdigest()[:12]}")
    cur.itersize = 1000
    by_pk = {}
    multiset = Counter()
    try:
        with pg.transaction():
            cur.execute(statement)
            index = {c["name"]: i for i, c in enumerate(cols)}
            types = [c["data_type"] for c in cols]
            pk_types = [next(c["data_type"] for c in cols if c["name"] == n) for n in pk_cols]
            while True:
                batch = cur.fetchmany(1000)
                if not batch:
                    break
                for row in batch:
                    values = tuple(row)
                    digest = row_digest(values, types, side="destination")
                    if pk_cols:
                        key = row_digest(tuple(values[index[n]] for n in pk_cols), pk_types, side="destination")
                        if key in by_pk:
                            raise RuntimeError("duplicate destination primary key during reconciliation")
                        by_pk[key] = digest
                    else:
                        multiset[digest] += 1
    finally:
        cur.close()
    return {"by_pk": by_pk, "multiset": multiset}


def hash_divergence(source, destination, pk_cols):
    if pk_cols:
        keys = set(source["by_pk"]) | set(destination["by_pk"])
        differing = sum(source["by_pk"].get(k) != destination["by_pk"].get(k) for k in keys)
        return int(differing), len(source["by_pk"]), len(destination["by_pk"])
    source_counts, dest_counts = source["multiset"], destination["multiset"]
    keys = set(source_counts) | set(dest_counts)
    source_unmatched = sum(max(source_counts.get(k, 0) - dest_counts.get(k, 0), 0) for k in keys)
    dest_unmatched = sum(max(dest_counts.get(k, 0) - source_counts.get(k, 0), 0) for k in keys)
    return max(source_unmatched, dest_unmatched), sum(source_counts.values()), sum(dest_counts.values())


def key_for_row(row, columns, names):
    index = {c["name"]: i for i, c in enumerate(columns)}
    return tuple(row[index[n]] for n in names)


def bucket_counts_source(mysql, table):
    rows = source_fetch(mysql, f"SELECT tenant_id,COUNT(*) FROM {mysql_qi(table)} GROUP BY tenant_id")
    return {r[0]: int(r[1]) for r in rows}


def bucket_counts_pg(pg, schema, table):
    with pg.cursor() as cur:
        cur.execute(sql.SQL("SELECT tenant_id,COUNT(*) FROM {} GROUP BY tenant_id").format(pg_qualified(schema, table)))
        rows = cur.fetchall()
    return {r[0]: int(r[1]) for r in rows}


def sums_for_source(mysql, table, cols):
    selected = [c for c in cols if c["data_type"] in NUMERIC_TYPES and not c["generated"]]
    if not selected:
        return {}
    expressions = ", ".join(f"SUM({mysql_qi(c['name'])})" for c in selected)
    row = source_fetch(mysql, f"SELECT {expressions} FROM {mysql_qi(table)}")[0]
    return {c["name"]: Decimal(str(v)) for c, v in zip(selected, row, strict=True) if v is not None}


def sums_for_pg(pg, schema, table, cols):
    selected = [c for c in cols if c["data_type"] in NUMERIC_TYPES and not c["generated"]]
    if not selected:
        return {}
    expressions = sql.SQL(", ").join(sql.SQL("SUM({})").format(sql.Identifier(c["name"])) for c in selected)
    with pg.cursor() as cur:
        cur.execute(sql.SQL("SELECT {} FROM {}").format(expressions, pg_qualified(schema, table)))
        row = cur.fetchone()
    return {c["name"]: Decimal(str(v)) for c, v in zip(selected, row, strict=True) if v is not None}


def numeric_equal(a, b, typ):
    if a is None or b is None:
        return a is b
    return a == b


def binary_stats_source(mysql, table, cols):
    selected = [c for c in cols if c["data_type"] in BINARY_TYPES]
    if not selected:
        return {}
    expressions = []
    for col in selected:
        name = mysql_qi(col["name"])
        expressions.extend([f"COUNT({name})", f"COALESCE(SUM(OCTET_LENGTH({name})),0)"])
    row = source_fetch(mysql, f"SELECT {', '.join(expressions)} FROM {mysql_qi(table)}")[0]
    result = {}
    for i, col in enumerate(selected):
        offset = i * 2
        result[col["name"]] = (int(row[offset] or 0), int(row[offset + 1] or 0))
    return result


def binary_stats_pg(pg, schema, table, cols):
    selected = [c for c in cols if c["data_type"] in BINARY_TYPES]
    if not selected:
        return {}
    expressions = []
    for col in selected:
        name = sql.Identifier(col["name"])
        expressions.extend([sql.SQL("COUNT({})").format(name), sql.SQL("COALESCE(SUM(octet_length({})),0)").format(name)])
    with pg.cursor() as cur:
        cur.execute(sql.SQL("SELECT {} FROM {}").format(sql.SQL(", ").join(expressions), pg_qualified(schema, table)))
        row = cur.fetchone()
    result = {}
    for i, col in enumerate(selected):
        offset = i * 2
        result[col["name"]] = (int(row[offset] or 0), int(row[offset + 1] or 0))
    return result


def max_value_source(mysql, table, col):
    return source_scalar(mysql, table, f"MAX({mysql_qi(col)})")


def max_value_pg(pg, schema, table, col):
    with pg.cursor() as cur:
        cur.execute(sql.SQL("SELECT MAX({}) FROM {}").format(sql.Identifier(col), pg_qualified(schema, table)))
        return cur.fetchone()[0]


def primary_max_mismatches(mysql, pg, schema, table, pk_cols, cols):
    mismatches = 0
    for col in pk_cols:
        source_value = max_value_source(mysql, table, col)
        destination_value = max_value_pg(pg, schema, table, col)
        data_type = next(c["data_type"] for c in cols if c["name"] == col)
        if canonical(source_value, data_type, side="source") != canonical(destination_value, data_type, side="destination"):
            mismatches += 1
    return mismatches


def sequence_status(pg, schema, table, col, max_value):
    with pg.cursor() as cur:
        cur.execute("SELECT pg_get_serial_sequence(%s,%s)", (f'{qi(schema)}.{qi(table)}', col))
        qualified = cur.fetchone()[0]
    if not qualified:
        return False
    with pg.cursor() as cur:
        cur.execute("SELECT n.nspname,c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE c.oid=%s::regclass",
                    (qualified,))
        seq_schema, seq_name = cur.fetchone()
    with pg.cursor() as cur:
        cur.execute(sql.SQL("SELECT last_value,is_called FROM {}.{}").format(
            sql.Identifier(seq_schema), sql.Identifier(seq_name)))
        last_value, is_called = cur.fetchone()
    threshold = int(max_value) if max_value is not None else 0
    return not is_called and int(last_value) == threshold + 1


def fk_orphans(pg, fk):
    src = pg_qualified("erp", fk["table"])
    ref = pg_qualified("erp", fk["columns"][0]["ref_table"])
    joins = []
    not_null = []
    for c in fk["columns"]:
        joins.append(sql.SQL("p.{} = c.{}").format(sql.Identifier(c["ref_column"]), sql.Identifier(c["column"])))
        not_null.append(sql.SQL("c.{} IS NOT NULL").format(sql.Identifier(c["column"])))
    where = sql.SQL(" AND ").join(not_null)
    join = sql.SQL(" AND ").join(joins)
    statement = sql.SQL("SELECT COUNT(*) FROM {} c LEFT JOIN {} p ON {} WHERE {} AND p.{} IS NULL").format(
        src, ref, join, where, sql.Identifier(fk["columns"][0]["ref_column"]))
    with pg.cursor() as cur:
        cur.execute(statement)
        return int(cur.fetchone()[0])


def fk_tenant_mismatches(pg, fk, columns):
    source_has = any(c["name"] == "tenant_id" for c in columns[fk["table"]])
    target_table = fk["columns"][0]["ref_table"]
    target_has = any(c["name"] == "tenant_id" for c in columns[target_table])
    if not source_has or not target_has:
        return 0
    src = pg_qualified("erp", fk["table"])
    ref = pg_qualified("erp", target_table)
    joins = sql.SQL(" AND ").join(
        sql.SQL("p.{}=c.{}").format(sql.Identifier(c["ref_column"]), sql.Identifier(c["column"]))
        for c in fk["columns"]
    )
    statement = sql.SQL("SELECT COUNT(*) FROM {} c JOIN {} p ON {} WHERE c.tenant_id IS NOT NULL AND p.tenant_id IS NOT NULL AND c.tenant_id<>p.tenant_id").format(src, ref, joins)
    with pg.cursor() as cur:
        cur.execute(statement)
        return int(cur.fetchone()[0])


def verify_destination_fk(pg, fk):
    table_ref = f'"erp"."{fk["table"]}"'
    referenced_table = fk["columns"][0]["ref_table"]
    constraint_name = generated_name("fk", fk["table"], fk["name"])
    with pg.cursor() as cur:
        cur.execute("""SELECT c.contype,c.convalidated,c.confupdtype,c.confdeltype,
                   (SELECT array_agg(a.attname ORDER BY k.ord) FROM unnest(c.conkey) WITH ORDINALITY k(attnum,ord)
                    JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=k.attnum),
                   (SELECT array_agg(a.attname ORDER BY k.ord) FROM unnest(c.confkey) WITH ORDINALITY k(attnum,ord)
                    JOIN pg_attribute a ON a.attrelid=c.confrelid AND a.attnum=k.attnum),rn.nspname,rc.relname
                   FROM pg_constraint c JOIN pg_class rc ON rc.oid=c.confrelid
                   JOIN pg_namespace rn ON rn.oid=rc.relnamespace
                   WHERE c.conrelid=%s::regclass AND c.conname=%s""",
                    (table_ref, constraint_name))
        row = cur.fetchone()
    if row is None or row[0] != "f" or (row[6], row[7]) != ("erp", referenced_table):
        raise RuntimeError("expected foreign key constraint is absent or references the wrong table")
    action_codes = {"NO ACTION": "a", "RESTRICT": "r", "CASCADE": "c", "SET NULL": "n", "SET DEFAULT": "d"}
    ordered = sorted(fk["columns"], key=lambda item: item["position"])
    source_columns = [item["column"] for item in ordered]
    target_columns = [item["ref_column"] for item in ordered]
    if (list(row[4] or []) != source_columns or list(row[5] or []) != target_columns
            or row[2] != action_codes.get(ordered[0]["on_update"])
            or row[3] != action_codes.get(ordered[0]["on_delete"])):
        raise RuntimeError("destination foreign key definition differs from the approved source signature")
    return bool(row[1])


def normalize_postgres_type(type_name):
    if type_name is None:
        return None
    value = type_name.strip().lower()
    for source, target in (("character varying", "varchar"), ("character", "char")):
        if value == source or value.startswith(source + "("):
            return target + value[len(source):]
    return value


def verify_destination_fk_types(pg, fk, columns):
    ordered = sorted(fk["columns"], key=lambda item: item["position"])
    with pg.cursor() as cur:
        for item in ordered:
            source_expected = normalize_postgres_type(
                next(c["pg_type"] for c in columns[fk["table"]] if c["name"] == item["column"]))
            referenced_expected = normalize_postgres_type(
                next(c["pg_type"] for c in columns[item["ref_table"]]
                     if c["name"] == item["ref_column"]))
            cur.execute(
                "SELECT (SELECT format_type(a.atttypid,a.atttypmod) FROM pg_attribute a "
                "WHERE a.attrelid=%s::regclass AND a.attname=%s AND a.attnum>0 AND NOT a.attisdropped),"
                "(SELECT format_type(a.atttypid,a.atttypmod) FROM pg_attribute a "
                "WHERE a.attrelid=%s::regclass AND a.attname=%s AND a.attnum>0 AND NOT a.attisdropped)",
                (f'"erp"."{fk["table"]}"', item["column"],
                 f'"erp"."{item["ref_table"]}"', item["ref_column"]),
            )
            source_actual, referenced_actual = cur.fetchone()
            source_actual = normalize_postgres_type(source_actual)
            referenced_actual = normalize_postgres_type(referenced_actual)
            if (source_actual != source_expected or referenced_actual != referenced_expected
                    or source_actual != referenced_actual):
                raise RuntimeError("destination foreign key column types differ from the normalized contract")


def verify_signed_integer_range_checks(pg, normalizations):
    expected = {}
    for item in normalizations:
        if item["rule"] != "widen_signed_integer_foreign_key":
            continue
        name = generated_name("ck", item["table"], item["column"], "mysql_signed_int_range")
        expected[name] = (item["table"],
                          f"check{item['column']}>={-2147483648}and{item['column']}<={2147483647}")
    if len(expected) != 2:
        raise RuntimeError("signed integer range-check manifest differs from the approved contract")
    with pg.cursor() as cur:
        cur.execute("""SELECT n.nspname,r.relname,c.conname,pg_get_constraintdef(c.oid,true),c.convalidated
                       FROM pg_constraint c JOIN pg_class r ON r.oid=c.conrelid
                       JOIN pg_namespace n ON n.oid=r.relnamespace
                       WHERE c.contype='c' AND c.conname=ANY(%s)""", (list(expected),))
        rows = cur.fetchall()
    observed = set()
    for schema, table, name, definition, validated in rows:
        definition = (definition.lower().replace("::integer", "").replace("::int4", "")
                      .replace("::bigint", "").replace("::int8", ""))
        normalized = "".join(ch for ch in definition if ch.isalnum() or ch in "_<>=.-")
        observed.add((schema, table, name, normalized, bool(validated)))
    wanted = {("erp", table, name, definition, True)
              for name, (table, definition) in expected.items()}
    if observed != wanted:
        raise RuntimeError("signed integer range-check definitions differ from the reviewed bundle")


def fk_metrics_for_table(table, fk_results):
    items = [item for item in fk_results if item["table"] == table]
    return {
        "foreign_keys": len(items),
        "foreign_keys_valid": sum(item["valid"] for item in items),
        "foreign_keys_with_orphans": sum(item["orphans"] > 0 for item in items),
        "fk_orphan_count": sum(item["orphans"] for item in items),
        "fk_tenant_mismatches": sum(item["tenant_mismatches"] for item in items),
    }


def validate_fk(pg, fk):
    name = generated_name("fk", fk["table"], fk["name"])
    with pg.cursor() as cur:
        cur.execute(sql.SQL("ALTER TABLE {} VALIDATE CONSTRAINT {}").format(
            pg_qualified("erp", fk["table"]), sql.Identifier(name)))


def timestamp_samples_source(mysql, table, cols, pk_cols):
    timestamps = [c for c in cols if c["data_type"] == "timestamp"]
    if not timestamps:
        return {"columns": [], "rows": [], "key_types": []}
    selection = list(dict.fromkeys(pk_cols + [c["name"] for c in timestamps]))
    order_cols = pk_cols or [c["name"] for c in timestamps]
    order = " ORDER BY " + ", ".join(mysql_qi(n) for n in order_cols) if order_cols else ""
    cur = mysql.cursor()
    pk_types = [next(c["data_type"] for c in cols if c["name"] == n) for n in pk_cols]
    result = {"columns": [c["name"] for c in timestamps], "rows": [], "key_types": pk_types}
    try:
        cur.execute(f"SELECT {', '.join(mysql_qi(n) for n in selection)} FROM {mysql_qi(table)}{order} LIMIT 20")
        ix = {name: index for index, name in enumerate(selection)}
        for row in cur.fetchall():
            converted = {c["name"]: convert_temporal(row[ix[c["name"]]], "timestamp") for c in timestamps}
            if pk_cols:
                pk_values = tuple(row[ix[n]] for n in pk_cols)
                key = row_digest(pk_values, pk_types, side="source").hex()
            else:
                key = None
            result["rows"].append({"key": key, "values": converted})
    finally:
        cur.close()
    return result


def timestamp_samples_pg(pg, schema, table, samples, pk_cols):
    columns = samples["columns"]
    source_rows = samples["rows"]
    if not columns or not source_rows:
        return {"sampled": 0, "matches": 0}
    if pk_cols:
        selection = list(dict.fromkeys(pk_cols + columns))
        fields = sql.SQL(", ").join(sql.Identifier(c) for c in selection)
        order = sql.SQL(", ").join(sql.Identifier(c) for c in pk_cols)
        with pg.cursor() as cur:
            cur.execute(sql.SQL("SELECT {} FROM {} ORDER BY {} LIMIT 20").format(fields, pg_qualified(schema, table), order))
            target_rows = cur.fetchall()
        ix = {name: i for i, name in enumerate(selection)}
        pk_types = samples["key_types"]
        target_map = {}
        for row in target_rows:
            key = row_digest(tuple(row[ix[n]] for n in pk_cols), pk_types, side="destination").hex()
            target_map[key] = {c: row[ix[c]] for c in columns}
        matches = 0
        for row in source_rows:
            target = target_map.get(row["key"])
            if target and all(target[c] == row["values"][c] for c in columns):
                matches += 1
        return {"sampled": len(source_rows) * len(columns), "matches": matches * len(columns)}
    source_counter = Counter(tuple(row["values"][c] for c in columns) for row in source_rows)
    with pg.cursor() as cur:
        order = sql.SQL(", ").join(sql.SQL("{} NULLS FIRST").format(sql.Identifier(c)) for c in columns)
        fields = sql.SQL(", ").join(sql.Identifier(c) for c in columns)
        cur.execute(sql.SQL("SELECT {} FROM {} ORDER BY {} LIMIT 20").format(fields, pg_qualified(schema, table), order))
        target_rows = cur.fetchall()
    target_counter = Counter(tuple(row) for row in target_rows)
    matches = sum(min(source_counter[k], target_counter[k]) for k in set(source_counter) | set(target_counter))
    return {"sampled": len(source_rows) * len(columns), "matches": matches * len(columns)}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--mysql-config", required=True)
    ap.add_argument("--pg-socket", required=True)
    ap.add_argument("--pg-port", type=int, required=True)
    ap.add_argument("--database", required=True)
    ap.add_argument("--repo-root", required=True)
    ap.add_argument("--csv", required=True)
    ap.add_argument("--summary", required=True)
    args = ap.parse_args()
    if args.database != "erp_ensaio_v9" or args.pg_port != 55432:
        raise SystemExit("refusing non-v9 or non-rehearsal target")
    mysql = None
    pg = None
    try:
        set_reconciliation_stage("mysql_connect")
        mysql = mysql_connection(args.mysql_config)
        set_reconciliation_stage("postgres_connect")
        pg = psycopg.connect(host=args.pg_socket, port=args.pg_port, dbname=args.database,
                             user="postgres", autocommit=True, connect_timeout=10)
        set_reconciliation_stage("source_table_inventory")
        tables = table_inventory(mysql)
        set_reconciliation_stage("source_legacy_classification")
        classify_legacy_tables(tables, args.repo_root)
        set_reconciliation_stage("source_column_inventory")
        cols = columns_inventory(mysql)
        set_reconciliation_stage("source_index_inventory")
        indexes = indexes_inventory(mysql)
        set_reconciliation_stage("source_fk_inventory")
        fks = foreign_keys_inventory(mysql)
        validate_foreign_key_snapshot(fks, tables)
        set_reconciliation_stage("source_fk_normalization")
        fk_type_normalizations = normalize_fk_types(mysql, cols, fks)
        set_reconciliation_stage("source_catalog_shape")
        if len(tables) != 270 or sum(map(len, cols.values())) != 3339 or len(fks) != 11:
            raise RuntimeError("catalog shape changed during reconciliation")
        set_reconciliation_stage("source_identity_shape")
        if (sum(c["auto_increment"] for cs in cols.values() for c in cs) != 232
                or sum("PRIMARY" not in indexes.get(t, {}) for t in tables) != 30):
            raise RuntimeError("identity or primary-key counts changed during reconciliation")
        set_reconciliation_stage("postgres_connection_contract")
        verify_v9_postgres_connection(pg)
        with pg.cursor() as cur:
            set_reconciliation_stage("postgres_table_count")
            cur.execute("SELECT COUNT(*) FROM pg_tables WHERE schemaname IN ('erp','legado')")
            if cur.fetchone()[0] != 270:
                raise RuntimeError("destination table count differs from source snapshot")
            set_reconciliation_stage("postgres_fk_count")
            cur.execute("SELECT COUNT(*),COUNT(*) FILTER (WHERE NOT convalidated) FROM pg_constraint "
                        "WHERE contype='f' AND connamespace IN (SELECT oid FROM pg_namespace "
                        "WHERE nspname IN ('erp','legado'))")
            if tuple(cur.fetchone()) != (11, 11):
                raise RuntimeError("destination foreign key count or initial validation state differs")
        set_reconciliation_stage("postgres_range_checks")
        verify_signed_integer_range_checks(pg, fk_type_normalizations)
        results = []
        total_started = time.monotonic()
        fk_results = []
        for fk in fks:
            set_reconciliation_stage("fk_destination_type")
            verify_destination_fk_types(pg, fk, cols)
            set_reconciliation_stage("fk_destination_signature")
            initially_validated = verify_destination_fk(pg, fk)
            if initially_validated:
                raise RuntimeError("foreign key was validated before data reconciliation")
            set_reconciliation_stage("fk_orphan_count")
            orphan_count = fk_orphans(pg, fk)
            set_reconciliation_stage("fk_tenant_scope")
            tenant_mismatch_count = fk_tenant_mismatches(pg, fk, cols)
            fk_results.append({"table": fk["table"], "name": fk["name"], "orphans": orphan_count,
                               "tenant_mismatches": tenant_mismatch_count,
                               "constraint_validated": initially_validated, "valid": False})
        for table in sorted(tables):
            set_reconciliation_stage("table_metadata")
            schema = tables[table]["schema"]
            table_cols = cols[table]
            set_reconciliation_stage("table_primary_key_inventory")
            pk_cols = primary_columns(indexes, table)
            set_reconciliation_stage("table_source_count")
            source_n = source_table_count(mysql, table)
            set_reconciliation_stage("table_destination_count")
            target_n = pg_table_count(pg, schema, table)
            set_reconciliation_stage("table_source_hash")
            hs = row_hashes_source(mysql, table, table_cols, pk_cols)
            set_reconciliation_stage("table_destination_hash")
            hd = row_hashes_pg(pg, schema, table, table_cols, pk_cols)
            hash_diff, source_hash_rows, destination_hash_rows = hash_divergence(hs, hd, pk_cols)
            set_reconciliation_stage("table_pk_max")
            pk_max_mismatch_count = primary_max_mismatches(mysql, pg, schema, table, pk_cols, table_cols)
            set_reconciliation_stage("table_numeric_sums")
            numeric_src = sums_for_source(mysql, table, table_cols)
            numeric_dst = sums_for_pg(pg, schema, table, table_cols)
            numeric_columns = [c for c in table_cols if c["data_type"] in NUMERIC_TYPES and not c["generated"]]
            numeric_mismatches = 0
            for c in numeric_columns:
                numeric_mismatches += not numeric_equal(numeric_src.get(c["name"]), numeric_dst.get(c["name"]), c["data_type"])
            set_reconciliation_stage("table_binary_stats")
            binary_columns = [c for c in table_cols if c["data_type"] in BINARY_TYPES and not c["generated"]]
            binary_src = binary_stats_source(mysql, table, table_cols)
            binary_dst = binary_stats_pg(pg, schema, table, table_cols)
            binary_mismatches = sum(binary_src.get(n) != binary_dst.get(n) for n in set(binary_src) | set(binary_dst))
            tenant_bucket_mismatches = 0
            tenant_buckets = 0
            if any(c["name"] == "tenant_id" for c in table_cols):
                set_reconciliation_stage("table_tenant_buckets")
                src_buckets = bucket_counts_source(mysql, table)
                dst_buckets = bucket_counts_pg(pg, schema, table)
                tenant_buckets = len(set(src_buckets) | set(dst_buckets))
                tenant_bucket_mismatches = sum(src_buckets.get(k, 0) != dst_buckets.get(k, 0) for k in set(src_buckets) | set(dst_buckets))
            identity_cols = [c for c in table_cols if c["auto_increment"]]
            identity_results = []
            set_reconciliation_stage("table_identity_sequences")
            for c in identity_cols:
                src_max = max_value_source(mysql, table, c["name"])
                dst_max = max_value_pg(pg, schema, table, c["name"])
                seq_ready = sequence_status(pg, schema, table, c["name"], dst_max)
                identity_results.append({"column": c["name"], "max_match": src_max == dst_max, "sequence_ready": seq_ready})
            set_reconciliation_stage("table_timestamp_samples")
            timestamp_src = timestamp_samples_source(mysql, table, table_cols, pk_cols)
            timestamp_dst = timestamp_samples_pg(pg, schema, table, timestamp_src, pk_cols)
            timestamp_sampled = timestamp_dst["sampled"]
            timestamp_mismatches = timestamp_dst["sampled"] - timestamp_dst["matches"]
            results.append({"table": table, "schema": schema, "source_rows": source_n, "destination_rows": target_n,
                            "row_count_match": source_n == target_n, "rows_divergent": hash_diff,
                            "source_hash_rows": source_hash_rows, "destination_hash_rows": destination_hash_rows,
                            "primary_key_columns": len(pk_cols), "pk_max_mismatch_count": pk_max_mismatch_count,
                            "identity_max_match_count": sum(x["max_match"] for x in identity_results),
                            "identity_count": len(identity_results), "sequence_ready_count": sum(x["sequence_ready"] for x in identity_results),
                            "numeric_columns_compared": len(numeric_columns), "numeric_sum_mismatches": numeric_mismatches,
                            "binary_columns_compared": len(binary_columns), "binary_mismatches": binary_mismatches,
                            "tenant_buckets": tenant_buckets, "tenant_bucket_mismatches": tenant_bucket_mismatches,
                            "foreign_keys": 0, "foreign_keys_valid": 0, "foreign_keys_with_orphans": 0,
                            "fk_orphan_count": 0, "fk_tenant_mismatches": 0,
                            "timestamp_samples": timestamp_sampled, "timestamp_mismatches": timestamp_mismatches})
            print(json.dumps({"event": "table_reconciled", "table": table, "rows": source_n,
                              "row_count_match": source_n == target_n, "rows_divergent": hash_diff}, ensure_ascii=False), flush=True)
        set_reconciliation_stage("summary_aggregation")
        summary = {
            "source_schema": SCHEMA, "tables": len(results), "source_rows": sum(r["source_rows"] for r in results),
            "destination_rows": sum(r["destination_rows"] for r in results),
            "row_count_mismatches": sum(not r["row_count_match"] for r in results),
            "tables_with_row_hash_divergence": sum(r["rows_divergent"] != 0 for r in results),
            "row_hash_divergent_total": sum(r["rows_divergent"] for r in results),
            "primary_key_columns": sum(r["primary_key_columns"] for r in results),
            "pk_max_mismatches": sum(r["pk_max_mismatch_count"] for r in results),
            "identity_columns": sum(r["identity_count"] for r in results),
            "identity_max_mismatches": sum(r["identity_count"] - r["identity_max_match_count"] for r in results),
            "sequences_not_ready": sum(r["identity_count"] - r["sequence_ready_count"] for r in results),
            "numeric_sum_mismatches": sum(r["numeric_sum_mismatches"] for r in results),
            "binary_mismatches": sum(r["binary_mismatches"] for r in results),
            "tenant_bucket_mismatches": sum(r["tenant_bucket_mismatches"] for r in results),
            "timestamp_samples": sum(r["timestamp_samples"] for r in results),
            "timestamp_mismatches": sum(r["timestamp_mismatches"] for r in results),
            "foreign_keys": len(fk_results), "foreign_keys_valid": sum(x["valid"] for x in fk_results),
            "foreign_keys_with_orphans": sum(x["orphans"] > 0 for x in fk_results),
            "fk_orphan_count_total": sum(x["orphans"] for x in fk_results),
            "fk_tenant_mismatch_count": sum(x["tenant_mismatches"] for x in fk_results),
            "fk_type_normalizations": fk_type_normalizations,
            "fk_type_normalizations_sha256": hashlib.sha256(
                json.dumps(fk_type_normalizations, ensure_ascii=False, sort_keys=True,
                           separators=(",", ":")).encode()).hexdigest(),
            "fk_results": fk_results, "elapsed_seconds": round(time.monotonic() - total_started, 3),
        }
        set_reconciliation_stage("summary_write")
        Path(args.summary).write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        Path(args.summary).chmod(0o600)
        print(json.dumps({k: v for k, v in summary.items() if k != "fk_results"}, ensure_ascii=False), flush=True)
        required_zero = ("row_count_mismatches", "tables_with_row_hash_divergence", "row_hash_divergent_total",
                         "pk_max_mismatches", "identity_max_mismatches", "sequences_not_ready",
                         "numeric_sum_mismatches", "binary_mismatches", "tenant_bucket_mismatches",
                         "timestamp_mismatches")
        required_zero = required_zero + ("fk_tenant_mismatch_count",)
        if any(summary[key] != 0 for key in required_zero):
            set_reconciliation_stage("metrics_gate")
            print("RECONCILIATION_BLOCKED stage=metrics_gate code=reconciliation_metrics_mismatch",
                  file=sys.stderr, flush=True)
            raise SystemExit(2)
        set_reconciliation_stage("fk_validation")
        with pg.transaction():
            for fk, result in zip(fks, fk_results, strict=True):
                if result["orphans"]:
                    if verify_destination_fk(pg, fk):
                        raise RuntimeError("foreign key with orphaned rows must remain NOT VALID")
                    continue
                validate_fk(pg, fk)
                if not verify_destination_fk(pg, fk):
                    raise RuntimeError("foreign key did not reach validated state")
                result["valid"] = True
        summary["foreign_keys_valid"] = sum(x["valid"] for x in fk_results)
        unresolved_fks = summary["foreign_keys_valid"] + summary["foreign_keys_with_orphans"]
        if unresolved_fks != len(fks):
            raise RuntimeError("foreign key state cannot be reconciled")
        summary["status"] = "ACCEPTED" if summary["foreign_keys_with_orphans"] == 0 else "ACCEPTED_WITH_ORPHANED_FKS"
        summary["fk_results"] = fk_results
        summary["elapsed_seconds"] = round(time.monotonic() - total_started, 3)
        for table_result in results:
            table_result.update(fk_metrics_for_table(table_result["table"], fk_results))
        set_reconciliation_stage("csv_write")
        Path(args.csv).parent.mkdir(parents=True, exist_ok=True)
        fields = list(results[0].keys())
        with open(args.csv, "w", newline="", encoding="utf-8") as f:
            w = csv.DictWriter(f, fieldnames=fields)
            w.writeheader()
            w.writerows(results)
        Path(args.csv).chmod(0o600)
        set_reconciliation_stage("summary_final_write")
        Path(args.summary).write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        Path(args.summary).chmod(0o600)
        print(json.dumps({k: v for k, v in summary.items() if k != "fk_results"}, ensure_ascii=False), flush=True)
    finally:
        if mysql is not None:
            try:
                mysql.rollback()
            finally:
                mysql.close()
        if pg is not None:
            pg.close()

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        state = getattr(exc, "sqlstate", None) or "none"
        print(f"RECONCILIATION_FAILED type={type(exc).__name__} "
              f"stage={ACTIVE_RECONCILIATION_STAGE} "
              f"code=reconciliation_{ACTIVE_RECONCILIATION_STAGE} sqlstate={state}",
              file=sys.stderr, flush=True)
        raise SystemExit(1)
