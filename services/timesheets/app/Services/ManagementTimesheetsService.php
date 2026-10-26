<?php

namespace App\Services;

use App\Models\EmployeeConfirmation;
use App\Models\FinalApproval;
use App\Models\FinalApprovalException;
use App\Models\TimesheetEntry;
use App\Support\ReportingFreeze;
use App\Support\TimeHours;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ManagementTimesheetsService
{
    public function __construct(private readonly TimesheetsContext $context) {}

    private function rawRows($query)
    {
        return $query->get()->map(static fn ($row) => (object) $row->getRawOriginal());
    }

    public function authorized(Request $request, array $current, bool $write): array
    {
        [$employees, $departments] = $this->context->directory($request);
        $assignments = $this->context->assignments($request);
        $access = $this->context->accessInfo($request, $current, $assignments, $departments);
        $this->context->assertContourPermission($access,
            $write ? 'timesheets.management.manage' : 'timesheets.management.view');
        return compact('employees', 'departments', 'assignments', 'access');
    }

    public function month(Request $request, array $current): array
    {
        [$start, $end] = $this->context->monthBounds($request->query('month'));
        $context = $this->authorized($request, $current, false);
        ['employees' => $employees, 'departments' => $departments,
            'assignments' => $allAssignments, 'access' => $access] = $context;
        $from = $start->toDateString();
        $to = $end->toDateString();
        [$employeeIds, $visible] = $this->context->scopeForPeriod(
            $current, $employees, $allAssignments, $access, $from, $to);
        $memberIds = $visible->pluck('client_member_id')->unique()->values()->all();
        $visibleClientIds = $visible->pluck('client_id')->map(static fn ($id) => (int) $id)
            ->unique()->values()->all();
        $periods = collect($request->attributes->get('locked_reporting_periods', []))
            ->filter(static fn (array $period) =>
                in_array((int) ($period['client_id'] ?? 0), $visibleClientIds, true)
                && ($period['period_start'] ?? '') <= $to && ($period['period_end'] ?? '') >= $from)
            ->values();
        return [
            'month' => $start->format('Y-m'),
            'current_employee' => $current,
            'access' => $access,
            'employees' => $employees->filter(static fn ($e) =>
                in_array((int) $e['id'], $employeeIds, true))->values(),
            'departments' => $departments,
            'assignments' => $visible,
            'entries' => $memberIds
                ? $this->rawRows(TimesheetEntry::query()
                    ->whereIn('employee_id', $employeeIds ?: [0])
                    ->whereIn('client_member_id', $memberIds)
                    ->whereBetween('work_date', [$from, $to]))
                : collect(),
            'confirmations' => $this->rawRows(EmployeeConfirmation::query()
                ->whereIn('employee_id', $employeeIds ?: [0])
                ->whereBetween('work_date', [$from, $to])),
            'final_approval_exceptions' => $this->rawRows(FinalApprovalException::query()
                ->whereIn('employee_id', $employeeIds ?: [0])
                ->whereBetween('work_date', [$from, $to])),
            'final_approvals' => $this->rawRows(FinalApproval::query()
                ->whereIn('employee_id', $employeeIds ?: [0])
                ->whereIn('client_member_id', $memberIds ?: [0])->where('month', $from)),
            'absences' => $this->context->absenceData($request, $from, $to, $employeeIds),
            'client_reporting_periods' => $periods,
            'period_locked' => false,
        ];
    }

    /** Existing single- and multi-member mutations remain one atomic employee/day operation. */
    public function write(Request $request, array $current, array $authorized, array $data, array $rows): array
    {
        $employeeId = (int) $data['employee_id'];
        $date = $data['work_date'];
        $employees = $authorized['employees'];
        $assignments = $authorized['assignments'];
        $access = $authorized['access'];
        $target = $employees->first(static fn ($e) => (int) $e['id'] === $employeeId);
        abort_unless($target, 404, 'Employee not found');
        $prepared = [];
        foreach ($rows as $row) {
            $hours = TimeHours::decimal($row['hours']);
            $assignment = collect($this->context->activeAssignments($assignments, $employeeId, $date))
                ->firstWhere('client_member_id', (int) $row['client_member_id']);
            abort_unless($assignment, 422, 'Нет действующих условий подключения к клиенту на выбранную дату');
            abort_unless($this->context->canManageAssignment($current, $target, $assignment, $access),
                403, 'Timesheet is outside your scope');
            $this->context->assertReportEditable($request, (int) $assignment['client_id'], $date);
            $prepared[] = [
                'client_member_id' => (int) $row['client_member_id'],
                'client_id' => (int) $assignment['client_id'],
                'hours' => $hours,
                'description' => trim((string) ($row['description'] ?? '')) ?: null,
            ];
        }
        DB::transaction(function () use ($employeeId, $date, $prepared, $current): void {
            TimeHours::lockDay($employeeId, $date);
            foreach ($prepared as $row) ReportingFreeze::assertEditable($row['client_id'], $date);
            $existingRows = TimesheetEntry::query()->where('employee_id', $employeeId)
                ->where('work_date', $date)->orderBy('id')->lockForUpdate()->get();
            $memberIds = array_column($prepared, 'client_member_id');
            $otherCents = $existingRows->reject(static fn ($entry) =>
                in_array((int) $entry->client_member_id, $memberIds, true))
                ->sum(static fn ($entry) => TimeHours::cents($entry->hours));
            $newCents = array_sum(array_map(static fn ($row) => TimeHours::cents($row['hours']), $prepared));
            abort_if($otherCents + $newCents > 2400, 422, 'Total hours for the day cannot exceed 24');
            $changedIds = [];
            foreach ($prepared as $row) {
                $memberId = $row['client_member_id'];
                $existing = $existingRows->first(static fn ($entry) => (int) $entry->client_member_id === $memberId);
                TimeHours::assertWritable($row['hours'], $existing?->hours);
                if ($existing && TimeHours::cents($existing->hours) === TimeHours::cents($row['hours'])
                    && $existing->description === $row['description']) continue;
                if (!$existing && TimeHours::cents($row['hours']) === 0 && $row['description'] === null) continue;
                $payload = [
                    'employee_id' => $employeeId, 'client_member_id' => $memberId,
                    'work_date' => $date, 'hours' => $row['hours'],
                    'description' => $row['description'], 'term_conflict' => false, 'updated_at' => now(),
                ];
                if ($existing) TimesheetEntry::query()->where('id', $existing->id)->update($payload);
                else TimesheetEntry::query()->insert([...$payload, 'created_at' => now()]);
                $this->context->audit('manager_entry_changed', (int) $current['id'], $employeeId,
                    $memberId, $date, $existing ? $existing->getRawOriginal() : null,
                    ['hours' => $row['hours'], 'description' => $row['description']]);
                $changedIds[] = $memberId;
            }
            if ($changedIds) {
                EmployeeConfirmation::query()->where('employee_id', $employeeId)
                    ->where('work_date', $date)->delete();
                foreach ($changedIds as $memberId) {
                    FinalApprovalException::query()->updateOrInsert(
                        ['employee_id' => $employeeId, 'client_member_id' => $memberId, 'work_date' => $date],
                        ['created_at' => now(), 'updated_at' => now()],
                    );
                }
            }
        });
        return [
            'ok' => true,
            'entries' => $this->rawRows(TimesheetEntry::query()->where('employee_id', $employeeId)
                ->where('work_date', $date)),
        ];
    }

    /** Descriptions always come from the locked database row, not a browser snapshot. */
    public function bulkHours(Request $request, array $current, array $authorized, array $data): array
    {
        $hours = TimeHours::decimal($data['hours']);
        $dates = $data['dates'];
        sort($dates);
        abort_if(substr($dates[0], 0, 7) !== substr(end($dates), 0, 7),
            422, 'Selected dates must belong to one month');
        $employees = $authorized['employees'];
        $target = $employees->first(static fn ($e) => (int) $e['id'] === (int) $data['employee_id']);
        abort_unless($target, 404, 'Employee not found');
        $prepared = [];
        foreach ($dates as $date) {
            $assignment = collect($this->context->activeAssignments(
                $authorized['assignments'], (int) $data['employee_id'], $date
            ))->firstWhere('client_member_id', (int) $data['client_member_id']);
            abort_unless($assignment, 422, 'Нет действующих условий подключения к клиенту на выбранную дату');
            abort_unless($this->context->canManageAssignment(
                $current, $target, $assignment, $authorized['access']),
                403, 'Timesheet is outside your scope');
            $this->context->assertReportEditable($request, (int) $assignment['client_id'], $date);
            $prepared[$date] = $assignment;
        }
        DB::transaction(function () use ($data, $prepared, $hours, $current): void {
            $employeeId = (int) $data['employee_id'];
            foreach (array_keys($prepared) as $date) TimeHours::lockDay($employeeId, $date);
            foreach ($prepared as $date => $assignment) {
                ReportingFreeze::assertEditable((int) $assignment['client_id'], $date);
            }
            $existingRows = TimesheetEntry::query()->where('employee_id', $employeeId)
                ->whereIn('work_date', array_keys($prepared))->orderBy('work_date')->orderBy('id')
                ->lockForUpdate()->get();
            $changedDates = [];
            foreach ($prepared as $date => $assignment) {
                $dayRows = $existingRows->filter(static fn ($entry) => $entry->work_date === $date);
                $otherCents = $dayRows->reject(static fn ($entry) =>
                    (int) $entry->client_member_id === (int) $data['client_member_id'])
                    ->sum(static fn ($entry) => TimeHours::cents($entry->hours));
                abort_if($otherCents + TimeHours::cents($hours) > 2400,
                    422, 'Total hours for the day cannot exceed 24');
                $existing = $dayRows->first(static fn ($entry) =>
                    (int) $entry->client_member_id === (int) $data['client_member_id']);
                TimeHours::assertWritable($hours, $existing?->hours);
                if ($existing && TimeHours::cents($existing->hours) === TimeHours::cents($hours)) continue;
                if (!$existing && TimeHours::cents($hours) === 0) continue;
                if ($existing) {
                    TimesheetEntry::query()->where('id', $existing->id)
                        ->update(['hours' => $hours, 'term_conflict' => false, 'updated_at' => now()]);
                } else {
                    TimesheetEntry::query()->insert([
                        'employee_id' => $employeeId, 'client_member_id' => $data['client_member_id'],
                        'work_date' => $date, 'hours' => $hours, 'description' => null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $this->context->audit('manager_entry_changed', (int) $current['id'],
                    $employeeId, $assignment['client_member_id'], $date,
                    $existing ? $existing->getRawOriginal() : null,
                    ['hours' => $hours, 'description' => $existing?->description]);
                $changedDates[] = $date;
            }
            if ($changedDates) {
                EmployeeConfirmation::query()->where('employee_id', $employeeId)
                    ->whereIn('work_date', $changedDates)->delete();
                foreach ($changedDates as $date) {
                    FinalApprovalException::query()->updateOrInsert([
                        'employee_id' => $employeeId,
                        'client_member_id' => $data['client_member_id'], 'work_date' => $date,
                    ], ['created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        return ['ok' => true, 'dates' => $dates];
    }

    public function finalApproval(Request $request, array $current, array $authorized, array $data): array
    {
        [$start, $end] = $this->context->monthBounds($data['month']);
        $from = $start->toDateString();
        $to = $end->toDateString();
        $target = $authorized['employees']->first(static fn ($e) =>
            (int) $e['id'] === (int) $data['employee_id']);
        abort_unless($target, 404, 'Employee not found');
        $identity = collect($request->attributes->get('all_client_members', []))->first(static fn ($m) =>
            $m['client_member_id'] === (int) $data['client_member_id']
            && $m['employee_id'] === (int) $data['employee_id']);
        abort_unless($identity, 422, 'Подключение сотрудника не найдено.');
        abort_unless($this->context->canManageAssignment(
            $current, $target, $identity, $authorized['access']),
            403, 'Подключение вне вашей области доступа.');
        $targetAssignments = collect($authorized['assignments'])->filter(static fn ($a) =>
            $a['client_member_id'] === (int) $data['client_member_id']
            && $a['employee_id'] === (int) $data['employee_id']
            && $a['valid_from'] <= $to
            && (empty($a['valid_to']) || $a['valid_to'] >= $from))->values();
        abort_if($data['approved'] && $targetAssignments->isEmpty(),
            422, 'Нет действующих условий в выбранном месяце.');
        $memberIds = [(int) $data['client_member_id']];
        $this->context->assertReportEditable($request, (int) $identity['client_id'], $from, $to);
        $allMembers = $request->attributes->get('all_client_members', []);
        $locks = $request->attributes->get('locked_reporting_periods', []);
        DB::transaction(function () use ($data, $current, $from, $to, $targetAssignments,
            $start, $end, $memberIds, $identity, $allMembers, $locks): void {
            foreach (CarbonPeriod::create($start, $end) as $day) {
                TimeHours::lockDay((int) $data['employee_id'], $day->toDateString());
            }
            ReportingFreeze::assertEditable((int) $identity['client_id'], $from, $to);
            if ($data['approved']) {
                $actual = TimesheetEntry::query()->where('client_member_id', $data['client_member_id'])
                    ->whereBetween('work_date', [$from, $to])->get();
                foreach ($actual as $entry) {
                    $covered = $targetAssignments->filter(static fn ($a) =>
                        $a['valid_from'] <= $entry->work_date
                        && (empty($a['valid_to']) || $a['valid_to'] >= $entry->work_date));
                    abort_if((int) $entry->employee_id !== (int) $data['employee_id']
                        || $covered->count() !== 1 || (bool) $entry->term_conflict,
                        422, 'Есть конфликтующие часы вне коммерческих условий.');
                }
                foreach (CarbonPeriod::create($start, $end) as $day) {
                    $date = $day->toDateString();
                    $hasApproved = $targetAssignments->contains(static fn (array $a) =>
                        $a['valid_from'] <= $date
                        && (empty($a['valid_to']) || $a['valid_to'] >= $date));
                    if (!$hasApproved) continue;
                    if (EmployeeConfirmation::query()->where('employee_id', $data['employee_id'])
                        ->where('work_date', $date)->exists()) continue;
                    $this->context->assertLocalEmployeeDatesEditable(
                        (int) $data['employee_id'], [$date], $allMembers);
                    foreach ($allMembers as $member) {
                        if ($member['employee_id'] !== (int) $data['employee_id']) continue;
                        abort_if(collect($locks)->contains(static fn ($period) =>
                            $period['client_id'] === $member['client_id']
                            && $period['period_start'] <= $date && $period['period_end'] >= $date),
                            423, 'Общий день сотрудника защищён отчётным периодом другого клиента.');
                    }
                    EmployeeConfirmation::query()->insert([
                        'employee_id' => $data['employee_id'], 'work_date' => $date,
                        'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            FinalApprovalException::query()->where('employee_id', $data['employee_id'])
                ->where('client_member_id', $data['client_member_id'])
                ->whereBetween('work_date', [$from, $to])->delete();
            if ($data['approved']) {
                FinalApproval::query()->updateOrInsert([
                    'employee_id' => $data['employee_id'],
                    'client_member_id' => $data['client_member_id'], 'month' => $from,
                ], [
                    'approved_by' => $current['id'], 'approved_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                FinalApproval::query()->where('employee_id', $data['employee_id'])
                    ->where('client_member_id', $data['client_member_id'])
                    ->where('month', $from)->delete();
            }
            $this->context->audit($data['approved'] ? 'final_approved' : 'final_unapproved',
                (int) $current['id'], (int) $data['employee_id'], null, null, null,
                ['client_member_ids' => $memberIds, 'month' => $from]);
        });
        return ['ok' => true, 'client_member_ids' => $memberIds];
    }
}
