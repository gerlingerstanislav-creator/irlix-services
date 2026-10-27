<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // The owner explicitly requires these Account Manager workspaces.
        // Repair existing matrices, including ones seeded before the current
        // role model, once. The matrix remains editable after this migration.
        // Never widen a non-none scope or grant other business/admin roles.
        foreach ([
            'reports.view', 'reports.manage', 'cashflow.view',
            'requests.view', 'positions.view', 'attempts.view',
            'timesheets.management.view',
        ] as $permission) {
            $row = DB::table('client_contour_permissions')
                ->where('role', 'account-manager')
                ->where('permission', $permission)
                ->first();
            $now = now();
            if ($row === null) {
                DB::table('client_contour_permissions')->insert([
                    'role' => 'account-manager', 'permission' => $permission,
                    'allowed' => true, 'scope' => 'own',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                continue;
            }
            if ($row->allowed && in_array($row->scope, ['own', 'team', 'all'], true)) {
                continue;
            }
            DB::table('client_contour_permissions')->where('id', $row->id)->update([
                'allowed' => true,
                'scope' => in_array($row->scope, ['own', 'team', 'all'], true) ? $row->scope : 'own',
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Access rules are user-editable and audited. Blind rollback would
        // remove access decisions and cannot reconstruct their former state.
    }
};
