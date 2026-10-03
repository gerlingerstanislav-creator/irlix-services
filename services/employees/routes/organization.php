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

Route::get('/staff-positions', function (Request $request) {
    $access = (array) $request->attributes->get('employees_access', []);
    $canReadSalary = (bool) ($access['permissions']['employees.salary.read'] ?? false);

    $positions = DB::table('staff_positions')
        ->select(['id', 'name', 'base_salary', 'created_at', 'updated_at'])
        ->orderBy('name')
        ->get()
        ->map(function ($position) use ($canReadSalary) {
            if (!$canReadSalary) $position->base_salary = null;
            return $position;
        });

    return response()->json(['data' => $positions]);
});

Route::post('/staff-positions', function (Request $request) use ($isPlatformAdmin) {
    if (!$isPlatformAdmin($request)) {
        return response()->json(['message' => 'Только администратор платформы может изменять штатное расписание.'], 403);
    }

    $validator = Validator::make($request->all(), [
        'name' => ['required', 'string', 'max:255', Rule::unique('staff_positions', 'name')],
        'base_salary' => ['nullable', 'numeric', 'min:0'],
    ]);
    if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

    $data = $validator->validated();
    $id = DB::table('staff_positions')->insertGetId([
        'name' => trim($data['name']),
        'base_salary' => $data['base_salary'] ?? null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json(['data' => DB::table('staff_positions')->where('id', $id)->first()], 201);
});

Route::put('/staff-positions/{position}', function (Request $request, int $position) use ($isPlatformAdmin) {
    if (!$isPlatformAdmin($request)) {
        return response()->json(['message' => 'Только администратор платформы может изменять штатное расписание.'], 403);
    }

    $current = DB::table('staff_positions')->where('id', $position)->first();
    if (!$current) return response()->json(['message' => 'Должность не найдена.'], 404);

    $validator = Validator::make($request->all(), [
        'name' => ['required', 'string', 'max:255', Rule::unique('staff_positions', 'name')->ignore($position)],
        'base_salary' => ['nullable', 'numeric', 'min:0'],
    ]);
    if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

    $data = $validator->validated();
    $newName = trim($data['name']);

    DB::transaction(function () use ($position, $newName, $data) {
        DB::table('staff_positions')->where('id', $position)->update([
            'name' => $newName,
            'base_salary' => $data['base_salary'] ?? null,
            'updated_at' => now(),
        ]);
        DB::table('employees')->where('position_id', $position)->update([
            'position' => $newName,
            'updated_at' => now(),
        ]);
    });

    return response()->json(['data' => DB::table('staff_positions')->where('id', $position)->first()]);
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
    if ($employeeCount > 0 || $childCount > 0) {
        return response()->json([
            'message' => 'Нельзя удалить непустое подразделение. Сначала перенесите сотрудников и дочерние подразделения.',
            'meta' => ['employee_count' => $employeeCount, 'child_department_count' => $childCount],
        ], 409);
    }

    DB::table('departments')->where('id', $department)->delete();

    return response()->json([
        'data' => ['id' => $department, 'name' => $current->name, 'deleted' => true],
    ]);
});
