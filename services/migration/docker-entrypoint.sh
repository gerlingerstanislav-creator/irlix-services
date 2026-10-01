#!/bin/sh
set -eu

mkdir -p /data

# The API container owns schema bootstrap. Worker containers share the same private SQLite volume
# and start only after the API healthcheck succeeds, so they skip migrations to avoid startup races.
if [ "${MIGRATION_SKIP_BOOTSTRAP:-false}" != "true" ]; then
  php artisan migrate --force --no-interaction
fi

exec "$@"
