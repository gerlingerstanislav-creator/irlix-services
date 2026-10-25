<?php

namespace App\Http\Controllers;

use App\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class DepartmentController extends Controller
{
    public function __construct(private readonly DepartmentService $departments) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->departments->list()]);
    }

    private function rules(?int $department = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'alias' => [
                'nullable', 'string', 'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Rule::unique('departments', 'alias')->ignore($department),
                Rule::notIn(['platform-admin', 'platform-tester', 'personnel-officer',
                    'system-admin', 'finance-director', 'finance', 'manager']),
                'not_regex:/-head$/D',
            ],
            'parent_id' => ['nullable', 'integer', 'exists:departments,id'],
            'manager_id' => ['nullable', 'integer', 'exists:employees,id'],
            'hr_id' => ['nullable', 'integer', 'exists:employees,id'],
            'yandex_id' => ['nullable', 'integer'],
            'ldap_group' => ['nullable', 'string', 'max:255'],
            'is_production' => ['boolean'],
        ];
    }

    private function normalizeAlias(Request $request): void
    {
        if ($request->exists('alias') && is_string($request->input('alias'))) {
            $request->merge(['alias' => preg_replace('/ +/', '-', trim($request->input('alias')))]);
        }
    }

    private function isAdmin(Request $request): bool
    {
        return in_array('platform-admin', (array) ($request->attributes
            ->get('employees_access', [])['roles'] ?? []), true);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request)) return response()->json([
            'message' => 'Только администратор платформы может изменять подразделения.',
        ], 403);
        $this->normalizeAlias($request);
        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        return response()->json(['data' => $this->departments->create($validator->validated())], 201);
    }

    public function update(Request $request, int $department): JsonResponse
    {
        if (!$this->isAdmin($request)) return response()->json([
            'message' => 'Только администратор платформы может изменять подразделения.',
        ], 403);
        $this->normalizeAlias($request);
        $validator = Validator::make($request->all(), $this->rules($department));
        if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);
        if (!$this->departments->exists($department)) {
            return response()->json(['message' => 'Department not found'], 404);
        }
        $data = $validator->validated();
        $parent = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        if ($this->departments->wouldCycle($department, $parent)) return response()->json([
            'errors' => ['parent_id' => [
                'Подразделение не может быть вложено в себя или в собственное дочернее подразделение.',
            ]],
        ], 422);
        return response()->json(['data' => $this->departments->update($department, $data, $parent)]);
    }
}
