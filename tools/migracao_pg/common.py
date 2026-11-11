from __future__ import annotations

import hashlib
import json
import os
import re
import stat
from datetime import date, datetime, time, timedelta, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path
from zoneinfo import ZoneInfo

SAO_PAULO = ZoneInfo("America/Sao_Paulo")
UTC = timezone.utc
V9_DATABASE = "erp_ensaio_v9"
V9_PGDATA = "/var/lib/erp-pg-rehearsal/pg-data"
V9_PGSOCKET = "/var/lib/erp-pg-rehearsal/pg-socket"
V9_PGPORT = 55432
V10D_DATABASE = "erp_ensaio_v10d"
V10D_PGDATA = "/var/lib/erp-pg-rehearsal/v10d/pg-data"
V10D_PGSOCKET = "/var/lib/erp-pg-rehearsal/v10d/pg-socket"
V10D_PGPORT = 55433
V10E_DATABASE = "erp_ensaio_v10e"
V10E_PGDATA = "/var/lib/erp-pg-rehearsal/v10e/pg-data"
V10E_PGSOCKET = "/var/lib/erp-pg-rehearsal/v10e/pg-socket"
V10E_PGPORT = 55434


def open_directory_chain(path) -> int:
    directory = Path(path)
    if not directory.is_absolute() or any(part in {".", ".."} for part in directory.parts[1:]):
        raise RuntimeError("directory path is not a safe absolute path")
    flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_NOFOLLOW", 0)
    fd = os.open("/", flags)
    try:
        for component in directory.parts[1:]:
            next_fd = os.open(component, flags, dir_fd=fd)
            info = os.fstat(next_fd)
            mode = stat.S_IMODE(info.st_mode)
            shared_write = bool(mode & 0o022)
            safe_sticky_tmp = info.st_uid == 0 and bool(info.st_mode & stat.S_ISVTX)
            if (not stat.S_ISDIR(info.st_mode) or (shared_write and not safe_sticky_tmp)):
                os.close(next_fd)
                raise RuntimeError("directory path crosses an unsafe writable directory")
            os.close(fd)
            fd = next_fd
        return fd
    except BaseException:
        os.close(fd)
        raise


def open_file_from_directory(path, *, expected_parent_uid=None, expected_parent_mode=None,
                             flags=None) -> int:
    file_path = Path(path)
    parent_fd = open_directory_chain(file_path.parent)
    try:
        parent = os.fstat(parent_fd)
        if (expected_parent_uid is not None and parent.st_uid != expected_parent_uid
                or expected_parent_mode is not None and stat.S_IMODE(parent.st_mode) != expected_parent_mode):
            raise RuntimeError("file parent directory has unsafe ownership or permissions")
        file_flags = os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0) if flags is None else flags
        return os.open(file_path.name, file_flags, dir_fd=parent_fd)
    finally:
        os.close(parent_fd)


def require_v10d_database(database: str) -> None:
    if database != V10D_DATABASE:
        raise SystemExit("refusing any database except the exclusive v10d rehearsal target")


def require_v10d_endpoint(database: str, socket: str, port: int) -> None:
    require_v10d_database(database)
    if socket != V10D_PGSOCKET or port != V10D_PGPORT:
        raise SystemExit("refusing any PostgreSQL endpoint except the isolated v10d target")


def require_v10e_database(database: str) -> None:
    if database != V10E_DATABASE:
        raise SystemExit("refusing any database except the exclusive v10e rehearsal target")


def require_v10e_endpoint(database: str, socket: str, port: int) -> None:
    require_v10e_database(database)
    if socket != V10E_PGSOCKET or port != V10E_PGPORT:
        raise SystemExit("refusing any PostgreSQL endpoint except the isolated v10e target")


def qi(value: str) -> str:
    if "\x00" in value:
        raise ValueError("NUL in identifier")
    return '"' + value.replace('"', '""') + '"'


def mysql_qi(value: str) -> str:
    if "\x00" in value:
        raise ValueError("NUL in identifier")
    return "`" + value.replace("`", "``") + "`"


def ql(value: str) -> str:
    if "\x00" in value:
        raise ValueError("NUL in SQL literal")
    return "'" + value.replace("'", "''") + "'"


def pg_name(value: str, limit: int = 63) -> str:
    raw = value.encode("utf-8")
    if len(raw) <= limit:
        return value
    raise ValueError("identifier exceeds PostgreSQL's 63-byte limit; explicit mapping required")


def generated_name(*parts: str, limit: int = 63) -> str:
    value = "_".join(parts)
    raw = value.encode("utf-8")
    if len(raw) <= limit:
        return value
    suffix = hashlib.sha256(raw).hexdigest()[:10]
    prefix = raw[: limit - len(suffix) - 1]
    while True:
        try:
            head = prefix.decode("utf-8")
            break
        except UnicodeDecodeError:
            prefix = prefix[:-1]
    return f"{head}_{suffix}"


def parse_mysql_enum(column_type: str) -> list[str]:
    match = re.fullmatch(r"(?:enum|set)\((.*)\)", column_type.strip(), re.IGNORECASE | re.DOTALL)
    if not match:
        raise ValueError("invalid enum metadata")
    source = match.group(1)
    out: list[str] = []
    i = 0
    while i < len(source):
        while i < len(source) and source[i] in " \t\r\n,":
            i += 1
        if i >= len(source) or source[i] != "'":
            raise ValueError("invalid enum literal")
        i += 1
        value: list[str] = []
        while i < len(source):
            ch = source[i]
            if ch == "\\":
                i += 1
                if i >= len(source):
                    raise ValueError("invalid enum escape")
                escaped = source[i]
                value.append({"0": "\x00", "b": "\x08", "n": "\n", "r": "\r", "t": "\t", "Z": "\x1a"}.get(escaped, escaped))
                i += 1
                continue
            if ch == "'":
                if i + 1 < len(source) and source[i + 1] == "'":
                    value.append("'")
                    i += 2
                    continue
                i += 1
                break
            value.append(ch)
            i += 1
        else:
            raise ValueError("unterminated enum literal")
        out.append("".join(value))
    if not out:
        raise ValueError("empty enum domain")
    return out


def mysql_type_to_pg(data_type: str, column_type: str, extra: str = "") -> str:
    t = data_type.lower()
    unsigned = "unsigned" in column_type.lower()
    auto = "auto_increment" in extra.lower()
    if auto:
        if t not in {"tinyint", "smallint", "mediumint", "int", "integer", "bigint"}:
            raise ValueError("unsupported AUTO_INCREMENT type")
        return "bigint"
    if t in {"tinyint", "smallint"}:
        return "integer" if t == "smallint" and unsigned else "smallint"
    if t in {"int", "integer", "mediumint"}:
        return "bigint" if unsigned else "integer"
    if t == "bigint":
        return "numeric(20,0)" if unsigned else "bigint"
    if t == "decimal":
        m = re.search(r"decimal\((\d+)\s*,\s*(\d+)\)", column_type, re.I)
        if not m:
            raise ValueError("invalid DECIMAL metadata")
        return f"numeric({int(m.group(1))},{int(m.group(2))})"
    if t == "float":
        return "real"
    if t == "double":
        return "double precision"
    if t in {"date"}:
        return "date"
    if t in {"datetime", "timestamp"}:
        return "timestamp(0) without time zone"
    if t == "time":
        return "time(0) without time zone"
    if t == "enum":
        return "varchar"
    if t == "set":
        return "varchar"
    if t in {"char", "varchar"}:
        m = re.search(r"\((\d+)\)", column_type)
        if not m:
            raise ValueError("invalid character length metadata")
        return f"{t}({int(m.group(1))})"
    if t in {"tinytext", "text", "mediumtext", "longtext"}:
        return "text"
    if t in {"tinyblob", "blob", "mediumblob", "longblob", "binary", "varbinary"}:
        return "bytea"
    if t == "json":
        return "json"
    raise ValueError(f"unsupported MySQL type: {t}")


def collate_class(column_name: str, data_type: str, column_type: str, source_collation: str | None = None) -> str | None:
    if data_type.lower() not in {"char", "varchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set"}:
        return None
    name = column_name.lower()
    if (source_collation and "_bin" in source_collation.lower()) or "_bin" in name or re.search(r"(^|_)(token|hash|senha|password|secret)(_|$)", name):
        return '"C"'
    if re.search(r"(^|_)(arquivo|file|path|caminho)(_|$)", name):
        return '"C"'
    return '"erp"."ci"'


def zero_date(value: object) -> bool:
    if value is None:
        return False
    if isinstance(value, (date, datetime)):
        return False
    text = value.decode("ascii", "strict") if isinstance(value, bytes) else str(value)
    m = re.match(r"^(\d{4})-(\d{2})-(\d{2})", text)
    if not m:
        return False
    y, month, day = map(int, m.groups())
    return y == 0 or month == 0 or day == 0


def json_contains_nul(value: object) -> bool:
    text = bytes(value).decode("utf-8", "strict") if isinstance(value, (bytes, bytearray, memoryview)) else str(value)
    decoded = json.loads(text)

    def contains(item):
        if isinstance(item, str):
            return "\x00" in item
        if isinstance(item, dict):
            return any(contains(key) or contains(child) for key, child in item.items())
        if isinstance(item, list):
            return any(contains(child) for child in item)
        return False

    return contains(decoded)


def convert_temporal(value: object, data_type: str) -> object:
    if value is None or zero_date(value):
        return None
    if isinstance(value, bytes):
        value = value.decode("ascii")
    if data_type.lower() == "timestamp":
        if isinstance(value, datetime):
            if value.microsecond:
                raise ValueError("fractional TIMESTAMP requires explicit preservation decision")
            if value.tzinfo is None:
                value = value.replace(tzinfo=UTC)
            value = value.astimezone(SAO_PAULO).replace(tzinfo=None)
        else:
            value = datetime.fromisoformat(str(value)).replace(tzinfo=UTC).astimezone(SAO_PAULO).replace(tzinfo=None)
        return value
    if data_type.lower() == "datetime":
        if isinstance(value, datetime):
            if value.microsecond:
                raise ValueError("fractional DATETIME requires explicit preservation decision")
            return value.replace(tzinfo=None)
        result = datetime.fromisoformat(str(value))
        if result.microsecond:
            raise ValueError("fractional DATETIME requires explicit preservation decision")
        return result.replace(tzinfo=None)
    if data_type.lower() == "date":
        if isinstance(value, datetime):
            return value.date()
        if isinstance(value, date):
            return value
        return date.fromisoformat(str(value)[:10])
    if data_type.lower() == "time":
        if isinstance(value, timedelta):
            seconds = value.total_seconds()
            if seconds < 0 or seconds >= 86400:
                raise ValueError("MySQL TIME outside PostgreSQL time-of-day range")
            whole = int(seconds)
            return time(whole // 3600, whole % 3600 // 60, whole % 60)
        if isinstance(value, time):
            if value.tzinfo is not None:
                raise ValueError("timezone-aware TIME is unsupported")
            if value.microsecond:
                raise ValueError("fractional TIME requires explicit preservation decision")
            return value
        text = str(value)
        if "." in text:
            base, fraction = text.split(".", 1)
            if fraction.strip("0"):
                raise ValueError("fractional TIME requires explicit preservation decision")
            text = base
        if text.startswith("-") or text.startswith("+"):
            raise ValueError("MySQL duration TIME is outside PostgreSQL time-of-day semantics")
        result = time.fromisoformat(text)
        if result.hour > 23:
            raise ValueError("MySQL TIME is outside PostgreSQL time-of-day range")
        return result
    return value


def ambiguous_sao_paulo_wall_time(value: datetime) -> bool:
    if value.tzinfo is not None:
        raise ValueError("wall-time ambiguity check expects a naive local timestamp")
    return value.replace(tzinfo=SAO_PAULO, fold=0).utcoffset() != value.replace(tzinfo=SAO_PAULO, fold=1).utcoffset()


def verify_v9_postgres_connection(conn) -> None:
    with conn.cursor() as cur:
        cur.execute("SELECT current_database(),current_setting('listen_addresses'),current_setting('port'),"
                    "current_setting('data_directory'),current_setting('unix_socket_directories'),"
                    "current_setting('server_version_num'),current_setting('TimeZone'),current_setting('server_encoding')")
        row = cur.fetchone()
    if (row is None or row[0] != V9_DATABASE or row[1] != "" or int(row[2]) != V9_PGPORT
            or row[3] != V9_PGDATA or row[4] != V9_PGSOCKET or not 180000 <= int(row[5]) < 190000
            or row[6] != "America/Sao_Paulo" or row[7] != "UTF8"):
        raise RuntimeError("PostgreSQL connection is not the approved isolated v9 rehearsal")


def verify_v10d_postgres_connection(conn) -> None:
    with conn.cursor() as cur:
        cur.execute("SELECT current_database(),current_setting('listen_addresses'),current_setting('port'),"
                    "current_setting('data_directory'),current_setting('unix_socket_directories'),"
                    "current_setting('server_version_num'),current_setting('TimeZone'),current_setting('server_encoding')")
        row = cur.fetchone()
    if (row is None or row[0] != V10D_DATABASE or row[1] != "" or int(row[2]) != V10D_PGPORT
            or row[3] != V10D_PGDATA or row[4] != V10D_PGSOCKET or not 180000 <= int(row[5]) < 190000
            or row[6] != "America/Sao_Paulo" or row[7] != "UTF8"):
        raise RuntimeError("PostgreSQL connection is not the approved isolated v10d rehearsal")


def verify_v10e_postgres_connection(conn) -> None:
    with conn.cursor() as cur:
        cur.execute("SELECT current_database(),current_setting('listen_addresses'),current_setting('port'),"
                    "current_setting('data_directory'),current_setting('unix_socket_directories'),"
                    "current_setting('server_version_num'),current_setting('TimeZone'),current_setting('server_encoding')")
        row = cur.fetchone()
    if (row is None or row[0] != V10E_DATABASE or row[1] != "" or int(row[2]) != V10E_PGPORT
            or row[3] != V10E_PGDATA or row[4] != V10E_PGSOCKET or not 180000 <= int(row[5]) < 190000
            or row[6] != "America/Sao_Paulo" or row[7] != "UTF8"):
        raise RuntimeError("PostgreSQL connection is not the approved isolated v10e rehearsal")


def canonical(value: object, data_type: str, *, side: str = "source", generated: bool = False) -> bytes:
    if value is None:
        return b"N"
    t = data_type.lower()
    if t in {"date", "datetime", "timestamp", "time"}:
        if side == "source":
            value = convert_temporal(value, t)
        elif t == "timestamp" and isinstance(value, datetime) and value.tzinfo is not None:
            value = value.astimezone(SAO_PAULO).replace(tzinfo=None)
        if value is None:
            return b"N"
        text = value.isoformat(timespec="seconds") if isinstance(value, (datetime, time)) else value.isoformat()
        return b"T" + text.encode("ascii")
    if t in {"decimal", "numeric", "int", "integer", "bigint", "smallint", "tinyint", "mediumint"}:
        try:
            n = Decimal(str(value))
        except InvalidOperation as exc:
            raise ValueError("invalid numeric value") from exc
        if n == 0:
            text = "0"
        else:
            text = format(n.normalize(), "f")
        return b"D" + text.encode("ascii")
    if t in {"float", "double", "real", "double precision"}:
        return b"F" + format(float(value), ".17g").encode("ascii")
    if t in {"blob", "tinyblob", "mediumblob", "longblob", "binary", "varbinary", "bytea"}:
        raw = bytes(value)
        return b"B" + hashlib.sha256(raw).digest() + len(raw).to_bytes(8, "big")
    if isinstance(value, bytes):
        raw = value.decode("utf-8", "strict").encode("utf-8") if t in {"json", "char", "varchar", "tinytext", "text", "mediumtext", "longtext", "enum", "set"} else value
    else:
        raw = str(value).encode("utf-8")
    if t == "char" or t.startswith("char("):
        raw = raw.rstrip(b" ")
    return b"S" + len(raw).to_bytes(8, "big") + raw


def row_digest(values: tuple[object, ...], types: list[str], *, side: str = "source") -> bytes:
    h = hashlib.sha256()
    for value, data_type in zip(values, types, strict=True):
        field = canonical(value, data_type, side=side)
        h.update(len(field).to_bytes(8, "big"))
        h.update(field)
    return h.digest()


def json_dump(path, payload: object) -> None:
    from pathlib import Path
    target = Path(path)
    target.write_text(json.dumps(payload, ensure_ascii=False, sort_keys=True) + "\n", encoding="utf-8")
    target.chmod(0o600)
