<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Preserve the owner's existing custom grants/denials from the old
        // 'finance' label while switching to the canonical 'accounting' alias.
        // Never overwrite a setting already configured for 'accounting'.
        DB::statement(<<<'SQL'
INSERT INTO employee_permission_rules(role,permission,enabled,created_at,updated_at)
SELECT 'accounting',permission,enabled,now(),now()
FROM employee_permission_rules WHERE role='finance'
ON CONFLICT (role,permission) DO NOTHING
SQL);
    }
    public function down(): void
    {
        // Deliberately preserve existing permissions and admin edits on rollback.
    }
};
