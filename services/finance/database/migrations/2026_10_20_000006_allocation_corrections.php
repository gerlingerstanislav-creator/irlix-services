<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('payment_allocations',function(Blueprint $t) {
            $t->timestamp('reversed_at')->nullable();
            $t->string('reversed_by',150)->nullable();
            $t->string('reversal_reason',500)->nullable();
        });
        Schema::table('payment_allocations',function(Blueprint $t){
            $t->dropUnique(['cash_transaction_id','invoice_id']);
        });
        DB::statement("CREATE UNIQUE INDEX finance_active_payment_allocation_unique
            ON payment_allocations(cash_transaction_id,invoice_id)
            WHERE reversed_at IS NULL");
    }

    public function down(): void {
        DB::statement('DROP INDEX IF EXISTS finance_active_payment_allocation_unique');
        Schema::table('payment_allocations',function(Blueprint $t){
            $t->unique(['cash_transaction_id','invoice_id']);
        });
        Schema::table('payment_allocations',function(Blueprint $t){
            $t->dropColumn(['reversed_at','reversed_by','reversal_reason']);
        });
    }
};
