<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('migration_control_write_intents', function (Blueprint $table): void {
            $table->id();
            $table->string('service'); $table->string('entity_type'); $table->string('legacy_id');
            $table->unsignedBigInteger('migration_run_id');
            $table->timestamps();
            $table->unique(['service','entity_type','legacy_id'],'control_write_intent_identity');
        });
    }
    public function down(): void { Schema::dropIfExists('migration_control_write_intents'); }
};
