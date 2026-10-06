<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('migration_table_progress', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('migration_run_id');
            $table->string('table_name');
            $table->string('state')->default('waiting');
            $table->unsignedBigInteger('total')->nullable();
            $table->unsignedBigInteger('success_count')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            $table->unsignedBigInteger('warning_count')->default(0);
            $table->unsignedBigInteger('read_count')->default(0);
            $table->timestamps();
            $table->unique(['migration_run_id', 'table_name']);
        });
    }
    public function down(): void { Schema::dropIfExists('migration_table_progress'); }
};
