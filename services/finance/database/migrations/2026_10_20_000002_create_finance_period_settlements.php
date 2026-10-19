<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('finance_period_settlements', function(Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('reporting_period_id')->unique();
            $t->enum('status',['open','pending','synced'])->default('open');
            $t->unsignedBigInteger('ledger_version')->default(1);
            $t->uuid('evidence_id')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('finance_period_settlements'); }
};
