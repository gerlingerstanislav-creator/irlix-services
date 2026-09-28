<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contact_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_person_id')->constrained('contact_people')->cascadeOnDelete();
            $table->string('type', 100);
            $table->string('contact', 500);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['contact_person_id', 'is_active']);
        });

        DB::table('contact_people')->orderBy('id')->each(function ($person) {
            if (!empty($person->phone)) {
                DB::table('contact_methods')->insert([
                    'contact_person_id' => $person->id,
                    'type' => 'Телефон',
                    'contact' => $person->phone,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            if (!empty($person->email)) {
                DB::table('contact_methods')->insert([
                    'contact_person_id' => $person->id,
                    'type' => 'Email',
                    'contact' => $person->email,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_methods');
    }
};
