#!/bin/sh
set -eu

# Only the migration service's private SQLite metadata DB is migrated here.
php artisan migrate --force --no-interaction
exec "$@"
