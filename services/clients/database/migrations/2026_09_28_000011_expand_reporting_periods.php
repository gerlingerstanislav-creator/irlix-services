<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reporting_periods', function (Blueprint $table) {
            $table->date('timesheets_approved_at')->nullable();
            $table->date('act_approved_at')->nullable();
            $table->date('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reporting_periods', function (Blueprint $table) {
            $table->dropColumn(['timesheets_approved_at', 'act_approved_at', 'paid_at']);
        });
    }
};
