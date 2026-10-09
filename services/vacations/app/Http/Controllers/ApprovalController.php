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

final class ApprovalController extends Controller
{
    public function __construct(
        private readonly CurrentEmployee $currentEmployee,
        private readonly EmployeesDirectory $employees,
        private readonly AbsenceService $absences,
        private readonly VacationsAccess $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request) {
            $employeeId = (int) $employee['id'];
            $isAdmin = $this->authorization->isAdmin($access);
            $isManager = $this->authorization->isManager($access);
            $isPersonnelOfficer = $this->authorization->isPersonnelOfficer($access);

            $query = DB::table('absence_approvals as a')
                ->join('absences as x', 'x.id', '=', 'a.absence_id')
                ->where('a.status', 'pending')
                ->select(['a.*', 'x.employee_id', 'x.type', 'x.starts_on', 'x.ends_on', 'x.status as absence_status', 'x.comment']);

            if (!$isAdmin && !$isPersonnelOfficer) {
                $query->where(function ($query) use ($employeeId, $isManager) {
                    $query->where('a.approver_employee_id', $employeeId);
                    if ($isManager) $query->orWhere('a.required_role', 'manager');
                });
            }

            $items = $query->orderBy('a.created_at')->get()->map(fn ($row) => (array) $row)->all();

            $filtered = [];
            foreach ($items as $item) {
                if ($isAdmin) {
                    $filtered[] = $item;
                    continue;
                }
                if (!$isAdmin && (int) $item['employee_id'] === $employeeId) continue;
                if ($isPersonnelOfficer) { $filtered[] = $item; continue; }
                if (($item['required_role'] ?? null) === 'hr') {
                    if ($isPersonnelOfficer) $filtered[] = $item;
                    continue;
                }
                if (($item['required_role'] ?? null) !== 'manager' || (int) ($item['approver_employee_id'] ?? 0) === $employeeId) {
                    $filtered[] = $item;
                    continue;
                }
                try {
                    $this->employees->employeeApprovalContext($request, (int) $item['employee_id']);
                    $filtered[] = $item;
                } catch (DomainException) {
                    // Employees permission+scope remains the source of truth for manager visibility.
                }
            }

            $directoryPayload = $this->employees->vacationsDirectory($request);
            $directory = [];
            foreach ($directoryPayload['employees'] ?? [] as $person) $directory[(int) $person['id']] = $person;
            $absenceIds = array_values(array_unique(array_map(fn ($item) => (int) $item['absence_id'], $filtered)));
            $attachmentCounts = $absenceIds
                ? DB::table('absence_attachments')->whereIn('absence_id', $absenceIds)
                    ->selectRaw('absence_id, COUNT(*)::int as aggregate')->groupBy('absence_id')
                    ->pluck('aggregate', 'absence_id')->all()
                : [];

            foreach ($filtered as &$item) {
                $target = $directory[(int) $item['employee_id']] ?? null;
                $item['employee_name'] = $target['full_name'] ?? null;
                $item['department_name'] = $target['department_name'] ?? null;
                $item['attachment_count'] = (int) ($attachmentCounts[(int) $item['absence_id']] ?? 0);
                $item['available_actions'] = ['view', 'history', 'reject'];
                $item['available_actions'][] = ($item['stage'] ?? null) === 'hr_final_review' && $isPersonnelOfficer ? 'provide' : 'approve';
                if ($isAdmin || $isPersonnelOfficer || $isManager) $item['available_actions'][] = 'return_to_planned';
                if (($isAdmin || $isPersonnelOfficer) && $item['attachment_count'] > 0) $item['available_actions'][] = 'view_attachments';
                $item['pending_approval_id'] = (int) $item['id'];
            }
            unset($item);

            return response()->json(['data' => array_values($filtered), 'meta' => ['count' => count($filtered)]]);
        });
    }

    public function approve(Request $request, int $approval): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request, $approval) {
            $employeeId = (int) $employee['id'];
            $subject = $this->subject($request);

            if ($this->authorization->isAdmin($access)) {
                $task = DB::table('absence_approvals')->where('id', $approval)->where('status', 'pending')->first();
                if (!$task) throw new DomainException('Задача согласования не найдена или уже обработана');
                $stageTaskIds = DB::table('absence_approvals')
                    ->where('absence_id', $task->absence_id)
                    ->where('sequence', $task->sequence)
                    ->where('status', 'pending')
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $result = null;
                foreach ($stageTaskIds as $stageTaskId) {
                    $result = $this->absences->approve($stageTaskId, $employeeId, $subject, fn () => true);
                }
                return response()->json(['data' => $result ?? $this->absences->get((int) $task->absence_id)]);
            }

            $selected = DB::table('absence_approvals')->where('id', $approval)->where('status', 'pending')->first();
            if (!$selected) throw new DomainException('Задача согласования не найдена или уже обработана');
            $selectedAbsence = $this->absences->get((int) $selected->absence_id);
            if ((int) $selectedAbsence['employee_id'] === $employeeId) throw new DomainException('Нельзя согласовать собственное отсутствие');
            $delegated = $this->authorization->isPersonnelOfficer($access)
                && (int) ($selected->approver_employee_id ?? 0) !== $employeeId;
            if ($delegated && !$request->boolean('delegate_confirmed')) {
                return response()->json(['message' => 'Вы пытаетесь провести согласование за другого сотрудника', 'requires_delegation_confirmation' => true], 409);
            }

            $result = $this->absences->approve(
                $approval,
                $employeeId,
                $subject,
                function (array $task, array $absence) use ($request, $access, $employeeId): bool {
                    $managerInScope = false;
                    if (($task['required_role'] ?? null) === 'manager'
                        && $this->authorization->isManager($access)
                        && (int) ($task['approver_employee_id'] ?? 0) !== $employeeId) {
                        try {
                            $this->employees->employeeApprovalContext($request, (int) $absence['employee_id']);
                            $managerInScope = true;
                        } catch (DomainException) {
                            $managerInScope = false;
                        }
                    }
                    return $this->authorization->canApproveTask($access, $employeeId, $task, $absence, $managerInScope);
                }
            );

            return response()->json(['data' => $result]);
        });
    }

    public function reject(Request $request, int $approval): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request, $approval) {
            $comment = trim((string) $request->input('comment', ''));
            if ($comment === '' || mb_strlen($comment) > 2000) throw new DomainException('Укажите комментарий отклонения (до 2000 символов)');
            $task = DB::table('absence_approvals')->where('id', $approval)->where('status', 'pending')->first();
            if (!$task) throw new DomainException('Задача согласования не найдена или уже обработана');
            $absence = $this->absences->get((int) $task->absence_id);
            $actorId = (int) $employee['id'];
            if (!$this->authorization->isAdmin($access) && (int) $absence['employee_id'] === $actorId) throw new DomainException('Нельзя отклонить собственное отсутствие');
            $delegated = !$this->authorization->isAdmin($access) && $this->authorization->isPersonnelOfficer($access)
                && (int) ($task->approver_employee_id ?? 0) !== $actorId;
            if ($delegated && !$request->boolean('delegate_confirmed')) {
                return response()->json(['message' => 'Вы пытаетесь провести согласование за другого сотрудника', 'requires_delegation_confirmation' => true], 409);
            }
            $managerInScope = false;
            if (($task->required_role ?? null) === 'manager' && $this->authorization->isManager($access)) {
                try {
                    $this->employees->employeeApprovalContext($request, (int) $absence['employee_id']);
                    $managerInScope = true;
                } catch (DomainException) {
                    $managerInScope = false;
                }
            }
            if (!$this->authorization->canApproveTask($access, $actorId, (array) $task, $absence, $managerInScope)) {
                throw new DomainException('Недостаточно прав для отклонения');
            }
            return response()->json(['data' => $this->absences->returnToPlanned(
                (int) $absence['id'], $actorId, $this->subject($request), 'rejected_to_planned', $comment
            )]);
        });
    }

    public function returnToPlanned(Request $request, int $absence): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request, $absence) {
            $target = $this->absences->get($absence);
            $employeeId = (int) $employee['id'];
            $allowed = $this->authorization->isAdmin($access) || $this->authorization->isPersonnelOfficer($access);

            if (!$allowed && (int) $target['employee_id'] === $employeeId
                && !in_array($target['status'], ['confirmed', 'cancelled', 'rejected'], true)) $allowed = true;
            if (!$allowed && $this->authorization->isManager($access)) {
                try {
                    $this->employees->employeeApprovalContext($request, (int) $target['employee_id']);
                    $allowed = true;
                } catch (DomainException) {
                    $allowed = false;
                }
            }

            if (!$allowed) throw new DomainException('Недостаточно прав для возврата отсутствия в «Запланировано»');

            return response()->json(['data' => $this->absences->returnToPlanned(
                $absence,
                $employeeId,
                $this->subject($request),
            )]);
        });
    }

    private function handle(Request $request, callable $callback): JsonResponse
    {
        try {
            $employee = $this->currentEmployee->resolve($request);
            $access = $this->employees->vacationsAccess($request, (int) $employee['id']);
            return $callback($employee, $access);
        } catch (DomainException $e) {
            $message = $e->getMessage();
            $status = str_contains($message, 'не найден') ? 404
                : (str_contains($message, 'Недостаточно прав') || str_contains($message, 'Нельзя согласовать') || str_contains($message, 'Нельзя отклонить') ? 403 : 422);
            return response()->json(['message' => $message], $status);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    private function subject(Request $request): string
    {
        $identity = (array) $request->attributes->get('identity', []);
        return (string) ($identity['sub'] ?? '');
    }
}
