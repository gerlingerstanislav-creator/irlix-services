#!/usr/bin/env python3
"""Generic runtime smoke checks for all registered components selected by a release."""
import json
import re
import subprocess
from pathlib import Path
from service_plan import ROOT, registry, compose_command


def main():
    plan = json.loads((ROOT / ".ci/deploy-plan.json").read_text())
    data = registry()
    compose = compose_command(data, ".env")
    sudo = [] if __import__("os").geteuid() == 0 else ["sudo", "-n"]
    env = dict(line.split("=", 1) for line in (ROOT / ".env").read_text().splitlines() if "=" in line and not line.startswith("#"))
    host = env["IRLIX_PUBLIC_URL"].split("://", 1)[-1].split("/", 1)[0]
    selected = set(plan["services"])
    for service in sorted(selected):
        assert re.fullmatch(r"[a-z0-9][a-z0-9-]*", service)
        container = subprocess.check_output([*sudo, *compose, "ps", "-q", service], cwd=ROOT, text=True).strip()
        assert container, f"Missing container: {service}"
        running = subprocess.check_output([*sudo, "docker", "inspect", "-f", "{{.State.Running}}", container], text=True).strip()
        assert running == "true", f"Container is not running: {service}"
    for c in data["components"]:
        if not selected.intersection([c["service"], *c.get("aliases", [])]):
            continue
        path = c.get("health_path") or c["page_path"]
        try:
            body = subprocess.check_output(["curl", "-H", f"Host: {host}", "-fsS", "--connect-timeout", "2", "--max-time", "10",
                                            "--retry", "5", "--retry-all-errors", "--retry-delay", "1", "http://127.0.0.1" + path], text=True)
        except subprocess.CalledProcessError:
            if c["id"] == "resource-monitor":
                # Safe operational diagnostics only. Never dump Docker metadata or environment.
                print("[verify] Resource monitor health failed; sanitized collector log follows", flush=True)
                logs = subprocess.run([*sudo, "docker", "logs", "--tail", "15", "--since", "3m", container],
                                      text=True, capture_output=True)
                for line in logs.stdout.splitlines() + logs.stderr.splitlines():
                    if line.startswith("Resource collection failed"):
                        print("[verify] " + line[:220], flush=True)
            raise
        if c["kind"] == "backend":
            health = json.loads(body)
            assert health.get("status", health.get("data", {}).get("status")) == "ok", f"Health failed: {c['id']}"
        else:
            assert "<html" in body.lower(), f"Invalid frontend HTML: {c['id']}"
        print(f"[verify] {c['id']} {path} OK")
    for script in plan["verify"]:
        assert re.fullmatch(r"scripts/verify-[a-z0-9-]+\.sh", script), "Invalid verification script"
        subprocess.run(["sh", str(ROOT / script)], check=True)
    print("Selected release components verified.")


if __name__ == "__main__":
    main()
