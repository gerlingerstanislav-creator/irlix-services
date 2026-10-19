<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_reviews', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('created_by');
            $table->date('effective_month');
            $table->decimal('gross_salary', 15, 2);
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('cycle')->default(0);
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'effective_month']);
            $table->index(['status', 'effective_month']);
        });

        \Illuminate\Support\Facades\DB::statement("CREATE UNIQUE INDEX salary_reviews_employee_month_active ON salary_reviews (employee_id, effective_month) WHERE status <> 'cancelled'");

        Schema::create('salary_review_approvals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('review_id');
            $table->unsignedInteger('cycle');
            $table->unsignedInteger('step');
            $table->unsignedBigInteger('approver_id');
            $table->string('decision', 24)->default('waiting');
            $table->text('comment')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
            $table->unique(['review_id', 'cycle', 'step']);
            $table->index(['approver_id', 'decision']);
        });
        Schema::create('salary_review_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('review_id');
            $table->date('effective_month');
            $table->decimal('gross_salary', 15, 2);
            $table->string('status', 24);
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('changed_by');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['review_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_review_versions');
        Schema::dropIfExists('salary_review_approvals');
        Schema::dropIfExists('salary_reviews');
    }
};
