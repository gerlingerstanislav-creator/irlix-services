#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
cd /opt/irlix-services
if docker compose version >/dev/null 2>&1; then COMPOSE="docker compose"; else COMPOSE="docker-compose"; fi
COMPOSE="$COMPOSE -f docker-compose.yml -f docker-compose.migration.yml"

PUBLIC_URL="$(grep '^IRLIX_PUBLIC_URL=' .env | tail -n1 | cut -d= -f2-)"
[ -n "$PUBLIC_URL" ] || { echo "MIGRATION VERIFY FAILED: IRLIX_PUBLIC_URL is missing" >&2; exit 1; }
HOST_HEADER=${PUBLIC_URL#http://}
HOST_HEADER=${HOST_HEADER#https://}
HOST_HEADER=${HOST_HEADER%%/*}

fail() {
  echo "MIGRATION VERIFY FAILED: $*" >&2
  $SUDO sh -c "$COMPOSE --env-file .env ps migration migration-worker" || true
  $SUDO sh -c "$COMPOSE --env-file .env logs --tail=120 migration migration-worker" || true
  exit 1
}

container_app_key() {
  service="$1"
  cid=$($SUDO sh -c "$COMPOSE --env-file .env ps -q $service" 2>/dev/null || true)
  [ -n "$cid" ] || return 1
  $SUDO docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$cid" 2>/dev/null \
    | sed -n 's/^APP_KEY=//p' \
    | head -n1
}

expected_key="$(grep '^MIGRATION_APP_KEY=' .env 2>/dev/null | tail -n1 | cut -d= -f2-)"
[ -n "$expected_key" ] || fail "MIGRATION_APP_KEY is missing from server .env"
api_key="$(container_app_key migration || true)"
worker_key="$(container_app_key migration-worker || true)"
[ -n "$api_key" ] || fail "migration API container APP_KEY is missing"
[ -n "$worker_key" ] || fail "migration-worker container APP_KEY is missing"
[ "$api_key" = "$expected_key" ] || fail "migration API APP_KEY differs from persistent MIGRATION_APP_KEY"
[ "$worker_key" = "$expected_key" ] || fail "migration-worker APP_KEY differs from persistent MIGRATION_APP_KEY"
[ "$api_key" = "$worker_key" ] || fail "migration API and migration-worker use different APP_KEY values"

health="$(curl -H "Host: $HOST_HEADER" -fsS --retry 20 --retry-all-errors --retry-delay 2 --max-time 10 http://127.0.0.1/api/migration/health)" || fail "health endpoint is unreachable"
printf '%s' "$health" | grep -q '"service":"migration"' || fail "health payload is invalid"
printf '%s' "$health" | grep -q '"status":"ok"' || fail "health status is not ok"

anonymous_status="$(curl -H "Host: $HOST_HEADER" -sS -o /tmp/migration-anonymous.json -w '%{http_code}' --max-time 10 http://127.0.0.1/api/migration/state)"
[ "$anonymous_status" = "401" ] || {
  cat /tmp/migration-anonymous.json >&2 || true
  fail "anonymous state endpoint returned HTTP $anonymous_status instead of 401"
}

echo "Migration Service verification passed. API and worker use the same persistent encryption key; no legacy DB connection was attempted."
