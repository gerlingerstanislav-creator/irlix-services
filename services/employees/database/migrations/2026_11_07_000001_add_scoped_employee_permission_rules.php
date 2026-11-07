<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Existing boolean grants retain their exact enabled flag.
        // NULL scope means the role's existing default (manager: own; personnel: all).
        Schema::table('employee_permission_rules', function (Blueprint $table): void {
            $table->string('scope', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_permission_rules', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
    }
};
