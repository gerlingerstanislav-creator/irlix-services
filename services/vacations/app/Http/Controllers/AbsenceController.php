<?php

namespace App\Http\Controllers;

use App\Application\AbsenceService;
use App\Domain\Absence\AbsenceType;
use App\Support\CurrentEmployee;
use App\Support\EmployeesDirectory;
use App\Support\VacationsAccess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

final class AbsenceController extends Controller
{
    public function __construct(
        private readonly CurrentEmployee $currentEmployee,
        private readonly EmployeesDirectory $employees,
        private readonly AbsenceService $absences,
        private readonly VacationsAccess $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->withEmployee($request, function (array $employee) use ($request) {
            $year = (int) ($request->query('year') ?: now()->year);
            if ($year < 2000 || $year > 2100) return response()->json(['message' => 'Invalid year'], 422);
            $items = $this->absences->listOwn((int) $employee['id'], $year);
            return response()->json(['data' => $items, 'meta' => ['year' => $year, 'count' => count($items)]]);
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
            $access = $this->employees->access($request);
            if (!$this->authorization->isManager($access)) {
                throw new DomainException('Только руководитель может создавать отсутствие сотруднику');
            }
            $this->authorization->assertCanAccessEmployee($request, $access, $actorId, $targetId);

            $data = $this->validatePayload($request, false);
            $created = $this->absences->createOwn($targetId, $data, $this->subject($request));

            // createOwn historically treats the owner as the actor. For delegated creation
            // keep the domain write path but correct the audit actor to the real manager.
            DB::table('absence_status_history')
                ->where('absence_id', $created['id'])
                ->where('reason', 'created')
                ->update(['actor_employee_id' => $actorId]);
            DB::table('absence_audit_log')
                ->where('absence_id', $created['id'])
                ->where('event', 'created')
                ->update(['actor_employee_id' => $actorId]);

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
            $context = $this->employees->selfApprovalContext($request);
            return response()->json(['data' => $this->absences->submitOwn(
                $absence,
                (int) $employee['id'],
                $this->subject($request),
                $context,
            )]);
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
            $status = str_contains($message, 'не найден') ? 404 : (str_contains($message, 'прав') || str_contains($message, 'Только руководитель') ? 403 : 422);
            return response()->json(['message' => $message], $status);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}
