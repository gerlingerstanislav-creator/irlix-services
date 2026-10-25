<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentAssignmentHistory;
use App\Models\SalaryHistory;
use App\Models\SalaryReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class PayrollOverviewService
{
    /**
     * Read-only year overview. The calendar remains owned by Platform Core;
     * no direct query crosses an internal-service boundary.
     */
    public function overview(int $year, array $permitted, ?int $departmentId): array
    {
        try {
            $response = Http::timeout(10)->get(
                rtrim((string) env('PLATFORM_CORE_URL', 'http://platform-core:8000/api'), '/')
                    .'/production-calendar',
                ['year' => $year]
            );
            if (!$response->successful()) throw new RuntimeException('Calendar HTTP '.$response->status());
            $calendar = $response->json('data');
        } catch (Throwable $e) {
            report($e);
            return ['status' => 503, 'message' => 'Не удалось получить производственный календарь'];
        }
        if (!($calendar['available'] ?? false)) {
            return ['status' => 503, 'message' => 'Производственный календарь на этот год пока недоступен'];
        }
        $working = [];
        $monthWorkDays = array_fill(1, 12, 0);
        foreach (($calendar['days'] ?? []) as $day => $item) {
            if (!($item['is_working'] ?? false)) continue;
            $working[$day] = true;
            $monthWorkDays[(int) substr($day, 5, 2)]++;
        }
        $departments = Department::query()->get(['id', 'name', 'parent_id']);
        $eligibleDepartments = $departmentId === null ? $permitted : [$departmentId];
        if ($departmentId !== null) {
            $children = [];
            foreach ($departments as $department) {
                $children[(int) ($department->parent_id ?? 0)][] = (int) $department->id;
            }
            $queue = [$departmentId];
            while ($queue) {
                $id = array_shift($queue);
                foreach ($children[$id] ?? [] as $child) {
                    if (!in_array($child, $eligibleDepartments, true)
                        && in_array($child, $permitted, true)) {
                        $eligibleDepartments[] = $child;
                        $queue[] = $child;
                    }
                }
            }
        }

        $eligible = array_flip($eligibleDepartments);
        $employees = Employee::query()->get([
            'id', 'full_name', 'department_id', 'hired_at', 'fired_at', 'employment_status',
        ]);
        $salary = SalaryHistory::query()->orderBy('effective_from')->get()->groupBy('employee_id');
        $assignments = EmploymentAssignmentHistory::query()->orderBy('effective_from')
            ->get()->groupBy('employee_id');
        $reviews = SalaryReview::query()->where('status', '<>', 'cancelled')
            ->orderBy('effective_month')->get()->groupBy('employee_id');
        $summary = [];
        for ($month = 1; $month <= 12; $month++) $summary[$month] = ['current' => 0, 'planned' => 0];

        $rows = [];
        foreach ($employees as $employee) {
            $history = $assignments->get($employee->id, collect());
            $salaryHistory = $salary->get($employee->id, collect());
            $plans = $reviews->get($employee->id, collect());
            $monthly = [];
            $hasVisibleDay = false;
            for ($month = 1; $month <= 12; $month++) {
                $current = 0.0;
                $planned = 0.0;
                $worked = 0;
                $start = CarbonImmutable::create($year, $month, 1);
                for ($date = $start; $date->month === $month; $date = $date->addDay()) {
                    $day = $date->toDateString();
                    if (!isset($working[$day])) continue;
                    $assignment = null;
                    foreach ($history as $item) {
                        if ($item->effective_from <= $day
                            && (!$item->effective_to || $item->effective_to >= $day)) {
                            $assignment = $item;
                        }
                    }
                    if (!$assignment || !isset($eligible[(int) ($assignment->department_id ?? 0)])) {
                        continue;
                    }
                    $actual = null;
                    foreach ($salaryHistory as $record) {
                        if ($record->effective_from <= $day
                            && (!$record->effective_to || $record->effective_to >= $day)) {
                            $actual = $record;
                        }
                    }
                    if (!$actual) continue;
                    $currentAmount = (float) $actual->gross_salary;
                    $plannedAmount = $currentAmount;
                    $effective = $actual->effective_from;
                    foreach ($plans as $plan) {
                        if ($plan->effective_month <= $day && $plan->effective_month >= $effective) {
                            $plannedAmount = (float) $plan->gross_salary;
                            $effective = $plan->effective_month;
                        }
                    }
                    $divisor = $monthWorkDays[$month] ?: 1;
                    $current += $currentAmount / $divisor;
                    $planned += $plannedAmount / $divisor;
                    $worked++;
                    $hasVisibleDay = true;
                }
                $monthly[$month] = [
                    'current' => round($current, 2),
                    'planned' => round($planned, 2),
                    'working_days' => $worked,
                ];
                $summary[$month]['current'] += $current;
                $summary[$month]['planned'] += $planned;
            }
            if ($hasVisibleDay) $rows[] = [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'months' => $monthly,
                'reviews' => $plans->map(static fn ($plan) => [
                    'id' => $plan->id,
                    'month' => $plan->effective_month,
                    'status' => $plan->status,
                    'gross_salary' => $plan->gross_salary,
                ])->values()->all(),
            ];
        }
        foreach ($summary as &$month) {
            $month['current'] = round($month['current'], 2);
            $month['planned'] = round($month['planned'], 2);
            $month['difference'] = round($month['planned'] - $month['current'], 2);
        }
        unset($month);
        return ['status' => 200, 'data' => [
            'year' => $year,
            'summary' => $summary,
            'employees' => $rows,
            'departments' => $departments->whereIn('id', $permitted)->values()
                ->map(static fn (Department $row) => (object) $row->getRawOriginal()),
            'calendar_source' => $calendar['source'] ?? null,
        ]];
    }
}
