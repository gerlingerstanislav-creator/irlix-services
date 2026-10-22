#!/usr/bin/env bash
# Deliver exactly the CI-verified GHCR images to the server without requiring
# production Docker to resolve ghcr.io. No intermediate tar files or secrets.
set -euo pipefail

: "${DEPLOY_HOST:?required}"
: "${DEPLOY_PORT:?required}"
: "${DEPLOY_USER:?required}"
: "${GHCR_USER:?required}"
: "${GHCR_TOKEN:?required}"

mapfile -t images < <(python3 scripts/ci/service_plan.py image-refs)
if (( ${#images[@]} == 0 )); then
  echo "No changed GHCR image references to transfer."
  exit 0
fi

ssh_args=(-i "$HOME/.ssh/deploy_key" -p "$DEPLOY_PORT" -o BatchMode=yes -o ConnectTimeout=15)
remote="$DEPLOY_USER@$DEPLOY_HOST"

# Clean only unreferenced Docker images/build cache, at most once, BEFORE
# loading new tagged images. This mirrors the existing low-space release gate.
# Never touch containers, persistent volumes, snapshots or database state.
ssh "${ssh_args[@]}" "$remote" 'sh -s' <<'REMOTE'
set -eu
if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
docker_root=$($SUDO docker info --format '{{.DockerRootDir}}')
free_kb=$(df -Pk "$docker_root" | awk 'NR==2 {print $4}')
test -n "$free_kb"
if [ "$free_kb" -lt 8388608 ]; then
  echo "Low Docker storage: removing unreferenced images and build cache before transfer."
  $SUDO docker image prune --all --force >/dev/null
  $SUDO docker builder prune --all --force >/dev/null
  free_kb=$(df -Pk "$docker_root" | awk 'NR==2 {print $4}')
fi
echo "Docker image storage available before transfer: ${free_kb} KiB."
if [ "$free_kb" -lt 4194304 ]; then
  echo "Insufficient free space to safely stage verified release images." >&2
  exit 1
fi
REMOTE

# Auth happens on GitHub's runner, where GHCR is reachable. Docker images are
# streamed directly into the server daemon (no temporary archive on disk).
# Pre-existing identical tagged images remain untouched.
printf '%s' "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_USER" --password-stdin >/dev/null
for image in "${images[@]}"; do
  # image-refs validates the registry, source-hash tag and current SHA.
  if ssh "${ssh_args[@]}" "$remote" "if [ \"\$(id -u)\" -eq 0 ]; then docker image inspect '$image' >/dev/null 2>&1; else sudo -n docker image inspect '$image' >/dev/null 2>&1; fi"; then
    echo "Already present on server: $image"
    continue
  fi
  echo "Streaming verified image to server: $image"
  docker pull "$image"
  docker save "$image" | gzip -1 | ssh "${ssh_args[@]}" "$remote" 'set -e; if [ "$(id -u)" -eq 0 ]; then gzip -dc | docker load >/dev/null; else gzip -dc | sudo -n docker load >/dev/null; fi'
  ssh "${ssh_args[@]}" "$remote" "if [ \"\$(id -u)\" -eq 0 ]; then docker image inspect '$image' >/dev/null; else sudo -n docker image inspect '$image' >/dev/null; fi"
  docker image rm "$image" >/dev/null 2>&1 || true
  echo "Transferred and verified: $image"
done
