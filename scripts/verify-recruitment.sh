#!/bin/sh
set -eu
cd /opt/irlix-services
if [ "$(id -u)" -eq 0 ]; then SUDO=""; else SUDO="sudo -n"; fi
COMPOSE="$(python3 scripts/ci/service_plan.py compose-args --env-file .env)"
PUBLIC_URL=$(sed -n 's/^IRLIX_PUBLIC_URL=//p' .env | tail -n1)
HOST_HEADER=${PUBLIC_URL#*://}; HOST_HEADER=${HOST_HEADER%%/*}
for service in recruitment recruitment-web; do
  container=$($SUDO sh -c "$COMPOSE ps -q $service")
  test -n "$container"
  test "$($SUDO docker inspect -f '{{.State.Running}}' "$container")" = true
done
curl -H "Host: $HOST_HEADER" -fsS --max-time 10 --retry 5 --retry-all-errors --retry-delay 1 http://127.0.0.1/recruitment/ | grep -q 'IRLIX Recruitment'
curl -H "Host: $HOST_HEADER" -fsS --max-time 10 --retry 5 --retry-all-errors --retry-delay 1 http://127.0.0.1/api/recruitment/health | grep -q '"service":"recruitment"'
$SUDO sh -c "$COMPOSE exec -T recruitment php artisan migrate:status --no-ansi" | grep -q '2026_09_27_000001_create_recruitment_domain'
echo 'Recruitment verification passed.'
