<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('migration_batch_progress', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('migration_run_id');
            $table->string('service');
            $table->string('table_name');
            $table->unsignedInteger('batch_number');
            $table->string('first_source_id');
            $table->string('last_source_id');
            $table->unsignedInteger('row_count');
            $table->string('source_sha256', 64);
            $table->string('state')->default('completed');
            $table->timestamps();
            $table->unique(['migration_run_id', 'table_name', 'batch_number'], 'migration_batch_unique');
            $table->index(['migration_run_id', 'table_name']);
        });
        Schema::create('migration_batch_natural_keys', function (Blueprint $table): void {
            $table->unsignedBigInteger('migration_run_id');
            $table->string('entity_type');
            $table->string('key_hash', 64);
            $table->string('legacy_id');
            $table->primary(['migration_run_id','entity_type','key_hash'], 'batch_natural_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_batch_natural_keys');
        Schema::dropIfExists('migration_batch_progress');
    }
};
