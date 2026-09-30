<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            // Employees owns the referenced identity; no cross-service foreign key.
            $table->unsignedBigInteger('direction_department_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('positions', fn (Blueprint $table) => $table->dropColumn('direction_department_id'));
    }
};
