<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('reporting_period_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporting_period_id')->constrained('reporting_periods')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('title', 255);
            $table->text('text')->nullable();
            $table->string('author', 255)->nullable();
            $table->timestamps();
            $table->index(['reporting_period_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('reporting_period_history'); }
};
