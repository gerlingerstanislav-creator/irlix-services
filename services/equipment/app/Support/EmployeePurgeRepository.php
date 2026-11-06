<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Infrastructure bulk-erasure adapter. Equipment asset records belong to the
 * company; deleting a person removes their assignments and identifying actor
 * references, not the inventory, damage or write-off of other employees.
 */
final class EmployeePurgeRepository
{
    public function erase(int $employeeId, array $identities): void
    {
        DB::table('equipment_assignments')->where('employee_id', $employeeId)->delete();

        // Audit rows about the erased employee (including assignment details)
        // must not retain the old external employee ID inside JSON payload.
        DB::table('equipment_audit_log')->where('payload->employee_id', $employeeId)->delete();

        foreach (array_unique(array_filter($identities, static fn ($value) => $value !== '')) as $identity) {
            DB::table('equipment_audit_log')->where('actor', $identity)->delete();
            DB::table('equipment_permission_audit')->where('actor', $identity)->delete();
            foreach ([
                'equipment_assignments' => ['issued_by', 'returned_by'],
                'equipment_write_offs' => ['actor'],
                'equipment_damages' => ['actor'],
            ] as $table => $columns) {
                foreach ($columns as $column) {
                    DB::table($table)->where($column, $identity)->update([$column => 'Удалённый сотрудник']);
                }
            }
        }
    }
}
