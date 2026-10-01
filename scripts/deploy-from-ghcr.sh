#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
cd /opt/irlix-services

if docker compose version >/dev/null 2>&1; then
  COMPOSE="docker compose"
else
  COMPOSE="docker-compose"
fi
COMPOSE="$COMPOSE -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.images.yml -f docker-compose.cv.yml"

upsert_env() {
  key="$1"; value="$2"; file=.env
  if $SUDO grep -q "^${key}=" "$file"; then
    escaped=$(printf '%s' "$value" | sed 's/[&|\\]/\\&/g')
    $SUDO sed -i "s|^${key}=.*|${key}=${escaped}|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" | $SUDO tee -a "$file" >/dev/null
  fi
}

upsert_env IRLIX_PUBLIC_URL "$IRLIX_PUBLIC_URL"
upsert_env KEYCLOAK_PUBLIC_URL "${IRLIX_PUBLIC_URL}/keycloak/auth"
upsert_env KEYCLOAK_ISSUER "${IRLIX_PUBLIC_URL}/keycloak/auth/realms/irlix"

current_purge_token=$($SUDO sh -c "grep '^IRLIX_INTERNAL_PURGE_TOKEN=' .env 2>/dev/null | head -n1 | cut -d= -f2-" || true)
if [ -z "$current_purge_token" ] || [ "$current_purge_token" = "irlix-local-purge-token" ]; then
  current_purge_token=$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')
  upsert_env IRLIX_INTERNAL_PURGE_TOKEN "$current_purge_token"
fi

set_tag() { upsert_env "$1" "$GITHUB_SHA"; }
services=""

if [ "${FULL:-false}" = true ]; then
  for key in PLATFORM_CORE_IMAGE_TAG EMPLOYEES_IMAGE_TAG EMPLOYEES_WEB_IMAGE_TAG VACATIONS_IMAGE_TAG VACATIONS_WEB_IMAGE_TAG CLIENTS_IMAGE_TAG CLIENTS_WEB_IMAGE_TAG TIMESHEETS_IMAGE_TAG TIMESHEETS_WEB_IMAGE_TAG SPECIALISTS_IMAGE_TAG SPECIALISTS_WEB_IMAGE_TAG RECRUITMENT_IMAGE_TAG RECRUITMENT_WEB_IMAGE_TAG DESIGN_SYSTEM_IMAGE_TAG PORTAL_IMAGE_TAG; do
    set_tag "$key"
  done
else
  [ "${PLATFORM_CORE:-false}" = true ] && { set_tag PLATFORM_CORE_IMAGE_TAG; services="$services platform-core"; }
  [ "${EMPLOYEES:-false}" = true ] && { set_tag EMPLOYEES_IMAGE_TAG; services="$services employees employees-events"; }
  [ "${WEB:-false}" = true ] && { set_tag EMPLOYEES_WEB_IMAGE_TAG; services="$services web"; }
  [ "${VACATIONS:-false}" = true ] && { set_tag VACATIONS_IMAGE_TAG; set_tag VACATIONS_WEB_IMAGE_TAG; services="$services vacations vacations-web"; }
  [ "${CLIENTS:-false}" = true ] && { set_tag CLIENTS_IMAGE_TAG; set_tag CLIENTS_WEB_IMAGE_TAG; services="$services clients clients-web"; }
  [ "${TIMESHEETS:-false}" = true ] && { set_tag TIMESHEETS_IMAGE_TAG; set_tag TIMESHEETS_WEB_IMAGE_TAG; services="$services timesheets timesheets-web"; }
  [ "${SPECIALISTS:-false}" = true ] && { set_tag SPECIALISTS_IMAGE_TAG; set_tag SPECIALISTS_WEB_IMAGE_TAG; services="$services specialists specialists-web"; }
  [ "${RECRUITMENT:-false}" = true ] && { set_tag RECRUITMENT_IMAGE_TAG; set_tag RECRUITMENT_WEB_IMAGE_TAG; services="$services recruitment recruitment-web"; }
  [ "${PORTAL:-false}" = true ] && { set_tag PORTAL_IMAGE_TAG; services="$services portal"; }
  [ "${DESIGN_SYSTEM:-false}" = true ] && { set_tag DESIGN_SYSTEM_IMAGE_TAG; services="$services design-system"; }
  [ "${AUTH:-false}" = true ] && services="$services keycloak"
fi

for entry in \
  'TIMESHEETS_DB_USER=timesheets_app' \
  'TIMESHEETS_DB_PASSWORD=timesheets_local' \
  'SPECIALISTS_DB_USER=specialists_app' \
  'SPECIALISTS_DB_PASSWORD=specialists_local' \
  'RECRUITMENT_DB_USER=recruitment_app' \
  'RECRUITMENT_DB_PASSWORD=recruitment_local'
do
  key="${entry%%=*}"
  grep -q "^${key}=" .env || echo "$entry" | $SUDO tee -a .env >/dev/null
done

printf '%s' "$GHCR_TOKEN" | $SUDO docker login ghcr.io -u "$GHCR_USER" --password-stdin >/dev/null

$SUDO cp infra/nginx/irlix-services.conf /etc/nginx/sites-available/irlix-services
$SUDO ln -sfn /etc/nginx/sites-available/irlix-services /etc/nginx/sites-enabled/irlix-services
$SUDO rm -f /etc/nginx/sites-enabled/default
$SUDO nginx -t

# GHCR deployments do not need historical local images or build cache. Running
# container images are retained by Docker; only unused images/cache are removed.
$SUDO docker image prune -af >/dev/null || true
$SUDO docker builder prune -af >/dev/null || true

if [ "${FULL:-false}" = true ]; then
  $SUDO sh -c "$COMPOSE --env-file .env pull"
  # CV converter is still built on the stand while its backend/frontend are
  # being validated. The local LLM image is pulled and its model cache persists.
  $SUDO sh -c "$COMPOSE --env-file .env build cv-converter cv-web"
  $SUDO sh -c "$COMPOSE --env-file .env up -d --no-build --remove-orphans"

  echo "Waiting for CV local LLM to become ready..."
  cv_llm_ready=false
  i=0
  while [ "$i" -lt 120 ]; do
    if curl -fsS --max-time 10 http://127.0.0.1:8097/api/health/llm 2>/dev/null | grep -q '"status":"ok"'; then
      cv_llm_ready=true
      break
    fi
    i=$((i + 1))
    sleep 3
  done
  if [ "$cv_llm_ready" != true ]; then
    $SUDO sh -c "$COMPOSE --env-file .env logs --tail=160 cv-llm cv-converter" || true
    echo "CV local LLM did not become ready" >&2
    exit 1
  fi
  echo "CV local LLM ready."

  if [ "$(grep '^CV_LLM_PROVIDER=' .env 2>/dev/null | tail -n1 | cut -d= -f2- || true)" = "" ] || [ "$(grep '^CV_LLM_PROVIDER=' .env 2>/dev/null | tail -n1 | cut -d= -f2- || true)" = "local" ]; then
    echo "Running CV real-inference smoke..."
    $SUDO sh -c "$COMPOSE --env-file .env exec -T cv-converter python -m app.smoke" || {
      $SUDO sh -c "$COMPOSE --env-file .env logs --tail=160 cv-llm cv-converter" || true
      echo "CV real-inference smoke failed" >&2
      exit 1
    }
  fi
elif [ -n "${services# }" ]; then
  $SUDO sh -c "$COMPOSE --env-file .env pull $services"
  $SUDO sh -c "$COMPOSE --env-file .env up -d --no-build $services"
fi

if [ "${FULL:-false}" = true ] || [ "${VACATIONS:-false}" = true ] || [ "${CLIENTS:-false}" = true ] || [ "${TIMESHEETS:-false}" = true ] || [ "${SPECIALISTS:-false}" = true ] || [ "${RECRUITMENT:-false}" = true ]; then
  $SUDO sh -c "$COMPOSE --env-file .env exec -T postgres sh /docker-entrypoint-initdb.d/001-init-schemas.sh < /dev/null"
fi
[ "${FULL:-false}" = true ] || [ "${EMPLOYEES:-false}" = true ] && $SUDO sh -c "$COMPOSE --env-file .env exec -T employees php artisan migrate --force < /dev/null"
[ "${FULL:-false}" = true ] || [ "${VACATIONS:-false}" = true ] && $SUDO sh -c "$COMPOSE --env-file .env exec -T vacations php artisan migrate --force < /dev/null"
[ "${FULL:-false}" = true ] || [ "${CLIENTS:-false}" = true ] && $SUDO sh -c "$COMPOSE --env-file .env exec -T clients php artisan migrate --force < /dev/null"
[ "${FULL:-false}" = true ] || [ "${TIMESHEETS:-false}" = true ] && $SUDO sh -c "$COMPOSE --env-file .env exec -T timesheets php artisan migrate --force < /dev/null"
[ "${FULL:-false}" = true ] || [ "${SPECIALISTS:-false}" = true ] && $SUDO sh -c "$COMPOSE --env-file .env exec -T specialists php artisan migrate --force < /dev/null"
[ "${FULL:-false}" = true ] || [ "${RECRUITMENT:-false}" = true ] && $SUDO sh -c "$COMPOSE --env-file .env exec -T recruitment php artisan migrate --force < /dev/null"

$SUDO systemctl reload nginx
