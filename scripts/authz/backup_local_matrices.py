#!/usr/bin/env python3
"""Private, read-only pre-migration snapshot of one owned AuthZ matrix.

No credentials, individual roles or archived content are printed. Refuse to
deploy if the matrix and audit cannot both be backed up and verified.
"""
import os
import secrets
import shlex
import subprocess
import sys
from pathlib import Path

ROOT = Path("/opt/irlix-services")
TABLES = {
    "clients": ("client_contour_permissions", "client_contour_permission_audit"),
    "timesheets": ("timesheets_role_permissions", "timesheets_permission_audit"),
    "vacations": ("vacations_role_permissions", "vacations_permission_audit"),
    "recruitment": ("recruitment_role_permissions", "recruitment_permission_audit"),
    "specialists": ("specialists_role_permissions", "specialists_permission_audit"),
    "finance": ("finance_role_permissions", "finance_permission_audit"),
    "equipment": ("equipment_role_permissions", "equipment_permission_audit"),
}


def snapshot(service: str) -> None:
    if service not in TABLES or os.geteuid() != 0:
        raise RuntimeError("Only approved service owners and host root may snapshot")
    os.umask(0o077)
    compose = shlex.split(subprocess.check_output(
        [sys.executable, "scripts/ci/service_plan.py", "compose-args",
         "--env-file", ".env"], cwd=ROOT, text=True, timeout=20))
    matrix, audit = TABLES[service]
    stamp = __import__("datetime").datetime.now(
        __import__("datetime").timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    directory = Path("/opt/irlix-authz-backups") / (
        f"matrix-{service}-{stamp}-{secrets.token_hex(5)}")
    directory.mkdir(parents=True, mode=0o700)
    path = directory / "authz-matrix.dump"
    script = (
        'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" '
        '-d "$POSTGRES_DB" -Fc -t "$1" -t "$2"'
    )
    command = [*compose, "exec", "-T", "postgres", "sh", "-c", script,
               "authz-snapshot", f"{service}.{matrix}", f"{service}.{audit}"]
    try:
        with path.open("xb") as out:
            result = subprocess.run(command, stdout=out, stderr=subprocess.PIPE,
                                    cwd=ROOT, timeout=90, check=False)
        if result.returncode != 0 or path.stat().st_size < 64:
            raise RuntimeError("PostgreSQL snapshot failed")
        # Validate that both requested TABLE entries exist in the archive.
        # An unrecognized/missing table must not produce a fake successful backup.
        listing = subprocess.run(
            [*compose, "exec", "-T", "postgres", "pg_restore", "--list"],
            input=path.read_bytes(), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            cwd=ROOT, timeout=40, check=False)
        names = listing.stdout.decode("utf-8", errors="replace")
        if listing.returncode != 0 or not all(
            any(line.startswith(";") is False and f" TABLE {service} {table} " in line
                for line in names.splitlines())
            for table in (matrix, audit)
        ):
            raise RuntimeError("Snapshot did not include both policy tables")
        path.chmod(0o600)
        print(f"Protected {service} authorization matrix and audit snapshot verified.")
    except Exception:
        path.unlink(missing_ok=True)
        try:
            directory.rmdir()
        except OSError:
            pass
        raise


if __name__ == "__main__":
    try:
        snapshot(sys.argv[1] if len(sys.argv) == 2 else "")
    except Exception:
        print("Authorization backup unavailable; release is stopped.", file=sys.stderr)
        sys.exit(1)
