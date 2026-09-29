<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('reporting_periods', function (Blueprint $table) {
            $table->date('timesheets_sent_at')->nullable();
            $table->date('act_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reporting_periods', function (Blueprint $table) {
            $table->dropColumn(['timesheets_sent_at', 'act_sent_at']);
        });
    }
};
