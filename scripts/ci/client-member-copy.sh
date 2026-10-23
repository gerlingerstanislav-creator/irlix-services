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
  docker rm -fv copy-clients copy-postgres >/dev/null 2>&1 || true
  docker network rm "$task_network" >/dev/null 2>&1 || true
  rm -rf "$task_private"
}
trap task_cleanup EXIT
# Runtime network has no Internet/production route and exposes no host ports.
docker network create --internal "$task_network" >/dev/null
docker run -d --name copy-postgres --network "$task_network" \
  -e POSTGRES_PASSWORD=synthetic_copy_password postgres:16-alpine >/dev/null
task_pg_ready=0
for task_i in $(seq 1 90); do
  if docker exec copy-postgres pg_isready -h 127.0.0.1 -U postgres >/dev/null 2>&1; then task_pg_ready=1; break; fi
  if [ "$(docker inspect --format '{{.State.Running}}' copy-postgres)" != true ]; then break; fi
  sleep 1
done
if [ "$task_pg_ready" != 1 ]; then
  # Before restore only: this container has never received private data.
  docker inspect --format 'Disposable PostgreSQL bootstrap: status={{.State.Status}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}}' copy-postgres
  docker logs --tail 60 copy-postgres 2>&1
  echo 'BLOCKED: disposable PostgreSQL did not become ready' >&2
  exit 2
fi
docker exec -i -e PGPASSWORD=synthetic_copy_password copy-postgres psql -h 127.0.0.1 -U postgres -v ON_ERROR_STOP=1 >/dev/null <<'SQL'
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
  docker exec -e PGPASSWORD=synthetic_copy_password copy-postgres psql -h 127.0.0.1 -U postgres -d "target_$task_service" -v ON_ERROR_STOP=1 -c "CREATE SCHEMA $task_service;" >/dev/null
done
task_env=(--network "$task_network" --entrypoint php
  -e APP_ENV=testing -e APP_DEBUG=false -e LOG_CHANNEL=null
  -e CLIENT_MEMBER_ISOLATED_COPY=1
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
# Clients must establish canonical mappings before dependent Timesheets preflight.
# Its dry-run is the first write gate. Converter and Timesheets dry-run gates run
# after actual Clients application below, against the correct dependency state.
docker run --rm "${task_env[@]}" "${task_target_env[@]}" -v "$task_private:/private" member-migration-ci /ci/client-member-copy.php dry-run clients
# Back up only the disposable databases; dump contents and diagnostics stay private.
for task_service in clients timesheets; do
  if ! docker exec -e PGPASSWORD=synthetic_copy_password copy-postgres pg_dump -h 127.0.0.1 -U postgres -Fc "target_$task_service" > "$task_private/checkpoint-$task_service.dump" 2> "$task_private/checkpoint-$task_service.log"; then
    echo 'BLOCKED: isolated PostgreSQL checkpoint failed' >&2; exit 2
  fi
done
docker run --rm "${task_env[@]}" "${task_target_env[@]}" -v "$task_private:/private" member-migration-ci /ci/client-member-copy.php checkpoint
task_apply_copy() {
  docker run --rm "${task_env[@]}" "${task_target_env[@]}" -v "$task_private:/private" member-migration-ci /ci/client-member-copy.php apply clients
  # Aggregate-only matching diagnostics; sensitive partner names remain private.
  docker run --rm "${task_env[@]}" "${task_target_env[@]}" -v "$task_private:/private" member-migration-ci /ci/client-member-copy.php partner-candidates
  docker run --rm "${task_env[@]}" -e DB_DATABASE=target_timesheets -e DB_SEARCH_PATH=timesheets member-timesheets-ci /ci/client-member-copy.php convert-apply
  docker run --rm "${task_env[@]}" "${task_target_env[@]}" -v "$task_private:/private" member-migration-ci /ci/client-member-copy.php apply timesheets
}
task_apply_copy
# Prove the actual private checkpoint can restore the same business data, then
# repeat the same real import. The source copies are immutable throughout.
docker stop copy-clients >/dev/null
for task_service in clients timesheets; do
  if ! docker exec -i -e PGPASSWORD=synthetic_copy_password copy-postgres pg_restore -h 127.0.0.1 -U postgres --clean --if-exists --exit-on-error -d "target_$task_service" < "$task_private/checkpoint-$task_service.dump" > "$task_private/restore-$task_service.log" 2>&1; then
    echo 'BLOCKED: isolated PostgreSQL checkpoint restore failed' >&2; exit 2
  fi
done
rm -f "$task_private/metadata.sqlite-wal" "$task_private/metadata.sqlite-shm"
cp "$task_private/checkpoint-metadata.sqlite" "$task_private/metadata.sqlite"
docker run --rm "${task_env[@]}" "${task_target_env[@]}" -v "$task_private:/private" member-migration-ci /ci/client-member-copy.php restored
docker start copy-clients >/dev/null
for task_i in $(seq 1 30); do
  if docker run --rm --network "$task_network" --entrypoint php member-migration-ci -r 'exit(@file_get_contents("http://copy-clients:8000/up")===false?1:0);' >/dev/null 2>&1; then break; fi
  sleep 1
done
task_apply_copy
