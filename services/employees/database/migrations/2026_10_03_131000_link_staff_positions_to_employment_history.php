<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_periods', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('position')->constrained('staff_positions')->restrictOnDelete();
        });

        Schema::table('employment_assignment_history', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('position')->constrained('staff_positions')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE employment_periods h
               SET position_id = p.id
              FROM staff_positions p
             WHERE h.position_id IS NULL
               AND h.position IS NOT NULL
               AND btrim(h.position) = p.name
        SQL);

        DB::statement(<<<'SQL'
            UPDATE employment_assignment_history h
               SET position_id = p.id
              FROM staff_positions p
             WHERE h.position_id IS NULL
               AND h.position IS NOT NULL
               AND btrim(h.position) = p.name
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sync_history_staff_position_id()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.position IS NULL OR btrim(NEW.position) = '' THEN
                    NEW.position_id := NULL;
                ELSE
                    SELECT id INTO NEW.position_id
                      FROM staff_positions
                     WHERE name = btrim(NEW.position)
                     LIMIT 1;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER employment_periods_sync_staff_position_id
            BEFORE INSERT OR UPDATE OF position ON employment_periods
            FOR EACH ROW
            EXECUTE FUNCTION sync_history_staff_position_id();

            CREATE TRIGGER assignment_history_sync_staff_position_id
            BEFORE INSERT OR UPDATE OF position ON employment_assignment_history
            FOR EACH ROW
            EXECUTE FUNCTION sync_history_staff_position_id();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS assignment_history_sync_staff_position_id ON employment_assignment_history;');
        DB::unprepared('DROP TRIGGER IF EXISTS employment_periods_sync_staff_position_id ON employment_periods;');
        DB::unprepared('DROP FUNCTION IF EXISTS sync_history_staff_position_id();');

        Schema::table('employment_assignment_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });
        Schema::table('employment_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });
    }
};
