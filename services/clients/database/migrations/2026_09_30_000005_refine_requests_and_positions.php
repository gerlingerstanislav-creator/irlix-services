<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->string('expected_connection_time', 32)->default('Неизвестно')->after('quantity');
            $table->string('acceptable_tu_format', 32)->default('Не важно')->after('expected_connection_time');
        });

        DB::statement("UPDATE client_requests SET status = CASE WHEN status IN ('Закрыт: успех', 'Закрыт: неудача') THEN 'Закрыт' ELSE 'Открыт' END");
        DB::statement("ALTER TABLE client_requests ALTER COLUMN status SET DEFAULT 'Открыт'");
    }

    public function down(): void
    {
        DB::statement("UPDATE client_requests SET status = CASE WHEN status = 'Закрыт' THEN 'Закрыт: успех' ELSE 'Новый' END");
        DB::statement("ALTER TABLE client_requests ALTER COLUMN status SET DEFAULT 'Новый'");

        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn(['expected_connection_time', 'acceptable_tu_format']);
        });
    }
};
