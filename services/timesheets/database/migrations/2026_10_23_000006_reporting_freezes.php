<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reporting_freezes', function (Blueprint $t) {
            $t->unsignedBigInteger('reporting_period_id')->primary();
            $t->unsignedBigInteger('client_id');
            $t->date('period_start');
            $t->date('period_end');
            $t->string('freeze_token', 64)->unique();
            $t->string('revision', 64);
            $t->timestamps();
            $t->index(['client_id', 'period_start', 'period_end']);
        });
    }
    public function down(): void
    {
        if (DB::table('reporting_freezes')->exists()) throw new RuntimeException('Active reporting freezes must be released before rollback');
        Schema::dropIfExists('reporting_freezes');
    }
};
