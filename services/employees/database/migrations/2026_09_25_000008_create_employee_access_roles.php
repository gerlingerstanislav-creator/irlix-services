<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_access_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('role', 64);
            $table->timestamps();
            $table->unique(['employee_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_access_roles');
    }
};
