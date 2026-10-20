<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('accruals', function(Blueprint $t) {
            $t->unsignedBigInteger('reversal_of_accrual_id')->nullable()->unique();
            $t->foreign('reversal_of_accrual_id')->references('id')->on('accruals')->restrictOnDelete();
            $t->string('correction_reason',500)->nullable();
        });
    }
    public function down(): void {
        Schema::table('accruals',function(Blueprint $t) {
            $t->dropForeign(['reversal_of_accrual_id']);
            $t->dropUnique(['reversal_of_accrual_id']);
            $t->dropColumn(['reversal_of_accrual_id','correction_reason']);
        });
    }
};
