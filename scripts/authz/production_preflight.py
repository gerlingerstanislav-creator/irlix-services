#!/usr/bin/env python3
"""Protected production AuthZ V2 preflight.

Only aggregate status is logged. Real matrix and audit rows stay on host.
Restore tests use disposable PostgreSQL databases, never the live database.
Not a replacement for signed role/object E2E or live flag rollback.
"""
import fcntl
import json
import os
import re
import secrets
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path("/opt/irlix-services")
BACKUPS = Path("/opt/irlix-authz-backups")
SERVICE_ORDER = (
    "clients", "timesheets", "vacations", "specialists",
    "recruitment", "equipment", "finance",
)


def container_command(compose, script, *args, stdin=None, timeout=90):
    completed = subprocess.run(
        [*compose, "exec", "-T", "postgres", "sh", "-c", script, "authz-preflight", *args],
        stdin=stdin, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        cwd=ROOT, timeout=timeout, check=False,
    )
    # The raw stderr from postgres could contain private identifiers.
    return completed.returncode == 0


def restored_matrix_count(compose, db, service, matrix):
    # Only whitelisted schema/table names are passed, and only counts leave DB.
    statement = 'PGPASSWORD="$POSTGRES_PASSWORD" psql -X -A -t -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$1" -c "SELECT count(*) FROM $2.$3"'
    result = subprocess.run(
        [*compose, "exec", "-T", "postgres", "sh", "-c", statement,
         "authz-preflight", db, service, matrix],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, cwd=ROOT,
        timeout=30, check=False,
    )
    value = result.stdout.strip()
    if result.returncode != 0 or re.fullmatch(rb"[0-9]+", value) is None:
        return None
    return int(value)


def restore_into_disposable_database(compose, archive, service, matrix, expected_rows):
    """Rehearse pg_restore without touching any live policy rows."""
    db = "irlix_authz_restorecheck_" + secrets.token_hex(8)
    if re.fullmatch(r"irlix_authz_restorecheck_[a-f0-9]{16}", db) is None:
        raise RuntimeError("Invalid temporary database name")
    create = 'PGPASSWORD="$POSTGRES_PASSWORD" psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE $1"'
    drop = 'PGPASSWORD="$POSTGRES_PASSWORD" psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres -c "DROP DATABASE $1 WITH (FORCE)"'
    schema = 'PGPASSWORD="$POSTGRES_PASSWORD" psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$1" -c "CREATE SCHEMA $2"'
    restore = 'PGPASSWORD="$POSTGRES_PASSWORD" pg_restore --exit-on-error --no-owner --no-acl -U "$POSTGRES_USER" -d "$1"'
    if not container_command(compose, create, db):
        raise RuntimeError("Unable to allocate isolated restore database")
    ok = False
    cleaned = False
    try:
        if container_command(compose, schema, db, service):
            with archive.open("rb") as contents:
                ok = container_command(compose, restore, db, stdin=contents, timeout=120)
            if ok:
                ok = restored_matrix_count(compose, db, service, matrix) == expected_rows
    finally:
        cleaned = container_command(compose, drop, db, timeout=60)
    if not cleaned:
        raise RuntimeError("Isolated restore database cleanup failed")
    if not ok:
        raise RuntimeError("Isolated matrix/audit database restore failed")


def snapshot_and_rehearse(service, compose, backup, expected_rows):
    existing = set(BACKUPS.glob(f"matrix-{service}-*/authz-matrix.dump"))
    backup.snapshot(service)
    created = set(BACKUPS.glob(f"matrix-{service}-*/authz-matrix.dump")) - existing
    if len(created) != 1:
        raise RuntimeError(f"No unique protected archive was observed for {service}")
    archive = created.pop()
    if archive.is_symlink() or archive.parent.is_symlink():
        raise RuntimeError(f"Unexpected backup symlink for {service}")
    st = archive.stat()
    parent = archive.parent.stat()
    if st.st_uid != 0 or st.st_mode & 0o077 or parent.st_uid != 0 or parent.st_mode & 0o077:
        raise RuntimeError(f"Protected archive permissions invalid for {service}")
    restore_into_disposable_database(
        compose, archive, service, backup.TABLES[service][0], expected_rows,
    )


def check_readiness(probe, compose):
    evidence = probe.collect(compose, [])
    if not evidence.get("jwt_gate_consistent") or evidence.get("keycloak_role_sync") != "ready":
        raise RuntimeError("Identity gate or Keycloak role sync not ready")
    if not evidence.get("all_local_flags_disabled"):
        raise RuntimeError("Local V2 flags must remain disabled until full acceptance")
    if evidence.get("missing_critical_grants"):
        raise RuntimeError("Critical V2 coverage is incomplete")
    matrices = evidence.get("local_v2", {})
    if set(matrices) != set(SERVICE_ORDER):
        raise RuntimeError("Unrecognized service matrix inventory")
    for service in SERVICE_ORDER:
        state = matrices[service]
        if (state.get("flag") != "disabled"
                or state.get("matrix", {}).get("status") != "ok"
                or state.get("critical_coverage", {}).get("status") != "ok"
                or state["matrix"].get("rows", 0) < 1):
            raise RuntimeError(f"Invalid authorization readiness for {service}")
    return {service: matrices[service]["matrix"]["rows"] for service in SERVICE_ORDER}


def write_private_receipt(results):
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    path = BACKUPS / f"preflight-{stamp}-{secrets.token_hex(6)}.json"
    receipt = {
        "format": "irlix-local-authz-preflight-v1",
        "created_at_utc": datetime.now(timezone.utc).isoformat(),
        "matrix_archive_verified": results,
        "isolated_matrix_restore": results,
        "signed_role_object_e2e": "NOT TESTED",
        "per_service_live_flag_rollback": "NOT TESTED",
        "cutover_accepted": False,
    }
    with path.open("x", encoding="utf-8") as handle:
        json.dump(receipt, handle, sort_keys=True)
        handle.flush()
        os.fsync(handle.fileno())
    path.chmod(0o600)


def main():
    if os.geteuid() != 0 or not ROOT.is_dir():
        raise RuntimeError("Production host root required")
    os.umask(0o077)
    BACKUPS.mkdir(mode=0o700, parents=True, exist_ok=True)
    st = BACKUPS.stat()
    if BACKUPS.is_symlink() or st.st_uid != 0 or st.st_mode & 0o077:
        raise RuntimeError("Protected archive root is not root-only")
    sys.path.insert(0, str(ROOT / "scripts" / "authz"))
    import inspect_runtime as probe
    import backup_local_matrices as backup
    if set(backup.TABLES) != set(SERVICE_ORDER):
        raise RuntimeError("Unexpected production backup table catalog")
    with (BACKUPS / ".production-preflight.lock").open("a+") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        compose = probe.default_production_compose()
        if not compose:
            raise RuntimeError("Unable to resolve exact production Compose configuration")
        expected = check_readiness(probe, compose)
        results = {}
        for service in SERVICE_ORDER:
            snapshot_and_rehearse(service, compose, backup, expected[service])
            results[service] = "PASS"
            print(f"[authz-preflight] {service}: protected archive + isolated restore PASS", flush=True)
        write_private_receipt(results)
        print("[authz-preflight] 7/7 backups and isolated restore PASS; role/object E2E and live flag rollback NOT TESTED; 0/7 activated.", flush=True)


if __name__ == "__main__":
    try:
        main()
    except Exception:
        # Never forward raw postgres errors, credentials, or policy row details.
        print("[authz-preflight] FAIL: protected preflight did not complete. No V2 flags were changed.", file=sys.stderr)
        sys.exit(1)
