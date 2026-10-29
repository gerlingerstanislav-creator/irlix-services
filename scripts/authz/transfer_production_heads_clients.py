#!/usr/bin/env python3
"""Private role-key migration: Employees production department aliases to Clients.

Only department alias labels are exchanged. No user, department ID, grants or
commercial records leave their owning service. Both CLI outputs are hidden.
"""
import shlex
import subprocess
import sys
from pathlib import Path

ROOT = Path("/opt/irlix-services")


def run():
    compose = shlex.split(subprocess.check_output(
        [sys.executable, "scripts/ci/service_plan.py", "compose-args",
         "--env-file", ".env"], cwd=ROOT, text=True, timeout=20))
    metadata = subprocess.run([
        *compose, "exec", "-T", "employees", "php",
        "/app/bin/export-clients-production-head-aliases.php",
    ], cwd=ROOT, capture_output=True, timeout=40, check=True)
    result = subprocess.run([
        *compose, "exec", "-T", "clients", "php",
        "/app/bin/finalize-v2-role-matrix.php",
    ], input=metadata.stdout, cwd=ROOT, capture_output=True, timeout=55, check=True)
    if metadata.returncode or result.returncode:
        raise RuntimeError("Clients V2 finalization failed")
    print("Clients V2 production department-manager grants migrated and legacy role rows removed.")


if __name__ == "__main__":
    try:
        run()
    except (OSError, ValueError, subprocess.SubprocessError, RuntimeError):
        print("Clients V2 canonical role finalization failed; source policy not cleared.",
              file=sys.stderr)
        sys.exit(1)
