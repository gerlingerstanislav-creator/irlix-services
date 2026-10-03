<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // This bootstrap label is a global access role, never an employment position.
        $normalizedName = "lower(replace(replace(replace(trim(name), ' ', ''), '-', ''), '_', ''))";
        $normalizedPosition = "lower(replace(replace(replace(trim(position), ' ', ''), '-', ''), '_', ''))";
        $ids = DB::table('staff_positions')
            ->whereRaw($normalizedName." = 'platformadministrator'")
            ->pluck('id')->all();

        foreach (['employees', 'employment_periods', 'employment_assignment_history'] as $table) {
            DB::table($table)->where(function ($query) use ($ids, $normalizedPosition) {
                $query->whereRaw($normalizedPosition." = 'platformadministrator'");
                if ($ids) $query->orWhereIn('position_id', $ids);
            })->update(['position' => null, 'position_id' => null, 'updated_at' => now()]);
        }
        // Employee records, employment periods and employee_access_roles remain intact.
        if ($ids) DB::table('staff_positions')->whereIn('id', $ids)->delete();

        DB::statement("ALTER TABLE staff_positions ADD CONSTRAINT staff_positions_no_platform_admin CHECK (".$normalizedName." <> 'platformadministrator')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE staff_positions DROP CONSTRAINT IF EXISTS staff_positions_no_platform_admin');
        // Do not recreate the invalid role-as-position or change global access grants.
    }
};
