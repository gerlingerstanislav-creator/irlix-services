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

$platformAdminOnly = static function (Request $request): bool {
    $roles = (array) (($request->attributes->get('employees_access', []))['roles'] ?? []);
    return in_array(SpecialRoles::PlatformAdmin, $roles, true);
};

Route::put('/access/roles/{role}/{employee}', function (Request $request, string $role, int $employee) use ($platformAdminOnly) {
    if (!SpecialRoles::exists($role)) return response()->json(['message' => 'Unknown special role'], 404);
    if (!$platformAdminOnly($request)) return response()->json(['message' => 'Управление ролями доступно только администратору платформы.'], 403);
    if (!DB::table('employees')->where('id', $employee)->exists()) return response()->json(['message' => 'Employee not found'], 404);

    DB::table('employee_access_roles')->updateOrInsert(
        ['employee_id' => $employee, 'role' => $role],
        ['updated_at' => now(), 'created_at' => now()]
    );
    return response()->json(['data' => ['employee_id' => $employee, 'role' => $role]]);
});

Route::delete('/access/roles/{role}/{employee}', function (Request $request, string $role, int $employee) use ($platformAdminOnly) {
    if (!SpecialRoles::exists($role)) return response()->json(['message' => 'Unknown special role'], 404);
    if (!$platformAdminOnly($request)) return response()->json(['message' => 'Управление ролями доступно только администратору платформы.'], 403);
    if ($role === SpecialRoles::PlatformAdmin && DB::table('employees')->where('id', $employee)->where('login', 'admin')->exists()) {
        return response()->json(['message' => 'Нельзя снять роль администратора платформы с системного аккаунта IRLIX.'], 409);
    }
    DB::table('employee_access_roles')->where('employee_id', $employee)->where('role', $role)->delete();
    return response()->noContent();
});

// A verified Keycloak access token authorizes only issuance of a short-lived,
// HttpOnly navigation cookie. All actual content and SVG asset requests are
// checked again by the host nginx gate against current Employees role grants.
Route::post('/brandbook/session', function (Request $request) {
    $access = (array) $request->attributes->get('employees_access', []);
    $identity = (array) $request->attributes->get('identity', []);
    if (!($access['services']['brandbook'] ?? false) || !\App\Support\BrandbookGate::hasRole((array) ($access['roles'] ?? []))) {
        return response()->json(['message' => 'Brandbook access requires top-management'], 403);
    }
    $employee = DB::table('employees')->where('id', (int) ($access['employee_id'] ?? 0))
        ->where('keycloak_user_id', (string) ($identity['sub'] ?? ''))->first();
    if (!\App\Support\BrandbookGate::active($employee) || !\App\Support\BrandbookGate::hasRole(
        DB::table('employee_access_roles')->where('employee_id', $employee->id)->pluck('role')->all()
    )) return response()->json(['message' => 'Brandbook role is not assigned'], 403);

    $expiresAt = time() + 300;
    $encrypted = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
        'employee_id' => (int) $employee->id,
        'subject' => (string) $identity['sub'],
        'expires_at' => $expiresAt,
    ], JSON_THROW_ON_ERROR));
    // The portal is the only issuance client; browser requests to /brandbook/
    // carry this cookie automatically. HttpOnly prevents JS exfiltration.
    $secure = $request->isSecure() || $request->header('X-Forwarded-Proto') === 'https';
    $cookie = cookie('irlix_brandbook', $encrypted, 5, '/brandbook/', null, $secure, true, false, 'lax');
    return response()->json(['data' => ['expires_in' => 300]])
        ->header('Cache-Control', 'private, no-store')
        ->cookie($cookie);
});
