<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // Drafts may have temporary duplicate numbers, but two issued invoices
        // must not have the same real accounting number for this legal entity.
        DB::statement("CREATE UNIQUE INDEX finance_issued_invoice_number_unique
            ON invoices(number)
            WHERE document_status = 'issued' AND number IS NOT NULL AND number <> ''");
    }
    public function down(): void {
        DB::statement('DROP INDEX IF EXISTS finance_issued_invoice_number_unique');
    }
};
