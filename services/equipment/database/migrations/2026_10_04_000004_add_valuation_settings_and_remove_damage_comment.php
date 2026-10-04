<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('equipment_settings', function (Blueprint $table) {
            $table->id();
            $table->string('equipment_type', 32)->unique();
            $table->unsignedInteger('useful_life_months');
            $table->timestamps();
        });

        $now = now();
        DB::table('equipment_settings')->insert([
            ['equipment_type' => 'pc', 'useful_life_months' => 48, 'created_at' => $now, 'updated_at' => $now],
            ['equipment_type' => 'laptop', 'useful_life_months' => 36, 'created_at' => $now, 'updated_at' => $now],
            ['equipment_type' => 'smartphone', 'useful_life_months' => 36, 'created_at' => $now, 'updated_at' => $now],
            ['equipment_type' => 'tablet', 'useful_life_months' => 36, 'created_at' => $now, 'updated_at' => $now],
        ]);

        if (Schema::hasColumn('equipment_damages', 'comment')) {
            Schema::table('equipment_damages', function (Blueprint $table) {
                $table->dropColumn('comment');
            });
        }
    }

    public function down(): void {
        if (!Schema::hasColumn('equipment_damages', 'comment')) {
            Schema::table('equipment_damages', function (Blueprint $table) {
                $table->text('comment')->nullable();
            });
        }
        Schema::dropIfExists('equipment_settings');
    }
};
