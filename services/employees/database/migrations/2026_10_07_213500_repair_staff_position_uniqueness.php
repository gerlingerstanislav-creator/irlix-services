<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE staff_positions DROP CONSTRAINT IF EXISTS staff_positions_name_unique');
        DB::statement('DROP INDEX IF EXISTS staff_positions_name_unique');
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS staff_positions_direction_name_unique ON staff_positions (direction_id, name)'
        );
    }

    public function down(): void
    {
        // This is a schema-repair migration. Restoring the old global name uniqueness would be destructive.
    }
};
