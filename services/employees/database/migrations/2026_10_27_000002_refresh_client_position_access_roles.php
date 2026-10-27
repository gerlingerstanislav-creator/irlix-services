<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Account Manager is also an HR position within Client Service,
        // not necessarily an 'account-management' department membership.
        // Existing JWTs do not automatically acquire changed functional roles.
        // Reconcile every linked identity through the versioned retry queue;
        // do not touch user credentials, passwords or Keycloak directly here.
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
        // Role updates are reconciled against current Employees data, not
        // rolled back by deleting queued events or editing realm roles.
    }
};
