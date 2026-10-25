<?php

use App\Support\CurrentEmployee;
use App\Support\TimeHours;
use App\Support\ReportingFreeze;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

$tokenOf = fn (Request $request): ?string => $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');

$dependencyGet = function (Request $request, string $env, string $default, string $path, array $query = []) use ($tokenOf): array {
    $response = Http::withToken((string) $tokenOf($request))
        ->withHeaders($request->header('X-Irlix-Role-Preview') ? ['X-Irlix-Role-Preview' => $request->header('X-Irlix-Role-Preview')] : [])
        ->acceptJson()
        ->timeout(8)
        ->get(rtrim((string) env($env, $default), '/').$path, $query);

    abort_unless($response->successful(), 503, "Dependency request failed: {$path} ({$response->status()})");
    $data = $response->json('data');

    abort_unless(is_array($data), 503, "Invalid dependency response: {$path}");
    return $data;
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
        'client_member_id' => $projectId,
        'work_date' => $workDate,
        'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
    ]);
};

$directory = function (Request $request) use ($dependencyGet, $tokenOf): array {
    $response = Http::withToken((string) $tokenOf($request))
        ->withHeaders($request->header('X-Irlix-Role-Preview') ? ['X-Irlix-Role-Preview' => $request->header('X-Irlix-Role-Preview')] : [])->acceptJson()->timeout(8)
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
        // ClientMember + MemberTerms are authoritative; project assignments
        // are organizational only and must never determine writable dates.
        $snapshot = app(\App\Support\ClientMemberDirectory::class)->snapshot();
        $request->attributes->set('locked_reporting_periods', $snapshot['locked_periods']);
        $request->attributes->set('membership_revision', $snapshot['revision']);
        $request->attributes->set('all_client_members', $snapshot['members']);
        $rows = [];
        $byMember = [];
        foreach ($snapshot['members'] as $term) {
            // The directory may include historical members with no terms.
            if ($term['employee_id'] === null || $term['valid_from'] === null) continue;
            $memberId = (int) $term['client_member_id'];
            $employeeId = (int) $term['employee_id'];
            $clientId = (int) $term['client_id'];
            if (isset($byMember[$memberId]) &&
                ($byMember[$memberId]['employee_id'] !== $employeeId || $byMember[$memberId]['client_id'] !== $clientId)) {
                throw new \RuntimeException('Inconsistent Clients membership identity');
            }
            $byMember[$memberId] = ['employee_id' => $employeeId, 'client_id' => $clientId];
            $rows[] = $term + [
                'project_name' => $term['client_name'],
                'employee_name' => $term['specialist_name'],
            ];
        }
        return $rows;
    } catch (\Throwable $error) {
        abort(503, 'Данные клиентских подключений Clients недоступны или неполны.');
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
    $jwtMode = filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    $assignedAccess = $jwtMode ? [] : $dependencyGet($request, 'EMPLOYEES_URL', 'http://employees:8000/api', '/access/me');
    $roles = array_values(array_unique(array_merge(
        $request->attributes->get('identity')['realm_roles'] ?? [],
        $assignedAccess['roles'] ?? [],
    )));
    $employeeId = (int) $employee['id'];
    $platformAdmin = in_array('platform-admin', $roles, true);
    $managedDepartmentIds = $jwtMode
        ? array_values(array_map(fn ($role) => (int) substr($role, strlen('scope:department-')),
            array_filter($roles, fn ($role) => is_string($role) && preg_match('/^scope:department-[1-9][0-9]*$/D', $role))))
        : $departments->filter(fn ($d) => (int) ($d['manager_id'] ?? 0) === $employeeId)
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    do {
        $previousCount = count($managedDepartmentIds);
        foreach ($departments as $department) {
            $id = (int) ($department['id'] ?? 0);
            if ($id && in_array((int) ($department['parent_id'] ?? 0), $managedDepartmentIds, true)
                && !in_array($id, $managedDepartmentIds, true)) $managedDepartmentIds[] = $id;
        }
    } while (count($managedDepartmentIds) !== $previousCount);
    $isAccountManager = collect($allAssignments)
        ->contains(fn (array $a) => ((int) ($a['account_employee_id'] ?? 0) === $employeeId || in_array($employeeId, $a['delegated_account_employee_ids'] ?? [], true)));
    $clientContour = $dependencyGet($request, 'CLIENTS_URL', 'http://clients:8000/api', '/permissions/me');
    $contourPermissions = (array) ($clientContour['permissions'] ?? []);

    $canLock = $platformAdmin || (bool) ($contourPermissions['timesheets.management.manage']['allowed'] ?? false);

    $path = $request->path();
    $scopePermission = str_contains($path, '/analytics') ? 'timesheets.analytics.view'
        : (str_contains($path, '/audit') ? 'timesheets.audit.view'
        : (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? 'timesheets.management.manage' : 'timesheets.management.view'));
    $scope = $platformAdmin ? 'all' : (string) ($contourPermissions[$scopePermission]['scope'] ?? 'none');
    return compact('roles', 'platformAdmin', 'managedDepartmentIds', 'isAccountManager', 'canLock', 'contourPermissions', 'scope');
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

    if ($access['platformAdmin'] || $access['scope'] === 'all') {
        $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    } else {
        $employeeIds = $employees
            ->filter(fn ($e) => $access['scope'] === 'team' && in_array((int) ($e['department_id'] ?? 0), $access['managedDepartmentIds'], true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        foreach ($allAssignments as $a) {
            if (((int) ($a['account_employee_id'] ?? 0) === $currentId || in_array($currentId, $a['delegated_account_employee_ids'] ?? [], true)) && $overlapsPeriod($a, $from, $to)) {
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
        if ($access['platformAdmin'] || $access['scope'] === 'all') return true;
        if ($access['scope'] === 'none') return false;
        if (((int) ($a['account_employee_id'] ?? 0) === $currentId || in_array($currentId, $a['delegated_account_employee_ids'] ?? [], true))) return true;

        $employee = $employeeMap->get((int) $a['employee_id']);
        return $access['scope'] === 'team' && $employee
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
    if ($access['platformAdmin'] || $access['scope'] === 'all') return true;
    if ($access['scope'] === 'none') return false;
    if ((int) ($assignment['account_employee_id'] ?? 0) === (int) $current['id'] || in_array((int) $current['id'], $assignment['delegated_account_employee_ids'] ?? [], true)) return true;

    return $access['scope'] === 'team' && in_array((int) ($targetEmployee['department_id'] ?? 0), $access['managedDepartmentIds'], true);
};

$assertReportEditable = function (Request $request, int $clientId, string $workDate, ?string $to = null) use ($dependencyGet): void {
    $status = $dependencyGet($request, 'CLIENTS_URL', 'http://clients:8000/api', '/reporting-period-lock', [
        'client_id' => $clientId,
        'work_date' => $workDate,
        ...($to ? ['to' => $to] : []),
    ]);
    abort_unless(is_bool($status['locked'] ?? null), 503, 'Некорректный ответ блокировок Clients.');
    abort_if($status['locked'], 423, 'ТШ заблокирован после отправки клиенту на согласование.');
};

$assertEmployeeDatesEditable = function (Request $request, int $employeeId, array $dates) use ($assignments, $assertReportEditable): void {
    $assignments($request);
    $allAssignments = $request->attributes->get('all_client_members', []);
    $checked = [];
    foreach (array_values(array_unique($dates)) as $date) {
        foreach ($allAssignments as $assignment) {
            if ($assignment['employee_id'] !== $employeeId) continue;
            $key = $assignment['client_id'].'-'.$date;
            if (isset($checked[$key])) continue;
            $assertReportEditable($request, (int) $assignment['client_id'], $date);
            $checked[$key] = true;
        }
    }
};

$assertLocalEmployeeDatesEditable = static function (int $employeeId, array $dates, array $members): void {
    foreach ($members as $member) {
        if ((int) ($member['employee_id'] ?? 0) !== $employeeId) continue;
        foreach ($dates as $date) ReportingFreeze::assertEditable((int) $member['client_id'], $date);
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
        'members' => collect($request->attributes->get('all_client_members', []))->filter(fn ($m) => $m['employee_id'] === (int) $employee['id'])->values(),
        'client_reporting_periods' => collect($request->attributes->get('locked_reporting_periods', []))
            ->filter(fn (array $period) => $employeeAssignments->contains('client_id', (int) ($period['client_id'] ?? 0))
                && ($period['period_start'] ?? '') <= $to && ($period['period_end'] ?? '') >= $from)
            ->values(),
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
    $assignments, $activeAssignments, $audit, $assertReportEditable,
) {
    $employee = $currentEmployee->resolve($request);
    $data = $request->validate([
        'work_date' => ['required', 'date_format:Y-m-d'],
        'client_member_id' => ['required', 'integer', 'min:1'],
        'hours' => ['required', 'numeric', 'min:0', 'max:24'],
        'description' => ['nullable', 'string', 'max:4000'],
    ]);
    $hours = TimeHours::decimal($data['hours']);
    $description = trim((string) ($data['description'] ?? '')) ?: null;
    $employeeId = (int) $employee['id'];
    $projectId = (int) $data['client_member_id'];
    $date = $data['work_date'];
    $assignment = collect($activeAssignments($assignments($request), $employeeId, $date))
        ->firstWhere('client_member_id', $projectId);
    abort_unless($assignment, 422, 'Нет действующих условий подключения к клиенту на выбранную дату');
    $assertReportEditable($request, (int) $assignment['client_id'], $date);

    DB::transaction(function () use ($employeeId, $projectId, $date, $hours, $description, $audit, $assignment): void {
        TimeHours::lockDay($employeeId, $date);
        ReportingFreeze::assertEditable((int) $assignment['client_id'], $date);
        $month = Carbon::parse($date)->startOfMonth()->toDateString();
        abort_if(
            DB::table('final_approvals')->where('employee_id', $employeeId)->where('client_member_id', $projectId)
                ->where('month', $month)->exists()
            && !DB::table('final_approval_exceptions')->where('employee_id', $employeeId)
                ->where('client_member_id', $projectId)->where('work_date', $date)->exists(),
            423, 'Таймшит клиента окончательно подтверждён'
        );
        $existing = DB::table('timesheet_entries')->where('employee_id', $employeeId)
            ->where('client_member_id', $projectId)->where('work_date', $date)->lockForUpdate()->first();
        $other = DB::table('timesheet_entries')->where('employee_id', $employeeId)
            ->where('work_date', $date)->where(fn ($q) => $q->whereNull('client_member_id')->orWhere('client_member_id', '!=', $projectId))->sum('hours');
        abort_if(TimeHours::cents($other) + TimeHours::cents($hours) > 2400, 422, 'Total hours for the day cannot exceed 24');
        TimeHours::assertWritable($hours, $existing?->hours);
        if ($existing && TimeHours::cents($existing->hours) === TimeHours::cents($hours)
            && $existing->description === $description) return;

        $payload = [
            'employee_id' => $employeeId, 'client_member_id' => $projectId, 'work_date' => $date,
            'hours' => $hours, 'description' => $description, 'term_conflict' => false, 'updated_at' => now(),
        ];
        if ($existing) DB::table('timesheet_entries')->where('id', $existing->id)->update($payload);
        else DB::table('timesheet_entries')->insert([...$payload, 'created_at' => now()]);
        DB::table('employee_confirmations')->where('employee_id', $employeeId)->where('work_date', $date)->delete();
        $audit($existing ? 'entry_updated' : 'entry_created', $employeeId, $employeeId, $projectId,
            $date, $existing ? (array) $existing : null, ['hours' => $hours, 'description' => $description]);
    });
    return response()->json(['data' => ['ok' => true]]);
});

Route::post('/confirm', function (Request $request, CurrentEmployee $currentEmployee) use ($audit, $assertEmployeeDatesEditable, $assignments, $assertLocalEmployeeDatesEditable) {
    $employee = $currentEmployee->resolve($request);
    $data = $request->validate([
        'dates' => ['required', 'array', 'min:1', 'max:62'],
        'dates.*' => ['date_format:Y-m-d'],
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
    sort($confirmableDates);
    DB::transaction(function () use ($confirmableDates, $employee, $audit, $allAssignments, $assertLocalEmployeeDatesEditable): void {
        foreach ($confirmableDates as $date) TimeHours::lockDay((int) $employee['id'], $date);
        $assertLocalEmployeeDatesEditable((int) $employee['id'], $confirmableDates, request()->attributes->get('all_client_members', $allAssignments));
        $facts = DB::table('timesheet_entries')->where('employee_id', $employee['id'])->whereIn('work_date', $confirmableDates)->get();
        foreach ($facts as $fact) {
            $cover = collect($allAssignments)->filter(fn ($a) => $a['client_member_id'] === (int) $fact->client_member_id
                && $a['employee_id'] === (int) $employee['id'] && $a['valid_from'] <= $fact->work_date
                && (empty($a['valid_to']) || $a['valid_to'] >= $fact->work_date));
            abort_if($cover->count() !== 1 || (bool) $fact->term_conflict, 422, 'В выбранных днях есть конфликтующие или неперенесённые часы.');
        }
        foreach ($confirmableDates as $date) {
            DB::table('employee_confirmations')->updateOrInsert(
                ['employee_id' => $employee['id'], 'work_date' => $date],
                ['confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            );
            $audit('employee_confirmed', (int) $employee['id'], (int) $employee['id'], null, $date, null, ['confirmed' => true]);
        }
    });

    return response()->json(['data' => [
        'confirmed' => count($confirmableDates),
        'skipped_without_assignment' => count($requestedDates) - count($confirmableDates),
    ]]);
});

Route::post('/unconfirm', function (Request $request, CurrentEmployee $currentEmployee) use ($audit, $assertEmployeeDatesEditable, $assignments, $assertLocalEmployeeDatesEditable) {
    $employee = $currentEmployee->resolve($request);
    $data = $request->validate([
        'dates' => ['required', 'array', 'min:1', 'max:62'],
        'dates.*' => ['date_format:Y-m-d'],
    ]);

    $allAssignments = $assignments($request);
    $assertEmployeeDatesEditable($request, (int) $employee['id'], $data['dates']);
    $dates = array_values(array_unique($data['dates']));
    sort($dates);
    DB::transaction(function () use ($dates, $employee, $allAssignments, $audit, $assertLocalEmployeeDatesEditable): void {
        foreach ($dates as $date) TimeHours::lockDay((int) $employee['id'], $date);
        $assertLocalEmployeeDatesEditable((int) $employee['id'], $dates, request()->attributes->get('all_client_members', $allAssignments));
        foreach ($dates as $date) {
        $month = Carbon::parse($date)->startOfMonth()->toDateString();
        $projectIds = collect(request()->attributes->get('all_client_members', $allAssignments))
            ->filter(fn (array $assignment) =>
                $assignment['employee_id'] === (int) $employee['id']

            )
            ->pluck('client_member_id')
            ->map(fn ($projectId) => (int) $projectId)
            ->unique()
            ->values()
            ->all();

        if ($projectIds) {
            abort_if(
                DB::table('final_approvals')
                    ->where('employee_id', $employee['id'])
                    ->where('month', $month)
                    ->whereIn('client_member_id', $projectIds)
                    ->whereNotIn('client_member_id', DB::table('final_approval_exceptions')
                        ->select('client_member_id')->where('employee_id', $employee['id'])->where('work_date', $date))
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
    });

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
    $projectIds = $visibleAssignments->pluck('client_member_id')->unique()->values()->all();
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
                ->whereIn('client_member_id', $projectIds)
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
            ->whereIn('client_member_id', $projectIds ?: [0])
            ->where('month', $from)
            ->get(),
        'absences' => $absenceData($request, $from, $to, $employeeIds),
        'client_reporting_periods' => $clientReportingPeriods,
        'period_locked' => false,
    ]]);
});

Route::put('/management/entries', function (Request $request, CurrentEmployee $currentEmployee) use (
    $directory, $assignments, $accessInfo, $activeAssignments, $canManageAssignment,
    $audit, $assertReportEditable, $assertContourPermission,
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
            'entries.*.client_member_id' => ['required', 'integer', 'min:1', 'distinct'],
            'entries.*.hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'entries.*.description' => ['nullable', 'string', 'max:4000'],
        ]);
        $rows = $data['entries'];
    } else {
        $data = $request->validate([...$baseRules,
            'client_member_id' => ['required', 'integer', 'min:1'],
            'hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'description' => ['nullable', 'string', 'max:4000'],
        ]);
        $rows = [$data];
    }
    $employeeId = (int) $data['employee_id'];
    $date = $data['work_date'];
    $target = $employees->first(fn ($e) => (int) $e['id'] === $employeeId);
    abort_unless($target, 404, 'Employee not found');
    $prepared = [];
    foreach ($rows as $row) {
        $hours = TimeHours::decimal($row['hours']);
        $assignment = collect($activeAssignments($allAssignments, $employeeId, $date))
            ->firstWhere('client_member_id', (int) $row['client_member_id']);
        abort_unless($assignment, 422, 'Нет действующих условий подключения к клиенту на выбранную дату');
        abort_unless($canManageAssignment($current, $target, $assignment, $access), 403, 'Timesheet is outside your scope');
        $assertReportEditable($request, (int) $assignment['client_id'], $date);
        $prepared[] = ['client_member_id' => (int) $row['client_member_id'], 'client_id' => (int) $assignment['client_id'], 'hours' => $hours,
            'description' => trim((string) ($row['description'] ?? '')) ?: null];
    }
    DB::transaction(function () use ($employeeId, $date, $prepared, $current, $audit): void {
        TimeHours::lockDay($employeeId, $date);
        foreach ($prepared as $row) ReportingFreeze::assertEditable($row['client_id'], $date);
        $existingRows = DB::table('timesheet_entries')->where('employee_id', $employeeId)
            ->where('work_date', $date)->orderBy('id')->lockForUpdate()->get();
        $projectIds = array_column($prepared, 'client_member_id');
        $otherCents = $existingRows->reject(fn ($entry) => in_array((int) $entry->client_member_id, $projectIds, true))
            ->sum(fn ($entry) => TimeHours::cents($entry->hours));
        $newCents = array_sum(array_map(fn ($row) => TimeHours::cents($row['hours']), $prepared));
        abort_if($otherCents + $newCents > 2400, 422, 'Total hours for the day cannot exceed 24');
        $changedProjectIds = [];
        foreach ($prepared as $row) {
            $projectId = $row['client_member_id'];
            $existing = $existingRows->first(fn ($entry) => (int) $entry->client_member_id === $projectId);
            TimeHours::assertWritable($row['hours'], $existing?->hours);
            if ($existing && TimeHours::cents($existing->hours) === TimeHours::cents($row['hours'])
                && $existing->description === $row['description']) continue;
            // The management form submits all active projects. Untouched blank zeroes are not explicit entries.
            if (!$existing && TimeHours::cents($row['hours']) === 0 && $row['description'] === null) continue;
            $payload = [
                'employee_id' => $employeeId, 'client_member_id' => $projectId,
                'work_date' => $date, 'hours' => $row['hours'],
                'description' => $row['description'], 'term_conflict' => false, 'updated_at' => now(),
            ];
            if ($existing) DB::table('timesheet_entries')->where('id', $existing->id)->update($payload);
            else DB::table('timesheet_entries')->insert([...$payload, 'created_at' => now()]);
            $audit('manager_entry_changed', (int) $current['id'], $employeeId, $projectId,
                $date, $existing ? (array) $existing : null,
                ['hours' => $row['hours'], 'description' => $row['description']]);
            $changedProjectIds[] = $projectId;
        }
        if ($changedProjectIds) {
            DB::table('employee_confirmations')->where('employee_id', $employeeId)
                ->where('work_date', $date)->delete();
            foreach ($changedProjectIds as $projectId) {
                DB::table('final_approval_exceptions')->updateOrInsert(
                    ['employee_id' => $employeeId, 'client_member_id' => $projectId, 'work_date' => $date],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    });
    return response()->json(['data' => ['ok' => true, 'entries' => DB::table('timesheet_entries')
        ->where('employee_id', $employeeId)->where('work_date', $date)->get()]]);
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
        'client_member_id' => ['required', 'integer', 'min:1'],
        'dates' => ['required', 'array', 'min:1', 'max:31'],
        'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
        'hours' => ['required', 'numeric', 'min:0', 'max:24'],
    ]);
    $hours = TimeHours::decimal($data['hours']);
    $dates = $data['dates'];
    sort($dates);
    $month = Carbon::parse($dates[0])->startOfMonth()->toDateString();
    abort_if(substr($dates[0], 0, 7) !== substr(end($dates), 0, 7), 422, 'Selected dates must belong to one month');
    $target = $employees->first(fn ($e) => (int) $e['id'] === (int) $data['employee_id']);
    abort_unless($target, 404, 'Employee not found');
    $prepared = [];
    foreach ($dates as $date) {
        $assignment = collect($activeAssignments($allAssignments, (int) $data['employee_id'], $date))
            ->firstWhere('client_member_id', (int) $data['client_member_id']);
        abort_unless($assignment, 422, 'Нет действующих условий подключения к клиенту на выбранную дату');
        abort_unless($canManageAssignment($current, $target, $assignment, $access), 403, 'Timesheet is outside your scope');
        $assertReportEditable($request, (int) $assignment['client_id'], $date);
        $prepared[$date] = $assignment;
    }
    DB::transaction(function () use ($data, $prepared, $hours, $current, $audit): void {
        $employeeId = (int) $data['employee_id'];
        foreach (array_keys($prepared) as $date) TimeHours::lockDay($employeeId, $date);
        foreach ($prepared as $date => $assignment) ReportingFreeze::assertEditable((int) $assignment['client_id'], $date);
        $existingRows = DB::table('timesheet_entries')->where('employee_id', $employeeId)
            ->whereIn('work_date', array_keys($prepared))->orderBy('work_date')->orderBy('id')->lockForUpdate()->get();
        $changedDates = [];
        foreach ($prepared as $date => $assignment) {
            $dayRows = $existingRows->filter(fn ($entry) => $entry->work_date === $date);
            $otherCents = $dayRows->reject(fn ($entry) => (int) $entry->client_member_id === (int) $data['client_member_id'])
                ->sum(fn ($entry) => TimeHours::cents($entry->hours));
            abort_if($otherCents + TimeHours::cents($hours) > 2400, 422, 'Total hours for the day cannot exceed 24');
            $existing = $dayRows->first(fn ($entry) => (int) $entry->client_member_id === (int) $data['client_member_id']);
            TimeHours::assertWritable($hours, $existing?->hours);
            // Mass editing zero keeps existing comments and does not fabricate rows for untouched empty cells.
            TimeHours::assertWritable($hours, $existing?->hours);
        if ($existing && TimeHours::cents($existing->hours) === TimeHours::cents($hours)) continue;
            if (!$existing && TimeHours::cents($hours) === 0) continue;
            if ($existing) {
                DB::table('timesheet_entries')->where('id', $existing->id)
                    ->update(['hours' => $hours, 'term_conflict' => false, 'updated_at' => now()]);
            } else {
                DB::table('timesheet_entries')->insert([
                    'employee_id' => $employeeId, 'client_member_id' => $data['client_member_id'],
                    'work_date' => $date, 'hours' => $hours, 'description' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $audit('manager_entry_changed', (int) $current['id'], $employeeId, $assignment['client_member_id'], $date,
                $existing ? (array) $existing : null, ['hours' => $hours, 'description' => $existing?->description]);
            $changedDates[] = $date;
        }
        if ($changedDates) {
            DB::table('employee_confirmations')->where('employee_id', $employeeId)
                ->whereIn('work_date', $changedDates)->delete();
            foreach ($changedDates as $date) {
                DB::table('final_approval_exceptions')->updateOrInsert(
                    ['employee_id' => $employeeId, 'client_member_id' => $data['client_member_id'], 'work_date' => $date],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
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
    $canManageAssignment,
    $assertLocalEmployeeDatesEditable,
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
        'client_member_id' => ['required', 'integer', 'min:1'],
        'month' => ['required', 'date_format:Y-m'],
        'approved' => ['required', 'boolean'],
    ]);

    [$start, $end] = $monthBounds($data['month']);
    $from = $start->toDateString();
    $to = $end->toDateString();

    $target = $employees->first(fn ($e) => (int) $e['id'] === (int) $data['employee_id']);
    abort_unless($target, 404, 'Employee not found');

    $identity = collect($request->attributes->get('all_client_members', []))->first(fn ($m) =>
        $m['client_member_id'] === (int) $data['client_member_id'] && $m['employee_id'] === (int) $data['employee_id']);
    abort_unless($identity, 422, 'Подключение сотрудника не найдено.');
    abort_unless($canManageAssignment($current, $target, $identity, $access), 403, 'Подключение вне вашей области доступа.');
    $targetAssignments = collect($allAssignments)->filter(fn ($a) => $a['client_member_id'] === (int) $data['client_member_id']
        && $a['employee_id'] === (int) $data['employee_id'] && $a['valid_from'] <= $to
        && (empty($a['valid_to']) || $a['valid_to'] >= $from))->values();
    abort_if($data['approved'] && $targetAssignments->isEmpty(), 422, 'Нет действующих условий в выбранном месяце.');
    $projectIds = [(int) $data['client_member_id']];
    $assertReportEditable($request, (int) $identity['client_id'], $from, $to);

    $allMembers=$request->attributes->get('all_client_members', []);
    $locks=$request->attributes->get('locked_reporting_periods', []);
    DB::transaction(function () use ($data, $current, $from, $to, $targetAssignments, $start, $end, $audit, $projectIds, $identity, $allMembers, $locks, $assertLocalEmployeeDatesEditable): void {
        // Serialize approvals with every edit or preliminary confirmation in this employee/month.
        foreach (CarbonPeriod::create($start, $end) as $day) {
            TimeHours::lockDay((int) $data['employee_id'], $day->toDateString());
        }
        ReportingFreeze::assertEditable((int) $identity['client_id'], $from, $to);
        if ($data['approved']) {
            $actual = DB::table('timesheet_entries')->where('client_member_id', $data['client_member_id'])
                ->whereBetween('work_date', [$from, $to])->get();
            foreach ($actual as $entry) {
                $covered = $targetAssignments->filter(fn ($a) => $a['valid_from'] <= $entry->work_date
                    && (empty($a['valid_to']) || $a['valid_to'] >= $entry->work_date));
                abort_if((int) $entry->employee_id !== (int) $data['employee_id'] || $covered->count() !== 1
                    || (bool) $entry->term_conflict, 422, 'Есть конфликтующие часы вне коммерческих условий.');
            }
            foreach (CarbonPeriod::create($start, $end) as $day) {
                $date = $day->toDateString();
                $hasApprovedAssignment = $targetAssignments->contains(fn (array $a) =>
                    $a['valid_from'] <= $date && (empty($a['valid_to']) || $a['valid_to'] >= $date)
                );
                if (!$hasApprovedAssignment) continue;

                if (DB::table('employee_confirmations')->where('employee_id', $data['employee_id'])->where('work_date', $date)->exists()) continue;
                $assertLocalEmployeeDatesEditable((int) $data['employee_id'], [$date], $allMembers);
                foreach ($allMembers as $member) {
                    if ($member['employee_id'] !== (int) $data['employee_id']) continue;
                    abort_if(collect($locks)->contains(fn ($p) => $p['client_id'] === $member['client_id']
                        && $p['period_start'] <= $date && $p['period_end'] >= $date), 423, 'Общий день сотрудника защищён отчётным периодом другого клиента.');
                }
                DB::table('employee_confirmations')->insert([
                    'employee_id' => $data['employee_id'], 'work_date' => $date,
                    'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        DB::table('final_approval_exceptions')
            ->where('employee_id', $data['employee_id'])->where('client_member_id', $data['client_member_id'])
            ->whereBetween('work_date', [$from, $to])->delete();

        if ($data['approved']) {
            DB::table('final_approvals')->updateOrInsert(
                ['employee_id' => $data['employee_id'], 'client_member_id' => $data['client_member_id'], 'month' => $from],
                ['approved_by' => $current['id'], 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            );
        } else {
            DB::table('final_approvals')
                ->where('employee_id', $data['employee_id'])
                ->where('client_member_id', $data['client_member_id'])
                ->where('month', $from)
                ->delete();
        }
        $audit(
            $data['approved'] ? 'final_approved' : 'final_unapproved',
            (int) $current['id'],
            (int) $data['employee_id'],
            null, null, null,
            ['client_member_ids' => $projectIds, 'month' => $from],
        );
    });

    return response()->json(['data' => ['ok' => true, 'client_member_ids' => $projectIds]]);
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
    [$employeeIds, $visibleAssignments] = $scopeForPeriod($current, $employees, $allAssignments, $access, $from, $to);

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
    $approved = DB::table('final_approvals')
        ->whereIn('employee_id', $employeeIds ?: [0])
        ->where('month', $from)
        ->get();

    $exceptions = DB::table('final_approval_exceptions')
        ->whereIn('employee_id', $employeeIds ?: [0])
        ->whereBetween('work_date', [$from, $to])
        ->get();
    $allEntries = $projectIds
        ? DB::table('timesheet_entries')
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

    return response()->json(['data' => [
        'month' => $start->format('Y-m'),
        'filters' => ['departments' => $departmentOptions, 'clients' => $clientOptions],
        'type_labels' => $typeLabels,
        'partners' => $partnerReport['rows'],
        'partner_totals' => $partnerReport['totals'],
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
        $memberIds = collect($request->attributes->get('all_client_members', []))->filter(function ($member) use ($access, $current, $employees) {
            if ($access['scope'] === 'all') return true;
            if ($access['scope'] === 'none') return false;
            if ((int) ($member['account_employee_id'] ?? 0) === (int) $current['id']
                || in_array((int) $current['id'], $member['delegated_account_employee_ids'], true)) return true;
            if ($member['employee_id'] === null || $access['scope'] !== 'team') return false;
            $employee = $employees->first(fn ($e) => (int) $e['id'] === $member['employee_id']);
            return $employee && in_array((int) ($employee['department_id'] ?? 0), $access['managedDepartmentIds'], true);
        })->pluck('client_member_id')->unique()->all();
        $query->where(fn ($q) => $q->whereIn('client_member_id', $memberIds ?: [0])
            ->orWhere(fn ($own) => $own->whereNull('client_member_id')->where('employee_id', $access['scope'] === 'none' ? 0 : (int) $current['id'])));
    }

    return response()->json(['data' => $query->get()]);
});

// Domain-owned action feed for the platform Dashboard.
require __DIR__.'/attention.php';

// Client-member partner timesheets (AM-entered, no synthetic Employees identity).
require __DIR__.'/partner-hours.php';
