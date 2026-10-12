<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // NULL is an unknown legacy amount, never a free/zero-rate connection.
        // Business APIs continue to require a numeric rate for newly created conditions.
        DB::statement('ALTER TABLE member_terms ALTER COLUMN hourly_rate DROP NOT NULL');
        // Legacy technology/grade are nullable; preserve absence without inventing catalog values.
        DB::statement('ALTER TABLE member_terms ALTER COLUMN technology DROP NOT NULL');
        DB::statement('ALTER TABLE member_terms ALTER COLUMN level DROP NOT NULL');
    }

    public function down(): void
    {
        // Refuse rollback until operators have supplied every unknown amount.
        DB::statement('ALTER TABLE member_terms ALTER COLUMN hourly_rate SET NOT NULL');
        DB::statement('ALTER TABLE member_terms ALTER COLUMN technology SET NOT NULL');
        DB::statement('ALTER TABLE member_terms ALTER COLUMN level SET NOT NULL');
    }
};
