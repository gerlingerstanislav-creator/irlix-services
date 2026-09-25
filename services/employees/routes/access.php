<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/access/me', function (Request $request) {
    return response()->json(['data' => $request->attributes->get('employees_access', [])]);
});

Route::get('/access/company-admins', function () {
    $rows = DB::table('employee_access_roles as r')
        ->join('employees as e', 'e.id', '=', 'r.employee_id')
        ->where('r.role', 'company-admin')
        ->select(['e.id', 'e.full_name', 'e.login', 'e.work_email', 'r.created_at'])
        ->orderBy('e.full_name')
        ->get();
    return response()->json(['data' => $rows]);
});

Route::put('/access/company-admins/{employee}', function (int $employee) {
    if (!DB::table('employees')->where('id', $employee)->exists()) {
        return response()->json(['message' => 'Employee not found'], 404);
    }
    DB::table('employee_access_roles')->updateOrInsert(
        ['employee_id' => $employee, 'role' => 'company-admin'],
        ['updated_at' => now(), 'created_at' => now()]
    );
    return response()->json(['data' => ['employee_id' => $employee, 'role' => 'company-admin']]);
});

Route::delete('/access/company-admins/{employee}', function (int $employee) {
    DB::table('employee_access_roles')->where('employee_id', $employee)->where('role', 'company-admin')->delete();
    return response()->noContent();
});
