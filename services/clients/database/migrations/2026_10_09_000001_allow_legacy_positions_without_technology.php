<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy positions may have no technology. API creation still requires one.
        Schema::table('positions', fn (Blueprint $table) => $table->string('technology')->nullable()->change());
    }

    public function down(): void
    {
        // Keep imported NULL values; reverting must not invent or delete technologies.
    }
};
