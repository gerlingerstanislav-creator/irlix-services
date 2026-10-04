<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('equipment_assignments', function (Blueprint $table) {
            $table->dropColumn('planned_ends_on');
        });

        Schema::create('equipment_damages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_item_id')->constrained('equipment_items')->cascadeOnDelete();
            $table->date('occurred_on')->index();
            $table->text('description');
            $table->decimal('repair_cost', 14, 2)->default(0);
            $table->date('repaired_on')->nullable()->index();
            $table->decimal('value_loss', 14, 2)->default(0);
            $table->text('comment')->nullable();
            $table->string('actor', 200);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_damages');
        Schema::table('equipment_assignments', function (Blueprint $table) {
            $table->date('planned_ends_on')->nullable();
        });
    }
};