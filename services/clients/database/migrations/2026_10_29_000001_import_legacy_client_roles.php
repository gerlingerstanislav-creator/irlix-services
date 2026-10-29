<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Re-key the EXISTING administrator-edited Clients permission rows to
 * signed V2 identity labels. Never regenerate defaults or copy an absent
 * permission; original records and manual deny/unassigned semantics remain.
 * Production managers are special: their generic role is projected only
 * after verification of a current signed head:* assignment at request time.
 */
return new class extends Migration {
    public function up(): void
    {
        $roleKeys = [
            'employee' => 'org:irlix',
            'client-service' => 'org:client-service',
            'account-manager' => 'org:account-management',
            'accounting-head' => 'head:account-management',
            'sales-manager' => 'org:sales',
            'sales-head' => 'head:sales',
            'client-service-head' => 'head:client-service',
            'personnel-officer' => 'special:personnel-officer',
            'system-admin' => 'special:system-admin',
        ];
        DB::transaction(static function () use ($roleKeys): void {
            $existing = DB::table('client_contour_permissions')
                ->whereIn('role', array_keys($roleKeys))
                ->get(['role','permission','allowed','scope','denied']);
            foreach ($existing as $row) {
                DB::table('client_contour_permissions')->insertOrIgnore([
                    'role' => $roleKeys[$row->role],
                    'permission' => $row->permission,
                    'allowed' => (bool) $row->allowed,
                    'scope' => $row->scope,
                    'denied' => (bool) $row->denied,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $saved = DB::table('client_contour_permissions')
                    ->where('role', $roleKeys[$row->role])
                    ->where('permission', $row->permission)
                    ->first(['allowed','scope','denied']);
                if (!$saved || (bool) $saved->allowed !== (bool) $row->allowed
                    || (bool) $saved->denied !== (bool) $row->denied
                    || (string) $saved->scope !== (string) $row->scope) {
                    throw new RuntimeException('Existing V2 Clients policy conflicts with legacy matrix');
                }
            }
        });
    }

    public function down(): void
    {
        // Never erase V2 rows that an administrator may have edited.
    }
};
