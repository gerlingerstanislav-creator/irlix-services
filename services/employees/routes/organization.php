<?php

use App\Support\SpecialRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

$isPlatformAdmin = static function (Request $request): bool {
    $access = (array) $request->attributes->get('employees_access', []);
    return in_array(SpecialRoles::PlatformAdmin, $access['roles'] ?? [], true);
};

$validateDirection = static function (array $data) {
    $directionId = (int) ($data['direction_id'] ?? 0);
    return DB::table('departments')->where('id', $directionId)->exists();
};

$validatePositionName = static function ($attribute, $value, $fail) {
    if (strtolower(str_replace([' ', '-', '_'], '', trim((string) $value))) === 'platformadministrator') {
        $fail('Администратор платформы — глобальная роль, а не должность штатного расписания.');
    }
};

$positionQuery = static function () {
    return DB::table('staff_positions as p')
        ->leftJoin('departments as d', 'd.id', '=', 'p.direction_id')
        ->select(['p.id', 'p.name', 'p.direction_id', 'd.name as direction_name', 'p.base_salary', 'p.closed_at', 'p.created_at', 'p.updated_at'])
        ->selectSub(fn($q) => $q->from('employees as position_employee')->selectRaw('COUNT(*)::int')->whereColumn('position_employee.position_id', 'p.id')->whereColumn('position_employee.department_id', 'p.direction_id')->where('position_employee.employment_status', 'Трудоустроен'), 'employee_count');
};

Route::get('/staff-positions', function (Request $request) use ($positionQuery) {
    $access = (array) $request->attributes->get('employees_access', []);
    $canReadSalary = (bool) ($access['permissions']['employees.salary.read'] ?? false);

    $positions = $positionQuery()
        ->orderByRaw('p.closed_at IS NOT NULL')
        ->orderBy('d.name')
        ->orderBy('p.name')
        ->get()
        ->map(function ($position) use ($canReadSalary) {
            if (!$canReadSalary) $position->base_salary = null;
            return $position;
        });

    return response()->json(['data' => $positions]);
});

Route::post('/staff-positions', function (Request $request) use ($isPlatformAdmin, $validateDirection, $validatePositionName, $positionQuery) {
    if (!$isPlatformAdmin($request)) {
        return response()->json(['message' => 'Только администратор платформы может изменять штатное расписание.'], 403);
    }

    $validator = Validator::make($request->all(), [
        'name' => ['required', 'string', 'max:255', $validatePositionName, Rule::unique('staff_positions', 'name')],
        'direction_id' => ['required', 'integer', Rule::exists('departments', 'id')],
        'base_salary' => ['nullable', 'numeric', 'min:0'],
    ]);
    if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

    $data = $validator->validated();
    if (!$validateDirection($data)) {
        return response()->json(['errors' => ['direction_id' => ['Выберите существующее подразделение для должности.']]], 422);
    }

    $id = DB::table('staff_positions')->insertGetId([
        'name' => trim($data['name']),
        'direction_id' => (int) $data['direction_id'],
        'base_salary' => $data['base_salary'] ?? null,
        'closed_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json(['data' => $positionQuery()->where('p.id', $id)->first()], 201);
});

Route::put('/staff-positions/{position}', function (Request $request, int $position) use ($isPlatformAdmin, $validateDirection, $validatePositionName, $positionQuery) {
    if (!$isPlatformAdmin($request)) {
        return response()->json(['message' => 'Только администратор платформы может изменять штатное расписание.'], 403);
    }

    $current = DB::table('staff_positions')->where('id', $position)->first();
    if (!$current) return response()->json(['message' => 'Должность не найдена.'], 404);

    $validator = Validator::make($request->all(), [
        'name' => ['required', 'string', 'max:255', $validatePositionName, Rule::unique('staff_positions', 'name')->ignore($position)],
        'direction_id' => ['required', 'integer', Rule::exists('departments', 'id')],
        'base_salary' => ['nullable', 'numeric', 'min:0'],
    ]);
    if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

    $data = $validator->validated();
    if (!$validateDirection($data)) {
        return response()->json(['errors' => ['direction_id' => ['Выберите существующее подразделение для должности.']]], 422);
    }
    $newName = trim($data['name']);

    DB::transaction(function () use ($position, $newName, $data) {
        DB::table('staff_positions')->where('id', $position)->update([
            'name' => $newName,
            'direction_id' => (int) $data['direction_id'],
            'base_salary' => $data['base_salary'] ?? null,
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('position_id', $position)->update([
            'position' => $newName,
            'updated_at' => now(),
        ]);
    });

    return response()->json(['data' => $positionQuery()->where('p.id', $position)->first()]);
});

Route::post('/staff-positions/{position}/close', function (Request $request, int $position) use ($isPlatformAdmin, $positionQuery) {
    if (!$isPlatformAdmin($request)) {
        return response()->json(['message' => 'Только администратор платформы может закрывать должности.'], 403);
    }

    $current = DB::table('staff_positions')->where('id', $position)->first();
    if (!$current) return response()->json(['message' => 'Должность не найдена.'], 404);

    if ($current->closed_at === null) {
        DB::table('staff_positions')->where('id', $position)->update(['closed_at' => now(), 'updated_at' => now()]);
    }

    return response()->json(['data' => $positionQuery()->where('p.id', $position)->first()]);
});

Route::delete('/staff-positions/{position}', function (Request $request, int $position) use ($isPlatformAdmin) {
    if (!$isPlatformAdmin($request)) {
        return response()->json(['message' => 'Полное удаление должности доступно только администратору платформы.'], 403);
    }

    $current = DB::table('staff_positions')->where('id', $position)->first();
    if (!$current) return response()->json(['message' => 'Должность не найдена.'], 404);

    $currentEmployees = DB::table('employees')->where('position_id', $position)->count();
    $periodHistory = DB::table('employment_periods')->where('position_id', $position)->count();
    $assignmentHistory = DB::table('employment_assignment_history')->where('position_id', $position)->count();
    $historyRecords = $periodHistory + $assignmentHistory;
    if ($currentEmployees > 0 || $historyRecords > 0) {
        return response()->json([
            'message' => 'Нельзя полностью удалить должность, которая используется сотрудниками или присутствует в кадровой истории. Закройте её вместо удаления.',
            'meta' => ['employee_count' => $currentEmployees, 'history_count' => $historyRecords],
        ], 409);
    }

    DB::table('staff_positions')->where('id', $position)->delete();
    return response()->json(['data' => ['id' => $position, 'name' => $current->name, 'deleted' => true]]);
});

Route::delete('/departments/{department}', function (Request $request, int $department) use ($isPlatformAdmin) {
    if (!$isPlatformAdmin($request)) {
        return response()->json(['message' => 'Полное удаление подразделения доступно только администратору платформы.'], 403);
    }

    if (!hash_equals('engineer', trim((string) $request->input('confirmation_code', '')))) {
        return response()->json(['message' => 'Неверный контрольный код удаления.'], 422);
    }

    $current = DB::table('departments')->where('id', $department)->first();
    if (!$current) return response()->json(['message' => 'Подразделение не найдено.'], 404);

    $employeeCount = DB::table('employees')->where('department_id', $department)->count();
    $childCount = DB::table('departments')->where('parent_id', $department)->count();
    $positionCount = DB::table('staff_positions')->where('direction_id', $department)->count();
    if ($employeeCount > 0 || $childCount > 0 || $positionCount > 0) {
        return response()->json([
            'message' => 'Нельзя удалить непустое подразделение. Сначала перенесите сотрудников, дочерние подразделения и должности штатного расписания.',
            'meta' => ['employee_count' => $employeeCount, 'child_department_count' => $childCount, 'staff_position_count' => $positionCount],
        ], 409);
    }

    DB::table('departments')->where('id', $department)->delete();

    return response()->json([
        'data' => ['id' => $department, 'name' => $current->name, 'deleted' => true],
    ]);
});
