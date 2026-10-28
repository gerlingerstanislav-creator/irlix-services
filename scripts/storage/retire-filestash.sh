#!/bin/sh
# Remove the retired Filestash runtime/config only, after new Drive is healthy.
# S3 objects, business volumes, and other Docker services must never be touched.
set -eu
# A newly recreated Docker container can briefly reset connections while nginx
# and uvicorn are starting. Probe both origins with a bounded readiness window.
# Never retire legacy state on timeout, wrong SPA content or unhealthy API.
storage_ready=false
attempt=1
while [ "$attempt" -le 30 ]; do
  drive=$(curl -fsS --connect-timeout 2 --max-time 4 http://127.0.0.1:8115/ 2>/dev/null || true)
  code=$(curl -sS -o /dev/null -w '%{http_code}' --connect-timeout 2 --max-time 4 http://127.0.0.1:8114/health 2>/dev/null || true)
  if printf '%s' "$drive" | grep -Fq '<title>IRLIX · Хранилище</title>' && [ "$code" = 200 ]; then
    storage_ready=true
    break
  fi
  attempt=$((attempt + 1))
  sleep 1
done
if [ "$storage_ready" != true ]; then
  echo "Storage Drive/API not ready after 30 startup checks; preserving existing data and retired-state cleanup." >&2
  exit 1
fi
echo "Storage Drive and API readiness confirmed."

# Filter by both immutable Compose project and exact retired service label.
old=$(docker ps -aq \
  --filter label=com.docker.compose.project=irlix-services \
  --filter label=com.docker.compose.service=storage-web)
set -- $old
[ "$#" -le 1 ] || { echo "More than one Filestash runtime; refusing cleanup." >&2; exit 1; }
if [ "$#" -eq 1 ]; then
  id="$1"
  # Never force-remove another component because of a stale label.
  service=$(docker inspect -f '{{index .Config.Labels "com.docker.compose.service"}}' "$id")
  [ "$service" = storage-web ] || exit 1
  docker stop "$id" >/dev/null
  docker rm "$id" >/dev/null
fi
# This dedicated volume contains only Filestash settings, not the S3 objects.
# Check both Docker labels to avoid deleting an unrelated volume with same name.
volume=irlix-services_storage_filestash_state
if docker volume inspect "$volume" >/dev/null 2>&1; then
  project=$(docker volume inspect -f '{{index .Labels "com.docker.compose.project"}}' "$volume")
  label=$(docker volume inspect -f '{{index .Labels "com.docker.compose.volume"}}' "$volume")
  [ "$project" = irlix-services ] && [ "$label" = storage_filestash_state ] || {
    echo "Unexpected volume labels; preserving Filestash state for investigation." >&2
    exit 1
  }
  docker volume rm "$volume" >/dev/null
fi
# Only the retired, uniquely named image may be removed. Never prune generic images.
image=ghcr.io/gerlingerstanislav-creator/irlix-storage-web
docker image ls --format '{{.Repository}}:{{.Tag}}' | grep -F "$image:" | while IFS= read -r tagged; do
  case "$tagged" in
    "$image":*) docker image rm "$tagged" >/dev/null 2>&1 || true ;;
  esac
done
echo "Retired Filestash runtime and dedicated state; S3 object storage unchanged."
