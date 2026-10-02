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

container_env_value() {
  service="$1"
  key="$2"
  cid=$($SUDO sh -c "$COMPOSE --env-file .env ps -q $service" 2>/dev/null || true)
  [ -n "$cid" ] || return 1
  $SUDO docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$cid" 2>/dev/null \
    | sed -n "s/^${key}=//p" \
    | head -n1
}

expected_key="$(grep '^MIGRATION_APP_KEY=' .env 2>/dev/null | tail -n1 | cut -d= -f2-)"
[ -n "$expected_key" ] || fail "MIGRATION_APP_KEY is missing from server .env"

api_app_key="$(container_env_value migration APP_KEY || true)"
worker_app_key="$(container_env_value migration-worker APP_KEY || true)"
api_migration_key="$(container_env_value migration MIGRATION_APP_KEY || true)"
worker_migration_key="$(container_env_value migration-worker MIGRATION_APP_KEY || true)"

[ "$api_app_key" = "$expected_key" ] || fail "migration API APP_KEY differs from persistent MIGRATION_APP_KEY"
[ "$worker_app_key" = "$expected_key" ] || fail "migration-worker APP_KEY differs from persistent MIGRATION_APP_KEY"
[ "$api_migration_key" = "$expected_key" ] || fail "migration API MIGRATION_APP_KEY is missing or differs from server .env"
[ "$worker_migration_key" = "$expected_key" ] || fail "migration-worker MIGRATION_APP_KEY is missing or differs from server .env"
[ "$api_migration_key" = "$worker_migration_key" ] || fail "migration API and migration-worker use different dedicated credential keys"

health="$(curl -H "Host: $HOST_HEADER" -fsS --retry 20 --retry-all-errors --retry-delay 2 --max-time 10 http://127.0.0.1/api/migration/health)" || fail "health endpoint is unreachable"
printf '%s' "$health" | grep -q '"service":"migration"' || fail "health payload is invalid"
printf '%s' "$health" | grep -q '"status":"ok"' || fail "health status is not ok"

anonymous_status="$(curl -H "Host: $HOST_HEADER" -sS -o /tmp/migration-anonymous.json -w '%{http_code}' --max-time 10 http://127.0.0.1/api/migration/state)"
[ "$anonymous_status" = "401" ] || {
  cat /tmp/migration-anonymous.json >&2 || true
  fail "anonymous state endpoint returned HTTP $anonymous_status instead of 401"
}

echo "Migration Service verification passed. API and worker share the same dedicated MIGRATION_APP_KEY; no legacy DB connection was attempted."
