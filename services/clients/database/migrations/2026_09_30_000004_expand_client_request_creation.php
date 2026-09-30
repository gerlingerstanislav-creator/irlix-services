<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_requests', function (Blueprint $table): void {
            $table->date('request_date')->nullable()->after('responsible_employee_id');
            $table->unsignedSmallInteger('lifetime_weeks')->nullable()->after('request_date');
        });

        DB::statement("UPDATE client_requests SET request_date = created_at::date WHERE request_date IS NULL");
        DB::statement("UPDATE client_requests SET lifetime_weeks = LEAST(4, GREATEST(1, CEIL(GREATEST(1, deadline - request_date) / 7.0)::int)) WHERE lifetime_weeks IS NULL AND deadline IS NOT NULL");
        DB::statement("UPDATE client_requests SET lifetime_weeks = 1 WHERE lifetime_weeks IS NULL");
        DB::statement('ALTER TABLE client_requests ALTER COLUMN request_date SET NOT NULL');
        DB::statement('ALTER TABLE client_requests ALTER COLUMN lifetime_weeks SET NOT NULL');
        DB::statement('ALTER TABLE client_requests ALTER COLUMN lifetime_weeks SET DEFAULT 1');
    }

    public function down(): void
    {
        Schema::table('client_requests', function (Blueprint $table): void {
            $table->dropColumn(['request_date', 'lifetime_weeks']);
        });
    }
};
