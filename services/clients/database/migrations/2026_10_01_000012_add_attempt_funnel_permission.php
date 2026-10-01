<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $defaults = [
            'employee' => 'own',
            'account-manager' => 'own',
            'accounting-head' => 'team',
            'client-service-head' => 'all',
            'sales-manager' => 'own',
            'sales-head' => 'team',
            'department-manager' => 'team',
            'personnel-officer' => 'own',
            'system-admin' => 'own',
        ];
        $now = now();
        foreach ($defaults as $role => $scope) {
            if (DB::table('client_contour_permissions')->where(['role' => $role, 'permission' => 'attempts.analytics.view'])->exists()) continue;
            DB::table('client_contour_permissions')->insert([
                'role' => $role,
                'permission' => 'attempts.analytics.view',
                'allowed' => true,
                'scope' => $scope,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('client_contour_permissions')->where('permission', 'attempts.analytics.view')->delete();
    }
};
