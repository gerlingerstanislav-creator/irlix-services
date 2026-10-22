<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Domain facts own only the employee, project, date, hours and comment.
        // Client ownership and account assignments come from the authoritative Clients API.
        Schema::table('timesheet_entries', function (Blueprint $table): void {
            $table->dropColumn(['client_id', 'account_employee_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE timesheet_entries ADD CONSTRAINT timesheet_entries_hours_range CHECK (hours >= 0 AND hours <= 24)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE timesheet_entries DROP CONSTRAINT IF EXISTS timesheet_entries_hours_range');
        }
        // Values must be reconstructed from Clients on rollback, never fabricated.
        Schema::table('timesheet_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('account_employee_id')->nullable();
        });
    }
};
