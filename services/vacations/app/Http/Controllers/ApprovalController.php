<?php

namespace App\Http\Controllers;

use App\Application\AbsenceService;
use App\Support\CurrentEmployee;
use App\Support\EmployeesDirectory;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ApprovalController extends Controller
{
    public function __construct(
        private readonly CurrentEmployee $currentEmployee,
        private readonly EmployeesDirectory $employees,
        private readonly AbsenceService $absences,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->handle($request, function (array $employee, array $access) use ($request) {
            $items = $this->absences->approvalQueueFor((int) $employee['id'], $access);

            $filtered = [];
            foreach ($items as $item) {
                if (($item['required_role'] ?? null) !== 'manager' || (int) ($item['approver_employee_id'] ?? 0) === (int) $employee['id']) {
                    $filtered[] = $item;
                    continue;
                }

                try {
                    $this->employees->employeeApprovalContext($request, (int) $item['employee_id']);
                    $filtered[] = $item;
                } catch (DomainException) {
                    // The Employees scope is the source of truth for a manager's subtree.
                }
            }

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

                    $roles = array_map('strval', $access['roles'] ?? []);
                    $admin = in_array('company-admin', $roles, true) || in_array('platform-admin', $roles, true);
                    if ($admin) return true;

                    if (($task['required_role'] ?? null) === 'hr' && in_array('hr', $roles, true)) return true;
                    if (($task['required_role'] ?? null) !== 'manager' || !in_array('manager', $roles, true)) return false;

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
            $roles = array_map('strval', $access['roles'] ?? []);
            $admin = in_array('company-admin', $roles, true) || in_array('platform-admin', $roles, true);
            $allowed = $admin || in_array('hr', $roles, true);

            if (!$allowed && in_array('manager', $roles, true)) {
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
