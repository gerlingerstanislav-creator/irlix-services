#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
cd /opt/irlix-services

if docker compose version >/dev/null 2>&1; then
  COMPOSE="docker compose"
else
  COMPOSE="docker-compose"
fi
COMPOSE="$COMPOSE -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.images.yml"

for entry in \
  'RECRUITMENT_DB_USER=recruitment_app' \
  'RECRUITMENT_DB_PASSWORD=recruitment_local'
do
  key="${entry%%=*}"
  grep -q "^${key}=" .env || echo "$entry" | $SUDO tee -a .env >/dev/null
done

$SUDO sh -c "$COMPOSE --env-file .env up -d --no-build postgres redis keycloak employees specialists recruitment recruitment-web"
$SUDO sh -c "$COMPOSE --env-file .env exec -T postgres sh /docker-entrypoint-initdb.d/001-init-schemas.sh < /dev/null"

attempt=0
until curl -fsS --max-time 3 http://127.0.0.1:8094/api/health | grep -q '"service":"recruitment"'; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 30 ]; then
    echo 'Recruitment backend did not become healthy.' >&2
    $SUDO sh -c "$COMPOSE --env-file .env ps recruitment recruitment-web" || true
    $SUDO sh -c "$COMPOSE --env-file .env logs --tail=150 recruitment" || true
    exit 1
  fi
  sleep 2
done

$SUDO sh -c "$COMPOSE --env-file .env exec -T recruitment php artisan migrate --force < /dev/null"

echo 'Recruitment runtime is ready.'
