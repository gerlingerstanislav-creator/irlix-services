#!/usr/bin/env python3
"""Generic runtime smoke checks for all registered components selected by a release."""
import json
import re
import subprocess
from pathlib import Path
from service_plan import ROOT, registry, compose_command


# Runtime verification dependencies only: do not restart, rebuild or migrate Finance
# when a source backend changes. Frontends and unrelated releases stay selective.
FINANCE_SOURCES = {"finance", "finance-web", "clients", "timesheets", "employees"}


def verification_services(services):
    selected = set(services)
    if selected.intersection(FINANCE_SOURCES):
        selected.update({"finance", "finance-web"})
    return selected


def verify_finance(compose, sudo, host):
    # Public API reads must fail before exposing any financial records. Discard
    # response bodies, including unexpected successes; only status codes escape.
    for endpoint in ("expenses", "audit", "profitability", "expenses/payment-links"):
        status = subprocess.check_output([
            "curl", "-sS", "-o", "/dev/null", "-w", "%{http_code}",
            "-H", f"Host: {host}", "-H", "Accept: application/json",
            "--connect-timeout", "2", "--max-time", "10",
            f"http://127.0.0.1/api/finance/{endpoint}",
        ], text=True)
        assert status == "401", f"Finance anonymous {endpoint}: expected 401, got {status}"
    subprocess.run([*sudo, *compose, "exec", "-T", "finance", "php",
                    "tests/finance_integrations_smoke.php"], cwd=ROOT,
                   stdin=subprocess.DEVNULL, check=True)
    print("[verify] Finance anonymous access and read-only source contracts OK", flush=True)



def verify_design_system(host):
    """SSO protection and real app identity must both pass at their own origins."""
    headers_and_status = subprocess.check_output([
        "curl", "-sS", "-D", "-", "-o", "/dev/null", "-w", "\n%{http_code}",
        "-H", f"Host: {host}", "--connect-timeout", "2", "--max-time", "10",
        "http://127.0.0.1/design-system/",
    ], text=True)
    headers, status = headers_and_status.rsplit("\n", 1)
    assert status == "302", f"Design System anonymous gate: expected 302, got {status}"
    redirect = re.search(r"(?im)^Location:\s*(\S+)", headers)
    assert redirect and redirect.group(1).endswith(
        "/?design-system=1&return=/design-system/"
    ), "Design System anonymous gate must redirect to Portal SSO"

    for path in ("/", "/components/", "/navigation/"):
        response = subprocess.check_output([
            "curl", "-sS", "--connect-timeout", "2", "--max-time", "10",
            "--retry", "5", "--retry-all-errors", "--retry-delay", "1",
            "-w", "\n%{http_code}", "http://127.0.0.1:8083" + path,
        ], text=True)
        body, status = response.rsplit("\n", 1)
        assert status == "200", f"Design System origin {path}: expected 200, got {status}"
        assert "<title>irlix design system</title>" in body.lower(), (
            f"Design System origin {path}: wrong frontend, expected Design System"
        )
    print("[verify] design-system: anonymous SSO 302; origin SPA and deep links 200", flush=True)


def verify_brandbook(host):
    """Distinguish an anonymous 302 login redirect from a working Brandbook SPA."""
    headers_and_status = subprocess.check_output([
        "curl", "-sS", "-D", "-", "-o", "/dev/null", "-w", "\n%{http_code}",
        "-H", f"Host: {host}", "--connect-timeout", "2", "--max-time", "10",
        "http://127.0.0.1/brandbook/",
    ], text=True)
    headers, status = headers_and_status.rsplit("\n", 1)
    assert status == "302", f"Brandbook anonymous gate: expected 302, got {status}"
    redirect = re.search(r"(?im)^Location:\s*(\S+)", headers)
    assert redirect and redirect.group(1).endswith("/?brandbook=1"), (
        "Brandbook anonymous gate must redirect to Portal SSO entry"
    )

    # A 302 response has nginx-generated <html> and previously passed the
    # generic frontend check even when the Brandbook origin was missing.
    # Check the actual frontend on its host loopback port, without bypassing
    # or weakening the external server-side authorization gate.
    # An HTML 200 alone does not prove that port 8105 serves the Brandbook.
    # A stale/misrouted Portal build also returns HTML and renders its own 404
    # at /brandbook/. Verify the actual application identity at the origin.
    brandbook_marker = "<title>IRLIX — цифровая основа брендбука v1.0</title>"
    for path, marker in (
        ("/", brandbook_marker),
        ("/identity/", brandbook_marker),
        ("/colors/", brandbook_marker),
        ("/downloads/irlix-logo-dark-background.svg", "<svg"),
    ):
        response = subprocess.check_output([
            "curl", "-sS", "--connect-timeout", "2", "--max-time", "10",
            "--retry", "5", "--retry-all-errors", "--retry-delay", "1",
            "-w", "\n%{http_code}", "http://127.0.0.1:8105" + path,
        ], text=True)
        body, status = response.rsplit("\n", 1)
        assert status == "200", f"Brandbook origin {path}: expected 200, got {status}"
        assert marker.lower() in body.lower(), (
            f"Brandbook origin {path}: wrong application response; expected Brandbook, "
            "not Portal/another HTML fallback"
        )
    print("[verify] brandbook: anonymous SSO redirect 302; origin SPA, deep links and SVG 200", flush=True)

def main():
    plan = json.loads((ROOT / ".ci/deploy-plan.json").read_text())
    data = registry()
    compose = compose_command(data, ".env")
    sudo = [] if __import__("os").geteuid() == 0 else ["sudo", "-n"]
    # Server .env contains credentials and is root-only (0600). Read only the
    # non-secret origin via the existing narrow, noninteractive sudo policy.
    origin_line = subprocess.check_output(
        [*sudo, "grep", "^IRLIX_PUBLIC_URL=", str(ROOT / ".env")],
        text=True, cwd=ROOT,
    ).splitlines()[-1]
    host = origin_line.split("=", 1)[1].split("://", 1)[-1].split("/", 1)[0]
    selected = verification_services(plan["services"])
    for service in sorted(selected):
        assert re.fullmatch(r"[a-z0-9][a-z0-9-]*", service)
        container = subprocess.check_output([*sudo, *compose, "ps", "-q", service], cwd=ROOT, text=True).strip()
        assert container, f"Missing container: {service}"
        running = subprocess.check_output([*sudo, "docker", "inspect", "-f", "{{.State.Running}}", container], text=True).strip()
        assert running == "true", f"Container is not running: {service}"
    # The protected frontends are shared security and navigation dependencies.
    # Check the real app identities on *every* release, including smoke-only
    # or script-only plans where no application container is selected.
    verify_brandbook(host)
    verify_design_system(host)
    for c in data["components"]:
        if not selected.intersection([c["service"], *c.get("aliases", [])]):
            continue
        if c["id"] in {"brandbook", "design-system"}:
            continue  # Their actual origins and SSO gates were already checked.
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
        if c["id"] == "resource-monitor":
            # The normal health endpoint only checks snapshot freshness.
            # A resource-monitor release must additionally supply verified VM RAM.
            monitor = subprocess.check_output([*sudo, *compose, "ps", "-q", "resource-monitor"],
                                              cwd=ROOT, text=True).strip()
            test = ("import json,time; d=json.load(open('/data/current.json')); "
                    "h=d['host']; "
                    "assert h.get('memory_source') == 'host'; "
                    "assert isinstance(h.get('memory_used'),int); "
                    "assert 0 <= h['memory_used'] <= h['memory_total']; "
                    "assert time.time()-d['collected_at'] < 45")
            subprocess.run([*sudo, "docker", "exec", monitor, "python", "-c", test], check=True)
            print("[verify] resource-monitor verified host RAM OK", flush=True)
        print(f"[verify] {c['id']} {path} OK")
    if "finance" in selected:
        verify_finance(compose, sudo, host)
    for script in plan["verify"]:
        assert re.fullmatch(r"scripts/verify-[a-z0-9-]+\.sh", script), "Invalid verification script"
        subprocess.run(["sh", str(ROOT / script)], check=True)
    print("Selected release components verified.")


if __name__ == "__main__":
    main()
