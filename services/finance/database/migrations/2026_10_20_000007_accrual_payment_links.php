<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('accrual_payment_links', function(Blueprint $t) {
            $t->id();
            $t->foreignId('accrual_id')->constrained('accruals')->restrictOnDelete();
            $t->foreignId('cash_transaction_id')->constrained('cash_transactions')->restrictOnDelete();
            $t->decimal('amount',17,2);
            $t->string('created_by',150);
            $t->timestamps();
            $t->unique(['accrual_id','cash_transaction_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('accrual_payment_links');
    }
};
