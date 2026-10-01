#!/bin/sh
set -eu

if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
cd /opt/irlix-services

COMPOSE="$(python3 scripts/ci/service_plan.py compose-args --env-file .env)"
# Generated values are shell-quoted and service/tag names are validated against the registry.
eval "$(python3 scripts/ci/service_plan.py deploy-controls)"

upsert_env() {
  key="$1"; value="$2"; file=.env
  if $SUDO grep -q "^${key}=" "$file"; then
    escaped=$(printf '%s' "$value" | sed 's/[&|\\]/\\&/g')
    $SUDO sed -i "s|^${key}=.*|${key}=${escaped}|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" | $SUDO tee -a "$file" >/dev/null
  fi
}

migration_runtime_diagnostics() {
  echo "Migration runtime diagnostics:" >&2
  $SUDO sh -c "$COMPOSE ps migration migration-worker" >&2 || true
  $SUDO sh -c "$COMPOSE logs --tail=200 migration migration-worker" >&2 || true
}

compose_up_or_diagnose() {
  if ! $SUDO sh -c "$COMPOSE up -d --no-build $*"; then
    migration_runtime_diagnostics
    return 1
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

# Migration DB credentials entered from the admin console are encrypted at rest. Generate the
# encryption key once on the server and keep it stable across container replacements.
current_migration_key=$($SUDO sh -c "grep '^MIGRATION_APP_KEY=' .env 2>/dev/null | head -n1 | cut -d= -f2-" || true)
if [ -z "$current_migration_key" ]; then
  current_migration_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
  upsert_env MIGRATION_APP_KEY "$current_migration_key"
fi

# Keep the previous image references for a reviewable rollback; never copy secrets.
mkdir -p .ci
grep '_IMAGE_TAG=' .env > .ci/previous-image-tags.env || true
while IFS='=' read -r key tag; do
  [ -n "$key" ] && upsert_env "$key" "$tag"
done <<EOF
$(python3 scripts/ci/service_plan.py deploy-env)
EOF

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

if [ "$ROUTING" = true ]; then
  $SUDO cp infra/nginx/irlix-services.conf /etc/nginx/sites-available/irlix-services
  $SUDO ln -sfn /etc/nginx/sites-available/irlix-services /etc/nginx/sites-enabled/irlix-services
  $SUDO rm -f /etc/nginx/sites-enabled/default
  $SUDO nginx -t
  $SUDO systemctl reload nginx
fi

# Keep reusable image layers and the previous release. Cleanup is a separate
# maintenance operation, not part of every application deployment.

if [ -n "$DEPLOY_SERVICES" ]; then
  $SUDO sh -c "$COMPOSE pull $DEPLOY_SERVICES"
fi
# Bootstrap new schema/users before starting a backend that needs them.
if [ "$SCHEMA" = true ]; then
  compose_up_or_diagnose postgres
  $SUDO sh -c "$COMPOSE exec -T postgres sh /docker-entrypoint-initdb.d/001-init-schemas.sh < /dev/null"
fi
if [ "$FULL" = true ]; then
  compose_up_or_diagnose --remove-orphans
elif [ -n "$DEPLOY_SERVICES" ]; then
  compose_up_or_diagnose $DEPLOY_SERVICES
fi
for service in $MIGRATE_SERVICES; do
  $SUDO sh -c "$COMPOSE exec -T $service php artisan migrate --force < /dev/null"
done

if [ "$CV_CHECK" = true ]; then
  echo "Waiting for CV local LLM to become ready..."
  cv_llm_ready=false
  i=0
  while [ "$i" -lt 60 ]; do
    if curl -fsS --connect-timeout 1 --max-time 2 http://127.0.0.1:8097/api/health/llm 2>/dev/null | grep -q '"status":"ok"'; then
      cv_llm_ready=true
      break
    fi
    # Repeated crashes are a terminal startup failure, not an inference warmup.
    llm_container=$($SUDO sh -c "$COMPOSE ps -q cv-llm" || true)
    if [ -n "$llm_container" ]; then
      restarts=$($SUDO docker inspect -f '{{.RestartCount}}' "$llm_container" || echo 0)
      if [ "$restarts" -ge 3 ]; then break; fi
    fi
    i=$((i + 1))
    sleep 1
  done
  if [ "$cv_llm_ready" != true ]; then
    $SUDO sh -c "$COMPOSE ps cv-llm cv-converter" || true
    $SUDO sh -c "$COMPOSE logs --tail=200 cv-llm cv-converter" || true
    echo "CV local LLM did not become ready within bounded readiness window" >&2
    exit 1
  fi
  echo "CV local LLM ready."

  if [ "$(grep '^CV_LLM_PROVIDER=' .env 2>/dev/null | tail -n1 | cut -d= -f2- || true)" = "" ] || [ "$(grep '^CV_LLM_PROVIDER=' .env 2>/dev/null | tail -n1 | cut -d= -f2- || true)" = "local" ]; then
    echo "Running CV real-inference smoke..."
    $SUDO sh -c "timeout 180 $COMPOSE exec -T -e CV_LLM_MAX_OUTPUT_TOKENS=900 -e CV_LLM_TIMEOUT_SECONDS=120 cv-converter python -m app.smoke" || {
      $SUDO sh -c "$COMPOSE ps cv-llm cv-converter" || true
      $SUDO sh -c "$COMPOSE logs --tail=200 cv-llm cv-converter" || true
      echo "CV real-inference smoke failed or timed out" >&2
      exit 1
    }
  fi
fi
