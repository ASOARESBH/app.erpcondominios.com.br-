from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import tempfile

from .ddl import DdlGenerationError, canonical_fingerprint, generate_ddl


def _write_new_file(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temp_name = tempfile.mkstemp(prefix="." + path.name + ".", dir=path.parent)
    try:
        content = content.rstrip("\n") + "\n"
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temp_name, 0o644)
        os.link(temp_name, path)
    except FileExistsError as error:
        raise SystemExit("OUTPUT_ALREADY_EXISTS") from error
    finally:
        try:
            os.unlink(temp_name)
        except FileNotFoundError:
            pass


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("DUPLICATE_JSON_KEY")
        result[key] = value
    return result


def _generator_fingerprint() -> str:
    digest = hashlib.sha256()
    for name in ("generate_ddl.py", "ddl.py", "generated_columns.py", "type_mapping.py", "070_business_triggers_tenant_scoped.sql"):
        digest.update(name.encode("utf-8") + b"\0")
        path = (Path(__file__).resolve().parents[2] / "sql" / "postgresql" / name) if name.endswith(".sql") else Path(__file__).with_name(name)
        digest.update(path.read_bytes())
        digest.update(b"\0")
    return digest.hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser(description="Gera DDL PostgreSQL revisável de um snapshot sanitizado")
    parser.add_argument("--snapshot", required=True, type=Path)
    parser.add_argument("--collation-overrides", type=Path)
    parser.add_argument("--application-role")
    parser.add_argument("--output-dir", required=True, type=Path)
    args = parser.parse_args()
    try:
        snapshot_text = args.snapshot.read_text(encoding="utf-8")
        snapshot = json.loads(snapshot_text, object_pairs_hook=_unique_object)
    except (OSError, json.JSONDecodeError, ValueError):
        raise SystemExit("SNAPSHOT_INVALID")
    if not isinstance(snapshot, dict) or canonical_fingerprint(snapshot) != snapshot.get("fingerprint_sha256"):
        raise SystemExit("SNAPSHOT_FINGERPRINT_MISMATCH")
    fingerprint = str(snapshot["fingerprint_sha256"])
    snapshot_artifact = snapshot_text if snapshot_text.endswith("\n") else snapshot_text + "\n"
    snapshot_sha256 = hashlib.sha256(snapshot_artifact.encode("utf-8")).hexdigest()
    overrides = {}
    if args.collation_overrides:
        try:
            overrides = json.loads(args.collation_overrides.read_text(encoding="utf-8"), object_pairs_hook=_unique_object)
        except (OSError, json.JSONDecodeError, ValueError):
            raise SystemExit("COLLATION_OVERRIDES_INVALID")
        if not isinstance(overrides, dict) or any(not isinstance(k, str) or not isinstance(v, str) or v not in {"ci", "C"} for k, v in overrides.items()):
            raise SystemExit("COLLATION_OVERRIDES_INVALID")
    override_json = json.dumps(overrides, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    override_hash = hashlib.sha256(override_json.encode("utf-8")).hexdigest()
    role_token = args.application_role if args.application_role is not None else "(unassigned)"
    role_hash = hashlib.sha256(role_token.encode("utf-8")).hexdigest()
    generator_hash = _generator_fingerprint()
    directory_name = f"catalog-{fingerprint[:12]}-collation-{override_hash[:8]}-role-{role_hash[:8]}-code-{generator_hash[:8]}"
    output_dir = args.output_dir / directory_name
    if output_dir.exists():
        raise SystemExit("OUTPUT_ALREADY_EXISTS")
    try:
        bundle = generate_ddl(snapshot, collation_overrides=overrides, application_role=args.application_role)
    except DdlGenerationError as error:
        raise SystemExit(error.args[0] if error.args else "DDL_GENERATION_BLOCKED")

    bundle.report["generator_code_sha256"] = generator_hash
    bundle.report["application_role_sha256"] = role_hash
    bundle.report["snapshot_file_sha256"] = snapshot_sha256
    safety_header = "-- LOAD ARTIFACT: DO NOT APPLY unless report.json has load_executable_complete=true and required load reviews are approved.\n"
    script_artifacts = {
        filename: (safety_header + f"-- Generator code SHA-256: {generator_hash}\n" + content).rstrip("\n") + "\n"
        for filename, content in bundle.scripts.items()
    }
    bundle.report["generated_scripts_sha256"] = {
        filename: hashlib.sha256(content.encode("utf-8")).hexdigest()
        for filename, content in sorted(script_artifacts.items())
    }
    output_dir.parent.mkdir(parents=True, exist_ok=True)
    output_dir.mkdir(mode=0o755, exist_ok=False)
    try:
        for filename, content in script_artifacts.items():
            _write_new_file(output_dir / filename, content)
        _write_new_file(output_dir / "catalog_snapshot.json", snapshot_artifact)
        report_path = output_dir / "report.json"
        _write_new_file(report_path, json.dumps(bundle.report, ensure_ascii=False, indent=2, sort_keys=True) + "\n")
        _write_new_file(output_dir / "BUNDLE_COMPLETE", "bundle_complete=true\n")
    except BaseException:
        for child in output_dir.iterdir():
            child.unlink(missing_ok=True)
        output_dir.rmdir()
        raise
    print(json.dumps({
        "catalog_fingerprint_sha256": fingerprint,
        "snapshot_file_sha256": snapshot_sha256,
        "collation_overrides_sha256": override_hash,
        "application_role_sha256": role_hash,
        "generator_code_sha256": generator_hash,
        "output_directory": str(output_dir),
        "load_executable_complete": bundle.report["load_executable_complete"],
        "executable_complete": bundle.report["executable_complete"],
        "review_items": len(bundle.report["review_items"]),
        "blocked_tables": len(bundle.report["blocked_tables"]),
        "unsupported_indexes": len(bundle.report["unsupported_indexes"]),
        "deferred_indexes": len(bundle.report["deferred_indexes"]),
        "foreign_keys_generated_not_valid": bundle.report["foreign_keys"]["generated_not_valid"],
        "foreign_keys_deferred": len(bundle.report["foreign_keys"]["deferred"]),
    }, sort_keys=True))
    return 0 if bundle.report["load_executable_complete"] else 2


if __name__ == "__main__":
    raise SystemExit(main())
