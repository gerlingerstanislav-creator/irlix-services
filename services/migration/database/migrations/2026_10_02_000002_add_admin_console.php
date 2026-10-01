<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 64)->unique();
            $table->string('host', 255);
            $table->unsignedInteger('port')->default(5432);
            $table->string('database', 255);
            $table->string('username', 255);
            $table->text('password_encrypted');
            $table->string('sslmode', 32)->default('prefer');
            $table->boolean('readonly_acknowledged')->default(false);
            $table->string('verification_status', 32)->default('not_verified');
            $table->text('verification_message')->nullable();
            $table->string('verified_database', 255)->nullable();
            $table->string('verified_user', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::table('migration_runs', function (Blueprint $table): void {
            $table->string('requested_by', 255)->nullable()->after('status');
            $table->string('progress_phase', 64)->nullable()->after('requested_by');
            $table->text('progress_message')->nullable()->after('progress_phase');
            $table->unsignedBigInteger('processed_count')->default(0)->after('progress_message');
            $table->unsignedBigInteger('success_count')->default(0)->after('processed_count');
            $table->unsignedBigInteger('warning_count')->default(0)->after('success_count');
            $table->unsignedBigInteger('conflict_count')->default(0)->after('warning_count');
            $table->timestamp('heartbeat_at')->nullable()->after('conflict_count');
            $table->index(['service', 'status', 'started_at']);
        });

        Schema::create('migration_run_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('migration_run_id')->constrained('migration_runs')->cascadeOnDelete();
            $table->string('level', 16)->default('info');
            $table->string('event', 64);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at');
            $table->index(['migration_run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_run_events');
        Schema::table('migration_runs', function (Blueprint $table): void {
            $table->dropIndex(['service', 'status', 'started_at']);
            $table->dropColumn([
                'requested_by',
                'progress_phase',
                'progress_message',
                'processed_count',
                'success_count',
                'warning_count',
                'conflict_count',
                'heartbeat_at',
            ]);
        });
        Schema::dropIfExists('migration_connections');
    }
};
