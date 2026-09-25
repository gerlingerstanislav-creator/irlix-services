<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
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
