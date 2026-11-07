<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // The direction-HR automatic role is derived from existing hr_id rows.
        // Reconcile existing linked accounts; do not directly modify Keycloak.
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
        // JWT roles follow the deployed computation and remain reconciliation-based.
    }
};
