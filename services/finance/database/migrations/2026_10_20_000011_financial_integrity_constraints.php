<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Last line of defense for financial writes coming from application code,
// direct migrations, or future external imports: no zero or negative "cash
// paid" rows, no over-VAT invoices, and no fake self-transfers.
return new class extends Migration {
    private const RULES = [
        'invoices' => [
            'finance_invoice_amount_positive' => 'amount > 0',
            'finance_invoice_vat_bounds' => 'vat_amount >= 0 AND vat_amount <= amount',
            'finance_invoice_document_status' => "document_status IN ('draft','issued','cancelled')",
        ],
        'cash_transactions' => [
            'finance_cash_amount_positive' => 'amount > 0',
            'finance_cash_direction_valid' => "direction IN ('in','out')",
            'finance_cash_flow_type_valid' => "flow_type IN ('operating','investing','financing','internal_transfer')",
        ],
        'payment_allocations' => [
            'finance_payment_allocation_positive' => 'amount > 0',
        ],
        'accrual_payment_links' => [
            'finance_accrual_link_positive' => 'amount > 0',
        ],
        'cash_transfers' => [
            'finance_transfer_positive' => 'amount > 0',
            'finance_transfer_distinct_accounts' => 'source_account_id <> destination_account_id',
        ],
    ];
    public function up(): void {
        foreach(self::RULES as $table=>$rules) {
            foreach($rules as $name=>$expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
            }
        }
    }
    public function down(): void {
        foreach(self::RULES as $table=>$rules) {
            foreach($rules as $name=>$expression) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }
    }
};
