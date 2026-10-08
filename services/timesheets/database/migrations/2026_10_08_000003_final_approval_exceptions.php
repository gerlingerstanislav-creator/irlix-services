<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('final_approval_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('project_id');
            $table->date('work_date');
            $table->timestamps();
            $table->unique(['employee_id', 'project_id', 'work_date'], 'final_approval_exceptions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_approval_exceptions');
    }
};
