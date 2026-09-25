#!/bin/sh
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  --set=platform_user="$PLATFORM_CORE_DB_USER" \
  --set=platform_password="$PLATFORM_CORE_DB_PASSWORD" \
  --set=employees_user="$EMPLOYEES_DB_USER" \
  --set=employees_password="$EMPLOYEES_DB_PASSWORD" \
  --set=vacations_user="$VACATIONS_DB_USER" \
  --set=vacations_password="$VACATIONS_DB_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'platform_user', :'platform_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'platform_user') \gexec

SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'employees_user', :'employees_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'employees_user') \gexec

SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'vacations_user', :'vacations_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'vacations_user') \gexec

REVOKE CREATE ON SCHEMA public FROM PUBLIC;

SELECT format('CREATE SCHEMA IF NOT EXISTS platform_core AUTHORIZATION %I', :'platform_user') \gexec
SELECT format('CREATE SCHEMA IF NOT EXISTS employees AUTHORIZATION %I', :'employees_user') \gexec
SELECT format('CREATE SCHEMA IF NOT EXISTS vacations AUTHORIZATION %I', :'vacations_user') \gexec

SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'platform_user') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'employees_user') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'vacations_user') \gexec

SELECT format('ALTER ROLE %I SET search_path TO platform_core', :'platform_user') \gexec
SELECT format('ALTER ROLE %I SET search_path TO employees', :'employees_user') \gexec
SELECT format('ALTER ROLE %I SET search_path TO vacations', :'vacations_user') \gexec

REVOKE ALL ON SCHEMA platform_core FROM PUBLIC;
REVOKE ALL ON SCHEMA employees FROM PUBLIC;
REVOKE ALL ON SCHEMA vacations FROM PUBLIC;

SELECT format('GRANT USAGE, CREATE ON SCHEMA platform_core TO %I', :'platform_user') \gexec
SELECT format('GRANT USAGE, CREATE ON SCHEMA employees TO %I', :'employees_user') \gexec
SELECT format('GRANT USAGE, CREATE ON SCHEMA vacations TO %I', :'vacations_user') \gexec
SQL
