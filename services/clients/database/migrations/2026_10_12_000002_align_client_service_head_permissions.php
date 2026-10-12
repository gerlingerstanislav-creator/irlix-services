<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // One-time initial policy: the Client Service Head receives the union of
        // client-facing roles. Future edits to this role remain independent.
        $sources = ['account-manager', 'sales-manager', 'accounting-head', 'sales-head'];
        $permissions = DB::table('client_contour_permissions')
            ->whereIn('role', $sources)->where('allowed', true)
            ->where('permission', 'not like', 'permissions.%')
            ->pluck('permission')->unique()->all();
        foreach ($permissions as $permission) {
            DB::table('client_contour_permissions')->updateOrInsert(
                ['role' => 'client-service-head', 'permission' => $permission],
                ['allowed' => true, 'scope' => 'all', 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        // No automatic rollback: changing an administrator-edited permission
        // matrix during rollback would remove intentional access decisions.
    }
};
