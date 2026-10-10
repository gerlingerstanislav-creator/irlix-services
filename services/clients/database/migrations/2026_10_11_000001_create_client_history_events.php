<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_history_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('title', 255);
            $table->text('text')->nullable();
            $table->string('created_by_username', 255)->nullable();
            $table->timestamps();
            $table->index(['client_id','created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_history_events');
    }
};
