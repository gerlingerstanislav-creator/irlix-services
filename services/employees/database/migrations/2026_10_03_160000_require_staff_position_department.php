<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only infer a legacy department when all non-empty links agree.
        DB::statement(<<<'SQL'
            UPDATE staff_positions p
               SET direction_id = source.department_id
              FROM (
                SELECT position_id, MIN(department_id) AS department_id
                FROM (
                  SELECT position_id, department_id FROM employees
                  UNION ALL SELECT position_id, department_id FROM employment_periods
                  UNION ALL SELECT position_id, department_id FROM employment_assignment_history
                ) links
                WHERE position_id IS NOT NULL AND department_id IS NOT NULL
                GROUP BY position_id HAVING COUNT(DISTINCT department_id) = 1
              ) source
             WHERE p.id = source.position_id AND p.direction_id IS NULL
        SQL);
        $unlinked = DB::table('staff_positions')->whereNull('direction_id')->get(['id', 'name']);
        if ($unlinked->isNotEmpty()) {
            throw new RuntimeException('Assign a department to existing staff positions before retrying migration. IDs: '.$unlinked->map(fn ($position) => $position->id.': '.$position->name)->implode(', ').'. Existing positions are preserved.');
        }
        DB::statement('ALTER TABLE staff_positions ALTER COLUMN direction_id SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE staff_positions ALTER COLUMN direction_id DROP NOT NULL');
    }
};
