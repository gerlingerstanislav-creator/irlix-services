<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('keycloak_auth_sync', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->primary();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('version')->default(1);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION employees_queue_auth_sync(p_employee_id bigint) RETURNS void AS $$
BEGIN
  IF p_employee_id IS NULL THEN RETURN; END IF;
  INSERT INTO keycloak_auth_sync(employee_id,status,attempts,version,available_at,created_at,updated_at)
    VALUES(p_employee_id,'pending',0,1,now(),now(),now())
  ON CONFLICT (employee_id) DO UPDATE SET status='pending',attempts=0,version=keycloak_auth_sync.version+1,available_at=now(),updated_at=now();
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION employees_auth_sync_employee_event() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    PERFORM employees_queue_auth_sync(OLD.id);
  ELSIF TG_OP = 'INSERT' THEN
    PERFORM employees_queue_auth_sync(NEW.id);
  ELSIF ROW(OLD.department_id,OLD.employment_status,OLD.keycloak_user_id) IS DISTINCT FROM
        ROW(NEW.department_id,NEW.employment_status,NEW.keycloak_user_id) THEN
    PERFORM employees_queue_auth_sync(NEW.id);
  END IF;
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER employees_auth_sync_employee AFTER INSERT OR UPDATE OR DELETE ON employees
FOR EACH ROW EXECUTE FUNCTION employees_auth_sync_employee_event();

CREATE OR REPLACE FUNCTION employees_auth_sync_special_role_event() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    PERFORM employees_queue_auth_sync(OLD.employee_id);
  ELSE
    PERFORM employees_queue_auth_sync(NEW.employee_id);
  END IF;
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER employees_auth_sync_role AFTER INSERT OR UPDATE OR DELETE ON employee_access_roles
FOR EACH ROW EXECUTE FUNCTION employees_auth_sync_special_role_event();

CREATE OR REPLACE FUNCTION employees_auth_sync_global_event() RETURNS trigger AS $$
BEGIN
  INSERT INTO keycloak_auth_sync(employee_id,status,attempts,version,available_at,created_at,updated_at)
    SELECT id,'pending',0,1,now(),now(),now() FROM employees
  ON CONFLICT (employee_id) DO UPDATE SET status='pending',attempts=0,available_at=now(),updated_at=now();
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER employees_auth_sync_department AFTER INSERT OR UPDATE OR DELETE ON departments
FOR EACH STATEMENT EXECUTE FUNCTION employees_auth_sync_global_event();
CREATE TRIGGER employees_auth_sync_permission AFTER INSERT OR UPDATE OR DELETE ON employee_permission_rules
FOR EACH STATEMENT EXECUTE FUNCTION employees_auth_sync_global_event();
SQL);

        DB::statement("INSERT INTO keycloak_auth_sync(employee_id,status,available_at,created_at,updated_at) SELECT id,'pending',now(),now(),now() FROM employees ON CONFLICT DO NOTHING");
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS employees_auth_sync_permission ON employee_permission_rules;
DROP TRIGGER IF EXISTS employees_auth_sync_department ON departments;
DROP TRIGGER IF EXISTS employees_auth_sync_role ON employee_access_roles;
DROP TRIGGER IF EXISTS employees_auth_sync_employee ON employees;
DROP FUNCTION IF EXISTS employees_auth_sync_global_event();
DROP FUNCTION IF EXISTS employees_auth_sync_special_role_event();
DROP FUNCTION IF EXISTS employees_auth_sync_employee_event();
DROP FUNCTION IF EXISTS employees_queue_auth_sync(bigint);
SQL);
        Schema::dropIfExists('keycloak_auth_sync');
    }
};
