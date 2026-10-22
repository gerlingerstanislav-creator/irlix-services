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
            $table->string('service', 64);
            $table->string('table_name', 64);
            $table->unsignedInteger('batch_number');
            $table->string('first_source_id', 255);
            $table->string('last_source_id', 255);
            $table->unsignedInteger('row_count');
            $table->string('source_sha256', 64);
            $table->string('state', 24);
            $table->timestamps();
            $table->unique(['migration_run_id', 'table_name', 'batch_number'], 'migration_batch_run_table_number');
            $table->index(['service', 'table_name', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('migration_batch_progress'); }
};
