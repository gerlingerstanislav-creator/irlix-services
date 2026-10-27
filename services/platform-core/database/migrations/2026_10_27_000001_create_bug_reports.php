<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('bug_reports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('employee_id');
            $table->string('reporter_keycloak_sub', 191);
            $table->string('service_key', 64);
            $table->string('page_path', 2048);
            $table->text('description');
            $table->string('status', 16)->default('open');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by_employee_id')->nullable();
            $table->index(['status', 'created_at']);
            $table->index(['service_key', 'created_at']);
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('bug_reports'); }
};
