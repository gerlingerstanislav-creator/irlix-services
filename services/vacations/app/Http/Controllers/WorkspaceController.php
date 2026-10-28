<?php

namespace App\Http\Controllers;


use App\Application\AbsenceService;
use App\Application\AbsenceReadService;
use App\Support\CurrentEmployee;
use App\Support\EmployeesDirectory;
use App\Support\VacationsAccess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class WorkspaceController extends Controller
{
    public function __construct(
        private readonly CurrentEmployee $currentEmployee,
        private readonly EmployeesDirectory $employees,
        private readonly AbsenceService $absences,
        private readonly AbsenceReadService $reads,
        private readonly VacationsAccess $authorization,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request) {
            $directory = [];
            $departments = [];
            $localGrants = $request->attributes->get('vacations_local_permissions');
            $canReadDirectory = $this->authorization->isElevated($access)
                && (!is_array($localGrants)
                    || (($localGrants['vacations.department.view']['allowed'] ?? false)
                        && ($localGrants['vacations.department.view']['scope'] ?? 'none') === 'all')
                    || (($localGrants['vacations.manage.view']['allowed'] ?? false)
                        && ($localGrants['vacations.manage.view']['scope'] ?? 'none') === 'all'));
            if (is_array($localGrants)) {
                // The service-specific matrix, never the presence of an HR
                // or manager identity alone, decides visibility. Team
                // projections are filtered before leaving this backend.
                $allowedScopes = [];
                foreach (['vacations.department.view', 'vacations.manage.view'] as $permission) {
                    $rule = (array) ($localGrants[$permission] ?? []);
                    if (($rule['allowed'] ?? false) === true) {
                        $allowedScopes[] = $rule['scope'] ?? 'none';
                    }
                }
                $scope = in_array('all', $allowedScopes, true) ? 'all'
                    : (in_array('team', $allowedScopes, true) ? 'team' : 'none');
                $canReadDirectory = $scope !== 'none';
            }
            if ($canReadDirectory) {
                $payload = $this->employees->vacationsDirectory($request);
                $directory = array_values($payload['employees'] ?? []);
                $departments = array_values($payload['departments'] ?? []);
                if (is_array($localGrants) && $scope === 'team') {
                    $ids = array_fill_keys(
                        \App\Services\VacationsObjectScope::managedDepartments(
                            (int) $employee['id'], $departments
                        ), true);
                    $directory = array_values(array_filter($directory,
                        static fn (array $person): bool =>
                            isset($ids[(int) ($person['department_id'] ?? 0)])));
                    $departments = array_values(array_filter($departments,
                        static fn (array $dept): bool =>
                            isset($ids[(int) ($dept['id'] ?? 0)])));
                }
            }

            return response()->json(['data' => [
                'employee' => $employee,
                'access' => $access,
                'authorization' => is_array($localGrants)
                    ? ['mode' => 'local', 'permissions' => $localGrants]
                    : ['mode' => 'legacy', 'permissions' => []],
                'employees' => $directory,
                'departments' => $departments,
            ]]);
        });
    }

    public function departmentRoster(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request) {
            $local = $request->attributes->get('vacations_local_permissions');
            if (!is_array($local) && !$this->authorization->isElevated($access)) {
                throw new DomainException('Недостаточно прав для просмотра отпусков подразделения');
            }
            $year = filter_var($request->query('year'), FILTER_VALIDATE_INT);
            if ($year === false || $year < 2000 || $year > 2100) {
                return response()->json(['message' => 'Invalid historical year'], 422);
            }
            $historical = $this->employees->vacationsHistoricalDirectory($request, $year);
            if (is_array($local)) {
                $scope = (string) ($local['vacations.department.view']['scope'] ?? 'none');
                if ($scope !== 'all') {
                    // Historical directory is only a presentation model.
                    // The CURRENT manager-ID relations determine which of
                    // its employees can be shown under a team grant.
                    $org = $this->employees->vacationsDirectory($request);
                    $departments = array_values($org['departments'] ?? []);
                    $allowedDepartments = array_fill_keys(
                        \App\Services\VacationsObjectScope::managedDepartments(
                            (int) $employee['id'], $departments
                        ), true);
                    $allowedEmployees = [];
                    foreach (($org['employees'] ?? []) as $person) {
                        if (isset($allowedDepartments[(int) ($person['department_id'] ?? 0)])) {
                            $allowedEmployees[(int) $person['id']] = true;
                        }
                    }
                    $historical = [
                        'employees' => array_values(array_filter(
                            (array) ($historical['employees'] ?? []),
                            static fn (array $person): bool =>
                                isset($allowedEmployees[(int) ($person['id'] ?? 0)])
                        )),
                        'departments' => array_values(array_filter(
                            (array) ($historical['departments'] ?? []),
                            static fn (array $dept): bool =>
                                isset($allowedDepartments[(int) ($dept['id'] ?? 0)])
                        )),
                    ];
                }
            }
            return response()->json(['data' => $historical]);
        });
    }

    public function show(Request $request, int $absence): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request, $absence) {
            $item = $this->absences->get($absence);
            $actorId = (int) $employee['id'];
            $targetId = (int) $item['employee_id'];
            $isAssignedApprover = $this->reads->isAssignedApprover($absence, $actorId);
            $historicalReadOnly = false;
            $historicalPerson = null;
            if (!$isAssignedApprover) {
                try {
                    $this->authorization->assertCanAccessEmployee($request, $access, $actorId, $targetId);
                } catch (DomainException $original) {
                    $year = filter_var($request->query('historical_year'), FILTER_VALIDATE_INT);
                    if (!$this->authorization->isElevated($access) || $year === false || $year < 2000 || $year > 2100) {
                        throw $original;
                    }
                    $historicalDirectory = $this->employees->vacationsHistoricalDirectory($request, $year);
                    $historicalVisible = false;
                    foreach ($historicalDirectory['employees'] ?? [] as $person) {
                        if ((int) $person['id'] !== $targetId) continue;
                        $historicalPerson = $person;
                        foreach ($person['employment_segments'] ?? [] as $segment) {
                            if ($segment['from'] <= ($item['ends_on'] ?: '9999-12-31')
                                && $segment['to'] >= $item['starts_on']) $historicalVisible = true;
                        }
                    }
                    if (!$historicalVisible) throw $original;
                    // Historical membership permits viewing this absence, never performing actions.
                    $historicalReadOnly = true;
                }
            }

            $directory = $this->directoryMap($request, $employee, $access);
            $attachmentCount = $this->reads->attachmentCount($absence);
            $history = $this->reads->statusHistory($absence);
            $rejectedAudit = $this->reads->rejectionEvents($absence);
            $rejectionComments = [];
            foreach ($rejectedAudit as $event) {
                $after = json_decode((string) ($event->after ?? ''), true);
                $rejectionComments[] = is_array($after) ? ($after['rejection_comment'] ?? null) : null;
            }
            foreach ($history as &$entry) {
                if (($entry['reason'] ?? '') === 'rejected_to_planned') {
                    $entry['rejection_comment'] = array_shift($rejectionComments);
                }
            }
            unset($entry);
            $approvals = $this->enrichApprovals($this->absences->approvalsFor($absence), $this->approverNameMap($request));
            $pending = $this->pendingApprovalForActor($approvals, $request, $access, $actorId, $targetId);

            $item['employee_name'] = $directory[$targetId]['full_name']
                ?? $historicalPerson['full_name']
                ?? ($targetId === $actorId ? ($employee['full_name'] ?? null) : null);
            $item['department_name'] = $historicalReadOnly
                ? ($historicalPerson['department_name'] ?? null)
                : ($directory[$targetId]['department_name'] ?? ($targetId === $actorId ? ($employee['department_name'] ?? null) : null));
            $item['attachment_count'] = $attachmentCount;
            $item['available_actions'] = $historicalReadOnly
                ? ['view', 'history']
                : $this->availableActions($item, $access, $actorId, $pending, $attachmentCount,
                    $request->attributes->get('vacations_local_permissions'));
            $item['pending_approval_id'] = $historicalReadOnly ? null : ($pending['id'] ?? null);
            $item['pending_approval_stage'] = $pending['stage'] ?? null;
            $item['approval_progress'] = $approvals;

            return response()->json(['data' => [
                'absence' => $item,
                'approvals' => $approvals,
                'history' => $history,
            ]]);
        });
    }

    public function registry(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request) {
            $localGrants = $request->attributes->get('vacations_local_permissions');
            if (is_array($localGrants)) {
                if (($localGrants['vacations.manage.view']['allowed'] ?? false) !== true) {
                    throw new DomainException('Не разрешён просмотр реестра отпусков');
                }
            } elseif (!$this->authorization->isElevated($access)) {
                throw new DomainException('Недостаточно прав для просмотра отпусков подразделения');
            }


            $historical = $request->has('historical_year');
            $year = $historical ? filter_var($request->query('historical_year'), FILTER_VALIDATE_INT) : null;
            if ($historical && ($year === false || $year < 2000 || $year > 2100)) {
                return response()->json(['message' => 'Invalid historical year'], 422);
            }
            $directoryPayload = $historical
                ? $this->employees->vacationsHistoricalDirectory($request, $year)
                : $this->employees->vacationsDirectory($request);
            $directory = array_values($directoryPayload['employees'] ?? []);
            $byId = [];
            foreach ($directory as $person) $byId[(int) $person['id']] = $person;
            $employeeIds = array_keys($byId);

            $localGrants = $request->attributes->get('vacations_local_permissions');
            if (is_array($localGrants)) {
                $scope = (string) ($localGrants['vacations.manage.view']['scope'] ?? 'none');
                $actorIdForScope = (int) $employee['id'];
                if ($scope === 'own') {
                    $employeeIds = in_array($actorIdForScope, $employeeIds, true)
                        ? [$actorIdForScope] : [];
                } elseif ($scope === 'team') {
                    $org = $this->employees->vacationsDirectory($request);
                    $orgPeople = array_values($org['employees'] ?? []);
                    $orgDepartments = array_values($org['departments'] ?? []);
                    $employeeIds = array_values(array_filter($employeeIds, static fn (int $id): bool =>
                        \App\Services\VacationsObjectScope::allows(
                            'team', $actorIdForScope, $id, $orgDepartments, $orgPeople
                        )));
                } elseif ($scope !== 'all') {
                    $employeeIds = [];
                }
            }

            if ($departmentId = (int) $request->query('department_id', 0)) {
                $employeeIds = array_values(array_filter($employeeIds, fn (int $id) => (int) ($byId[$id]['department_id'] ?? 0) === $departmentId));
            }
            if ($employeeId = (int) $request->query('employee_id', 0)) {
                $employeeIds = in_array($employeeId, $employeeIds, true) ? [$employeeId] : [];
            }
            if (!$employeeIds) return response()->json(['data' => [], 'meta' => ['count' => 0]]);


            $filters = $request->query();
            if ($historical) {
                // A historical scope is ONLY valid for this requested year, not for every absence
                // ever recorded for an employee who happened to work in that department.
                $yearFrom = sprintf('%04d-01-01', $year);
                $yearTo = sprintf('%04d-12-31', $year);
                $filters['from'] = max($yearFrom, (string) ($filters['from'] ?? $yearFrom));
                $filters['to'] = min($yearTo, (string) ($filters['to'] ?? $yearTo));
                if ($filters['from'] > $filters['to']) {
                    return response()->json(['data' => [], 'meta' => ['count' => 0]]);
                }
            }
            $items = $this->reads->registry($employeeIds, $filters);
            if ($historical) {
                $items = array_values(array_filter($items, static function (array $absence) use ($byId): bool {
                    $segments = $byId[(int) $absence['employee_id']]['employment_segments'] ?? [];
                    foreach ($segments as $segment) {
                        if ($segment['from'] <= ($absence['ends_on'] ?: '9999-12-31')
                            && $segment['to'] >= $absence['starts_on']) return true;
                    }
                    return false;
                }));
            }
            $actorId = (int) $employee['id'];
            $currentEmployeeIds = null;
            if ($historical) {
                $currentEmployeeIds = array_fill_keys(array_map(
                    static fn ($p) => (int) $p['id'],
                    $this->employees->vacationsDirectory($request)['employees'] ?? []
                ), true);
            }
            $absenceIds = array_map(fn ($item) => (int) $item['id'], $items);
            $attachmentCounts = $this->attachmentCounts($absenceIds);
            $approvalTasks = $this->approvalTasks($absenceIds, $this->approverNameMap($request));

            foreach ($items as &$item) {
                $targetId = (int) $item['employee_id'];
                $item['employee_name'] = $byId[$targetId]['full_name'] ?? null;

                $item['department_id'] = $byId[$targetId]['department_id'] ?? null;
                $item['department_name'] = $byId[$targetId]['department_name'] ?? null;
                $item['attachment_count'] = $attachmentCounts[(int) $item['id']] ?? 0;
                $tasks = $approvalTasks[(int) $item['id']] ?? [];
                $pending = $this->pendingApprovalForActor($tasks, $request, $access, $actorId, $targetId);
                $item['available_actions'] = $historical && !isset($currentEmployeeIds[$targetId])
                    ? ['view', 'history']
                    : $this->availableActions($item, $access, $actorId, $pending, (int) $item['attachment_count'],
                        $request->attributes->get('vacations_local_permissions'));
                $item['pending_approval_id'] = $pending['id'] ?? null;
                $item['pending_approval_stage'] = $pending['stage'] ?? null;
                $item['approval_progress'] = $tasks;
                $item['requires_my_action'] = $pending !== null;
            }
            unset($item);

            return response()->json(['data' => $items, 'meta' => ['count' => count($items)]]);
        });
    }

    public function history(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request) {
            $actorId = (int) $employee['id'];
            $directory = [];
            $visibleEmployeeIds = [$actorId];

            $localGrants = $request->attributes->get('vacations_local_permissions');
            if (is_array($localGrants)) {
                $scope = (string) ($localGrants['vacations.history.view']['scope'] ?? 'none');
                if (in_array($scope, ['team', 'all'], true)) {
                    $payload = $this->employees->vacationsDirectory($request);
                    $people = array_values($payload['employees'] ?? []);
                    $departments = array_values($payload['departments'] ?? []);
                    $directory = array_values(array_filter($people,
                        static fn (array $person): bool => $scope === 'all'
                            || \App\Services\VacationsObjectScope::allows(
                                'team', $actorId, (int) ($person['id'] ?? 0), $departments, $people)));
                    $visibleEmployeeIds = array_values(array_unique(array_merge(
                        [$actorId], array_map(static fn (array $person): int =>
                            (int) ($person['id'] ?? 0), $directory)
                    )));
                }
            } elseif ($this->authorization->isElevated($access)) {
                $payload = $this->employees->vacationsDirectory($request);
                $directory = array_values($payload['employees'] ?? []);
                $visibleEmployeeIds = array_values(array_unique(array_merge(
                    [$actorId], array_map(static fn (array $row): int =>
                        (int) ($row['id'] ?? 0), $directory)
                )));
            }
            $directoryMap = [];
            foreach ($directory as $person) $directoryMap[(int) $person['id']] = $person;
            $directoryMap[$actorId] ??= $employee;

            $items = array_map(function (array $item) use ($directoryMap): array {
                $target = $directoryMap[(int) $item['employee_id']] ?? null;
                $actor = $item['actor_employee_id'] ? ($directoryMap[(int) $item['actor_employee_id']] ?? null) : null;
                $item['employee_name'] = $target['full_name'] ?? null;
                $item['department_name'] = $target['department_name'] ?? null;
                $item['actor_name'] = $actor['full_name'] ?? null;
                if (($item['event'] ?? null) === 'approved' && !empty($item['after'])) {
                    $after = is_array($item['after']) ? $item['after'] : json_decode((string) $item['after'], true);
                    if (is_array($after) && ($after['status'] ?? null) === 'confirmed') $item['event'] = 'confirmed';
                }
                unset($item['after']);
                return $item;
            }, $this->reads->auditHistory($visibleEmployeeIds, $request->query()));

            return response()->json(['data' => $items, 'meta' => ['count' => count($items)]]);
        });
    }

    private function directoryMap(Request $request, array $employee, array $access): array
    {
        $map = [(int) $employee['id'] => $employee];
        if (!$this->authorization->isElevated($access)) return $map;
        $payload = $this->employees->vacationsDirectory($request);
        foreach ($payload['employees'] ?? [] as $person) $map[(int) $person['id']] = $person;
        return $map;
    }

    private function approverNameMap(Request $request): array
    {
        $map = [];
        try {
            foreach ($this->employees->employees($request) as $person) {
                $id = (int) ($person['id'] ?? 0);
                if ($id > 0) $map[$id] = (string) ($person['full_name'] ?? "Сотрудник #{$id}");
            }
        } catch (DomainException $e) {
            // Employees global directory can be forbidden for ordinary employees.
            // A missing name must never block access to one's own absence.
        }
        return $map;
    }

    private function enrichApprovals(array $approvals, array $nameMap): array
    {
        foreach ($approvals as &$task) {
            $approverId = (int) ($task['approver_employee_id'] ?? 0);
            $task['approver_name'] = $approverId > 0 ? ($nameMap[$approverId] ?? "Сотрудник #{$approverId}") : null;
            $actualId = (int) ($task['acted_by_employee_id'] ?? 0);
            $task['acted_by_name'] = $actualId > 0 ? ($nameMap[$actualId] ?? "Сотрудник #{$actualId}") : null;
        }
        unset($task);
        return $approvals;
    }

    private function attachmentCounts(array $absenceIds): array
    {
        if (!$absenceIds) return [];
        return $this->reads->attachmentCounts($absenceIds);
    }

    private function approvalTasks(array $absenceIds, array $nameMap): array
    {
        if (!$absenceIds) return [];
        $rows = $this->reads->approvals($absenceIds);
        $result = [];
        foreach ($rows as $row) {
            $task = $row->getRawOriginal();
            $approverId = (int) ($task['approver_employee_id'] ?? 0);
            $task['approver_name'] = $approverId > 0 ? ($nameMap[$approverId] ?? "Сотрудник #{$approverId}") : null;
            $actualId = (int) ($task['acted_by_employee_id'] ?? 0);
            $task['acted_by_name'] = $actualId > 0 ? ($nameMap[$actualId] ?? "Сотрудник #{$actualId}") : null;
            $result[(int) $row->absence_id][] = $task;
        }
        return $result;
    }

    private function pendingApprovalForActor(array $approvals, Request $request, array $access, int $actorId, int $targetEmployeeId): ?array
    {
        foreach ($approvals as $task) {
            if (($task['status'] ?? null) !== 'pending') continue;
            $managerInScope = false;
            if (($task['required_role'] ?? null) === 'manager'
                && $this->authorization->isManager($access)
                && (int) ($task['approver_employee_id'] ?? 0) !== $actorId) {
                try {
                    $this->employees->employeeApprovalContext($request, $targetEmployeeId);
                    $managerInScope = true;
                } catch (DomainException) {
                    $managerInScope = false;
                }
            }
            if ($this->authorization->canApproveTask(
                $access, $actorId, $task, ['employee_id' => $targetEmployeeId], $managerInScope
            )) return $task;
        }
        return null;
    }

    private function availableActions(array $absence, array $access, int $actorId,
        ?array $pending, int $attachmentCount, ?array $localPermissions = null): array
    {
        $status = (string) $absence['status'];
        $owner = (int) $absence['employee_id'] === $actorId;
        $terminal = in_array($status, ['confirmed', 'rejected', 'cancelled'], true);
        $actions = ['view', 'history'];

        if (($owner || $this->authorization->isPersonnelOfficer($access) || $this->authorization->isManager($access)) && $status === 'planned') {
            if ($owner) $actions[] = 'edit';
            $documentRequired = in_array((string) $absence['type'], ['paid_vacation', 'unpaid_vacation', 'sick_leave', 'maternity_leave'], true);
            $documentReady = !$documentRequired || $attachmentCount > 0;
            $periodReady = !in_array((string) $absence['type'], ['sick_leave', 'maternity_leave'], true) || !empty($absence['ends_on']);
            if ($documentReady && $periodReady) $actions[] = 'submit';
        }
        if ($owner && !$terminal && $status !== 'planned') $actions[] = 'withdraw';
        if ($owner && !$terminal && $attachmentCount === 0) $actions[] = 'upload_attachment';
        if ($owner && $attachmentCount > 0) $actions[] = 'view_attachments';

        if ($this->authorization->isPersonnelOfficer($access)) {
            if ($attachmentCount > 0) $actions[] = 'view_attachments';
            if (!$terminal && $status !== 'planned') $actions[] = 'return_to_planned';
        } elseif ($this->authorization->isManager($access) && !$terminal && $status !== 'planned') {
            $actions[] = 'return_to_planned';
        }

        if ($pending) {
            $actions[] = 'reject';
            $actions[] = ($pending['stage'] ?? null) === 'hr_final_review' && $this->authorization->isPersonnelOfficer($access)
                ? 'provide'
                : 'approve';
        }

        if ($this->authorization->isAdmin($access)) $actions[] = 'delete';

        return \App\Services\VacationsActionPolicy::visible($actions, $localPermissions);
    }

    private function handle(Request $request, callable $callback): JsonResponse
    {
        try {
            $employee = $this->currentEmployee->resolve($request);
            $access = $this->employees->vacationsAccess($request, (int) $employee['id']);
            return $callback($employee, $access);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], str_contains($e->getMessage(), 'не найден') ? 404 : 403);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}
