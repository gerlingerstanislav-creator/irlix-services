<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('act_approval_day_type', 16)->default('working');
            $table->string('payment_day_type', 16)->default('working');
        });
        DB::table('clients')->update([
            'act_approval_day_type' => 'working',
            'payment_day_type' => 'working',
        ]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn(['act_approval_day_type', 'payment_day_type']);
        });
    }
};
