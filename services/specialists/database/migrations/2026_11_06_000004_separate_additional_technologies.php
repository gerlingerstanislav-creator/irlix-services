<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Main technologies remain in technologies/specialist_technologies.
        // Additional technologies have their own catalog and membership keys:
        // the same name may validly be main for one worker and extra for another.
        Schema::create('additional_technologies', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('origin_technology_id')->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('specialist_additional_technologies', static function (Blueprint $table): void {
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('additional_technology_id');
            $table->timestamps();
            $table->primary(['employee_id', 'additional_technology_id'], 'specialist_additional_technologies_pk');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX additional_technologies_normalized_name_unique ON additional_technologies (LOWER(BTRIM(name)))');
        }

        // Historical links were ALL marked secondary by the graded-profile
        // migration unless a user explicitly converted them to primary.
        // Copy only secondary usages; unassigned/main-only names stay in the
        // main catalog. Preserve old timestamps, identities and assignments.
        $old = DB::table('specialist_technologies as st')
            ->join('technologies as t', 't.id', '=', 'st.technology_id')
            ->where(static fn ($query) => $query->where('st.kind', 'secondary')->orWhereNull('st.kind'))
            ->select(['st.employee_id', 'st.technology_id', 'st.created_at', 'st.updated_at',
                't.name', 't.active'])
            ->orderBy('st.technology_id')->get();

        $mapping = [];
        foreach ($old as $row) {
            $oldId = (int) $row->technology_id;
            if (!isset($mapping[$oldId])) {
                $mapping[$oldId] = DB::table('additional_technologies')->insertGetId([
                    'name' => $row->name, 'origin_technology_id' => $oldId,
                    'active' => (bool) $row->active,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('specialist_additional_technologies')->insert([
                'employee_id' => (int) $row->employee_id,
                'additional_technology_id' => $mapping[$oldId],
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }

        // Once every secondary assignment is migrated successfully it ceases
        // to be stored in the main association table (no hidden double source).
        DB::table('specialist_technologies')
            ->where(static fn ($query) => $query->where('kind', 'secondary')->orWhereNull('kind'))
            ->delete();
    }

    public function down(): void
    {
        // Restore every extra association including names added after cutover.
        // Refuse any lossy rollback rather than overwrite a primary association
        // when an employee intentionally has both roles for the same name.
        $rows = DB::table('specialist_additional_technologies as st')
            ->join('additional_technologies as t', 't.id', '=', 'st.additional_technology_id')
            ->select(['st.employee_id', 'st.created_at', 'st.updated_at',
                't.name', 't.origin_technology_id'])
            ->get();
        foreach ($rows as $row) {
            $source = $row->origin_technology_id;
            if (!$source || !DB::table('technologies')->where('id', $source)->exists()) {
                $source = DB::table('technologies')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($row->name)])->value('id');
                if (!$source) $source = DB::table('technologies')->insertGetId([
                    'name' => $row->name, 'active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $existing = DB::table('specialist_technologies')
                ->where('employee_id', $row->employee_id)
                ->where('technology_id', $source)->first();
            if ($existing && $existing->kind === 'primary') {
                throw new RuntimeException('Unsafe rollback: a technology is both main and additional for the same employee.');
            }
            if (!$existing) DB::table('specialist_technologies')->insert([
                'employee_id' => $row->employee_id, 'technology_id' => $source,
                'kind' => 'secondary', 'grade' => null,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }
        Schema::dropIfExists('specialist_additional_technologies');
        Schema::dropIfExists('additional_technologies');
    }
};
