#!/usr/bin/env bash
# Copies committed immutable objects to an independently configured cloud S3 remote.
# This is NOT a restorable platform backup: PostgreSQL metadata and restore manifest
# are deliberately out of scope until the consistent backup coordinator is built.
set -euo pipefail
umask 077
: "${STORAGE_COPY_SOURCE:?Set rclone source remote, e.g. local:objects}"
: "${STORAGE_COPY_DEST:?Set rclone target remote, e.g. cloud:archive/objects}"
if [ "$STORAGE_COPY_SOURCE" = "$STORAGE_COPY_DEST" ]; then
  echo "ERROR: source and destination must differ" >&2; exit 2
fi
case "$STORAGE_COPY_SOURCE" in
  *:*) ;;
  *) echo "ERROR: source must be an rclone remote" >&2; exit 2 ;;
esac
case "$STORAGE_COPY_DEST" in
  *:*) ;;
  *) echo "ERROR: destination must be an rclone remote" >&2; exit 2 ;;
esac
command -v rclone >/dev/null || { echo "ERROR: rclone is missing" >&2; exit 2; }
# --immutable rejects same-key different-content overwrites, protecting prior archives.
# Intentionally NEVER use sync or delete on destination.
rclone copy "$STORAGE_COPY_SOURCE" "$STORAGE_COPY_DEST" --immutable --transfers 2 --checkers 4
# Download-based verification handles differing S3 ETag/hash implementations.
rclone check "$STORAGE_COPY_SOURCE" "$STORAGE_COPY_DEST" --one-way --download
echo "Object copy verified. WARNING: this is not a consistent metadata+object backup."
