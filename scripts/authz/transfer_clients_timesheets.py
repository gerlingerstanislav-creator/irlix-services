#!/usr/bin/env python3
"""Private inter-service transfer of existing Timesheets grants from Clients.

No cross-schema SQL, client token, user identifier or permission rows enter
release logs. Each owning backend reads/writes only its own database.
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
    source = subprocess.run([*compose, "exec", "-T", "clients", "php",
                             "/app/bin/export-timesheets-legacy-policy.php"],
                            cwd=ROOT, capture_output=True, timeout=40, check=True)
    destination = subprocess.run([*compose, "exec", "-T", "timesheets", "php",
                                  "/app/bin/import-clients-legacy-permissions.php"],
                                 input=source.stdout, cwd=ROOT, capture_output=True,
                                 timeout=40, check=True)
    if source.returncode or destination.returncode:
        raise RuntimeError("Local permissions transfer failed")
    print("Existing Clients Timesheets grants transferred to Timesheets; "
          "no policy rows disclosed.")


if __name__ == "__main__":
    try:
        run()
    except (OSError, ValueError, subprocess.SubprocessError) as error:
        # Never print captured STDOUT/STDERR, including private legacy rows.
        print("Legacy Timesheets matrix transfer failed (no policy output).",
              file=sys.stderr)
        sys.exit(1)
