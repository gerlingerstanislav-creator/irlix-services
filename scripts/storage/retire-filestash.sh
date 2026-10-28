#!/bin/sh
# Remove the retired Filestash runtime/config only, after new Drive is healthy.
# S3 objects, business volumes, and other Docker services must never be touched.
set -eu
# Fail closed if the current user-facing frontend/API is not responding.
drive=$(curl -fsS --connect-timeout 2 --max-time 12 http://127.0.0.1:8115/) || {
  echo "Storage Drive origin not ready; retaining legacy Filestash." >&2
  exit 1
}
printf '%s' "$drive" | grep -Fq '<title>IRLIX · Хранилище</title>' || {
  echo "Storage Drive origin unexpected; retaining legacy Filestash." >&2
  exit 1
}
code=$(curl -sS -o /dev/null -w '%{http_code}' --connect-timeout 2 --max-time 12 http://127.0.0.1:8114/health) || exit 1
[ "$code" = 200 ] || {
  echo "Storage API not ready; retaining legacy Filestash." >&2
  exit 1
}
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
