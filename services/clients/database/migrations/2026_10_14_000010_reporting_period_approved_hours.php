<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('reporting_period_approved_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporting_period_id')->constrained('reporting_periods')->cascadeOnDelete();
            $table->foreignId('member_term_id')->constrained('member_terms')->cascadeOnDelete();
            $table->decimal('hours', 10, 2);
            $table->string('updated_by', 255)->nullable();
            $table->timestamps();
            $table->unique(['reporting_period_id','member_term_id'],'period_term_approved_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('reporting_period_approved_hours'); }
};
