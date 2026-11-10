<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The migration service writes directly to Employees' tables, bypassing the
 * HTTP lifecycle. Profile changes must enqueue the same Keycloak sync worker.
 * Keep the existing authorization trigger and extend its update condition.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION employees_auth_sync_employee_event() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    PERFORM employees_queue_auth_sync(OLD.id);
  ELSIF TG_OP = 'INSERT' THEN
    PERFORM employees_queue_auth_sync(NEW.id);
  ELSIF ROW(OLD.department_id, OLD.employment_status, OLD.keycloak_user_id,
            OLD.login, OLD.work_email, OLD.first_name, OLD.last_name)
        IS DISTINCT FROM
        ROW(NEW.department_id, NEW.employment_status, NEW.keycloak_user_id,
            NEW.login, NEW.work_email, NEW.first_name, NEW.last_name) THEN
    PERFORM employees_queue_auth_sync(NEW.id);
  END IF;
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION employees_auth_sync_employee_event() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    PERFORM employees_queue_auth_sync(OLD.id);
  ELSIF TG_OP = 'INSERT' THEN
    PERFORM employees_queue_auth_sync(NEW.id);
  ELSIF ROW(OLD.department_id, OLD.employment_status, OLD.keycloak_user_id)
        IS DISTINCT FROM
        ROW(NEW.department_id, NEW.employment_status, NEW.keycloak_user_id) THEN
    PERFORM employees_queue_auth_sync(NEW.id);
  END IF;
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;
SQL);
    }
};
