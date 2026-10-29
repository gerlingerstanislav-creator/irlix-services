#!/usr/bin/env python3
"""Private Employees-to-Clients canonical authorization finalization.

Production-safe error categories only. Never disclose department names,
role identifiers, employee IDs, policy rows, SQL details or raw stderr.
"""
import shlex
import subprocess
import sys
from pathlib import Path

ROOT = Path("/opt/irlix-services")

# A hard-coded allowlist, not an echo of untrusted/private stderr.
SAFE_REASONS = (
    "Unrecognized active legacy Clients role",
    "Unrecognized active Clients permission",
    "Missing V2 role permission after import",
    "Unreconciled Clients policy difference",
    "No production head roles for existing manager grants",
    "Invalid production organization alias",
)


def safe_reason(raw: bytes) -> str:
    if not isinstance(raw, bytes):
        return "Unclassified Clients finalization error"
    output = raw.decode("utf-8", errors="replace")
    for category in SAFE_REASONS:
        if f"[clients-v2-finalize] {category}\n" in output:
            return category
    return "Unclassified Clients finalization error"


def run():
    compose = shlex.split(subprocess.check_output(
        [sys.executable, "scripts/ci/service_plan.py", "compose-args",
         "--env-file", ".env"], cwd=ROOT, text=True, timeout=20))
    metadata = subprocess.run([
        *compose, "exec", "-T", "employees", "php",
        "/app/bin/export-clients-production-head-aliases.php",
    ], cwd=ROOT, capture_output=True, timeout=40, check=False)
    if metadata.returncode != 0:
        raise RuntimeError("Employees production department aliases unavailable or invalid")

    result = subprocess.run([
        *compose, "exec", "-T", "clients", "php",
        "/app/bin/finalize-v2-role-matrix.php",
    ], input=metadata.stdout, cwd=ROOT, capture_output=True, timeout=55, check=False)
    if result.returncode != 0:
        raise RuntimeError(safe_reason(result.stderr))
    print("Clients V2 canonical matrix finalization PASS; obsolete role rows removed.")


if __name__ == "__main__":
    try:
        run()
    except (OSError, ValueError, subprocess.SubprocessError, RuntimeError) as error:
        category = str(error) if str(error) in (
            *SAFE_REASONS,
            "Employees production department aliases unavailable or invalid",
            "Unclassified Clients finalization error",
        ) else "Unclassified finalization transport or runtime error"
        print(f"Clients V2 finalization STOPPED ({category}); source policy preserved.",
              file=sys.stderr)
        sys.exit(1)
