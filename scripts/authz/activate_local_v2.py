#!/usr/bin/env python3
"""One-time production switch to seven independent local AuthZ V2 policies.

Operator-authorized release only. Root-only. Uses existing Compose and images;
no new environment, schema wipe or legacy authorization service. Restarts one
business service at a time, verifies live flags and HTTP deny/health, and
restores the flags changed in THIS run if any stage fails. Never log secrets.
"""
import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
from datetime import datetime, timezone, timedelta
from pathlib import Path

ROOT = Path("/opt/irlix-services")
BACKUPS = Path("/opt/irlix-authz-backups")
ENV_FILE = ROOT / ".env"
ORDER = ("clients", "timesheets", "vacations", "specialists",
         "recruitment", "equipment", "finance")
NEGATIVE = {service: f"/api/{service}/permissions/me" for service in ORDER}
NEGATIVE["equipment"] = "/api/equipment/permissions"
NEGATIVE["finance"] = "/api/finance/me"


def env_value(key):
    # Values are NEVER serialized or printed. Duplicate values fail closed.
    name = (key + "=").encode("ascii")
    rows = [line[len(name):].strip() for line in ENV_FILE.read_bytes().splitlines()
            if line.startswith(name)]
    if len(rows) != 1:
        return None
    return rows[0].decode("ascii", errors="strict")


def set_flag(key, enabled):
    if re.fullmatch(r"IRLIX_[A-Z_]+_LOCAL_AUTHZ_ENABLED", key) is None:
        raise RuntimeError("Unexpected feature flag")
    data = ENV_FILE.read_bytes()
    name = (key + "=").encode("ascii")
    rows = data.splitlines(keepends=True)
    matches = [i for i, row in enumerate(rows) if row.startswith(name)]
    if len(matches) != 1:
        raise RuntimeError("Existing flag requires a unique server setting")
    new_line = name + (b"true" if enabled else b"false") + b"\n"
    rows[matches[0]] = new_line
    fd, name_temp = tempfile.mkstemp(dir=ROOT, prefix=".authz-env-", suffix=".tmp")
    try:
        os.fchmod(fd, ENV_FILE.stat().st_mode & 0o777)
        with os.fdopen(fd, "wb") as handle:
            handle.write(b"".join(rows))
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(name_temp, ENV_FILE)
    finally:
        if os.path.exists(name_temp):
            os.unlink(name_temp)


def verified_protected_snapshots():
    # Prior preflight (7/7) produced a private receipt and real archives.
    # Do not trust a public workflow conclusion alone.
    if BACKUPS.is_symlink() or not BACKUPS.is_dir():
        return False
    st = BACKUPS.stat()
    if st.st_uid != 0 or st.st_mode & 0o077:
        return False
    now = datetime.now(timezone.utc)
    for receipt in sorted(BACKUPS.glob("preflight-*.json"), reverse=True):
        if receipt.is_symlink() or receipt.stat().st_mode & 0o077:
            continue
        try:
            data = json.loads(receipt.read_text())
            when = datetime.fromisoformat(data["created_at_utc"])
            if when.tzinfo is None or now - when > timedelta(days=2) or when > now:
                continue
            a = data.get("matrix_archive_verified")
            b = data.get("isolated_matrix_restore")
            if (set(a or {}) == set(ORDER) and set(b or {}) == set(ORDER)
                    and all(a[svc] == "PASS" and b[svc] == "PASS" for svc in ORDER)):
                return all(any(BACKUPS.glob(f"matrix-{svc}-*/authz-matrix.dump"))
                           for svc in ORDER)
        except (OSError, ValueError, TypeError, KeyError):
            continue
    return False


def run_compose(compose, service):
    result = subprocess.run(
        [*compose, "up", "-d", "--no-build", "--no-deps",
         "--force-recreate", service],
        cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        timeout=150, check=False,
    )
    if result.returncode != 0:
        raise RuntimeError("Service recreate did not complete")


def http_status(path):
    try:
        with urllib.request.urlopen("http://127.0.0.1" + path, timeout=8) as r:
            r.read(1)
            return r.status
    except urllib.error.HTTPError as error:
        return error.code
    except (urllib.error.URLError, OSError, TimeoutError):
        return None


def verify_service(probe, compose, service, enabled):
    id_ = probe.container_id([], compose, service)
    env = probe.effective_env([], id_) if id_ else None
    flag_key, model = probe.MATRICES[service]
    if probe.flag(env, flag_key) != ("enabled" if enabled else "disabled"):
        raise RuntimeError("Wrong live feature flag after recreation")
    matrix = probe.matrix_counts([], compose, service, model)
    critical = probe.critical_coverage([], compose, service, model)
    if matrix.get("status") != "ok" or matrix.get("rows", 0) < 1:
        raise RuntimeError("Current local matrix is unavailable")
    if critical.get("status") != "ok" or critical.get("missing_grants"):
        raise RuntimeError("Required local permissions are missing")
    # Verify HTTP entry is live, and a caller WITHOUT a valid JWT cannot
    # open any representative business endpoint.
    if http_status(f"/api/{service}/health") != 200:
        raise RuntimeError("Business service did not become healthy")
    if http_status(NEGATIVE[service]) != 401:
        raise RuntimeError("Business API did not reject an unauthenticated request")


def main():
    if os.geteuid() != 0 or not ROOT.is_dir() or not ENV_FILE.is_file():
        raise RuntimeError("Root on production host required")
    os.umask(0o077)
    sys.path.insert(0, str(ROOT / "scripts" / "authz"))
    import inspect_runtime as probe
    compose = probe.default_production_compose()
    if not compose or not verified_protected_snapshots():
        raise RuntimeError("Verified protected production policy snapshots are absent")
    report = probe.collect(compose, [])
    if not report.get("jwt_gate_consistent") or report.get("keycloak_role_sync") != "ready":
        raise RuntimeError("Keycloak identity prerequisites missing")
    if report.get("missing_critical_grants") or set(report.get("local_v2", {})) != set(ORDER):
        raise RuntimeError("Incomplete service-owned permission matrices")
    if env_value("IRLIX_FINANCE_V2_DENIAL_IMPORT_DONE") != "true":
        raise RuntimeError("Finance explicit denials have not migrated to V2")
    for service in ORDER:
        flag = report["local_v2"][service]["flag"]
        if flag not in ("enabled", "disabled"):
            raise RuntimeError("Unverifiable existing authorization flag")
        if (report["local_v2"][service]["matrix"].get("status") != "ok"
                or report["local_v2"][service]["critical_coverage"].get("status") != "ok"):
            raise RuntimeError("Invalid service authorization coverage")

    switched = []
    try:
        for service in ORDER:
            key, _ = probe.MATRICES[service]
            if report["local_v2"][service]["flag"] == "enabled":
                verify_service(probe, compose, service, True)
                print(f"[authz-v2-cutover] {service}: already enabled and verified", flush=True)
                continue
            set_flag(key, True)
            switched.append(service)
            run_compose(compose, service)
            verify_service(probe, compose, service, True)
            print(f"[authz-v2-cutover] {service}: independent local V2 enabled; health and anonymous deny PASS", flush=True)
    except Exception:
        for service in reversed(switched):
            key, _ = probe.MATRICES[service]
            try:
                set_flag(key, False)
                run_compose(compose, service)
                verify_service(probe, compose, service, False)
                print(f"[authz-v2-cutover] {service}: same-service flag rollback PASS", flush=True)
            except Exception:
                print(f"[authz-v2-cutover] {service}: manual investigation required after failed rollback", file=sys.stderr)
        raise
    print("[authz-v2-cutover] 7/7 independent local V2 flags enabled. Live positive role/object user scenarios not attested by this probe.", flush=True)


if __name__ == "__main__":
    try:
        main()
    except Exception:
        print("[authz-v2-cutover] FAIL: production cutover incomplete; check protected host before retry.", file=sys.stderr)
        sys.exit(1)
