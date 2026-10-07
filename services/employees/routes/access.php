<?php

use App\Support\SpecialRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/access/me', function (Request $request) {
    return response()->json(['data' => $request->attributes->get('employees_access', [])]);
});

$roleMembers = function (string $role) {
    return DB::table('employee_access_roles as r')
        ->join('employees as e', 'e.id', '=', 'r.employee_id')
        ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
        ->where('r.role', $role)
        ->select(['e.id', 'e.full_name', 'e.login', 'e.work_email', 'e.position', 'e.department_id', 'd.name as department_name', 'r.created_at'])
        ->orderBy('e.full_name')
        ->get();
};

Route::get('/access/roles', function () use ($roleMembers) {
    $data = [];
    foreach (SpecialRoles::catalog() as $role) {
        $members = $roleMembers($role['key']);
        $data[] = [...$role, 'members' => $members, 'member_count' => $members->count()];
    }
    return response()->json(['data' => $data]);
});

$protectedPlatformRoles = [SpecialRoles::PlatformAdmin, SpecialRoles::PlatformTester];
$testerCanMutateRole = static function (Request $request, string $role) use ($protectedPlatformRoles): bool {
    $roles = (array) (($request->attributes->get('employees_access', []))['roles'] ?? []);
    $isAdmin = in_array(SpecialRoles::PlatformAdmin, $roles, true);
    $isTester = in_array(SpecialRoles::PlatformTester, $roles, true);
    return $isAdmin || !$isTester || !in_array($role, $protectedPlatformRoles, true);
};

Route::put('/access/roles/{role}/{employee}', function (Request $request, string $role, int $employee) use ($testerCanMutateRole) {
    if (!SpecialRoles::exists($role)) return response()->json(['message' => 'Unknown special role'], 404);
    if (!$testerCanMutateRole($request, $role)) return response()->json(['message' => 'Тестировщик платформы не может назначать роли администратора платформы или тестировщика платформы.'], 403);
    if (!DB::table('employees')->where('id', $employee)->exists()) return response()->json(['message' => 'Employee not found'], 404);

    DB::table('employee_access_roles')->updateOrInsert(
        ['employee_id' => $employee, 'role' => $role],
        ['updated_at' => now(), 'created_at' => now()]
    );
    return response()->json(['data' => ['employee_id' => $employee, 'role' => $role]]);
});

Route::delete('/access/roles/{role}/{employee}', function (Request $request, string $role, int $employee) use ($testerCanMutateRole) {
    if (!SpecialRoles::exists($role)) return response()->json(['message' => 'Unknown special role'], 404);
    if (!$testerCanMutateRole($request, $role)) return response()->json(['message' => 'Тестировщик платформы не может снимать роли администратора платформы или тестировщика платформы.'], 403);
    if ($role === SpecialRoles::PlatformAdmin && DB::table('employees')->where('id', $employee)->where('login', 'admin')->exists()) {
        return response()->json(['message' => 'Нельзя снять роль администратора платформы с системного аккаунта IRLIX.'], 409);
    }
    DB::table('employee_access_roles')->where('employee_id', $employee)->where('role', $role)->delete();
    return response()->noContent();
});
