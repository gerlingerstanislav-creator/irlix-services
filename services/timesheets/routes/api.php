<?php

use App\Support\CurrentEmployee;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

$tokenOf = fn (Request $request): ?string => $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');

$dependencyGet = function (Request $request, string $env, string $default, string $path, array $query = []) use ($tokenOf): array {
    $response = Http::withToken((string) $tokenOf($request))
        ->acceptJson()
        ->timeout(8)
        ->get(rtrim((string) env($env, $default), '/').$path, $query);

    abort_unless($response->successful(), 503, "Dependency request failed: {$path} ({$response->status()})");
    $data = $response->json('data');

    return is_array($data) ? $data : [];
};

$monthBounds = function (?string $month): array {
    $start = $month
        ? Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth()
        : now()->startOfMonth();

    return [$start, $start->copy()->endOfMonth()];
};

$audit = function (
    string $action,
    ?int $actorId,
    ?int $employeeId = null,
    ?int $projectId = null,
    ?string $workDate = null,
    mixed $before = null,
    mixed $after = null,
): void {
    DB::table('timesheet_audit')->insert([
        'action' => $action,
        'actor_employee_id' => $actorId,
        'employee_id' => $employeeId,
        'project_id' => $projectId,
        'work_date' => $workDate,
        'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
    ]);
};

$directory = function (Request $request) use ($dependencyGet, $tokenOf): array {
    $response = Http::withToken((string) $tokenOf($request))->acceptJson()->timeout(8)
        ->get(rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/').'/clients-directory');
    if ($response->successful() && is_array($response->json('data.employees'))) {
        return [collect($response->json('data.employees')), collect($response->json('data.departments', []))];
    }
    return [
        collect($dependencyGet($request, 'EMPLOYEES_URL', 'http://employees:8000/api', '/employees')),
        collect($dependencyGet($request, 'EMPLOYEES_URL', 'http://employees:8000/api', '/departments')),
    ];
};

$assignments = function (Request $request): array {
    try {
        $snapshot = app(\App\Support\AssignmentsDirectory::class)->snapshot();
        $request->attributes->set('locked_reporting_periods', $snapshot['locked_reporting_periods']);
        return $snapshot['assignments'];
    } catch (\Throwable $error) {
        abort(503, 'Данные подключений Clients временно недоступны. Повторите запрос.');
    }
};

$overlapsPeriod = fn (array $a, string $from, string $to): bool =>
    $a['valid_from'] <= $to && (empty($a['valid_to']) || $a['valid_to'] >= $from);

$activeAssignments = function (array $all, int $employeeId, string $date): array {
    return array_values(array_filter($all, fn (array $a) =>
        $a['employee_id'] === $employeeId
        && $a['valid_from'] <= $date
        && (empty($a['valid_to']) || $a['valid_to'] >= $date)
    ));
};

$accessInfo = function (Request $request, array $employee, array $allAssignments, $departments) use ($dependencyGet): array {
    $assignedAccess = $dependencyGet($request, 'EMPLOYEES_URL', 'http://employees:8000/api', '/access/me');
    $roles = array_values(array_unique(array_merge(
        $request->attributes->get('identity')['realm_roles'] ?? [],
        $assignedAccess['roles'] ?? [],
    )));
    $employeeId = (int) $employee['id'];
    $platformAdmin = count(array_intersect(['platform-admin', 'platform-tester'], $roles)) > 0;
    $managedDepartmentIds = $departments
        ->filter(fn ($d) => (int) ($d['manager_id'] ?? 0) === $employeeId)
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();
    do {
        $previousCount = count($managedDepartmentIds);
        foreach ($departments as $department) {
            $id = (int) ($department['id'] ?? 0);
            if ($id && in_array((int) ($department['parent_id'] ?? 0), $managedDepartmentIds, true)
                && !in_array($id, $managedDepartmentIds, true)) $managedDepartmentIds[] = $id;
        }
    } while (count($managedDepartmentIds) !== $previousCount);
    $isAccountManager = collect($allAssignments)
        ->contains(fn (array $a) => (int) ($a['account_employee_id'] ?? 0) === $employeeId);
    $clientContour = $dependencyGet($request, 'CLIENTS_URL', 'http://clients:8000/api', '/permissions/me');
    $contourPermissions = (array) ($clientContour['permissions'] ?? []);

    $position = mb_strtolower((string) ($employee['position'] ?? ''));
    $canLock = $platformAdmin
        || str_contains($position, 'руководитель направления аккаунтинга')
        || str_contains($position, 'руководитель клиентской службы');

    return compact('roles', 'platformAdmin', 'managedDepartmentIds', 'isAccountManager', 'canLock', 'contourPermissions');
};

$assertContourPermission = function (array $access, string $permission): void {
    abort_unless($access['platformAdmin'] || ($access['contourPermissions'][$permission]['allowed'] ?? false), 403, 'Недостаточно прав для этого раздела Timesheets.');
};

$scopeForPeriod = function (
    array $current,
    $employees,
    array $allAssignments,
    array $access,
    string $from,
    string $to,
) use ($overlapsPeriod): array {
    $currentId = (int) $current['id'];
    $employeeMap = $employees->keyBy(fn ($e) => (int) $e['id']);

    if ($access['platformAdmin']) {
        $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    } else {
        $employeeIds = $employees
            ->filter(fn ($e) => in_array((int) ($e['department_id'] ?? 0), $access['managedDepartmentIds'], true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        foreach ($allAssignments as $a) {
            if ((int) ($a['account_employee_id'] ?? 0) === $currentId && $overlapsPeriod($a, $from, $to)) {
                $employeeIds[] = (int) $a['employee_id'];
            }
        }
        $employeeIds = array_values(array_unique($employeeIds));
    }

    $visibleAssignments = collect($allAssignments)->filter(function (array $a) use (
        $access,
        $employeeMap,
        $currentId,
        $overlapsPeriod,
        $from,
        $to
    ): bool {
        if (!$overlapsPeriod($a, $from, $to)) return false;
        if ($access['platformAdmin']) return true;
        if ((int) ($a['account_employee_id'] ?? 0) === $currentId) return true;

        $employee = $employeeMap->get((int) $a['employee_id']);
        return $employee
            && in_array((int) ($employee['department_id'] ?? 0), $access['managedDepartmentIds'], true);
    })->values();

    return [$employeeIds, $visibleAssignments];
};

$canManageAssignment = function (
    array $current,
    array $targetEmployee,
    array $assignment,
    array $access,
): bool {
    if ($access['platformAdmin']) return true;
    if ((int) ($assignment['account_employee_id'] ?? 0) === (int) $current['id']) return true;

    return in_array((int) ($targetEmployee['department_id'] ?? 0), $access['managedDepartmentIds'], true);
};

$assertReportEditable = function (Request $request, int $clientId, string $workDate, ?string $to = null) use ($dependencyGet): void {
    $status = $dependencyGet($request, 'CLIENTS_URL', 'http://clients:8000/api', '/reporting-period-lock', [
        'client_id' => $clientId,
        'work_date' => $workDate,
        ...($to ? ['to' => $to] : []),
    ]);
    abort_if($status['locked'] ?? false, 423, 'ТШ заблокирован после отправки клиенту на согласование.');
};

$assertEmployeeDatesEditable = function (Request $request, int $employeeId, array $dates) use ($assignments, $assertReportEditable): void {
    $allAssignments = $assignments($request);
    $checked = [];
    foreach (array_values(array_unique($dates)) as $date) {
        foreach ($allAssignments as $assignment) {
            if ($assignment['employee_id'] !== $employeeId
                || $assignment['valid_from'] > $date
                || (!empty($assignment['valid_to']) && $assignment['valid_to'] < $date)) continue;
            $key = $assignment['client_id'].'-'.$date;
            if (isset($checked[$key])) continue;
            $assertReportEditable($request, (int) $assignment['client_id'], $date);
            $checked[$key] = true;
        }
    }
};

$absenceData = function (Request $request, string $from, string $to, array $employeeIds) use ($dependencyGet): array {
    if (!$employeeIds) return [];

    return $dependencyGet($request, 'VACATIONS_URL', 'http://vacations:8000/api', '/calendar-absences', [
        'from' => $from,
        'to' => $to,
        'employee_ids' => array_values(array_unique($employeeIds)),
    ]);
};

Route::get('/health', fn () => response()->json([
    'service' => 'timesheets',
    'status' => 'ok',
    'database' => DB::select('select 1') ? 'ok' : 'error',
]));

Route::get('/workspace', function (Request $request, CurrentEmployee $currentEmployee) use (
    $monthBounds,
    $directory,
    $assignments,
    $accessInfo,
    $absenceData,
    $overlapsPeriod,
) {
    $employee = $currentEmployee->resolve($request);
    [$start, $end] = $monthBounds($request->query('month'));
    [, $departments] = $directory($request);
    $allAssignments = $assignments($request);

    $from = $start->toDateString();
    $to = $end->toDateString();
    $employeeAssignments = collect($allAssignments)
        ->filter(fn (array $a) => $a['employee_id'] === (int) $employee['id'] && $overlapsPeriod($a, $from, $to))
        ->values();

    return response()->json(['data' => [
        'employee' => $employee,
        'month' => $start->format('Y-m'),
        'assignments' => $employeeAssignments,
        'entries' => DB::table('timesheet_entries')
            ->where('employee_id', $employee['id'])
            ->whereBetween('work_date', [$from, $to])
            ->orderBy('work_date')
            ->get(),
        'confirmations' => DB::table('employee_confirmations')
            ->where('employee_id', $employee['id'])
            ->whereBetween('work_date', [$from, $to])
            ->pluck('confirmed_at', 'work_date'),
        'final_approval_exceptions' => DB::table('final_approval_exceptions')
            ->where('employee_id', $employee['id'])
            ->whereBetween('work_date', [$from, $to])
            ->get(),
        'final_approvals' => DB::table('final_approvals')
            ->where('employee_id', $employee['id'])
            ->where('month', $from)
            ->get(),
        'absences' => $absenceData($request, $from, $to, [(int) $employee['id']]),
        'period_locked' => false,
        'access' => $accessInfo($request, $employee, $allAssignments, $departments),
    ]]);
});

Route::put('/entries', function (Request $request, CurrentEmployee $currentEmployee) use (
    $assignments,
    $activeAssignments,
    $audit,
    $assertReportEditable,
) {
    $employee = $currentEmployee->resolve($request);
    $data = $request->validate([
        'work_date' => ['required', 'date'],
        'project_id' => ['required', 'integer', 'min:1'],
        'hours' => ['required', 'numeric', 'min:0', 'max:24'],
        'description' => ['nullable', 'string', 'max:4000'],
    ]);

    $hours = (float) $data['hours'];
    abort_if(abs($hours * 4 - round($hours * 4)) > 0.00001, 422, 'Hours must be a multiple of 0.25');

    $allAssignments = $assignments($request);
    $assignment = collect($activeAssignments($allAssignments, (int) $employee['id'], $data['work_date']))
        ->firstWhere('project_id', (int) $data['project_id']);
    abort_unless($assignment, 422, 'Project assignment is not active for the selected date');
    $assertReportEditable($request, (int) $assignment['client_id'], $data['work_date']);

    $month = Carbon::parse($data['work_date'])->startOfMonth()->toDateString();
    abort_if(
        DB::table('final_approvals')
            ->where('employee_id', $employee['id'])
            ->where('project_id', $data['project_id'])
            ->where('month', $month)
            ->exists()
        && !DB::table('final_approval_exceptions')->where('employee_id', $employee['id'])
            ->where('project_id', $data['project_id'])->where('work_date', $data['work_date'])->exists(),
        423,
        'This project timesheet is finally approved',
    );

    $existing = DB::table('timesheet_entries')
        ->where('employee_id', $employee['id'])
        ->where('project_id', $data['project_id'])
        ->where('work_date', $data['work_date'])
        ->first();

    $otherHours = (float) DB::table('timesheet_entries')
        ->where('employee_id', $employee['id'])
        ->where('work_date', $data['work_date'])
        ->where('project_id', '!=', $data['project_id'])
        ->sum('hours');
    abort_if($otherHours + $hours > 24.00001, 422, 'Total hours for the day cannot exceed 24');

    DB::transaction(function () use ($employee, $data, $hours, $assignment, $existing): void {
        if ($hours <= 0) {
            if ($existing) DB::table('timesheet_entries')->where('id', $existing->id)->delete();
        } else {
            $payload = [
                'employee_id' => (int) $employee['id'],
                'client_id' => $assignment['client_id'],
                'project_id' => $assignment['project_id'],
                'account_employee_id' => $assignment['account_employee_id'],
                'work_date' => $data['work_date'],
                'hours' => $hours,
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'updated_at' => now(),
            ];

            if ($existing) DB::table('timesheet_entries')->where('id', $existing->id)->update($payload);
            else DB::table('timesheet_entries')->insert([...$payload, 'created_at' => now()]);
        }

        DB::table('employee_confirmations')
            ->where('employee_id', $employee['id'])
            ->where('work_date', $data['work_date'])
            ->delete();
    });

    $audit(
        $hours <= 0 ? 'entry_deleted' : ($existing ? 'entry_updated' : 'entry_created'),
        (int) $employee['id'],
        (int) $employee['id'],
        (int) $data['project_id'],
        $data['work_date'],
        $existing ? (array) $existing : null,
        $hours <= 0 ? null : ['hours' => $hours, 'description' => $data['description'] ?? null],
    );

    return response()->json(['data' => ['ok' => true]]);
});

Route::post('/confirm', function (Request $request, CurrentEmployee $currentEmployee) use ($audit, $assertEmployeeDatesEditable, $assignments) {
    $employee = $currentEmployee->resolve($request);
    $data = $request->validate([
        'dates' => ['required', 'array', 'min:1', 'max:62'],
        'dates.*' => ['date'],
    ]);

    $requestedDates = array_values(array_unique($data['dates']));
    $allAssignments = $assignments($request);
    $confirmableDates = array_values(array_filter($requestedDates, fn (string $date) =>
        collect($allAssignments)->contains(fn (array $assignment) =>
            $assignment['employee_id'] === (int) $employee['id']
            && $assignment['valid_from'] <= $date
            && (empty($assignment['valid_to']) || $assignment['valid_to'] >= $date)
        )
    ));

    $assertEmployeeDatesEditable($request, (int) $employee['id'], $confirmableDates);
    foreach ($confirmableDates as $date) {
        DB::table('employee_confirmations')->updateOrInsert(
            ['employee_id' => $employee['id'], 'work_date' => $date],
            ['confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        );
        $audit('employee_confirmed', (int) $employee['id'], (int) $employee['id'], null, $date, null, ['confirmed' => true]);
    }

    return response()->json(['data' => [
        'confirmed' => count($confirmableDates),
        'skipped_without_assignment' => count($requestedDates) - count($confirmableDates),
    ]]);
});

Route::post('/unconfirm', function (Request $request, CurrentEmployee $currentEmployee) use ($audit, $assertEmployeeDatesEditable, $assignments) {
    $employee = $currentEmployee->resolve($request);
    $data = $request->validate([
        'dates' => ['required', 'array', 'min:1', 'max:62'],
        'dates.*' => ['date'],
    ]);

    $allAssignments = $assignments($request);
    $assertEmployeeDatesEditable($request, (int) $employee['id'], $data['dates']);
    foreach (array_values(array_unique($data['dates'])) as $date) {
        $month = Carbon::parse($date)->startOfMonth()->toDateString();
        $projectIds = collect($allAssignments)
            ->filter(fn (array $assignment) =>
                $assignment['employee_id'] === (int) $employee['id']
                && $assignment['valid_from'] <= $date
                && (empty($assignment['valid_to']) || $assignment['valid_to'] >= $date)
            )
            ->pluck('project_id')
            ->map(fn ($projectId) => (int) $projectId)
            ->unique()
            ->values()
            ->all();

        if ($projectIds) {
            abort_if(
                DB::table('final_approvals')
                    ->where('employee_id', $employee['id'])
                    ->where('month', $month)
                    ->whereIn('project_id', $projectIds)
                    ->whereNotIn('project_id', DB::table('final_approval_exceptions')
                        ->select('project_id')->where('employee_id', $employee['id'])->where('work_date', $date))
                    ->exists(),
                423,
                'Финально подтверждённый день не может быть отменён сотрудником.',
            );
        }

        DB::table('employee_confirmations')
            ->where('employee_id', $employee['id'])
            ->where('work_date', $date)
            ->delete();
        $audit('employee_unconfirmed', (int) $employee['id'], (int) $employee['id'], null, $date, ['confirmed' => true], ['confirmed' => false]);
    }

    return response()->json(['data' => ['ok' => true]]);
});

Route::get('/management', function (Request $request, CurrentEmployee $currentEmployee) use (
    $monthBounds,
    $directory,
    $assignments,
    $accessInfo,
    $scopeForPeriod,
    $absenceData,
    $assertContourPermission,
) {
    $current = $currentEmployee->resolve($request);
    [$start, $end] = $monthBounds($request->query('month'));
    [$employees, $departments] = $directory($request);
    $allAssignments = $assignments($request);
    $access = $accessInfo($request, $current, $allAssignments, $departments);
    $assertContourPermission($access, 'timesheets.management.view');
    $from = $start->toDateString();
    $to = $end->toDateString();
    [$employeeIds, $visibleAssignments] = $scopeForPeriod($current, $employees, $allAssignments, $access, $from, $to);
    $projectIds = $visibleAssignments->pluck('project_id')->unique()->values()->all();
    $visibleClientIds = $visibleAssignments->pluck('client_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    $clientReportingPeriods = collect($request->attributes->get('locked_reporting_periods', []))
        ->filter(fn (array $period) =>
            in_array((int) ($period['client_id'] ?? 0), $visibleClientIds, true)
            && ($period['period_start'] ?? '') <= $to
            && ($period['period_end'] ?? '') >= $from
        )
        ->values();

    return response()->json(['data' => [
        'month' => $start->format('Y-m'),
        'current_employee' => $current,
        'access' => $access,
        'employees' => $employees->filter(fn ($e) => in_array((int) $e['id'], $employeeIds, true))->values(),
        'departments' => $departments,
        'assignments' => $visibleAssignments,
        'entries' => $projectIds
            ? DB::table('timesheet_entries')
                ->whereIn('employee_id', $employeeIds ?: [0])
                ->whereIn('project_id', $projectIds)
                ->whereBetween('work_date', [$from, $to])
                ->get()
            : collect(),
        'confirmations' => DB::table('employee_confirmations')
            ->whereIn('employee_id', $employeeIds ?: [0])
            ->whereBetween('work_date', [$from, $to])
            ->get(),
        'final_approval_exceptions' => DB::table('final_approval_exceptions')
            ->whereIn('employee_id', $employeeIds ?: [0])
            ->whereBetween('work_date', [$from, $to])
            ->get(),
        'final_approvals' => DB::table('final_approvals')
            ->whereIn('employee_id', $employeeIds ?: [0])
            ->whereIn('project_id', $projectIds ?: [0])
            ->where('month', $from)
            ->get(),
        'absences' => $absenceData($request, $from, $to, $employeeIds),
        'client_reporting_periods' => $clientReportingPeriods,
        'period_locked' => false,
    ]]);
});

Route::put('/management/entries', function (Request $request, CurrentEmployee $currentEmployee) use (
    $directory,
    $assignments,
    $accessInfo,
    $activeAssignments,
    $canManageAssignment,
    $audit,
    $assertReportEditable,
    $assertContourPermission,
) {
    $current = $currentEmployee->resolve($request);
    [$employees, $departments] = $directory($request);
    $allAssignments = $assignments($request);
    $access = $accessInfo($request, $current, $allAssignments, $departments);
    $assertContourPermission($access, 'timesheets.management.manage');

    $baseRules = [
        'employee_id' => ['required', 'integer', 'min:1'],
        'work_date' => ['required', 'date_format:Y-m-d'],
    ];
    if ($request->has('entries')) {
        $data = $request->validate([...$baseRules,
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.project_id' => ['required', 'integer', 'min:1', 'distinct'],
            'entries.*.hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'entries.*.description' => ['nullable', 'string', 'max:4000'],
        ]);
        $rows = $data['entries'];
    } else {
        // Retain compatibility with an already open frontend from the previous release.
        $data = $request->validate([...$baseRules,
            'project_id' => ['required', 'integer', 'min:1'],
            'hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'description' => ['nullable', 'string', 'max:4000'],
        ]);
        $rows = [$data];
    }
    $target = $employees->first(fn ($e) => (int) $e['id'] === (int) $data['employee_id']);
    abort_unless($target, 404, 'Employee not found');
    $prepared = [];
    foreach ($rows as $row) {
        $hours = (float) $row['hours'];
        abort_if(abs($hours * 4 - round($hours * 4)) > 0.00001, 422, 'Hours must be a multiple of 0.25');
        $assignment = collect($activeAssignments($allAssignments, (int) $data['employee_id'], $data['work_date']))
            ->firstWhere('project_id', (int) $row['project_id']);
        abort_unless($assignment, 422, 'Project assignment is not active for the selected date');
        abort_unless($canManageAssignment($current, $target, $assignment, $access), 403, 'Timesheet is outside your scope');
        $assertReportEditable($request, (int) $assignment['client_id'], $data['work_date']);
        $prepared[] = ['assignment' => $assignment, 'hours' => $hours, 'description' => trim((string) ($row['description'] ?? '')) ?: null];
    }
    $month = Carbon::parse($data['work_date'])->startOfMonth()->toDateString();
    DB::transaction(function () use ($data, $prepared, $current, $month, $audit): void {
        // Lock the whole employee/day, and validate the final batch total rather than intermediate totals.
        $existingRows = DB::table('timesheet_entries')->where('employee_id', $data['employee_id'])
            ->where('work_date', $data['work_date'])->orderBy('id')->lockForUpdate()->get();
        $projectIds = array_column(array_column($prepared, 'assignment'), 'project_id');
        $otherHours = (float) $existingRows->reject(fn ($entry) => in_array((int) $entry->project_id, $projectIds, true))->sum('hours');
        abort_if($otherHours + array_sum(array_column($prepared, 'hours')) > 24.00001, 422, 'Total hours for the day cannot exceed 24');
        foreach ($prepared as $row) {
            $assignment = $row['assignment'];
            $existing = $existingRows->first(fn ($entry) => (int) $entry->project_id === $assignment['project_id']);
            $payload = [
                'employee_id' => (int) $data['employee_id'], 'client_id' => $assignment['client_id'],
                'project_id' => $assignment['project_id'], 'account_employee_id' => $assignment['account_employee_id'],
                'work_date' => $data['work_date'], 'hours' => $row['hours'], 'description' => $row['description'],
                'updated_at' => now(),
            ];
            if ($row['hours'] <= 0) {
                if ($existing) DB::table('timesheet_entries')->where('id', $existing->id)->delete();
            } elseif ($existing) DB::table('timesheet_entries')->where('id', $existing->id)->update($payload);
            else DB::table('timesheet_entries')->insert([...$payload, 'created_at' => now()]);
            $audit('manager_entry_changed', (int) $current['id'], (int) $data['employee_id'],
                $assignment['project_id'], $data['work_date'], $existing ? (array) $existing : null,
                $row['hours'] <= 0 ? null : ['hours' => $row['hours'], 'description' => $row['description']]);
        }
        DB::table('employee_confirmations')->where('employee_id', $data['employee_id'])->where('work_date', $data['work_date'])->delete();
        foreach ($projectIds as $projectId) {
            DB::table('final_approval_exceptions')->updateOrInsert(
                ['employee_id' => $data['employee_id'], 'project_id' => $projectId, 'work_date' => $data['work_date']],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    });

    return response()->json(['data' => ['ok' => true, 'entries' => DB::table('timesheet_entries')
        ->where('employee_id', $data['employee_id'])->where('work_date', $data['work_date'])->get()]]);

});

// Hours-only range update: descriptions are read under the database lock, never from a stale browser snapshot.
Route::put('/management/bulk-hours', function (Request $request, CurrentEmployee $currentEmployee) use (
    $directory, $assignments, $accessInfo, $activeAssignments, $canManageAssignment,
    $audit, $assertReportEditable, $assertContourPermission,
) {
    $current = $currentEmployee->resolve($request);
    [$employees, $departments] = $directory($request);
    $allAssignments = $assignments($request);
    $access = $accessInfo($request, $current, $allAssignments, $departments);
    $assertContourPermission($access, 'timesheets.management.manage');
    $data = $request->validate([
        'employee_id' => ['required', 'integer', 'min:1'],
        'project_id' => ['required', 'integer', 'min:1'],
        'dates' => ['required', 'array', 'min:1', 'max:31'],
        'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
        'hours' => ['required', 'numeric', 'min:0', 'max:24'],
    ]);
    $hours = (float) $data['hours'];
    abort_if(abs($hours * 4 - round($hours * 4)) > 0.00001, 422, 'Hours must be a multiple of 0.25');
    $dates = $data['dates'];
    sort($dates);
    $month = Carbon::parse($dates[0])->startOfMonth()->toDateString();
    abort_if(substr($dates[0], 0, 7) !== substr(end($dates), 0, 7), 422, 'Selected dates must belong to one month');
    $target = $employees->first(fn ($e) => (int) $e['id'] === (int) $data['employee_id']);
    abort_unless($target, 404, 'Employee not found');
    $prepared = [];
    foreach ($dates as $date) {
        $assignment = collect($activeAssignments($allAssignments, (int) $data['employee_id'], $date))
            ->firstWhere('project_id', (int) $data['project_id']);
        abort_unless($assignment, 422, 'Project assignment is not active for the selected date');
        abort_unless($canManageAssignment($current, $target, $assignment, $access), 403, 'Timesheet is outside your scope');
        $assertReportEditable($request, (int) $assignment['client_id'], $date);
        $prepared[$date] = $assignment;
    }
    DB::transaction(function () use ($data, $prepared, $hours, $current, $month, $audit): void {
        $existingRows = DB::table('timesheet_entries')->where('employee_id', $data['employee_id'])
            ->whereIn('work_date', array_keys($prepared))->orderBy('work_date')->orderBy('id')->lockForUpdate()->get();
        foreach ($prepared as $date => $assignment) {
            $dayRows = $existingRows->filter(fn ($entry) => $entry->work_date === $date);
            $otherHours = (float) $dayRows->reject(fn ($entry) => (int) $entry->project_id === (int) $data['project_id'])->sum('hours');
            abort_if($otherHours + $hours > 24.00001, 422, 'Total hours for the day cannot exceed 24');
            $existing = $dayRows->first(fn ($entry) => (int) $entry->project_id === (int) $data['project_id']);
            // Zero hours still retain the existing row and its description; do not create empty rows.
            if ($existing) {
                DB::table('timesheet_entries')->where('id', $existing->id)->update(['hours' => $hours, 'updated_at' => now()]);
            } elseif ($hours > 0) {
                DB::table('timesheet_entries')->insert([
                    'employee_id' => (int) $data['employee_id'], 'client_id' => $assignment['client_id'],
                    'project_id' => $assignment['project_id'], 'account_employee_id' => $assignment['account_employee_id'],
                    'work_date' => $date, 'hours' => $hours, 'description' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $audit('manager_entry_changed', (int) $current['id'], (int) $data['employee_id'], $assignment['project_id'], $date,
                $existing ? (array) $existing : null,
                $existing || $hours > 0 ? ['hours' => $hours, 'description' => $existing?->description] : null);
        }
        DB::table('employee_confirmations')->where('employee_id', $data['employee_id'])->whereIn('work_date', array_keys($prepared))->delete();
        foreach (array_keys($prepared) as $date) {
            DB::table('final_approval_exceptions')->updateOrInsert(
                ['employee_id' => $data['employee_id'], 'project_id' => $data['project_id'], 'work_date' => $date],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    });
    return response()->json(['data' => ['ok' => true, 'dates' => $dates]]);
});

Route::post('/management/final-approval', function (Request $request, CurrentEmployee $currentEmployee) use (
    $monthBounds,
    $directory,
    $assignments,
    $accessInfo,
    $scopeForPeriod,
    $audit,
    $assertReportEditable,
    $assertContourPermission,
) {
    $current = $currentEmployee->resolve($request);
    [$employees, $departments] = $directory($request);
    $allAssignments = $assignments($request);
    $access = $accessInfo($request, $current, $allAssignments, $departments);
    $assertContourPermission($access, 'timesheets.management.manage');
    $data = $request->validate([
        'employee_id' => ['required', 'integer', 'min:1'],
        'project_id' => ['required', 'integer', 'min:1'],
        'month' => ['required', 'date_format:Y-m'],
        'approved' => ['required', 'boolean'],
    ]);

    [$start, $end] = $monthBounds($data['month']);
    $from = $start->toDateString();
    $to = $end->toDateString();

    $target = $employees->first(fn ($e) => (int) $e['id'] === (int) $data['employee_id']);
    abort_unless($target, 404, 'Employee not found');

    [$visibleEmployeeIds, $visibleAssignments] = $scopeForPeriod($current, $employees, $allAssignments, $access, $from, $to);
    abort_unless(in_array((int) $data['employee_id'], $visibleEmployeeIds, true), 403, 'Employee is outside your scope');

    $targetAssignments = $visibleAssignments
        ->filter(fn (array $a) => $a['employee_id'] === (int) $data['employee_id'] && $a['project_id'] === (int) $data['project_id'])
        ->values();
    $projectIds = $targetAssignments->pluck('project_id')->unique()->values()->all();
    abort_if(!$projectIds, 422, 'Проект специалиста находится вне вашей области доступа.');
    foreach ($targetAssignments as $assignment) {
        $assertReportEditable($request, (int) $assignment['client_id'], max($from, $assignment['valid_from']), min($to, $assignment['valid_to'] ?: $to));
    }

    DB::transaction(function () use ($data, $current, $from, $to, $targetAssignments, $start, $end): void {
        if ($data['approved']) {
            foreach (CarbonPeriod::create($start, $end) as $day) {
                $date = $day->toDateString();
                $hasApprovedAssignment = $targetAssignments->contains(fn (array $a) =>
                    $a['valid_from'] <= $date && (empty($a['valid_to']) || $a['valid_to'] >= $date)
                );
                if (!$hasApprovedAssignment) continue;

                DB::table('employee_confirmations')->updateOrInsert(
                    ['employee_id' => $data['employee_id'], 'work_date' => $date],
                    ['confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }

        DB::table('final_approval_exceptions')
            ->where('employee_id', $data['employee_id'])->where('project_id', $data['project_id'])
            ->whereBetween('work_date', [$from, $to])->delete();

        if ($data['approved']) {
            DB::table('final_approvals')->updateOrInsert(
                ['employee_id' => $data['employee_id'], 'project_id' => $data['project_id'], 'month' => $from],
                ['approved_by' => $current['id'], 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            );
        } else {
            DB::table('final_approvals')
                ->where('employee_id', $data['employee_id'])
                ->where('project_id', $data['project_id'])
                ->where('month', $from)
                ->delete();
        }
    });

    $audit(
        $data['approved'] ? 'final_approved' : 'final_unapproved',
        (int) $current['id'],
        (int) $data['employee_id'],
        null,
        null,
        null,
        ['project_ids' => $projectIds, 'month' => $from],
    );

    return response()->json(['data' => ['ok' => true, 'project_ids' => $projectIds]]);
});

Route::get('/analytics', function (Request $request, CurrentEmployee $currentEmployee) use (
    $monthBounds,
    $directory,
    $assignments,
    $accessInfo,
    $scopeForPeriod,
    $absenceData,
    $assertContourPermission,
) {
    $current = $currentEmployee->resolve($request);
    [$start, $end] = $monthBounds($request->query('month'));
    [$employees, $departments] = $directory($request);
    $allAssignments = $assignments($request);
    $access = $accessInfo($request, $current, $allAssignments, $departments);
    $assertContourPermission($access, 'timesheets.analytics.view');
    $from = $start->toDateString();
    $to = $end->toDateString();
    [$employeeIds, $visibleAssignments] = $scopeForPeriod($current, $employees, $allAssignments, $access, $from, $to);

    $projectIds = $visibleAssignments->pluck('project_id')->unique()->values()->all();
    $approved = DB::table('final_approvals')
        ->whereIn('employee_id', $employeeIds ?: [0])
        ->where('month', $from)
        ->get();

    $approvedKeys = $approved->mapWithKeys(fn ($a) => [$a->employee_id.':'.$a->project_id => true]);
    $excludedKeys = DB::table('final_approval_exceptions')
        ->whereIn('employee_id', $employeeIds ?: [0])->whereBetween('work_date', [$from, $to])
        ->get()->mapWithKeys(fn ($a) => [$a->employee_id.':'.$a->project_id.':'.$a->work_date => true]);

    $entries = $projectIds
        ? DB::table('timesheet_entries')
            ->whereIn('employee_id', $employeeIds ?: [0])
            ->whereIn('project_id', $projectIds)
            ->whereBetween('work_date', [$from, $to])
            ->get()
            ->filter(fn ($entry) => $approvedKeys->has($entry->employee_id.':'.$entry->project_id)
                    && !$excludedKeys->has($entry->employee_id.':'.$entry->project_id.':'.$entry->work_date))
        : collect();

    $absences = collect($absenceData($request, $from, $to, $employeeIds));
    $workdays = collect(CarbonPeriod::create($start, $end))->filter(fn (Carbon $day) => $day->isWeekday())->count();
    $normHours = $workdays * 8;

    $typeLabels = [
        'paid_vacation' => 'Оплачиваемый отпуск',
        'unpaid_vacation' => 'Неоплачиваемый отпуск',
        'sick_leave' => 'Больничный',
        'maternity_leave' => 'Декрет',
        'day_off' => 'Отгул',
    ];

    $employeeMap = $employees->keyBy(fn ($e) => (int) $e['id']);
    $rows = [];

    foreach ($employeeIds as $employeeId) {
        $employee = $employeeMap->get((int) $employeeId);
        if (!$employee) continue;

        $commercial = (float) $entries->where('employee_id', $employeeId)->sum('hours');
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
            'commercial_percent' => $normHours > 0 ? round($commercial / $normHours * 100, 2) : 0,
            'idle_hours' => $idle,
            'absences' => $absenceHours,
        ];
    }

    $totals = [
        'employees' => count($rows),
        'norm_hours' => array_sum(array_column($rows, 'norm_hours')),
        'commercial_hours' => array_sum(array_column($rows, 'commercial_hours')),
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
                'commercial_percent' => $norm > 0 ? round($group->sum('commercial_hours') / $norm * 100, 2) : 0,
                'idle_hours' => $group->sum('idle_hours'),
                'absences' => $absenceTotals,
            ];
        })
        ->values();

    return response()->json(['data' => [
        'month' => $start->format('Y-m'),
        'type_labels' => $typeLabels,
        'totals' => $totals,
        'employees' => $rows,
        'departments' => $byDepartment,
        'access' => $access,
    ]]);
});

Route::get('/audit', function (Request $request, CurrentEmployee $currentEmployee) use (
    $monthBounds,
    $directory,
    $assignments,
    $accessInfo,
    $scopeForPeriod,
    $assertContourPermission,
) {
    $current = $currentEmployee->resolve($request);
    [$employees, $departments] = $directory($request);
    $allAssignments = $assignments($request);
    $access = $accessInfo($request, $current, $allAssignments, $departments);
    $assertContourPermission($access, 'timesheets.audit.view');

    $query = DB::table('timesheet_audit')->orderByDesc('created_at')->limit(500);

    if (!$access['platformAdmin']) {
        [$start, $end] = $monthBounds(null);
        [$employeeIds] = $scopeForPeriod(
            $current,
            $employees,
            $allAssignments,
            $access,
            $start->copy()->subYears(5)->toDateString(),
            $end->copy()->addYears(5)->toDateString(),
        );
        $employeeIds[] = (int) $current['id'];
        $query->whereIn('employee_id', array_values(array_unique($employeeIds)));
    }

    return response()->json(['data' => $query->get()]);
});
