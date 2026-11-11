from __future__ import annotations

import argparse
import configparser
import errno
import hashlib
import json
import os
import pwd
import re
import signal
import stat
import subprocess
import sys
from pathlib import Path

import pymysql

from catalog import SCHEMA, mysql_connection
from common import open_directory_chain, open_file_from_directory

READER_USER = "pgloader"
READER_HOST = "127.0.0.1"
MYSQL_PORT = 3306
EXPECTED_TABLES = 270
V10E_DATABASE = "erp_ensaio_v10e"
V10E_BRANCH = "pg/03-4-fix-mysql-source-provisioning"
V10E_ROOT = Path("/var/lib/erp-pg-rehearsal/v10e")
V10E_SECRETS = V10E_ROOT / "secrets"
V10E_CONTAINER_SECRETS = V10E_ROOT / "mysql-secrets"
V10E_MANIFEST = V10E_SECRETS / "source-manifest.json"
V10E_DUMP = V10E_ROOT / "source-copy.sql.gz"
V10E_MYSQL_DATA = V10E_ROOT / "mysql-data"
V10E_MYSQL_SOCKET = V10E_ROOT / "mysql-socket"
V10E_CONTAINER = "erp-mysql-source-v10e"
V10E_REPOSITORY_URL = "https://github.com/ASOARESBH/app.erpcondominios.com.br-.git"
V10E_C2_COMMIT = "c4703e9e153fd2d156f8c9dab398c12d72dd19cf"
V10E_BASELINE_COMMIT = "f60739713e6de3f2473b79ecd14a70fd700e5462"
INTERRUPTED = False


class ProvisioningError(RuntimeError):
    def __init__(self, code: str):
        self.code = code
        super().__init__(code)


def record_interruption(_signum, _frame):
    global INTERRUPTED
    INTERRUPTED = True


def reject_interrupted_operation():
    if INTERRUPTED:
        raise ProvisioningError("interrupted")


def read_private_client_config(path: str | Path, expected_uid: int = 0) -> dict[str, str]:
    config_path = Path(path)
    fd = None
    parser = configparser.RawConfigParser()
    try:
        fd = open_file_from_directory(config_path, expected_parent_uid=expected_uid,
                                       expected_parent_mode=0o700)
        item = os.fstat(fd)
        if (not stat.S_ISREG(item.st_mode) or item.st_uid != expected_uid or item.st_nlink != 1
                or stat.S_IMODE(item.st_mode) not in (0o400, 0o600) or item.st_size > 4096):
            raise ProvisioningError("client_config_unsafe")
        with os.fdopen(fd, "r", encoding="utf-8") as stream:
            fd = None
            parser.read_file(stream)
        if not parser.has_section("client"):
            raise ProvisioningError("client_config_invalid")
        return {key: value for key, value in parser.items("client")}
    except OSError as exc:
        if exc.errno in (errno.ELOOP, errno.EISDIR):
            raise ProvisioningError("client_config_unsafe") from None
        raise ProvisioningError("client_config_unavailable") from None
    except (configparser.Error, UnicodeError):
        raise ProvisioningError("client_config_invalid") from None
    finally:
        if fd is not None:
            os.close(fd)


def validate_root_secret_pair(expected_uid: int = 0) -> None:
    bundle = read_container_secret_bundle(expected_uid)
    parser = configparser.RawConfigParser()
    try:
        parser.read_string(bundle["client.cnf"].decode("utf-8", errors="strict"))
    except (configparser.Error, UnicodeError):
        raise ProvisioningError("admin_config_invalid") from None
    if not parser.has_section("client"):
        raise ProvisioningError("admin_config_invalid")
    admin = {key: value for key, value in parser.items("client")}
    if (admin.get("user") != "root" or not admin.get("password")
            or admin.get("host", "127.0.0.1") not in {"127.0.0.1", "localhost"}
            or admin.get("port", "3306") != "3306"
            or admin.get("protocol", "TCP").upper() != "TCP"):
        raise ProvisioningError("admin_config_invalid")
    password = bundle["root.pass"]
    try:
        decoded_password = password.decode("utf-8", errors="strict")
    except UnicodeDecodeError:
        raise ProvisioningError("v10e_root_secret_pair_mismatch") from None
    if decoded_password != admin["password"] or b"\n" in password or b"\r" in password:
        raise ProvisioningError("v10e_root_secret_pair_mismatch")


def read_container_secret_bundle(expected_uid: int = 0) -> dict[str, bytes]:
    directory_fd = None
    try:
        directory_fd = open_directory_chain(V10E_CONTAINER_SECRETS)
        directory = os.fstat(directory_fd)
        with os.scandir(directory_fd) as stream:
            names = {entry.name for entry in stream}
        if (not stat.S_ISDIR(directory.st_mode) or directory.st_uid != expected_uid
                or stat.S_IMODE(directory.st_mode) != 0o700 or names != {"client.cnf", "root.pass"}):
            raise ProvisioningError("v10e_container_secret_bundle_invalid")
        contents = {}
        for name in names:
            item_fd = os.open(name, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0), dir_fd=directory_fd)
            try:
                item = os.fstat(item_fd)
                if (not stat.S_ISREG(item.st_mode) or item.st_uid != expected_uid or item.st_nlink != 1
                        or stat.S_IMODE(item.st_mode) != 0o400 or item.st_size > 4096):
                    raise ProvisioningError("v10e_container_secret_bundle_invalid")
                with os.fdopen(item_fd, "rb") as stream:
                    item_fd = -1
                    payload = stream.read(4097)
                if len(payload) > 4096:
                    raise ProvisioningError("v10e_container_secret_bundle_invalid")
                contents[name] = payload
            finally:
                if item_fd >= 0:
                    os.close(item_fd)
        return contents
    except OSError as exc:
        if exc.errno in (errno.ELOOP, errno.EISDIR):
            raise ProvisioningError("v10e_container_secret_bundle_invalid") from None
        raise ProvisioningError("v10e_container_secret_bundle_unavailable") from None
    finally:
        if directory_fd is not None:
            os.close(directory_fd)


def validate_container_secret_bundle(expected_uid: int = 0) -> None:
    read_container_secret_bundle(expected_uid)


def validate_reader_options(options: dict[str, str]) -> str:
    if options.get("user") != READER_USER:
        raise ProvisioningError("reader_config_user_mismatch")
    if options.get("host", READER_HOST) != READER_HOST or options.get("port", str(MYSQL_PORT)) != str(MYSQL_PORT):
        raise ProvisioningError("reader_config_endpoint_mismatch")
    if options.get("protocol", "TCP").upper() != "TCP":
        raise ProvisioningError("reader_config_endpoint_mismatch")
    password = options.get("password", "")
    if not password:
        raise ProvisioningError("reader_config_password_missing")
    return password


def validate_network_namespace(expected_inode: int) -> None:
    if expected_inode <= 0:
        raise ProvisioningError("network_namespace_invalid")
    try:
        actual_inode = os.stat("/proc/self/ns/net").st_ino
    except OSError:
        raise ProvisioningError("network_namespace_unavailable") from None
    if actual_inode != expected_inode:
        raise ProvisioningError("network_namespace_mismatch")


def validate_config_targets(admin_config: str | Path, reader_config: str | Path) -> None:
    if (Path(admin_config) != V10E_CONTAINER_SECRETS / "client.cnf"
            or Path(reader_config) != V10E_SECRETS / "pgloader.cnf"):
        raise ProvisioningError("provisioner_config_target_mismatch")


def validate_v10e_clone(expected_commit: str, expected_version: str, expected_netns_inode: int,
                         expected_uid: int = 0) -> None:
    if not re.fullmatch(r"[0-9a-f]{40}", expected_commit) or not expected_version.startswith("5.7."):
        raise ProvisioningError("v10e_execution_parameters_invalid")
    repository = Path(__file__).resolve().parents[2]
    try:
        origin = subprocess.run(["git", "-C", str(repository), "remote", "get-url", "origin"],
                                capture_output=True, text=True, check=False, timeout=15)
        branch = subprocess.run(["git", "-C", str(repository), "branch", "--show-current"],
                                capture_output=True, text=True, check=False, timeout=15)
        head = subprocess.run(["git", "-C", str(repository), "rev-parse", "HEAD"],
                              capture_output=True, text=True, check=False, timeout=15)
        status = subprocess.run(["git", "-C", str(repository), "status", "--porcelain",
                                 "--untracked-files=all"], capture_output=True, text=True,
                                check=False, timeout=15)
        baseline = subprocess.run(["git", "-C", str(repository), "merge-base", "--is-ancestor",
                                   V10E_BASELINE_COMMIT, expected_commit], capture_output=True,
                                  check=False, timeout=15)
        c2 = subprocess.run(["git", "-C", str(repository), "merge-base", "--is-ancestor",
                             V10E_C2_COMMIT, expected_commit], capture_output=True,
                            check=False, timeout=15)
    except Exception:
        raise ProvisioningError("v10e_git_provenance_unavailable") from None
    if (origin.returncode or origin.stdout.strip() != V10E_REPOSITORY_URL
            or branch.returncode or branch.stdout.strip() != V10E_BRANCH
            or head.returncode or head.stdout.strip() != expected_commit
            or status.returncode or status.stdout.strip()
            or baseline.returncode or c2.returncode):
        raise ProvisioningError("v10e_git_provenance_mismatch")
    fd = None
    try:
        postgres_uid = pwd.getpwnam("postgres").pw_uid
        root_fd = open_directory_chain(V10E_ROOT)
        try:
            root_info = os.fstat(root_fd)
            if (not stat.S_ISDIR(root_info.st_mode) or root_info.st_uid != postgres_uid
                    or stat.S_IMODE(root_info.st_mode) != 0o700):
                raise ProvisioningError("v10e_root_directory_unsafe")
        finally:
            os.close(root_fd)
        validate_root_secret_pair(expected_uid)
        fd = open_file_from_directory(V10E_MANIFEST, expected_parent_uid=expected_uid,
                                      expected_parent_mode=0o700)
        item = os.fstat(fd)
        if (not stat.S_ISREG(item.st_mode) or item.st_uid != expected_uid or item.st_nlink != 1
                or stat.S_IMODE(item.st_mode) != 0o600 or item.st_size > 4096):
            raise ProvisioningError("v10e_manifest_unsafe")
        with os.fdopen(fd, "r", encoding="utf-8") as stream:
            fd = None
            manifest = json.load(stream)
    except ProvisioningError:
        raise
    except Exception:
        raise ProvisioningError("v10e_manifest_unavailable") from None
    finally:
        if fd is not None:
            os.close(fd)
    expected_fields = {
        "target_database", "code_branch", "code_commit", "source_schema", "dump_sha256",
        "dump_bytes", "mysql_version", "container_name", "container_id", "image_name",
        "image_id", "restore_status", "restored_at_utc",
    }
    if (not isinstance(manifest, dict) or set(manifest) != expected_fields
            or manifest.get("target_database") != V10E_DATABASE
            or manifest.get("code_branch") != V10E_BRANCH
            or manifest.get("code_commit") != expected_commit
            or not re.fullmatch(r"[0-9a-f]{40}", str(manifest.get("code_commit", "")))
            or manifest.get("source_schema") != SCHEMA
            or not re.fullmatch(r"[0-9a-f]{64}", str(manifest.get("dump_sha256", "")))
            or type(manifest.get("dump_bytes")) is not int or manifest["dump_bytes"] <= 0
            or manifest.get("mysql_version") != expected_version
            or manifest.get("container_name") != V10E_CONTAINER
            or not re.fullmatch(r"[0-9a-f]{64}", str(manifest.get("container_id", "")))
            or manifest.get("image_name") != "docker.io/library/mysql:5.7"
            or not re.fullmatch(r"sha256:[0-9a-f]{64}", str(manifest.get("image_id", "")))
            or not re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z",
                                str(manifest.get("restored_at_utc", "")))
            or manifest.get("restore_status") != "complete"):
        raise ProvisioningError("v10e_manifest_contract_mismatch")
    fd = None
    try:
        postgres_uid = pwd.getpwnam("postgres").pw_uid
        fd = open_file_from_directory(V10E_DUMP, expected_parent_uid=postgres_uid,
                                      expected_parent_mode=0o700)
        dump_info = os.fstat(fd)
        if (not stat.S_ISREG(dump_info.st_mode) or dump_info.st_uid != expected_uid or dump_info.st_nlink != 1
                or stat.S_IMODE(dump_info.st_mode) != 0o400 or dump_info.st_size != manifest["dump_bytes"]):
            raise ProvisioningError("v10e_dump_metadata_mismatch")
        digest = hashlib.sha256()
        for block in iter(lambda: os.read(fd, 1024 * 1024), b""):
            digest.update(block)
        os.lseek(fd, 0, os.SEEK_SET)
        gzip_check = subprocess.run(["gzip", "-t", "-q", f"/proc/self/fd/{fd}"],
                                    pass_fds=(fd,), capture_output=True, check=False, timeout=300)
        if gzip_check.returncode:
            raise ProvisioningError("v10e_dump_compression_invalid")
    except ProvisioningError:
        raise
    except Exception:
        raise ProvisioningError("v10e_dump_unavailable") from None
    finally:
        if fd is not None:
            os.close(fd)
    if digest.hexdigest() != manifest["dump_sha256"]:
        raise ProvisioningError("v10e_dump_hash_mismatch")
    if expected_netns_inode <= 0:
        raise ProvisioningError("network_namespace_invalid")
    result = subprocess.run(["podman", "inspect", V10E_CONTAINER], capture_output=True,
                            text=True, timeout=30)
    if result.returncode:
        raise ProvisioningError("v10e_container_unavailable")
    try:
        container = json.loads(result.stdout)[0]
        state = container["State"]
        host = container["HostConfig"]
        config = container["Config"]
        pid = int(state["Pid"])
        actual_mounts = {(mount.get("Type"), mount.get("Destination"), mount.get("Source"), mount.get("RW"))
                         for mount in container["Mounts"]}
        env = set(config.get("Env") or [])
        args = set(container.get("Args") or [])
        netns_inode = os.stat(f"/proc/{pid}/ns/net").st_ino
    except Exception:
        raise ProvisioningError("v10e_container_metadata_unavailable") from None
    expected_mounts = {
        ("bind", "/var/lib/mysql", str(V10E_MYSQL_DATA), True),
        ("bind", "/var/run/mysqld", str(V10E_MYSQL_SOCKET), True),
        ("bind", "/run/rehearsal-secrets", str(V10E_CONTAINER_SECRETS), False),
    }
    if (container.get("Name") != f"/{V10E_CONTAINER}"
            or container.get("Id") != manifest.get("container_id")
            or container.get("Image") != manifest.get("image_id")
            or container.get("ImageName") != manifest.get("image_name")
            or state.get("Running") is not True or pid <= 1
            or netns_inode != expected_netns_inode
            or os.stat("/proc/self/ns/net").st_ino != expected_netns_inode
            or container.get("AppArmorProfile") != "erp-mysql-rehearsal"
            or host.get("NetworkMode") != "none" or host.get("PortBindings") not in (None, {})
            or host.get("Privileged") is not False or host.get("CapAdd") not in (None, [])
            or "no-new-privileges" not in (host.get("SecurityOpt") or [])
            or "apparmor=erp-mysql-rehearsal" not in (host.get("SecurityOpt") or [])
            or actual_mounts != expected_mounts
            or not {"--bind-address=127.0.0.1", "--event-scheduler=OFF", "--local-infile=0",
                    "--secure-file-priv=NULL", "--default-time-zone=-03:00",
                    "--innodb-buffer-pool-size=512M", "--max-allowed-packet=256M"}.issubset(args)
            or not {"MYSQL_DATABASE=inlaud99_erpcondor",
                    "MYSQL_ROOT_PASSWORD_FILE=/run/rehearsal-secrets/root.pass",
                    "MYSQL_INITDB_SKIP_TZINFO=1"}.issubset(env)):
        raise ProvisioningError("v10e_container_isolation_mismatch")


def _source_state(cursor) -> tuple[str, int, int, int, str]:
    cursor.execute("SELECT CURRENT_USER()")
    admin_identity = cursor.fetchone()
    if admin_identity is None or str(admin_identity[0]) not in {"root@localhost", "root@127.0.0.1"}:
        raise ProvisioningError("admin_identity_invalid")
    cursor.execute("SELECT VERSION(),@@port,@@global.read_only,@@global.super_read_only,@@global.event_scheduler")
    row = cursor.fetchone()
    if row is None or len(row) != 5:
        raise ProvisioningError("source_state_unavailable")
    return str(row[0]), int(row[1]), int(row[2]), int(row[3]), str(row[4]).upper()


def _validate_source_state(state: tuple[str, int, int, int, str], *, frozen: bool,
                           expected_version: str | None = None) -> None:
    version, port, read_only, super_read_only, event_scheduler = state
    if (not version.startswith("5.7.") or port != MYSQL_PORT or event_scheduler != "OFF"
            or (expected_version is not None and version != expected_version)):
        raise ProvisioningError("source_runtime_mismatch")
    if frozen and (read_only != 1 or super_read_only != 1):
        raise ProvisioningError("source_read_only_guard_failed")


def _provision_account(admin_conn, password: str, expected_version: str | None = None,
                       audit_callback=None) -> None:
    with admin_conn.cursor() as cursor:
        state = _source_state(cursor)
        _validate_source_state(state, frozen=True, expected_version=expected_version)
        cursor.execute("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=%s", (SCHEMA,))
        schema_rows = cursor.fetchone()
        if schema_rows is None or int(schema_rows[0]) != 1:
            raise ProvisioningError("source_schema_missing")
        cursor.execute(
            "SELECT COUNT(*) FROM mysql.user WHERE BINARY User=BINARY %s AND BINARY Host=BINARY %s",
            (READER_USER, READER_HOST),
        )
        account_rows = cursor.fetchone()
        if account_rows is None or int(account_rows[0]) not in (0, 1):
            raise ProvisioningError("reader_account_state_invalid")
        reject_interrupted_operation()

        freeze_change_attempted = False
        provisioning_error = None
        freeze_restore_failed = False
        try:
            freeze_change_attempted = True
            cursor.execute("SET GLOBAL super_read_only=OFF")
            if audit_callback:
                audit_callback("super_read_only_off_applied")
            reject_interrupted_operation()
            cursor.execute("SET GLOBAL read_only=OFF")
            if audit_callback:
                audit_callback("read_only_off_applied")
            reject_interrupted_operation()
            if int(account_rows[0]) == 0:
                cursor.execute(
                    "CREATE USER IF NOT EXISTS 'pgloader'@'127.0.0.1' IDENTIFIED BY %s",
                    (password,),
                )
            else:
                cursor.execute("ALTER USER 'pgloader'@'127.0.0.1' IDENTIFIED BY %s", (password,))
            reject_interrupted_operation()
            cursor.execute("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'pgloader'@'127.0.0.1'")
            cursor.execute(
                "GRANT SELECT, SHOW VIEW, TRIGGER ON `inlaud99_erpcondor`.* TO 'pgloader'@'127.0.0.1'"
            )
        except ProvisioningError as exc:
            provisioning_error = exc.code
        except Exception:
            provisioning_error = "reader_account_provision_failed"
        finally:
            if freeze_change_attempted:
                try:
                    cursor.execute("SET GLOBAL read_only=ON")
                except Exception:
                    freeze_restore_failed = True
                try:
                    cursor.execute("SET GLOBAL super_read_only=ON")
                except Exception:
                    freeze_restore_failed = True
        if freeze_restore_failed:
            if audit_callback:
                audit_callback("read_only_restore_failed")
            raise ProvisioningError("source_freeze_restore_failed")
        try:
            state = _source_state(cursor)
            _validate_source_state(state, frozen=True, expected_version=expected_version)
        except Exception:
            if audit_callback:
                audit_callback("read_only_restore_unverified")
            raise ProvisioningError("source_freeze_restore_failed") from None
        if audit_callback:
            audit_callback("read_only_restored_verified")
        if provisioning_error:
            raise ProvisioningError(provisioning_error)
        reject_interrupted_operation()


def validate_reader_connection(reader_config: str | Path, connector=None) -> int:
    conn = None
    try:
        if connector is None:
            connector = mysql_connection
        conn = connector(str(reader_config))
        with conn.cursor() as cursor:
            cursor.execute("SELECT CURRENT_USER(),@@global.read_only,@@global.super_read_only,@@global.event_scheduler")
            identity = cursor.fetchone()
            if identity is None or tuple(map(str, identity)) != ("pgloader@127.0.0.1", "1", "1", "OFF"):
                raise ProvisioningError("reader_identity_or_flags_invalid")
            cursor.execute(
                "SELECT COUNT(*) FROM information_schema.TABLES "
                "WHERE TABLE_SCHEMA=%s AND TABLE_TYPE='BASE TABLE'",
                (SCHEMA,),
            )
            tables = cursor.fetchone()
            if tables is None or int(tables[0]) != EXPECTED_TABLES:
                raise ProvisioningError("source_catalog_probe_failed")
        return int(tables[0])
    except ProvisioningError:
        raise
    except Exception:
        raise ProvisioningError("reader_authentication_or_catalog_failed") from None
    finally:
        if conn is not None:
            try:
                conn.rollback()
            except Exception:
                pass
            try:
                conn.close()
            except Exception:
                pass


def provision_mysql_reader(admin_config: str | Path, reader_config: str | Path,
                           expected_version: str, expected_commit: str,
                           expected_netns_inode: int, audit_callback=None) -> int:
    validate_config_targets(admin_config, reader_config)
    validate_network_namespace(expected_netns_inode)
    validate_v10e_clone(expected_commit, expected_version, expected_netns_inode)
    admin = read_private_client_config(admin_config)
    reader = read_private_client_config(reader_config)
    password = validate_reader_options(reader)
    admin_user = admin.get("user", "")
    admin_password = admin.get("password", "")
    if admin_user != "root" or not admin_password:
        raise ProvisioningError("admin_config_invalid")
    try:
        admin_conn = pymysql.connect(
            user=admin_user,
            password=admin_password,
            host=READER_HOST,
            port=MYSQL_PORT,
            database="mysql",
            charset="utf8mb4",
            autocommit=True,
            connect_timeout=10,
            read_timeout=30,
            write_timeout=10,
        )
    except Exception:
        raise ProvisioningError("admin_authentication_failed") from None
    try:
        _provision_account(admin_conn, password, expected_version, audit_callback)
    finally:
        admin_conn.close()
    return validate_reader_connection(reader_config)


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="Provision the isolated MySQL rehearsal reader")
    parser.add_argument("--admin-config", required=True)
    parser.add_argument("--reader-config", required=True)
    parser.add_argument("--expected-commit", required=True)
    parser.add_argument("--expected-mysql-version", required=True)
    parser.add_argument("--expected-netns-inode", required=True, type=int)
    args = parser.parse_args(argv)
    if os.geteuid() != 0:
        print("MYSQL_READER_PROVISION_FAILED type=ProvisioningError code=must_run_as_root")
        return 1
    try:
        validate_config_targets(args.admin_config, args.reader_config)
    except ProvisioningError:
        print("MYSQL_READER_PROVISION_FAILED type=ProvisioningError code=provisioner_config_target_mismatch")
        return 1
    global INTERRUPTED
    INTERRUPTED = False
    signal.signal(signal.SIGTERM, record_interruption)
    signal.signal(signal.SIGINT, record_interruption)
    def audit_read_only_step(step: str) -> None:
        allowed = {
            "super_read_only_off_applied", "read_only_off_applied",
            "read_only_restore_failed", "read_only_restore_unverified",
            "read_only_restored_verified",
        }
        if step in allowed:
            print(f"MYSQL_SOURCE_FREEZE_EVENT step={step} target=erp_ensaio_v10e", flush=True)
    try:
        table_count = provision_mysql_reader(args.admin_config, args.reader_config,
                                             expected_version=args.expected_mysql_version,
                                             expected_commit=args.expected_commit,
                                             expected_netns_inode=args.expected_netns_inode,
                                             audit_callback=audit_read_only_step)
    except ProvisioningError as exc:
        print(f"MYSQL_READER_PROVISION_FAILED type=ProvisioningError code={exc.code}")
        return 1
    except Exception:
        print("MYSQL_READER_PROVISION_FAILED type=ProvisioningError code=unclassified")
        return 1
    print(
        "MYSQL_READER_PROVISIONED identity_validated=true grants_minimal=true "
        f"read_only=true event_scheduler_off=true catalog_tables={table_count}"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
