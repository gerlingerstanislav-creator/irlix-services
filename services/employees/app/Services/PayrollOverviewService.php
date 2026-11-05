<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentAssignmentHistory;
use App\Models\EmploymentPeriod;
use App\Models\SalaryHistory;
use App\Models\SalaryReview;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class PayrollOverviewService
{
    public function __construct(private readonly PayrollMonthCalculator $calculator) {}

    /**
     * Calculate year FOT from historical employment and assignments. Filtered
     * parents include only descendants within the caller's authorized scope.
     * Status filtering uses the employee's current employment status; the
     * historical register and payroll remain determined by dated records.
     */
    public function overview(int $year, array $permitted, array $departmentIds = [], array $statuses = []): array
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
            if (!($item['is_working'] ?? false) || substr((string) $day, 0, 4) !== (string) $year) continue;
            $month = (int) substr((string) $day, 5, 2);
            if ($month < 1 || $month > 12) continue;
            $working[$day] = true;
            $monthWorkDays[$month]++;
        }

        $departments = Department::query()->get(['id', 'name', 'parent_id']);
        $permitted = array_values(array_unique(array_map('intval', $permitted)));
        $allowed = array_fill_keys($permitted, true);
        $selected = $departmentIds ?: $permitted;
        $children = [];
        foreach ($departments as $department) {
            $children[(int) ($department->parent_id ?? 0)][] = (int) $department->id;
        }
        $eligible = [];
        $queue = array_values(array_unique(array_map('intval', $selected)));
        while ($queue) {
            $id = array_shift($queue);
            if (!isset($allowed[$id]) || isset($eligible[$id])) continue;
            $eligible[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                if (isset($allowed[$child]) && !isset($eligible[$child])) $queue[] = $child;
            }
        }

        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);
        $query = Employee::query()->select(['id', 'full_name', 'employment_status']);
        if ($statuses) $query->whereIn('employment_status', $statuses);
        $employees = $query->orderBy('full_name')->get();

        // The employment-period table, not the latest employee status or
        // assignment alone, decides whether payroll may accrue on a date.
        $periods = EmploymentPeriod::query()->where('started_at', '<=', $yearEnd)
            ->where(static fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $yearStart))
            ->orderBy('started_at')->get()->groupBy('employee_id');
        $salary = SalaryHistory::query()->orderBy('effective_from')->get()->groupBy('employee_id');
        $assignments = EmploymentAssignmentHistory::query()->orderBy('effective_from')
            ->get()->groupBy('employee_id');
        $reviews = SalaryReview::query()->where('status', '<>', 'cancelled')
            ->orderBy('effective_month')->get()->groupBy('employee_id');

        $summary = [];
        for ($month = 1; $month <= 12; $month++) $summary[$month] = ['current' => 0, 'planned' => 0];
        $rows = [];
        foreach ($employees as $employee) {
            $employment = $periods->get($employee->id, collect());
            $history = $assignments->get($employee->id, collect());

            // Do not hide a person whose only overlap is a non-working day,
            // or whose salary history is absent. Their FOT can legitimately be zero.
            if (!$this->calculator->visible($employment, $history, $eligible, $yearStart, $yearEnd)) {
                continue;
            }
            $salaryHistory = $salary->get($employee->id, collect());
            $plans = $reviews->get($employee->id, collect());
            $monthly = $this->calculator->calculate(
                $year, $working, $monthWorkDays, $employment, $history, $salaryHistory, $plans, $eligible
            );

            foreach ($monthly as $month => $amounts) {
                $summary[$month]['current'] += $amounts['current'];
                $summary[$month]['planned'] += $amounts['planned'];
            }
            $rows[] = [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'employment_status' => $employee->employment_status,
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
