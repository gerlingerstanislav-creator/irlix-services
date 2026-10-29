#!/bin/sh
# Root-only service-owned matrix snapshot before any AuthZ migration.
set -eu
cd /opt/irlix-services
if [ "$(id -u)" -ne 0 ]; then
  exec sudo -n python3 scripts/authz/backup_local_matrices.py "$@"
fi
exec python3 scripts/authz/backup_local_matrices.py "$@"
