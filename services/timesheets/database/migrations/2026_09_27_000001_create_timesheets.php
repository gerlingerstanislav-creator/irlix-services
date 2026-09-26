<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('timesheet_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('account_employee_id')->nullable();
            $table->date('work_date');
            $table->decimal('hours', 5, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'project_id', 'work_date']);
            $table->index(['employee_id', 'work_date']);
        });

        Schema::create('employee_confirmations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('work_date');
            $table->timestampTz('confirmed_at');
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
        });

        Schema::create('final_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('project_id');
            $table->date('month');
            $table->unsignedBigInteger('approved_by');
            $table->timestampTz('approved_at');
            $table->timestamps();
            $table->unique(['employee_id', 'project_id', 'month']);
        });

        Schema::create('period_locks', function (Blueprint $table) {
            $table->id();
            $table->date('month')->unique();
            $table->unsignedBigInteger('locked_by');
            $table->timestampTz('locked_at');
            $table->timestamps();
        });

        Schema::create('timesheet_audit', function (Blueprint $table) {
            $table->id();
            $table->string('action', 80);
            $table->unsignedBigInteger('actor_employee_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->date('work_date')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timesheet_audit');
        Schema::dropIfExists('period_locks');
        Schema::dropIfExists('final_approvals');
        Schema::dropIfExists('employee_confirmations');
        Schema::dropIfExists('timesheet_entries');
    }
};
