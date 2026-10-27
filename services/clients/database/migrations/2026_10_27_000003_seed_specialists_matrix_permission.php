<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Previously this global page was implicitly visible to every active
        // Client Service employee regardless of the Clients matrix.
        // Preserve that baseline as an EXPLICIT, editable matrix row. Never
        // overwrite a subsequently configured rule or change head-role scopes.
        DB::table('client_contour_permissions')->insertOrIgnore([
            'role' => 'client-service',
            'permission' => 'specialists.view',
            'allowed' => true,
            'scope' => 'all',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // This rule becomes user-configurable. Do not delete a potentially
        // edited permission during migration rollback.
    }
};
