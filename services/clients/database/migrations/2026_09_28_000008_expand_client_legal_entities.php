<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_legal_entities', function (Blueprint $table) {
            $table->string('full_name')->nullable()->after('name');
            $table->string('ogrn', 32)->nullable()->after('inn');
            $table->string('kpp', 32)->nullable()->after('ogrn');
            $table->date('registration_date')->nullable()->after('kpp');
            $table->string('okpo', 32)->nullable()->after('registration_date');
            $table->string('oktmo', 32)->nullable()->after('okpo');
            $table->text('address')->nullable()->after('oktmo');
        });

        // Existing demo rows predate the full entity shape. Keep them valid without
        // importing any values from UI reference screenshots.
        DB::table('client_legal_entities')
            ->whereNull('full_name')
            ->update(['full_name' => DB::raw('name')]);
    }

    public function down(): void
    {
        Schema::table('client_legal_entities', function (Blueprint $table) {
            $table->dropColumn(['full_name', 'ogrn', 'kpp', 'registration_date', 'okpo', 'oktmo', 'address']);
        });
    }
};
