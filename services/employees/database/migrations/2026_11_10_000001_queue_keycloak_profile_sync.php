<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated queue for Keycloak corporate profile values. The authorization
 * roles worker can be deliberately disabled; login/email sync must not depend
 * on activating potentially unrelated realm-role writes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('keycloak_profile_sync', function (Blueprint $table): void {
            $table->unsignedBigInteger('employee_id')->primary();
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedBigInteger('version')->default(1);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('synced_at')->nullable();
            $table->string('last_error', 600)->nullable();
            $table->timestampsTz();
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION employees_queue_profile_sync(p_employee_id bigint) RETURNS void AS $$
BEGIN
  INSERT INTO employees.keycloak_profile_sync (
      employee_id,status,version,attempts,available_at,created_at,updated_at
  ) VALUES (
      p_employee_id,'pending',1,0,now(),now(),now()
  ) ON CONFLICT (employee_id) DO UPDATE SET
      status='pending',version=employees.keycloak_profile_sync.version+1,
      attempts=0,available_at=now(),updated_at=now();
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION employees_profile_sync_employee_event() RETURNS trigger AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN
    DELETE FROM employees.keycloak_profile_sync WHERE employee_id=OLD.id;
  ELSIF TG_OP = 'INSERT' THEN
    IF NEW.keycloak_user_id IS NOT NULL THEN
      PERFORM employees_queue_profile_sync(NEW.id);
    END IF;
  ELSIF TG_OP = 'UPDATE' THEN
    IF NEW.keycloak_user_id IS NOT NULL
      AND ROW(OLD.login,OLD.work_email,OLD.first_name,OLD.last_name,OLD.keycloak_user_id)
        IS DISTINCT FROM
          ROW(NEW.login,NEW.work_email,NEW.first_name,NEW.last_name,NEW.keycloak_user_id) THEN
      PERFORM employees_queue_profile_sync(NEW.id);
    END IF;
  END IF;
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER employees_profile_sync_employee AFTER INSERT OR UPDATE OR DELETE
  ON employees.employees FOR EACH ROW EXECUTE FUNCTION employees_profile_sync_employee_event();
SQL);
        // Newly installed queue never blindly rewrites every existing Keycloak
        // account. A subsequent HR edit/import (or explicit admin reconciliation)
        // schedules only the affected identities.
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS employees_profile_sync_employee ON employees.employees;
DROP FUNCTION IF EXISTS employees_profile_sync_employee_event();
DROP FUNCTION IF EXISTS employees_queue_profile_sync(bigint);
SQL);
        Schema::dropIfExists('keycloak_profile_sync');
    }
};
