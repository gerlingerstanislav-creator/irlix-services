<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('actor_sub')->nullable()->index();
            $table->unsignedBigInteger('actor_employee_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->string('action')->index();
            $table->string('status', 32)->default('success')->index();
            $table->string('target_type', 64)->nullable()->index();
            $table->string('target_id', 128)->nullable()->index();
            $table->string('target_label')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['target_type', 'target_id']);
            $table->index(['occurred_at', 'action']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type')->index();
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->string('aggregate_type', 64)->index();
            $table->string('aggregate_id', 128)->index();
            $table->timestampTz('occurred_at');
            $table->jsonb('payload');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent()->index();
            $table->timestampTz('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'available_at']);
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION employees_enqueue_employee_event() RETURNS trigger AS $$
DECLARE
    event_name text;
    row_data record;
    previous_department bigint;
BEGIN
    row_data := CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;

    IF TG_OP = 'INSERT' THEN
        event_name := 'employee.created';
    ELSIF TG_OP = 'DELETE' THEN
        event_name := 'employee.deleted';
    ELSIF OLD.employment_status IS DISTINCT FROM NEW.employment_status AND NEW.employment_status = 'Уволен' THEN
        event_name := 'employee.dismissed';
    ELSIF OLD.employment_status = 'Уволен' AND NEW.employment_status = 'Трудоустроен' THEN
        event_name := 'employee.rehired';
    ELSIF ROW(
        OLD.full_name, OLD.first_name, OLD.last_name, OLD.middle_name, OLD.login, OLD.work_email,
        OLD.department_id, OLD.position, OLD.specialization, OLD.employment_status, OLD.work_format,
        OLD.cooperation_type, OLD.hired_at, OLD.fired_at
    ) IS DISTINCT FROM ROW(
        NEW.full_name, NEW.first_name, NEW.last_name, NEW.middle_name, NEW.login, NEW.work_email,
        NEW.department_id, NEW.position, NEW.specialization, NEW.employment_status, NEW.work_format,
        NEW.cooperation_type, NEW.hired_at, NEW.fired_at
    ) THEN
        event_name := 'employee.updated';
    ELSE
        RETURN row_data;
    END IF;

    INSERT INTO outbox_events (
        id, event_type, event_version, aggregate_type, aggregate_id, occurred_at, payload,
        status, attempts, available_at, created_at, updated_at
    ) VALUES (
        md5(random()::text || clock_timestamp()::text)::uuid,
        event_name,
        1,
        'employee',
        row_data.id::text,
        now(),
        jsonb_build_object(
            'employee_id', row_data.id,
            'login', row_data.login,
            'work_email', row_data.work_email,
            'full_name', row_data.full_name,
            'department_id', row_data.department_id,
            'position', row_data.position,
            'employment_status', row_data.employment_status,
            'cooperation_type', row_data.cooperation_type,
            'work_format', row_data.work_format
        ),
        'pending', 0, now(), now(), now()
    );

    IF TG_OP = 'UPDATE' AND OLD.department_id IS DISTINCT FROM NEW.department_id THEN
        previous_department := OLD.department_id;
        INSERT INTO outbox_events (
            id, event_type, event_version, aggregate_type, aggregate_id, occurred_at, payload,
            status, attempts, available_at, created_at, updated_at
        ) VALUES (
            md5(random()::text || clock_timestamp()::text)::uuid,
            'employee.department_changed',
            1,
            'employee',
            NEW.id::text,
            now(),
            jsonb_build_object(
                'employee_id', NEW.id,
                'login', NEW.login,
                'work_email', NEW.work_email,
                'full_name', NEW.full_name,
                'previous_department_id', previous_department,
                'department_id', NEW.department_id,
                'position', NEW.position,
                'employment_status', NEW.employment_status,
                'cooperation_type', NEW.cooperation_type,
                'work_format', NEW.work_format
            ),
            'pending', 0, now(), now(), now()
        );
    END IF;

    RETURN row_data;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER employees_domain_events
AFTER INSERT OR UPDATE OR DELETE ON employees
FOR EACH ROW EXECUTE FUNCTION employees_enqueue_employee_event();

CREATE OR REPLACE FUNCTION employees_enqueue_access_event() RETURNS trigger AS $$
DECLARE
    employee_id_value bigint;
    role_value text;
    granted_value boolean;
    employee_row record;
BEGIN
    employee_id_value := CASE WHEN TG_OP = 'DELETE' THEN OLD.employee_id ELSE NEW.employee_id END;
    role_value := CASE WHEN TG_OP = 'DELETE' THEN OLD.role ELSE NEW.role END;
    granted_value := TG_OP <> 'DELETE';

    IF role_value <> 'company-admin' THEN
        RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
    END IF;

    SELECT id, login, work_email, full_name, department_id, position, employment_status, cooperation_type, work_format
      INTO employee_row
      FROM employees
     WHERE id = employee_id_value;

    INSERT INTO outbox_events (
        id, event_type, event_version, aggregate_type, aggregate_id, occurred_at, payload,
        status, attempts, available_at, created_at, updated_at
    ) VALUES (
        md5(random()::text || clock_timestamp()::text)::uuid,
        'employee.access_changed',
        1,
        'employee',
        employee_id_value::text,
        now(),
        jsonb_build_object(
            'employee_id', employee_id_value,
            'login', employee_row.login,
            'work_email', employee_row.work_email,
            'full_name', employee_row.full_name,
            'department_id', employee_row.department_id,
            'position', employee_row.position,
            'employment_status', employee_row.employment_status,
            'cooperation_type', employee_row.cooperation_type,
            'work_format', employee_row.work_format,
            'role', role_value,
            'granted', granted_value
        ),
        'pending', 0, now(), now(), now()
    );

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER employee_access_domain_events
AFTER INSERT OR DELETE ON employee_access_roles
FOR EACH ROW EXECUTE FUNCTION employees_enqueue_access_event();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS employee_access_domain_events ON employee_access_roles;');
        DB::unprepared('DROP FUNCTION IF EXISTS employees_enqueue_access_event();');
        DB::unprepared('DROP TRIGGER IF EXISTS employees_domain_events ON employees;');
        DB::unprepared('DROP FUNCTION IF EXISTS employees_enqueue_employee_event();');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('audit_log');
    }
};
