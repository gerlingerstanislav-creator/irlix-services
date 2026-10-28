<?php

namespace App\Services;

use App\Models\TimesheetAudit;
use App\Support\ClientMemberDirectory;
use App\Support\ReportingFreeze;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Shared Clients/Employees/Vacations projections and server-side timesheet scope.
 * Clients remains authoritative for ClientMember/MemberTerms and permissions.
 */
final class TimesheetsContext
{
    public function __construct(private readonly ClientMemberDirectory $members) {}

    public function monthBounds(?string $month): array
    {
        $start = $month
            ? Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth()
            : now()->startOfMonth();
        return [$start, $start->copy()->endOfMonth()];
    }

    private function token(Request $request): ?string
    {
        return $request->bearerToken() ?: $request->header('X-Irlix-Access-Token');
    }

    public function dependencyGet(Request $request, string $env, string $default, string $path, array $query = []): array
    {
        $response = Http::withToken((string) $this->token($request))
            ->withHeaders($request->header('X-Irlix-Role-Preview')
                ? ['X-Irlix-Role-Preview' => $request->header('X-Irlix-Role-Preview')] : [])
            ->acceptJson()->timeout(8)
            ->get(rtrim((string) env($env, $default), '/').$path, $query);
        abort_unless($response->successful(), 503, "Dependency request failed: {$path} ({$response->status()})");
        $data = $response->json('data');
        abort_unless(is_array($data), 503, "Invalid dependency response: {$path}");
        return $data;
    }

    public function directory(Request $request): array
    {
        $response = Http::withToken((string) $this->token($request))
            ->withHeaders($request->header('X-Irlix-Role-Preview')
                ? ['X-Irlix-Role-Preview' => $request->header('X-Irlix-Role-Preview')] : [])
            ->acceptJson()->timeout(8)
            ->get(rtrim((string) env('EMPLOYEES_URL', 'http://employees:8000/api'), '/').'/clients-directory');
        if ($response->successful() && is_array($response->json('data.employees'))) {
            return [collect($response->json('data.employees')), collect($response->json('data.departments', []))];
        }
        return [
            collect($this->dependencyGet($request, 'EMPLOYEES_URL', 'http://employees:8000/api', '/employees')),
            collect($this->dependencyGet($request, 'EMPLOYEES_URL', 'http://employees:8000/api', '/departments')),
        ];
    }

    public function assignments(Request $request): array
    {
        try {
            $snapshot = $this->members->snapshot();
            $request->attributes->set('locked_reporting_periods', $snapshot['locked_periods']);
            $request->attributes->set('membership_revision', $snapshot['revision']);
            $request->attributes->set('all_client_members', $snapshot['members']);
            $rows = [];
            $byMember = [];
            foreach ($snapshot['members'] as $term) {
                if ($term['employee_id'] === null || $term['valid_from'] === null) continue;
                $memberId = (int) $term['client_member_id'];
                $employeeId = (int) $term['employee_id'];
                $clientId = (int) $term['client_id'];
                if (isset($byMember[$memberId])
                    && ($byMember[$memberId]['employee_id'] !== $employeeId
                        || $byMember[$memberId]['client_id'] !== $clientId)) {
                    throw new \RuntimeException('Inconsistent Clients membership identity');
                }
                $byMember[$memberId] = ['employee_id' => $employeeId, 'client_id' => $clientId];
                $rows[] = $term + [
                    'project_name' => $term['client_name'],
                    'employee_name' => $term['specialist_name'],
                ];
            }
            return $rows;
        } catch (\Throwable) {
            abort(503, 'Данные клиентских подключений Clients недоступны или неполны.');
        }
    }

    public function overlapsPeriod(array $assignment, string $from, string $to): bool
    {
        return $assignment['valid_from'] <= $to
            && (empty($assignment['valid_to']) || $assignment['valid_to'] >= $from);
    }

    public function activeAssignments(array $assignments, int $employeeId, string $date): array
    {
        return array_values(array_filter($assignments, static fn (array $a) =>
            $a['employee_id'] === $employeeId && $a['valid_from'] <= $date
            && (empty($a['valid_to']) || $a['valid_to'] >= $date)
        ));
    }

    public function accessInfo(Request $request, array $employee, array $assignments, $departments): array
    {
        $jwtMode = filter_var(env('IRLIX_AUTHZ_JWT_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $localEnabled = $jwtMode
            && filter_var(env('IRLIX_TIMESHEETS_LOCAL_AUTHZ_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
        $assignedAccess = $jwtMode ? [] : $this->dependencyGet($request,
            'EMPLOYEES_URL', 'http://employees:8000/api', '/access/me');
        $roles = array_values(array_unique(array_merge(
            $request->attributes->get('identity')['realm_roles'] ?? [],
            $assignedAccess['roles'] ?? [],
        )));
        $employeeId = (int) $employee['id'];
        $platformAdmin = in_array('platform-admin', $roles, true)
            || ($localEnabled && in_array('special:platform-admin', $roles, true));
        // V2 derives leadership scope from CURRENT trusted Employees manager
        // relationships, never from the legacy global scope:* JWT grant.
        $managedDepartmentIds = $localEnabled
            ? $departments->filter(static fn ($d) => (int) ($d['manager_id'] ?? 0) === $employeeId)
                ->pluck('id')->map(static fn ($id) => (int) $id)->values()->all()
            : ($jwtMode
                ? array_values(array_map(static fn ($role) => (int) substr($role, strlen('scope:department-')),
                    array_filter($roles, static fn ($role) =>
                        is_string($role) && preg_match('/^scope:department-[1-9][0-9]*$/D', $role))))
                : $departments->filter(static fn ($d) => (int) ($d['manager_id'] ?? 0) === $employeeId)
                    ->pluck('id')->map(static fn ($id) => (int) $id)->values()->all());
        do {
            $previousCount = count($managedDepartmentIds);
            foreach ($departments as $department) {
                $id = (int) ($department['id'] ?? 0);
                if ($id && in_array((int) ($department['parent_id'] ?? 0), $managedDepartmentIds, true)
                    && !in_array($id, $managedDepartmentIds, true)) {
                    $managedDepartmentIds[] = $id;
                }
            }
        } while (count($managedDepartmentIds) !== $previousCount);
        $isAccountManager = collect($assignments)->contains(static fn (array $a) =>
            (int) ($a['account_employee_id'] ?? 0) === $employeeId
            || in_array($employeeId, $a['delegated_account_employee_ids'] ?? [], true));
        // Local policy uses the SAME verified roles and supports the existing
        // per-permission own/team/all object checks in this service. Clients
        // remains authoritative for connections, not Timesheets permissions.
        if ($localEnabled) {
            $contourPermissions = (array) $request->attributes->get('timesheets_local_permissions', []);
        } else {
            $contour = $this->dependencyGet($request, 'CLIENTS_URL',
                'http://clients:8000/api', '/permissions/me');
            $contourPermissions = (array) ($contour['permissions'] ?? []);
        }
        $canLock = $platformAdmin || (bool) ($contourPermissions['timesheets.management.manage']['allowed'] ?? false);
        $path = $request->path();
        $scopePermission = str_contains($path, '/analytics') ? 'timesheets.analytics.view'
            : (str_contains($path, '/audit') ? 'timesheets.audit.view'
            : (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                ? 'timesheets.management.manage' : 'timesheets.management.view'));
        if ($localEnabled && $path === 'api/workspace') $scopePermission = 'timesheets.mine.view';
        if ($localEnabled && in_array($path, ['api/entries','api/confirm','api/unconfirm'], true)) {
            $scopePermission = 'timesheets.mine.manage';
        }
        $scope = $platformAdmin ? 'all' : (string) ($contourPermissions[$scopePermission]['scope'] ?? 'none');
        return compact('roles', 'platformAdmin', 'managedDepartmentIds', 'isAccountManager',
            'canLock', 'contourPermissions', 'scope');
    }

    public function assertContourPermission(array $access, string $permission): void
    {
        abort_unless($access['platformAdmin'] || ($access['contourPermissions'][$permission]['allowed'] ?? false),
            403, 'Недостаточно прав для этого раздела Timesheets.');
    }

    public function scopeForPeriod(array $current, $employees, array $assignments,
        array $access, string $from, string $to): array
    {
        $currentId = (int) $current['id'];
        $employeeMap = $employees->keyBy(static fn ($e) => (int) $e['id']);
        if ($access['platformAdmin'] || $access['scope'] === 'all') {
            $employeeIds = $employees->pluck('id')->map(static fn ($id) => (int) $id)->values()->all();
        } else {
            $employeeIds = $employees
                ->filter(static fn ($e) => $access['scope'] === 'team'
                    && in_array((int) ($e['department_id'] ?? 0), $access['managedDepartmentIds'], true))
                ->pluck('id')->map(static fn ($id) => (int) $id)->values()->all();
            foreach ($assignments as $assignment) {
                if (((int) ($assignment['account_employee_id'] ?? 0) === $currentId
                    || in_array($currentId, $assignment['delegated_account_employee_ids'] ?? [], true))
                    && $this->overlapsPeriod($assignment, $from, $to)) {
                    $employeeIds[] = (int) $assignment['employee_id'];
                }
            }
            $employeeIds = array_values(array_unique($employeeIds));
        }
        $visible = collect($assignments)->filter(function (array $a) use (
            $access, $employeeMap, $currentId, $from, $to
        ): bool {
            if (!$this->overlapsPeriod($a, $from, $to)) return false;
            if ($access['platformAdmin'] || $access['scope'] === 'all') return true;
            if ($access['scope'] === 'none') return false;
            if ((int) ($a['account_employee_id'] ?? 0) === $currentId
                || in_array($currentId, $a['delegated_account_employee_ids'] ?? [], true)) return true;
            $employee = $employeeMap->get((int) $a['employee_id']);
            return $access['scope'] === 'team' && $employee
                && in_array((int) ($employee['department_id'] ?? 0), $access['managedDepartmentIds'], true);
        })->values();
        return [$employeeIds, $visible];
    }

    public function canManageAssignment(array $current, array $target,
        array $assignment, array $access): bool
    {
        if ($access['platformAdmin'] || $access['scope'] === 'all') return true;
        if ($access['scope'] === 'none') return false;
        if ((int) ($assignment['account_employee_id'] ?? 0) === (int) $current['id']
            || in_array((int) $current['id'], $assignment['delegated_account_employee_ids'] ?? [], true)) return true;
        return $access['scope'] === 'team'
            && in_array((int) ($target['department_id'] ?? 0), $access['managedDepartmentIds'], true);
    }

    public function assertReportEditable(Request $request, int $clientId, string $date, ?string $to = null): void
    {
        $status = $this->dependencyGet($request, 'CLIENTS_URL', 'http://clients:8000/api',
            '/reporting-period-lock', [
                'client_id' => $clientId, 'work_date' => $date,
                ...($to ? ['to' => $to] : []),
            ]);
        abort_unless(is_bool($status['locked'] ?? null), 503, 'Некорректный ответ блокировок Clients.');
        abort_if($status['locked'], 423, 'ТШ заблокирован после отправки клиенту на согласование.');
    }

    public function assertEmployeeDatesEditable(Request $request, int $employeeId, array $dates): void
    {
        $this->assignments($request);
        $assignments = $request->attributes->get('all_client_members', []);
        $checked = [];
        foreach (array_values(array_unique($dates)) as $date) {
            foreach ($assignments as $assignment) {
                if ($assignment['employee_id'] !== $employeeId) continue;
                $key = $assignment['client_id'].'-'.$date;
                if (isset($checked[$key])) continue;
                $this->assertReportEditable($request, (int) $assignment['client_id'], $date);
                $checked[$key] = true;
            }
        }
    }

    public function assertLocalEmployeeDatesEditable(int $employeeId, array $dates, array $members): void
    {
        foreach ($members as $member) {
            if ((int) ($member['employee_id'] ?? 0) !== $employeeId) continue;
            foreach ($dates as $date) {
                ReportingFreeze::assertEditable((int) $member['client_id'], $date);
            }
        }
    }

    public function absenceData(Request $request, string $from, string $to, array $employeeIds): array
    {
        if (!$employeeIds) return [];
        return $this->dependencyGet($request, 'VACATIONS_URL',
            'http://vacations:8000/api', '/calendar-absences', [
                'from' => $from, 'to' => $to,
                'employee_ids' => array_values(array_unique($employeeIds)),
            ]);
    }

    public function audit(string $action, ?int $actorId, ?int $employeeId = null,
        ?int $clientMemberId = null, ?string $date = null, mixed $before = null,
        mixed $after = null): void
    {
        TimesheetAudit::query()->insert([
            'action' => $action, 'actor_employee_id' => $actorId,
            'employee_id' => $employeeId, 'client_member_id' => $clientMemberId,
            'work_date' => $date,
            'before' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
            'after' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
