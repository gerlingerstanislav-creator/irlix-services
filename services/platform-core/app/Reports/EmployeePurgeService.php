<?php

namespace App\Reports;

use App\Models\BugReport;
use Illuminate\Support\Facades\DB;

/** Bug reports are employee-authored objects; remove them and foreign reviewer IDs. */
final class EmployeePurgeService
{
    public function purge(int $employee): void
    {
        DB::transaction(static function () use ($employee): void {
            BugReport::query()->where('employee_id', $employee)->delete();
            BugReport::query()->where('closed_by_employee_id', $employee)
                ->update(['closed_by_employee_id' => null]);
        });
    }
}
