<?php

use App\Support\ClientContourAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ensure the Sales business column exists after the legacy-to-V2 transition.
 * The editable key is org:sales, not the retired sales-manager matrix role.
 * A previously configured V2 column is never reseeded or overwritten.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::transaction(static function (): void {
            $role = 'org:sales';
            if (DB::table('client_contour_permissions')->where('role', $role)->exists()) {
                return;
            }

            $defaults = [
                'leads.view', 'leads.manage',
                'requests.view', 'requests.manage',
                'positions.view', 'positions.manage',
                'attempts.view', 'attempts.manage',
            ];
            $now = now();
            foreach (array_keys(ClientContourAccess::PERMISSION_LABELS) as $permission) {
                DB::table('client_contour_permissions')->insertOrIgnore([
                    'role' => $role,
                    'permission' => $permission,
                    'allowed' => in_array($permission, $defaults, true),
                    'scope' => in_array($permission, $defaults, true) ? 'own' : 'none',
                    'denied' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // No reverse: the role's permissions can be edited by administrators.
    }
};
