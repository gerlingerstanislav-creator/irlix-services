#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
cd /opt/irlix-services
if docker compose version >/dev/null 2>&1; then COMPOSE="docker compose"; else COMPOSE="docker-compose"; fi

fail() {
  echo "VERIFY FAILED: $*" >&2
  exit 1
}

check_body() {
  name="$1"; url="$2"; service="$3"; expected="$4"
  echo "[verify] $name -> $url"
  response="$(curl -fsS --retry 20 --retry-all-errors --retry-delay 2 --max-time 10 "$url")" || {
    $SUDO $COMPOSE logs --tail=120 "$service" || true
    fail "$name is unreachable"
  }
  printf '%s' "$response" | grep -q "$expected" || fail "$name body does not contain: $expected"
  echo "[verify] $name OK"
}

check_status() {
  name="$1"; url="$2"; expected="$3"
  actual="$(curl -sS -o /dev/null -w '%{http_code}' --retry 8 --retry-all-errors --retry-delay 1 "$url")"
  echo "[verify] $name -> HTTP $actual (expected $expected)"
  [ "$actual" = "$expected" ] || fail "$name returned HTTP $actual instead of $expected"
}

check_body "Dashboard" http://127.0.0.1/ portal "Dashboard"
dashboard_asset="$(curl -fsS http://127.0.0.1/ | grep -o '/assets/[^\"'"'"']*\.js' | head -n1)"
[ -n "$dashboard_asset" ] || fail "Dashboard JS asset was not found in HTML"
check_status "Dashboard JS" "http://127.0.0.1$dashboard_asset" 200
check_body "Employees frontend" http://127.0.0.1/employees/ web "IRLIX Services"
check_body "Design System" http://127.0.0.1/design-system/ design-system "IRLIX Design System"
check_body "OIDC discovery" http://127.0.0.1/keycloak/auth/realms/irlix/.well-known/openid-configuration keycloak '"issuer"'
check_status "Legacy /auth" http://127.0.0.1/auth 404
check_status "Platform logout" http://127.0.0.1/auth/logout/ 200

auth_status="$(curl -sS -o /tmp/oidc-auth.html -w '%{http_code}' 'http://127.0.0.1/keycloak/auth/realms/irlix/protocol/openid-connect/auth?client_id=irlix-services-web&redirect_uri=http%3A%2F%2F192.168.90.100%2F&response_type=code&scope=openid&state=ci-smoke')"
echo "[verify] OIDC authorization form -> HTTP $auth_status"
[ "$auth_status" = 200 ] || { cat /tmp/oidc-auth.html; fail "OIDC authorization endpoint returned HTTP $auth_status"; }
grep -q 'name="username"' /tmp/oidc-auth.html || fail "OIDC login form has no username field"

check_body "Platform Core health" http://127.0.0.1/api/platform/health platform-core '"status":"ok"'
check_body "Employees health" http://127.0.0.1/api/employees/health employees '"identity"'
check_status "Anonymous departments API" http://127.0.0.1/api/employees/departments 401
check_status "Anonymous employees API" http://127.0.0.1/api/employees/employees 401

echo "Stand verification passed."
