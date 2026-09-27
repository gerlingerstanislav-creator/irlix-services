#!/bin/sh
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  --set=platform_user="$PLATFORM_CORE_DB_USER" \
  --set=platform_password="$PLATFORM_CORE_DB_PASSWORD" \
  --set=employees_user="$EMPLOYEES_DB_USER" \
  --set=employees_password="$EMPLOYEES_DB_PASSWORD" \
  --set=vacations_user="$VACATIONS_DB_USER" \
  --set=vacations_password="$VACATIONS_DB_PASSWORD" \
  --set=clients_user="$CLIENTS_DB_USER" \
  --set=clients_password="$CLIENTS_DB_PASSWORD" \
  --set=timesheets_user="$TIMESHEETS_DB_USER" \
  --set=timesheets_password="$TIMESHEETS_DB_PASSWORD" \
  --set=specialists_user="$SPECIALISTS_DB_USER" \
  --set=specialists_password="$SPECIALISTS_DB_PASSWORD" \
  --set=recruitment_user="$RECRUITMENT_DB_USER" \
  --set=recruitment_password="$RECRUITMENT_DB_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'platform_user', :'platform_password') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'platform_user') \gexec
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'employees_user', :'employees_password') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'employees_user') \gexec
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'vacations_user', :'vacations_password') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'vacations_user') \gexec
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'clients_user', :'clients_password') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'clients_user') \gexec
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'timesheets_user', :'timesheets_password') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'timesheets_user') \gexec
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'specialists_user', :'specialists_password') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'specialists_user') \gexec
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'recruitment_user', :'recruitment_password') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'recruitment_user') \gexec

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
SELECT format('CREATE SCHEMA IF NOT EXISTS platform_core AUTHORIZATION %I', :'platform_user') \gexec
SELECT format('CREATE SCHEMA IF NOT EXISTS employees AUTHORIZATION %I', :'employees_user') \gexec
SELECT format('CREATE SCHEMA IF NOT EXISTS vacations AUTHORIZATION %I', :'vacations_user') \gexec
SELECT format('CREATE SCHEMA IF NOT EXISTS clients AUTHORIZATION %I', :'clients_user') \gexec
SELECT format('CREATE SCHEMA IF NOT EXISTS timesheets AUTHORIZATION %I', :'timesheets_user') \gexec
SELECT format('CREATE SCHEMA IF NOT EXISTS specialists AUTHORIZATION %I', :'specialists_user') \gexec
SELECT format('CREATE SCHEMA IF NOT EXISTS recruitment AUTHORIZATION %I', :'recruitment_user') \gexec

SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'platform_user') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'employees_user') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'vacations_user') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'clients_user') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'timesheets_user') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'specialists_user') \gexec
SELECT format('GRANT CONNECT ON DATABASE %I TO %I', current_database(), :'recruitment_user') \gexec

SELECT format('ALTER ROLE %I SET search_path TO platform_core', :'platform_user') \gexec
SELECT format('ALTER ROLE %I SET search_path TO employees', :'employees_user') \gexec
SELECT format('ALTER ROLE %I SET search_path TO vacations', :'vacations_user') \gexec
SELECT format('ALTER ROLE %I SET search_path TO clients', :'clients_user') \gexec
SELECT format('ALTER ROLE %I SET search_path TO timesheets', :'timesheets_user') \gexec
SELECT format('ALTER ROLE %I SET search_path TO specialists', :'specialists_user') \gexec
SELECT format('ALTER ROLE %I SET search_path TO recruitment', :'recruitment_user') \gexec

REVOKE ALL ON SCHEMA platform_core FROM PUBLIC;
REVOKE ALL ON SCHEMA employees FROM PUBLIC;
REVOKE ALL ON SCHEMA vacations FROM PUBLIC;
REVOKE ALL ON SCHEMA clients FROM PUBLIC;
REVOKE ALL ON SCHEMA timesheets FROM PUBLIC;
REVOKE ALL ON SCHEMA specialists FROM PUBLIC;
REVOKE ALL ON SCHEMA recruitment FROM PUBLIC;
SELECT format('GRANT USAGE, CREATE ON SCHEMA platform_core TO %I', :'platform_user') \gexec
SELECT format('GRANT USAGE, CREATE ON SCHEMA employees TO %I', :'employees_user') \gexec
SELECT format('GRANT USAGE, CREATE ON SCHEMA vacations TO %I', :'vacations_user') \gexec
SELECT format('GRANT USAGE, CREATE ON SCHEMA clients TO %I', :'clients_user') \gexec
SELECT format('GRANT USAGE, CREATE ON SCHEMA timesheets TO %I', :'timesheets_user') \gexec
SELECT format('GRANT USAGE, CREATE ON SCHEMA specialists TO %I', :'specialists_user') \gexec
SELECT format('GRANT USAGE, CREATE ON SCHEMA recruitment TO %I', :'recruitment_user') \gexec
SQL
