<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_history', function (Blueprint $table) {
            $table->date('effective_to')->nullable()->after('effective_from');
        });

        Schema::create('employee_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('status');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('employment_assignment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('position')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            WITH ordered AS (
                SELECT id,
                       LEAD(effective_from) OVER (PARTITION BY employee_id ORDER BY effective_from, id) AS next_from,
                       ROW_NUMBER() OVER (PARTITION BY employee_id ORDER BY effective_from DESC, id DESC) AS rn_desc
                FROM salary_history
            )
            UPDATE salary_history s
               SET effective_to = CASE WHEN o.next_from IS NULL THEN NULL ELSE (o.next_from - INTERVAL '1 day')::date END,
                   status = CASE WHEN o.rn_desc = 1 THEN 'Действует' ELSE 'Завершена' END,
                   updated_at = NOW()
              FROM ordered o
             WHERE o.id = s.id
        SQL);

        $employees = DB::table('employees')->get();
        foreach ($employees as $employee) {
            $createdDate = $employee->created_at ? substr((string) $employee->created_at, 0, 10) : now()->toDateString();
            $from = $employee->hired_at ?: $createdDate;
            DB::table('employee_status_history')->insert([
                'employee_id' => $employee->id,
                'status' => $employee->employment_status ?: 'Трудоустроен',
                'effective_from' => $from,
                'effective_to' => null,
                'reason' => 'Initial migration',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('employment_assignment_history')->insert([
                'employee_id' => $employee->id,
                'department_id' => $employee->department_id,
                'position' => $employee->position,
                'effective_from' => $from,
                'effective_to' => $employee->employment_status === 'Уволен' ? ($employee->fired_at ?: $createdDate) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::statement('CREATE UNIQUE INDEX employment_periods_one_open_per_employee ON employment_periods (employee_id) WHERE ended_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX employee_status_history_one_open_per_employee ON employee_status_history (employee_id) WHERE effective_to IS NULL');
        DB::statement('CREATE UNIQUE INDEX employment_assignment_history_one_open_per_employee ON employment_assignment_history (employee_id) WHERE effective_to IS NULL');
        DB::statement('CREATE UNIQUE INDEX salary_history_one_active_per_employee ON salary_history (employee_id) WHERE effective_to IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS salary_history_one_active_per_employee');
        DB::statement('DROP INDEX IF EXISTS employment_assignment_history_one_open_per_employee');
        DB::statement('DROP INDEX IF EXISTS employee_status_history_one_open_per_employee');
        DB::statement('DROP INDEX IF EXISTS employment_periods_one_open_per_employee');
        Schema::dropIfExists('employment_assignment_history');
        Schema::dropIfExists('employee_status_history');
        Schema::table('salary_history', function (Blueprint $table) {
            $table->dropColumn('effective_to');
        });
    }
};
