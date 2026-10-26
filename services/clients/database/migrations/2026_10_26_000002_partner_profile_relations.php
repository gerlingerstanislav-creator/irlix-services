<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('partner_contact_relations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('partner_id')->constrained('partners')->restrictOnDelete();
            $t->foreignId('contact_person_id')->constrained('contact_people')->restrictOnDelete();
            $t->string('position')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['partner_id', 'contact_person_id']);
        });

        Schema::create('partner_legal_entities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('partner_id')->constrained('partners')->restrictOnDelete();
            $t->string('name');
            $t->string('inn', 32);
            $t->string('full_name', 500);
            $t->string('ogrn', 32)->nullable();
            $t->string('kpp', 32)->nullable();
            $t->date('registration_date')->nullable();
            $t->string('okpo', 32)->nullable();
            $t->string('oktmo', 32)->nullable();
            $t->text('address')->nullable();
            $t->timestamps();
            $t->index(['partner_id', 'name']);
        });

        Schema::create('partner_history_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('partner_id')->constrained('partners')->restrictOnDelete();
            $t->string('type', 100);
            $t->string('title', 255);
            $t->text('text')->nullable();
            $t->string('created_by_username', 255)->nullable();
            $t->timestamps();
            $t->index(['partner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_history_events');
        Schema::dropIfExists('partner_legal_entities');
        Schema::dropIfExists('partner_contact_relations');
    }
};
