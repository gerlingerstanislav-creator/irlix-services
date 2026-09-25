<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE vacations.absences ALTER COLUMN ends_on DROP NOT NULL');
        DB::statement('ALTER TABLE vacations.absences ALTER COLUMN calendar_days DROP NOT NULL');

        Schema::table('vacations.absences', function (Blueprint $table) {
            $table->unsignedInteger('entitlement_days')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
        });

        Schema::create('vacations.absence_approvals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('absence_id');
            $table->unsignedInteger('sequence');
            $table->string('stage', 48);
            $table->string('status', 24)->default('pending');
            $table->string('required_role', 48)->nullable();
            $table->unsignedBigInteger('approver_employee_id')->nullable();
            $table->string('approver_subject', 128)->nullable();
            $table->string('acted_by_subject', 128)->nullable();
            $table->timestampTz('acted_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index(['absence_id', 'sequence']);
            $table->index(['status', 'required_role']);
            $table->index(['approver_employee_id', 'status']);
        });

        Schema::create('vacations.absence_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('absence_id');
            $table->string('from_status', 48)->nullable();
            $table->string('to_status', 48);
            $table->string('actor_subject', 128);
            $table->unsignedBigInteger('actor_employee_id')->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['absence_id', 'created_at']);
        });

        Schema::create('vacations.absence_audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('absence_id');
            $table->string('event', 64);
            $table->string('actor_subject', 128);
            $table->unsignedBigInteger('actor_employee_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['absence_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacations.absence_audit_log');
        Schema::dropIfExists('vacations.absence_status_history');
        Schema::dropIfExists('vacations.absence_approvals');

        Schema::table('vacations.absences', function (Blueprint $table) {
            $table->dropColumn(['entitlement_days', 'submitted_at', 'confirmed_at']);
        });
    }
};
