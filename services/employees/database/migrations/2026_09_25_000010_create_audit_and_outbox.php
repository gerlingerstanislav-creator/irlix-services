<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->string('request_id', 64)->nullable()->index();
            $table->string('actor_sub')->nullable()->index();
            $table->unsignedBigInteger('actor_employee_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->string('action')->index();
            $table->string('status', 32)->default('success')->index();
            $table->string('target_type', 64)->nullable()->index();
            $table->string('target_id', 128)->nullable()->index();
            $table->string('target_label')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['target_type', 'target_id']);
            $table->index(['occurred_at', 'action']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type')->index();
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->string('aggregate_type', 64)->index();
            $table->string('aggregate_id', 128)->index();
            $table->timestampTz('occurred_at');
            $table->jsonb('payload');
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent()->index();
            $table->timestampTz('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('audit_log');
    }
};
