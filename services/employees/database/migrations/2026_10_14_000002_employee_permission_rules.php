<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('employee_permission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('role', 80);
            $table->string('permission', 100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['role','permission']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('employee_permission_rules');
    }
};
