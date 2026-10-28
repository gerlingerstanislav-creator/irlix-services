#!/usr/bin/env python3
"""Read-only Storage V1 production cutover probe.

No credentials, DSNs, employee records, object names or file contents are
printed. This checks deployment prerequisites; it is NOT restore evidence.
"""
import json
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path("/opt/irlix-services")
CHECK_API = """
import json, os
result = {
    "enabled": os.getenv("STORAGE_V1_ENABLED") == "1",
    "db_configured": bool(os.getenv("STORAGE_V1_DB_DSN")),
    "schema": "not_configured"
}
if result["db_configured"]:
    try:
        import psycopg
        with psycopg.connect(os.environ["STORAGE_V1_DB_DSN"], connect_timeout=3) as conn:
            with conn.cursor() as cur:
                cur.execute("SELECT to_regclass('storage.objects') IS NOT NULL, "
                            "to_regclass('storage.grants') IS NOT NULL, "
                            "to_regclass('storage.audit_events') IS NOT NULL, "
                            "to_regclass('storage.file_versions') IS NOT NULL")
                result["schema"] = "ready" if all(cur.fetchone()) else "incomplete"
    except Exception:
        result["schema"] = "unavailable"
print(json.dumps(result))
"""
CHECK_EMPLOYEES = (
    'echo json_encode(["enabled" => '
    'getenv("STORAGE_V1_ACCESS_ENABLED") === "1"]);'
)


def main(compose_args=None):
    sudo = [] if os.geteuid() == 0 else ["sudo", "-n"]
    compose = list(compose_args) if compose_args else (["docker", "compose"] if subprocess.run(
        [*sudo, "docker", "compose", "version"], capture_output=True, check=False
    ).returncode == 0 else ["docker-compose"])
    results = {}
    for service, cmd in (
        ("api", ["python3", "-c", CHECK_API]),
        ("employees", ["php", "-r", CHECK_EMPLOYEES]),
    ):
        run = subprocess.run(
            [*sudo, *compose, "exec", "-T", "storage-api" if service == "api" else service, *cmd],
            cwd=ROOT, capture_output=True, text=True, timeout=25, check=False,
        )
        if run.returncode != 0:
            results[service] = {"probe": "unavailable"}
            continue
        try:
            data = json.loads(run.stdout.strip())
            # Strictly allowlist diagnostics. Never forward arbitrary container output.
            if service == "api":
                results[service] = {
                    "enabled": data.get("enabled") is True,
                    "db_configured": data.get("db_configured") is True,
                    "schema": data.get("schema") if data.get("schema") in {
                        "not_configured", "ready", "incomplete", "unavailable"
                    } else "unavailable",
                }
            else:
                results[service] = {"enabled": data.get("enabled") is True}
        except (ValueError, TypeError, AttributeError):
            results[service] = {"probe": "invalid_response"}

    # The original pilot may still sit on the VM root filesystem. Report
    # capacity and isolation without publishing the private host path.
    disk = {"separate_filesystem": False, "free_gib": None, "status": "unknown"}
    env_file = ROOT / ".env"
    if env_file.is_file():
        try:
            rows = (line.split("=", 1) for line in env_file.read_text().splitlines()
                    if line.startswith("STORAGE_S3_DATA_DIR="))
            data_path = next((v.strip().strip('"').strip("'") for key, v in rows
                              if key == "STORAGE_S3_DATA_DIR"), "")
            if data_path and os.path.isdir(data_path):
                stat = os.statvfs(data_path)
                disk = {
                    "separate_filesystem": os.stat(data_path).st_dev != os.stat("/").st_dev,
                    "free_gib": (stat.f_bavail * stat.f_frsize) // (1024 ** 3),
                    "status": "measured",
                }
        except (OSError, ValueError):
            disk["status"] = "unavailable"
    results["disk"] = disk
    print("[storage-v1-readiness] " + json.dumps(results, sort_keys=True), flush=True)
    if results.get("api", {}).get("schema") != "ready":
        print("[storage-v1-readiness] V1 metadata unavailable or unverified; "
              "legacy pilot must not be retired without a safe alternative.", flush=True)
    print("[storage-v1-readiness] Backup+restore, ACL E2E and isolated disk: "
          "NOT TESTED by this read-only probe.", flush=True)


if __name__ == "__main__":
    main(sys.argv[1:])
