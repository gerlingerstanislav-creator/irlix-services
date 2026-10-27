<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Functional Sales positions are now derived by the same Employees
        // source as Account Manager positions. Reconcile existing identities
        // so Keycloak realm roles reflect current HR data, with retries.
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
        // Idempotent reconciliation is intentionally not undone: existing
        // identity events or manually changed permissions must not be deleted.
    }
};
