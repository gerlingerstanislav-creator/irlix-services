<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('equipment_items', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_number', 100)->unique();
            $table->string('type', 32);
            $table->string('manufacturer', 160);
            $table->string('model', 160);
            $table->string('serial_number', 200)->nullable()->index();
            $table->string('purpose', 32);
            $table->string('condition', 32)->default('ok');
            $table->text('comment')->nullable();
            $table->date('purchased_on')->nullable();
            $table->decimal('purchase_cost', 14, 2)->nullable();
            $table->unsignedInteger('useful_life_months')->nullable();
            $table->string('cpu', 200)->nullable();
            $table->string('ram', 100)->nullable();
            $table->string('storage', 200)->nullable();
            $table->string('gpu', 200)->nullable();
            $table->string('os', 100)->nullable();
            $table->string('os_version', 100)->nullable();
            $table->string('imei', 32)->nullable()->index();
            $table->date('written_off_at')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('equipment_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_item_id')->constrained('equipment_items');
            $table->unsignedBigInteger('employee_id')->index();
            $table->date('starts_on');
            $table->date('planned_ends_on')->nullable();
            $table->date('returned_on')->nullable()->index();
            $table->string('issued_by', 200);
            $table->string('returned_by', 200)->nullable();
            $table->text('issue_comment')->nullable();
            $table->text('return_comment')->nullable();
            $table->timestamps();
        });
        Schema::create('equipment_write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_item_id')->unique()->constrained('equipment_items');
            $table->date('written_off_on');
            $table->string('reason', 500);
            $table->text('comment')->nullable();
            $table->string('actor', 200);
            $table->decimal('residual_value', 14, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('equipment_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_item_id')->nullable()->constrained('equipment_items');
            $table->string('event', 64);
            $table->string('actor', 200);
            $table->jsonb('payload')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('equipment_audit_log');
        Schema::dropIfExists('equipment_write_offs');
        Schema::dropIfExists('equipment_assignments');
        Schema::dropIfExists('equipment_items');
    }
};
