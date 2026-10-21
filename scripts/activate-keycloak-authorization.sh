#!/bin/sh
# Run standalone AFTER deploy/migrations and Keycloak bootstrap, BEFORE
# production smoke. Synchronize role grants first, enable JWT-only second.
# Owner requested production activation; never trust successful image builds
# as evidence of real role synchronization.
set -eu
cd /opt/irlix-services
if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
COMPOSE="$(python3 scripts/ci/service_plan.py compose-args --env-file .env)"
upsert_env() {
  key="$1"; value="$2"; file=.env
  if $SUDO grep -q "^${key}=" "$file"; then
    escaped=$(printf '%s' "$value" | sed 's/[&|\\]/\\&/g')
    $SUDO sed -i "s|^${key}=.*|${key}=${escaped}|" "$file"
  else
    printf '%s=%s\n' "$key" "$value" | $SUDO tee -a "$file" >/dev/null
  fi
}

if [ -f infra/authz-rollout.enabled ]; then
  done_before=$($SUDO sh -c "grep '^IRLIX_AUTHZ_ROLLOUT_DONE=' .env 2>/dev/null | tail -n1 | cut -d= -f2-" || true)
  if [ "$done_before" != true ]; then
    echo "Beginning guarded Keycloak authorization rollout..."
    authz_services="platform-core vacations clients timesheets specialists recruitment equipment finance migration cv-converter"
    # Migration cannot be restarted while its destructive jobs are in progress.
    if ! $SUDO sh -c "$COMPOSE exec -T migration php -r 'require \"/app/vendor/autoload.php\"; \$app=require \"/app/bootstrap/app.php\"; \$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); [\$status,\$state]=app(\\App\\Migration\\Core\\MigrationOperationsClient::class)->request(\"GET\",\"/console/state\"); if(\$status!==200 || in_array(\$state[\"operation\"][\"status\"]??\"\",[\"queued\",\"running\"],true)) exit(4);' < /dev/null"; then
      echo "Cannot prove Migration has no active operation. Aborting authz rollout without restarting services." >&2
      exit 1
    fi

    # Sync is opt-in so an image deploy cannot silently replace realm roles.
    upsert_env IRLIX_AUTH_SYNC_WRITE_ENABLED true
    $SUDO sh -c "$COMPOSE up -d --no-build --force-recreate employees-authorization-sync"
    authz_ready=false
    tries=0
    while [ "$tries" -lt 125 ]; do
      summary=$($SUDO sh -c "$COMPOSE exec -T employees php bin/authz-status.php < /dev/null" 2>/dev/null || true)
      if printf '%s' "$summary" | grep -q '"ready":true'; then authz_ready=true; break; fi
      tries=$((tries+1))
      sleep 3
    done
    if [ "$authz_ready" != true ]; then
      echo "Keycloak roles did not synchronize for all linked Employees accounts. Totals only: $summary" >&2
      upsert_env IRLIX_AUTH_SYNC_WRITE_ENABLED false
      $SUDO sh -c "$COMPOSE up -d --no-build --force-recreate employees-authorization-sync" || true
      echo "JWT-only authorization remains disabled; cannot claim production cutover." >&2
      exit 1
    fi

    # Confirm every protected service has the new image before switching.
    for service in $authz_services; do
      cid=$($SUDO sh -c "$COMPOSE ps -q $service" || true)
      if [ -z "$cid" ]; then
        upsert_env IRLIX_AUTH_SYNC_WRITE_ENABLED false
        echo "Required service $service absent; authorization cutover aborted" >&2
        exit 1
      fi
    done

    upsert_env IRLIX_AUTHZ_JWT_ENABLED true
    if ! $SUDO sh -c "$COMPOSE up -d --no-build --force-recreate $authz_services"; then
      upsert_env IRLIX_AUTHZ_JWT_ENABLED false
      $SUDO sh -c "$COMPOSE up -d --no-build --force-recreate $authz_services" || true
      echo "JWT-only backend recreation failed; reverted to Employees legacy gate." >&2
      exit 1
    fi

    activated=true
    for service in $authz_services; do
      cid=$($SUDO sh -c "$COMPOSE ps -q $service" || true)
      if [ -z "$cid" ] || ! $SUDO docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$cid" | grep -qx 'IRLIX_AUTHZ_JWT_ENABLED=true'; then
        echo "$service did not activate JWT-only authorization" >&2
        activated=false
      fi
    done
    if [ "$activated" != true ]; then
      upsert_env IRLIX_AUTHZ_JWT_ENABLED false
      $SUDO sh -c "$COMPOSE up -d --no-build --force-recreate $authz_services" || true
      echo "JWT flag parity verification failed, reverted to legacy authorization." >&2
      exit 1
    fi
    upsert_env IRLIX_AUTHZ_ROLLOUT_SHA "${IRLIX_RELEASE_SHA:-unknown}"
    upsert_env IRLIX_AUTHZ_ROLLOUT_DONE true
    echo "Keycloak authorization rollout active: all protected service backends have JWT gate and synced role grants. Await production smoke."
  fi
fi
