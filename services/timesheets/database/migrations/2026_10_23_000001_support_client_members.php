<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $t) {
            // Cross-service reference: no local FK to Clients database.
            $t->unsignedBigInteger('client_member_id')->nullable();
            $t->unique(['client_member_id', 'work_date'], 'timesheet_entries_client_member_date_unique');
            $t->index(['client_member_id', 'work_date']);
        });
        Schema::table('final_approvals', function (Blueprint $t) {
            $t->unsignedBigInteger('client_member_id')->nullable();
            $t->unique(['client_member_id', 'month'], 'final_approvals_client_member_month_unique');
        });
        Schema::table('final_approval_exceptions', function (Blueprint $t) {
            $t->unsignedBigInteger('client_member_id')->nullable();
            $t->unique(['client_member_id', 'work_date'], 'final_approval_exceptions_client_member_date_unique');
        });
        Schema::table('timesheet_audit', function (Blueprint $t) {
            $t->unsignedBigInteger('client_member_id')->nullable();
        });
        // Existing project-based rows remain until the verified legacy
        // backfill is complete. New client-member facts have no project.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE timesheet_entries ALTER COLUMN employee_id DROP NOT NULL');
            DB::statement('ALTER TABLE timesheet_entries ALTER COLUMN project_id DROP NOT NULL');
            DB::statement('ALTER TABLE final_approvals ALTER COLUMN employee_id DROP NOT NULL');
            DB::statement('ALTER TABLE final_approvals ALTER COLUMN project_id DROP NOT NULL');
            DB::statement('ALTER TABLE final_approval_exceptions ALTER COLUMN employee_id DROP NOT NULL');
            DB::statement('ALTER TABLE final_approval_exceptions ALTER COLUMN project_id DROP NOT NULL');
            DB::statement("ALTER TABLE timesheet_entries ADD CONSTRAINT timesheet_entries_ownership_xor CHECK ((project_id IS NOT NULL) <> (client_member_id IS NOT NULL))");
        }
        Schema::create('partner_time_confirmations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_member_id');
            $t->date('work_date');
            $t->unsignedBigInteger('confirmed_by_employee_id')->nullable();
            // Future OIDC-authenticated external actor: not used in MVP.
            $t->string('confirmed_by_subject')->nullable();
            $t->timestampTz('confirmed_at');
            $t->timestamps();
            $t->unique(['client_member_id', 'work_date'], 'partner_time_confirmations_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('timesheet_entries')->whereNotNull('client_member_id')->exists()
            || DB::table('final_approvals')->whereNotNull('client_member_id')->exists()) {
            throw new RuntimeException('Client-member timesheets and approvals require explicit reconciliation before rollback.');
        }
        Schema::dropIfExists('partner_time_confirmations');
        if (DB::getDriverName() === 'pgsql') DB::statement('ALTER TABLE timesheet_entries DROP CONSTRAINT IF EXISTS timesheet_entries_ownership_xor');
        Schema::table('timesheet_audit', function (Blueprint $t) { $t->dropColumn('client_member_id'); });
        Schema::table('final_approval_exceptions', function (Blueprint $t) {
            $t->dropUnique('final_approval_exceptions_client_member_date_unique');
            $t->dropColumn('client_member_id');
        });
        Schema::table('final_approvals', function (Blueprint $t) {
            $t->dropUnique('final_approvals_client_member_month_unique');
            $t->dropColumn('client_member_id');
        });
        Schema::table('timesheet_entries', function (Blueprint $t) {
            $t->dropUnique('timesheet_entries_client_member_date_unique');
            $t->dropIndex(['client_member_id', 'work_date']);
            $t->dropColumn('client_member_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE timesheet_entries ALTER COLUMN employee_id SET NOT NULL');
            DB::statement('ALTER TABLE timesheet_entries ALTER COLUMN project_id SET NOT NULL');
            DB::statement('ALTER TABLE final_approvals ALTER COLUMN employee_id SET NOT NULL');
            DB::statement('ALTER TABLE final_approvals ALTER COLUMN project_id SET NOT NULL');
            DB::statement('ALTER TABLE final_approval_exceptions ALTER COLUMN employee_id SET NOT NULL');
            DB::statement('ALTER TABLE final_approval_exceptions ALTER COLUMN project_id SET NOT NULL');
        }
    }
};
