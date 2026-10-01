<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        foreach ([
            ['role' => 'sales-manager', 'permission' => 'clients.view', 'scope' => 'own'],
            ['role' => 'sales-manager', 'permission' => 'clients.manage', 'scope' => 'own'],
            ['role' => 'sales-head', 'permission' => 'clients.view', 'scope' => 'team'],
            ['role' => 'sales-head', 'permission' => 'clients.manage', 'scope' => 'team'],
        ] as $grant) {
            DB::table('client_contour_permissions')->updateOrInsert(
                ['role' => $grant['role'], 'permission' => $grant['permission']],
                ['allowed' => true, 'scope' => $grant['scope'], 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('client_contour_permissions')
            ->whereIn('role', ['sales-manager', 'sales-head'])
            ->whereIn('permission', ['clients.view', 'clients.manage'])
            ->update(['allowed' => false, 'scope' => 'none', 'updated_at' => now()]);
    }
};
