<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // A client-service employee must be able to open their own Clients
        // workspace even when they are neither an Account Manager nor Sales.
        // Existing administrator matrix choices must never be overwritten.
        $now = now();
        foreach (['clients.view', 'contacts.view'] as $permission) {
            DB::table('client_contour_permissions')->insertOrIgnore([
                'role' => 'client-service',
                'permission' => $permission,
                'allowed' => true,
                'scope' => 'own',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Permission rows are user-editable business policy; deleting them on
        // rollback would destroy administrator changes made after migration.
    }
};
