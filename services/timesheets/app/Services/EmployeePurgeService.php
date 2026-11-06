<?php
namespace App\Services;

use App\Models\EmployeeConfirmation;
use App\Models\FinalApproval;
use App\Models\FinalApprovalException;
use App\Models\PartnerDailyConfirmation;
use App\Models\TimesheetLegacySource;
use App\Models\PeriodLock;
use App\Models\ReportingFreezeRecord;
use App\Models\TimesheetAudit;
use App\Models\TimesheetEntry;
use App\Support\AssignmentsDirectory;
use App\Support\ClientMemberDirectory;
use App\Support\ReportingFreeze;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class EmployeePurgeService
{
    public function __construct(
        private readonly ClientMemberDirectory $directory,
        private readonly AssignmentsDirectory $legacyDirectory,
    ) {}

    /** Reject the entire purge if even one fact or approval is financially protected. */
    public function purge(int $employee, array $memberIds = []): void
    {
        DB::transaction(function () use ($employee, $memberIds): void {
            ReportingFreeze::lock();
            // Authorized administrative cleanup is not a normal time edit.
            // The employee's own confirmed rows are removed even after a
            // customer reporting period was closed; unrelated facts survive.
            $owned = static fn ($q) => $q->where('employee_id', $employee)
                ->orWhereIn('client_member_id', $memberIds);
            TimesheetLegacySource::query()->where('employee_id', $employee)->delete();
            TimesheetEntry::query()->where($owned)->delete();
            FinalApprovalException::query()->where($owned)->delete();
            PartnerDailyConfirmation::query()->where('confirmed_by_employee_id', $employee)
                ->update(['confirmed_by_employee_id' => null]);
            EmployeeConfirmation::query()->where('employee_id', $employee)->delete();
            FinalApproval::query()->where($owned)->delete();
            TimesheetAudit::query()->where($owned)->delete();
            FinalApproval::query()->where('approved_by', $employee)->update(['approved_by' => null]);
            PeriodLock::query()->where('locked_by', $employee)->update(['locked_by' => null]);
            TimesheetAudit::query()->where('actor_employee_id', $employee)->update(['actor_employee_id' => null]);
        });
    }
}
