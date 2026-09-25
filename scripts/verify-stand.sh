#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
cd /opt/irlix-services
if docker compose version >/dev/null 2>&1; then COMPOSE="docker compose"; else COMPOSE="docker-compose"; fi

HOST_HEADER=192.168.90.100
PUBLIC_KEYCLOAK_URL="http://$HOST_HEADER/keycloak/auth"

fail() {
  echo "VERIFY FAILED: $*" >&2
  exit 1
}

curl_stand() {
  curl -H "Host: $HOST_HEADER" "$@"
}

ensure_platform_nginx_site() {
  changed=false
  for site in /etc/nginx/sites-enabled/*; do
    [ -e "$site" ] || continue
    [ "$(basename "$site")" = "irlix-services" ] && continue
    if $SUDO grep -Eq 'server_name[^;]*192\.168\.90\.100' "$site" 2>/dev/null; then
      echo "[verify] disabling legacy nginx site shadowing $HOST_HEADER: $site"
      $SUDO rm -f "$site"
      changed=true
    fi
  done
  if [ "$changed" = true ]; then
    $SUDO nginx -t
    $SUDO systemctl reload nginx
  fi
}

check_body() {
  name="$1"; url="$2"; service="$3"; expected="$4"
  echo "[verify] $name -> $url (Host: $HOST_HEADER)"
  response="$(curl_stand -fsS --retry 20 --retry-all-errors --retry-delay 2 --max-time 10 "$url")" || {
    $SUDO $COMPOSE logs --tail=120 "$service" || true
    fail "$name is unreachable"
  }
  if ! printf '%s' "$response" | grep -q "$expected"; then
    printf '%s\n' "$response" | head -c 1000 >&2 || true
    printf '\n' >&2
    fail "$name body does not contain: $expected"
  fi
  echo "[verify] $name OK"
}

check_status() {
  name="$1"; url="$2"; expected="$3"
  actual="$(curl_stand -sS -o /dev/null -w '%{http_code}' --retry 8 --retry-all-errors --retry-delay 1 "$url")"
  echo "[verify] $name -> HTTP $actual (expected $expected)"
  [ "$actual" = "$expected" ] || fail "$name returned HTTP $actual instead of $expected"
}

ensure_platform_nginx_site

check_body "Dashboard" http://127.0.0.1/ portal "Dashboard"
dashboard_asset="$(curl_stand -fsS http://127.0.0.1/ | grep -o '/assets/[^\"'"'"']*\.js' | head -n1)"
[ -n "$dashboard_asset" ] || fail "Dashboard JS asset was not found in HTML"
check_status "Dashboard JS" "http://127.0.0.1$dashboard_asset" 200
check_body "Employees frontend" http://127.0.0.1/employees/ web "IRLIX Services"
check_body "Vacations frontend" http://127.0.0.1/vacations/ vacations-web 'id=.app.'
check_body "Design System" http://127.0.0.1/design-system/ design-system "IRLIX Design System"

echo "[verify] OIDC discovery uses public Keycloak URL"
oidc_discovery="$(curl_stand -fsS --retry 20 --retry-all-errors --retry-delay 2 --max-time 10 http://127.0.0.1/keycloak/auth/realms/irlix/.well-known/openid-configuration)" || {
  $SUDO $COMPOSE logs --tail=120 keycloak || true
  fail "OIDC discovery is unreachable"
}
printf '%s' "$oidc_discovery" | grep -Fq "\"issuer\":\"$PUBLIC_KEYCLOAK_URL/realms/irlix\"" || fail "OIDC issuer is not the public Keycloak URL"
if printf '%s' "$oidc_discovery" | grep -Fq 'keycloak:8080'; then
  fail "OIDC discovery exposes internal Keycloak hostname"
fi
echo "[verify] OIDC public hostname OK"

check_status "Legacy /auth" http://127.0.0.1/auth 404

echo "[verify] Employees -> internal Keycloak JWKS"
$SUDO $COMPOSE exec -T employees php -r '
$url = "http://keycloak:8080/keycloak/auth/realms/irlix/protocol/openid-connect/certs";
$body = @file_get_contents($url);
if ($body === false) { fwrite(STDERR, "JWKS unreachable\n"); exit(1); }
$data = json_decode($body, true);
if (!is_array($data) || empty($data["keys"])) { fwrite(STDERR, "JWKS payload invalid\n"); exit(1); }
echo "JWKS OK\n";
' || {
  $SUDO $COMPOSE logs --tail=120 employees keycloak || true
  fail "Employees cannot reach Keycloak JWKS"
}

echo "[verify] Employees audit/outbox migration"
$SUDO $COMPOSE exec -T employees php artisan migrate:status --no-ansi | grep -q '2026_09_25_000010_create_audit_and_outbox' || {
  $SUDO $COMPOSE logs --tail=120 employees || true
  fail "Employees audit/outbox migration is not installed"
}
echo "[verify] Employees audit/outbox migration OK"

echo "[verify] Employees event publisher"
events_container="$($SUDO $COMPOSE ps -q employees-events)"
[ -n "$events_container" ] || fail "Employees event publisher container is missing"
[ "$($SUDO docker inspect -f '{{.State.Running}}' "$events_container")" = "true" ] || {
  $SUDO $COMPOSE logs --tail=120 employees-events || true
  fail "Employees event publisher is not running"
}
$SUDO $COMPOSE exec -T rabbitmq rabbitmqctl list_exchanges name 2>/dev/null | grep -qx 'irlix.events' || {
  $SUDO $COMPOSE logs --tail=120 employees-events rabbitmq || true
  fail "Employees events exchange is not declared"
}
echo "[verify] Employees event publisher OK"

echo "[verify] Vacations schema"
$SUDO $COMPOSE exec -T vacations php artisan migrate:status --no-ansi || true
$SUDO $COMPOSE exec -T vacations php -r '
$host = getenv("DB_HOST") ?: "postgres";
$port = getenv("DB_PORT") ?: "5432";
$db = getenv("DB_DATABASE") ?: "irlix_services";
$user = getenv("DB_USERNAME") ?: "vacations_app";
$password = getenv("DB_PASSWORD") ?: "";
$pdo = new PDO("pgsql:host={$host};port={$port};dbname={$db}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$relation = $pdo->query("select to_regclass('"'"'vacations.absences'"'"')")->fetchColumn();
if ($relation !== "vacations.absences") { fwrite(STDERR, "vacations.absences is missing\n"); exit(1); }
echo "vacations.absences OK\n";
' || {
  $SUDO $COMPOSE logs --tail=120 vacations || true
  fail "Vacations absences table is not installed"
}
echo "[verify] Vacations schema OK"

auth_status="$(curl_stand -sS -o /tmp/oidc-auth.html -w '%{http_code}' 'http://127.0.0.1/keycloak/auth/realms/irlix/protocol/openid-connect/auth?client_id=irlix-services-web&redirect_uri=http%3A%2F%2F192.168.90.100%2F&response_type=code&scope=openid&state=ci-smoke')"
echo "[verify] OIDC authorization form -> HTTP $auth_status"
[ "$auth_status" = 200 ] || { cat /tmp/oidc-auth.html; fail "OIDC authorization endpoint returned HTTP $auth_status"; }
grep -q 'name="username"' /tmp/oidc-auth.html || fail "OIDC login form has no username field"

check_body "Platform Core health" http://127.0.0.1/api/platform/health platform-core '"status":"ok"'
check_body "Employees health" http://127.0.0.1/api/employees/health employees '"identity"'
check_body "Vacations health" http://127.0.0.1/api/vacations/health vacations '"service":"vacations"'
check_status "Anonymous departments API" http://127.0.0.1/api/employees/departments 401
check_status "Anonymous employees API" http://127.0.0.1/api/employees/employees 401
check_status "Anonymous audit API" http://127.0.0.1/api/employees/audit 401
check_status "Anonymous vacations me API" http://127.0.0.1/api/vacations/me 401

echo "[verify] Recent Keycloak mail-related errors"
$SUDO $COMPOSE logs --since=30m keycloak 2>&1 | grep -Ei 'mail|smtp|email|messagingexception|authenticationfailed|sendfailed|ssl|tls|535|550|553' | tail -n 120 || true

echo "Stand verification passed."
