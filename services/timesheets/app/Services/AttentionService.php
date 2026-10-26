<?php

namespace App\Services;

use App\Models\EmployeeConfirmation;
use App\Models\FinalApproval;
use App\Models\TimesheetEntry;
use Carbon\Carbon;
use Illuminate\Http\Request;

final class AttentionService
{
    public function __construct(private readonly TimesheetsContext $context) {}

    public function tasks(Request $request, array $current): array
    {
        $employeeId = (int) $current['id'];
        $today = Carbon::today();
        $months = [$today->copy()->subMonthNoOverflow()->startOfMonth()];
        if ($today->day >= 25) $months[] = $today->copy()->startOfMonth();
        $assignments = $this->context->assignments($request);
        $tasks = [];
        foreach ($months as $start) {
            $end = $start->copy()->endOfMonth();
            $from = $start->toDateString();
            $to = $end->toDateString();
            $month = $start->format('Y-m');
            $own = collect($assignments)->filter(static fn (array $a) =>
                (int) $a['employee_id'] === $employeeId
                && $a['valid_from'] <= $to && (empty($a['valid_to']) || $a['valid_to'] >= $from))->values();
            if ($own->isNotEmpty()) {
                $entries = TimesheetEntry::query()->where('employee_id', $employeeId)
                    ->whereBetween('work_date', [$from, $to])->get();
                $entryDates = $entries->filter(static fn ($row) => (float) $row->hours > 0)
                    ->pluck('work_date')->map(static fn ($date) => substr((string) $date, 0, 10))->unique()->all();
                $confirmedDates = EmployeeConfirmation::query()->where('employee_id', $employeeId)
                    ->whereBetween('work_date', [$from, $to])->pluck('work_date')
                    ->map(static fn ($date) => substr((string) $date, 0, 10))->unique()->all();
                $absences = $this->context->absenceData($request, $from, $to, [$employeeId]);
                $missing = 0;
                $date = $start->copy();
                $last = $end->lessThan($today) ? $end : $today->copy()->subDay();
                while ($date->lte($last)) {
                    $key = $date->toDateString();
                    if ($date->isWeekday() && $own->contains(static fn ($a) =>
                        $a['valid_from'] <= $key && (empty($a['valid_to']) || $a['valid_to'] >= $key))) {
                        $absent = collect($absences)->contains(static fn ($a) =>
                            in_array($a['status'] ?? '', ['confirmed', 'provided'], true)
                            && $a['starts_on'] <= $key
                            && (empty($a['ends_on']) || $a['ends_on'] >= $key));
                        if (!$absent && !in_array($key, $entryDates, true)) $missing++;
                    }
                    $date->addDay();
                }
                if ($missing) {
                    $tasks[] = [
                        'id' => 'fill:'.$month, 'title' => 'Заполнить таймшиты за '.$start->translatedFormat('F Y'),
                        'detail' => 'Нет записей за '.$missing.' рабочих дней', 'kind' => 'attention',
                        'due_at' => $to, 'href' => '/timesheets/mine/?section=mine&month='.$month,
                    ];
                } elseif (count($entryDates) && count(array_diff($entryDates, $confirmedDates))) {
                    $tasks[] = [
                        'id' => 'confirm:'.$month, 'title' => 'Подтвердить таймшиты за '.$start->translatedFormat('F Y'),
                        'detail' => 'Записи заполнены, но не все дни подтверждены', 'kind' => 'attention',
                        'due_at' => $to, 'href' => '/timesheets/mine/?month='.$month,
                    ];
                }
            }
            [$employees, $departments] = $this->context->directory($request);
            $access = $this->context->accessInfo($request, $current, $assignments, $departments);
            if (!$access['platformAdmin']
                && !($access['contourPermissions']['timesheets.management.view']['allowed'] ?? false)) continue;
            [$employeeIds, $visible] = $this->context->scopeForPeriod(
                $current, $employees, $assignments, $access, $from, $to);
            if (!$employeeIds || $visible->isEmpty()) continue;
            $submittedIds = EmployeeConfirmation::query()->whereIn('employee_id', $employeeIds)
                ->whereBetween('work_date', [$from, $to])->pluck('employee_id')
                ->map(static fn ($id) => (int) $id)->unique()->all();
            if (!$submittedIds) continue;
            $approvalKeys = FinalApproval::query()->whereIn('employee_id', $submittedIds)
                ->where('month', $from)->get()
                ->map(static fn ($row) => $row->employee_id.':'.$row->client_member_id)->all();
            $pending = $visible->filter(static fn ($a) =>
                in_array((int) $a['employee_id'], $submittedIds, true)
                && !in_array($a['employee_id'].':'.$a['client_member_id'], $approvalKeys, true))
                ->map(static fn ($a) => $a['employee_id'].':'.$a['client_member_id'])->unique()->count();
            if ($pending) {
                $tasks[] = [
                    'id' => 'approve:'.$month, 'title' => 'Согласовать таймшиты сотрудников',
                    'detail' => 'Ожидают подтверждения: '.$pending.' подключений за '.$start->translatedFormat('F Y'),
                    'kind' => 'attention', 'due_at' => $to,
                    'href' => '/timesheets/management/?section=management&month='.$month,
                ];
            }
        }
        return $tasks;
    }
}
