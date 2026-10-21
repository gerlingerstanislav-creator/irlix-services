<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Keycloak User Profile may reject unmanaged custom attributes (HTTP 400).
        // Keep the sync journal in Employees; only signed effective grants live in Keycloak.
        Schema::table('keycloak_auth_sync', function (Blueprint $table) {
            $table->jsonb('managed_roles')->nullable();
        });
        // Reconcile every linked identity under the new verified read-back
        // protocol, including accounts previously marked synced without proof.
        // No Keycloak mutation occurs during the migration.
        DB::statement("UPDATE keycloak_auth_sync SET status='pending', attempts=0,
            version=version+1, available_at=now(), updated_at=now()
            WHERE employee_id IN (SELECT id FROM employees WHERE keycloak_user_id IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::table('keycloak_auth_sync', function (Blueprint $table) {
            $table->dropColumn('managed_roles');
        });
    }
};
