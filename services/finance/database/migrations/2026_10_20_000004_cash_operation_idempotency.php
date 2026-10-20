<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::table('cash_transactions',function(Blueprint $t){
            $t->uuid('request_id')->nullable()->unique();
        });
        // Bank references are account- and direction-scoped; nulls are allowed.
        // The partial unique index closes the race between duplicate lookup and insert.
        DB::statement("CREATE UNIQUE INDEX finance_cash_bank_reference_unique ON cash_transactions
            (financial_account_id,direction,reference) WHERE reference IS NOT NULL AND reference <> ''");
    }
    public function down(): void {
        DB::statement('DROP INDEX IF EXISTS finance_cash_bank_reference_unique');
        Schema::table('cash_transactions',function(Blueprint $t){
            $t->dropUnique(['request_id']);
            $t->dropColumn('request_id');
        });
    }
};
