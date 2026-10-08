<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AssignmentReconciler
{
    public function __construct(private readonly AssignmentsDirectory $directory) {}

    public function run(): void
    {
        DB::transaction(function (): void {
            // Lock existing candidates before reading the authoritative snapshot. New rows are not candidates.
            // The lock order matches timesheet writes: entries, employee confirmations, final approvals.
            $entries = DB::table('timesheet_entries')->orderBy('id')->lockForUpdate()->get();
            $confirmations = DB::table('employee_confirmations')->orderBy('id')->lockForUpdate()->get();
            $approvals = DB::table('final_approvals')->orderBy('id')->lockForUpdate()->get();
            $snapshot = $this->directory->snapshot();
            $allAssignments = $snapshot['assignments'];
            $periods = $snapshot['locked_reporting_periods'];
            $approvalLocked = function (int $employeeId, int $projectId, string $month) use ($allAssignments, $entries, $periods): bool {
                $to = Carbon::parse($month)->endOfMonth()->toDateString();
                $clientIds = collect($allAssignments)->filter(fn ($a) => $a['employee_id'] === $employeeId && $a['project_id'] === $projectId)->pluck('client_id')
                    ->merge($entries->filter(fn ($entry) => (int) $entry->employee_id === $employeeId && (int) $entry->project_id === $projectId)->pluck('client_id'))->unique();
                return $clientIds->contains(fn ($clientId) => ReportingPeriodApprovals::locked($periods, (int) $clientId, $month, $to));
            };

            foreach ($entries as $entry) {
                if (ReportingPeriodApprovals::locked($periods, (int) $entry->client_id, $entry->work_date, $entry->work_date)) continue;
                $valid = collect($allAssignments)->contains(fn (array $a) =>
                    $a['employee_id'] === (int) $entry->employee_id
                    && $a['project_id'] === (int) $entry->project_id
                    && $a['valid_from'] <= $entry->work_date
                    && (empty($a['valid_to']) || $a['valid_to'] >= $entry->work_date)
                );

                if ($valid) continue;

                $before = (array) $entry;
                DB::transaction(function () use ($entry, $approvalLocked): void {
                    DB::table('timesheet_entries')->where('id', $entry->id)->delete();
                    $month = Carbon::parse($entry->work_date)->startOfMonth()->toDateString();
                    if (!$approvalLocked((int) $entry->employee_id, (int) $entry->project_id, $month)) DB::table('final_approvals')
                        ->where('employee_id', $entry->employee_id)
                        ->where('project_id', $entry->project_id)
                        ->where('month', Carbon::parse($entry->work_date)->startOfMonth()->toDateString())
                        ->delete();
                });
                $this->audit('entry_deleted_outside_assignment', null, (int) $entry->employee_id, (int) $entry->project_id, $entry->work_date, $before, null);
            }


            foreach ($approvals as $approval) {
                if ($approvalLocked((int) $approval->employee_id, (int) $approval->project_id, $approval->month)) continue;
                $from = Carbon::parse($approval->month)->startOfMonth()->toDateString();
                $to = Carbon::parse($approval->month)->endOfMonth()->toDateString();
                $stillRelevant = collect($allAssignments)->contains(fn (array $a) =>
                    $a['employee_id'] === (int) $approval->employee_id
                    && $a['project_id'] === (int) $approval->project_id
                    && $a['valid_from'] <= $to && (empty($a['valid_to']) || $a['valid_to'] >= $from)
                );

                if (!$stillRelevant) {
                    DB::table('final_approvals')->where('id', $approval->id)->delete();
                    $this->audit('final_approval_deleted_outside_assignment', null, (int) $approval->employee_id, (int) $approval->project_id, null, (array) $approval, null);
                }
            }


            foreach ($confirmations as $confirmation) {
                $protected = $entries->contains(fn ($entry) => (int) $entry->employee_id === (int) $confirmation->employee_id
                    && $entry->work_date === $confirmation->work_date
                    && ReportingPeriodApprovals::locked($periods, (int) $entry->client_id, $confirmation->work_date, $confirmation->work_date));
                if ($protected) continue;
                $hasActiveAssignment = collect($allAssignments)->contains(fn (array $a) =>
                    $a['employee_id'] === (int) $confirmation->employee_id
                    && $a['valid_from'] <= $confirmation->work_date
                    && (empty($a['valid_to']) || $a['valid_to'] >= $confirmation->work_date)
                );

                if ($hasActiveAssignment) continue;

                DB::table('employee_confirmations')->where('id', $confirmation->id)->delete();
                $this->audit(
                    'employee_confirmation_deleted_outside_assignment',
                    null,
                    (int) $confirmation->employee_id,
                    null,
                    $confirmation->work_date,
                    ['confirmed' => true],
                    ['confirmed' => false],
                );
            }
        });
    }

    private function audit(string $action, ?int $actorId, ?int $employeeId, ?int $projectId,
        ?string $workDate, mixed $before, mixed $after): void
    {
        DB::table('timesheet_audit')->insert([
            'action' => $action, 'actor_employee_id' => $actorId, 'employee_id' => $employeeId,
            'project_id' => $projectId, 'work_date' => $workDate,
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
