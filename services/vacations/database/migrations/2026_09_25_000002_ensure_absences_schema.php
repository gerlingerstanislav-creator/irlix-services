<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $target = DB::selectOne("select to_regclass('vacations.absences') as relation")?->relation;
        if ($target === 'vacations.absences') {
            return;
        }

        $legacy = DB::selectOne("select to_regclass('public.absences') as relation")?->relation;
        if ($legacy === 'absences' || $legacy === 'public.absences') {
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
