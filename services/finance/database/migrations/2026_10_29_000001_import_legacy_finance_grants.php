<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Copy the non-administrative base grants from EmployeesAccess::result.
 * Employees' current service admission and individual manual finance denials
 * are additionally enforced at request time; this seed alone grants nothing.
 * Existing custom V2 rows are NEVER overwritten.
 */
return new class extends Migration {
    public function up(): void
    {
        $all = [
            'finance.view', 'finance.salary.view', 'finance.invoices.manage',
            'finance.cash.confirm', 'finance.expenses.manage',
            'finance.accounts.manage', 'finance.planning.manage',
        ];
        DB::transaction(static function () use ($all): void {
            foreach ($all as $permission) {
                DB::table('finance_role_permissions')->insertOrIgnore([
                    'role'=>'org:accounting','permission'=>$permission,
                    'allowed'=>true,'scope'=>'all',
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            DB::table('finance_role_permissions')->insertOrIgnore([
                'role'=>'head:accounting','permission'=>'finance.planning.approve',
                'allowed'=>true,'scope'=>'all',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        });
    }

    public function down(): void
    {
        // Do not delete records that may subsequently be edited by an admin.
        // Restore from the protected schema backup if a rollback is required.
    }
};
