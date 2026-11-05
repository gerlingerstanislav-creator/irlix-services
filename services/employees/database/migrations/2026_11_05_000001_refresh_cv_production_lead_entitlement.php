<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Code-only expansion of Employees services.cv-converter does not fire
        // employee/department triggers. Requeue already linked identities so
        // Keycloak publishes service:cv-converter for production managers.
        // The existing versioned journal handles concurrent changes; no direct
        // Keycloak writes, credential changes or role assignments happen here.
        DB::unprepared(<<<'SQL'
DO $$
DECLARE current_employee record;
BEGIN
    FOR current_employee IN
        SELECT id FROM employees WHERE keycloak_user_id IS NOT NULL
    LOOP
        PERFORM employees_queue_auth_sync(current_employee.id);
    END LOOP;
END
$$;
SQL);
    }

    public function down(): void
    {
        // Effective grants are always reconciled against current Employees
        // rules; deleting or manually editing Keycloak roles here is unsafe.
    }
};
