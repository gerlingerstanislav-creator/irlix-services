<?php

namespace App\Services;

use App\Models\EmployeeConfirmation;
use App\Models\FinalApproval;
use App\Models\FinalApprovalException;
use App\Models\TimesheetEntry;
use App\Support\ReportingFreeze;
use App\Support\TimeHours;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PersonalTimesheetsService
{
    public function __construct(private readonly TimesheetsContext $context) {}

    private function rawRows($query)
    {
        return $query->get()->map(static fn ($row) => (object) $row->getRawOriginal());
    }

    public function workspace(Request $request, array $employee): array
    {
        [$start, $end] = $this->context->monthBounds($request->query('month'));
        [, $departments] = $this->context->directory($request);
        $allAssignments = $this->context->assignments($request);
        $from = $start->toDateString();
        $to = $end->toDateString();
        $employeeAssignments = collect($allAssignments)
            ->filter(fn (array $a) => $a['employee_id'] === (int) $employee['id']
                && $this->context->overlapsPeriod($a, $from, $to))->values();
        return [
            'employee' => $employee,
            'month' => $start->format('Y-m'),
            'assignments' => $employeeAssignments,
            'members' => collect($request->attributes->get('all_client_members', []))
                ->filter(fn ($m) => $m['employee_id'] === (int) $employee['id'])->values(),
            'client_reporting_periods' => collect($request->attributes->get('locked_reporting_periods', []))
                ->filter(fn (array $period) => $employeeAssignments->contains('client_id',
                    (int) ($period['client_id'] ?? 0))
                    && ($period['period_start'] ?? '') <= $to
                    && ($period['period_end'] ?? '') >= $from)->values(),
            'entries' => $this->rawRows(TimesheetEntry::query()->where('employee_id', $employee['id'])
                ->whereBetween('work_date', [$from, $to])->orderBy('work_date')),
            'confirmations' => EmployeeConfirmation::query()->where('employee_id', $employee['id'])
                ->whereBetween('work_date', [$from, $to])->pluck('confirmed_at', 'work_date'),
            'final_approval_exceptions' => $this->rawRows(FinalApprovalException::query()
                ->where('employee_id', $employee['id'])->whereBetween('work_date', [$from, $to])),
            'final_approvals' => $this->rawRows(FinalApproval::query()
                ->where('employee_id', $employee['id'])->where('month', $from)),
            'absences' => $this->context->absenceData($request, $from, $to, [(int) $employee['id']]),
            'period_locked' => false,
            'access' => $this->context->accessInfo($request, $employee, $allAssignments, $departments),
        ];
    }

    public function write(Request $request, array $employee, array $data): void
    {
        $hours = TimeHours::decimal($data['hours']);
        $description = trim((string) ($data['description'] ?? '')) ?: null;
        $employeeId = (int) $employee['id'];
        $memberId = (int) $data['client_member_id'];
        $date = $data['work_date'];
        $assignment = collect($this->context->activeAssignments(
            $this->context->assignments($request), $employeeId, $date
        ))->firstWhere('client_member_id', $memberId);
        abort_unless($assignment, 422, 'Нет действующих условий подключения к клиенту на выбранную дату');
        $this->context->assertReportEditable($request, (int) $assignment['client_id'], $date);

        DB::transaction(function () use ($employeeId, $memberId, $date, $hours, $description, $assignment): void {
            TimeHours::lockDay($employeeId, $date);
            ReportingFreeze::assertEditable((int) $assignment['client_id'], $date);
            $month = Carbon::parse($date)->startOfMonth()->toDateString();
            abort_if(
                FinalApproval::query()->where('employee_id', $employeeId)
                    ->where('client_member_id', $memberId)->where('month', $month)->exists()
                && !FinalApprovalException::query()->where('employee_id', $employeeId)
                    ->where('client_member_id', $memberId)->where('work_date', $date)->exists(),
                423, 'Таймшит клиента окончательно подтверждён'
            );
            $existing = TimesheetEntry::query()->where('employee_id', $employeeId)
                ->where('client_member_id', $memberId)->where('work_date', $date)->lockForUpdate()->first();
            $other = TimesheetEntry::query()->where('employee_id', $employeeId)
                ->where('work_date', $date)
                ->where(static fn ($q) => $q->whereNull('client_member_id')
                    ->orWhere('client_member_id', '!=', $memberId))->sum('hours');
            abort_if(TimeHours::cents($other) + TimeHours::cents($hours) > 2400,
                422, 'Total hours for the day cannot exceed 24');
            TimeHours::assertWritable($hours, $existing?->hours);
            if ($existing && TimeHours::cents($existing->hours) === TimeHours::cents($hours)
                && $existing->description === $description) return;
            $payload = [
                'employee_id' => $employeeId, 'client_member_id' => $memberId, 'work_date' => $date,
                'hours' => $hours, 'description' => $description, 'term_conflict' => false, 'updated_at' => now(),
            ];
            if ($existing) TimesheetEntry::query()->where('id', $existing->id)->update($payload);
            else TimesheetEntry::query()->insert([...$payload, 'created_at' => now()]);
            EmployeeConfirmation::query()->where('employee_id', $employeeId)
                ->where('work_date', $date)->delete();
            $this->context->audit($existing ? 'entry_updated' : 'entry_created',
                $employeeId, $employeeId, $memberId, $date,
                $existing ? $existing->getRawOriginal() : null,
                ['hours' => $hours, 'description' => $description]);
        });
    }

    public function confirm(Request $request, array $employee, array $data): array
    {
        $requested = array_values(array_unique($data['dates']));
        $assignments = $this->context->assignments($request);
        $dates = array_values(array_filter($requested, static fn (string $date) =>
            collect($assignments)->contains(static fn (array $assignment) =>
                $assignment['employee_id'] === (int) $employee['id']
                && $assignment['valid_from'] <= $date
                && (empty($assignment['valid_to']) || $assignment['valid_to'] >= $date))
        ));
        $this->context->assertEmployeeDatesEditable($request, (int) $employee['id'], $dates);
        sort($dates);
        DB::transaction(function () use ($dates, $employee, $assignments, $request): void {
            foreach ($dates as $date) TimeHours::lockDay((int) $employee['id'], $date);
            $this->context->assertLocalEmployeeDatesEditable((int) $employee['id'], $dates,
                $request->attributes->get('all_client_members', $assignments));
            $facts = TimesheetEntry::query()->where('employee_id', $employee['id'])
                ->whereIn('work_date', $dates)->get();
            foreach ($facts as $fact) {
                $cover = collect($assignments)->filter(static fn ($a) =>
                    $a['client_member_id'] === (int) $fact->client_member_id
                    && $a['employee_id'] === (int) $employee['id']
                    && $a['valid_from'] <= $fact->work_date
                    && (empty($a['valid_to']) || $a['valid_to'] >= $fact->work_date));
                abort_if($cover->count() !== 1 || (bool) $fact->term_conflict,
                    422, 'В выбранных днях есть конфликтующие или неперенесённые часы.');
            }
            foreach ($dates as $date) {
                EmployeeConfirmation::query()->updateOrInsert(
                    ['employee_id' => $employee['id'], 'work_date' => $date],
                    ['confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                );
                $this->context->audit('employee_confirmed', (int) $employee['id'],
                    (int) $employee['id'], null, $date, null, ['confirmed' => true]);
            }
        });
        return [
            'confirmed' => count($dates),
            'skipped_without_assignment' => count($requested) - count($dates),
        ];
    }

    public function unconfirm(Request $request, array $employee, array $data): void
    {
        $assignments = $this->context->assignments($request);
        $this->context->assertEmployeeDatesEditable($request, (int) $employee['id'], $data['dates']);
        $dates = array_values(array_unique($data['dates']));
        sort($dates);
        DB::transaction(function () use ($dates, $employee, $assignments, $request): void {
            foreach ($dates as $date) TimeHours::lockDay((int) $employee['id'], $date);
            $this->context->assertLocalEmployeeDatesEditable((int) $employee['id'], $dates,
                $request->attributes->get('all_client_members', $assignments));
            foreach ($dates as $date) {
                $month = Carbon::parse($date)->startOfMonth()->toDateString();
                $memberIds = collect($request->attributes->get('all_client_members', $assignments))
                    ->filter(static fn (array $a) => $a['employee_id'] === (int) $employee['id'])
                    ->pluck('client_member_id')->map(static fn ($id) => (int) $id)
                    ->unique()->values()->all();
                if ($memberIds) {
                    abort_if(FinalApproval::query()->where('employee_id', $employee['id'])
                        ->where('month', $month)->whereIn('client_member_id', $memberIds)
                        ->whereNotIn('client_member_id', FinalApprovalException::query()
                            ->select('client_member_id')
                            ->where('employee_id', $employee['id'])->where('work_date', $date))
                        ->exists(), 423, 'Финально подтверждённый день не может быть отменён сотрудником.');
                }
                EmployeeConfirmation::query()->where('employee_id', $employee['id'])
                    ->where('work_date', $date)->delete();
                $this->context->audit('employee_unconfirmed', (int) $employee['id'],
                    (int) $employee['id'], null, $date,
                    ['confirmed' => true], ['confirmed' => false]);
            }
        });
    }
}
