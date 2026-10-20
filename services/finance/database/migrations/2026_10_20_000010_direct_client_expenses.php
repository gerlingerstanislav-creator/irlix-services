<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('accruals',function(Blueprint $table){
            // External IDs only: Clients owns the client and project catalogs.
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->string('direct_cost_reason',500)->nullable();
        });
    }
    public function down(): void {
        Schema::table('accruals',function(Blueprint $table){
            $table->dropIndex(['client_id']);
            $table->dropIndex(['project_id']);
            $table->dropColumn(['client_id','project_id','direct_cost_reason']);
        });
    }
};
