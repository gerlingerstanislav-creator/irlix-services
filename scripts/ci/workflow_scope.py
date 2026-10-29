#!/usr/bin/env python3
"""Select specialized PR workflow jobs from the actual PR diff, never a prior push.

Fail closed if the base cannot be compared. Manual invocations run the full suite.
Shared UI source changes fan out to all consumers; test/doc-only changes do not.
"""
import argparse
import json
import os
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
UI_APPS = ("web", "clients", "vacations", "timesheets", "specialists",
           "recruitment", "cv", "portal", "migration", "design-system", "equipment")
KEYCLOAK_BACKENDS = ("platform-core", "migration", "employees", "finance")


def diff_paths(base):
    if not base or not all(c in "0123456789abcdefABCDEF" for c in base):
        raise ValueError("PR base must be a nonempty commit SHA")
    lines = subprocess.check_output(
        ["git", "diff", "--name-only", "--diff-filter=ACMR", base + "...HEAD"],
        cwd=ROOT, text=True,
    )
    return set(lines.splitlines())


def shared_ui_source(path):
    return (path.startswith("packages/ui/")
            and not path.startswith("packages/ui/tests/")
            and not path.endswith(".md"))


def select(mode, paths=None):
    full = paths is None
    changed = paths or set()
    workflow = f".github/workflows/{mode}.yml"
    if mode == "ui-appearance-check":
        apps = list(UI_APPS) if full or workflow in changed or any(map(shared_ui_source, changed)) else [
            app for app in UI_APPS if any(path.startswith(f"apps/{app}/") for path in changed)
        ]
        return {"matrix": {"include": [{"app": app} for app in apps]}, "enabled": bool(apps)}
    if mode == "keycloak-authz":
        common = (full or workflow in changed
                  or any(p.startswith("infra/keycloak/") or p == "scripts/ci/keycloak_authz_smoke.py"
                         for p in changed))
        backends = list(KEYCLOAK_BACKENDS) if common else [
            service for service in KEYCLOAK_BACKENDS
            if any(p.startswith(f"services/{service}/") for p in changed)
        ]
        return {"matrix": {"include": [{"service": service} for service in backends]},
                "enabled": bool(backends)}
    if mode == "client-history-ui-check":
        common = full or workflow in changed or any(map(shared_ui_source, changed))
        apps = ["clients", "design-system"] if common else (
            ["clients"] if any(p.startswith("apps/clients/") for p in changed) else [])
        return {"matrix": {"include": [{"app": app} for app in apps]}, "enabled": bool(apps),
                "php": full or workflow in changed
                or any(p.startswith("services/clients/") for p in changed)}
    if mode == "finance-validation":
        common = full or workflow in changed
        ui_source = any(map(shared_ui_source, changed))
        finance_frontend = common or ui_source or any(
            p.startswith("apps/finance/") for p in changed)
        clients_frontend = common or ui_source or any(
            p.startswith("apps/clients/") for p in changed)
        frontend = finance_frontend or clients_frontend
        backend_sources = any(p.startswith((
            "services/finance/", "services/clients/",
            "services/timesheets/", "services/employees/")) for p in changed)
        compose = "docker-compose.finance.yml" in changed
        return {"frontend": frontend, "finance_frontend": finance_frontend,
                "clients_frontend": clients_frontend,
                "backend": common or backend_sources or compose,
                "clients_container": common or any(p.startswith((
                    "services/clients/", "services/timesheets/")) for p in changed),
                "finance_container": common or backend_sources or compose}
    raise ValueError(f"Unknown workflow: {mode}")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=[
        "ui-appearance-check", "keycloak-authz", "client-history-ui-check",
        "finance-validation",
    ])
    parser.add_argument("--base", help="PR target SHA; omit only for manual/full run")
    args = parser.parse_args()
    result = select(args.mode, diff_paths(args.base) if args.base else None)
    encoded = {key: json.dumps(value, separators=(",", ":")) if isinstance(value, dict)
               else str(value).lower() if isinstance(value, bool) else value
               for key, value in result.items()}
    if os.environ.get("GITHUB_OUTPUT"):
        with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as stream:
            for key, value in encoded.items():
                stream.write(f"{key}={value}\n")
    print(json.dumps({"workflow": args.mode, "selection": result}, indent=2))


if __name__ == "__main__":
    main()
