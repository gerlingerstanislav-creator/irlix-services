<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales owns a client when clients.sales_employee_id matches their Employees id.
 * The original Sales baseline opened requests/positions/attempts but not the
 * Clients registry. Repair only untouched defaults; preserve audited edits,
 * explicit denials and removed V2 columns.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(static function (): void {
            $role = 'org:sales';
            $permission = 'clients.view';
            if (!DB::table('client_contour_permissions')->where('role', $role)->exists()) {
                return; // An administrator may have removed the role entirely.
            }
            $row = DB::table('client_contour_permissions')
                ->where('role', $role)->where('permission', $permission)
                ->lockForUpdate()->first();
            $audited = DB::table('client_contour_permission_audit')
                ->whereIn('role', [$role, 'sales-manager'])
                ->where('permission', $permission)->exists();
            if ($audited || ($row && ((bool) $row->denied || (bool) $row->allowed))) return;

            if ($row) {
                DB::table('client_contour_permissions')->where('id', $row->id)
                    ->update(['allowed' => true, 'scope' => 'own', 'updated_at' => now()]);
            } else {
                DB::table('client_contour_permissions')->insert([
                    'role' => $role, 'permission' => $permission,
                    'allowed' => true, 'scope' => 'own', 'denied' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // No reverse: this permission becomes administrator-managed after seeding.
    }
};
