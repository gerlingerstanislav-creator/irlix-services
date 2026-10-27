#!/usr/bin/env bash
# Read-only safety check. Must be run on the intended dedicated storage host.
set -euo pipefail
: "${STORAGE_S3_DATA_DIR:?Set STORAGE_S3_DATA_DIR}"
: "${STORAGE_S3_IMAGE:?Set STORAGE_S3_IMAGE}"
: "${STORAGE_S3_ACCESS_KEY:?Set STORAGE_S3_ACCESS_KEY}"
: "${STORAGE_S3_SECRET_KEY:?Set STORAGE_S3_SECRET_KEY}"
: "${STORAGE_ADMIN_PASSWORD:?Set STORAGE_ADMIN_PASSWORD}"

case "$STORAGE_S3_DATA_DIR" in
  /*) ;;
  *) echo "ERROR: storage path must be absolute" >&2; exit 2 ;;
esac
if [ "$STORAGE_S3_DATA_DIR" = "/" ] || [ "$STORAGE_S3_DATA_DIR" = "/var" ] ||
   [ "$STORAGE_S3_DATA_DIR" = "/opt" ]; then
  echo "ERROR: storage path is unsafe" >&2; exit 2
fi
if [ ! -d "$STORAGE_S3_DATA_DIR" ] || [ ! -w "$STORAGE_S3_DATA_DIR" ]; then
  echo "ERROR: dedicated storage directory missing or not writable" >&2; exit 2
fi
if ! mountpoint -q "$STORAGE_S3_DATA_DIR"; then
  echo "ERROR: storage directory must be an actual mount point" >&2
  echo "Refusing to use the host root filesystem" >&2
  exit 2
fi
case "$STORAGE_S3_IMAGE" in
  *@sha256:*) ;;
  *) echo "ERROR: pin STORAGE_S3_IMAGE by immutable sha256 digest" >&2; exit 2 ;;
esac
for value in "$STORAGE_S3_ACCESS_KEY" "$STORAGE_S3_SECRET_KEY" "$STORAGE_ADMIN_PASSWORD"; do
  case "$value" in
    ""|REPLACE*|changeme|password|secret|admin) echo "ERROR: placeholder or unsafe secret" >&2; exit 2 ;;
  esac
done
# Require 10 GiB free for first pilot; actual production threshold is capacity-planned separately.
free_kib=$(df -Pk "$STORAGE_S3_DATA_DIR" | awk 'NR==2 {print $4}')
if [ -z "$free_kib" ] || [ "$free_kib" -lt 10485760 ]; then
  echo "ERROR: pilot volume needs at least 10 GiB free before provisioning" >&2
  exit 2
fi
echo "PASS: dedicated mount, image pin, supplied secrets and minimum capacity checks"
echo "NOTE: this does not validate restore, durability, encryption or security."
