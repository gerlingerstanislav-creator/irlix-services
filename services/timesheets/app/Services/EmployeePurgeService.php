<?php
namespace App\Services;

use App\Models\EmployeeConfirmation;
use App\Models\FinalApproval;
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
    public function purge(int $employee): void
    {
        DB::transaction(function () use ($employee): void {
            ReportingFreeze::lock();
            $snapshot = $this->directory->snapshot();
            $byMember = [];
            foreach ($snapshot['members'] as $member) {
                $byMember[$member['client_member_id']] = $member['client_id'];
            }
            $legacy = TimesheetEntry::query()->where('employee_id', $employee)
                ->whereNotNull('project_id')->exists()
                ? $this->legacyDirectory->snapshot()['project_clients'] : [];
            $periods = array_merge($snapshot['locked_periods'], ReportingFreezeRecord::query()
                ->get()->map(static fn ($period) => $period->getRawOriginal())->all());
            foreach (TimesheetEntry::query()->where('employee_id', $employee)->get() as $fact) {
                $clientId = $fact->client_member_id !== null
                    ? ($byMember[$fact->client_member_id] ?? null)
                    : ($legacy[$fact->project_id] ?? null);
                abort_unless($clientId !== null, 503, 'Cannot purge facts with unresolved ownership');
                foreach ($periods as $period) {
                    abort_if((int) $period['client_id'] === (int) $clientId
                        && $period['period_start'] <= $fact->work_date && $period['period_end'] >= $fact->work_date,
                        423, 'Protected financial facts require a dedicated purge procedure');
                }
            }
            foreach (FinalApproval::query()->where(
                static fn ($q) => $q->where('approved_by', $employee)->orWhere('employee_id', $employee)
            )->get() as $approval) {
                $clientId = $approval->client_member_id !== null
                    ? ($byMember[$approval->client_member_id] ?? null)
                    : ($legacy[$approval->project_id] ?? null);
                abort_unless($clientId !== null, 503, 'Cannot purge approvals with unresolved ownership');
                $end = Carbon::parse($approval->month)->endOfMonth()->toDateString();
                foreach ($periods as $period) {
                    abort_if((int) $period['client_id'] === (int) $clientId
                        && $period['period_start'] <= $end && $period['period_end'] >= $approval->month,
                        423, 'Protected approval authors require a dedicated purge procedure');
                }
            }
            TimesheetEntry::query()->where('employee_id', $employee)->delete();
            EmployeeConfirmation::query()->where('employee_id', $employee)->delete();
            FinalApproval::query()->where('employee_id', $employee)->delete();
            TimesheetAudit::query()->where('employee_id', $employee)->delete();
            FinalApproval::query()->where('approved_by', $employee)->update(['approved_by' => null]);
            PeriodLock::query()->where('locked_by', $employee)->update(['locked_by' => null]);
            TimesheetAudit::query()->where('actor_employee_id', $employee)->update(['actor_employee_id' => null]);
        });
    }
}
