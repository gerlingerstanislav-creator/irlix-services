<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('migration_connections', function (Blueprint $table): void {
            $table->text('legacy_app_key_encrypted')->nullable();
            $table->string('legacy_cipher', 32)->default('AES-256-CBC');
        });
    }
    public function down(): void
    {
        Schema::table('migration_connections', fn (Blueprint $table) => $table->dropColumn(['legacy_app_key_encrypted', 'legacy_cipher']));
    }
};
