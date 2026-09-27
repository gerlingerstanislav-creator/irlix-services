<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('act_approval_days')->nullable();
            $table->unsignedSmallInteger('payment_days')->nullable();
            $table->json('technologies')->nullable();
        });

        Schema::create('client_legal_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name');
            $table->string('inn', 32)->nullable();
            $table->timestamps();
            $table->index('client_id');
        });

        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->text('text');
            $table->string('created_by_username')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_notes');
        Schema::dropIfExists('client_legal_entities');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['description', 'act_approval_days', 'payment_days', 'technologies']);
        });
    }
};
