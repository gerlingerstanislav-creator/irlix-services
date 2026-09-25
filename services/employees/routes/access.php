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

Route::put('/access/roles/{role}/{employee}', function (string $role, int $employee) {
    if (!SpecialRoles::exists($role)) return response()->json(['message' => 'Unknown special role'], 404);
    if (!DB::table('employees')->where('id', $employee)->exists()) return response()->json(['message' => 'Employee not found'], 404);

    DB::table('employee_access_roles')->updateOrInsert(
        ['employee_id' => $employee, 'role' => $role],
        ['updated_at' => now(), 'created_at' => now()]
    );
    return response()->json(['data' => ['employee_id' => $employee, 'role' => $role]]);
});

Route::delete('/access/roles/{role}/{employee}', function (string $role, int $employee) {
    if (!SpecialRoles::exists($role)) return response()->json(['message' => 'Unknown special role'], 404);
    DB::table('employee_access_roles')->where('employee_id', $employee)->where('role', $role)->delete();
    return response()->noContent();
});

// Backward-compatible aliases for existing integrations while the UI moves to the generic role model.
Route::get('/access/company-admins', fn() => response()->json(['data' => $roleMembers(SpecialRoles::CompanyAdmin)]));
Route::put('/access/company-admins/{employee}', function (int $employee) {
    if (!DB::table('employees')->where('id', $employee)->exists()) return response()->json(['message' => 'Employee not found'], 404);
    DB::table('employee_access_roles')->updateOrInsert(
        ['employee_id' => $employee, 'role' => SpecialRoles::CompanyAdmin],
        ['updated_at' => now(), 'created_at' => now()]
    );
    return response()->json(['data' => ['employee_id' => $employee, 'role' => SpecialRoles::CompanyAdmin]]);
});
Route::delete('/access/company-admins/{employee}', function (int $employee) {
    DB::table('employee_access_roles')->where('employee_id', $employee)->where('role', SpecialRoles::CompanyAdmin)->delete();
    return response()->noContent();
});
