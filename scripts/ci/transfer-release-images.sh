#!/usr/bin/env bash
# Deliver exactly the CI-verified GHCR images to the server without requiring
# production Docker to resolve ghcr.io. No intermediate tar files or secrets.
set -euo pipefail

: "${DEPLOY_HOST:?required}"
: "${DEPLOY_PORT:?required}"
: "${DEPLOY_USER:?required}"
: "${GHCR_USER:?required}"
: "${GHCR_TOKEN:?required}"

image_refs="$(python3 scripts/ci/service_plan.py image-refs)"
if [ -z "$image_refs" ]; then
  echo "No changed GHCR image references to transfer."
  exit 0
fi
mapfile -t images <<< "$image_refs"
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

# Retain exact verified source tags. Never transfer images already available on
# the server. A small multi-image docker save archive deduplicates shared layers
# (particularly the many Vue frontends), and needs only one SSH load per batch.
# Keep batches bounded so the GitHub runner does not exhaust its local disk.
batch_size="${IRLIX_TRANSFER_BATCH_SIZE:-3}"
case "$batch_size" in 1|2|3|4) ;; *) echo "Invalid image transfer batch size (1..4)" >&2; exit 1 ;; esac
started_at="$(date +%s)"
already=0
transferred=0
batches=0
declare -a pending=()
for image in "${images[@]}"; do
  if ssh "${ssh_args[@]}" "$remote" "if [ \"\\$(id -u)\" -eq 0 ]; then docker image inspect '$image' >/dev/null 2>&1; else sudo -n docker image inspect '$image' >/dev/null 2>&1; fi"; then
    echo "Already present on server: $image"
    already=$((already + 1))
  else
    pending+=("$image")
  fi
done

if (( ${#pending[@]} == 0 )); then
  echo "All ${#images[@]} verified images are already on the server."
  if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    printf '### Production image delivery\n- Selected: %s\n- Already on server: %s\n- Transferred: 0\n' "${#images[@]}" "$already" >> "$GITHUB_STEP_SUMMARY"
  fi
  exit 0
fi

# Only authenticate and pull on the GitHub runner, never on production.
printf '%s' "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_USER" --password-stdin >/dev/null
declare -a batch=()
flush_batch() {
  if (( ${#batch[@]} == 0 )); then return 0; fi
  batches=$((batches + 1))
  echo "Loading verified image batch $batches (${#batch[@]} tags, shared layers deduplicated)"
  # pipefail ensures a failed save/compression/SSH/load cannot pass as deployed.
  docker save "${batch[@]}" | gzip -1 | ssh "${ssh_args[@]}" "$remote" 'set -e; if [ "$(id -u)" -eq 0 ]; then gzip -dc | docker load >/dev/null; else gzip -dc | sudo -n docker load >/dev/null; fi'
  for image in "${batch[@]}"; do
    ssh "${ssh_args[@]}" "$remote" "if [ \"\\$(id -u)\" -eq 0 ]; then docker image inspect '$image' >/dev/null; else sudo -n docker image inspect '$image' >/dev/null; fi"
    echo "Transferred and verified: $image"
    docker image rm "$image" >/dev/null 2>&1 || true
    transferred=$((transferred + 1))
  done
  batch=()
}

for image in "${pending[@]}"; do
  echo "Pulling verified image for batch: $image"
  docker pull "$image"
  batch+=("$image")
  if (( ${#batch[@]} >= batch_size )); then flush_batch; fi
done
flush_batch

elapsed=$(( $(date +%s) - started_at ))
echo "Image delivery: selected=${#images[@]}, reused=$already, transferred=$transferred, batches=$batches, elapsed=${elapsed}s"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  printf '### Production image delivery\n- Selected: %s\n- Already on server: %s\n- Transferred: %s\n- SSH batches: %s\n- Elapsed: %ss\n' \
    "${#images[@]}" "$already" "$transferred" "$batches" "$elapsed" >> "$GITHUB_STEP_SUMMARY"
fi
