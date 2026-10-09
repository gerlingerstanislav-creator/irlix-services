<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('vacations.absence_approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('acted_by_employee_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vacations.absence_approvals', function (Blueprint $table) {
            $table->dropColumn('acted_by_employee_id');
        });
    }
};
