<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_positions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('base_salary', 14, 2)->nullable();
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('position')->constrained('staff_positions')->nullOnDelete();
        });

        $positions = DB::table('employees')
            ->whereNotNull('position')
            ->whereRaw("btrim(position) <> ''")
            ->selectRaw('DISTINCT btrim(position) as name')
            ->orderBy('name')
            ->pluck('name');

        foreach ($positions as $name) {
            DB::table('staff_positions')->insertOrIgnore([
                'name' => $name,
                'base_salary' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::statement(<<<'SQL'
            UPDATE employees e
               SET position_id = p.id
              FROM staff_positions p
             WHERE e.position IS NOT NULL
               AND btrim(e.position) = p.name
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sync_employee_staff_position_id()
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

            CREATE TRIGGER employees_sync_staff_position_id
            BEFORE INSERT OR UPDATE OF position ON employees
            FOR EACH ROW
            EXECUTE FUNCTION sync_employee_staff_position_id();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS employees_sync_staff_position_id ON employees;');
        DB::unprepared('DROP FUNCTION IF EXISTS sync_employee_staff_position_id();');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });

        Schema::dropIfExists('staff_positions');
    }
};
