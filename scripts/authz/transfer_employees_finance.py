#!/usr/bin/env python3
"""One-time owner-to-owner Finance role-denial transfer; no private rows in logs."""
import shlex
import subprocess
import sys
from pathlib import Path

ROOT = Path("/opt/irlix-services")


def run():
    compose = shlex.split(subprocess.check_output(
        [sys.executable, "scripts/ci/service_plan.py", "compose-args",
         "--env-file", ".env"], cwd=ROOT, text=True, timeout=20))
    source = subprocess.run([
        *compose, "exec", "-T", "employees", "php",
        "/app/bin/export-finance-v2-denials.php",
    ], capture_output=True, cwd=ROOT, timeout=40, check=True)
    destination = subprocess.run([
        *compose, "exec", "-T", "finance", "php",
        "/app/bin/import-employees-finance-denials.php",
    ], input=source.stdout, capture_output=True, cwd=ROOT, timeout=45, check=True)
    if source.returncode or destination.returncode:
        raise RuntimeError("Finance permission import failed")
    print("Finance V2 role-level explicit denies transferred to Finance owner (private metadata withheld).")


if __name__ == "__main__":
    try:
        run()
    except (OSError, ValueError, subprocess.SubprocessError, RuntimeError):
        print("Finance role-denial transfer failed; V2 cutover MUST NOT proceed.",
              file=sys.stderr)
        sys.exit(1)
