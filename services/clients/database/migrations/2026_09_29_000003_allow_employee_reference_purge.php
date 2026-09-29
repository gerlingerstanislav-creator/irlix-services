<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE clients ALTER COLUMN account_employee_id DROP NOT NULL');
        DB::statement('ALTER TABLE leads ALTER COLUMN responsible_employee_id DROP NOT NULL');
        DB::statement('ALTER TABLE client_requests ALTER COLUMN responsible_employee_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE clients ALTER COLUMN account_employee_id SET NOT NULL');
        DB::statement('ALTER TABLE leads ALTER COLUMN responsible_employee_id SET NOT NULL');
        DB::statement('ALTER TABLE client_requests ALTER COLUMN responsible_employee_id SET NOT NULL');
    }
};
