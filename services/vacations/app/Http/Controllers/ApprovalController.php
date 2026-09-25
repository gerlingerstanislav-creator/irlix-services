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
            $isManager = $this->authorization->isManager($access);
            $isPersonnelOfficer = $this->authorization->isPersonnelOfficer($access);

            $items = DB::table('absence_approvals as a')
                ->join('absences as x', 'x.id', '=', 'a.absence_id')
                ->where('a.status', 'pending')
                ->where(function ($query) use ($employeeId, $isManager, $isPersonnelOfficer) {
                    $query->where('a.approver_employee_id', $employeeId);
                    if ($isPersonnelOfficer) $query->orWhere('a.required_role', 'hr'); // legacy DB stage = personnel review
                    if ($isManager) $query->orWhere('a.required_role', 'manager');
                })
                ->select(['a.*', 'x.employee_id', 'x.type', 'x.starts_on', 'x.ends_on', 'x.status as absence_status', 'x.comment'])
                ->orderBy('a.created_at')
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            $filtered = [];
            foreach ($items as $item) {
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
                $item['available_actions'] = ['view', 'history'];
                $item['available_actions'][] = ($isPersonnelOfficer && ($item['stage'] ?? null) === 'hr_final_review') ? 'provide' : 'approve';
                if ($isPersonnelOfficer || $isManager) $item['available_actions'][] = 'return_to_planned';
                if ($isPersonnelOfficer && $item['attachment_count'] > 0) $item['available_actions'][] = 'view_attachments';
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
            $result = $this->absences->approve(
                $approval,
                $employeeId,
                $this->subject($request),
                function (array $task, array $absence) use ($request, $access, $employeeId): bool {
                    if ((int) ($task['approver_employee_id'] ?? 0) === $employeeId) return true;
                    if ($this->authorization->isAdmin($access)) return true;

                    // Historical stage/DB role `hr` represents the personnel approval stage.
                    if (($task['required_role'] ?? null) === 'hr') return $this->authorization->isPersonnelOfficer($access);
                    if (($task['required_role'] ?? null) !== 'manager' || !$this->authorization->isManager($access)) return false;

                    try {
                        $this->employees->employeeApprovalContext($request, (int) $absence['employee_id']);
                        return true;
                    } catch (DomainException) {
                        return false;
                    }
                }
            );

            return response()->json(['data' => $result]);
        });
    }

    public function returnToPlanned(Request $request, int $absence): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request, $absence) {
            $target = $this->absences->get($absence);
            $employeeId = (int) $employee['id'];
            $allowed = $this->authorization->isPersonnelOfficer($access);

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
            $access = $this->employees->access($request);
            return $callback($employee, $access);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], str_contains($e->getMessage(), 'не найден') ? 404 : 422);
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
