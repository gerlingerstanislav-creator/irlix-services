<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // A new code-derived special role cannot be published to already
        // synchronized Keycloak identities by SQL triggers alone: no source
        // rows changed. Requeue linked accounts through the existing durable
        // versioned worker, which issues and revokes the exact current grants.
        // No role assignments or Keycloak flags are modified directly.
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
        // Changes to derived JWT grants are reconciled from the currently
        // deployed Employees access model, not reversed by restoring SQL rows.
    }
};
