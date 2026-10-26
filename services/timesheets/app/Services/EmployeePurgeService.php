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
    public function purge(int $employee): void
    {
        DB::transaction(function () use ($employee): void {
            ReportingFreeze::lock();
            // A newly created employee has no financially protected facts. Do not
            // depend on the Clients integration just to purge empty Timesheets data.
            $facts = TimesheetEntry::query()->where('employee_id', $employee)->get();
            $approvals = FinalApproval::query()
                ->where(static fn ($q) => $q->where('approved_by', $employee)->orWhere('employee_id', $employee))
                ->get();
            $exceptions = FinalApprovalException::query()->where('employee_id', $employee)->get();
            if ($facts->isNotEmpty() || $approvals->isNotEmpty() || $exceptions->isNotEmpty()) {
                $snapshot = $this->directory->snapshot();
                $byMember = [];
                foreach ($snapshot['members'] as $member) {
                    $byMember[$member['client_member_id']] = $member['client_id'];
                }
                $hasLegacy = $facts->contains(static fn ($row) => $row->project_id !== null)
                    || $approvals->contains(static fn ($row) => $row->project_id !== null)
                    || $exceptions->contains(static fn ($row) => $row->project_id !== null);
                $legacy = $hasLegacy ? $this->legacyDirectory->snapshot()['project_clients'] : [];
                $periods = array_merge($snapshot['locked_periods'], ReportingFreezeRecord::query()
                    ->get()->map(static fn ($period) => $period->getRawOriginal())->all());
                foreach ($facts as $fact) {
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
                foreach ($approvals as $approval) {
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
                foreach ($exceptions as $exception) {
                    $clientId = $exception->client_member_id !== null
                        ? ($byMember[$exception->client_member_id] ?? null)
                        : ($legacy[$exception->project_id] ?? null);
                    abort_unless($clientId !== null, 503, 'Cannot purge exceptions with unresolved ownership');
                    foreach ($periods as $period) {
                        abort_if((int) $period['client_id'] === (int) $clientId
                            && $period['period_start'] <= $exception->work_date
                            && $period['period_end'] >= $exception->work_date,
                            423, 'Protected approval exceptions require a dedicated purge procedure');
                    }
                }
            }
            TimesheetLegacySource::query()->where('employee_id', $employee)->delete();
            TimesheetEntry::query()->where('employee_id', $employee)->delete();
            TimesheetEntry::query()->where('account_employee_id', $employee)->update(['account_employee_id' => null]);
            FinalApprovalException::query()->where('employee_id', $employee)->delete();
            PartnerDailyConfirmation::query()->where('confirmed_by_employee_id', $employee)
                ->update(['confirmed_by_employee_id' => null]);
            EmployeeConfirmation::query()->where('employee_id', $employee)->delete();
            FinalApproval::query()->where('employee_id', $employee)->delete();
            TimesheetAudit::query()->where('employee_id', $employee)->delete();
            FinalApproval::query()->where('approved_by', $employee)->update(['approved_by' => null]);
            PeriodLock::query()->where('locked_by', $employee)->update(['locked_by' => null]);
            TimesheetAudit::query()->where('actor_employee_id', $employee)->update(['actor_employee_id' => null]);
        });
    }
}
