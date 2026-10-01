<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE clients ALTER COLUMN account_employee_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE clients SET account_employee_id = sales_employee_id WHERE account_employee_id IS NULL AND sales_employee_id IS NOT NULL');
        DB::statement('ALTER TABLE clients ALTER COLUMN account_employee_id SET NOT NULL');
    }
};
