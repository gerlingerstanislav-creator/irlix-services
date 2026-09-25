<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $platformAdminEmployeeIds = DB::table('employee_access_roles')
            ->where('role', 'platform-admin')
            ->pluck('employee_id')
            ->all();

        if ($platformAdminEmployeeIds) {
            DB::table('employee_access_roles')
                ->where('role', 'company-admin')
                ->whereIn('employee_id', $platformAdminEmployeeIds)
                ->delete();
        }

        DB::table('employee_access_roles')
            ->where('role', 'company-admin')
            ->update(['role' => 'platform-admin', 'updated_at' => now()]);

        $admin = DB::table('employees')->where('login', 'admin')->first();
        if (!$admin) {
            $adminId = DB::table('employees')->insertGetId([
                'full_name' => 'IRLIX Admin',
                'first_name' => 'IRLIX',
                'last_name' => 'Admin',
                'login' => 'admin',
                'work_email' => 'admin@irlix.ru',
                'position' => 'Platform Administrator',
                'employment_status' => 'Трудоустроен',
                'work_format' => 'Удалённо',
                'cooperation_type' => 'Системный аккаунт',
                'identity_status' => 'not_provisioned',
                'onboarding_email_status' => 'not_requested',
                'is_remote' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $adminId = (int) $admin->id;
        }

        DB::table('employee_access_roles')->updateOrInsert(
            ['employee_id' => $adminId, 'role' => 'platform-admin'],
            ['created_at' => now(), 'updated_at' => now()]
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION employees_enqueue_access_event() RETURNS trigger AS $$
DECLARE
    employee_id_value bigint;
    role_value text;
    granted_value boolean;
    employee_row employees%ROWTYPE;
BEGIN
    IF TG_OP = 'DELETE' THEN
        employee_id_value := OLD.employee_id;
        role_value := OLD.role;
        granted_value := false;
    ELSE
        employee_id_value := NEW.employee_id;
        role_value := NEW.role;
        granted_value := true;
    END IF;

    IF role_value <> 'platform-admin' THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
        RETURN NEW;
    END IF;

    SELECT * INTO employee_row FROM employees WHERE id = employee_id_value;
    IF NOT FOUND THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
        RETURN NEW;
    END IF;

    INSERT INTO outbox_events (
        id, event_type, event_version, aggregate_type, aggregate_id, occurred_at, payload,
        status, attempts, available_at, created_at, updated_at
    ) VALUES (
        md5(random()::text || clock_timestamp()::text)::uuid,
        'employee.access_changed', 1, 'employee', employee_id_value::text, now(),
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

    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        DB::table('employee_access_roles')->where('role', 'platform-admin')->update([
            'role' => 'company-admin',
            'updated_at' => now(),
        ]);
    }
};
