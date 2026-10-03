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
            $table->foreignId('direction_id')->nullable()->after('name')->constrained('departments')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable()->after('base_salary');
        });

        DB::statement(<<<'SQL'
            UPDATE staff_positions p
               SET direction_id = source.direction_id
              FROM (
                    SELECT e.position_id, MIN(e.department_id) AS direction_id
                      FROM employees e
                      JOIN departments d ON d.id = e.department_id AND d.is_production = TRUE
                     WHERE e.position_id IS NOT NULL
                     GROUP BY e.position_id
                    HAVING COUNT(DISTINCT e.department_id) = 1
                   ) source
             WHERE p.id = source.position_id
               AND p.direction_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('staff_positions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('direction_id');
            $table->dropColumn('closed_at');
        });
    }
};
