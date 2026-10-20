<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t): void {
            $t->decimal('overtime_multiplier', 8, 3)->default(1);
        });
        Schema::table('reporting_periods', function (Blueprint $t): void {
            // Snapshot: independent from later client-default changes.
            $t->decimal('overtime_multiplier', 8, 3)->default(1);
        });
        Schema::table('reporting_period_approved_hours', function (Blueprint $t): void {
            // Existing "hours" remain approved REGULAR hours; do not rewrite
            // historical Timesheets or assume approved == worked.
            $t->decimal('overtime_hours', 12, 2)->default(0);
        });
        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_overtime_multiplier_valid CHECK (overtime_multiplier >= 0.001)');
        DB::statement('ALTER TABLE reporting_periods ADD CONSTRAINT reporting_periods_overtime_multiplier_valid CHECK (overtime_multiplier >= 0.001)');
        DB::statement('ALTER TABLE reporting_period_approved_hours ADD CONSTRAINT reporting_period_approved_hours_overtime_valid CHECK (overtime_hours >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reporting_period_approved_hours DROP CONSTRAINT IF EXISTS reporting_period_approved_hours_overtime_valid');
        DB::statement('ALTER TABLE reporting_periods DROP CONSTRAINT IF EXISTS reporting_periods_overtime_multiplier_valid');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_overtime_multiplier_valid');
        Schema::table('reporting_period_approved_hours', static function (Blueprint $t): void {
            $t->dropColumn('overtime_hours');
        });
        Schema::table('reporting_periods', static function (Blueprint $t): void {
            $t->dropColumn('overtime_multiplier');
        });
        Schema::table('clients', static function (Blueprint $t): void {
            $t->dropColumn('overtime_multiplier');
        });
    }
};
