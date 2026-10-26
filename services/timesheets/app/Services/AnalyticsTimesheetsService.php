<?php
namespace App\Services;

use App\Models\TimesheetEntry;
use App\Models\FinalApproval;
use App\Models\FinalApprovalException;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

final class AnalyticsTimesheetsService
{
    public function __construct(private readonly TimesheetsContext $context) {}

    public function report(Request $request, array $current): array
    {
        [$start, $end] = $this->context->monthBounds($request->query('month'));
    [$employees, $departments] = $this->context->directory($request);
    $allAssignments = $this->context->assignments($request);
    $access = $this->context->accessInfo($request, $current, $allAssignments, $departments);
    $this->context->assertContourPermission($access, 'timesheets.analytics.view');
    $filters = $request->validate([
        'department_id' => ['nullable', 'regex:/^(?:[1-9][0-9]*|partners)$/'],
        'client_id' => ['nullable', 'integer', 'min:1'],
        'staff_type' => ['nullable', 'in:all,staff,partners'],
    ]);
    $selectedDepartment = $filters['department_id'] ?? null;
    $selectedClient = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
    $staffType = $filters['staff_type'] ?? 'all';
    $from = $start->toDateString();
    $to = $end->toDateString();
    [$employeeIds, $visibleAssignments] = $this->context->scopeForPeriod($current, $employees, $allAssignments, $access, $from, $to);

    // Only Employees-declared production departments contribute to this report.
    // Do not infer production status from a department name or client assignment.
    $productionDepartmentIds = $departments
        ->filter(fn ($department) => in_array(strtolower((string) ($department['is_production'] ?? '')), ['1', 'true', 't'], true))
        ->pluck('id')->map(fn ($id) => (int) $id)->all();
    $employeeMap = $employees->keyBy(fn ($employee) => (int) $employee['id']);
    $employeeIds = array_values(array_filter($employeeIds, fn ($id) =>
        in_array((int) ($employeeMap->get((int) $id)['department_id'] ?? 0), $productionDepartmentIds, true)));
    $visibleAssignments = $visibleAssignments
        ->filter(fn ($assignment) => in_array((int) $assignment['employee_id'], $employeeIds, true))
        ->values();

    // Options come from the caller's permission scope, before optional filters.
    $departmentOptions = $departments
        ->filter(fn ($department) => in_array((int) $department['id'], array_unique(array_map(
            fn ($id) => (int) ($employeeMap->get((int) $id)['department_id'] ?? 0), $employeeIds
        )), true))
        ->map(fn ($department) => ['id' => (string) $department['id'],
            'name' => $department['name'] ?? ('Подразделение #'.$department['id'])])
        ->values()->all();
    $staffClientOptions = $visibleAssignments
        ->mapWithKeys(fn ($assignment) => [(int) $assignment['client_id'] => [
            'id' => (int) $assignment['client_id'], 'name' => (string) $assignment['client_name'],
        ]])->values()->all();

    if ($staffType === 'partners' || $selectedDepartment === 'partners') {
        $employeeIds = [];
        $visibleAssignments = collect();
    } else {
        if ($selectedDepartment !== null) {
            $employeeIds = array_values(array_filter($employeeIds, fn ($id) =>
                (int) ($employeeMap->get((int) $id)['department_id'] ?? 0) === (int) $selectedDepartment));
        }
        if ($selectedClient !== null) {
            $visibleAssignments = $visibleAssignments->filter(fn ($assignment) =>
                (int) $assignment['client_id'] === $selectedClient)->values();
            // A client filter must not keep staff without that client's connection.
            $connected = $visibleAssignments->pluck('employee_id')->map(fn ($id) => (int) $id)->all();
            $employeeIds = array_values(array_filter($employeeIds, fn ($id) => in_array((int) $id, $connected, true)));
        }
        $visibleAssignments = $visibleAssignments->filter(fn ($assignment) =>
            in_array((int) $assignment['employee_id'], $employeeIds, true))->values();
    }

    $projectIds = $visibleAssignments->pluck('client_member_id')->unique()->values()->all();
    $approved = FinalApproval::query()
        ->whereIn('employee_id', $employeeIds ?: [0])
        ->where('month', $from)
        ->get();

    $exceptions = FinalApprovalException::query()
        ->whereIn('employee_id', $employeeIds ?: [0])
        ->whereBetween('work_date', [$from, $to])
        ->get();
    $allEntries = $projectIds
        ? TimesheetEntry::query()
            ->whereIn('employee_id', $employeeIds ?: [0])
            ->whereIn('client_member_id', $projectIds)
            ->whereBetween('work_date', [$from, $to])
            ->get()
        : collect();

    [$entries, $unconfirmedEntries, $conflictedEntries] =
        app(\App\Services\CommercialAnalyticsClassifier::class)->classify(
            $allEntries,
            $approved,
            $exceptions,
            (array) $request->attributes->get('locked_reporting_periods', []),
            $allAssignments,
        );

    // Pre-aggregate once instead of scanning the entire month's facts
    // again for each employee in the report.
    $confirmedByEmployee = $entries->groupBy('employee_id')->map->sum('hours');
    $unconfirmedByEmployee = $unconfirmedEntries->groupBy('employee_id')->map->sum('hours');
    $conflictedByEmployee = $conflictedEntries->groupBy('employee_id')->map->sum('hours');

    $absences = collect($this->context->absenceData($request, $from, $to, $employeeIds));
    $workdays = collect(CarbonPeriod::create($start, $end))->filter(fn (Carbon $day) => $day->isWeekday())->count();
    $normHours = $workdays * 8;

    $typeLabels = [
        'paid_vacation' => 'Оплачиваемый отпуск',
        'unpaid_vacation' => 'Неоплачиваемый отпуск',
        'sick_leave' => 'Больничный',
        'maternity_leave' => 'Декрет',
        'day_off' => 'Отгул',
    ];

    $rows = [];

    foreach ($employeeIds as $employeeId) {
        $employee = $employeeMap->get((int) $employeeId);
        if (!$employee) continue;

        $commercial = (float) ($confirmedByEmployee->get($employeeId) ?? 0);
        $unconfirmed = (float) ($unconfirmedByEmployee->get($employeeId) ?? 0);
        $conflicted = (float) ($conflictedByEmployee->get($employeeId) ?? 0);
        $absenceHours = [];

        foreach ($typeLabels as $type => $label) {
            $hours = 0;
            foreach ($absences->filter(fn ($a) =>
                (int) ($a['employee_id'] ?? 0) === (int) $employeeId
                && ($a['type'] ?? null) === $type
                && ($a['status'] ?? null) === 'confirmed'
            ) as $absence) {
                $absenceStart = Carbon::parse(max((string) $absence['starts_on'], $from));
                $absenceEnd = Carbon::parse(min((string) $absence['ends_on'], $to));
                foreach (CarbonPeriod::create($absenceStart, $absenceEnd) as $day) {
                    if ($day->isWeekday()) $hours += 8;
                }
            }
            $absenceHours[$type] = $hours;
        }

        $absenceTotal = array_sum($absenceHours);
        $idle = max(0, $normHours - $commercial - $absenceTotal);

        $rows[] = [
            'employee_id' => (int) $employeeId,
            'employee_name' => $employee['full_name'] ?? 'Сотрудник',
            'department_id' => $employee['department_id'] ?? null,
            'department_name' => $employee['department_name'] ?? 'Без подразделения',
            'norm_hours' => $normHours,
            'commercial_hours' => $commercial,
            'unconfirmed_hours' => $unconfirmed,
            'conflicted_hours' => $conflicted,
            'absence_hours' => $absenceTotal,
            'commercial_percent' => $normHours > 0 ? round($commercial / $normHours * 100, 2) : 0,
            'idle_hours' => $idle,
            'absences' => $absenceHours,
        ];
    }

    $totals = [
        'employees' => count($rows),
        'norm_hours' => array_sum(array_column($rows, 'norm_hours')),
        'commercial_hours' => array_sum(array_column($rows, 'commercial_hours')),
        'unconfirmed_hours' => array_sum(array_column($rows, 'unconfirmed_hours')),
        'conflicted_hours' => array_sum(array_column($rows, 'conflicted_hours')),
        'absence_hours' => array_sum(array_column($rows, 'absence_hours')),
        'idle_hours' => array_sum(array_column($rows, 'idle_hours')),
        'absences' => [],
    ];
    foreach ($typeLabels as $type => $label) {
        $totals['absences'][$type] = array_sum(array_map(fn ($row) => $row['absences'][$type] ?? 0, $rows));
    }
    $totals['commercial_percent'] = $totals['norm_hours'] > 0
        ? round($totals['commercial_hours'] / $totals['norm_hours'] * 100, 2)
        : 0;

    $byDepartment = collect($rows)
        ->groupBy(fn ($row) => (string) ($row['department_id'] ?? 'none'))
        ->map(function ($group) use ($typeLabels) {
            $norm = $group->sum('norm_hours');
            $absenceTotals = [];
            foreach ($typeLabels as $type => $label) {
                $absenceTotals[$type] = $group->sum(fn ($row) => $row['absences'][$type] ?? 0);
            }

            return [
                'department_id' => $group->first()['department_id'],
                'department_name' => $group->first()['department_name'],
                'employees' => $group->count(),
                'norm_hours' => $norm,
                'commercial_hours' => $group->sum('commercial_hours'),
                'unconfirmed_hours' => $group->sum('unconfirmed_hours'),
                'conflicted_hours' => $group->sum('conflicted_hours'),
                'absence_hours' => $group->sum('absence_hours'),
                'commercial_percent' => $norm > 0 ? round($group->sum('commercial_hours') / $norm * 100, 2) : 0,
                'idle_hours' => $group->sum('idle_hours'),
                'absences' => $absenceTotals,
            ];
        })
        ->values();

    $partnerReport = app(\App\Services\PartnerAnalyticsService::class)->summary(
        (array) $request->attributes->get('all_client_members', []),
        (array) $request->attributes->get('locked_reporting_periods', []),
        $current,
        $access,
        $from,
        $to,
        $selectedClient,
        $staffType !== 'staff' && ($selectedDepartment === null || $selectedDepartment === 'partners'),
    );

    // All three representations share the same staff + distinct-partner pool.
    // A partner with several client memberships contributes one monthly norm.
    $partnerRows = $partnerReport['rows'];
    $partnerTotals = $partnerReport['totals'];
    $totals['employees'] += $partnerTotals['specialists'];
    foreach (['norm_hours', 'commercial_hours', 'unconfirmed_hours', 'conflicted_hours', 'idle_hours'] as $metric) {
        $totals[$metric] += $partnerTotals[$metric];
    }
    $totals['commercial_percent'] = $totals['norm_hours'] > 0
        ? round($totals['commercial_hours'] / $totals['norm_hours'] * 100, 2)
        : ($partnerTotals['specialists'] > 0 && count($rows) === 0 ? 100 : 0);
    if ($partnerRows) {
        $partnerNorm = $partnerTotals['norm_hours'];
        $byDepartment->push([
            'department_id' => 'partners',
            'department_name' => 'Партнёры',
            'employees' => $partnerTotals['specialists'],
            'norm_hours' => $partnerNorm,
            'commercial_hours' => $partnerTotals['commercial_hours'],
            'unconfirmed_hours' => $partnerTotals['unconfirmed_hours'],
            'conflicted_hours' => $partnerTotals['conflicted_hours'],
            'absence_hours' => 0,
            'commercial_percent' => 100,
            'idle_hours' => $partnerTotals['idle_hours'],
            'absences' => array_fill_keys(array_keys($typeLabels), 0),
        ]);
    }

    $clientOptions = collect(array_merge($staffClientOptions, $partnerReport['client_options']))
        ->unique('id')->sortBy('name')->values()->all();
    if ($partnerReport['client_options']) $departmentOptions[] = ['id' => 'partners', 'name' => 'Партнёры'];

    return [
        'month' => $start->format('Y-m'),
        'filters' => ['departments' => $departmentOptions, 'clients' => $clientOptions],
        'type_labels' => $typeLabels,
        'partners' => $partnerReport['rows'],
        'partner_totals' => $partnerReport['totals'],
        'totals' => $totals,
        'employees' => $rows,
        'departments' => $byDepartment,
        'access' => $access,
    ];;
    }
}
