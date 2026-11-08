from __future__ import annotations

import argparse
import configparser
import json
import re
import sys
import time
from datetime import date, datetime, time as dtime
from decimal import Decimal
from pathlib import Path

import psycopg
import pymysql
from psycopg import sql
from pymysql.constants import FIELD_TYPE

from common import (ambiguous_sao_paulo_wall_time, convert_temporal, qi, row_digest,
                    json_contains_nul, mysql_qi, verify_v9_postgres_connection)
from catalog import (SCHEMA, classify_legacy_tables, mysql_connection, query, table_inventory,
                     columns_inventory, indexes_inventory, text_nul_counts_for_table)

BATCH_SIZE = 1000


class TextualNulError(ValueError):
    def __init__(self, table, column):
        self.table = table
        self.column = column
        super().__init__(f"NUL byte in textual column {table}.{column}")


def mysql_table_names(conn):
    tables = table_inventory(conn)
    cols = columns_inventory(conn)
    indexes = indexes_inventory(conn)
    return tables, cols, indexes


def order_clause(indexes, table):
    pk = indexes.get(table, {}).get("PRIMARY")
    if not pk:
        return ""
    parts = []
    for item in pk:
        if not item["column"]:
            raise ValueError("primary key contains an expression")
        parts.append(mysql_qi(item["column"]) + (" DESC" if item["direction"] == "D" else " ASC"))
    return " ORDER BY " + ", ".join(parts)


def check_text_nul(table, columns, values):
    for col, value in zip(columns, values, strict=True):
        if value is None:
            continue
        if col["data_type"] == "json":
            try:
                if json_contains_nul(value):
                    raise TextualNulError(table, col["name"])
            except (UnicodeDecodeError, json.JSONDecodeError):
                raise ValueError("invalid JSON text in source") from None
            continue
        if col["data_type"] in {"char", "varchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set"}:
            if isinstance(value, (bytes, bytearray, memoryview)) and b"\x00" in value:
                raise TextualNulError(table, col["name"])
            if isinstance(value, (bytes, bytearray, memoryview)):
                continue
        if isinstance(value, str) and "\x00" in value:
            raise TextualNulError(table, col["name"])


def convert_value(table, col, value, zero_counts, dst_fold_counts):
    typ = col["data_type"]
    if value is None:
        return None
    if typ in {"date", "datetime", "timestamp", "time"}:
        if typ in {"date", "datetime", "timestamp"}:
            raw = value.decode("ascii") if isinstance(value, bytes) else str(value)
            if raw.startswith("0000-") or re.match(r"^\d{4}-00-|^\d{4}-\d{2}-00", raw):
                zero_counts[f"{table}.{col['name']}"] = zero_counts.get(f"{table}.{col['name']}", 0) + 1
        converted = convert_temporal(value, typ)
        if typ == "timestamp" and converted is not None:
            if ambiguous_sao_paulo_wall_time(converted):
                key = f"{table}.{col['name']}"
                dst_fold_counts[key] = dst_fold_counts.get(key, 0) + 1
                raise ValueError("TIMESTAMP maps to an ambiguous America/Sao_Paulo wall time")
        return converted
    if typ in {"tinyint", "smallint", "mediumint", "int", "integer", "bigint"}:
        if isinstance(value, bool):
            return int(value)
        return int(value)
    if typ == "decimal":
        return value if isinstance(value, Decimal) else Decimal(str(value))
    if typ == "float":
        return float(value)
    if typ == "double":
        return float(value)
    if typ == "json":
        if isinstance(value, bytes):
            return value.decode("utf-8")
        return str(value)
    if typ in {"tinyblob", "blob", "mediumblob", "longblob", "binary", "varbinary"}:
        return bytes(value)
    if isinstance(value, bytes):
        return value.decode("utf-8", "strict")
    return value


def destination_columns(cols):
    return [c for c in cols if not c["generated"]]


def dst_copy_type(data_type):
    if data_type == "json":
        return "json"
    return None


def load_table(mysql_conn, pg_conn, schema, table, all_columns, indexes, zero_counts, dst_fold_counts, batch_size):
    src_cols = all_columns[table]
    insert_cols = destination_columns(src_cols)
    colnames_src = ", ".join(mysql_qi(c["name"]) for c in src_cols)
    order = order_clause(indexes, table)
    src_sql = f"SELECT {colnames_src} FROM {mysql_qi(table)}{order}"
    placeholders = sql.SQL(",").join(sql.Identifier(c["name"]) for c in insert_cols)
    copy_sql = sql.SQL("COPY {}.{} ({}) FROM STDIN").format(
        sql.Identifier(schema), sql.Identifier(table), placeholders
    )
    started = time.monotonic()
    source_count = 0
    src_cursor = mysql_conn.cursor(pymysql.cursors.SSCursor)
    try:
        src_cursor.execute(src_sql)
        with pg_conn.transaction():
            with pg_conn.cursor() as cur:
                with cur.copy(copy_sql) as copy:
                    while True:
                        rows = src_cursor.fetchmany(batch_size)
                        if not rows:
                            break
                        for row in rows:
                            row = tuple(row)
                            check_text_nul(table, src_cols, row)
                            values = []
                            for col, value in zip(src_cols, row, strict=True):
                                if col["generated"]:
                                    continue
                                values.append(convert_value(table, col, value, zero_counts, dst_fold_counts))
                            copy.write_row(tuple(values))
                            source_count += 1
        return {"table": table, "source_rows": source_count,
                "elapsed_seconds": round(time.monotonic() - started, 3), "ordered_by_pk": bool(order)}
    finally:
        src_cursor.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--mysql-config", required=True)
    ap.add_argument("--pg-socket", required=True)
    ap.add_argument("--pg-port", type=int, required=True)
    ap.add_argument("--database", required=True)
    ap.add_argument("--repo-root", required=True)
    ap.add_argument("--evidence", required=True)
    ap.add_argument("--batch-size", type=int, default=BATCH_SIZE)
    args = ap.parse_args()
    if args.database != "erp_ensaio_v9":
        raise SystemExit("refusing unexpected rehearsal database name")
    if args.batch_size < 1 or args.batch_size > 10000:
        raise SystemExit("batch size out of approved range")
    mysql_conn = mysql_connection(args.mysql_config)
    pg_conn = None
    zero_counts: dict[str, int] = {}
    dst_fold_counts: dict[str, int] = {}
    current_table = None
    source_columns = {}
    try:
        pg_conn = psycopg.connect(host=args.pg_socket, port=args.pg_port, dbname=args.database,
                                  user="postgres", autocommit=True, connect_timeout=10)
        with mysql_conn.cursor() as cur:
            cur.execute("SELECT @@read_only,@@super_read_only,@@event_scheduler,@@session.time_zone")
            flags = cur.fetchone()
            if tuple(map(str, flags)) != ("1", "1", "OFF", "+00:00"):
                raise RuntimeError("source MySQL safety flags changed")
        verify_v9_postgres_connection(pg_conn)
        with pg_conn.cursor() as cur:
            cur.execute("SELECT count(*) FROM pg_tables WHERE schemaname IN ('erp','legado')")
            if cur.fetchone()[0] != 270:
                raise RuntimeError("destination schema is not a complete generated 270-table schema")
            cur.execute("SELECT n.nspname,c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('erp','legado') AND c.relkind='r'")
            destination = cur.fetchall()
            for schema, table in destination:
                cur.execute(sql.SQL("SELECT count(*) FROM {}.{}").format(sql.Identifier(schema), sql.Identifier(table)))
                if cur.fetchone()[0] != 0:
                    raise RuntimeError("destination contains rows; refusing append or overwrite")
        tables, columns, indexes = mysql_table_names(mysql_conn)
        source_columns = columns
        classify_legacy_tables(tables, args.repo_root)
        if len(tables) != 270 or sum(map(len, columns.values())) != 3339:
            raise RuntimeError("source catalog changed")
        totals = []
        total_start = time.monotonic()
        for table in sorted(tables):
            current_table = table
            result = load_table(mysql_conn, pg_conn, tables[table]["schema"], table, columns, indexes,
                                zero_counts, dst_fold_counts, args.batch_size)
            totals.append(result)
            print(json.dumps({"event": "table_loaded", **result}, ensure_ascii=False), flush=True)
        report = {"tables_loaded": len(totals), "source_rows_loaded": sum(x["source_rows"] for x in totals),
                  "elapsed_seconds": round(time.monotonic() - total_start, 3), "zero_date_conversions": zero_counts,
                  "dst_ambiguous_local_times": dst_fold_counts,
                  "tables_without_pk": sorted(t for t in tables if "PRIMARY" not in indexes.get(t, {})),
                  "table_metrics": totals}
        Path(args.evidence).write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        Path(args.evidence).chmod(0o600)
        print(json.dumps({k: v for k, v in report.items() if k != "table_metrics"}, ensure_ascii=False), flush=True)
    except Exception as exc:
        report = {"status": "BLOCKED", "error_type": type(exc).__name__,
                  "sqlstate": getattr(exc, "sqlstate", None) or "none", "blocked_table": current_table,
                  "zero_date_conversions": zero_counts, "dst_ambiguous_local_times": dst_fold_counts}
        if isinstance(exc, TextualNulError):
            report["textual_nul_observation"] = {"table": exc.table, "column": exc.column,
                                                 "minimum_count": 1}
            try:
                counts = text_nul_counts_for_table(mysql_conn, exc.table, source_columns.get(exc.table, []))
                if counts:
                    report["textual_nul_counts"] = counts
                    report["textual_nul_count_scan_status"] = "complete"
                else:
                    report["textual_nul_count_scan_status"] = "inconsistent_with_observation"
            except Exception:
                report["textual_nul_count_scan_status"] = "failed"
        Path(args.evidence).write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        Path(args.evidence).chmod(0o600)
        raise
    finally:
        if pg_conn is not None:
            pg_conn.close()
        mysql_conn.rollback()
        mysql_conn.close()

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        state = getattr(exc, "sqlstate", None) or "none"
        print(f"LOAD_FAILED type={type(exc).__name__} sqlstate={state}", file=sys.stderr, flush=True)
        raise SystemExit(1)
