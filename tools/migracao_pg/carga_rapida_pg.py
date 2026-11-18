#!/usr/bin/env python3
"""
ERP Condomínios — carga direta MySQL -> PostgreSQL (ambiente de TESTE/HOMOLOGAÇÃO).

Lê o schema e os dados de uma CÓPIA MySQL (ex.: erp_pg_source) e recria tudo no
PostgreSQL (ex.: erp_pg_dev), aplicando as regras de tipo aprovadas pelo André.
Não imprime nenhum valor de dado: só nomes, contagens e tempos.

Uso (no servidor, como root):
  python3 carga_rapida_pg.py \
      --mysql-defaults /etc/erpcondominios/secrets/pg_reader.cnf --mysql-db erp_pg_source \
      --pg "dbname=erp_pg_dev user=postgres host=/var/run/postgresql" \
      --owner erp_owner --app-role erp_app --recriar

Proteções: recusa os bancos de produção como origem e só escreve em destinos da allowlist.
"""
import argparse, datetime as dt, decimal, hashlib, json, re, sys, time
from zoneinfo import ZoneInfo

import pymysql, pymysql.cursors
import psycopg
from psycopg import sql

ORIGENS_PROIBIDAS = {"erpcondominios", "erpcondominios_stage_20261114"}
DESTINOS_PERMITIDOS = {"erp_pg_dev", "erp_pg_teste", "erp_pg_hml"}
TZ_SP = ZoneInfo("America/Sao_Paulo")
SCHEMA = "erp"
LEGADO = "legado"
RE_LEGADO = re.compile(r"(_bkp_|_backup$|_backup_|_old$|_copy$|_\d{8}(_|$))", re.I)
RE_COLLATE_C = re.compile(r"(token|hash|senha|password|secret|chave|api_?key|slug|uuid|caminho|path|arquivo|filename|url)", re.I)
TEXT_TYPES = {"varchar", "char", "text", "tinytext", "mediumtext", "longtext", "enum", "set"}
TEMPORAL = {"datetime", "timestamp", "date"}


def log(msg):
    print(f"[{dt.datetime.now():%H:%M:%S}] {msg}", flush=True)


def qi(*parts):
    return sql.SQL(".").join(sql.Identifier(p) for p in parts)


def lit(v):
    return sql.Literal(v)


def pg_name(*parts, limite=63):
    nome = "_".join(parts)
    if len(nome) <= limite:
        return nome
    h = hashlib.sha1(nome.encode()).hexdigest()[:8]
    return nome[: limite - 9] + "_" + h


# ---------------------------------------------------------------- catálogo MySQL
def ler_catalogo(my, db):
    c = my.cursor(pymysql.cursors.DictCursor)
    c.execute("""SELECT TABLE_NAME t FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=%s AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME""", (db,))
    tabelas = [r["t"] for r in c.fetchall()]
    c.execute("""SELECT TABLE_NAME t, COLUMN_NAME n, ORDINAL_POSITION o, DATA_TYPE dt, COLUMN_TYPE ct,
                        IS_NULLABLE nl, COLUMN_DEFAULT df, EXTRA ex, CHARACTER_MAXIMUM_LENGTH ml,
                        NUMERIC_PRECISION np, NUMERIC_SCALE ns, COLLATION_NAME co,
                        GENERATION_EXPRESSION ge, COLUMN_KEY ck
                 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s
                 ORDER BY TABLE_NAME, ORDINAL_POSITION""", (db,))
    cols = {}
    for r in c.fetchall():
        cols.setdefault(r["t"], []).append(r)
    c.execute("""SELECT TABLE_NAME t, INDEX_NAME i, NON_UNIQUE nu, SEQ_IN_INDEX s, COLUMN_NAME col,
                        SUB_PART sp, INDEX_TYPE it, EXPRESSION expr
                 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=%s
                 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX""", (db,))
    idx = {}
    for r in c.fetchall():
        idx.setdefault(r["t"], {}).setdefault(r["i"], []).append(r)
    c.execute("""SELECT k.CONSTRAINT_NAME fk, k.TABLE_NAME t, k.COLUMN_NAME col, k.ORDINAL_POSITION o,
                        k.REFERENCED_TABLE_NAME rt, k.REFERENCED_COLUMN_NAME rc,
                        r.UPDATE_RULE ur, r.DELETE_RULE dr
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                   ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
                      AND r.TABLE_NAME=k.TABLE_NAME
                 WHERE k.TABLE_SCHEMA=%s AND k.REFERENCED_TABLE_NAME IS NOT NULL
                 ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION""", (db,))
    fks = {}
    for r in c.fetchall():
        fks.setdefault((r["t"], r["fk"]), []).append(r)
    c.execute("""SELECT TRIGGER_NAME n, EVENT_OBJECT_TABLE t, ACTION_TIMING tm, EVENT_MANIPULATION ev
                 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=%s ORDER BY 1""", (db,))
    triggers = c.fetchall()
    return tabelas, cols, idx, fks, triggers


# ---------------------------------------------------------------- pré-varredura (só agregados)
def pre_varredura(my, t, cols, rel):
    """Descobre datas zero em colunas NOT NULL, TIME fora da faixa e valores de ENUM fora da lista."""
    c = my.cursor()
    info = {"temporal_vira_null": set(), "time_interval": set(), "enum_extra": {}}
    for col in cols:
        n, d = col["n"], col["dt"]
        qn = f"`{n}`"
        if d in TEMPORAL:
            c.execute(f"SELECT COUNT(*) FROM `{t}` WHERE CAST({qn} AS CHAR) LIKE '0000%%' "
                      f"OR CAST({qn} AS CHAR) REGEXP '^[0-9]{{4}}-00|^[0-9]{{4}}-[0-9]{{2}}-00'")
            z = c.fetchone()[0]
            if z:
                info["temporal_vira_null"].add(n)
                rel["datas_zero"].append({"tabela": t, "coluna": n, "linhas": z})
        elif d == "time":
            c.execute(f"SELECT COUNT(*) FROM `{t}` WHERE {qn} < '00:00:00' OR {qn} > '23:59:59'")
            if c.fetchone()[0]:
                info["time_interval"].add(n)
                rel["time_para_interval"].append({"tabela": t, "coluna": n})
        elif d == "enum":
            permitidos = parse_enum(col["ct"])
            c.execute(f"SELECT DISTINCT CAST({qn} AS CHAR) FROM `{t}` WHERE {qn} IS NOT NULL")
            extras = sorted({r[0] for r in c.fetchall()} - set(permitidos))
            if extras:
                info["enum_extra"][n] = extras
                rel["enum_fora_da_lista"].append({"tabela": t, "coluna": n, "qtd_valores": len(extras)})
    return info


def parse_enum(ct):
    corpo = ct[ct.index("(") + 1: ct.rindex(")")]
    return [m.replace("''", "'") for m in re.findall(r"'((?:[^']|'')*)'", corpo)]


# ---------------------------------------------------------------- tipos e defaults
def tipo_pg(col, info, usar_ci):
    d, ct = col["dt"], col["ct"].lower()
    unsigned = "unsigned" in ct
    auto = "auto_increment" in (col["ex"] or "").lower()
    if d in ("tinyint", "smallint"):
        base = "integer" if (unsigned and d == "smallint") else "smallint"
    elif d in ("mediumint", "int", "integer"):
        base = "bigint" if unsigned else "integer"
    elif d == "bigint":
        base = "bigint" if (auto or not unsigned) else "numeric(20,0)"
    elif d in ("decimal", "numeric"):
        base = f"numeric({col['np']},{col['ns']})"
    elif d == "float":
        base = "real"
    elif d == "double":
        base = "double precision"
    elif d == "bit":
        base = "integer"
    elif d == "year":
        base = "smallint"
    elif d == "datetime" or d == "timestamp":
        base = "timestamp(0) without time zone"
    elif d == "date":
        base = "date"
    elif d == "time":
        base = "interval" if col["n"] in info["time_interval"] else "time(0) without time zone"
    elif d == "json":
        base = "json"
    elif d in ("blob", "tinyblob", "mediumblob", "longblob", "binary", "varbinary"):
        base = "bytea"
    elif d in ("varchar", "char"):
        base = f"{'varchar' if d == 'varchar' else 'char'}({col['ml']})"
    elif d in ("text", "tinytext", "mediumtext", "longtext"):
        base = "text"
    elif d == "enum":
        base = f"varchar({max([len(v) for v in parse_enum(col['ct'])] + [len(x) for x in info['enum_extra'].get(col['n'], [])] + [1])})"
    elif d == "set":
        base = "text"
    else:
        raise SystemExit(f"Tipo não suportado: {d} em {col['t']}.{col['n']}")
    collate = None
    if d in TEXT_TYPES:
        co = (col["co"] or "").lower()
        if co.endswith("_bin") or RE_COLLATE_C.search(col["n"]):
            collate = "C"
        elif usar_ci:
            collate = "ci"
    return base, collate, auto


def default_pg(col, tipo_base):
    df, ex = col["df"], (col["ex"] or "").lower()
    if df is None:
        return None
    s = str(df)
    up = s.upper()
    if "default_generated" in ex or up.startswith("CURRENT_TIMESTAMP") or up in ("NOW()", "CURRENT_TIMESTAMP()"):
        if up.startswith("CURRENT_TIMESTAMP") or up.startswith("NOW("):
            return sql.SQL("LOCALTIMESTAMP(0)")
        if up in ("CURDATE()", "(CURDATE())", "CURRENT_DATE"):
            return sql.SQL("CURRENT_DATE")
        if "UUID()" in up:
            return sql.SQL("gen_random_uuid()::text")
        return "__DESCARTADO__"
    if col["dt"] in TEMPORAL and (s.startswith("0000") or "-00" in s[:10]):
        return None
    if col["dt"] in ("tinyint", "smallint", "int", "integer", "mediumint", "bigint", "decimal",
                     "numeric", "float", "double", "year"):
        try:
            decimal.Decimal(s)
            return sql.SQL(s)
        except Exception:
            return "__DESCARTADO__"
    if col["dt"] == "bit":
        m = re.match(r"b'([01]+)'", s)
        return sql.SQL(str(int(m.group(1), 2))) if m else "__DESCARTADO__"
    return lit(s)


# ---------------------------------------------------------------- DDL
def criar_tabela(pg, sch, t, cols, info, usar_ci, rel):
    defs, checks = [], []
    for col in cols:
        base, collate, auto = tipo_pg(col, info, usar_ci)
        parts = [sql.Identifier(col["n"]), sql.SQL(base)]
        if collate == "ci":
            parts.append(sql.SQL("COLLATE {}").format(qi(sch, "ci")))
        elif collate == "C":
            parts.append(sql.SQL('COLLATE "C"'))
        ge = col["ge"]
        if ge:
            expr = ge.replace("`", '"')
            parts.append(sql.SQL("GENERATED ALWAYS AS ({}) STORED").format(sql.SQL(expr)))
            rel["colunas_geradas"].append({"tabela": t, "coluna": col["n"]})
        elif auto:
            parts.append(sql.SQL("GENERATED BY DEFAULT AS IDENTITY"))
        else:
            dfv = default_pg(col, base)
            if dfv == "__DESCARTADO__":
                rel["defaults_descartados"].append({"tabela": t, "coluna": col["n"], "default_mysql": str(col["df"])[:60]})
            elif dfv is not None:
                parts.append(sql.SQL("DEFAULT {}").format(dfv))
        nullable = col["nl"] == "YES" or col["n"] in info["temporal_vira_null"]
        if col["nl"] != "YES" and col["n"] in info["temporal_vira_null"]:
            rel["not_null_removido"].append({"tabela": t, "coluna": col["n"]})
        if not nullable and not ge:
            parts.append(sql.SQL("NOT NULL"))
        defs.append(sql.SQL(" ").join(parts))
        if col["dt"] == "enum":
            vals = parse_enum(col["ct"]) + info["enum_extra"].get(col["n"], [])
            checks.append(sql.SQL("CHECK ({} IN ({}))").format(
                sql.Identifier(col["n"]), sql.SQL(", ").join(lit(v) for v in vals)))
        if col["dt"] == "bigint" and "unsigned" in col["ct"].lower() and base == "bigint":
            checks.append(sql.SQL("CHECK ({} >= 0)").format(sql.Identifier(col["n"])))
    pg.execute(sql.SQL("CREATE TABLE {} ({})").format(qi(sch, t), sql.SQL(", ").join(defs + checks)))


def criar_indices(pg, sch, t, indices, cols_by_name, rel):
    for nome, partes in indices.items():
        tipo = partes[0]["it"]
        unico = partes[0]["nu"] == 0
        if nome == "PRIMARY":
            pg.execute(sql.SQL("ALTER TABLE {} ADD PRIMARY KEY ({})").format(
                qi(sch, t), sql.SQL(", ").join(sql.Identifier(p["col"]) for p in partes)))
            continue
        if tipo == "FULLTEXT":
            rel["fulltext_adiados"].append({"tabela": t, "indice": nome})
            continue
        exprs = []
        for p in partes:
            if p["col"] is None:
                rel["indices_ignorados"].append({"tabela": t, "indice": nome, "motivo": "índice funcional"})
                exprs = None
                break
            c = cols_by_name[p["col"]]
            if p["sp"] or c["dt"] in ("text", "mediumtext", "longtext", "tinytext"):
                n = int(p["sp"] or 255)
                exprs.append(sql.SQL("left({}, {})").format(sql.Identifier(p["col"]), sql.SQL(str(n))))
            else:
                exprs.append(sql.Identifier(p["col"]))
        if not exprs:
            continue
        iname = pg_name(t, nome)
        try:
            with pg.transaction():
                pg.execute(sql.SQL("CREATE {}INDEX {} ON {} ({})").format(
                    sql.SQL("UNIQUE " if unico else ""), sql.Identifier(iname), qi(sch, t),
                    sql.SQL(", ").join(exprs)))
        except psycopg.Error as e:
            if unico:
                with pg.transaction():
                    pg.execute(sql.SQL("CREATE INDEX {} ON {} ({})").format(
                        sql.Identifier(iname), qi(sch, t), sql.SQL(", ").join(exprs)))
                rel["unique_rebaixado"].append({"tabela": t, "indice": nome, "sqlstate": e.sqlstate})
            else:
                rel["indices_ignorados"].append({"tabela": t, "indice": nome, "sqlstate": e.sqlstate})


def criar_trigger_on_update(pg, sch):
    pg.execute(sql.SQL("""
    CREATE OR REPLACE FUNCTION {f}() RETURNS trigger LANGUAGE plpgsql AS $$
    DECLARE col text := TG_ARGV[0];
    BEGIN
      -- Igual ao MySQL ON UPDATE CURRENT_TIMESTAMP: só atualiza se a linha mudou
      -- e se o comando não definiu a coluna explicitamente.
      IF (to_jsonb(NEW) - col) IS DISTINCT FROM (to_jsonb(OLD) - col)
         AND (to_jsonb(NEW) -> col) IS NOT DISTINCT FROM (to_jsonb(OLD) -> col) THEN
        NEW := jsonb_populate_record(NEW, jsonb_build_object(col, LOCALTIMESTAMP(0)));
      END IF;
      RETURN NEW;
    END $$""").format(f=qi(sch, "trg_on_update_timestamp")))


# ---------------------------------------------------------------- carga
def carregar(my, pg, db, sch, t, cols, rel, lote=2000):
    load_cols = [c for c in cols if not c["ge"]]
    sel = []
    for c in load_cols:
        if c["dt"] in TEMPORAL or c["dt"] == "time":
            sel.append(f"CAST(`{c['n']}` AS CHAR)")
        elif c["dt"] in ("enum", "set"):
            sel.append(f"CAST(`{c['n']}` AS CHAR)")
        elif c["dt"] == "bit":
            sel.append(f"CAST(`{c['n']}` AS UNSIGNED)")
        else:
            sel.append(f"`{c['n']}`")
    pk = [c["n"] for c in cols if c["ck"] == "PRI"]
    order = (" ORDER BY " + ", ".join(f"`{p}`" for p in pk)) if pk else ""
    cur = my.cursor(pymysql.cursors.SSCursor)
    cur.execute(f"SELECT {', '.join(sel)} FROM `{db}`.`{t}`{order}")
    tipos = [c["dt"] for c in load_cols]
    nul_count = 0
    n = 0
    copy_sql = sql.SQL("COPY {} ({}) FROM STDIN").format(
        qi(sch, t), sql.SQL(", ").join(sql.Identifier(c["n"]) for c in load_cols))
    with pg.cursor() as pc:
        with pc.copy(copy_sql) as cp:
            while True:
                rows = cur.fetchmany(lote)
                if not rows:
                    break
                for r in rows:
                    out = []
                    for v, d in zip(r, tipos):
                        if v is None:
                            out.append(None)
                            continue
                        if d in TEMPORAL:
                            if v.startswith("0000") or v[5:7] == "00" or (len(v) >= 10 and v[8:10] == "00"):
                                out.append(None)
                                continue
                            if d == "timestamp":
                                utc = dt.datetime.strptime(v[:19], "%Y-%m-%d %H:%M:%S").replace(tzinfo=dt.timezone.utc)
                                v = utc.astimezone(TZ_SP).strftime("%Y-%m-%d %H:%M:%S")
                            else:
                                v = v[:19]
                        elif d in TEXT_TYPES and isinstance(v, str) and "\x00" in v:
                            v = v.replace("\x00", "")
                            nul_count += 1
                        elif isinstance(v, (bytes, bytearray)) and d in TEXT_TYPES:
                            v = v.decode("utf-8", "replace").replace("\x00", "")
                        out.append(v)
                    cp.write_row(out)
                    n += 1
    cur.close()
    if nul_count:
        rel["nul_removido"].append({"tabela": t, "celulas": nul_count})
    return n


def ajustar_sequencias(pg, sch, t, cols):
    for c in cols:
        if "auto_increment" in (c["ex"] or "").lower():
            pg.execute(sql.SQL(
                "SELECT setval(pg_get_serial_sequence({tab}, {col}), "
                "COALESCE((SELECT max({ci}) FROM {q}), 0) + 1, false)").format(
                tab=lit(f'{sch}."{t}"'), col=lit(c["n"]), ci=sql.Identifier(c["n"]), q=qi(sch, t)))


# ---------------------------------------------------------------- reconciliação (agregados)
def reconciliar(my, pg, db, sch, t, cols, n_carregado):
    c = my.cursor()
    c.execute(f"SELECT COUNT(*) FROM `{db}`.`{t}`")
    n_src = c.fetchone()[0]
    r = {"tabela": t, "origem": n_src, "destino": None, "ok": True, "detalhes": []}
    r["destino"] = pg.execute(sql.SQL("SELECT count(*) FROM {}").format(qi(sch, t))).fetchone()[0]
    if r["destino"] != n_src or n_carregado != n_src:
        r["ok"] = False
        r["detalhes"].append("contagem")
    for col in cols:
        nome = col["n"]
        if col["dt"] in ("decimal", "numeric", "int", "integer", "bigint", "smallint", "tinyint", "mediumint") \
                or col["ge"]:
            c.execute(f"SELECT CAST(SUM(`{nome}`) AS CHAR), CAST(MAX(`{nome}`) AS CHAR) FROM `{db}`.`{t}`")
            s_src, m_src = c.fetchone()
            s_dst, m_dst = pg.execute(sql.SQL("SELECT sum({c})::text, max({c})::text FROM {q}").format(
                c=sql.Identifier(nome), q=qi(sch, t))).fetchone()
            if not num_eq(s_src, s_dst) or not num_eq(m_src, m_dst):
                r["ok"] = False
                r["detalhes"].append(f"soma/max:{nome}")
        elif col["dt"] in ("blob", "tinyblob", "mediumblob", "longblob", "binary", "varbinary"):
            c.execute(f"SELECT CAST(SUM(OCTET_LENGTH(`{nome}`)) AS CHAR) FROM `{db}`.`{t}`")
            b_src = c.fetchone()[0]
            b_dst = pg.execute(sql.SQL("SELECT sum(octet_length({c}))::text FROM {q}").format(
                c=sql.Identifier(nome), q=qi(sch, t))).fetchone()[0]
            if not num_eq(b_src, b_dst):
                r["ok"] = False
                r["detalhes"].append(f"bytes:{nome}")
    if any(c2["n"] == "tenant_id" for c2 in cols):
        c.execute(f"SELECT tenant_id, COUNT(*) FROM `{db}`.`{t}` GROUP BY tenant_id")
        src = {str(k): v for k, v in c.fetchall()}
        dst = {str(k): v for k, v in pg.execute(sql.SQL("SELECT tenant_id, count(*) FROM {} GROUP BY 1").format(
            qi(sch, t))).fetchall()}
        if src != dst:
            r["ok"] = False
            r["detalhes"].append("por_tenant")
    return r


def num_eq(a, b):
    if a is None or b is None:
        return a is None and b is None
    try:
        return decimal.Decimal(str(a)) == decimal.Decimal(str(b))
    except Exception:
        return str(a) == str(b)


# ---------------------------------------------------------------- FKs
def criar_fks(pg, sch_of, fks, rel):
    for (t, nome), partes in fks.items():
        rt = partes[0]["rt"]
        if rt not in sch_of:
            rel["fks"].append({"tabela": t, "fk": nome, "estado": "TABELA_REFERENCIADA_AUSENTE"})
            continue
        fkname = pg_name(t, nome)
        acoes = {"CASCADE": "CASCADE", "SET NULL": "SET NULL", "RESTRICT": "RESTRICT",
                 "NO ACTION": "NO ACTION", "SET DEFAULT": "SET DEFAULT"}
        stmt = sql.SQL("ALTER TABLE {} ADD CONSTRAINT {} FOREIGN KEY ({}) REFERENCES {} ({}) "
                       "ON UPDATE {} ON DELETE {} NOT VALID").format(
            qi(sch_of[t], t), sql.Identifier(fkname),
            sql.SQL(", ").join(sql.Identifier(p["col"]) for p in partes),
            qi(sch_of[rt], rt), sql.SQL(", ").join(sql.Identifier(p["rc"]) for p in partes),
            sql.SQL(acoes.get(partes[0]["ur"], "NO ACTION")), sql.SQL(acoes.get(partes[0]["dr"], "NO ACTION")))
        try:
            with pg.transaction():
                pg.execute(stmt)
        except psycopg.Error as e:
            rel["fks"].append({"tabela": t, "fk": nome, "estado": "NAO_CRIADA", "sqlstate": e.sqlstate})
            continue
        try:
            with pg.transaction():
                pg.execute(sql.SQL("ALTER TABLE {} VALIDATE CONSTRAINT {}").format(
                    qi(sch_of[t], t), sql.Identifier(fkname)))
            rel["fks"].append({"tabela": t, "fk": nome, "estado": "VALIDADA"})
        except psycopg.Error:
            rel["fks"].append({"tabela": t, "fk": nome, "estado": "NOT_VALID_ORFAOS"})


# ---------------------------------------------------------------- principal
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--mysql-defaults", required=True, help="arquivo .cnf com user/password/socket (0600)")
    ap.add_argument("--mysql-db", required=True)
    ap.add_argument("--pg", required=True, help='conninfo, ex.: "dbname=erp_pg_dev user=postgres host=/var/run/postgresql"')
    ap.add_argument("--owner", default=None)
    ap.add_argument("--app-role", default=None)
    ap.add_argument("--recriar", action="store_true", help="apaga e recria os schemas erp/legado no destino")
    ap.add_argument("--sem-ci", action="store_true", help="não usar collation case-insensitive (diagnóstico)")
    ap.add_argument("--so-tabelas", default=None, help="lista separada por vírgula (teste parcial)")
    ap.add_argument("--relatorio", default="carga_rapida_relatorio.json")
    a = ap.parse_args()

    if a.mysql_db in ORIGENS_PROIBIDAS:
        sys.exit(f"RECUSADO: '{a.mysql_db}' é banco de produção/rollback. Use a cópia (ex.: erp_pg_source).")
    inicio = time.time()
    my = pymysql.connect(read_default_file=a.mysql_defaults, database=a.mysql_db, charset="utf8mb4",
                         autocommit=False)
    myc = my.cursor()
    myc.execute("SET SESSION time_zone = '+00:00'")
    myc.execute("SET SESSION sql_mode = ''")
    myc.execute("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ")
    myc.execute("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY")

    pg = psycopg.connect(a.pg, autocommit=True)
    dbname = pg.execute("SELECT current_database()").fetchone()[0]
    if dbname not in DESTINOS_PERMITIDOS:
        sys.exit(f"RECUSADO: destino '{dbname}' fora da allowlist {sorted(DESTINOS_PERMITIDOS)}.")
    pg.execute("SET TIME ZONE 'America/Sao_Paulo'")
    log(f"Origem MySQL: {a.mysql_db} | Destino PostgreSQL: {dbname}")

    tabelas, cols, idx, fks, triggers = ler_catalogo(my, a.mysql_db)
    if a.so_tabelas:
        alvo = set(a.so_tabelas.split(","))
        tabelas = [t for t in tabelas if t in alvo]
    log(f"Catálogo: {len(tabelas)} tabelas, {sum(len(cols[t]) for t in tabelas)} colunas, "
        f"{len(fks)} FKs, {len(triggers)} triggers")

    rel = {k: [] for k in ("datas_zero", "time_para_interval", "enum_fora_da_lista", "colunas_geradas",
                           "defaults_descartados", "not_null_removido", "fulltext_adiados", "indices_ignorados",
                           "unique_rebaixado", "nul_removido", "fks", "tabelas", "falhas")}
    rel["triggers_mysql_nao_criadas"] = [{"nome": x["n"], "tabela": x["t"], "quando": f"{x['tm']} {x['ev']}"}
                                         for x in triggers]

    if a.recriar:
        pg.execute(sql.SQL("DROP SCHEMA IF EXISTS {} CASCADE").format(sql.Identifier(SCHEMA)))
        pg.execute(sql.SQL("DROP SCHEMA IF EXISTS {} CASCADE").format(sql.Identifier(LEGADO)))
    pg.execute(sql.SQL("CREATE SCHEMA IF NOT EXISTS {}").format(sql.Identifier(SCHEMA)))
    pg.execute(sql.SQL("CREATE SCHEMA IF NOT EXISTS {}").format(sql.Identifier(LEGADO)))
    pg.execute(sql.SQL("CREATE COLLATION IF NOT EXISTS {} (provider = icu, locale = 'und-u-ks-level2', "
                       "deterministic = false)").format(qi(SCHEMA, "ci")))
    pg.execute(sql.SQL("CREATE COLLATION IF NOT EXISTS {} (provider = icu, locale = 'und-u-ks-level2', "
                       "deterministic = false)").format(qi(LEGADO, "ci")))
    criar_trigger_on_update(pg, SCHEMA)

    sch_of = {}
    for t in tabelas:
        sch = LEGADO if RE_LEGADO.search(t) else SCHEMA
        sch_of[t] = sch
        t0 = time.time()
        try:
            info = pre_varredura(my, t, cols[t], rel)
            with pg.transaction():
                criar_tabela(pg, sch, t, cols[t], info, not a.sem_ci, rel)
                n = carregar(my, pg, a.mysql_db, sch, t, cols[t], rel)
            criar_indices(pg, sch, t, idx.get(t, {}), {c["n"]: c for c in cols[t]}, rel)
            ajustar_sequencias(pg, sch, t, cols[t])
            for c in cols[t]:
                if "on update current_timestamp" in (c["ex"] or "").lower():
                    pg.execute(sql.SQL("CREATE TRIGGER {} BEFORE UPDATE ON {} FOR EACH ROW "
                                       "EXECUTE FUNCTION {}({})").format(
                        sql.Identifier(pg_name(t, c["n"], "on_upd")), qi(sch, t),
                        qi(SCHEMA, "trg_on_update_timestamp"), lit(c["n"])))
            r = reconciliar(my, pg, a.mysql_db, sch, t, cols[t], n)
            r["schema"] = sch
            r["segundos"] = round(time.time() - t0, 2)
            rel["tabelas"].append(r)
            log(f"{'OK ' if r['ok'] else 'DIF'} {sch}.{t}: {r['origem']} -> {r['destino']} linhas "
                f"({r['segundos']}s){'' if r['ok'] else ' ' + ','.join(r['detalhes'])}")
        except Exception as e:
            code = getattr(e, "sqlstate", None)
            rel["falhas"].append({"tabela": t, "erro": type(e).__name__, "sqlstate": code})
            log(f"FALHA {t}: {type(e).__name__} {code or ''}")

    criar_fks(pg, sch_of, fks, rel)

    if a.owner:
        for s in (SCHEMA, LEGADO):
            pg.execute(sql.SQL("ALTER SCHEMA {} OWNER TO {}").format(sql.Identifier(s), sql.Identifier(a.owner)))
        for t, s in sch_of.items():
            pg.execute(sql.SQL("ALTER TABLE {} OWNER TO {}").format(qi(s, t), sql.Identifier(a.owner)))
        pg.execute(sql.SQL("ALTER FUNCTION {}() OWNER TO {}").format(
            qi(SCHEMA, "trg_on_update_timestamp"), sql.Identifier(a.owner)))
    if a.app_role:
        pg.execute(sql.SQL("GRANT USAGE ON SCHEMA {} TO {}").format(sql.Identifier(SCHEMA), sql.Identifier(a.app_role)))
        pg.execute(sql.SQL("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {} TO {}").format(
            sql.Identifier(SCHEMA), sql.Identifier(a.app_role)))
        pg.execute(sql.SQL("GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA {} TO {}").format(
            sql.Identifier(SCHEMA), sql.Identifier(a.app_role)))
        pg.execute(sql.SQL("ALTER ROLE {} IN DATABASE {} SET search_path = {}, public").format(
            sql.Identifier(a.app_role), sql.Identifier(dbname), sql.Identifier(SCHEMA)))
    pg.execute("ANALYZE")
    my.rollback()

    total = time.time() - inicio
    ok = sum(1 for r in rel["tabelas"] if r["ok"])
    resumo = {
        "inicio_fim_segundos": round(total, 1),
        "tabelas_ok": ok,
        "tabelas_com_diferenca": len(rel["tabelas"]) - ok,
        "tabelas_com_falha": len(rel["falhas"]),
        "linhas_origem": sum(r["origem"] for r in rel["tabelas"]),
        "linhas_destino": sum(r["destino"] or 0 for r in rel["tabelas"]),
        "fks_validadas": sum(1 for f in rel["fks"] if f["estado"] == "VALIDADA"),
        "fks_com_orfaos": sum(1 for f in rel["fks"] if f["estado"] == "NOT_VALID_ORFAOS"),
        "triggers_mysql_pendentes": len(rel["triggers_mysql_nao_criadas"]),
    }
    rel["resumo"] = resumo
    with open(a.relatorio, "w") as f:
        json.dump(rel, f, ensure_ascii=False, indent=1, default=str)
    log("RESUMO " + json.dumps(resumo, ensure_ascii=False))
    for k in ("datas_zero", "not_null_removido", "enum_fora_da_lista", "time_para_interval", "nul_removido",
              "defaults_descartados", "unique_rebaixado", "indices_ignorados", "fulltext_adiados"):
        if rel[k]:
            log(f"  {k}: {len(rel[k])}")
    log(f"Relatório completo (sem dados): {a.relatorio}")
    sys.exit(0 if (resumo["tabelas_com_diferenca"] == 0 and resumo["tabelas_com_falha"] == 0) else 2)


if __name__ == "__main__":
    main()
