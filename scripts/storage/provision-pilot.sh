#!/bin/sh
# Production pilot only. Secrets are written exclusively to the root-only server .env.
# No existing business volume is modified. Refuse new provisioning with low disk.
set -eu
cd /opt/irlix-services
umask 077
FILE=.env
[ -f "$FILE" ] || { echo "Storage pilot: server .env missing" >&2; exit 1; }
get() { sed -n "s/^$1=//p" "$FILE" | tail -1; }
put() {
  key="$1"; value="$2"
  if grep -q "^$key=" "$FILE"; then
    sed -i "s|^$key=.*|$key=$value|" "$FILE"
  else
    printf '%s=%s\n' "$key" "$value" >> "$FILE"
  fi
}
if ! grep -q '^STORAGE_S3_ACCESS_KEY=' "$FILE" ||
   [ "$(get STORAGE_S3_ACCESS_KEY)" = "PROVISION_ON_SERVER" ]; then
  put STORAGE_S3_ACCESS_KEY "irlix$(openssl rand -hex 12)"
fi
for key in STORAGE_S3_SECRET_KEY STORAGE_S3_ADMIN_PASSWORD; do
  existing="$(get "$key")"
  if [ -z "$existing" ] || [ "$existing" = "PROVISION_ON_SERVER" ]; then
    put "$key" "$(openssl rand -hex 32)"
  fi
done
if ! grep -q '^STORAGE_S3_BUCKET=' "$FILE"; then put STORAGE_S3_BUCKET irlix-storage-pilot; fi
path="$(get STORAGE_S3_DATA_DIR)"
if [ -z "$path" ] || [ "$path" = "PROVISION_ON_SERVER" ]; then
  path=/srv/irlix-storage-pilot
  put STORAGE_S3_DATA_DIR "$path"
fi
case "$path" in
  /srv/irlix-storage-pilot|/mnt/irlix-storage-pilot|/mnt/storage-data|/srv/storage-data) ;;
  *) echo "Storage pilot: unexpected data path; refusing unsafe mount $path" >&2; exit 1 ;;
esac
# Check the parent filesystem BEFORE mkdir. This is an intentionally small,
# synthetic-only pilot. Data on the current root drive is NOT business storage.
parent=$(dirname "$path")
mkdir -p "$parent"
free=$(df -Pk "$parent" | awk 'NR==2{print $4}')
if [ -z "$free" ] || [ "$free" -lt 8388608 ]; then
  echo "Storage pilot: needs >=8 GiB free BEFORE initial activation; current KiB: ${free:-unknown}" >&2
  exit 1
fi
mkdir -p "$path"
chmod 0700 "$path"
chmod 0600 "$FILE"
echo "Storage pilot secrets and preflight provisioned (no secret values printed)."
