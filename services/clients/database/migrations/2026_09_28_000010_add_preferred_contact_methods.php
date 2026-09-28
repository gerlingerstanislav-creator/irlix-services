<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contact_methods', function (Blueprint $table) {
            $table->boolean('is_preferred')->default(false);
            $table->index(['contact_person_id', 'is_preferred']);
        });
    }

    public function down(): void
    {
        Schema::table('contact_methods', function (Blueprint $table) {
            $table->dropIndex(['contact_person_id', 'is_preferred']);
            $table->dropColumn('is_preferred');
        });
    }
};
