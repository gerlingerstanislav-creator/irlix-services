#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
cd /opt/irlix-services
if docker compose version >/dev/null 2>&1; then COMPOSE="docker compose"; else COMPOSE="docker-compose"; fi

HOST_HEADER=192.168.90.100

fail() {
  echo "VERIFY FAILED: $*" >&2
  exit 1
}

curl_stand() {
  curl -H "Host: $HOST_HEADER" "$@"
}

check_body() {
  name="$1"; url="$2"; service="$3"; expected="$4"
  echo "[verify] $name -> $url (Host: $HOST_HEADER)"
  response="$(curl_stand -fsS --retry 20 --retry-all-errors --retry-delay 2 --max-time 10 "$url")" || {
    $SUDO $COMPOSE logs --tail=120 "$service" || true
    fail "$name is unreachable"
  }
  printf '%s' "$response" | grep -q "$expected" || fail "$name body does not contain: $expected"
  echo "[verify] $name OK"
}

check_status() {
  name="$1"; url="$2"; expected="$3"
  actual="$(curl_stand -sS -o /dev/null -w '%{http_code}' --retry 8 --retry-all-errors --retry-delay 1 "$url")"
  echo "[verify] $name -> HTTP $actual (expected $expected)"
  [ "$actual" = "$expected" ] || fail "$name returned HTTP $actual instead of $expected"
}

diagnose_logout() {
  echo "--- logout routing diagnostics ---"
  echo "Enabled nginx sites:"
  $SUDO ls -la /etc/nginx/sites-enabled 2>&1 || true
  echo "All active nginx config file markers and server names/listens:"
  $SUDO nginx -T 2>&1 | grep -n -E '^# configuration file |^[[:space:]]*(listen|server_name)[[:space:]]' || true
  echo "Definitions mentioning 192.168.90.100:"
  $SUDO nginx -T 2>&1 | grep -n -A8 -B8 '192\.168\.90\.100' || true
  echo "Host nginx logout configuration:"
  $SUDO nginx -T 2>&1 | grep -n -A8 -B4 'auth/logout' || true
  echo "Direct Portal root:"
  curl -sS -o /tmp/portal-root.html -w 'HTTP %{http_code}\n' http://127.0.0.1:8084/ || true
  echo "Direct Portal logout:"
  curl -sS -o /tmp/portal-logout.html -w 'HTTP %{http_code}\n' http://127.0.0.1:8084/auth/logout/ || true
  echo "Portal container nginx config:"
  $SUDO $COMPOSE exec -T portal nginx -T 2>&1 | grep -n -A12 -B4 'auth/logout' || true
  echo "Host logout response headers:"
  curl_stand -sS -D - -o /tmp/host-logout.html http://127.0.0.1/auth/logout/ || true
  echo "Host logout body (first 300 bytes):"
  head -c 300 /tmp/host-logout.html 2>/dev/null || true
  echo
  echo "--- end logout routing diagnostics ---"
}

check_body "Dashboard" http://127.0.0.1/ portal "Dashboard"
dashboard_asset="$(curl_stand -fsS http://127.0.0.1/ | grep -o '/assets/[^\"'"'"']*\.js' | head -n1)"
[ -n "$dashboard_asset" ] || fail "Dashboard JS asset was not found in HTML"
check_status "Dashboard JS" "http://127.0.0.1$dashboard_asset" 200
check_body "Employees frontend" http://127.0.0.1/employees/ web "IRLIX Services"
check_body "Design System" http://127.0.0.1/design-system/ design-system "IRLIX Design System"
check_body "OIDC discovery" http://127.0.0.1/keycloak/auth/realms/irlix/.well-known/openid-configuration keycloak '"issuer"'
check_status "Legacy /auth" http://127.0.0.1/auth 404
logout_status="$(curl_stand -sS -o /dev/null -w '%{http_code}' http://127.0.0.1/auth/logout/)"
echo "[verify] Platform logout -> HTTP $logout_status (expected 200)"
if [ "$logout_status" != 200 ]; then
  diagnose_logout
  fail "Platform logout returned HTTP $logout_status instead of 200"
fi

auth_status="$(curl_stand -sS -o /tmp/oidc-auth.html -w '%{http_code}' 'http://127.0.0.1/keycloak/auth/realms/irlix/protocol/openid-connect/auth?client_id=irlix-services-web&redirect_uri=http%3A%2F%2F192.168.90.100%2F&response_type=code&scope=openid&state=ci-smoke')"
echo "[verify] OIDC authorization form -> HTTP $auth_status"
[ "$auth_status" = 200 ] || { cat /tmp/oidc-auth.html; fail "OIDC authorization endpoint returned HTTP $auth_status"; }
grep -q 'name="username"' /tmp/oidc-auth.html || fail "OIDC login form has no username field"

check_body "Platform Core health" http://127.0.0.1/api/platform/health platform-core '"status":"ok"'
check_body "Employees health" http://127.0.0.1/api/employees/health employees '"identity"'
check_status "Anonymous departments API" http://127.0.0.1/api/employees/departments 401
check_status "Anonymous employees API" http://127.0.0.1/api/employees/employees 401

echo "Stand verification passed."
