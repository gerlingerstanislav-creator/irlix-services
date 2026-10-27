<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // A code-only RoleLabels change does not fire database row triggers.
        // Queue a new authorization snapshot for existing identities so the
        // worker promptly publishes account-manager/sales-manager grants.
        // The existing versioned queue handles concurrent edits safely.
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
        // Durable Keycloak reconciliations are not rolled back by replaying a
        // data migration; the worker always uses the current Employees model.
    }
};
