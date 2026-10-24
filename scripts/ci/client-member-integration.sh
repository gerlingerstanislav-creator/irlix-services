#!/usr/bin/env bash
# Disposable CI only. No deployment, external hosts or production secrets.
set -euo pipefail
task_root=$(cd "$(dirname "$0")/../.." && pwd)
task_evidence=/tmp/client-member-ci-evidence
mkdir -p "$task_evidence"
chmod 777 "$task_evidence"
export PGPASSWORD=synthetic_ci_password
if ! command -v psql >/dev/null; then
  sudo apt-get update -qq
  sudo apt-get install -y -qq postgresql-client
fi
psql -h 127.0.0.1 -U postgres -d membership_ci -v ON_ERROR_STOP=1 -c 'CREATE SCHEMA clients; CREATE SCHEMA timesheets;'
task_env=(--network host -e APP_ENV=testing -e APP_DEBUG=false -e LOG_CHANNEL=stderr
  -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=5432
  -e DB_DATABASE=membership_ci -e DB_USERNAME=postgres -e DB_PASSWORD=synthetic_ci_password
  -e CACHE_STORE=array -e SESSION_DRIVER=array
  -e IRLIX_TIMESHEETS_INTEGRATION_TOKEN=synthetic-membership-ci-token-0123456789
  -e CLIENTS_INTERNAL_URL=http://127.0.0.1:18081/internal
  -v "$task_root/scripts/ci:/ci:ro" -v "$task_evidence:/evidence")
docker run --rm "${task_env[@]}" -e DB_SEARCH_PATH=clients member-clients-ci php /ci/client-member-pg.php clients | tee "$task_evidence/clients-upgrade.log"
docker run --rm "${task_env[@]}" -e DB_SEARCH_PATH=timesheets member-timesheets-ci php /ci/client-member-pg.php timesheets | tee "$task_evidence/timesheets-upgrade.log"
docker run -d --name membership-clients "${task_env[@]}" -e DB_SEARCH_PATH=clients member-clients-ci php -S 0.0.0.0:18081 -t public public/index.php
docker run -d --name membership-timesheets "${task_env[@]}" -e DB_SEARCH_PATH=timesheets member-timesheets-ci php -S 0.0.0.0:18082 -t public public/index.php
trap 'docker logs membership-clients > "$task_evidence/clients-http.log" 2>&1 || true; docker logs membership-timesheets > "$task_evidence/timesheets-http.log" 2>&1 || true; docker rm -f membership-clients membership-timesheets >/dev/null 2>&1 || true' EXIT
for task_port in 18081 18082; do
  curl --retry 12 --retry-delay 1 --retry-connrefused --fail --silent "http://127.0.0.1:$task_port/up" >/dev/null
done
docker run --rm "${task_env[@]}" -e DB_SEARCH_PATH=timesheets member-timesheets-ci php /ci/client-member-pg.php convert | tee "$task_evidence/conversion.log"
docker run --rm --entrypoint sh "${task_env[@]}" \
  -e MIGRATION_METADATA_DATABASE=/tmp/client-member-metadata.sqlite \
  -e MIGRATION_IDENTITY_DATABASE=/tmp/client-member-identities.sqlite \
  member-migration-ci -ec 'touch /tmp/client-member-metadata.sqlite; php /ci/client-member-pg.php migration' | tee "$task_evidence/migration-pg.log"
# Two actual PostgreSQL sessions compete to insert an overlapping interval.
task_sql="SET search_path=clients; INSERT INTO member_terms(client_member_id,technology,level,hourly_rate,hours_per_day,valid_from,valid_to) SELECT id,'PHP','Senior',100,8,'2030-01-01','2030-01-31' FROM client_members WHERE employee_id=424242;"
psql -h 127.0.0.1 -U postgres -d membership_ci -v ON_ERROR_STOP=1 -c "BEGIN; $task_sql SELECT pg_sleep(2); COMMIT;" > "$task_evidence/concurrency-first.log" 2>&1 &
task_first=$!
# Wait for first session to hold the member row lock, rather than rely on a race.
for task_i in $(seq 1 100); do
  task_busy=$(psql -h 127.0.0.1 -U postgres -d membership_ci -Atc "SELECT count(*) FROM pg_stat_activity WHERE datname='membership_ci' AND wait_event='PgSleep'")
  if [ "$task_busy" = 1 ]; then break; fi
done
if psql -h 127.0.0.1 -U postgres -d membership_ci -v ON_ERROR_STOP=1 -c "$task_sql" > "$task_evidence/concurrency-second.log" 2>&1; then
  echo 'FAIL: concurrent overlapping terms accepted' >&2
  exit 1
fi
wait "$task_first"
grep -Fq 'Overlapping commercial terms' "$task_evidence/concurrency-second.log"
echo 'PASS: PostgreSQL concurrent overlap rejected' | tee "$task_evidence/concurrency.log"
