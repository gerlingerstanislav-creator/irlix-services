<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // The Account Manager's existing Client project registry contains
        // specialist connections. Reading the canonical connection card
        // requires members.view, independently from clients.view.
        // Repair the historic Account Manager baseline once, retaining any
        // configured team/all scope. The matrix remains editable thereafter.
        $permission = 'members.view';
        $row = DB::table('client_contour_permissions')
            ->where('role', 'account-manager')->where('permission', $permission)->first();
        if ($row && $row->allowed && in_array($row->scope, ['own', 'team', 'all'], true)) return;
        DB::table('client_contour_permissions')->updateOrInsert(
            ['role' => 'account-manager', 'permission' => $permission],
            ['allowed' => true,
             'scope' => $row && in_array($row->scope, ['own', 'team', 'all'], true) ? $row->scope : 'own',
             'updated_at' => now(), ...(!$row ? ['created_at' => now()] : [])]
        );
    }

    public function down(): void
    {
        // Edited permission assignments cannot be safely reverted.
    }
};
