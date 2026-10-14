<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

$roleCatalog = [
    'platform-admin'=>'Администратор платформы',
    'platform-tester'=>'Тестировщик платформы',
    'personnel-officer'=>'Специалист по кадрам',
    'hr'=>'HR',
    'finance'=>'Finance',
    'manager'=>'Руководитель подразделения',
    'system-admin'=>'Системный администратор',
];
$permissionCatalog = [
    'employees.read'=>'Просмотр сотрудников',
    'employees.application.create'=>'Подача заявок на трудоустройство',
    'employees.hire'=>'Трудоустройство',
    'employees.manage'=>'Редактирование карточек',
    'employees.salary.read'=>'Просмотр окладов',
    'employees.salary.manage'=>'Изменение окладов',
    'organization.read'=>'Просмотр оргструктуры',
    'organization.manage'=>'Изменение оргструктуры',
    'staff_positions.read'=>'Просмотр должностей',
    'staff_positions.manage'=>'Управление должностями',
    'access.manage'=>'Управление специальными ролями',
    'audit.read'=>'Просмотр аудита',
];
Route::get('/access/permissions', function (Request $request) use ($roleCatalog,$permissionCatalog) {
    if (!($request->attributes->get('employees_access',[])['permissions']['employees.permissions.manage'] ?? false)) return response()->json(['message'=>'Forbidden'],403);
    return response()->json(['data'=>[
        'roles'=>$roleCatalog,'permissions'=>$permissionCatalog,
        'rules'=>DB::table('employee_permission_rules')->get(['role','permission','enabled']),
    ]]);
});
Route::put('/access/permissions/{role}/{permission}', function (Request $request,string $role,string $permission) use ($roleCatalog,$permissionCatalog) {
    if (!($request->attributes->get('employees_access',[])['permissions']['employees.permissions.manage'] ?? false)) return response()->json(['message'=>'Forbidden'],403);
    if (!isset($roleCatalog[$role]) || !isset($permissionCatalog[$permission])) return response()->json(['message'=>'Unknown role or permission'],404);
    if ($role==='platform-admin') return response()->json(['message'=>'Platform admin permissions cannot be restricted'],403);
    $data=Validator::make($request->all(),['enabled'=>['required','boolean']])->validate();
    DB::table('employee_permission_rules')->updateOrInsert(['role'=>$role,'permission'=>$permission],['enabled'=>$data['enabled'],'created_at'=>now(),'updated_at'=>now()]);
    return response()->json(['data'=>['role'=>$role,'permission'=>$permission,'enabled'=>(bool)$data['enabled']]]);
});
