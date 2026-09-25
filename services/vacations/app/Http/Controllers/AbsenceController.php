<?php

namespace App\Http\Controllers;

use App\Application\AbsenceService;
use App\Domain\Absence\AbsenceType;
use App\Support\CurrentEmployee;
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
        private readonly AbsenceService $absences,
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
        return $this->withEmployee($request, fn (array $employee) => response()->json([
            'data' => $this->absences->submitOwn($absence, (int) $employee['id'], $this->subject($request)),
        ]));
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
            return response()->json(['message' => $e->getMessage()], str_contains($e->getMessage(), 'не найден') ? 404 : 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }
}
