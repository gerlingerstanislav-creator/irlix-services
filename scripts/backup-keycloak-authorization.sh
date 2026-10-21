#!/bin/sh
# A required, server-local rollback baseline for the Employees/Keycloak
# authorization cutover. No identity data is uploaded to GitHub or printed.
set -eu
if [ "$(id -u)" -ne 0 ]; then exec sudo -n sh "$0" "$@"; fi
cd /opt/irlix-services
umask 077
COMPOSE="$(python3 scripts/ci/service_plan.py compose-args --env-file .env)"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
dir="/opt/irlix-authz-backups/${stamp}-$$"
mkdir -p "$dir"
chmod 700 "$dir"
trap 'rm -f "$dir/employees.dump.tmp" "$dir/keycloak-roles.json.tmp"' EXIT

# pg_dump custom format is a consistent live PostgreSQL backup of the
# Employees schema. The Keycloak instance uses a separate persistent volume:
# take a restorable snapshot of the actual direct realm role assignments via
# read-only authenticated Admin API, without touching Keycloak storage.
sh -c "$COMPOSE exec -T postgres sh -c 'PGPASSWORD=\"\$POSTGRES_PASSWORD\" pg_dump -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -n employees -Fc' > '$dir/employees.dump.tmp'"
if [ ! -s "$dir/employees.dump.tmp" ]; then
  echo "Authorization backup: empty Employees pg_dump; aborting rollout" >&2
  exit 1
fi
mv "$dir/employees.dump.tmp" "$dir/employees.dump"

sh -c "$COMPOSE exec -T employees php bin/authz-export.php < /dev/null > '$dir/keycloak-roles.json.tmp'"
python3 - "$dir/keycloak-roles.json.tmp" <<'PY'
import json
import sys
with open(sys.argv[1], encoding='utf-8') as source:
    snapshot = json.load(source)
assert snapshot.get('format') == 'irlix-keycloak-direct-role-backup-v1'
assert snapshot.get('linked_users', 0) > 0
assert snapshot['linked_users'] == len(snapshot.get('entries', []))
PY
mv "$dir/keycloak-roles.json.tmp" "$dir/keycloak-roles.json"
chmod 600 "$dir/employees.dump" "$dir/keycloak-roles.json"
echo "Private Employees and Keycloak role rollback baseline verified and saved on production host."
