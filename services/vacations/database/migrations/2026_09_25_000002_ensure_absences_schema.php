<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $relations = DB::select(<<<'SQL'
            select n.nspname as schema_name, c.relname, c.relkind
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where c.relname = 'absences'
            order by n.nspname
        SQL);

        $target = collect($relations)->first(fn ($relation) => $relation->schema_name === 'vacations');
        if ($target !== null) {
            if (in_array($target->relkind, ['r', 'p'], true)) {
                return;
            }

            throw new \RuntimeException("vacations.absences exists with unexpected relkind {$target->relkind}; refusing destructive repair");
        }

        $legacy = collect($relations)->first(fn ($relation) => $relation->schema_name === 'public');
        if ($legacy !== null) {
            if (! in_array($legacy->relkind, ['r', 'p'], true)) {
                throw new \RuntimeException("public.absences exists with unexpected relkind {$legacy->relkind}; refusing destructive repair");
            }

            DB::statement('ALTER TABLE public.absences SET SCHEMA vacations');
            return;
        }

        Schema::create('vacations.absences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('employee_id');
            $table->string('type', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('calendar_days');
            $table->string('status', 32)->default('planned');
            $table->text('comment')->nullable();
            $table->string('created_by_subject', 64);
            $table->timestamps();
            $table->index(['employee_id', 'starts_on', 'ends_on']);
            $table->index(['status', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacations.absences');
    }
};
