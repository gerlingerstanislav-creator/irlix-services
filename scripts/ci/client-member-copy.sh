#!/usr/bin/env bash
# Runs only on an ephemeral runner. No private data/logs become CI artifacts.
set -euo pipefail
set +x
task_root=$(cd "$(dirname "$0")/../.." && pwd)
task_private=${CLIENT_MEMBER_PRIVATE_DIR:?Private snapshot directory required}
test -s "$task_private/source.jsonl"
touch "$task_private/metadata.sqlite"
task_network=client-member-private
task_cleanup() {
  docker rm -f copy-clients copy-postgres >/dev/null 2>&1 || true
  docker network rm "$task_network" >/dev/null 2>&1 || true
  rm -rf "$task_private"
}
trap task_cleanup EXIT
# Runtime network has no Internet/production route and exposes no host ports.
docker network create --internal "$task_network" >/dev/null
docker run -d --name copy-postgres --network "$task_network" \
  -e POSTGRES_PASSWORD=synthetic_copy_password postgres:16-alpine >/dev/null
for task_i in $(seq 1 40); do
  if docker exec copy-postgres pg_isready -U postgres >/dev/null 2>&1; then break; fi
  sleep 1
done
docker exec -i copy-postgres psql -U postgres -v ON_ERROR_STOP=1 >/dev/null <<'SQL'
CREATE ROLE copy_reader LOGIN PASSWORD 'synthetic_copy_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE DATABASE target_clients;
CREATE DATABASE target_timesheets;
CREATE DATABASE target_employees;
CREATE DATABASE legacy_clients;
CREATE DATABASE legacy_timesheets;
REVOKE CREATE ON DATABASE legacy_clients FROM PUBLIC;
REVOKE CREATE ON DATABASE legacy_timesheets FROM PUBLIC;
SQL
for task_service in clients timesheets; do
  docker exec copy-postgres psql -U postgres -d "target_$task_service" -v ON_ERROR_STOP=1 -c "CREATE SCHEMA $task_service;" >/dev/null
done
task_env=(--network "$task_network" --entrypoint php
  -e APP_ENV=testing -e APP_DEBUG=false -e LOG_CHANNEL=null
  -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
  -e MIGRATION_APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
  -e MIGRATION_CREDENTIAL_KEY_FILE=/private/credential.key
  -e MIGRATION_METADATA_DATABASE=/private/metadata.sqlite
  -e MIGRATION_IDENTITY_DATABASE=/private/identities.sqlite
  -e DB_CONNECTION=pgsql -e DB_HOST=copy-postgres -e DB_PORT=5432
  -e DB_USERNAME=postgres -e DB_PASSWORD=synthetic_copy_password
  -e CACHE_STORE=array -e SESSION_DRIVER=array
  -e IRLIX_TIMESHEETS_INTEGRATION_TOKEN=synthetic-membership-copy-token-0123456789
  -e CLIENTS_INTERNAL_URL=http://copy-clients:8000/internal
  -v "$task_root/scripts/ci:/ci:ro")
for task_service in clients timesheets; do
  docker run --rm "${task_env[@]}" -e DB_DATABASE="target_$task_service" -e DB_SEARCH_PATH="$task_service" "member-$task_service-ci" /ci/client-member-copy.php old
done
task_target_env=()
for task_service in EMPLOYEES CLIENTS TIMESHEETS; do
  task_lower=$(printf '%s' "$task_service" | tr '[:upper:]' '[:lower:]')
  task_target_env+=(-e "TARGET_${task_service}_DB_HOST=copy-postgres" -e "TARGET_${task_service}_DB_DATABASE=target_$task_lower" -e "TARGET_${task_service}_DB_USERNAME=postgres" -e "TARGET_${task_service}_DB_PASSWORD=synthetic_copy_password")
done
docker run --rm "${task_env[@]}" "${task_target_env[@]}" -v "$task_private:/private" member-migration-ci /ci/client-member-copy.php restore
rm -f "$task_private/source.jsonl"
for task_service in clients timesheets; do
  docker run --rm "${task_env[@]}" -e DB_DATABASE="target_$task_service" -e DB_SEARCH_PATH="$task_service" "member-$task_service-ci" /ci/client-member-copy.php upgrade
done
docker run -d --name copy-clients "${task_env[@]}" -e DB_DATABASE=target_clients -e DB_SEARCH_PATH=clients member-clients-ci -S 0.0.0.0:8000 -t public public/index.php >/dev/null
for task_i in $(seq 1 30); do
  if docker run --rm --network "$task_network" --entrypoint php member-migration-ci -r 'exit(@file_get_contents("http://copy-clients:8000/up")===false?1:0);' >/dev/null 2>&1; then break; fi
  sleep 1
done
# Preserve both independently useful outcomes, even when a source has conflicts.
task_status=0
docker run --rm "${task_env[@]}" "${task_target_env[@]}" -v "$task_private:/private" member-migration-ci /ci/client-member-copy.php dry-run || task_status=2
docker run --rm "${task_env[@]}" -e DB_DATABASE=target_timesheets -e DB_SEARCH_PATH=timesheets member-timesheets-ci /ci/client-member-copy.php convert || task_status=2
exit "$task_status"
