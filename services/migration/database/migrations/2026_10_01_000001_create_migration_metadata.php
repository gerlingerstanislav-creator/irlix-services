<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 64);
            $table->string('mode', 32);
            $table->string('status', 32);
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['service', 'started_at']);
        });

        Schema::create('migration_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('migration_run_id')->nullable()->constrained('migration_runs')->nullOnDelete();
            $table->string('service', 64);
            $table->string('entity_type', 64);
            $table->string('legacy_id', 255);
            $table->string('target_id', 255);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['service', 'entity_type', 'legacy_id']);
        });

        Schema::create('migration_overrides', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 64);
            $table->string('entity_type', 64);
            $table->string('legacy_id', 255);
            $table->string('target_id', 255);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['service', 'entity_type', 'legacy_id']);
        });

        Schema::create('migration_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('migration_run_id')->nullable()->constrained('migration_runs')->nullOnDelete();
            $table->string('service', 64);
            $table->string('entity_type', 64);
            $table->string('legacy_id', 255)->nullable();
            $table->string('severity', 16)->default('error');
            $table->string('code', 96);
            $table->text('message');
            $table->json('context')->nullable();
            $table->boolean('resolved')->default(false);
            $table->json('resolution')->nullable();
            $table->timestamps();
            $table->index(['service', 'severity', 'resolved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_conflicts');
        Schema::dropIfExists('migration_overrides');
        Schema::dropIfExists('migration_mappings');
        Schema::dropIfExists('migration_runs');
    }
};
