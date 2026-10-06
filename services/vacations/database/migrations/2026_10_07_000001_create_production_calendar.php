<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('production_calendar_years', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->string('source', 64)->default('isdayoff');
            $table->string('state', 32)->default('unknown');
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('synced_at')->nullable();
            $table->text('last_error')->nullable();
        });

        Schema::create('production_calendar_days', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->unsignedSmallInteger('year')->index();
            $table->unsignedSmallInteger('code');
            $table->boolean('is_working');
            $table->boolean('is_day_off');
            $table->boolean('is_holiday');
            $table->boolean('is_short');
            $table->string('source', 64)->default('isdayoff');
            $table->timestampTz('synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_calendar_days');
        Schema::dropIfExists('production_calendar_years');
    }
};
