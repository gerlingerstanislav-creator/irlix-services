#!/bin/sh
set -eu

# Migration Service owns a private SQLite metadata database. Laravel's SQLite connector requires
# the file to exist before the service provider can apply PRAGMA settings, so initialize the file
# before the first artisan command. This file is unrelated to any legacy/source database.
if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
  db_path="${DB_DATABASE:-/data/migration.sqlite}"
  db_dir="$(dirname "$db_path")"
  mkdir -p "$db_dir"
  if [ ! -e "$db_path" ]; then
    : > "$db_path"
  fi
fi

# The API container owns schema bootstrap. Worker containers share the same private SQLite volume
# and start only after the API healthcheck succeeds, so they skip migrations to avoid startup races.
if [ "${MIGRATION_SKIP_BOOTSTRAP:-false}" != "true" ]; then
  php artisan migrate --force --no-interaction
fi

exec "$@"
