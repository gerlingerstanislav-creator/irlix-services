<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $clientPermissions = [
            'clients.view', 'clients.manage', 'contacts.view', 'contacts.manage',
            'members.view', 'members.manage', 'requests.view', 'requests.manage',
            'positions.view', 'positions.manage', 'attempts.view', 'attempts.manage',
            'leads.view', 'leads.manage', 'reports.view', 'reports.manage',
            'cashflow.view', 'permissions.view', 'permissions.manage',
        ];

        DB::table('client_contour_permissions')
            ->where('role', 'department-manager')
            ->whereIn('permission', $clientPermissions)
            ->update(['allowed' => false, 'scope' => 'none', 'updated_at' => now()]);

        DB::table('client_contour_permissions')
            ->where('role', 'department-manager')
            ->whereIn('permission', ['positions.view', 'attempts.view', 'attempts.manage'])
            ->update(['allowed' => true, 'scope' => 'team', 'updated_at' => now()]);

        DB::table('client_contour_permissions')
            ->whereIn('role', ['sales-manager', 'sales-head'])
            ->whereIn('permission', ['requests.view', 'requests.manage'])
            ->update(['allowed' => false, 'scope' => 'none', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('client_contour_permissions')
            ->where('role', 'department-manager')
            ->whereIn('permission', ['positions.view', 'attempts.view', 'attempts.manage'])
            ->update(['allowed' => false, 'scope' => 'none', 'updated_at' => now()]);

        DB::table('client_contour_permissions')->where('role', 'sales-manager')
            ->whereIn('permission', ['requests.view', 'requests.manage'])
            ->update(['allowed' => true, 'scope' => 'own', 'updated_at' => now()]);
        DB::table('client_contour_permissions')->where('role', 'sales-head')
            ->whereIn('permission', ['requests.view', 'requests.manage'])
            ->update(['allowed' => true, 'scope' => 'team', 'updated_at' => now()]);
    }
};
