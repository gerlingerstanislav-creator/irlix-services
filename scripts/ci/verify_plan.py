#!/usr/bin/env python3
"""Generic runtime smoke checks for all registered components selected by a release."""
import json
import re
import subprocess
import urllib.error
import urllib.request
from pathlib import Path
from service_plan import ROOT, registry, compose_command


# Runtime verification dependencies only: do not restart, rebuild or migrate Finance
# when a source backend changes. Frontends and unrelated releases stay selective.
FINANCE_SOURCES = {"finance", "finance-web", "clients", "timesheets", "employees"}
# Storage V1 preflight needs the full registered Compose overlay stack.
STORAGE_PREFLIGHT_SERVICES = {'employees', 'storage-s3', 'storage-drive', 'storage-api'}


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

def verify_storage_pilot(host):
    """Verify the public, data-free Drive bootstrap and protected APIs/S3."""
    args = ["curl", "-sS", "-o", "/dev/null", "-w", "%{http_code}",
            "-H", f"Host: {host}", "--connect-timeout", "2", "--max-time", "10"]
    frontend = subprocess.check_output([*args, "http://127.0.0.1/storage/"], text=True).strip()
    assert frontend == "200", f"Storage public SPA bootstrap unavailable: {frontend}"
    gate = subprocess.check_output([*args, "http://127.0.0.1/_storage_pilot_gate"], text=True).strip()
    assert gate == "404", f"internal storage gate exposed: {gate}"
    v1_gate = subprocess.check_output([*args, "http://127.0.0.1/_storage_v1_gate"], text=True).strip()
    assert v1_gate == "404", f"internal Storage V1 gate exposed: {v1_gate}"
    # Static UI may load before login; file data must NEVER be available without a session.
    api_external = subprocess.check_output([*args, "http://127.0.0.1/api/storage/items"], text=True).strip()
    assert api_external == "302", f"Storage external API must require SSO: {api_external}"
    v1_external = subprocess.check_output([*args, "http://127.0.0.1/api/storage/v1/"], text=True).strip()
    assert v1_external == "401", f"Storage V1 API must require a session: {v1_external}"
    old_port = subprocess.check_output([*args, "http://127.0.0.1:8110/"], text=True).strip()
    assert old_port == "302", f"Legacy port must redirect to /storage/: {old_port}"
    # Own Drive must serve its app, not the fallback Portal.
    drive = subprocess.check_output([
        "curl", "-sS", "--connect-timeout", "2", "--max-time", "10",
        "http://127.0.0.1:8115/",
    ], text=True)
    assert "<title>IRLIX · Хранилище</title>" in drive, "Own Storage Drive origin not serving expected app"
    api_health = subprocess.check_output([*args, "http://127.0.0.1:8114/health"], text=True).strip()
    assert api_health == "200", f"Storage API health unavailable: {api_health}"
    api_denied = subprocess.check_output([*args, "http://127.0.0.1:8114/items"], text=True).strip()
    assert api_denied == "401", f"Direct Storage API bypass without cookie: {api_denied}"
    s3 = subprocess.check_output([*args, "http://127.0.0.1:8113/"], text=True).strip()
    assert s3 in {"400", "401", "403"}, f"Unauthenticated S3 access was accepted or unavailable: {s3}"
    sudo = [] if __import__("os").geteuid() == 0 else ["sudo", "-n"]
    subprocess.run([*sudo, "python3", str(ROOT / "scripts/storage/verify_s3_pilot.py"), str(ROOT / ".env")],
                   check=True, cwd=ROOT)
    print("[verify] Storage pilot: SSO, own Drive origin, guarded API and signed S3 OK", flush=True)



def verify_storage_authenticated_navigation(compose, sudo, host):
    """Exercise cookie issuance against real admin DB and nginx authorization.

    Never write the cookie to logs, disk or shell arguments. This is a read-only
    smoke with an actual persisted platform-admin assignment, not a public login.
    """
    result = subprocess.run(
        [*sudo, *compose, "exec", "-T", "employees", "php",
         "tests/storage_pilot_session_runtime.php"],
        cwd=ROOT, capture_output=True, text=True,
        stdin=subprocess.DEVNULL, timeout=20,
    )
    if result.returncode:
        raise AssertionError(
            "Storage platform-admin session could not be issued; "
            "inspect Employees safely without logging the cookie"
        )
    secret_cookie = result.stdout.strip()
    assert secret_cookie and "\\n" not in secret_cookie, "Storage test cookie not issued"

    class DoNotFollowRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, req, fp, code, msg, headers, newurl):
            return None

    opener = urllib.request.build_opener(DoNotFollowRedirect())
    request = urllib.request.Request(
        "http://127.0.0.1/storage/",
        headers={"Host": host, "Cookie": "irlix_storage_pilot=" + secret_cookie},
        method="GET",
    )
    try:
        with opener.open(request, timeout=10) as response:
            status = response.status
            location = response.headers.get("Location", "")
    except urllib.error.HTTPError as exc:
        status, location = exc.code, exc.headers.get("Location", "")
    assert status == 200, (
        f"Storage authenticated canonical Drive route unhealthy: HTTP {status}"
    )
    assert not location, "Storage authenticated navigation redirected unexpectedly"
    # Confirm the actual SPA is returned, not a healthy but incorrect Dashboard.
    request_api = urllib.request.Request(
        "http://127.0.0.1/api/storage/items?folder=",
        headers={"Host": host, "Cookie": "irlix_storage_pilot=" + secret_cookie},
        method="GET",
    )
    try:
        with opener.open(request_api, timeout=15) as response:
            assert response.status == 200, "Storage canonical API read denied"
            listing = json.load(response)
            assert "files" in listing and "folders" in listing, "Unexpected Storage API response"
    except urllib.error.HTTPError as exc:
        raise AssertionError(f"Authenticated Storage listing unhealthy: HTTP {exc.code}") from None
    print("[verify] Storage: real admin cookie, clean /storage/ route and S3 listing OK", flush=True)

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
    # Read-only cutover evidence on every production release, even a
    # script-only rollout. Never log DSNs, passwords or employee metadata.
    if not selected or selected.intersection(STORAGE_PREFLIGHT_SERVICES):
        # Trigger schema creation only for the already-enabled SQLite debugging
        # backend; exercise a rolled-back transaction, never touching user data.
        subprocess.run([*sudo, *compose, "exec", "-T", "storage-api",
                        "python3", "-m", "app.sqlite_smoke"],
                       check=True, cwd=ROOT, timeout=90)
        subprocess.run([*sudo, "python3", str(ROOT / "scripts/storage/inspect-v1-readiness.py"), *compose],
                       check=True, cwd=ROOT, timeout=90)
    # Record *effective running-container* AuthZ switches and aggregate matrix
    # coverage on every production release, including script-only deploys.
    # Read-only, no credentials/identity records in CI logs, no V2 activation.
    subprocess.run([*sudo, "python3", str(ROOT / "scripts/authz/inspect_runtime.py"), *compose],
                   check=True, cwd=ROOT, timeout=240)
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
    # Probe real SSO only when Employees or Storage is in the release;
    # unrelated Finance and client smoke checks remain independent.
    if selected.intersection({'employees', 'storage-s3', 'storage-drive', 'storage-api'}):
        verify_storage_authenticated_navigation(compose, sudo, host)
    if selected.intersection({'storage-s3','storage-drive','storage-api'}):
        verify_storage_pilot(host)
    for c in data["components"]:
        if not selected.intersection([c["service"], *c.get("aliases", [])]):
            continue
        if c["id"] in {"brandbook", "design-system", "storage-s3", "storage", "storage-api"}:
            continue  # Specialized checks cover branded SPAs and private S3/Drive origins.
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
