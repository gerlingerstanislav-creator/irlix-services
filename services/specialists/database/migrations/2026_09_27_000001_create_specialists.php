<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('specialist_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id')->unique();
            $table->unsignedBigInteger('last_department_id')->nullable()->index();
            $table->string('employment_status')->nullable()->index();
            $table->string('grade')->nullable()->index();
            $table->text('manager_note')->nullable();
            $table->timestamps();
        });
        Schema::create('technologies', function (Blueprint $table) {
            $table->id(); $table->string('name')->unique(); $table->string('category')->nullable(); $table->boolean('active')->default(true); $table->timestamps();
        });
        Schema::create('competencies', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->unsignedBigInteger('technology_id')->nullable()->index(); $table->text('description')->nullable(); $table->boolean('active')->default(true); $table->timestamps(); $table->unique(['technology_id','name']);
        });
        Schema::create('specialist_technologies', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id'); $table->unsignedBigInteger('technology_id'); $table->timestamps(); $table->primary(['employee_id','technology_id']);
        });
        Schema::create('specialist_competencies', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id'); $table->unsignedBigInteger('competency_id'); $table->unsignedSmallInteger('level')->nullable(); $table->text('comment')->nullable(); $table->timestamps(); $table->primary(['employee_id','competency_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('specialist_competencies'); Schema::dropIfExists('specialist_technologies'); Schema::dropIfExists('competencies'); Schema::dropIfExists('technologies'); Schema::dropIfExists('specialist_profiles');
    }
};
