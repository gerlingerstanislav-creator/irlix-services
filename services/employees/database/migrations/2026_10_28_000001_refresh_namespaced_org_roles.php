<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Changes to role-claim calculation are code-only; existing records
        // have no row trigger to request a new Keycloak publication.
        // Requeue all linked identities using the existing versioned journal.
        // No direct changes to role mappings, grants or authorization flags.
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
        // Revocation is handled by role reconciliation from current Employees.
    }
};
