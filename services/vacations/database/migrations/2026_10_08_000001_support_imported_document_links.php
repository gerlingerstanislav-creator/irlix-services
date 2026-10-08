<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('vacations.absence_attachments', function (Blueprint $table) { $table->text('external_url')->nullable(); $table->string('source_original_name')->nullable(); }); }
    public function down(): void { Schema::table('vacations.absence_attachments', fn (Blueprint $table) => $table->dropColumn(['external_url','source_original_name'])); }
};
