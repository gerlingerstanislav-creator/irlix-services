<?php

namespace App\Http\Controllers;

use App\Application\AbsenceService;
use App\Support\CurrentEmployee;
use App\Support\EmployeesDirectory;
use App\Support\VacationsAccess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class WorkspaceController extends Controller
{
    public function __construct(
        private readonly CurrentEmployee $currentEmployee,
        private readonly EmployeesDirectory $employees,
        private readonly AbsenceService $absences,
        private readonly VacationsAccess $authorization,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request) {
            $directory = [];
            $departments = [];
            if ($this->authorization->isElevated($access)) {
                $payload = $this->employees->vacationsDirectory($request);
                $directory = array_values($payload['employees'] ?? []);
                $departments = array_values($payload['departments'] ?? []);
            }

            return response()->json(['data' => [
                'employee' => $employee,
                'access' => $access,
                'employees' => $directory,
                'departments' => $departments,
            ]]);
        });
    }

    public function show(Request $request, int $absence): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request, $absence) {
            $item = $this->absences->get($absence);
            $actorId = (int) $employee['id'];
            $targetId = (int) $item['employee_id'];
            $this->authorization->assertCanAccessEmployee($request, $access, $actorId, $targetId);

            $directory = $this->directoryMap($request, $employee, $access);
            $attachmentCount = DB::table('absence_attachments')->where('absence_id', $absence)->count();
            $history = DB::table('absence_status_history')->where('absence_id', $absence)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $approvals = $this->absences->approvalsFor($absence);
            $pending = $this->pendingApprovalForActor($approvals, $request, $access, $actorId, $targetId);

            $item['employee_name'] = $directory[$targetId]['full_name'] ?? ($targetId === $actorId ? ($employee['full_name'] ?? null) : null);
            $item['department_name'] = $directory[$targetId]['department_name'] ?? ($targetId === $actorId ? ($employee['department_name'] ?? null) : null);
            $item['attachment_count'] = $attachmentCount;
            $item['available_actions'] = $this->availableActions($item, $access, $actorId, $pending, $attachmentCount);
            $item['pending_approval_id'] = $pending['id'] ?? null;
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
            if (!$this->authorization->isElevated($access)) throw new DomainException('Недостаточно прав для просмотра отпусков подразделения');

            $directoryPayload = $this->employees->vacationsDirectory($request);
            $directory = array_values($directoryPayload['employees'] ?? []);
            $byId = [];
            foreach ($directory as $person) $byId[(int) $person['id']] = $person;
            $employeeIds = array_keys($byId);

            if ($departmentId = (int) $request->query('department_id', 0)) {
                $employeeIds = array_values(array_filter($employeeIds, fn (int $id) => (int) ($byId[$id]['department_id'] ?? 0) === $departmentId));
            }
            if ($employeeId = (int) $request->query('employee_id', 0)) {
                $employeeIds = in_array($employeeId, $employeeIds, true) ? [$employeeId] : [];
            }
            if (!$employeeIds) return response()->json(['data' => [], 'meta' => ['count' => 0]]);

            $query = DB::table('absences')->whereIn('employee_id', $employeeIds);
            if ($from = trim((string) $request->query('from', ''))) {
                $query->where(function ($q) use ($from) {
                    $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from);
                });
            }
            if ($to = trim((string) $request->query('to', ''))) $query->whereDate('starts_on', '<=', $to);
            if ($type = trim((string) $request->query('type', ''))) $query->where('type', $type);
            if ($status = trim((string) $request->query('status', ''))) $query->where('status', $status);

            $items = $query->orderBy('starts_on')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $actorId = (int) $employee['id'];
            $absenceIds = array_map(fn ($item) => (int) $item['id'], $items);
            $attachmentCounts = $this->attachmentCounts($absenceIds);
            $approvalTasks = $this->approvalTasks($absenceIds);

            foreach ($items as &$item) {
                $targetId = (int) $item['employee_id'];
                $item['employee_name'] = $byId[$targetId]['full_name'] ?? null;
                $item['department_id'] = $byId[$targetId]['department_id'] ?? null;
                $item['department_name'] = $byId[$targetId]['department_name'] ?? null;
                $item['attachment_count'] = $attachmentCounts[(int) $item['id']] ?? 0;
                $tasks = $approvalTasks[(int) $item['id']] ?? [];
                $pending = $this->pendingApprovalForActor($tasks, $request, $access, $actorId, $targetId);
                $item['available_actions'] = $this->availableActions($item, $access, $actorId, $pending, (int) $item['attachment_count']);
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

            if ($this->authorization->isElevated($access)) {
                $payload = $this->employees->vacationsDirectory($request);
                $directory = array_values($payload['employees'] ?? []);
                $visibleEmployeeIds = array_values(array_unique(array_merge([$actorId], array_map(fn ($row) => (int) $row['id'], $directory))));
            }
            $directoryMap = [];
            foreach ($directory as $person) $directoryMap[(int) $person['id']] = $person;
            $directoryMap[$actorId] ??= $employee;

            $query = DB::table('absence_audit_log as log')
                ->join('absences as x', 'x.id', '=', 'log.absence_id')
                ->whereIn('x.employee_id', $visibleEmployeeIds)
                ->select([
                    'log.id', 'log.absence_id', 'log.event', 'log.actor_subject', 'log.actor_employee_id', 'log.after', 'log.created_at',
                    'x.employee_id', 'x.type', 'x.starts_on', 'x.ends_on', 'x.status',
                ]);

            if ($from = trim((string) $request->query('from', ''))) $query->whereDate('log.created_at', '>=', $from);
            if ($to = trim((string) $request->query('to', ''))) $query->whereDate('log.created_at', '<=', $to);
            if ($employeeId = (int) $request->query('employee_id', 0)) $query->where('x.employee_id', $employeeId);
            if ($event = trim((string) $request->query('event', ''))) $query->where('log.event', $event);

            $items = $query->orderByDesc('log.created_at')->orderByDesc('log.id')->limit(500)->get()->map(function ($row) use ($directoryMap) {
                $item = (array) $row;
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
            })->all();

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

    private function attachmentCounts(array $absenceIds): array
    {
        if (!$absenceIds) return [];
        return DB::table('absence_attachments')
            ->whereIn('absence_id', $absenceIds)
            ->selectRaw('absence_id, COUNT(*)::int as aggregate')
            ->groupBy('absence_id')
            ->pluck('aggregate', 'absence_id')
            ->mapWithKeys(fn ($count, $id) => [(int) $id => (int) $count])
            ->all();
    }

    private function approvalTasks(array $absenceIds): array
    {
        if (!$absenceIds) return [];
        $rows = DB::table('absence_approvals')->whereIn('absence_id', $absenceIds)->orderBy('sequence')->orderBy('id')->get();
        $result = [];
        foreach ($rows as $row) $result[(int) $row->absence_id][] = (array) $row;
        return $result;
    }

    private function pendingApprovalForActor(array $approvals, Request $request, array $access, int $actorId, int $targetEmployeeId): ?array
    {
        foreach ($approvals as $task) {
            if (($task['status'] ?? null) !== 'pending') continue;
            if ((int) ($task['approver_employee_id'] ?? 0) === $actorId) return $task;
            // Historical `hr` approval tasks are the кадровик stages in Vacations.
            if (($task['required_role'] ?? null) === 'hr' && $this->authorization->isPersonnelOfficer($access)) return $task;
            if (($task['required_role'] ?? null) === 'manager' && $this->authorization->isManager($access)
                && $this->authorization->canAccessEmployee($request, $access, $actorId, $targetEmployeeId)) return $task;
        }
        return null;
    }

    private function availableActions(array $absence, array $access, int $actorId, ?array $pending, int $attachmentCount): array
    {
        $status = (string) $absence['status'];
        $owner = (int) $absence['employee_id'] === $actorId;
        $terminal = in_array($status, ['confirmed', 'rejected', 'cancelled'], true);
        $actions = ['view', 'history'];

        if ($owner && $status === 'planned') {
            $actions[] = 'edit';
            $actions[] = 'submit';
        }
        if ($owner && !$terminal) $actions[] = 'upload_attachment';
        if ($owner && $attachmentCount > 0) $actions[] = 'view_attachments';

        if ($this->authorization->isPersonnelOfficer($access)) {
            if ($attachmentCount > 0) $actions[] = 'view_attachments';
            if (!$terminal && $status !== 'planned') $actions[] = 'return_to_planned';
        } elseif ($this->authorization->isManager($access) && !$terminal && $status !== 'planned') {
            $actions[] = 'return_to_planned';
        }

        if ($pending) {
            $actions[] = ($pending['stage'] ?? null) === 'hr_final_review' && $this->authorization->isPersonnelOfficer($access)
                ? 'provide'
                : 'approve';
        }

        return array_values(array_unique($actions));
    }

    private function handle(Request $request, callable $callback): JsonResponse
    {
        try {
            $employee = $this->currentEmployee->resolve($request);
            $access = $this->employees->access($request);
            return $callback($employee, $access);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], str_contains($e->getMessage(), 'не найден') ? 404 : 403);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}
