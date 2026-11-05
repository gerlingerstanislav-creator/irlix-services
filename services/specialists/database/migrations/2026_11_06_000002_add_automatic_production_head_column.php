<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // A new system-derived role should be visible in the existing
        // Specialists permission matrix, but must not receive grants
        // implicitly. Preserve any administrator-edited or retired column.
        DB::table('specialists_permission_roles')->insertOrIgnore([
            'role' => 'special:production-direction-head',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Do not delete an administrator-managed role column or its grants
        // during application rollback.
    }
};
