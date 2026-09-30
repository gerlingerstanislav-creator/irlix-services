<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_clients_refresh_position_progress ON connection_attempts');
        DB::statement('DROP FUNCTION IF EXISTS clients_refresh_position_progress()');
        // Previously position closure was calculated from successful attempts, not a manager decision.
        DB::table('positions')->update(['status' => 'Открыт']);
        DB::statement("ALTER TABLE positions ALTER COLUMN status SET DEFAULT 'Открыт'");
        DB::statement("ALTER TABLE client_requests ALTER COLUMN status SET DEFAULT 'Открыт'");
    }

    public function down(): void
    {
        // Preserve explicit closures; rolling back must not resume automatic closing.
        DB::statement("ALTER TABLE positions ALTER COLUMN status SET DEFAULT 'Ждёт кандидатов'");
    }
};
