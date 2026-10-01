#!/bin/sh
set -eu

# Migration Service owns a private SQLite metadata database. Keep this separate from Laravel's
# generic DB_DATABASE setting so the API and worker cannot silently fall back to
# /app/database/database.sqlite when runtime environment merging changes.
metadata_db="${MIGRATION_METADATA_DATABASE:-/data/migration.sqlite}"
metadata_dir="$(dirname "$metadata_db")"
mkdir -p "$metadata_dir"
if [ ! -e "$metadata_db" ]; then
  : > "$metadata_db"
fi

# The API container owns schema bootstrap. Worker containers share the same private SQLite volume
# and start only after the API healthcheck succeeds, so they skip migrations to avoid startup races.
if [ "${MIGRATION_SKIP_BOOTSTRAP:-false}" != "true" ]; then
  php artisan migrate --force --no-interaction
  php artisan migrate:status --no-interaction >/dev/null
fi

exec "$@"
