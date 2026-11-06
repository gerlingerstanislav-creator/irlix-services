<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep stable identity IDs for Keycloak, audit and downstream references.
        // No employee, employment history or access role is deleted.
        Schema::table('employees', function (Blueprint $table) {
            $table->string('account_type', 16)->default('employee')->index();
        });

        DB::table('employees')
            ->where(fn ($query) => $query->where('login', 'admin')
                ->orWhere('cooperation_type', 'Системный аккаунт'))
            ->update(['account_type' => 'system', 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['account_type']);
            $table->dropColumn('account_type');
        });
    }
};
