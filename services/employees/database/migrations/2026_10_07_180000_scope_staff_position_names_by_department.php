<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_positions', function (Blueprint $table) {
            $table->dropUnique('staff_positions_name_unique');
            $table->unique(['direction_id', 'name'], 'staff_positions_direction_name_unique');
        });
    }

    public function down(): void
    {
        $duplicates = DB::table('staff_positions')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Cannot restore global staff position name uniqueness while the same title exists in multiple departments.'
            );
        }

        Schema::table('staff_positions', function (Blueprint $table) {
            $table->dropUnique('staff_positions_direction_name_unique');
            $table->unique('name', 'staff_positions_name_unique');
        });
    }
};
