<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Expand only: legacy project-based facts remain readable until the
        // coordinated migration to ClientMember IDs has completed and verified.
        Schema::table('timesheet_entries', function (Blueprint $t) {
            $t->unsignedBigInteger('client_member_id')->nullable();
            $t->index(['client_member_id', 'work_date']);
            $t->unique(['client_member_id', 'work_date']);
        });
        Schema::table('timesheet_entries', function (Blueprint $t) {
            $t->unsignedBigInteger('project_id')->nullable()->change();
            $t->unsignedBigInteger('employee_id')->nullable()->change();
        });
        Schema::table('final_approvals', function (Blueprint $t) {
            $t->unsignedBigInteger('client_member_id')->nullable();
            $t->unsignedBigInteger('employee_id')->nullable()->change();
            $t->unsignedBigInteger('project_id')->nullable()->change();
            $t->unique(['client_member_id', 'month']);
        });
        Schema::table('final_approval_exceptions', function (Blueprint $t) {
            $t->unsignedBigInteger('client_member_id')->nullable();
            $t->unsignedBigInteger('employee_id')->nullable()->change();
            $t->unsignedBigInteger('project_id')->nullable()->change();
            $t->unique(['client_member_id', 'work_date']);
        });
        Schema::table('timesheet_audit', function (Blueprint $t) {
            $t->unsignedBigInteger('client_member_id')->nullable();
        });
        Schema::create('partner_daily_confirmations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_member_id');
            $t->date('work_date');
            $t->unsignedBigInteger('confirmed_by_employee_id')->nullable();
            $t->timestampTz('confirmed_at');
            $t->timestamps();
            $t->unique(['client_member_id','work_date']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE timesheet_entries ADD CONSTRAINT timesheet_entries_source_xor CHECK ((project_id IS NULL) <> (client_member_id IS NULL))');
            DB::statement('ALTER TABLE timesheet_entries ADD CONSTRAINT timesheet_entries_employee_or_member CHECK (employee_id IS NOT NULL OR client_member_id IS NOT NULL)');
        }
    }
    public function down(): void
    {
        if (DB::table('timesheet_entries')->whereNotNull('client_member_id')->exists()) {
            throw new RuntimeException('Client member hours must be reconciled before rolling back schema');
        }
        Schema::dropIfExists('partner_daily_confirmations');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE timesheet_entries DROP CONSTRAINT IF EXISTS timesheet_entries_source_xor');
            DB::statement('ALTER TABLE timesheet_entries DROP CONSTRAINT IF EXISTS timesheet_entries_employee_or_member');
        }
        Schema::table('timesheet_audit', fn(Blueprint $t) => $t->dropColumn('client_member_id'));
        Schema::table('final_approval_exceptions', fn(Blueprint $t) => $t->dropUnique(['client_member_id', 'work_date']));
        Schema::table('final_approval_exceptions', fn(Blueprint $t) => $t->dropColumn('client_member_id'));
        Schema::table('final_approval_exceptions', function (Blueprint $t) { $t->unsignedBigInteger('employee_id')->nullable(false)->change();$t->unsignedBigInteger('project_id')->nullable(false)->change(); });
        Schema::table('final_approvals', fn(Blueprint $t) => $t->dropUnique(['client_member_id', 'month']));
        Schema::table('final_approvals', fn(Blueprint $t) => $t->dropColumn('client_member_id'));
        Schema::table('final_approvals', function (Blueprint $t) { $t->unsignedBigInteger('employee_id')->nullable(false)->change();$t->unsignedBigInteger('project_id')->nullable(false)->change(); });
        Schema::table('timesheet_entries', function (Blueprint $t) {
            $t->dropUnique(['client_member_id', 'work_date']);
            $t->dropIndex(['client_member_id', 'work_date']);
            $t->dropColumn('client_member_id');
        });
        Schema::table('timesheet_entries', function (Blueprint $t) {
            $t->unsignedBigInteger('project_id')->nullable(false)->change();
            $t->unsignedBigInteger('employee_id')->nullable(false)->change();
        });
    }
};
