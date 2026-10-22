#!/bin/sh
# Host-only reconciliation: a local CV model consumes RAM only while selected.
# This script runs as a systemd oneshot on the host; never expose Docker control
# or its socket to the CV web/API container.
set -eu
[ "$(id -u)" -eq 0 ] || { echo "root required" >&2; exit 1; }
cd "${IRLIX_SERVICES_ROOT:-/opt/irlix-services}"
test -f .env

# Use the same compose project definition as normal production deployment.
COMPOSE="$(python3 scripts/ci/service_plan.py compose-args --env-file .env)"

# If the API is absent / currently replacing, don't guess the provider or
# mutate the LLM. The next timer tick will reconcile after the API is ready.
api_running=$(docker ps -q --filter label=com.docker.compose.project=irlix-services --filter label=com.docker.compose.service=cv-converter)
if [ -z "$api_running" ]; then
  echo "CV API is not running; deferring local LLM reconciliation."
  exit 0
fi

# Effective provider is defined by the persisted cv_settings volume, not .env.
# It is deliberately read from the same settings loader used by the CV API.
# If settings cannot be read, fail closed without changing the model.
provider=$(sh -c "$COMPOSE exec -T cv-converter python -c 'from app.settings import load_settings; print(load_settings()[\"provider\"])' < /dev/null")
case "$provider" in
  local|gigachat|yandex|mws|openai_compatible) ;;
  *) echo "Unsupported CV provider; leaving local LLM unchanged." >&2; exit 1 ;;
esac

llm_running=$(docker ps -q --filter label=com.docker.compose.project=irlix-services --filter label=com.docker.compose.service=cv-llm)
case "$provider" in
  local)
    if [ -z "$llm_running" ]; then
      echo "CV provider is local; starting local LLM from existing Compose configuration."
      sh -c "$COMPOSE up -d --no-build --no-deps cv-llm"
    fi
    ;;
  *)
    if [ -n "$llm_running" ]; then
      echo "CV provider is $provider; stopping unused local LLM to release RAM."
      sh -c "$COMPOSE stop -t 30 cv-llm"
    fi
    ;;
esac
