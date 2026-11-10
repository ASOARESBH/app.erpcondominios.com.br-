from __future__ import annotations

import argparse
import configparser
import hashlib
import json
import os
import re
import sys
import subprocess
from collections import defaultdict
from pathlib import Path

import pymysql
from pymysql.constants import FIELD_TYPE

from common import (collate_class, generated_name, json_contains_nul, mysql_type_to_pg,
                    parse_mysql_enum, pg_name, qi, ql, mysql_qi, V10D_DATABASE,
                    require_v10d_database)

SCHEMA = "inlaud99_erpcondor"
OFFICIAL_ORIGIN = "https://github.com/ASOARESBH/app.erpcondominios.com.br-.git"
APPROVED_BASE = "f60739713e6de3f2473b79ecd14a70fd700e5462"
BACKUP_NAME = re.compile(r"(?:^|_)(?:bkp|backup|old|copy)(?:_|$)|(?:_20\d{6,14})$", re.I)
EXPECTED_BACKUP_CANDIDATES = {
    "avaliacoes_backup", "documentos_departamentos_migrado_bkp", "mt_os_chamados_id_zero_backup", "mt_protocolos_id_zero_backup",
    "rbac_sessoes_bkp_ids_a_partir_15_20261027", "rbac_usuario_grupos_bkp_ids_a_partir_15_20261027",
    "rbac_usuario_permissoes_bkp_ids_a_partir_15_20261027", "sessoes_usuarios_bkp_ids_a_partir_15_20261027",
    "usuarios_bkp_duplicata_admin_20261027", "usuarios_bkp_ids_a_partir_15_20261027",
    "usuario_modulos_bkp_ids_a_partir_15_20261027", "usuario_tenant_bkp_ids_a_partir_15_20261027",
}
EXPECTED_TRIGGERS = {
    "after_qrcode_token_insert", "after_validacao_dispositivo_insert", "trg_alerta_estoque_baixo",
    "trg_atualizar_km_acumulado", "tr_dependente_ativar_veiculos",
    "tr_dependente_deletar_veiculos", "tr_dependente_inativar_veiculos",
}
EXPECTED_TRIGGER_METADATA = {
    "after_qrcode_token_insert": ("qrcode_tokens", "AFTER", "INSERT", "ROW", None, "", "bd36dac166ce871c10a393d8f959206c43bc2f4f8c22adb5926064e5c38d0ab8", 103),
    "after_validacao_dispositivo_insert": ("logs_validacoes_dispositivo", "AFTER", "INSERT", "ROW", None, "", "c8ed66f274c4d58f1092980c89bca871fb7871f777c8eeae250b47bb2342fd18", 175),
    "trg_alerta_estoque_baixo": ("produtos_estoque", "AFTER", "UPDATE", "ROW", None, "", "986a4a8b3ea714c93c749ab834368115c98667b0210a81738c1e381f5fdf0e86", 536),
    "trg_atualizar_km_acumulado": ("checklist_veicular", "AFTER", "UPDATE", "ROW", None, "", "3bf6bfc05b756ae2627b55b874319faa055b4bdf38df1bd0bd78236a2f32baa9", 618),
    "tr_dependente_ativar_veiculos": ("dependentes", "BEFORE", "UPDATE", "ROW", None, "", "87c2eabb8e13760db61ea1a5d9bd01ef7a92689e546eada1b916a62a5d98e8a4", 155),
    "tr_dependente_deletar_veiculos": ("dependentes", "BEFORE", "DELETE", "ROW", None, "", "c9c617367a42f86f625a2c04b6cf1384caa19bdf04f0df5d713e88ed3708362f", 68),
    "tr_dependente_inativar_veiculos": ("dependentes", "BEFORE", "UPDATE", "ROW", None, "", "26d1ff52cc0fca6d6e4f7a93ea3b940788866fa6214522e4ac06126c31cf50a1", 139),
}
EXPECTED_PROCEDURES = {"corrigir_moradores_anexos_tenant", "mt_adicionar_coluna"}
EXPECTED_COLLATION_COUNTS = {
    "utf8mb4_general_ci": 147,
    "utf8mb4_unicode_ci": 1098,
    "utf8_unicode_ci": 127,
}
EXPECTED_TENANT_SIGNATURE_SHA256 = "d51a1cdc300c7730eaf92bbee6ee6debaf3e947b3831390749d342efb7401f77"
EXPECTED_NO_TENANT_NAMES_SHA256 = "70b1a0fee9e2686abb45bfd10330399a818b4ecf71ec908406a37bf249a4d5ca"
EXPECTED_FOREIGN_KEYS = {
    ("aplicativos_versoes", "fk_aplicativos_versoes_aplicativo"): (("aplicativo_id", "aplicativos_catalogo", "id", "CASCADE", "CASCADE"),),
    ("dashboard_empresa_widgets", "fk_dashboard_empresa_widget_catalogo"): (("widget_key", "dashboard_widgets_catalogo", "widget_key", "RESTRICT", "CASCADE"),),
    ("dashboard_usuario_widgets", "fk_dashboard_usuario_widget_catalogo"): (("widget_key", "dashboard_widgets_catalogo", "widget_key", "RESTRICT", "CASCADE"),),
    ("moradores_anexos", "fk_moradores_anexos_morador"): (("morador_id", "moradores", "id", "CASCADE", "CASCADE"),),
    ("rbac_auditoria", "fk_rbac_auditoria_sessao"): (("sessao_id", "rbac_sessoes", "id", "CASCADE", "SET NULL"),),
    ("rbac_grupo_permissoes", "fk_rbac_gp_grupo"): (("grupo_id", "rbac_grupos", "id", "CASCADE", "CASCADE"),),
    ("rbac_grupo_permissoes", "fk_rbac_gp_permissao"): (("permissao_id", "rbac_permissoes", "id", "CASCADE", "CASCADE"),),
    ("rbac_modulos", "fk_rbac_modulo_pai"): (("modulo_pai_id", "rbac_modulos", "id", "CASCADE", "RESTRICT"),),
    ("rbac_permissoes", "fk_rbac_permissao_modulo"): (("modulo_id", "rbac_modulos", "id", "CASCADE", "CASCADE"),),
    ("rbac_usuario_grupos", "fk_rbac_ug_grupo"): (("grupo_id", "rbac_grupos", "id", "CASCADE", "RESTRICT"),),
    ("rbac_usuario_permissoes", "fk_rbac_up_permissao"): (("permissao_id", "rbac_permissoes", "id", "CASCADE", "CASCADE"),),
}
EXPECTED_FK_TYPE_NORMALIZATIONS = {
    ("aplicativos_versoes", "aplicativo_id", "aplicativos_catalogo", "id"): {
        "source_data_type": "int", "source_unsigned": False, "source_auto_increment": False,
        "source_column_type": "int(11)", "source_precision": 10, "source_scale": 0, "source_nullable": False,
        "source_default_is_null": True, "source_extra": "", "source_generated": False,
        "source_pg_type": "integer", "referenced_data_type": "int", "referenced_unsigned": False,
        "referenced_auto_increment": True, "referenced_column_type": "int(11)",
        "referenced_precision": 10, "referenced_scale": 0, "referenced_nullable": False,
        "referenced_default_is_null": True, "referenced_extra": "auto_increment", "referenced_generated": False,
        "referenced_pg_type": "bigint", "target_pg_type": "bigint",
        "rule": "widen_signed_integer_foreign_key",
    },
    ("moradores_anexos", "morador_id", "moradores", "id"): {
        "source_data_type": "int", "source_unsigned": False, "source_auto_increment": False,
        "source_column_type": "int(11)", "source_precision": 10, "source_scale": 0, "source_nullable": False,
        "source_default_is_null": True, "source_extra": "", "source_generated": False,
        "source_pg_type": "integer", "referenced_data_type": "int", "referenced_unsigned": False,
        "referenced_auto_increment": True, "referenced_column_type": "int(11)",
        "referenced_precision": 10, "referenced_scale": 0, "referenced_nullable": False,
        "referenced_default_is_null": True, "referenced_extra": "auto_increment", "referenced_generated": False,
        "referenced_pg_type": "bigint", "target_pg_type": "bigint",
        "rule": "widen_signed_integer_foreign_key",
    },
    ("rbac_auditoria", "sessao_id", "rbac_sessoes", "id"): {
        "source_data_type": "bigint", "source_unsigned": True, "source_auto_increment": False,
        "source_column_type": "bigint(20) unsigned", "source_precision": 20, "source_scale": 0,
        "source_nullable": True, "source_default_is_null": True, "source_extra": "", "source_generated": False,
        "source_pg_type": "numeric(20,0)", "referenced_data_type": "bigint",
        "referenced_unsigned": True, "referenced_auto_increment": True,
        "referenced_column_type": "bigint(20) unsigned", "referenced_precision": 20,
        "referenced_scale": 0, "referenced_nullable": False, "referenced_default_is_null": True,
        "referenced_extra": "auto_increment", "referenced_generated": False,
        "referenced_pg_type": "bigint", "target_pg_type": "bigint",
        "rule": "narrow_unsigned_bigint_with_verified_range",
        "expected_source_counts": {"rows": 791, "nulls": 0, "fractional": 0, "negative": 0,
                                   "outside_signed_bigint": 0},
    },
}
TEMPORAL_TYPES = {"date", "datetime", "timestamp"}


def mysql_connection(config_path: str):
    cfg = configparser.RawConfigParser()
    if not cfg.read(config_path) or not cfg.has_section("client"):
        raise RuntimeError("invalid MySQL client configuration")
    opt = cfg["client"]
    conv = pymysql.converters.conversions.copy()
    for field in (FIELD_TYPE.DATE, FIELD_TYPE.NEWDATE, FIELD_TYPE.DATETIME, FIELD_TYPE.TIMESTAMP, FIELD_TYPE.TIME):
        conv[field] = lambda raw: raw
    if hasattr(FIELD_TYPE, "JSON"):
        conv[FIELD_TYPE.JSON] = lambda raw: raw
    params = {"user": opt.get("user"), "password": opt.get("password"),
        "database": SCHEMA, "charset": "utf8mb4", "autocommit": True, "connect_timeout": 10,
        "read_timeout": 300, "write_timeout": 10, "cursorclass": pymysql.cursors.SSCursor,
        "conv": conv, "use_unicode": True,
    }
    params.update({"host": "127.0.0.1", "port": 3306})
    conn = pymysql.connect(**params)
    try:
        with conn.cursor() as cur:
            cur.execute("SET SESSION time_zone = '+00:00'")
            cur.execute("SELECT CURRENT_USER()")
            current_user = cur.fetchone()[0]
            cur.execute("SHOW GRANTS")
            grants = [row[0].decode("utf-8") if isinstance(row[0], bytes) else str(row[0])
                      for row in cur.fetchall()]
            validate_reader_grants(current_user, grants)
            cur.execute("SELECT @@read_only,@@super_read_only,@@event_scheduler,@@session.time_zone")
            flags = cur.fetchone()
            if tuple(map(str, flags)) != ("1", "1", "OFF", "+00:00"):
                raise RuntimeError("source MySQL is not read-only with events disabled and UTC session")
            cur.execute("SET SESSION TRANSACTION READ ONLY")
            cur.execute("START TRANSACTION READ ONLY")
        return conn
    except BaseException:
        conn.close()
        raise


def validate_reader_grants(current_user: str, grants: list[str]) -> None:
    expected = {
        "GRANT USAGE ON *.* TO 'PGLOADER'@'127.0.0.1'",
        "GRANT SELECT, SHOW VIEW, TRIGGER ON INLAUD99_ERPCONDOR.* TO 'PGLOADER'@'127.0.0.1'",
    }
    normalized = {re.sub(r"\s+", " ", grant.strip()).replace("`", "").upper() for grant in grants}
    if current_user.lower() != "pgloader@127.0.0.1" or normalized != expected or len(grants) != 2:
        raise RuntimeError("source MySQL grants are not the exact approved read-only set")


def query(conn, sql, args=None):
    with conn.cursor() as cur:
        cur.execute(sql, args)
        return cur.fetchall()


def table_inventory(conn):
    rows = query(conn, """
        SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, DATA_LENGTH, INDEX_LENGTH
        FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_TYPE='BASE TABLE'
        ORDER BY TABLE_NAME
    """, (SCHEMA,))
    tables = {}
    for name, table_type, engine, collation, data_length, index_length in rows:
        tables[name] = {"name": name, "type": table_type, "engine": engine, "collation": collation,
                        "data_length": int(data_length or 0), "index_length": int(index_length or 0),
                        "schema": "erp"}
    return tables


def object_type_counts(conn):
    rows = query(conn, "SELECT TABLE_TYPE,COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s GROUP BY TABLE_TYPE", (SCHEMA,))
    return {str(kind): int(count) for kind, count in rows}


def classify_legacy_tables(tables, repo_root: str):
    candidates = {table for table in tables if BACKUP_NAME.search(table)}
    if candidates != EXPECTED_BACKUP_CANDIDATES:
        raise RuntimeError("backup candidate set differs from the reviewed rehearsal snapshot")
    root = Path(repo_root)
    def git(*args):
        return subprocess.run(["git", "-c", f"safe.directory={root}", "-C", str(root), *args], capture_output=True, text=True)
    origin = git("remote", "get-url", "origin")
    head = git("rev-parse", "HEAD")
    branch = git("branch", "--show-current")
    status = git("status", "--porcelain")
    if (origin.returncode or origin.stdout.strip() != OFFICIAL_ORIGIN or head.returncode
            or head.stdout.strip() != APPROVED_BASE or branch.stdout.strip() != "main"
            or status.returncode or status.stdout.strip()):
        raise RuntimeError("official source clone provenance or clean baseline check failed")
    decisions = {}
    for table in sorted(tables):
        if not BACKUP_NAME.search(table):
            tables[table]["schema"] = "erp"
            continue
        matches = git("grep", "-I", "-l", "-w", "-F", table, "--",
                      "*.php", "*.js", "*.html", "*.mjs", "*.py", "*.sh", "*.sql")
        if matches.returncode == 0:
            paths = sorted(line.split(":", 1)[0] for line in matches.stdout.splitlines())
            tables[table]["schema"] = "erp"
            decisions[table] = {"schema": "erp", "reason": "active_code_reference", "paths": paths}
        elif matches.returncode == 1:
            tables[table]["schema"] = "legado"
            decisions[table] = {"schema": "legado", "reason": "backup_name_no_active_code_reference", "paths": []}
        else:
            raise RuntimeError("git grep failed while classifying candidate backup table")
    return decisions


def columns_inventory(conn):
    rows = query(conn, """
        SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,
               COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION,CHARACTER_MAXIMUM_LENGTH,
               NUMERIC_PRECISION,NUMERIC_SCALE,CHARACTER_SET_NAME,COLLATION_NAME,DATETIME_PRECISION
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s
        ORDER BY TABLE_NAME,ORDINAL_POSITION
    """, (SCHEMA,))
    columns = defaultdict(list)
    for r in rows:
        (table, name, ordinal, data_type, column_type, nullable, default, extra, generated,
         char_length, precision, scale, charset, collation, datetime_precision) = r
        item = {"name": name, "ordinal": int(ordinal), "data_type": data_type.lower(),
                "column_type": column_type, "nullable": nullable == "YES", "default": default,
                "extra": extra or "", "generation_expression": generated or "",
                "char_length": int(char_length) if char_length is not None else None,
                "precision": int(precision) if precision is not None else None,
                "scale": int(scale) if scale is not None else None,
                "charset": charset, "collation": collation,
                "datetime_precision": int(datetime_precision) if datetime_precision is not None else None}
        item["pg_type"] = mysql_type_to_pg(item["data_type"], item["column_type"], item["extra"])
        if item["data_type"] in {"datetime", "timestamp", "time"} and (item["datetime_precision"] or 0) > 0:
            raise ValueError("fractional temporal precision requires explicit approval")
        item["generated"] = bool(item["generation_expression"])
        item["collate"] = collate_class(name, item["data_type"], item["column_type"], collation)
        if item["data_type"] in {"enum", "set"}:
            item["domain_values"] = parse_mysql_enum(item["column_type"])
        if "unsigned" in item["column_type"].lower():
            item["unsigned"] = True
        else:
            item["unsigned"] = False
        if "auto_increment" in item["extra"].lower():
            item["auto_increment"] = True
        else:
            item["auto_increment"] = False
        columns[table].append(item)
    return dict(columns)


def indexes_inventory(conn):
    rows = query(conn, """
        SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,COLLATION,INDEX_TYPE
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s
        ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX
    """, (SCHEMA,))
    indexes = defaultdict(lambda: defaultdict(list))
    for table, name, non_unique, seq, col, sub_part, direction, kind in rows:
        indexes[table][name].append({"non_unique": bool(non_unique), "seq": int(seq), "column": col,
                                     "sub_part": int(sub_part) if sub_part else None,
                                     "direction": direction, "type": kind})
    return {t: dict(v) for t, v in indexes.items()}


def validate_checklist_upsert_contract(indexes):
    table_indexes = indexes.get("checklist_km_acumulado", {})
    primary = table_indexes.get("PRIMARY", [])
    if (len(primary) != 1 or primary[0]["column"] != "id"
            or any(name != "PRIMARY" and any(not part["non_unique"] for part in parts)
                   for name, parts in table_indexes.items())):
        raise RuntimeError("checklist ON DUPLICATE KEY target differs from the approved primary-key contract")


def foreign_keys_inventory(conn):
    rows = query(conn, """
        SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.ORDINAL_POSITION,
               k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
         AND r.TABLE_NAME=k.TABLE_NAME
        WHERE k.CONSTRAINT_SCHEMA=%s AND k.REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION
    """, (SCHEMA,))
    grouped = defaultdict(list)
    for r in rows:
        grouped[(r[0], r[1])].append({"column": r[2], "position": int(r[3]), "ref_table": r[4],
                                     "ref_column": r[5], "on_update": r[6], "on_delete": r[7]})
    return [{"table": t, "name": n, "columns": v} for (t, n), v in grouped.items()]


def validate_foreign_key_snapshot(fks, tables):
    actual = {}
    for fk in fks:
        actual[(fk["table"], fk["name"])] = tuple(
            (col["column"], col["ref_table"], col["ref_column"], col["on_update"], col["on_delete"])
            for col in sorted(fk["columns"], key=lambda item: item["position"]))
    if actual != EXPECTED_FOREIGN_KEYS:
        raise RuntimeError("foreign key signature differs from approved rehearsal snapshot")
    for fk in fks:
        if tables[fk["table"]]["schema"] != "erp" or any(
                tables[col["ref_table"]]["schema"] != "erp" for col in fk["columns"]):
            raise RuntimeError("foreign key crosses the approved erp/legado boundary")


def normalize_fk_types(conn, columns, fks):
    column_lookup = {(table, item["name"]): item for table, items in columns.items() for item in items}
    pairs = {}
    for fk in fks:
        for col in fk["columns"]:
            key = (fk["table"], col["column"], col["ref_table"], col["ref_column"])
            if key in pairs:
                raise RuntimeError("duplicate foreign key column pair requires explicit review")
            pairs[key] = col
    if set(EXPECTED_FK_TYPE_NORMALIZATIONS) - set(pairs):
        raise RuntimeError("approved foreign key type normalization signature is missing")
    records = []
    for key in sorted(pairs):
        source = column_lookup.get((key[0], key[1]))
        referenced = column_lookup.get((key[2], key[3]))
        if source is None or referenced is None:
            raise RuntimeError("foreign key column metadata is missing")
        expected = EXPECTED_FK_TYPE_NORMALIZATIONS.get(key)
        if expected is None:
            if source["pg_type"] != referenced["pg_type"]:
                raise RuntimeError("unlisted foreign key type mismatch requires explicit review")
            continue
        actual = {
            "source_data_type": source["data_type"], "source_unsigned": source["unsigned"],
            "source_auto_increment": source["auto_increment"], "source_pg_type": source["pg_type"],
            "source_column_type": source["column_type"].lower(), "source_precision": source["precision"],
            "source_scale": source["scale"], "source_nullable": source["nullable"],
            "source_default_is_null": source["default"] is None, "source_extra": source["extra"].lower(),
            "source_generated": source["generated"],
            "referenced_data_type": referenced["data_type"], "referenced_unsigned": referenced["unsigned"],
            "referenced_auto_increment": referenced["auto_increment"],
            "referenced_column_type": referenced["column_type"].lower(),
            "referenced_precision": referenced["precision"], "referenced_scale": referenced["scale"],
            "referenced_nullable": referenced["nullable"],
            "referenced_default_is_null": referenced["default"] is None,
            "referenced_extra": referenced["extra"].lower(), "referenced_generated": referenced["generated"],
            "referenced_pg_type": referenced["pg_type"],
        }
        if actual != {name: value for name, value in expected.items()
                      if name not in {"target_pg_type", "rule", "expected_source_counts"}}:
            raise RuntimeError("foreign key type normalization metadata differs from the reviewed contract")
        record = {"table": key[0], "column": key[1], "referenced_table": key[2],
                  "referenced_column": key[3], "source_pg_type": source["pg_type"],
                  "referenced_pg_type": referenced["pg_type"], "target_pg_type": expected["target_pg_type"],
                  "rule": expected["rule"]}
        if expected["rule"] == "narrow_unsigned_bigint_with_verified_range":
            col = mysql_qi(key[1])
            row = query(conn, f"SELECT COUNT(*),COALESCE(SUM({col} IS NULL),0),"
                               f"COALESCE(SUM({col}<>TRUNCATE({col},0)),0),"
                               f"COALESCE(SUM({col}<0),0),"
                               f"COALESCE(SUM({col}>9223372036854775807),0) "
                               f"FROM {mysql_qi(key[0])}")[0]
            counts = {"rows": int(row[0]), "nulls": int(row[1]), "fractional": int(row[2]),
                      "negative": int(row[3]), "outside_signed_bigint": int(row[4])}
            if counts != expected["expected_source_counts"]:
                raise RuntimeError("unsigned foreign key values exceed the approved signed bigint range")
            if not source["nullable"] and counts["nulls"]:
                raise RuntimeError("non-null foreign key source contains null values")
            record["source_counts"] = counts
        source["pg_type"] = expected["target_pg_type"]
        records.append(record)
    if any(column_lookup[(key[0], key[1])]["pg_type"] != column_lookup[(key[2], key[3])]["pg_type"]
           for key in pairs):
        raise RuntimeError("foreign key PostgreSQL types remain incompatible after normalization")
    return records


def routines_inventory(conn):
    routines = query(conn, """
        SELECT ROUTINE_NAME,ROUTINE_TYPE,DATA_TYPE,DTD_IDENTIFIER,IS_DETERMINISTIC,SQL_DATA_ACCESS,SQL_MODE
        FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=%s ORDER BY ROUTINE_NAME
    """, (SCHEMA,))
    params = query(conn, """
        SELECT SPECIFIC_NAME,ORDINAL_POSITION,PARAMETER_NAME,PARAMETER_MODE,DATA_TYPE,DTD_IDENTIFIER
        FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA=%s
        ORDER BY SPECIFIC_NAME,ORDINAL_POSITION
    """, (SCHEMA,))
    param_map = defaultdict(list)
    for specific, position, name, mode, typ, dtd in params:
        if name is not None:
            param_map[specific].append({"position": int(position), "name": name, "mode": mode, "type": typ, "dtd": dtd})
    return [{"name": r[0], "type": r[1], "return_data_type": r[2], "return_dtd": r[3],
             "deterministic": r[4], "data_access": r[5], "sql_mode": r[6],
             "parameters": param_map[r[0]]} for r in routines]


def verified_routine_snapshot(snapshot_path):
    expected_path = Path(__file__).with_name("expected_routines.json")
    expected_bytes = expected_path.read_bytes()
    expected = json.loads(expected_bytes)
    observed = json.loads(Path(snapshot_path).read_text(encoding="utf-8"))
    if observed != expected or expected.get("schema") != SCHEMA or len(expected.get("routines", [])) != 2:
        raise RuntimeError("stored procedure definitions differ from approved metadata fingerprint")
    return expected["routines"], hashlib.sha256(expected_bytes).hexdigest()


def routine_parameter_signature(routine):
    return [(int(p["position"]), p["name"], p["mode"], p["type"], p["dtd"])
            for p in routine["parameters"]]


def triggers_inventory(conn):
    rows = query(conn, """
        SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,
               ACTION_ORIENTATION,ACTION_CONDITION,SQL_MODE,
               SHA2(ACTION_STATEMENT,256),OCTET_LENGTH(ACTION_STATEMENT)
        FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=%s ORDER BY TRIGGER_NAME
    """, (SCHEMA,))
    return [{"name": r[0], "timing": r[1], "event": r[2], "table": r[3],
             "orientation": r[4], "condition": r[5], "sql_mode": r[6],
            "definition_sha256": r[7], "definition_bytes": int(r[8]) if r[8] is not None else None}
            for r in rows]


def validate_trigger_snapshot(triggers):
    actual = {t["name"]: (t["table"], t["timing"], t["event"], t["orientation"], t["condition"],
                           t["sql_mode"], t["definition_sha256"], t["definition_bytes"])
              for t in triggers}
    if actual != EXPECTED_TRIGGER_METADATA:
        raise RuntimeError("trigger definitions differ from approved rehearsal snapshot")


def validate_on_update_snapshot(columns, manifest_path=None):
    path = Path(manifest_path) if manifest_path is not None else Path(__file__).with_name("expected_on_update_columns.json")
    raw = path.read_bytes()
    expected = json.loads(raw)
    if expected.get("schema") != SCHEMA or expected.get("count") != 111:
        raise RuntimeError("ON UPDATE CURRENT_TIMESTAMP manifest metadata differs from approved rehearsal snapshot")
    expected_columns = expected.get("columns")
    if (not isinstance(expected_columns, list) or len(expected_columns) != expected["count"]
            or len(set(expected_columns)) != expected["count"]):
        raise RuntimeError("ON UPDATE CURRENT_TIMESTAMP manifest metadata differs from approved rehearsal snapshot")
    if (len(columns) != expected["count"] or len(set(columns)) != expected["count"]
            or set(columns) != set(expected_columns)):
        raise RuntimeError("ON UPDATE CURRENT_TIMESTAMP column list differs from approved rehearsal snapshot")
    return hashlib.sha256(raw).hexdigest()


def events_inventory(conn):
    return query(conn, "SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=%s", (SCHEMA,))[0][0]


def zero_value_counts(conn, tables, columns):
    result = {}
    for table, cols in columns.items():
        temporal = [c for c in cols if c["data_type"] in TEMPORAL_TYPES]
        if not temporal:
            continue
        exprs = [f"SUM(CASE WHEN CAST({mysql_qi(c['name'])} AS CHAR) REGEXP %s THEN 1 ELSE 0 END)" for c in temporal]
        params = tuple(r"^(0000|[0-9]{4}-00-|[0-9]{4}-[0-9]{2}-00)" for _ in temporal)
        row = query(conn, f"SELECT {', '.join(exprs)} FROM {mysql_qi(table)}", params)[0]
        for col, count in zip(temporal, row, strict=True):
            n = int(count or 0)
            if n:
                result[f"{table}.{col['name']}"] = n
    return result


def time_conversion_risks(conn, columns):
    result = {}
    for table, cols in columns.items():
        times = [c for c in cols if c["data_type"] == "time"]
        if not times:
            continue
        expressions = [f"SUM(CASE WHEN {mysql_qi(c['name'])} IS NOT NULL AND "
                       f"(TIME_TO_SEC({mysql_qi(c['name'])}) < 0 OR TIME_TO_SEC({mysql_qi(c['name'])}) >= 86400 "
                       f"OR MICROSECOND({mysql_qi(c['name'])}) <> 0) THEN 1 ELSE 0 END)" for c in times]
        row = query(conn, f"SELECT {', '.join(expressions)} FROM {mysql_qi(table)}")[0]
        for col, count in zip(times, row, strict=True):
            if int(count or 0):
                result[f"{table}.{col['name']}"] = int(count)
    return result


def invalid_enum_counts(conn, columns):
    result = {}
    for table, cols in columns.items():
        enums = [c for c in cols if c["data_type"] == "enum"]
        if not enums:
            continue
        exprs = []
        for col in enums:
            name = mysql_qi(col["name"])
            allowed = ", ".join("CAST(CONVERT(X'" + value.encode("utf-8").hex() + "' USING utf8mb4) AS BINARY)" for value in col["domain_values"])
            exprs.extend([f"SUM(CASE WHEN {name}+0 = 0 THEN 1 ELSE 0 END)",
                          f"SUM(CASE WHEN {name}+0 = 0 OR BINARY {name} NOT IN ({allowed}) THEN 1 ELSE 0 END)"])
        row = query(conn, f"SELECT {', '.join(exprs)} FROM {mysql_qi(table)}")[0]
        for index, col in enumerate(enums):
            empty_count, outside_count = int(row[index * 2] or 0), int(row[index * 2 + 1] or 0)
            if empty_count or outside_count:
                name = mysql_qi(col["name"])
                allowed = ", ".join("CAST(CONVERT(X'" + value.encode("utf-8").hex() + "' USING utf8mb4) AS BINARY)"
                                     for value in col["domain_values"])
                observed = query(conn, f"SELECT DISTINCT HEX(CONVERT({name} USING utf8mb4)) FROM {mysql_qi(table)} "
                                       f"WHERE {name}+0=0 OR BINARY {name} NOT IN ({allowed})")
                values = []
                for value_row in observed:
                    encoded = value_row[0]
                    if encoded is None:
                        raise RuntimeError("NULL encountered while inspecting ENUM domain")
                    try:
                        value = bytes.fromhex(str(encoded)).decode("utf-8", errors="strict")
                    except (ValueError, UnicodeDecodeError) as exc:
                        raise RuntimeError("ENUM value is not valid UTF-8") from exc
                    if value not in values:
                        values.append(value)
                if outside_count and not values:
                    raise RuntimeError("ENUM outside-domain count could not be reconciled to values")
                result[f"{table}.{col['name']}"] = {"empty_enum_values": empty_count,
                                                      "out_of_domain_values": outside_count,
                                                      "observed_values": values}
    return result


def enum_counts_for_summary(invalid):
    return {key: {"empty_enum_values": item["empty_enum_values"],
                  "out_of_domain_values": item["out_of_domain_values"]}
            for key, item in invalid.items()}


def identity_range_failures(conn, columns):
    failures = []
    pg_max = 9223372036854775807
    for table, cols in columns.items():
        for col in cols:
            if not col["auto_increment"]:
                continue
            value = query(conn, f"SELECT MAX({mysql_qi(col['name'])}) FROM {mysql_qi(table)}")[0][0]
            if value is not None and int(value) >= pg_max:
                failures.append(f"{table}.{col['name']}")
    return failures


def text_nul_counts_for_table(conn, table, columns):
    textual_types = {"char", "varchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set"}
    textual = [c for c in columns if c["data_type"] in textual_types]
    json_cols = [c for c in columns if c["data_type"] == "json"]
    result = {}
    if textual:
        expressions = [f"SUM(CASE WHEN INSTR(BINARY {mysql_qi(c['name'])}, 0x00) > 0 THEN 1 ELSE 0 END)"
                       for c in textual]
        values = query(conn, f"SELECT {', '.join(expressions)} FROM {mysql_qi(table)}")[0]
        result.update({f"{table}.{col['name']}": int(count) for col, count in zip(textual, values, strict=True)
                       if int(count or 0)})
    for col in json_cols:
        count = 0
        cursor = conn.cursor(pymysql.cursors.SSCursor)
        try:
            cursor.execute(f"SELECT {mysql_qi(col['name'])} FROM {mysql_qi(table)} WHERE {mysql_qi(col['name'])} IS NOT NULL")
            for (value,) in cursor:
                if json_contains_nul(value):
                    count += 1
        finally:
            cursor.close()
        if count:
            result[f"{table}.{col['name']}"] = count
    return result


def text_nul_counts(conn, columns):
    result = {}
    for table, cols in columns.items():
        result.update(text_nul_counts_for_table(conn, table, cols))
    return result


def trigger_tenant_reference_counts(conn):
    checks = {
        "qrcode_token_access_tenant_mismatches": """SELECT COUNT(*) FROM qrcode_tokens q
            JOIN acessos_visitantes a ON a.id=q.acesso_id WHERE q.tenant_id<>a.tenant_id""",
        "device_log_device_tenant_mismatches": """SELECT COUNT(*) FROM logs_validacoes_dispositivo l
            JOIN dispositivos_tablets d ON d.id=l.dispositivo_id WHERE l.tenant_id<>d.tenant_id""",
        "dependent_vehicle_tenant_mismatches": """SELECT COUNT(*) FROM veiculos v
            JOIN dependentes d ON d.id=v.dependente_id WHERE v.dependente_id IS NOT NULL
            AND v.tenant_id<>d.tenant_id""",
    }
    return {name: int(query(conn, statement)[0][0] or 0) for name, statement in checks.items()}


def tenant_contract(tables, columns):
    tenant_lines = []
    no_tenant_lines = []
    tenant_tables = []
    for table in sorted(tables):
        col = next((c for c in columns[table] if c["name"] == "tenant_id"), None)
        if col is None:
            no_tenant_lines.append(f"N|{table}")
            continue
        default = col["default"]
        if isinstance(default, bytes):
            default = default.decode("utf-8", "strict")
        tenant_lines.append(
            f"T|{table}|{col['column_type']}|{'YES' if col['nullable'] else 'NO'}|{default if default is not None else '<NULL>'}"
        )
        tenant_tables.append(table)
    tenant_hash = hashlib.sha256("\n".join(tenant_lines).encode("utf-8")).hexdigest()
    no_tenant_hash = hashlib.sha256("\n".join(no_tenant_lines).encode("utf-8")).hexdigest()
    return tenant_tables, no_tenant_lines, tenant_hash, no_tenant_hash


SENSITIVE_DEFAULTS_ALLOWLIST = {
    ("face_descriptors", "token_usado"): ("tinyint", "0"),
    ("moradores", "senha_temporaria"): ("tinyint", "0"),
    ("pwa_notificacoes_push", "total_tokens"): ("int", "0"),
    ("pwa_oauth_cache", "token_type"): ("varchar", "Bearer"),
}


def validate_sensitive_column_defaults(columns):
    pattern = re.compile(r"(?:senha|password|token|secret|hash|api[_-]?key|private[_-]?key)", re.I)
    observed = set()
    for table, cols in columns.items():
        for col in cols:
            default = col["default"]
            if not pattern.search(col["name"]) or default in (None, "", "NULL", "null"):
                continue
            key = (table, col["name"])
            expected = SENSITIVE_DEFAULTS_ALLOWLIST.get(key)
            if isinstance(default, bytes):
                default = default.decode("utf-8", "strict")
            if expected is None or col["data_type"] != expected[0] or str(default) != expected[1]:
                raise RuntimeError("sensitive column defaults require manual review before DDL generation")
            observed.add(key)
    if observed != set(SENSITIVE_DEFAULTS_ALLOWLIST):
        raise RuntimeError("sensitive column defaults require manual review before DDL generation")


def source_size(conn, tables):
    total = sum(x["data_length"] + x["index_length"] for x in tables.values())
    return total


def safe_default(col, zero_cols):
    value = col["default"]
    if value is None:
        return None
    text = str(value)
    if "default_generated" in col["extra"].lower() and not re.fullmatch(r"CURRENT_TIMESTAMP(?:\(\d*\))?", text, re.I):
        raise ValueError("generated DEFAULT expression requires explicit translation")
    if re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*\s*\(.*\)", text) and not re.fullmatch(r"CURRENT_TIMESTAMP(?:\(\d*\))?", text, re.I):
        raise ValueError("function DEFAULT expression requires explicit translation")
    if col["data_type"] in TEMPORAL_TYPES and re.match(r"^(0000|\d{4}-00-|\d{4}-\d{2}-00)", text):
        return "NULL"
    if col["data_type"] == "date" and re.fullmatch(r"CURRENT_TIMESTAMP(?:\(\d*\))?", text, re.I):
        return "CURRENT_DATE"
    if col["data_type"] in {"datetime", "timestamp"} and re.fullmatch(r"CURRENT_TIMESTAMP(?:\(\d*\))?", text, re.I):
        return "LOCALTIMESTAMP(0)"
    if col["data_type"] == "time" and re.fullmatch(r"CURRENT_TIMESTAMP(?:\(\d*\))?", text, re.I):
        raise ValueError("CURRENT_TIMESTAMP default on TIME needs explicit review")
    if col["data_type"] in {"tinyint", "smallint", "mediumint", "int", "integer", "bigint", "decimal", "float", "double"}:
        if not re.fullmatch(r"[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?", text):
            raise ValueError("unsupported numeric default expression")
        return text
    if col["data_type"] in {"date", "datetime", "timestamp", "time"}:
        if text.upper() == "NULL":
            return "NULL"
        return ql(text)
    if col["data_type"] == "json":
        return ql(text) + "::json"
    return ql(text)


def expression_sql(expression: str) -> str:
    expr = expression.strip()
    if expr.startswith("(") and expr.endswith(")"):
        expr = expr[1:-1].strip()
    m = re.fullmatch(r"`?([A-Za-z_][A-Za-z0-9_]*)`?\s*([-+*/])\s*`?([A-Za-z_][A-Za-z0-9_]*)`?", expr)
    if not m:
        raise ValueError("unsupported generated-column expression")
    return f"{qi(m.group(1))} {m.group(2)} {qi(m.group(3))}"


def build_sql(catalog: dict, target_database: str = V10D_DATABASE) -> dict[str, str]:
    if target_database != V10D_DATABASE:
        raise ValueError("DDL generation is restricted to the exclusive v10d database")
    tables, columns, indexes, fks = (catalog[k] for k in ("tables", "columns", "indexes", "foreign_keys"))
    names = {}
    for t, info in tables.items():
        schema = info["schema"]
        names[t] = (schema, pg_name(t))
    seen = {}
    for t, (schema, name) in names.items():
        key = (schema, name)
        if key in seen:
            raise ValueError("PostgreSQL identifier collision")
        seen[key] = t
    target_database = pg_name(target_database)
    zero_cols = set(catalog["zero_value_counts"])
    invalid = catalog["invalid_enum_counts"]
    s001 = ["BEGIN;", "DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='erp_owner') THEN CREATE ROLE erp_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS; ELSIF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='erp_owner' AND (rolcanlogin OR rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls)) THEN RAISE EXCEPTION 'erp_owner role is unsafe'; END IF; END $$;",
            "DO $$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='erp_app') THEN CREATE ROLE erp_app NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS; ELSIF EXISTS (SELECT 1 FROM pg_roles WHERE rolname='erp_app' AND (rolcanlogin OR rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls)) THEN RAISE EXCEPTION 'erp_app role is unsafe'; END IF; END $$;",
            "DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname IN ('erp_owner','erp_app') AND (rolinherit OR COALESCE(cardinality(rolconfig),0)>0)) THEN RAISE EXCEPTION 'ERP role inheritance or global configuration is unsafe'; END IF; IF EXISTS (SELECT 1 FROM pg_auth_members m JOIN pg_roles r ON r.oid IN (m.roleid,m.member) WHERE r.rolname IN ('erp_owner','erp_app')) THEN RAISE EXCEPTION 'ERP role memberships require manual review'; END IF; END $$;",
            "CREATE SCHEMA IF NOT EXISTS erp;", "CREATE SCHEMA IF NOT EXISTS legado;",
            "ALTER SCHEMA erp OWNER TO erp_owner;", "ALTER SCHEMA legado OWNER TO erp_owner;",
            "CREATE COLLATION IF NOT EXISTS erp.ci (provider = icu, locale = 'und-u-ks-level2', deterministic = false);", ""]
    s002 = ["BEGIN;"]
    s002_fk = []
    s002_validate = ["BEGIN;"]
    s003 = ["BEGIN;"]
    s004 = ["BEGIN;"]
    s005 = ["BEGIN;"]
    s006 = ["BEGIN;", "ALTER SCHEMA erp OWNER TO erp_owner;", "ALTER SCHEMA legado OWNER TO erp_owner;"]
    auto_items = []
    update_items = []
    for table in sorted(tables):
        schema, tname = names[table]
        qtable = f"{qi(schema)}.{qi(tname)}"
        coldefs = []
        for col in columns[table]:
            c = qi(pg_name(col["name"]))
            if col["generated"]:
                gen = expression_sql(col["generation_expression"])
                mode = "VIRTUAL" if "virtual generated" in col["extra"].lower() else "STORED"
                collation = f" COLLATE {col['collate']}" if col["collate"] else ""
                not_null = " NOT NULL" if not col["nullable"] else ""
                coldefs.append(f"    {c} {col['pg_type']}{collation} GENERATED ALWAYS AS ({gen}) {mode}{not_null}")
                continue
            pgtype = col["pg_type"]
            nullable = col["nullable"]
            zero_count = catalog["zero_value_counts"].get(f"{table}.{col['name']}", 0)
            zero_default = col["data_type"] in TEMPORAL_TYPES and col["default"] is not None and re.match(r"^(0000|\d{4}-00-|\d{4}-\d{2}-00)", str(col["default"]))
            if zero_count or zero_default:
                nullable = True
            default = safe_default(col, zero_cols)
            parts = [f"    {c} {pgtype}"]
            if col["collate"]:
                parts.extend(["COLLATE", col["collate"]])
            if col["data_type"] == "enum":
                values = list(col["domain_values"])
                for observed in invalid.get(f"{table}.{col['name']}", {}).get("observed_values", []):
                    if observed not in values:
                        values.append(observed)
                literal_list = ", ".join(ql(v) for v in values)
                cname = generated_name("ck", tname, col["name"], "enum")
                parts.append(f"CONSTRAINT {qi(cname)} CHECK ({c} IN ({literal_list}))")
            if col["unsigned"]:
                cname = generated_name("ck", tname, col["name"], "unsigned")
                limits = {"tinyint": "255", "smallint": "65535", "mediumint": "16777215", "int": "4294967295", "integer": "4294967295", "bigint": "18446744073709551615"}
                upper = limits.get(col["data_type"])
                if col["data_type"] == "bigint" and col["auto_increment"]:
                    parts.append(f"CONSTRAINT {qi(cname)} CHECK ({c} >= 0)")
                elif upper:
                    parts.append(f"CONSTRAINT {qi(cname)} CHECK ({c} >= 0 AND {c} <= {upper})")
                elif col["data_type"] in {"decimal", "float", "double"}:
                    parts.append(f"CONSTRAINT {qi(cname)} CHECK ({c} >= 0)")
            elif col["data_type"] in {"tinyint", "mediumint"}:
                lower, upper = {"tinyint": ("-128", "127"), "mediumint": ("-8388608", "8388607")}[col["data_type"]]
                cname = generated_name("ck", tname, col["name"], "signed_range")
                parts.append(f"CONSTRAINT {qi(cname)} CHECK ({c} >= {lower} AND {c} <= {upper})")
            if default is not None:
                parts.append(f"DEFAULT {default}")
            if not nullable:
                parts.append("NOT NULL")
            if col["auto_increment"]:
                parts.append("GENERATED BY DEFAULT AS IDENTITY")
                auto_items.append((schema, tname, pg_name(col["name"])))
            coldefs.append(" ".join(parts))
            if re.search(r"on update current_timestamp", col["extra"], re.I):
                update_items.append((schema, tname, pg_name(col["name"])))
        s001.append(f"CREATE TABLE {qi(schema)}.{qi(tname)} (\n" + ",\n".join(coldefs) + "\n);")
        s001.append(f"ALTER TABLE {qtable} OWNER TO erp_owner;")
        for idx_name, parts in indexes.get(table, {}).items():
            if any(part["type"] != "BTREE" for part in parts):
                raise ValueError("non-BTREE index requires explicit compatibility mapping")
            if idx_name == "PRIMARY":
                kind = "PRIMARY KEY"
            elif not parts[0]["non_unique"]:
                kind = "UNIQUE"
            else:
                kind = "INDEX"
            cols_sql = []
            for part in parts:
                if not part["column"]:
                    raise ValueError("functional MySQL index requires explicit review")
                cname = qi(pg_name(part["column"]))
                if part["sub_part"]:
                    col_type = next(c["data_type"] for c in columns[table] if c["name"] == part["column"])
                    if col_type in {"tinyblob", "blob", "mediumblob", "longblob", "binary", "varbinary"}:
                        cname = f"substring({cname} from 1 for {part['sub_part']})"
                    else:
                        cname = f"left({cname}, {part['sub_part']})"
                if part["direction"] == "D":
                    cname += " DESC"
                cols_sql.append(cname)
            if kind == "PRIMARY KEY":
                s002.append(f"ALTER TABLE {qtable} ADD CONSTRAINT {qi(generated_name(tname, 'pkey'))} PRIMARY KEY ({', '.join(cols_sql)});")
            elif kind == "UNIQUE":
                iname = generated_name("ux", tname, idx_name)
                s002.append(f"CREATE UNIQUE INDEX {qi(iname)} ON {qtable} ({', '.join(cols_sql)});")
            else:
                iname = generated_name("ix", tname, idx_name)
                s002.append(f"CREATE INDEX {qi(iname)} ON {qtable} ({', '.join(cols_sql)});")
    fk_normalizations = catalog.get("fk_type_normalizations", [])
    if fk_normalizations:
        s002_fk.append(
            "DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace "
            "WHERE c.contype='f' AND n.nspname='erp') THEN "
            "RAISE EXCEPTION 'foreign key type normalization requires zero existing foreign keys'; END IF; END $$;"
        )
        for item in fk_normalizations:
            schema, table = "erp", item["table"]
            col = item["column"]
            ref_table, ref_col = item["referenced_table"], item["referenced_column"]
            qtable = f"{qi(schema)}.{qi(table)}"
            qcol = qi(col)
            allowed_types = sorted({item["source_pg_type"], item["target_pg_type"]})
            allowed = ", ".join(ql(value) for value in allowed_types)
            s002_fk.append(
                "DO $$ DECLARE actual_type text; BEGIN "
                f"SELECT format_type(a.atttypid,a.atttypmod) INTO actual_type FROM pg_attribute a "
                f"WHERE a.attrelid={ql(qi(schema) + '.' + qi(table))}::regclass AND a.attname={ql(col)} "
                "AND a.attnum>0 AND NOT a.attisdropped; "
                f"IF actual_type IS NULL OR actual_type NOT IN ({allowed}) THEN "
                "RAISE EXCEPTION 'foreign key source type differs from the reviewed normalization'; END IF; END $$;"
            )
            s002_fk.append(
                "DO $$ DECLARE actual_type text; BEGIN "
                f"SELECT format_type(a.atttypid,a.atttypmod) INTO actual_type FROM pg_attribute a "
                f"WHERE a.attrelid={ql('erp.' + ref_table)}::regclass AND a.attname={ql(ref_col)} "
                "AND a.attnum>0 AND NOT a.attisdropped; "
                f"IF actual_type IS DISTINCT FROM {ql(item['referenced_pg_type'])} THEN "
                "RAISE EXCEPTION 'foreign key referenced type differs from the reviewed normalization'; END IF; END $$;"
            )
            if item["rule"] == "narrow_unsigned_bigint_with_verified_range":
                s002_fk.append(
                    f"DO $$ BEGIN IF (SELECT COUNT(*) FROM {qtable}) <> 791 OR EXISTS "
                    f"(SELECT 1 FROM {qtable} WHERE {qcol} IS NULL OR "
                    f"({qcol}::numeric <> trunc({qcol}::numeric,0) OR {qcol} < 0 "
                    f"OR {qcol} > 9223372036854775807)) THEN "
                    "RAISE EXCEPTION 'unsigned foreign key values exceed the approved signed bigint range'; END IF; END $$;"
                )
            s002_fk.append(f"ALTER TABLE {qtable} ALTER COLUMN {qcol} TYPE bigint USING {qcol}::bigint;")
            if item["rule"] == "widen_signed_integer_foreign_key":
                check_name = generated_name("ck", table, col, "mysql_signed_int_range")
                s002_fk.append(
                    f"ALTER TABLE {qtable} ADD CONSTRAINT {qi(check_name)} "
                    f"CHECK ({qcol} BETWEEN -2147483648 AND 2147483647);"
                )
    for fk in fks:
        if tables[fk["table"]]["schema"] != "erp" or tables[fk["columns"][0]["ref_table"]]["schema"] != "erp":
            raise ValueError("foreign key crossing the legacy schema boundary requires explicit review")
        referenced_table = fk["columns"][0]["ref_table"]
        referenced_columns = [c["ref_column"] for c in fk["columns"]]
        candidate_keys = [parts for name, parts in indexes.get(referenced_table, {}).items()
                          if (name == "PRIMARY" or not parts[0]["non_unique"])
                          and [p["column"] for p in parts] == referenced_columns
                          and all(not p["sub_part"] for p in parts)]
        if not candidate_keys:
            raise ValueError("foreign key target is not a complete unique key in PostgreSQL")
        src_schema, src_table = names[fk["table"]]
        ref_schema, ref_table = names[fk["columns"][0]["ref_table"]]
        srccols = ", ".join(qi(pg_name(c["column"])) for c in fk["columns"])
        refcols = ", ".join(qi(pg_name(c["ref_column"])) for c in fk["columns"])
        name = generated_name("fk", src_table, fk["name"])
        actions = {"RESTRICT": "RESTRICT", "CASCADE": "CASCADE", "SET NULL": "SET NULL", "NO ACTION": "NO ACTION", "SET DEFAULT": "SET DEFAULT"}
        update = actions.get(fk["columns"][0]["on_update"])
        delete = actions.get(fk["columns"][0]["on_delete"])
        if update is None or delete is None:
            raise ValueError("foreign key action requires explicit compatibility mapping")
        s002_fk.append(f"ALTER TABLE {qi(src_schema)}.{qi(src_table)} ADD CONSTRAINT {qi(name)} FOREIGN KEY ({srccols}) REFERENCES {qi(ref_schema)}.{qi(ref_table)} ({refcols}) ON UPDATE {update} ON DELETE {delete} NOT VALID;")
        s002_validate.append(f"ALTER TABLE {qi(src_schema)}.{qi(src_table)} VALIDATE CONSTRAINT {qi(name)};")
    for schema, table, col in auto_items:
        qtable = f"{qi(schema)}.{qi(table)}"
        s003.append(f"SELECT setval(pg_get_serial_sequence({ql(qi(schema) + '.' + qi(table))}, {ql(col)}), COALESCE((SELECT MAX({qi(col)}) FROM {qtable}), 0) + 1, false);")
    for schema, table, col in update_items:
        fn = generated_name("touch", table, col)
        trg = generated_name("trg_touch", table, col)
        unchanged_others = f"(to_jsonb(NEW) - {ql(col)}) IS DISTINCT FROM (to_jsonb(OLD) - {ql(col)})"
        body = f"BEGIN IF NEW.{qi(col)} IS NOT DISTINCT FROM OLD.{qi(col)} AND {unchanged_others} THEN NEW.{qi(col)} := LOCALTIMESTAMP(0); END IF; RETURN NEW; END"
        s004.extend([f"CREATE OR REPLACE FUNCTION {qi('erp')}.{qi(fn)}() RETURNS trigger LANGUAGE plpgsql AS $$ {body} $$;",
                     f"ALTER FUNCTION {qi('erp')}.{qi(fn)}() OWNER TO erp_owner;",
                     f"CREATE TRIGGER {qi(trg)} BEFORE UPDATE ON {qi(schema)}.{qi(table)} FOR EACH ROW EXECUTE FUNCTION {qi('erp')}.{qi(fn)}();"])
    s006.extend([f"GRANT CONNECT ON DATABASE {qi(target_database)} TO erp_app;",
                 "GRANT USAGE ON SCHEMA erp TO erp_app;", "GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA erp TO erp_app;",
                 "GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA erp TO erp_app;",
                 f"ALTER ROLE erp_app IN DATABASE {qi(target_database)} SET search_path = erp;",
                 f"ALTER ROLE erp_app IN DATABASE {qi(target_database)} SET timezone = 'America/Sao_Paulo';",
                 "ALTER DEFAULT PRIVILEGES FOR ROLE erp_owner IN SCHEMA erp GRANT SELECT,INSERT,UPDATE,DELETE ON TABLES TO erp_app;",
                 "ALTER DEFAULT PRIVILEGES FOR ROLE erp_owner IN SCHEMA erp GRANT USAGE,SELECT ON SEQUENCES TO erp_app;",
                 "REVOKE ALL ON SCHEMA public FROM PUBLIC, erp_app;",
                 "REVOKE ALL ON SCHEMA legado FROM PUBLIC, erp_app;",
                 "REVOKE ALL ON ALL TABLES IN SCHEMA legado FROM PUBLIC, erp_app;",
                 "REVOKE ALL ON ALL SEQUENCES IN SCHEMA legado FROM PUBLIC, erp_app;"])
    for s in (s001, s002, s002_fk, s002_validate, s003, s004, s005, s006):
        if s is not s002_fk:
            s.append("COMMIT;")
    business_sql_path = Path(__file__).with_name("business_objects.sql")
    if not business_sql_path.is_file():
        raise ValueError("manual business trigger translation is missing")
    business_sql = business_sql_path.read_text(encoding="utf-8")
    return {"001_schema.sql": "\n".join(s001) + "\n", "002_constraints_indexes.sql": "\n".join(s002) + "\n",
            "002_foreign_keys.sql": "\n".join(s002_fk) + "\n",
            "002_validate_constraints.sql": "\n".join(s002_validate) + "\n", "003_identity_sequences.sql": "\n".join(s003) + "\n", "004_on_update_timestamp.sql": "\n".join(s004) + "\n",
            "005_business_triggers_routines.sql": business_sql, "006_grants.sql": "\n".join(s006) + "\n"}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--mysql-config", required=True)
    ap.add_argument("--output", required=True)
    ap.add_argument("--summary", required=True)
    ap.add_argument("--repo-root", required=True)
    ap.add_argument("--target-database", required=True)
    ap.add_argument("--routine-snapshot", required=True)
    args = ap.parse_args()
    require_v10d_database(args.target_database)
    conn = mysql_connection(args.mysql_config)
    try:
        tables = table_inventory(conn)
        legacy_decisions = classify_legacy_tables(tables, args.repo_root)
        object_types = object_type_counts(conn)
        columns = columns_inventory(conn)
        collation_counts = defaultdict(int)
        for cols in columns.values():
            for col in cols:
                if col["collation"] is not None:
                    collation_counts[col["collation"]] += 1
        if dict(collation_counts) != EXPECTED_COLLATION_COUNTS:
            raise RuntimeError("source column collation inventory differs from approved rehearsal snapshot")
        indexes = indexes_inventory(conn)
        validate_checklist_upsert_contract(indexes)
        fks = foreign_keys_inventory(conn)
        validate_foreign_key_snapshot(fks, tables)
        fk_type_normalizations = normalize_fk_types(conn, columns, fks)
        triggers = triggers_inventory(conn)
        validate_trigger_snapshot(triggers)
        routines, routine_snapshot_sha256 = verified_routine_snapshot(args.routine_snapshot)
        events = int(events_inventory(conn))
        if len(tables) != 270 or object_types != {"BASE TABLE": 270} or sum(len(v) for v in columns.values()) != 3339:
            raise RuntimeError("catalog shape differs from approved rehearsal snapshot")
        if (len(fks) != 11 or len(triggers) != 7 or len([r for r in routines if r["type"] == "PROCEDURE"]) != 2
                or len([r for r in routines if r["type"] == "FUNCTION"]) != 0 or events != 0):
            raise RuntimeError("catalog objects differ from approved rehearsal snapshot")
        if {r["name"] for r in routines if r["type"] == "PROCEDURE"} != EXPECTED_PROCEDURES:
            raise RuntimeError("trigger or routine names differ from approved rehearsal snapshot")
        routine_map = {r["name"]: r for r in routines}
        mt_signature = routine_parameter_signature(routine_map["mt_adicionar_coluna"])
        if (mt_signature != [(1, "p_tabela", "IN", "varchar", "varchar(64)"),
                             (2, "p_coluna", "IN", "varchar", "varchar(64)"),
                             (3, "p_definicao", "IN", "text", "text")]
                or routine_parameter_signature(routine_map["corrigir_moradores_anexos_tenant"])):
            raise RuntimeError("stored procedure signature differs from approved rehearsal snapshot")
        if any(t["engine"] != "InnoDB" for t in tables.values()):
            raise RuntimeError("non-InnoDB source table requires explicit review")
        auto_count = sum(c["auto_increment"] for cs in columns.values() for c in cs)
        no_pk_tables = [t for t in tables if "PRIMARY" not in indexes.get(t, {})]
        on_update_columns = sorted(f"{t}.{c['name']}" for t, cs in columns.items() for c in cs
                                   if re.search(r"on update current_timestamp", c["extra"], re.I))
        on_update_snapshot_sha256 = validate_on_update_snapshot(on_update_columns)
        if auto_count != 232 or len(no_pk_tables) != 30:
            raise RuntimeError("identity, primary-key, or on-update counts differ from approved rehearsal snapshot")
        tenant_tables, no_tenant_lines, tenant_hash, no_tenant_hash = tenant_contract(tables, columns)
        no_tenant_tables = [line.removeprefix("N|") for line in no_tenant_lines]
        if (len(tenant_tables) != 228 or len(no_tenant_tables) != 42
                or tenant_hash != EXPECTED_TENANT_SIGNATURE_SHA256
                or no_tenant_hash != EXPECTED_NO_TENANT_NAMES_SHA256):
            raise RuntimeError("tenant_id coverage differs from approved rehearsal snapshot")
        validate_sensitive_column_defaults(columns)
        zero_counts = zero_value_counts(conn, tables, columns)
        time_risks = time_conversion_risks(conn, columns)
        if time_risks:
            raise RuntimeError("MySQL TIME values are outside the approved PostgreSQL time(0) range")
        invalid_enum = invalid_enum_counts(conn, columns)
        trigger_tenant_mismatches = trigger_tenant_reference_counts(conn)
        if any(trigger_tenant_mismatches.values()):
            raise RuntimeError("trigger-related tenant references require review before translation")
        identity_failures = identity_range_failures(conn, columns)
        if identity_failures:
            raise RuntimeError("AUTO_INCREMENT values exceed PostgreSQL bigint on " + ",".join(identity_failures))
        nul_counts = text_nul_counts(conn, columns)
        if nul_counts:
            Path(args.summary).write_text(json.dumps({"status": "blocked_textual_nul", "text_nul_counts": nul_counts,
                                                      "zero_date_counts": zero_counts, "invalid_enum_counts": invalid_enum},
                                                     ensure_ascii=False, indent=2) + "\n")
            Path(args.summary).chmod(0o600)
            raise RuntimeError("textual NUL requires user decision; counts written to private summary")
        catalog = {"tables": tables, "object_types": object_types, "legacy_decisions": legacy_decisions,
                   "columns": columns, "indexes": indexes, "foreign_keys": fks,
                   "fk_type_normalizations": fk_type_normalizations,
                   "triggers": triggers, "routines": routines, "events": events,
                   "zero_value_counts": zero_counts, "invalid_enum_counts": invalid_enum,
                   "time_conversion_risks": time_risks,
                   "source_bytes": source_size(conn, tables)}
        sql = build_sql(catalog, args.target_database)
        out = Path(args.output)
        out.mkdir(parents=True, exist_ok=True)
        for name, body in sql.items():
            (out / name).write_text(body, encoding="utf-8")
        safe_invalid_enum = enum_counts_for_summary(invalid_enum)
        fk_normalization_json = json.dumps(fk_type_normalizations, ensure_ascii=False,
                                           sort_keys=True, separators=(",", ":"))
        summary = {"mysql_schema": SCHEMA, "object_types": object_types, "tables": len(tables), "views": 0, "columns": sum(map(len, columns.values())),
                   "indexes": sum(len(v) for v in indexes.values()), "primary_keys": sum(1 for t in indexes.values() if "PRIMARY" in t),
                   "foreign_keys": len(fks), "fk_type_normalizations": fk_type_normalizations,
                   "fk_type_normalizations_sha256": hashlib.sha256(fk_normalization_json.encode()).hexdigest(),
                   "triggers": len(triggers), "routines": len(routines), "events": events,
                   "auto_increment_columns": sum(c["auto_increment"] for cs in columns.values() for c in cs),
                   "on_update_current_timestamp_columns": on_update_columns,
                   "source_collation_counts": dict(sorted(collation_counts.items())),
                   "trigger_names": sorted(t["name"] for t in triggers),
                   "on_update_columns_sha256": on_update_snapshot_sha256,
                   "procedure_names": sorted(r["name"] for r in routines if r["type"] == "PROCEDURE"),
                   "routine_snapshot_sha256": routine_snapshot_sha256,
                   "function_names": sorted(r["name"] for r in routines if r["type"] == "FUNCTION"),
                   "set_columns": sorted(f"{t}.{c['name']}" for t, cs in columns.items() for c in cs if c["data_type"] == "set"),
                   "set_domain_value_counts": {f"{t}.{c['name']}": len(c["domain_values"])
                                               for t, cs in columns.items() for c in cs if c["data_type"] == "set"},
                   "enum_domain_value_counts": {f"{t}.{c['name']}": len(c["domain_values"])
                                                for t, cs in columns.items() for c in cs if c["data_type"] == "enum"},
                   "generated_columns": sum(c["generated"] for cs in columns.values() for c in cs),
                   "tenant_id_tables": tenant_tables, "no_tenant_id_tables": no_tenant_tables,
                   "tenant_signature_sha256": tenant_hash,
                   "no_tenant_names_sha256": no_tenant_hash,
                   "legacy_tables": sorted(t for t, decision in legacy_decisions.items() if decision["schema"] == "legado"),
                   "legacy_classification": legacy_decisions,
                   "textual_nul_counts": nul_counts,
                   "trigger_tenant_reference_mismatches": trigger_tenant_mismatches,
                   "zero_date_counts": zero_counts, "invalid_enum_counts": safe_invalid_enum,
                   "time_conversion_risks": time_risks,
                   "collate_c_columns": sorted(f"{t}.{c['name']}" for t, cs in columns.items() for c in cs if c["collate"] == '"C"'),
                   "prefix_indexes": [{"table": t, "index": name, "column": part["column"], "prefix_characters": part["sub_part"]}
                                      for t, idxs in indexes.items() for name, parts in idxs.items() for part in parts if part["sub_part"]],
                   "float_money_columns": sorted(f"{t}.{c['name']}" for t, cs in columns.items() for c in cs if c["data_type"] in {"float", "double"} and re.search(r"valor|preco|preço|custo|saldo|dinheiro|total|taxa", f"{t}.{c['name']}", re.I)),
                   "no_primary_key_tables": sorted(t for t in tables if "PRIMARY" not in indexes.get(t, {})),
                   "zero_default_cols": [f"{t}.{c['name']}" for t, cs in columns.items() for c in cs if c["data_type"] in TEMPORAL_TYPES and c["default"] is not None and re.match(r"^(0000|\d{4}-00-|\d{4}-\d{2}-00)", str(c["default"]))],
                   "source_estimated_bytes": catalog["source_bytes"],
                   "ddl_sha256": {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(out.glob("*.sql"))}}
        Path(args.summary).write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n")
    finally:
        conn.rollback()
        conn.close()

CATALOG_ERROR_CODES = {
    "backup candidate set differs from the reviewed rehearsal snapshot": "backup_candidate_set_mismatch",
    "catalog objects differ from approved rehearsal snapshot": "catalog_objects_mismatch",
    "catalog shape differs from approved rehearsal snapshot": "catalog_shape_mismatch",
    "ENUM outside-domain count could not be reconciled to values": "enum_domain_count_mismatch",
    "ENUM value is not valid UTF-8": "enum_value_not_utf8",
    "checklist ON DUPLICATE KEY target differs from the approved primary-key contract": "checklist_upsert_contract_mismatch",
    "foreign key crosses the approved erp/legado boundary": "foreign_key_schema_boundary_mismatch",
    "foreign key signature differs from approved rehearsal snapshot": "foreign_key_signature_mismatch",
    "approved foreign key type normalization signature is missing": "fk_type_normalization_missing",
    "duplicate foreign key column pair requires explicit review": "fk_type_pair_duplicate",
    "foreign key column metadata is missing": "fk_column_metadata_missing",
    "unlisted foreign key type mismatch requires explicit review": "fk_type_unlisted_mismatch",
    "foreign key type normalization metadata differs from the reviewed contract": "fk_type_metadata_mismatch",
    "unsigned foreign key values exceed the approved signed bigint range": "fk_unsigned_bigint_range_mismatch",
    "non-null foreign key source contains null values": "fk_type_nullability_mismatch",
    "foreign key PostgreSQL types remain incompatible after normalization": "fk_type_incompatible",
    "git grep failed while classifying candidate backup table": "backup_reference_scan_failed",
    "identity, primary-key, or on-update counts differ from approved rehearsal snapshot": "identity_or_key_shape_mismatch",
    "invalid MySQL client configuration": "mysql_client_config_invalid",
    "MySQL TIME values are outside the approved PostgreSQL time(0) range": "time_value_out_of_range",
    "non-InnoDB source table requires explicit review": "non_innodb_engine",
    "official source clone provenance or clean baseline check failed": "source_clone_provenance_mismatch",
    "NULL encountered while inspecting ENUM domain": "enum_null_member",
    "ON UPDATE CURRENT_TIMESTAMP column list differs from approved rehearsal snapshot": "on_update_columns_mismatch",
    "ON UPDATE CURRENT_TIMESTAMP manifest metadata differs from approved rehearsal snapshot": "on_update_manifest_metadata_mismatch",
    "sensitive column defaults require manual review before DDL generation": "sensitive_column_default",
    "source column collation inventory differs from approved rehearsal snapshot": "source_collation_mismatch",
    "source MySQL grants are not the exact approved read-only set": "source_grants_mismatch",
    "source MySQL is not read-only with events disabled and UTC session": "source_read_only_guard_failed",
    "stored procedure definitions differ from approved metadata fingerprint": "routine_fingerprint_mismatch",
    "stored procedure signature differs from approved rehearsal snapshot": "routine_signature_mismatch",
    "tenant_id coverage differs from approved rehearsal snapshot": "tenant_coverage_mismatch",
    "textual NUL requires user decision; counts written to private summary": "textual_nul_found",
    "trigger definitions differ from approved rehearsal snapshot": "trigger_fingerprint_mismatch",
    "trigger or routine names differ from approved rehearsal snapshot": "business_object_names_mismatch",
    "trigger-related tenant references require review before translation": "trigger_tenant_reference_mismatch",
}


def catalog_error_code(exc: Exception) -> str:
    return CATALOG_ERROR_CODES.get(str(exc), "unclassified")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(f"CATALOG_GENERATION_FAILED type={type(exc).__name__} code={catalog_error_code(exc)}", file=sys.stderr)
        raise SystemExit(1)
