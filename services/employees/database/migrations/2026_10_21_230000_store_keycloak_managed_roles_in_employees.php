<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Keycloak User Profile may reject unmanaged custom attributes (HTTP 400).
        // Keep the sync journal in Employees; only signed effective grants live in Keycloak.
        Schema::table('keycloak_auth_sync', function (Blueprint $table) {
            $table->jsonb('managed_roles')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('keycloak_auth_sync', function (Blueprint $table) {
            $table->dropColumn('managed_roles');
        });
    }
};
