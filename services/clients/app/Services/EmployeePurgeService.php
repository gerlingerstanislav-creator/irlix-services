<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMember;
use App\Models\LegacyProjectMember;
use App\Models\ConnectionAttempt;
use App\Models\Lead;
use App\Models\ClientRequest;
use App\Models\Position;
use App\Models\ClientContourPermissionAudit;
use App\Models\ProjectMemberFeedback;
use Illuminate\Support\Facades\DB;

final class EmployeePurgeService
{
    /** Do not erase canonical, historical client memberships. */
    public function purge(int $employeeId, string $username): void
    {
        DB::transaction(static function () use ($employeeId, $username): void {
            abort_if(ClientMember::query()->where('employee_id', $employeeId)->exists(), 409,
                'Employee has retained client history and cannot be purged.');

            LegacyProjectMember::query()->where('specialist_id', $employeeId)->delete();
            ConnectionAttempt::query()->where('specialist_id', $employeeId)->delete();

            Client::query()->where('sales_employee_id', $employeeId)->update(['sales_employee_id' => null]);
            Client::query()->where('account_employee_id', $employeeId)->update(['account_employee_id' => null]);
            Position::query()->where('responsible_rn_employee_id', $employeeId)->update(['responsible_rn_employee_id' => null]);
            Lead::query()->where('responsible_employee_id', $employeeId)->update(['responsible_employee_id' => null]);
            ClientRequest::query()->where('responsible_employee_id', $employeeId)->update(['responsible_employee_id' => null]);
            ConnectionAttempt::query()->where('responsible_employee_id', $employeeId)->update(['responsible_employee_id' => null]);
            ClientContourPermissionAudit::query()->where('actor_employee_id', $employeeId)->update(['actor_employee_id' => null]);

            if ($username !== '') {
                ProjectMemberFeedback::query()->where('created_by_username', $username)
                    ->update(['created_by_username' => null]);
            }
        });
    }
}
