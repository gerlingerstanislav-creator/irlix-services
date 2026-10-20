<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('budget_versions',function(Blueprint $table){
            $table->uuid('plan_key')->nullable()->index();
            $table->string('first_month',7)->nullable();
            $table->string('last_month',7)->nullable();
            $table->unique(['plan_key','revision']);
        });
    }
    public function down(): void {
        Schema::table('budget_versions',function(Blueprint $table){
            $table->dropUnique(['plan_key','revision']);
            $table->dropIndex(['plan_key']);
            $table->dropColumn(['plan_key','first_month','last_month']);
        });
    }
};
