<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('application_gross_salary', 14, 2)->nullable();
        });
    }
    public function down(): void {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('application_gross_salary');
        });
    }
};
