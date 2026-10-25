<?php

namespace App\Http\Controllers;


use App\Application\AbsenceService;
use App\Application\AbsenceReadService;
use App\Domain\Absence\AbsenceStatus;
use App\Domain\Absence\AbsenceType;
use App\Support\ClientsDirectory;
use App\Support\CurrentEmployee;
use App\Support\EmployeesDirectory;
use App\Support\VacationsAccess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

final class AbsenceController extends Controller
{
    public function __construct(
        private readonly CurrentEmployee $currentEmployee,
        private readonly EmployeesDirectory $employees,
        private readonly ClientsDirectory $clients,
        private readonly AbsenceService $absences,
        private readonly AbsenceReadService $reads,
        private readonly VacationsAccess $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->withEmployee($request, function (array $employee) use ($request) {
            $year = (int) ($request->query('year') ?: now()->year);
            if ($year < 2000 || $year > 2100) return response()->json(['message' => 'Invalid year'], 422);
            $items = $this->absences->listOwn((int) $employee['id'], $year);
            $ids = array_map(fn ($item) => (int) $item['id'], $items);
            $attachmentCounts = $this->reads->attachmentCounts($ids);
            $approvalRows = $this->reads->approvals($ids);
            $approverIds = $approvalRows->flatMap(fn ($row) => [$row->approver_employee_id, $row->acted_by_employee_id ?? null])
                ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $approverNames = [];
            if ($approverIds) {
                try {
                    foreach ($this->employees->employees($request) as $person) {
                        $id = (int) ($person['id'] ?? 0);
                        if ($id > 0 && in_array($id, $approverIds, true)) $approverNames[$id] = (string) ($person['full_name'] ?? "Сотрудник #{$id}");
                    }
                } catch (DomainException $e) {
                    // Global Employees directory is not required to read own absences.
                }
            }
            $approvalsByAbsence = [];
            foreach ($approvalRows as $row) {
                $task = $row->getRawOriginal();
                $approverId = (int) ($task['approver_employee_id'] ?? 0);
                $task['approver_name'] = $approverId > 0 ? ($approverNames[$approverId] ?? "Сотрудник #{$approverId}") : null;
                $actualId = (int) ($task['acted_by_employee_id'] ?? 0);
                $task['acted_by_name'] = $actualId > 0 ? ($approverNames[$actualId] ?? "Сотрудник #{$actualId}") : null;
                $approvalsByAbsence[(int) $task['absence_id']][] = $task;
            }
            foreach ($items as &$item) {
                $item['attachment_count'] = (int) ($attachmentCounts[(int) $item['id']] ?? 0);
                $item['approval_progress'] = $approvalsByAbsence[(int) $item['id']] ?? [];
            }
            unset($item);
            return response()->json(['data' => $items, 'meta' => ['year' => $year, 'count' => count($items)]]);
        });
    }

    public function occupied(Request $request): JsonResponse
    {
        return $this->withEmployee($request, function (array $employee) use ($request) {
            $actorId = (int) $employee['id'];
            $targetId = (int) ($request->query('employee_id') ?: $actorId);

            if ($targetId !== $actorId) {
                $access = $this->employees->vacationsAccess($request, (int) $employee['id']);
                $this->authorization->assertCanAccessEmployee($request, $access, $actorId, $targetId);
            }

            $items = $this->reads->occupied($targetId, [
                AbsenceStatus::Cancelled->value, AbsenceStatus::Rejected->value,
            ]);

            return response()->json(['data' => $items, 'meta' => ['count' => count($items)]]);
        });
    }

    public function show(Request $request, int $absence): JsonResponse
    {
        return $this->withEmployee($request, fn (array $employee) => response()->json([
            'data' => $this->absences->getOwn($absence, (int) $employee['id']),
        ]));
    }

    public function history(Request $request, int $absence): JsonResponse
    {
        return $this->withEmployee($request, fn (array $employee) => response()->json([
            'data' => $this->absences->history($absence, (int) $employee['id']),
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->withEmployee($request, function (array $employee) use ($request) {
            $data = $this->validatePayload($request, false);
            $created = $this->absences->createOwn((int) $employee['id'], $data, $this->subject($request));
            return response()->json(['data' => $created], 201);
        });
    }

    public function storeForEmployee(Request $request): JsonResponse
    {
        return $this->withEmployee($request, function (array $employee) use ($request) {
            $validator = Validator::make($request->all(), ['employee_id' => ['required', 'integer', 'min:1']]);
            if ($validator->fails()) throw new DomainException($validator->errors()->first());

            $actorId = (int) $employee['id'];
            $targetId = (int) $validator->validated()['employee_id'];
            $access = $this->employees->vacationsAccess($request, (int) $employee['id']);
            if (!$this->authorization->isManager($access) && !$this->authorization->isPersonnelOfficer($access)) {
                throw new DomainException('Создавать отсутствие сотруднику может руководитель в своей зоне или кадровик');
            }
            $this->authorization->assertCanAccessEmployee($request, $access, $actorId, $targetId);

            $data = $this->validatePayload($request, false);
            $created = $this->absences->createOwn($targetId, $data, $this->subject($request));

            $this->absences->recordCreationActor((int) $created['id'], $actorId);

            return response()->json(['data' => $created], 201);
        });
    }

    public function update(Request $request, int $absence): JsonResponse
    {
        return $this->withEmployee($request, function (array $employee) use ($request, $absence) {
            $data = $this->validatePayload($request, true);
            return response()->json(['data' => $this->absences->updateOwnPlanned(
                $absence,
                (int) $employee['id'],
                $data,
                $this->subject($request),
            )]);
        });
    }

    public function submit(Request $request, int $absence): JsonResponse
    {
        return $this->withEmployee($request, function (array $employee) use ($request, $absence) {
            $actorId = (int) $employee['id'];
            $access = $this->employees->vacationsAccess($request, $actorId);
            $current = $this->absences->get($absence);
            $employeeId = (int) $current['employee_id'];
            if ($employeeId !== $actorId) {
                if (!$this->authorization->isPersonnelOfficer($access) && !$this->authorization->isManager($access)) {
                    throw new DomainException('Недостаточно прав для отправки отсутствия за сотрудника');
                }
                $this->authorization->assertCanAccessEmployee($request, $access, $actorId, $employeeId);
            }
            $type = AbsenceType::from($current['type']);

            if (in_array($type, [AbsenceType::SickLeave, AbsenceType::MaternityLeave], true) && empty($current['ends_on'])) {
                throw new DomainException('Перед отправкой на подтверждение укажите фактическую дату окончания отсутствия');
            }

            $documentRequired = in_array($type, [
                AbsenceType::PaidVacation,
                AbsenceType::UnpaidVacation,
                AbsenceType::SickLeave,
                AbsenceType::MaternityLeave,
            ], true);
            if ($documentRequired && !$this->reads->hasAttachment($absence)) {
                $message = in_array($type, [AbsenceType::PaidVacation, AbsenceType::UnpaidVacation], true)
                    ? 'Перед отправкой на согласование прикрепите заявление на отпуск'
                    : 'Перед отправкой на подтверждение прикрепите подтверждающий документ';
                throw new DomainException($message);
            }

            if ($this->absences->hasAssignedChain($absence)) return response()->json(['data'=>$this->absences->submitOwn($absence, $employeeId, $this->subject($request), [])]);

            $context = $employeeId === $actorId
                ? $this->employees->selfApprovalContext($request)
                : $this->employees->employeeApprovalContext($request, $employeeId);
            $personnelOfficers = array_values($context['personnel_officers'] ?? []);
            if (!$personnelOfficers) throw new DomainException('В Employees не назначен кадровик для согласования отпусков');
            $context['hr_approver'] = $personnelOfficers[0];

            $accountManagerIds = [];
            if (in_array($type, [AbsenceType::PaidVacation, AbsenceType::UnpaidVacation], true)) {
                $clientContext = $this->clients->absenceApprovers(
                    $request,
                    $employeeId,
                    (string) $current['starts_on'],
                    (string) $current['ends_on'],
                );
                $accountManagerIds = $clientContext['account_manager_ids'];
            }

            $result = $this->absences->submitWithClientApprovers(
                $absence, $employeeId, $this->subject($request), $context, $type, $accountManagerIds
            );

            return response()->json(['data' => $result]);
        });
    }

    private function validatePayload(Request $request, bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $validator = Validator::make($request->all(), [
            'type' => [$required, Rule::in(AbsenceType::values())],
            'starts_on' => [$required, 'date'],
            'ends_on' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($validator->fails()) throw new DomainException($validator->errors()->first());
        return $validator->validated();
    }

    private function subject(Request $request): string
    {
        $identity = (array) $request->attributes->get('identity', []);
        return (string) ($identity['sub'] ?? '');
    }

    private function withEmployee(Request $request, callable $callback): JsonResponse
    {
        try {
            return $callback($this->currentEmployee->resolve($request));
        } catch (DomainException $e) {
            $message = $e->getMessage();
            $status = str_contains($message, 'не найден') ? 404 : (str_contains($message, 'Недостаточно прав') || str_contains($message, 'Создавать отсутствие') ? 403 : 422);
            return response()->json(['message' => $message], $status);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}
