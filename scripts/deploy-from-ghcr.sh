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
  for service in migration migration-worker; do
    runtime_app_key=$(migration_container_key "$service" APP_KEY || true)
    runtime_migration_key=$(migration_container_key "$service" MIGRATION_APP_KEY || true)
    if [ "$runtime_app_key" = "$current_migration_key" ]; then app_state=matches; else app_state=missing_or_different; fi
    if [ "$runtime_migration_key" = "$current_migration_key" ]; then migration_state=matches; else migration_state=missing_or_different; fi
    echo "$service keys: APP_KEY=$app_state MIGRATION_APP_KEY=$migration_state (values hidden)" >&2
  done
  $SUDO sh -c "$COMPOSE logs --tail=200 migration migration-worker" >&2 || true
}

compose_up_or_diagnose() {
  if ! $SUDO sh -c "$COMPOSE up -d --no-build $*"; then
    migration_runtime_diagnostics
    return 1
  fi
}

migration_container_key() {
  service="$1"
  variable="$2"
  cid=$($SUDO sh -c "$COMPOSE ps -q $service" 2>/dev/null || true)
  [ -n "$cid" ] || return 1
  $SUDO docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$cid" 2>/dev/null \
    | sed -n "s/^${variable}=//p" \
    | head -n1
}

IRLIX_PUBLIC_URL="${IRLIX_CANONICAL_ORIGIN:-http://services.lan}"
upsert_env IRLIX_PUBLIC_URL "$IRLIX_PUBLIC_URL"
upsert_env KEYCLOAK_PUBLIC_URL "${IRLIX_PUBLIC_URL}/keycloak/auth"
upsert_env KEYCLOAK_ISSUER "${IRLIX_PUBLIC_URL}/keycloak/auth/realms/irlix"

current_purge_token=$($SUDO sh -c "grep '^IRLIX_INTERNAL_PURGE_TOKEN=' .env 2>/dev/null | head -n1 | cut -d= -f2-" || true)
if [ -z "$current_purge_token" ] || [ "$current_purge_token" = "irlix-local-purge-token" ]; then
  current_purge_token=$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')
  upsert_env IRLIX_INTERNAL_PURGE_TOKEN "$current_purge_token"
fi

current_timesheets_token=$($SUDO sh -c "grep '^IRLIX_TIMESHEETS_INTEGRATION_TOKEN=' .env 2>/dev/null | head -n1 | cut -d= -f2-" || true)
if [ -z "$current_timesheets_token" ] || [ "$current_timesheets_token" = "irlix-local-timesheets-token" ]; then
  current_timesheets_token=$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')
  upsert_env IRLIX_TIMESHEETS_INTEGRATION_TOKEN "$current_timesheets_token"
fi

# Provision Finance integration credentials on the server only, never commit values to Git.
# Idempotent: existing strong secrets are reused to avoid breaking ongoing settlements.
for finance_secret in IRLIX_FINANCE_CLIENTS_TOKEN IRLIX_FINANCE_TIMESHEETS_TOKEN IRLIX_FINANCE_EMPLOYEES_TOKEN; do
  finance_current=$($SUDO sh -c "grep '^${finance_secret}=' .env 2>/dev/null | head -n1 | cut -d= -f2-" || true)
  if [ "${#finance_current}" -lt 64 ]; then
    finance_value=$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')
    upsert_env "$finance_secret" "$finance_value"
  fi
done
# A finance DB role must never use the Docker/dev fallback password on VPS.
# Keep the generated value persistent across rollouts; the init step applies
# it to existing roles as well, so a previous weak password is rotated.
finance_db_password=$($SUDO sh -c "grep '^FINANCE_DB_PASSWORD=' .env 2>/dev/null | head -n1 | cut -d= -f2-" || true)
if [ "${#finance_db_password}" -lt 32 ]; then
  finance_db_password=$(od -An -N32 -tx1 /dev/urandom | tr -d ' \\n')
  upsert_env FINANCE_DB_PASSWORD "$finance_db_password"
fi
finance_app_key=$($SUDO sh -c "grep '^FINANCE_APP_KEY=' .env 2>/dev/null | head -n1 | cut -d= -f2-" || true)
if [ -z "$finance_app_key" ]; then
  finance_app_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
  upsert_env FINANCE_APP_KEY "$finance_app_key"
fi

# Migration DB credentials entered from the admin console are encrypted at rest. Generate the
# encryption key once on the server and keep it stable across container replacements.
current_migration_key=$($SUDO sh -c "grep '^MIGRATION_APP_KEY=' .env 2>/dev/null | head -n1 | cut -d= -f2-" || true)
if [ -z "$current_migration_key" ]; then
  current_migration_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
  upsert_env MIGRATION_APP_KEY "$current_migration_key"
fi

# Selective deploys must not leave the long-running queue worker with an old APP_KEY. Detect drift
# before deployment and recreate API + worker together only when at least one running container has
# a key different from the persistent server MIGRATION_APP_KEY.
migration_key_sync_required=false
migration_runtime_present=false
migration_runtime_incomplete=false
for service in migration migration-worker; do
  runtime_app_key=$(migration_container_key "$service" APP_KEY || true)
  runtime_migration_key=$(migration_container_key "$service" MIGRATION_APP_KEY || true)
  if [ -n "$runtime_app_key" ] || [ -n "$runtime_migration_key" ]; then
    migration_runtime_present=true
    if [ "$runtime_app_key" != "$current_migration_key" ] || [ "$runtime_migration_key" != "$current_migration_key" ]; then
      migration_key_sync_required=true
    fi
  else
    migration_runtime_incomplete=true
  fi
done
if [ "$migration_runtime_present" = true ] && [ "$migration_runtime_incomplete" = true ]; then
  migration_key_sync_required=true
fi

# Only gate a release on migration credential verification when this release actually touches the
# migration runtime (or when runtime key drift forces us to recreate it). An unrelated selective
# deploy must not be blocked by pre-existing migration credentials that already need rotation.
migration_deploy_requested=false
migration_release_guard_required="$FULL"
for service in $DEPLOY_SERVICES; do
  case "$service" in
    migration|migration-worker) migration_deploy_requested=true; migration_release_guard_required=true ;;
    migration-ops) migration_release_guard_required=true ;;
  esac
done
if [ "$migration_key_sync_required" = true ]; then
  migration_release_guard_required=true
fi
# Replacing migration-ops terminates its coordinator thread; replacing the
# worker can interrupt a database write. Check the ACTUAL persisted runtime
# state before changing any running container. Never treat an unavailable or
# unparseable state as evidence that it is idle.
if [ "$migration_release_guard_required" = true ]; then
  migration_ops_container=$($SUDO sh -c "$COMPOSE ps -q migration-ops" 2>/dev/null || true)
  if [ -z "$migration_ops_container" ]; then
    if [ "$migration_runtime_present" = true ]; then
      echo "Release blocked: migration API or worker exists but migration-ops is not available for the preflight." >&2
      exit 1
    fi
    echo "No existing migration runtime detected; allowing first-time bootstrap."
  else
    echo "Checking migration operations and queue before runtime replacement..."
    if ! $SUDO sh -c "$COMPOSE exec -T migration-ops python3 scripts/ci/check_migration_release_safety.py < /dev/null"; then
      echo "Release blocked before container changes: migration execution is active or cannot be safely verified." >&2
      exit 1
    fi
  fi
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

# The CI runner can preload SHA-verified images over SSH. When enabled,
# production Docker must not need GHCR DNS or credentials, and must fail closed
# if even one planned image is absent. This check precedes container changes.
if [ "${IRLIX_IMAGES_PRELOADED:-}" = "1" ]; then
  while IFS= read -r image; do
    [ -z "$image" ] && continue
    if ! $SUDO docker image inspect "$image" >/dev/null 2>&1; then
      echo "Preloaded verified image missing: $image; refusing runtime changes." >&2
      exit 1
    fi
  done <<EOF
$(python3 scripts/ci/service_plan.py image-refs)
EOF
  echo "Verified release images are loaded; skipping production GHCR login and pull."
else
  # Compatibility for manual standalone releases without runner-side delivery.
  login_attempt=1
  while ! printf '%s' "$GHCR_TOKEN" | $SUDO docker login ghcr.io -u "$GHCR_USER" --password-stdin >/dev/null; do
    if [ "$login_attempt" -ge 4 ]; then
      echo "GHCR login failed after four bounded attempts. Deployment stopped before container changes." >&2
      exit 1
    fi
    echo "GHCR login failed (attempt $login_attempt/4); retrying after transient network error." >&2
    sleep "$((login_attempt * 5))"
    login_attempt=$((login_attempt + 1))
  done
fi

if [ "$ROUTING" = true ]; then
  $SUDO cp infra/nginx/irlix-services.conf /etc/nginx/sites-available/irlix-services
  $SUDO ln -sfn /etc/nginx/sites-available/irlix-services /etc/nginx/sites-enabled/irlix-services
  $SUDO rm -f /etc/nginx/sites-enabled/default
  $SUDO nginx -t
  $SUDO systemctl reload nginx
fi

# A cumulative release can exhaust the Docker filesystem before containers
# are recreated. Reclaim ONLY unused images / build cache when Docker free space
# is low. Never prune containers, networks, volumes or persistent business data;
# in-use images (including those referenced by stopped containers) are retained.
# This check is skipped on normal, sufficiently provisioned deploys.
if [ -n "$DEPLOY_SERVICES" ] && [ "${IRLIX_IMAGES_PRELOADED:-}" != "1" ]; then
  docker_root=$($SUDO docker info --format '{{.DockerRootDir}}' 2>/dev/null || printf '/var/lib/docker')
  docker_free_kb=$(df -Pk "$docker_root" 2>/dev/null | awk 'NR==2 {print $4}')
  if [ -n "$docker_free_kb" ] && [ "$docker_free_kb" -lt 8388608 ]; then
    echo "Docker image storage low: ${docker_free_kb} KiB available. Removing only unreferenced images/build cache before release pull."
    $SUDO docker image prune --all --force
    $SUDO docker builder prune --all --force
    docker_free_after=$(df -Pk "$docker_root" 2>/dev/null | awk 'NR==2 {print $4}')
    echo "Docker image storage after safe cleanup: ${docker_free_after:-unknown} KiB available."
  fi
  # GHCR occasionally becomes temporarily unresolvable via the production
  # DNS resolver. Retry the read-only image download only; do not attempt
  # migrations, startup, or authorization cutover after a failed final pull.
  pull_attempt=1
  while ! $SUDO sh -c "$COMPOSE pull $DEPLOY_SERVICES"; do
    if [ "$pull_attempt" -ge 4 ]; then
      echo "GHCR pull failed after four bounded attempts. Deployment stopped before container changes." >&2
      exit 1
    fi
    echo "GHCR pull failed (attempt $pull_attempt/4); retrying after transient network error." >&2
    sleep "$((pull_attempt * 5))"
    pull_attempt=$((pull_attempt + 1))
  done
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

# The host RAM sampler is installed only on Resource Monitor releases.
# It runs outside container cgroups and writes numeric data to the existing volume.
resource_monitor_requested="$FULL"
for service in $DEPLOY_SERVICES; do
  [ "$service" = resource-monitor ] && resource_monitor_requested=true
done
if [ "$resource_monitor_requested" = true ]; then
  $SUDO sh scripts/install-resource-host-memory.sh
fi

if [ "$migration_runtime_present" = true ] && [ "$migration_key_sync_required" = true ]; then
  echo "Migration runtime key drift detected; recreating migration API and worker with the persistent server key..."
  if ! $SUDO sh -c "$COMPOSE up -d --no-build --force-recreate migration migration-worker"; then
    migration_runtime_diagnostics
    exit 1
  fi
fi

# The outbox worker can be stopped independently of an unchanged Employees image.
# Bring it up with the current release image before checking the stand.
events_container=$($SUDO sh -c "$COMPOSE ps -q employees-events" || true)
events_running=false
if [ -n "$events_container" ]; then
  events_running=$($SUDO docker inspect -f '{{.State.Running}}' "$events_container" || true)
fi
if [ "$events_running" != true ]; then
  echo "Employees outbox publisher is stopped; restoring its existing service."
  $SUDO sh -c "$COMPOSE up -d --no-build employees-events"
fi

for service in $MIGRATE_SERVICES; do
  $SUDO sh -c "$COMPOSE exec -T $service php artisan migrate --force < /dev/null"
done

# Financial records must not become available through the portal before the
# backend can read its migrated, isolated PostgreSQL schema. Check the actual
# Finance API rather than trusting a running container or static 200 status.
finance_deploy_requested="$FULL"
for service in $DEPLOY_SERVICES; do
  [ "$service" = finance ] && finance_deploy_requested=true
done
if [ "$finance_deploy_requested" = true ]; then
  echo "Checking Finance database readiness..."
  finance_ready=false
  i=0
  while [ "$i" -lt 20 ]; do
    if curl -fsS --connect-timeout 1 --max-time 2 http://127.0.0.1:8104/api/health 2>/dev/null | grep -q '"status":"ok"'; then
      finance_ready=true
      break
    fi
    i=$((i + 1))
    sleep 1
  done
  if [ "$finance_ready" != true ]; then
    $SUDO sh -c "$COMPOSE ps finance finance-db-init" || true
    $SUDO sh -c "$COMPOSE logs --tail=120 finance finance-db-init" || true
    echo "Finance failed the database-readiness gate; deployment is not successful" >&2
    exit 1
  fi
  echo "Finance database and API are ready."
  # Confirm the deployed Finance service can actually read its three real
  # source domains before the release is marked successful. No data is written
  # and no confidential source response is logged by the smoke script.
  if ! $SUDO sh -c "$COMPOSE exec -T finance php tests/finance_integrations_smoke.php < /dev/null"; then
    $SUDO sh -c "$COMPOSE logs --tail=120 finance" || true
    echo "Finance Clients/Timesheets/Employees integration checks failed" >&2
    exit 1
  fi
  echo "Finance source integrations ready."
fi

# Verify runtime keys, credentials and cross-service document contracts after the selected
# database upgrades. A new Migration image may rely on columns added by Vacations in this release.
# Keep this gate limited to Migration releases/key drift, never unrelated selective deployments.
if [ "$migration_deploy_requested" = true ] || [ "$migration_key_sync_required" = true ]; then
  sh scripts/verify-migration.sh
fi

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

  # CV provider selection is stored in the persistent cv_settings volume.
  # Reading only .env can misidentify a live external GigaChat selection as
  # local, incorrectly turning an unrelated DNS outage into a failed rollout.
  # Resolve the EFFECTIVE provider through the same settings loader as the API.
  active_cv_provider=$($SUDO sh -c "$COMPOSE exec -T cv-converter python -c 'from app.settings import load_settings; print(load_settings()[\"provider\"])' < /dev/null")
  case "$active_cv_provider" in
    local)
    echo "Running CV real-inference smoke..."
    $SUDO sh -c "timeout 180 $COMPOSE exec -T -e CV_LLM_MAX_OUTPUT_TOKENS=900 -e CV_LLM_TIMEOUT_SECONDS=120 cv-converter python -m app.smoke" || {
      $SUDO sh -c "$COMPOSE ps cv-llm cv-converter" || true
      $SUDO sh -c "$COMPOSE logs --tail=200 cv-llm cv-converter" || true
      echo "CV real-inference smoke failed or timed out" >&2
      exit 1
    }
    ;;
    gigachat|yandex|mws|openai_compatible)
      echo "CV active provider is external ($active_cv_provider); local LLM readiness passed, but external real-inference smoke was NOT TESTED (not part of deploy gate)."
      ;;
    *)
      echo "CV active provider is unknown ($active_cv_provider); refusing to mark release verified." >&2
      exit 1
      ;;
  esac
fi
