<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->uuid('keycloak_user_id')->nullable()->unique()->after('login');
            $table->string('identity_status')->default('not_provisioned')->after('keycloak_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['keycloak_user_id']);
            $table->dropColumn(['keycloak_user_id', 'identity_status']);
        });
    }
};
