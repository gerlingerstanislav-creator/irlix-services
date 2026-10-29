#!/bin/sh
# Private pre-migration backup of affected service-owned authorization tables.
# Never send data to GitHub Actions/artifacts; no credential values in logs.
set -eu
if [ "$(id -u)" -ne 0 ]; then exec sudo -n sh "$0" "$@"; fi
cd /opt/irlix-services
umask 077

case "${1:-}" in
    finance|equipment|timesheets|vacations|recruitment|clients|specialists) schema="$1" ;;
    *) echo "Unsupported local AuthZ backup service" >&2; exit 2 ;;
esac
COMPOSE="$(python3 scripts/ci/service_plan.py compose-args --env-file .env)"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
destination="/opt/irlix-authz-backups/local-v2-${schema}-${stamp}-$$"
mkdir -p "$destination"
chmod 700 "$destination"
trap 'rm -f "$destination/schema.dump.tmp"' EXIT

# Full service schema snapshot includes legacy matrix and its audit,
# business records remain encrypted/protected by host filesystem controls.
sh -c "$COMPOSE exec -T postgres sh -c 'PGPASSWORD=\"\$POSTGRES_PASSWORD\" pg_dump -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -n \"$schema\" -Fc' > '$destination/schema.dump.tmp'"
test -s "$destination/schema.dump.tmp" || {
    echo "Authorization backup failed; refusing to migrate policy" >&2
    exit 1
}
mv "$destination/schema.dump.tmp" "$destination/schema.dump"
chmod 600 "$destination/schema.dump"
echo "Protected pre-migration $schema schema snapshot verified (server-only)."
