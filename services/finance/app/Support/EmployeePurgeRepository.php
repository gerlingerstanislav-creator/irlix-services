<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Bulk-erasure adapter. Only employee-owned accruals and their dependent links
 * are deleted. Shared company bank/cash records stay independent: deleting them
 * would destroy unrelated counterparties' ledger entries.
 */
final class EmployeePurgeRepository
{
    public function erase(int $employee, array $identities, string $fullName): void
    {
        $ids = DB::table('accruals')->where('employee_id', $employee)->pluck('id')->map(static fn ($v) => (int) $v)->all();
        $pending = $ids;
        $seen = array_fill_keys($ids, true);
        // Reversal records form a directed chain with RESTRICT FKs; collect the
        // whole employee-owned chain and remove descendants before their parents.
        $layers = [$pending];
        while ($pending !== []) {
            $next = DB::table('accruals')->whereIn('reversal_of_accrual_id', $pending)
                ->pluck('id')->map(static fn ($v) => (int) $v)
                ->filter(static fn ($v) => !isset($seen[$v]))->all();
            foreach ($next as $id) $seen[$id] = true;
            if (!$next) break;
            $layers[] = $next;
            $pending = $next;
        }
        $allIds = array_keys($seen);
        if ($allIds) {
            DB::table('accrual_payment_links')->whereIn('accrual_id', $allIds)->delete();
            DB::table('finance_audit_log')->where('entity_type', 'accrual')
                ->whereIn('entity_id', $allIds)->delete();
            // Child reversals must be removed before parent accruals.
            foreach (array_reverse($layers) as $layer) {
                DB::table('accruals')->whereIn('id', $layer)->delete();
            }
        }
        DB::table('finance_audit_log')->where('details->employee_id', $employee)->delete();

        foreach (array_unique(array_filter($identities, static fn ($v) => $v !== '')) as $identity) {
            // Non-null actor columns belong to company financial events, not to
            // the deleted identity; replace attribution with a non-personal marker.
            foreach ([
                'financial_accounts' => ['created_by'],
                'invoices' => ['created_by'],
                'cash_transactions' => ['created_by', 'confirmed_by'],
                'payment_allocations' => ['created_by'],
                'accruals' => ['created_by'],
                'budget_versions' => ['created_by', 'approved_by'],
                'accrual_payment_links' => ['created_by'],
                'finance_audit_log' => ['actor'],
            ] as $table => $columns) {
                foreach ($columns as $column) {
                    DB::table($table)->where($column, $identity)
                        ->update([$column => 'Удалённый сотрудник']);
                }
            }
            if ($fullName !== '' && $identity === $fullName) {
                DB::table('cash_transactions')->where('counterparty', $fullName)
                    ->update(['counterparty' => 'Удалённый сотрудник']);
            }
        }
    }
}
