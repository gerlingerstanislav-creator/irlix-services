<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

$employeeStatuses = [
    'Ожидает трудоустройства',
    'Трудоустроен',
    'Уволен',
];

$workFormats = [
    'Офис',
    'Удалённо',
];

$cooperationTypes = [
    'Штат',
    'ГПХ',
    'ИП',
    'Самозанятый',
];

$departmentWouldCycle = function (int $departmentId, ?int $parentId): bool {
    if ($parentId === null) {
        return false;
    }

    if ($departmentId === $parentId) {
        return true;
    }

    $visited = [];
    $currentId = $parentId;

    while ($currentId !== null) {
        if (isset($visited[$currentId])) {
            return true;
        }

        if ($currentId === $departmentId) {
            return true;
        }

        $visited[$currentId] = true;
        $currentId = DB::table('departments')->where('id', $currentId)->value('parent_id');
    }

    return false;
};

Route::get('/health', function () {
    DB::select('select 1');

    return response()->json([
        'service' => 'employees',
        'status' => 'ok',
        'database' => 'ok',
    ]);
});

Route::get('/reference-data', function () use ($employeeStatuses, $workFormats, $cooperationTypes) {
    return response()->json([
        'data' => [
            'employee_statuses' => $employeeStatuses,
            'work_formats' => $workFormats,
            'cooperation_types' => $cooperationTypes,
        ],
    ]);
});

Route::get('/departments', function () {
    $departments = DB::table('departments as d')
        ->leftJoin('departments as parent', 'parent.id', '=', 'd.parent_id')
        ->leftJoin('employees as employee', 'employee.department_id', '=', 'd.id')
        ->select([
            'd.id',
            'd.name',
            'd.alias',
            'd.parent_id',
            'parent.name as parent_name',
            DB::raw('COUNT(employee.id)::int as employee_count'),
            'd.created_at',
            'd.updated_at',
        ])
        ->groupBy('d.id', 'd.name', 'd.alias', 'd.parent_id', 'parent.name', 'd.created_at', 'd.updated_at')
        ->orderBy('d.name')
        ->get();

    return response()->json(['data' => $departments]);
});

Route::post('/departments', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'name' => ['required', 'string', 'max:255'],
        'alias' => ['nullable', 'string', 'max:255'],
        'parent_id' => ['nullable', 'integer', 'exists:departments,id'],
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    $id = DB::table('departments')->insertGetId([
        'name' => $data['name'],
        'alias' => $data['alias'] ?? null,
        'parent_id' => $data['parent_id'] ?? null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'data' => DB::table('departments')->where('id', $id)->first(),
    ], 201);
});

Route::put('/departments/{department}', function (Request $request, int $department) use ($departmentWouldCycle) {
    $validator = Validator::make($request->all(), [
        'name' => ['required', 'string', 'max:255'],
        'alias' => ['nullable', 'string', 'max:255'],
        'parent_id' => ['nullable', 'integer', 'exists:departments,id'],
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    if (! DB::table('departments')->where('id', $department)->exists()) {
        return response()->json(['message' => 'Department not found'], 404);
    }

    $data = $validator->validated();
    $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

    if ($departmentWouldCycle($department, $parentId)) {
        return response()->json([
            'errors' => ['parent_id' => ['Подразделение не может быть вложено в себя или в собственное дочернее подразделение.']],
        ], 422);
    }

    DB::table('departments')->where('id', $department)->update([
        'name' => $data['name'],
        'alias' => $data['alias'] ?? null,
        'parent_id' => $parentId,
        'updated_at' => now(),
    ]);

    return response()->json([
        'data' => DB::table('departments')->where('id', $department)->first(),
    ]);
});

Route::get('/employees', function (Request $request) {
    $query = DB::table('employees')
        ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
        ->select([
            'employees.id',
            'employees.full_name',
            'employees.department_id',
            'departments.name as department_name',
            'employees.position',
            'employees.employment_status',
            'employees.work_format',
            'employees.cooperation_type',
            'employees.hired_at',
            'employees.created_at',
            'employees.updated_at',
        ]);

    if ($search = trim((string) $request->query('search', ''))) {
        $query->where(function ($q) use ($search) {
            $q->where('employees.full_name', 'ilike', "%{$search}%")
                ->orWhere('employees.position', 'ilike', "%{$search}%");
        });
    }

    if ($departmentId = $request->query('department_id')) {
        $query->where('employees.department_id', (int) $departmentId);
    }

    if ($status = trim((string) $request->query('employment_status', ''))) {
        $query->where('employees.employment_status', $status);
    }

    $employees = $query->orderBy('employees.full_name')->get();

    return response()->json([
        'data' => $employees,
        'meta' => ['count' => $employees->count()],
    ]);
});

Route::post('/employees', function (Request $request) use ($employeeStatuses, $workFormats, $cooperationTypes) {
    $validator = Validator::make($request->all(), [
        'full_name' => ['required', 'string', 'max:255'],
        'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        'position' => ['nullable', 'string', 'max:255'],
        'employment_status' => ['nullable', Rule::in($employeeStatuses)],
        'work_format' => ['nullable', Rule::in($workFormats)],
        'cooperation_type' => ['nullable', Rule::in($cooperationTypes)],
        'hired_at' => ['nullable', 'date'],
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $data = $validator->validated();
    $id = DB::table('employees')->insertGetId([
        'full_name' => $data['full_name'],
        'department_id' => $data['department_id'] ?? null,
        'position' => $data['position'] ?? null,
        'employment_status' => $data['employment_status'] ?? null,
        'work_format' => $data['work_format'] ?? null,
        'cooperation_type' => $data['cooperation_type'] ?? null,
        'hired_at' => $data['hired_at'] ?? null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'data' => DB::table('employees')->where('id', $id)->first(),
    ], 201);
});

Route::put('/employees/{employee}', function (Request $request, int $employee) use ($employeeStatuses, $workFormats, $cooperationTypes) {
    $validator = Validator::make($request->all(), [
        'full_name' => ['required', 'string', 'max:255'],
        'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        'position' => ['nullable', 'string', 'max:255'],
        'employment_status' => ['nullable', Rule::in($employeeStatuses)],
        'work_format' => ['nullable', Rule::in($workFormats)],
        'cooperation_type' => ['nullable', Rule::in($cooperationTypes)],
        'hired_at' => ['nullable', 'date'],
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    if (! DB::table('employees')->where('id', $employee)->exists()) {
        return response()->json(['message' => 'Employee not found'], 404);
    }

    $data = $validator->validated();
    DB::table('employees')->where('id', $employee)->update([
        'full_name' => $data['full_name'],
        'department_id' => $data['department_id'] ?? null,
        'position' => $data['position'] ?? null,
        'employment_status' => $data['employment_status'] ?? null,
        'work_format' => $data['work_format'] ?? null,
        'cooperation_type' => $data['cooperation_type'] ?? null,
        'hired_at' => $data['hired_at'] ?? null,
        'updated_at' => now(),
    ]);

    return response()->json([
        'data' => DB::table('employees')->where('id', $employee)->first(),
    ]);
});
