#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
cd /opt/irlix-services
if docker compose version >/dev/null 2>&1; then COMPOSE="docker compose"; else COMPOSE="docker-compose"; fi
HOST_HEADER=192.168.90.100

fail() { echo "TIMESHEETS VERIFY FAILED: $*" >&2; exit 1; }
curl_stand() { curl -H "Host: $HOST_HEADER" "$@"; }

echo "[routing] direct SPA routes"
for route in \
  employees/employees employees/departments employees/roles employees/audit \
  vacations/mine vacations/department vacations/management vacations/audit \
  clients/clients clients/leads clients/contacts clients/requests clients/positions clients/attempts clients/members clients/cashflow clients/reporting-periods \
  timesheets/mine timesheets/management timesheets/commercial-load timesheets/audit \
  design-system/components design-system/navigation
do
  code="$(curl_stand -sS -o /dev/null -w '%{http_code}' --retry 8 --retry-all-errors --retry-delay 1 "http://127.0.0.1/$route/")"
  [ "$code" = 200 ] || fail "frontend route /$route/ returned HTTP $code"
done
echo "[routing] direct SPA routes OK"

echo "[timesheets] frontend"
html="$(curl_stand -fsS --retry 20 --retry-all-errors --retry-delay 2 http://127.0.0.1/timesheets/)" || {
  $SUDO $COMPOSE logs --tail=160 timesheets-web || true
  fail "frontend is unreachable"
}
printf '%s' "$html" | grep -q 'id="app"' || fail "frontend shell is invalid"
asset="$(printf '%s' "$html" | grep -o '/timesheets/assets/[^\"'"'"']*\.js' | head -n1)"
[ -n "$asset" ] || fail "frontend JS asset not found"
code="$(curl_stand -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1$asset")"
[ "$code" = 200 ] || fail "frontend JS returned HTTP $code"

echo "[timesheets] health"
health="$(curl_stand -fsS --retry 20 --retry-all-errors --retry-delay 2 http://127.0.0.1/api/timesheets/health)" || {
  $SUDO $COMPOSE logs --tail=160 timesheets || true
  fail "health is unreachable"
}
printf '%s' "$health" | grep -q '"service":"timesheets"' || fail "health payload is invalid"
printf '%s' "$health" | grep -q '"database":"ok"' || fail "database health is not ok"

echo "[timesheets] migration"
$SUDO $COMPOSE exec -T timesheets php artisan migrate:status --no-ansi | grep -q '2026_09_27_000001_create_timesheets' || {
  $SUDO $COMPOSE logs --tail=160 timesheets || true
  fail "timesheets migration is not installed"
}

echo "[timesheets] anonymous API protection"
code="$(curl_stand -sS -o /dev/null -w '%{http_code}' 'http://127.0.0.1/api/timesheets/workspace?month=2026-09')"
[ "$code" = 401 ] || fail "anonymous workspace API returned HTTP $code instead of 401"

echo "Timesheets stand verification passed."
