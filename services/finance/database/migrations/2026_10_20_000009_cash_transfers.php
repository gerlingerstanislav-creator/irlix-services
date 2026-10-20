<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('cash_transfers',function(Blueprint $t){
            $t->id();
            $t->uuid('request_id')->unique();
            $t->foreignId('source_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $t->foreignId('destination_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $t->date('occurred_on');
            $t->decimal('amount',17,2);
            $t->string('created_by',150);
            $t->timestamps();
        });
        Schema::table('cash_transactions',function(Blueprint $t) {
            $t->string('flow_type',20)->default('operating')->index();
            $t->foreignId('internal_transfer_id')->nullable()
                ->constrained('cash_transfers')->restrictOnDelete();
        });
    }
    public function down(): void {
        Schema::table('cash_transactions',function(Blueprint $t) {
            $t->dropForeign(['internal_transfer_id']);
            $t->dropColumn(['flow_type','internal_transfer_id']);
        });
        Schema::dropIfExists('cash_transfers');
    }
};
