<?php

namespace App\Http\Controllers;

use App\Services\DepartmentService;
use App\Services\StaffPositionService;
use App\Support\SpecialRoles;
use App\Support\StaffPositionImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

final class OrganizationController extends Controller
{
    public function __construct(
        private readonly StaffPositionService $positions,
        private readonly DepartmentService $departments
    ) {}

    private function isAdmin(Request $request): bool
    {
        $access = (array) $request->attributes->get('employees_access', []);
        return SpecialRoles::isPlatformPrivileged((array) ($access['roles'] ?? []));
    }

    private function validateName($attribute, $value, $fail): void
    {
        if (strtolower(str_replace([' ', '-', '_'], '', trim((string) $value))) === 'platformadministrator') {
            $fail('Администратор платформы — глобальная роль, а не должность штатного расписания.');
        }
    }

    private function validatePosition(Request $request, ?int $position = null): array|JsonResponse
    {
        $request->merge(['name' => trim((string) $request->input('name', ''))]);
        $unique = Rule::unique('staff_positions', 'name')
            ->where(fn ($query) => $query->where('direction_id', (int) $request->input('direction_id')));
        if ($position !== null) $unique->ignore($position);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', $this->validateName(...), $unique],
            'direction_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'base_salary' => ['nullable', 'numeric', 'min:0'],
        ]);
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        $data = $validator->validated();
        if (!$this->positions->existsDirection((int) $data['direction_id'])) {
            return response()->json(['errors' => [
                'direction_id' => ['Выберите существующее подразделение для должности.'],
            ]], 422);
        }
        return $data;
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->positions->list(
            (array) $request->attributes->get('employees_access', [])
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) return response()->json([
            'message' => 'Только администратор платформы может изменять штатное расписание.',
        ], 403);
        $data = $this->validatePosition($request);
        if ($data instanceof JsonResponse) return $data;
        return response()->json(['data' => $this->positions->create($data)], 201);
    }

    public function update(Request $request, int $position): JsonResponse
    {
        if (!$this->isAdmin($request)) return response()->json([
            'message' => 'Только администратор платформы может изменять штатное расписание.',
        ], 403);
        $current = $this->positions->findOriginal($position);
        if (!$current) return response()->json(['message' => 'Должность не найдена.'], 404);
        $data = $this->validatePosition($request, $position);
        if ($data instanceof JsonResponse) return $data;

        return response()->json(['data' => $this->positions->update($position, $data, $current)]);
    }

    public function import(Request $request, StaffPositionImporter $importer): JsonResponse
    {
        if (!$this->isAdmin($request)) return response()->json([
            'message' => 'Только администратор платформы может импортировать штатное расписание.',
        ], 403);
        $payload = $request->json()->all();
        if (isset($payload['positions']) && is_array($payload['positions'])) $payload = $payload['positions'];
        try {
            $result = $importer->import($payload);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => 'Импорт не выполнен из-за ошибки базы данных. Изменения отменены.'], 500);
        }
        return response()->json(['data' => $result]);
    }

    public function close(Request $request, int $position): JsonResponse
    {
        if (!$this->isAdmin($request)) return response()->json([
            'message' => 'Только администратор платформы может закрывать должности.',
        ], 403);
        if (!$this->positions->findOriginal($position)) {
            return response()->json(['message' => 'Должность не найдена.'], 404);
        }
        return response()->json(['data' => $this->positions->close($position)]);
    }

    public function reopen(Request $request, int $position): JsonResponse
    {
        if (!$this->isAdmin($request)) return response()->json([
            'message' => 'Только администратор платформы может переоткрывать должности.',
        ], 403);
        if (!$this->positions->findOriginal($position)) {
            return response()->json(['message' => 'Должность не найдена.'], 404);
        }
        return response()->json(['data' => $this->positions->reopen($position)]);
    }

    public function destroyPosition(Request $request, int $position): JsonResponse
    {
        $access = (array) $request->attributes->get('employees_access', []);
        if (!in_array('platform-admin', (array) ($access['roles'] ?? []), true)) return response()->json([
            'message' => 'Полное удаление должности доступно только администратору платформы.',
        ], 403);
        $outcome = $this->positions->delete($position);
        $status = $outcome['status'];
        unset($outcome['status']);
        return response()->json($outcome, $status);
    }

    public function destroyDepartment(Request $request, int $department): JsonResponse
    {
        if (!$this->isAdmin($request)) return response()->json([
            'message' => 'Полное удаление подразделения доступно только администратору платформы.',
        ], 403);
        if (!hash_equals('engineer', trim((string) $request->input('confirmation_code', '')))) {
            return response()->json(['message' => 'Неверный контрольный код удаления.'], 422);
        }
        $outcome = $this->departments->deleteEmpty($department);
        $status = $outcome['status'];
        unset($outcome['status']);
        return response()->json($outcome, $status);
    }
}
