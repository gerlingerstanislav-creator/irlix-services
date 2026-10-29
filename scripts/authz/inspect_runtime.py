#!/usr/bin/env python3
"""Read-only production evidence for the AuthZ V2 rollout.

Reads effective container env (not .env defaults) and own-service aggregate
policy counts. Never prints secret env values, user identities or matrix rows.
This probe does NOT activate flags and is NOT a substitute for role/object E2E.
"""
import json
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path("/opt/irlix-services")
PROTECTED = (
    "platform-core", "vacations", "clients", "timesheets", "specialists",
    "recruitment", "equipment", "finance", "migration", "cv-converter",
)
MATRICES = {
    "vacations": ("IRLIX_VACATIONS_LOCAL_AUTHZ_ENABLED", "VacationsRolePermission"),
    "clients": ("IRLIX_CLIENTS_LOCAL_AUTHZ_ENABLED", "ClientContourPermission"),
    "timesheets": ("IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED", "TimesheetsRolePermission"),
    "specialists": ("IRLIX_SPECIALISTS_LOCAL_AUTHZ_ENABLED", "SpecialistsRolePermission"),
    "recruitment": ("IRLIX_RECRUITMENT_LOCAL_AUTHZ_ENABLED", "RecruitmentRolePermission"),
    "equipment": ("IRLIX_EQUIPMENT_LOCAL_AUTHZ_ENABLED", "EquipmentRolePermission"),
    "finance": ("IRLIX_FINANCE_LOCAL_AUTHZ_ENABLED", "FinanceRolePermission"),
}

# Model names are hard-coded and never derived from a request or a container.
# Aggregate SELECT only; no individual role names, employee IDs, or amounts.
MATRIX_COUNTS_PHP = r"""
require "/app/vendor/autoload.php";
$app = require "/app/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $class = "App\\Models\\" . $argv[1];
    $table = (new $class)->getTable();
    $total = $class::query()->count();
    $allowed = $class::query()->where("allowed", true)->count();
    $explicit = $table === "client_contour_permissions"
        ? $class::query()->where("denied", true)->count()
        : $class::query()->where("allowed", false)->count();
    echo json_encode(["status"=>"ok", "rows"=>$total,
        "allow_rows"=>$allowed, "explicit_deny_rows"=>$explicit]);
} catch (\Throwable $e) {
    echo '{"status":"unavailable"}';
}
"""


# This is a coverage diagnostic, NOT a substitute for effective grants or E2E.
# These actions are the minimum existing service-entry workflows.
CRITICAL_PERMISSIONS = {
    "clients": ("clients.view",),
    "timesheets": ("timesheets.mine.view", "timesheets.mine.manage"),
    "vacations": ("vacations.mine.view", "vacations.mine.manage"),
    "specialists": ("specialists.profiles.view",),
    "recruitment": ("recruitment.workspace.view",),
    "equipment": ("equipment.view",),
    "finance": ("finance.view",),
}

CRITICAL_COVERAGE_PHP = r"""
require "/app/vendor/autoload.php";
$app = require "/app/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $class="App\\Models\\".$argv[1];
    $answer=[];
    foreach (explode(",", $argv[2]) as $permission) {
        $answer[$permission]=$class::query()
            ->where("permission", $permission)->where("allowed", true)->count();
    }
    echo json_encode(["status"=>"ok","counts"=>$answer], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    echo '{"status":"unavailable"}';
}
"""


def invoke(argv, timeout=18):
    try:
        return subprocess.run(argv, capture_output=True, text=True, cwd=ROOT,
                              timeout=timeout, check=False)
    except (OSError, subprocess.TimeoutExpired):
        return None


def safely_parse_json(result):
    if result is None or result.returncode != 0:
        return None
    try:
        return json.loads(result.stdout)
    except (ValueError, TypeError):
        return None


def container_id(sudo, compose, service):
    result = invoke([*sudo, *compose, "ps", "-q", service])
    if result is None or result.returncode != 0:
        return None
    ids = [x.strip() for x in result.stdout.splitlines() if x.strip()]
    return ids[0] if len(ids) == 1 else None


def effective_env(sudo, container):
    data = safely_parse_json(invoke([*sudo, "docker", "inspect", container]))
    if not isinstance(data, list) or len(data) != 1:
        return None
    info = data[0]
    if not isinstance(info, dict) or info.get("State", {}).get("Running") is not True:
        return None
    entries = info.get("Config", {}).get("Env", [])
    if not isinstance(entries, list):
        return None
    # Never return this map to a caller that serializes it as evidence.
    return {item.split("=", 1)[0]: item.split("=", 1)[1]
            for item in entries if isinstance(item, str) and "=" in item}


def flag(env, key):
    if env is None or key not in env:
        return "unverified"
    value = env[key].strip().lower()
    if value in ("true", "1"):
        return "enabled"
    if value in ("false", "0", ""):
        return "disabled"
    return "invalid"


def matrix_counts(sudo, compose, service, model):
    result = safely_parse_json(invoke([
        *sudo, *compose, "exec", "-T", service, "php", "-r",
        MATRIX_COUNTS_PHP, model,
    ], timeout=22))
    if not isinstance(result, dict) or result.get("status") != "ok":
        return {"status": "unverified"}
    keys = ("rows", "allow_rows", "explicit_deny_rows")
    if any(type(result.get(key)) is not int or result[key] < 0 for key in keys):
        return {"status": "unverified"}
    if result["allow_rows"] > result["rows"] or result["explicit_deny_rows"] > result["rows"]:
        return {"status": "unverified"}
    return {"status": "ok", **{key: result[key] for key in keys}}


def critical_coverage(sudo, compose, service, model):
    required = CRITICAL_PERMISSIONS.get(service, ())
    if not required:
        return {"status": "unverified"}
    result = safely_parse_json(invoke([
        *sudo, *compose, "exec", "-T", service, "php", "-r",
        CRITICAL_COVERAGE_PHP, model, ",".join(required),
    ], timeout=25))
    if not isinstance(result, dict) or result.get("status") != "ok":
        return {"status": "unverified"}
    counts = result.get("counts")
    if not isinstance(counts, dict) or set(counts) != set(required):
        return {"status": "unverified"}
    if any(type(counts[p]) is not int or counts[p] < 0 for p in required):
        return {"status": "unverified"}
    return {"status": "ok", "missing_grants": [p for p in required if counts[p] == 0]}


def role_sync(sudo, compose):
    raw = invoke([*sudo, *compose, "exec", "-T", "employees", "php",
                  "bin/authz-status.php"], timeout=25)
    # authz-status exits 2 when not ready; its JSON is still safe to parse.
    result = None
    if raw is not None and raw.returncode in (0, 2):
        try:
            result = json.loads(raw.stdout)
        except (ValueError, TypeError):
            pass
    return "ready" if isinstance(result, dict) and result.get("ready") is True \
        else "not_ready_or_unverified"


def collect(compose, sudo):
    global_flags = {}
    local = {}
    for service in PROTECTED:
        cid = container_id(sudo, compose, service)
        env = effective_env(sudo, cid) if cid else None
        global_flags[service] = flag(env, "IRLIX_AUTHZ_JWT_ENABLED")
        if service in MATRICES:
            name, model = MATRICES[service]
            local[service] = {
                "flag": flag(env, name),
                "matrix": matrix_counts(sudo, compose, service, model) if cid and env
                    else {"status": "unverified"},
                "critical_coverage": critical_coverage(sudo, compose, service, model)
                    if cid and env else {"status": "unverified"},
            }
    local_values = [x["flag"] for x in local.values()]
    jwt_values = list(global_flags.values())
    return {
        "format": "irlix-authz-runtime-readiness-v1",
        "scope": "read_only_container_state_and_aggregate_policy_counts",
        "jwt_gate_by_service": global_flags,
        "keycloak_role_sync": role_sync(sudo, compose),
        "local_v2": local,
        "all_local_flags_disabled": all(x == "disabled" for x in local_values),
        "all_local_flags_enabled": all(x == "enabled" for x in local_values),
        "jwt_gate_consistent": all(x == "enabled" for x in jwt_values),
        "signed_role_object_e2e": "NOT TESTED",
        "protected_matrix_backups": "NOT TESTED",
        "per_service_rollback": "NOT TESTED",
        "missing_critical_grants": {
            service: status["critical_coverage"]["missing_grants"]
            for service, status in local.items()
            if status["critical_coverage"].get("status") == "ok"
            and status["critical_coverage"]["missing_grants"]
        },
        "cutover_accepted": False,
    }


def main(args=None):
    compose = list(sys.argv[1:] if args is None else args) or ["docker", "compose"]
    sudo = [] if os.geteuid() == 0 else ["sudo", "-n"]
    evidence = collect(compose, sudo)
    print("[authz-v2-readiness] " + json.dumps(evidence, sort_keys=True), flush=True)
    if not evidence["jwt_gate_consistent"]:
        print("[authz-v2-readiness] JWT gate is not verified on every protected backend.",
              flush=True)
    if not evidence["all_local_flags_disabled"]:
        print("[authz-v2-readiness] Non-disabled/unverified local flags require operator review; "
              "no flag was changed.", flush=True)
    print("[authz-v2-readiness] Runtime flags and matrix counts alone never authorize "
          "V2 cutover. Signed role/object E2E, snapshots and rollback are NOT TESTED.",
          flush=True)


if __name__ == "__main__":
    main()
