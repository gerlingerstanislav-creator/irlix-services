<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/self', function (Request $request) {
    $identity = (array) $request->attributes->get('identity', []);
    $subject = $identity['sub'] ?? null;
    if (!$subject) {
        return response()->json(['message' => 'Authenticated identity has no subject'], 401);
    }

    $employee = DB::table('employees')
        ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
        ->where('employees.keycloak_user_id', $subject)
        ->select([
            'employees.id',
            'employees.full_name',
            'employees.first_name',
            'employees.last_name',
            'employees.login',
            'employees.work_email',
            'employees.department_id',
            'employees.position',
            'employees.employment_status',
            'departments.name as department_name',
        ])
        ->first();

    if (!$employee) {
        return response()->json(['message' => 'Employee profile is not linked to this account'], 404);
    }

    return response()->json(['data' => $employee]);
});
