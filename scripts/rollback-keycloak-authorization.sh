#!/bin/sh
set -eu
cd /opt/irlix-services
if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
COMPOSE="$(python3 scripts/ci/service_plan.py compose-args --env-file .env)"
set_env() {
  key="$1"; value="$2"
  if $SUDO grep -q "^${key}=" .env; then
    $SUDO sed -i "s|^${key}=.*|${key}=${value}|" .env
  else
    printf '%s=%s\n' "$key" "$value" | $SUDO tee -a .env >/dev/null
  fi
}
actual=$($SUDO sh -c "grep '^IRLIX_AUTHZ_ROLLOUT_SHA=' .env 2>/dev/null | cut -d= -f2-" || true)
done_before=$($SUDO sh -c "grep '^IRLIX_AUTHZ_ROLLOUT_DONE=' .env 2>/dev/null | cut -d= -f2-" || true)
if [ "$done_before" = true ] && [ -n "$actual" ] && [ "$actual" = "${IRLIX_RELEASE_SHA:-}" ]; then
  echo "Production authorization acceptance failed; restoring legacy Employees admission."
  set_env IRLIX_AUTHZ_JWT_ENABLED false
  set_env IRLIX_AUTH_SYNC_WRITE_ENABLED false
  services="platform-core vacations clients timesheets specialists recruitment equipment finance migration cv-converter"
  $SUDO sh -c "$COMPOSE up -d --no-build --force-recreate $services"
  $SUDO sh -c "$COMPOSE up -d --no-build --force-recreate employees-authorization-sync"
  set_env IRLIX_AUTHZ_ROLLOUT_DONE false
  echo "JWT-only authorization disabled again. Existing realm role mappings remain for investigation."
else
  echo "No current-release JWT authorization rollout to roll back."
fi
